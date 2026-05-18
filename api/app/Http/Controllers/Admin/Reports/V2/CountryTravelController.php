<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports\V2;

use App\Http\Controllers\Admin\Reports\BaseReportController;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * R10 · rpt-country-travel — Country & Travel.
 *
 * Executive question: "Where are travellers coming from, who is high-risk,
 * and is endemic-country flow producing alerts?"
 *
 * Engineering contract: aggregation-only SQL, two-query whereIn for every
 * `ref_*` lookup (cross-collation safe — `secondary_screenings.*_country_code`
 * is `utf8mb4_unicode_ci`, `ref_countries.country_code` is `utf8mb4_0900_ai_ci`).
 *
 * R10 footnote rule: every chart and KPI that uses traveller-origin says
 * "based on secondary-screened travellers" because primary tier has no
 * origin country.
 */
final class CountryTravelController extends BaseReportController
{
    protected string $reportKey   = 'rpt-country-travel';
    protected string $reportTitle = 'Country & Travel';

    private const RECORDS_LIMIT = 10;
    private const TOP_LIMIT     = 10;

    /* ──────────────────────────────────────────────────────────────────
     * 1.  index — Blade shell.
     * ────────────────────────────────────────────────────────────────── */
    public function index(Request $request): View
    {
        $this->ensureAccess($request);
        return view('admin.reports.v2.rpt-country-travel', [
            'reportKey'   => $this->reportKey,
            'reportTitle' => $this->reportTitle,
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 2.  meta — filter dropdowns + scope + endemic disease list.
     * ────────────────────────────────────────────────────────────────── */
    public function meta(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);

        $endemicDiseases = DB::table('ref_endemic_countries')
            ->where('is_active', 1)
            ->whereNotNull('disease_code')
            ->select('disease_code')
            ->distinct()
            ->orderBy('disease_code')
            ->pluck('disease_code')->all();

        return $this->ok([
            'poes'      => $this->scope->allowedPoes($scope),
            'scope'     => [
                'label'    => $scope['label'] ?? '—',
                'level'    => $scope['scope_level'] ?? 'SELF',
                'is_super' => (bool) ($scope['is_super'] ?? false),
            ],
            'cats'      => ['all', 'endemic', 'non_endemic'],
            'endemic_diseases' => $endemicDiseases,
            'data_notes'=> $this->dataNotes(),
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 3.  kpis — 5 tiles.
     * ────────────────────────────────────────────────────────────────── */
    public function kpis(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $secIds = $this->scopedSecondaryIds($scope, $f, $from, $to);
        $travellersTotal = count($secIds);

        if ($travellersTotal === 0) {
            return $this->ok([
                'kpis' => $this->emptyKpis(),
                'window' => [$from->toDateString(), $to->toDateString()],
            ]);
        }

        // Origin code distribution (aggregated single grouped query).
        $originRows = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereIn('id', $secIds)
            ->whereNotNull('journey_start_country_code')
            ->selectRaw('journey_start_country_code AS cc, COUNT(*) AS c, poe_code')
            ->groupBy('journey_start_country_code', 'poe_code')
            ->get();

        $byCountry = [];
        $byCountryPoe = [];   // [code => [poe => count]]
        foreach ($originRows as $r) {
            $cc = (string) $r->cc;
            if ($cc === '') { continue; }
            $byCountry[$cc] = ($byCountry[$cc] ?? 0) + (int) $r->c;
            $poe = (string) ($r->poe_code ?? '');
            if ($poe === '') { continue; }
            $byCountryPoe[$cc][$poe] = ($byCountryPoe[$cc][$poe] ?? 0) + (int) $r->c;
        }
        $uniqueOrigins = count($byCountry);

        // Endemic resolution: build the set of origin codes that match
        // ref_endemic_countries.country_code OR ref_countries.iso_alpha3 /
        // iso_alpha2 mapped from origin code.
        $endemicCodes = $this->resolveEndemicOriginCodes(array_keys($byCountry));

        $endemicTravellers = 0;
        foreach ($byCountry as $cc => $n) {
            if (in_array($cc, $endemicCodes, true)) { $endemicTravellers += $n; }
        }
        $endemicSharePct = $this->safePct($endemicTravellers, $travellersTotal);

        // Top route: country_code → poe_code with highest single count.
        $topRouteLabel = '—';
        $topRouteCount = 0;
        foreach ($byCountryPoe as $cc => $poes) {
            foreach ($poes as $poe => $n) {
                if ($n > $topRouteCount) {
                    $topRouteCount = $n;
                    $topRouteLabel = $cc . ' → ' . $poe;
                }
            }
        }
        // Replace cc with country display name where possible.
        $topRouteLabel = $this->resolveTopRouteLabel($topRouteLabel);

        // Endemic alert rate: alerts attached to endemic-origin secondary screenings.
        $endemicSecIds = $this->secIdsForOriginCodes($secIds, $endemicCodes);
        $endemicAlerts = empty($endemicSecIds) ? 0 : (int) DB::table('alerts')
            ->whereNull('deleted_at')
            ->whereIn('secondary_screening_id', $endemicSecIds)
            ->count();
        $endemicAlertRate = $this->safePct($endemicAlerts, $endemicTravellers);

        // Non-endemic alert rate (used for tone comparison).
        $nonEndemicTravellers = $travellersTotal - $endemicTravellers;
        $nonEndemicSecIds = array_values(array_diff($secIds, $endemicSecIds));
        $nonEndemicAlerts = empty($nonEndemicSecIds) ? 0 : (int) DB::table('alerts')
            ->whereNull('deleted_at')
            ->whereIn('secondary_screening_id', $nonEndemicSecIds)
            ->count();
        $nonEndemicAlertRate = $this->safePct($nonEndemicAlerts, $nonEndemicTravellers);

        $endemicTone = 'success';
        if ($endemicAlertRate !== null && $nonEndemicAlertRate !== null && $endemicAlertRate > $nonEndemicAlertRate) {
            $endemicTone = 'danger';
        }
        $endemicShareTone = ($endemicSharePct !== null && $endemicSharePct >= 25) ? 'warning' : 'success';

        return $this->ok([
            'kpis' => [
                ['key' => 'travellers_total',   'label' => 'Travellers Screened',   'value' => number_format($travellersTotal),    'tone' => 'brand', 'hint' => 'Secondary-tier screenings in window.'],
                ['key' => 'unique_origins',     'label' => 'Origin Countries',      'value' => number_format($uniqueOrigins),      'tone' => 'info',  'hint' => 'Distinct journey-start countries.'],
                ['key' => 'endemic_share_pct',  'label' => 'From Endemic Countries','value' => $endemicSharePct === null ? '—' : ($endemicSharePct . '%'), 'tone' => $endemicShareTone, 'hint' => '% of travellers from endemic-flagged origins.'],
                ['key' => 'top_route',          'label' => 'Top Route',             'value' => $topRouteLabel . ($topRouteCount > 0 ? ' · ' . number_format($topRouteCount) : ''), 'tone' => 'info', 'hint' => 'Origin → arrival POE pair with most travellers.'],
                ['key' => 'endemic_alert_rate', 'label' => 'Endemic Alert Rate',    'value' => $endemicAlertRate === null ? '—' : ($endemicAlertRate . '%'), 'tone' => $endemicTone, 'hint' => 'Alerts ÷ travellers from endemic origins.'],
            ],
            'extra' => [
                'non_endemic_alert_rate' => $nonEndemicAlertRate,
                'endemic_travellers'     => $endemicTravellers,
                'non_endemic_travellers' => $nonEndemicTravellers,
            ],
            'window' => [$from->toDateString(), $to->toDateString()],
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 4.  chart / chart CSV
     * ────────────────────────────────────────────────────────────────── */
    public function chart(Request $request, string $chart): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        return match ($chart) {
            'top_origins'      => $this->ok($this->chartTopOrigins($scope, $f)),
            'endemic_flow_30d' => $this->ok($this->chartEndemicFlow30d($scope, $f)),
            default            => $this->fail(404, 'Unknown chart key.'),
        };
    }

    public function chartCsv(Request $request, string $chart): StreamedResponse|Response
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        $payload = match ($chart) {
            'top_origins'      => $this->chartTopOrigins($scope, $f),
            'endemic_flow_30d' => $this->chartEndemicFlow30d($scope, $f),
            default            => abort(404, 'Unknown chart key.'),
        };
        return $this->writer->send(
            $this->reportKey,
            'CSV',
            $payload['csv_headers'],
            $payload['csv_rows'],
            $f,
            (int) ($request->user()->id ?? 0),
            $this->reportTitle . ' · ' . $chart,
        );
    }

    /* ──────────────────────────────────────────────────────────────────
     * 5.  records — 10-row paginated Country Index.
     * ────────────────────────────────────────────────────────────────── */
    public function records(Request $request): JsonResponse
    {
        $scope    = $this->ensureAccess($request);
        $f        = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $page    = max(1, (int) $request->input('page', 1));
        $perPage = self::RECORDS_LIMIT;
        $q       = trim((string) $request->input('q', ''));
        $sort    = (string) $request->input('sort', 'travellers');
        $dir     = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $cat     = (string) $request->input('cat', 'all');

        $secIds = $this->scopedSecondaryIds($scope, $f, $from, $to);
        if (empty($secIds)) {
            return $this->ok([
                'rows' => [],
                'pagination' => ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 1, 'from' => 0, 'to' => 0],
                'controls'   => ['sort' => $sort, 'dir' => $dir, 'q' => $q, 'cat' => $cat],
                'category_counts' => ['all' => 0, 'endemic' => 0, 'non_endemic' => 0],
            ]);
        }

        // Aggregate travellers by origin country — single grouped query.
        $countryRows = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereIn('id', $secIds)
            ->whereNotNull('journey_start_country_code')
            ->selectRaw('journey_start_country_code AS cc, COUNT(*) AS c')
            ->groupBy('journey_start_country_code')
            ->get();
        $codes = $countryRows->pluck('cc')->filter()->unique()->values()->all();

        // Lookup: country names via two-query whereIn (cross-collation safe).
        $nameMap = empty($codes) ? [] : DB::table('ref_countries')
            ->whereIn('country_code', $codes)
            ->where('is_active', 1)
            ->pluck('name', 'country_code')->all();
        $isoMap  = empty($codes) ? [] : DB::table('ref_countries')
            ->whereIn('country_code', $codes)
            ->where('is_active', 1)
            ->pluck('iso_alpha2', 'country_code')->all();

        // Endemic codes for these origins.
        $endemicCodes = $this->resolveEndemicOriginCodes($codes);

        // Per-country alert count via two-query: pull sec_id sets per origin code.
        // Doing it safely as one grouped sub-query.
        $alertCountByCode = [];
        if (! empty($codes)) {
            $rows = DB::table('alerts AS a')->whereNull('a.deleted_at')
                ->join('secondary_screenings AS s', 's.id', '=', 'a.secondary_screening_id')
                ->whereIn('s.id', $secIds)
                ->whereIn('s.journey_start_country_code', $codes)
                ->selectRaw('s.journey_start_country_code AS cc, COUNT(*) AS c')
                ->groupBy('s.journey_start_country_code')
                ->get();
            foreach ($rows as $r) { $alertCountByCode[(string) $r->cc] = (int) $r->c; }
        }

        // Top disease per country (two-query — first aggregate, then lookup).
        $topDiseaseByCode = $this->topDiseasePerCountry($secIds, $codes);

        // Top POE per country.
        $topPoeByCode = $this->topPoePerCountry($secIds, $codes);

        $rows = [];
        foreach ($countryRows as $r) {
            $cc  = (string) $r->cc;
            $end = in_array($cc, $endemicCodes, true);
            $rows[] = [
                'country_code' => $cc,
                'country'      => $nameMap[$cc] ?? $cc,
                'iso2'         => $isoMap[$cc]  ?? '',
                'travellers'   => (int) $r->c,
                'alerts_'      => (int) ($alertCountByCode[$cc] ?? 0),
                'top_disease'  => $topDiseaseByCode[$cc] ?? null,
                'endemic'      => $end,
                'top_poe'      => $topPoeByCode[$cc] ?? null,
            ];
        }

        // Filter chips.
        if ($cat === 'endemic') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['endemic']));
        } elseif ($cat === 'non_endemic') {
            $rows = array_values(array_filter($rows, fn ($r) => ! $r['endemic']));
        }

        // Search.
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = array_values(array_filter($rows, fn ($r) =>
                str_contains(mb_strtolower((string) $r['country']), $needle) ||
                str_contains(mb_strtolower((string) $r['country_code']), $needle) ||
                str_contains(mb_strtolower((string) $r['iso2']), $needle)
            ));
        }

        // Sort.
        $sortable = ['country', 'iso2', 'travellers', 'alerts_', 'top_disease', 'endemic', 'top_poe'];
        $sortKey  = in_array($sort, $sortable, true) ? $sort : 'travellers';
        usort($rows, function ($a, $b) use ($sortKey, $dir) {
            $av = $a[$sortKey] ?? null; $bv = $b[$sortKey] ?? null;
            if (is_bool($av)) { $av = $av ? 1 : 0; }
            if (is_bool($bv)) { $bv = $bv ? 1 : 0; }
            if ($av === null && $bv === null) return 0;
            if ($av === null) return 1;
            if ($bv === null) return -1;
            $cmp = is_numeric($av) && is_numeric($bv) ? ($av <=> $bv) : strcmp((string) $av, (string) $bv);
            return $dir === 'asc' ? $cmp : -$cmp;
        });

        $total      = count($rows);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $slice      = array_slice($rows, ($page - 1) * $perPage, $perPage);

        // Counts for chips.
        $endemicCount = 0;
        $nonEndemicCount = 0;
        foreach ($rows as $r) {
            if ($r['endemic']) { $endemicCount++; } else { $nonEndemicCount++; }
        }

        return $this->ok([
            'rows' => $slice,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $totalPages,
                'from'        => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to'          => min($page * $perPage, $total),
            ],
            'controls' => ['sort' => $sortKey, 'dir' => $dir, 'q' => $q, 'cat' => $cat],
            'category_counts' => [
                'all'         => $total,
                'endemic'     => $endemicCount,
                'non_endemic' => $nonEndemicCount,
            ],
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 6.  recordDetail — drill-down by country_code.
     * ────────────────────────────────────────────────────────────────── */
    public function recordDetail(Request $request, string $key): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $info = DB::table('ref_countries')->where('country_code', $key)
            ->whereNull('deleted_at')
            ->first(['country_code', 'name', 'iso_alpha2', 'iso_alpha3', 'is_active']);

        // Endemicity rows.
        $endemicityRaw = DB::table('ref_endemic_countries')
            ->where('country_code', $key)
            ->where('is_active', 1)
            ->orderByDesc('since_year')
            ->limit(50)
            ->get(['disease_code', 'endemicity_level', 'since_year', 'source', 'last_verified_at']);
        // Resolve disease names via two-query whereIn.
        $diseaseCodes = $endemicityRaw->pluck('disease_code')->unique()->values()->all();
        $diseaseNames = empty($diseaseCodes) ? [] : DB::table('ref_diseases')
            ->whereIn('disease_code', $diseaseCodes)
            ->pluck('display_name', 'disease_code')->all();
        $endemicity = $endemicityRaw->map(fn ($r) => [
            'disease_code'      => $r->disease_code,
            'display_name'      => $diseaseNames[$r->disease_code] ?? $r->disease_code,
            'endemicity_level'  => $r->endemicity_level,
            'since_year'        => $r->since_year,
            'source'            => $r->source,
            'last_verified_at'  => $r->last_verified_at,
        ]);

        // In-window scoped sec ids.
        $secIds = $this->scopedSecondaryIds($scope, $f, $from, $to);
        if (empty($secIds)) {
            return $this->ok([
                'country'    => $info ? (array) $info : ['country_code' => $key],
                'kpi_strip'  => ['travellers' => 0, 'alerts' => 0, 'alert_rate' => null, 'endemic' => ! $endemicity->isEmpty()],
                'endemicity' => $endemicity,
                'travellers' => [],
                'top_diseases' => [],
                'top_poes'   => [],
                'sparkline'  => [],
                'window'     => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            ]);
        }

        // Sec ids matching this country origin.
        $countrySecIds = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereIn('id', $secIds)
            ->where('journey_start_country_code', $key)
            ->pluck('id')->all();

        $travellersN = count($countrySecIds);
        $alertsN = empty($countrySecIds) ? 0 : (int) DB::table('alerts')
            ->whereNull('deleted_at')
            ->whereIn('secondary_screening_id', $countrySecIds)
            ->count();
        $alertRate = $this->safePct($alertsN, $travellersN);

        // Recent travellers (latest 20) — PII masked for non-super non-PHEOC.
        $travellersList = empty($countrySecIds) ? collect() : DB::table('secondary_screenings')
            ->whereIn('id', $countrySecIds)
            ->orderByDesc('opened_at')
            ->limit(20)
            ->get([
                'id', 'traveler_full_name', 'traveler_gender', 'traveler_age_years',
                'phone_number', 'travel_document_number',
                'arrival_datetime', 'transport_mode', 'poe_code',
                'risk_level', 'triage_category', 'final_disposition',
                'opened_at',
            ]);
        $travellers = $travellersList->map(function ($r) use ($scope) {
            $row = [
                'id'              => (int) $r->id,
                'name'            => $r->traveler_full_name,
                'gender'          => $r->traveler_gender,
                'age'             => $r->traveler_age_years,
                'phone_number'    => $r->phone_number,
                'travel_document_number' => $r->travel_document_number,
                'arrival_datetime'=> $r->arrival_datetime,
                'transport_mode'  => $r->transport_mode,
                'poe_code'        => $r->poe_code,
                'risk_level'      => $r->risk_level,
                'triage_category' => $r->triage_category,
                'final_disposition'=> $r->final_disposition,
                'opened_at'       => $r->opened_at,
            ];
            return $this->access->maskPii($row, $scope);
        });

        // Top diseases for those screenings (two-query).
        $diseaseRows = empty($countrySecIds) ? collect() : DB::table('secondary_suspected_diseases')
            ->whereIn('secondary_screening_id', $countrySecIds)
            ->selectRaw('disease_code, COUNT(*) AS c')
            ->groupBy('disease_code')
            ->orderByDesc('c')
            ->limit(10)
            ->get();
        $codes = $diseaseRows->pluck('disease_code')->all();
        $names = empty($codes) ? [] : DB::table('ref_diseases')
            ->whereIn('disease_code', $codes)
            ->pluck('display_name', 'disease_code')->all();
        $topDiseases = $diseaseRows->map(fn ($r) => [
            'disease_code' => $r->disease_code,
            'display_name' => $names[$r->disease_code] ?? $r->disease_code,
            'count'        => (int) $r->c,
        ]);

        // Top POEs for those screenings.
        $topPoes = empty($countrySecIds) ? collect() : DB::table('secondary_screenings')
            ->whereIn('id', $countrySecIds)
            ->selectRaw('poe_code, COUNT(*) AS c')
            ->groupBy('poe_code')
            ->orderByDesc('c')
            ->limit(8)
            ->get();

        // 30-day spark.
        $sparkRaw = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereIn('id', $countrySecIds ?: [-1])
            ->where('opened_at', '>=', Carbon::now()->subDays(29))
            ->selectRaw('DATE(opened_at) AS d, COUNT(*) AS c')
            ->groupBy(DB::raw('DATE(opened_at)'))
            ->pluck('c', 'd')->all();
        $spark = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->toDateString();
            $spark[] = ['date' => $d, 'count' => (int) ($sparkRaw[$d] ?? 0)];
        }

        return $this->ok([
            'country'      => $info ? (array) $info : ['country_code' => $key],
            'kpi_strip'    => [
                'travellers' => $travellersN,
                'alerts'     => $alertsN,
                'alert_rate' => $alertRate,
                'endemic'    => ! $endemicity->isEmpty(),
            ],
            'endemicity'   => $endemicity,
            'travellers'   => $travellers,
            'top_diseases' => $topDiseases,
            'top_poes'     => $topPoes,
            'sparkline'    => $spark,
            'window'       => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 7.  filterRules — extends parent.
     * ────────────────────────────────────────────────────────────────── */
    protected function filterRules(): array
    {
        return parent::filterRules() + [
            'sort' => ['nullable', 'in:country,iso2,travellers,alerts_,top_disease,endemic,top_poe'],
            'dir'  => ['nullable', 'in:asc,desc'],
            'cat'  => ['nullable', 'in:all,endemic,non_endemic'],
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     * Internal — chart builders.
     * ────────────────────────────────────────────────────────────────── */
    private function chartTopOrigins(array $scope, array $f): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($f);
        $secIds = $this->scopedSecondaryIds($scope, $f, $from, $to);
        if (empty($secIds)) {
            return [
                'labels' => [], 'datasets' => [
                    ['label' => 'Travellers', 'data' => []],
                    ['label' => 'Alerts',     'data' => []],
                ],
                'csv_headers' => ['Country', 'Travellers', 'Alerts'],
                'csv_rows'    => [],
                'footnote'    => 'Based on secondary-screened travellers (primary tier has no origin country).',
            ];
        }

        $rows = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereIn('id', $secIds)
            ->whereNotNull('journey_start_country_code')
            ->selectRaw('journey_start_country_code AS cc, COUNT(*) AS c')
            ->groupBy('journey_start_country_code')
            ->orderByDesc('c')
            ->limit(self::TOP_LIMIT)
            ->get();

        $codes = $rows->pluck('cc')->filter()->unique()->values()->all();
        $names = empty($codes) ? [] : DB::table('ref_countries')
            ->whereIn('country_code', $codes)
            ->where('is_active', 1)
            ->pluck('name', 'country_code')->all();

        // Per-code alert counts via JOIN that targets only secondary_screenings
        // (not ref_*) — collation-safe because both sides are utf8mb4_unicode_ci.
        $alertCounts = [];
        if (! empty($codes)) {
            $arows = DB::table('alerts AS a')->whereNull('a.deleted_at')
                ->join('secondary_screenings AS s', 's.id', '=', 'a.secondary_screening_id')
                ->whereIn('s.id', $secIds)
                ->whereIn('s.journey_start_country_code', $codes)
                ->selectRaw('s.journey_start_country_code AS cc, COUNT(*) AS c')
                ->groupBy('s.journey_start_country_code')
                ->get();
            foreach ($arows as $r) { $alertCounts[(string) $r->cc] = (int) $r->c; }
        }

        $labels = $tr = $al = $csv = [];
        foreach ($rows as $r) {
            $cc   = (string) $r->cc;
            $name = $names[$cc] ?? $cc;
            $labels[] = $name;
            $tr[]     = (int) $r->c;
            $al[]     = (int) ($alertCounts[$cc] ?? 0);
            $csv[]    = [$name, (int) $r->c, (int) ($alertCounts[$cc] ?? 0)];
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                ['label' => 'Travellers', 'data' => $tr],
                ['label' => 'Alerts',     'data' => $al],
            ],
            'csv_headers' => ['Country', 'Travellers', 'Alerts'],
            'csv_rows'    => $csv,
            'footnote'    => 'Based on secondary-screened travellers (primary tier has no origin country).',
        ];
    }

    private function chartEndemicFlow30d(array $scope, array $f): array
    {
        $from = Carbon::now()->subDays(29)->startOfDay();
        $to   = Carbon::now()->endOfDay();

        // 30-day in-scope sec ids.
        $secIds = $this->scopedSecondaryIdsForRange($scope, $f, $from, $to);
        if (empty($secIds)) {
            return [
                'labels' => array_map(fn ($i) => Carbon::now()->subDays(29 - $i)->format('d M'), range(0, 29)),
                'datasets' => [
                    ['label' => 'Endemic',     'data' => array_fill(0, 30, 0)],
                    ['label' => 'Non-endemic', 'data' => array_fill(0, 30, 0)],
                ],
                'csv_headers' => ['Date', 'Endemic', 'Non-endemic'],
                'csv_rows'    => [],
                'footnote'    => 'Based on secondary-screened travellers (primary tier has no origin country).',
            ];
        }

        $rows = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereIn('id', $secIds)
            ->whereNotNull('journey_start_country_code')
            ->selectRaw('DATE(opened_at) AS d, journey_start_country_code AS cc, COUNT(*) AS c')
            ->groupBy(DB::raw('DATE(opened_at)'), 'journey_start_country_code')
            ->get();

        $codes = $rows->pluck('cc')->filter()->unique()->values()->all();
        $endemicCodes = $this->resolveEndemicOriginCodes($codes);
        $endemicSet   = array_flip($endemicCodes);

        $endemicByDay = [];
        $otherByDay   = [];
        foreach ($rows as $r) {
            $d = (string) $r->d;
            if (isset($endemicSet[(string) $r->cc])) {
                $endemicByDay[$d] = ($endemicByDay[$d] ?? 0) + (int) $r->c;
            } else {
                $otherByDay[$d]   = ($otherByDay[$d] ?? 0) + (int) $r->c;
            }
        }

        $labels = $end = $oth = $csv = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->toDateString();
            $disp = Carbon::now()->subDays($i)->format('d M');
            $labels[] = $disp;
            $e = (int) ($endemicByDay[$d] ?? 0);
            $o = (int) ($otherByDay[$d]   ?? 0);
            $end[] = $e;
            $oth[] = $o;
            $csv[] = [$disp, $e, $o];
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                ['label' => 'Endemic',     'data' => $end],
                ['label' => 'Non-endemic', 'data' => $oth],
            ],
            'csv_headers' => ['Date', 'Endemic', 'Non-endemic'],
            'csv_rows'    => $csv,
            'footnote'    => 'Based on secondary-screened travellers (primary tier has no origin country).',
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     * Internal — secondary-screening scope helpers.
     * ────────────────────────────────────────────────────────────────── */
    private function scopedSecondaryQuery(array $scope, array $f, Carbon $from, Carbon $to)
    {
        $q = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($q, $scope);
        if (! empty($f['poe']))    { $q->where('poe_code', $f['poe']); }
        if (! empty($f['gender'])) { $q->where('traveler_gender', $f['gender']); }
        return $q;
    }

    private function scopedSecondaryIds(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        return $this->scopedSecondaryQuery($scope, $f, $from, $to)->pluck('id')->all();
    }

    /** 30-day chart variant of scopedSecondaryIds — same shape, fixed window. */
    private function scopedSecondaryIdsForRange(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        $q = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($q, $scope);
        if (! empty($f['poe'])) { $q->where('poe_code', $f['poe']); }
        return $q->pluck('id')->all();
    }

    /**
     * Filter a sec id list down to those whose origin country matches an
     * endemic code. Single grouped query — no PHP loop over sec rows.
     */
    private function secIdsForOriginCodes(array $secIds, array $codes): array
    {
        if (empty($secIds) || empty($codes)) { return []; }
        return DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereIn('id', $secIds)
            ->whereIn('journey_start_country_code', $codes)
            ->pluck('id')->all();
    }

    /**
     * Top disease (display name) per origin country — two-query whereIn for
     * both the disease aggregation and the ref_diseases display lookup.
     */
    private function topDiseasePerCountry(array $secIds, array $codes): array
    {
        if (empty($secIds) || empty($codes)) { return []; }

        // Pull (cc, sec_id) pairs for the in-scope secondaries (small N when
        // bounded by the country whitelist).
        $pairs = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereIn('id', $secIds)
            ->whereIn('journey_start_country_code', $codes)
            ->select('id', 'journey_start_country_code')
            ->get();

        $byCountry = [];
        foreach ($pairs as $p) { $byCountry[(string) $p->journey_start_country_code][] = (int) $p->id; }

        // Aggregate disease counts per country in PHP via grouped queries —
        // but to avoid one query per country, pull all disease rows for the
        // union of sec ids, then bucket in PHP.
        $allSecIds = array_merge(...array_values($byCountry));
        if (empty($allSecIds)) { return []; }

        $diseaseRows = DB::table('secondary_suspected_diseases')
            ->whereIn('secondary_screening_id', $allSecIds)
            ->select('secondary_screening_id', 'disease_code')
            ->get();

        // Map sec_id → country.
        $secToCountry = [];
        foreach ($byCountry as $cc => $ids) { foreach ($ids as $id) { $secToCountry[$id] = $cc; } }

        // Bucket: country → disease_code → count.
        $bucket = [];
        foreach ($diseaseRows as $dr) {
            $cc = $secToCountry[(int) $dr->secondary_screening_id] ?? null;
            if ($cc === null) { continue; }
            $bucket[$cc][(string) $dr->disease_code] = ($bucket[$cc][(string) $dr->disease_code] ?? 0) + 1;
        }

        // Pick top per country.
        $topCodeByCountry = [];
        $allTopCodes = [];
        foreach ($bucket as $cc => $counts) {
            arsort($counts);
            $top = array_key_first($counts);
            if ($top !== null) {
                $topCodeByCountry[$cc] = $top;
                $allTopCodes[] = $top;
            }
        }

        // Resolve display names via two-query whereIn.
        $allTopCodes = array_values(array_unique($allTopCodes));
        $names = empty($allTopCodes) ? [] : DB::table('ref_diseases')
            ->whereIn('disease_code', $allTopCodes)
            ->pluck('display_name', 'disease_code')->all();

        $out = [];
        foreach ($topCodeByCountry as $cc => $code) {
            $out[$cc] = $names[$code] ?? $code;
        }
        return $out;
    }

    /** Top POE per country (modal helper). Single grouped query. */
    private function topPoePerCountry(array $secIds, array $codes): array
    {
        if (empty($secIds) || empty($codes)) { return []; }
        $rows = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereIn('id', $secIds)
            ->whereIn('journey_start_country_code', $codes)
            ->whereNotNull('poe_code')
            ->selectRaw('journey_start_country_code AS cc, poe_code, COUNT(*) AS c')
            ->groupBy('journey_start_country_code', 'poe_code')
            ->get();
        $best = [];
        foreach ($rows as $r) {
            $cc = (string) $r->cc;
            if (! isset($best[$cc]) || $r->c > $best[$cc]['c']) {
                $best[$cc] = ['poe' => (string) $r->poe_code, 'c' => (int) $r->c];
            }
        }
        $out = [];
        foreach ($best as $cc => $b) { $out[$cc] = $b['poe']; }
        return $out;
    }

    /**
     * Resolve which of the supplied origin codes are endemic for any active
     * disease. Two-query lookup — first against ref_endemic_countries by
     * country_code; then via ref_countries.iso_alpha3/iso_alpha2 if the
     * direct lookup misses (legacy origin codes are sometimes country names
     * like "DRC" / "Zambia").
     */
    private function resolveEndemicOriginCodes(array $codes): array
    {
        if (empty($codes)) { return []; }

        // Direct hit: origin code matches ref_endemic_countries.country_code.
        $direct = DB::table('ref_endemic_countries')
            ->whereIn('country_code', $codes)
            ->where('is_active', 1)
            ->pluck('country_code')->unique()->values()->all();

        $matched = array_flip($direct);
        $unmatched = array_values(array_diff($codes, array_keys($matched)));
        if (empty($unmatched)) { return $direct; }

        // Lookup via ref_countries → iso_alpha3 / iso_alpha2 → re-check endemic.
        $refRows = DB::table('ref_countries')
            ->whereIn('country_code', $unmatched)
            ->where('is_active', 1)
            ->get(['country_code', 'iso_alpha2', 'iso_alpha3']);

        $isoCodes = [];
        foreach ($refRows as $r) {
            if ($r->iso_alpha3) { $isoCodes[strtoupper((string) $r->iso_alpha3)] = (string) $r->country_code; }
            if ($r->iso_alpha2) { $isoCodes[strtoupper((string) $r->iso_alpha2)] = (string) $r->country_code; }
        }
        if (empty($isoCodes)) { return $direct; }

        $isoEndemic = DB::table('ref_endemic_countries')
            ->whereIn('country_code', array_keys($isoCodes))
            ->where('is_active', 1)
            ->pluck('country_code')->unique()->values()->all();

        foreach ($isoEndemic as $iso) {
            $iso = strtoupper((string) $iso);
            if (isset($isoCodes[$iso])) { $matched[$isoCodes[$iso]] = true; }
        }
        return array_keys($matched);
    }

    /**
     * Replace the `cc → poe` portion of the top route label with the country
     * display name — best-effort lookup.
     */
    private function resolveTopRouteLabel(string $label): string
    {
        if ($label === '—' || ! str_contains($label, ' → ')) { return $label; }
        [$cc, $poe] = explode(' → ', $label, 2);
        $name = DB::table('ref_countries')->where('country_code', $cc)->where('is_active', 1)->value('name');
        return ($name ?: $cc) . ' → ' . $poe;
    }

    private function emptyKpis(): array
    {
        return [
            ['key' => 'travellers_total',   'label' => 'Travellers Screened',   'value' => '0', 'tone' => 'neutral', 'hint' => 'No secondary-tier screenings in window.'],
            ['key' => 'unique_origins',     'label' => 'Origin Countries',      'value' => '0', 'tone' => 'neutral', 'hint' => 'No data.'],
            ['key' => 'endemic_share_pct',  'label' => 'From Endemic Countries','value' => '—', 'tone' => 'neutral', 'hint' => 'Insufficient denominator.'],
            ['key' => 'top_route',          'label' => 'Top Route',             'value' => '—', 'tone' => 'neutral', 'hint' => 'No data.'],
            ['key' => 'endemic_alert_rate', 'label' => 'Endemic Alert Rate',    'value' => '—', 'tone' => 'neutral', 'hint' => 'Insufficient denominator.'],
        ];
    }
}
