<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\QuickReports;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quick Report · Country Analysis.
 *
 * URL:    /admin/quick-reports/country-analysis
 *
 * Question: "Where are our travellers coming from, where have they been,
 * and which countries are linked to endemic-disease activity?"
 *
 * Cohort: in-scope secondary screenings in window. Two country sources:
 *   1. Traveller nationality (`secondary_screenings.traveler_nationality_country_code`)
 *   2. Travel countries (`secondary_travel_countries`, with travel_role VISITED|TRANSIT)
 *
 * Endemic enrichment: cross-references `ref_endemic_countries.country_code`
 * (1,154 disease-country pairs in prod). A country flagged "endemic" carries
 * an info pill in the table and a footnote in the chart.
 *
 * Adaptive chart:
 *   A. Top traveller nationalities
 *   B. Top visited countries
 *   C. Top transit countries
 *   D. Empty
 */
final class CountryAnalysisController extends BaseQuickReportController
{
    protected string $reportKey   = 'qr-country';
    protected string $reportTitle = 'Country Analysis';

    private const TABLE_LIMIT = 20;
    private const CHART_TOP_N = 12;

    private const MATERIAL_PALETTE = [
        '#E53935','#1E88E5','#43A047','#FB8C00','#8E24AA','#00ACC1',
        '#F4511E','#3949AB','#7CB342','#D81B60','#FFB300','#00897B',
    ];

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.quick.country.index', [
            'scope' => $scope, 'reportKey' => $this->reportKey, 'reportTitle' => $this->reportTitle,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->applyDefaultWindow($this->readFilters($request));
        $payload = $this->memoise((int) ($scope['user_id'] ?? 0), $filters,
            fn () => $this->buildPayload($scope, $filters));
        $payload['filters'] = $filters;
        $payload['scope']   = ['label' => $scope['label'] ?? '—', 'level' => $scope['scope_level'] ?? 'SELF'];
        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->applyDefaultWindow($this->readFilters($request));
        $payload = $this->buildPayload($scope, $filters);

        $headers = ['Opened (Africa/Kampala)','Traveller','Nationality','Visited (codes)','Transit (codes)','Endemic match','Point of entry','Case file URL'];
        $rows = [];
        foreach ($payload['table_full'] as $r) {
            $rows[] = [
                $r['opened_at_label'], $r['traveller_name'],
                ($r['nationality_name'] ?? '') . ($r['nationality'] ? ' ('.$r['nationality'].')' : ''),
                implode(' · ', $r['visited'] ?? []) ?: '—',
                implode(' · ', $r['transit'] ?? []) ?: '—',
                $r['endemic_match'] ? 'Yes — '.implode(', ', $r['endemic_diseases'] ?? []) : 'No',
                $r['poe_name'] ?? '—',
                $r['case_file_url'] ?? '',
            ];
        }
        return $this->writer->send($this->reportKey, (string) $request->input('format', 'CSV'),
            $headers, $rows, $filters, (int) ($scope['user_id'] ?? 0), $this->reportTitle);
    }

    public function buildPayload(array $scope, array $filters): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($filters);
        $windowLabel = $this->windowLabel($from, $to);

        // ── 1. In-scope cohort ─────────────────────────────────────────────
        $secQ = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($secQ, $scope);

        if (! empty($filters['poe'])) { $secQ->where('poe_code', (string) $filters['poe']); }

        $sec = $secQ->select([
            'id','client_uuid','opened_at','traveler_full_name','traveler_initials','traveler_anonymous_code',
            'traveler_age_years','traveler_gender','traveler_nationality_country_code','poe_code',
        ])->orderBy('opened_at','desc')->orderBy('id','desc')->get();

        // De-dup by client_uuid
        $byUuid = []; $dedup = [];
        foreach ($sec as $r) {
            if (! $r->client_uuid) { $dedup[] = $r; continue; }
            if (! isset($byUuid[$r->client_uuid]) || (int) $r->id > (int) $byUuid[$r->client_uuid]->id) {
                $byUuid[$r->client_uuid] = $r;
            }
        }
        foreach ($byUuid as $r) { $dedup[] = $r; }
        $sec = collect($dedup);
        $secIds = $sec->pluck('id')->map(fn ($v) => (int) $v)->all();

        // ── Alert-id lookup per secondary (for canonical case-file URL) ────
        $alertIdBySid = $secIds
            ? DB::table('alerts')->whereNull('deleted_at')
                ->whereIn('secondary_screening_id', $secIds)
                ->orderBy('id')
                ->pluck('id', 'secondary_screening_id')->all()
            : [];

        // ── 2. Travel countries per case ───────────────────────────────────
        $travelRows = $secIds ? DB::table('secondary_travel_countries')
            ->whereIn('secondary_screening_id', $secIds)
            ->get(['secondary_screening_id','country_code','travel_role']) : collect();

        $visitedBySec = []; $transitBySec = [];
        $visitedCodes = []; $transitCodes = []; $nationalityCodes = [];

        foreach ($travelRows as $r) {
            $sid  = (int) $r->secondary_screening_id;
            $code = strtoupper((string) $r->country_code);
            if ($r->travel_role === 'TRANSIT') {
                $transitBySec[$sid][] = $code;
                $transitCodes[$code] = ($transitCodes[$code] ?? 0) + 1;
            } else {
                $visitedBySec[$sid][] = $code;
                $visitedCodes[$code] = ($visitedCodes[$code] ?? 0) + 1;
            }
        }
        foreach ($sec as $s) {
            $code = strtoupper((string) ($s->traveler_nationality_country_code ?? ''));
            if ($code !== '') { $nationalityCodes[$code] = ($nationalityCodes[$code] ?? 0) + 1; }
        }

        // ── 3. Country display names + endemic lookup ──────────────────────
        $allCodes = array_values(array_unique(array_merge(
            array_keys($nationalityCodes), array_keys($visitedCodes), array_keys($transitCodes),
        )));
        $countryNames = $allCodes
            ? DB::table('ref_countries')->whereIn('country_code', $allCodes)->pluck('name','country_code')->all()
            : [];

        // Endemic flags: any (country, disease) pair counts as "endemic match"
        // for the country. We store the list of diseases so the case row can
        // surface them (no full join — collation-safe).
        $endemicByCountry = []; // code => [disease_code, ...]
        if ($allCodes) {
            $rows = DB::table('ref_endemic_countries')
                ->whereIn('country_code', $allCodes)
                ->where('is_active', 1)
                ->whereIn('endemicity_level', ['ENDEMIC', 'OUTBREAK_ACTIVE', 'OUTBREAK_RECENT'])
                ->get(['country_code','disease_code']);
            foreach ($rows as $r) {
                $endemicByCountry[strtoupper((string) $r->country_code)][] = strtolower((string) $r->disease_code);
            }
        }

        $poeNames = $sec->pluck('poe_code')->filter()->unique()->values()->all();
        $poeNames = $poeNames ? DB::table('ref_poes')->whereIn('poe_code', $poeNames)->pluck('poe_name','poe_code')->all() : [];

        // ── 4. Per-case rows ───────────────────────────────────────────────
        $rows = [];
        $kpi24h = 0;
        $endemicCases = 0;
        $now24h = Carbon::now()->subDay();
        foreach ($sec as $s) {
            $sid = (int) $s->id;
            $natCode = strtoupper((string) ($s->traveler_nationality_country_code ?? ''));
            $visited = $visitedBySec[$sid] ?? [];
            $transit = $transitBySec[$sid] ?? [];

            // Determine endemic match (across nationality + visited + transit)
            $endemicDiseases = [];
            foreach ([$natCode, ...$visited, ...$transit] as $cc) {
                if ($cc !== '' && isset($endemicByCountry[$cc])) {
                    foreach ($endemicByCountry[$cc] as $d) { $endemicDiseases[$d] = true; }
                }
            }
            $isEndemic = ! empty($endemicDiseases);
            if ($isEndemic) { $endemicCases++; }

            try {
                if ($s->opened_at && Carbon::parse((string) $s->opened_at)->greaterThanOrEqualTo($now24h)) { $kpi24h++; }
            } catch (\Throwable $e) { /* skip */ }

            $row = [
                'id'              => $sid,
                'opened_at_iso'   => (string) $s->opened_at,
                'opened_at_label' => $this->humanDate((string) $s->opened_at),
                'traveller_name'  => $this->displayName($s),
                'age'             => $s->traveler_age_years !== null ? (int) $s->traveler_age_years : null,
                'sex'             => $s->traveler_gender,
                'nationality'     => $natCode ?: null,
                'nationality_name'=> $natCode ? ($countryNames[$natCode] ?? null) : null,
                'visited'         => $visited,
                'transit'         => $transit,
                'endemic_match'   => $isEndemic,
                'endemic_diseases'=> array_slice(array_keys($endemicDiseases), 0, 5),
                'poe_name'        => $poeNames[$s->poe_code] ?? $s->poe_code,
                'case_file_url'   => isset($alertIdBySid[$sid])
                    ? url("/admin/alerts/{$alertIdBySid[$sid]}/case-file")
                    : url("/admin/reports/rpt-case-files/{$sid}"),
                'alert_id'        => $alertIdBySid[$sid] ?? null,
            ];

            if (! empty($filters['q'])) {
                $needle = strtolower((string) $filters['q']);
                $hay = strtolower(implode(' ', array_filter([
                    $row['traveller_name'], $natCode, $row['nationality_name'],
                    implode(' ', $visited), implode(' ', $transit), $row['poe_name'],
                ])));
                if (strpos($hay, $needle) === false) { continue; }
            }
            $rows[] = $row;
        }

        // Sort: endemic first, then recency
        usort($rows, function ($a, $b) {
            if ($a['endemic_match'] !== $b['endemic_match']) { return $a['endemic_match'] ? -1 : 1; }
            return strcmp((string) $b['opened_at_iso'], (string) $a['opened_at_iso']);
        });

        $tableVisible = array_slice($rows, 0, self::TABLE_LIMIT);

        $kpis = [
            'total_cases'         => count($rows),
            'distinct_nationalities' => count($nationalityCodes),
            'distinct_visited'    => count($visitedCodes),
            'distinct_transit'    => count($transitCodes),
            'endemic_cases'       => $endemicCases,
            'top_nationality'     => (function () use ($nationalityCodes, $countryNames) {
                if (! $nationalityCodes) { return null; }
                $sorted = $nationalityCodes; arsort($sorted);
                $code = (string) array_key_first($sorted);
                $name = $countryNames[$code] ?? null;
                return $name ? "{$name} ({$code})" : $code;
            })(),
            'last_24h'            => $kpi24h,
        ];

        $chart = $this->pickChart($nationalityCodes, $visitedCodes, $transitCodes, $countryNames, $endemicByCountry, $windowLabel);

        return [
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
                         'days' => (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400) + 1,
                         'label' => $windowLabel],
            'kpis'       => $kpis,
            'chart'      => $chart,
            'table'      => $tableVisible,
            'table_full' => $rows,
            'total_rows' => count($rows),
            'shown_rows' => count($tableVisible),
            'meta' => [
                'poes' => $this->scope->allowedPoes($scope),
            ],
        ];
    }

    /**
     * Adaptive chart. Endemic-country bars get red across all three lenses
     * so the eye snaps to imported-disease risk regardless of which lens we
     * end up showing.
     */
    private function pickChart(array $nat, array $visited, array $transit, array $countryNames, array $endemicByCountry, string $windowLabel): array
    {
        $build = function (array $bucket, string $label) use ($countryNames, $endemicByCountry) {
            arsort($bucket);
            $labels = []; $values = []; $colors = []; $i = 0;
            foreach ($bucket as $cc => $count) {
                if ($i >= self::CHART_TOP_N) { break; }
                // Don't print "RW (RW)" when ref_countries has no entry — fall back to bare ISO code.
                $name = $countryNames[$cc] ?? null;
                $labels[] = $name ? "{$name} ({$cc})" : (string) $cc;
                $values[] = (int) $count;
                $colors[] = isset($endemicByCountry[$cc]) ? '#C62828' : self::MATERIAL_PALETTE[$i % count(self::MATERIAL_PALETTE)];
                $i++;
            }
            return [$labels, $values, $colors];
        };

        if (array_filter($nat)) {
            [$labels, $values, $colors] = $build($nat, 'nat');
            return [
                'kind' => 'nationalities',
                'title' => 'Top traveller nationalities',
                'subtitle' => 'Where the travellers are passport-holders. Red bars = nationality matches a country with endemic disease activity (immediate epi risk).',
                'labels' => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'cases',
            ];
        }

        if (array_filter($visited)) {
            [$labels, $values, $colors] = $build($visited, 'visited');
            return [
                'kind' => 'visited',
                'title' => 'Top visited countries',
                'subtitle' => 'Where travellers had been before screening. Red bars = country has endemic disease activity.',
                'labels' => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'visits',
            ];
        }

        if (array_filter($transit)) {
            [$labels, $values, $colors] = $build($transit, 'transit');
            return [
                'kind' => 'transit',
                'title' => 'Top transit countries',
                'subtitle' => 'Where travellers stopped en route. Red bars = endemic-disease country.',
                'labels' => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'transits',
            ];
        }

        return [
            'kind' => 'empty',
            'title' => 'No country data',
            'subtitle' => 'No cases with traveller country information in this window.',
            'labels' => [], 'values' => [], 'colors' => [], 'unit' => 'cases',
        ];
    }

    private function displayName(?object $s): string
    {
        if (! $s) { return 'Unknown traveller'; }
        $full = trim((string) ($s->traveler_full_name ?? ''));
        if ($full !== '') { return $full; }
        $init = trim((string) ($s->traveler_initials ?? ''));
        if ($init !== '') { return $init; }
        $anon = trim((string) ($s->traveler_anonymous_code ?? ''));
        if ($anon !== '') { return $anon; }
        return 'Unknown traveller';
    }

    private function humanDate(string $iso): string
    {
        if ($iso === '') { return '—'; }
        try { return Carbon::parse($iso)->setTimezone(config('app.timezone','Africa/Kampala'))->format('M j, H:i'); }
        catch (\Throwable $e) { return $iso; }
    }
}
