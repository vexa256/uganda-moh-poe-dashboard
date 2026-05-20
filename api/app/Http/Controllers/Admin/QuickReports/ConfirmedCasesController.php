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
 * Quick Report · Confirmed Cases.
 *
 * URL:        /admin/quick-reports/confirmed-cases
 * Data:       /admin/quick-reports/confirmed-cases/data
 * Export CSV: /admin/quick-reports/confirmed-cases/export
 *
 * Question: "Of every alert opened in the window, how many were
 * laboratory-confirmed, what disease, and what happened clinically?"
 *
 * Authoritative source for confirmation classification (mirrors the
 * existing rpt-case-confirmation contract): the `alert_case_outcomes`
 * (aco) row attached to the alert.
 *
 *   aco.case_classification ──┬─ SUSPECTED        ↦ Suspected (funnel head)
 *                             ├─ PROBABLE         ↦ Probable
 *                             ├─ CONFIRMED        ↦ Confirmed   (the headline)
 *                             ├─ DISCARDED        ↦ Ruled-out
 *                             ├─ LOST_TO_FOLLOWUP ↦ Pending
 *                             ├─ UNKNOWN          ↦ Pending
 *                             └─ (no aco row)     ↦ Pending lab outcome
 *
 * Disease attribution prefers `aco.lab_disease_code` (what the lab actually
 * said). Falls back to the top-ranked `secondary_suspected_diseases.disease_code`
 * for not-yet-confirmed cases so the table still answers "what do we think
 * this is".
 *
 * Paranoia rules (same as Suspected Cases — and re-applied here):
 *   1. Soft-deletes excluded on alerts, aco, and secondary_screenings.
 *   2. Per (alert_id), only the latest aco row counts (multiple rows for the
 *      same alert can exist if the wizard was re-opened).
 *   3. No cross-collation JOINs — ref_diseases / ref_poes lookups are
 *      separate whereIn() + pluck() calls.
 *   4. KPIs, chart, and table all derive from the same alert+aco fetch.
 *   5. Table capped at 20 rows; total is the honest cohort size.
 */
final class ConfirmedCasesController extends BaseQuickReportController
{
    protected string $reportKey   = 'qr-confirmed';
    protected string $reportTitle = 'Confirmed Cases';

    private const TABLE_LIMIT = 20;
    private const CHART_TOP_N = 12;

    /** Display labels for each classification bucket. */
    private const CLASS_LABELS = [
        'CONFIRMED'        => 'Confirmed',
        'PROBABLE'         => 'Probable',
        'SUSPECTED'        => 'Suspected',
        'DISCARDED'        => 'Ruled out',
        'LOST_TO_FOLLOWUP' => 'Lost to follow-up',
        'UNKNOWN'          => 'Pending',
        'PENDING'          => 'Pending lab',
    ];

    /**
     * Semantic palette for the classification chart fallback.
     * Greens = good signal, reds = bad signal, amber/blue-grey = ambiguous.
     */
    private const CLASS_COLORS = [
        'CONFIRMED'        => '#C62828', // red 800 — actually positive, demands action
        'PROBABLE'         => '#E64A19', // deep-orange 700 — strong suspicion
        'SUSPECTED'        => '#FB8C00', // orange 600 — under investigation
        'DISCARDED'        => '#43A047', // green 600 — ruled out (good news)
        'LOST_TO_FOLLOWUP' => '#8E24AA', // purple 600 — data hygiene problem
        'UNKNOWN'          => '#546E7A', // blue-grey 600
        'PENDING'          => '#546E7A', // blue-grey 600
    ];

    /** Same Material categorical palette as Suspected Cases (sibling consistency). */
    private const MATERIAL_PALETTE = [
        '#E53935', '#1E88E5', '#43A047', '#FB8C00', '#8E24AA', '#00ACC1',
        '#F4511E', '#3949AB', '#7CB342', '#D81B60', '#FFB300', '#00897B',
        '#5E35B1', '#6D4C41',
    ];

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.quick.confirmed.index', [
            'scope'       => $scope,
            'reportKey'   => $this->reportKey,
            'reportTitle' => $this->reportTitle,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->applyDefaultWindow($this->readFilters($request));

        $payload = $this->memoise(
            (int) ($scope['user_id'] ?? 0),
            $filters,
            fn () => $this->buildPayload($scope, $filters),
        );

        $payload['filters'] = $filters;
        $payload['scope']   = $this->scopeBlock($scope);

        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->applyDefaultWindow($this->readFilters($request));
        $payload = $this->buildPayload($scope, $filters);

        $headers = [
            'Opened (Africa/Kampala)',
            'Lab confirmed at',
            'Traveller name',
            'Age', 'Sex', 'Nationality',
            'Disease (confirmed or suspected)',
            'Classification',
            'Lab method',
            'IHR tier',
            'IHR notified',
            'Clinical outcome',
            'Point of entry',
            'Alert reference',
            'Case file URL',
        ];
        $rows = [];
        foreach ($payload['table_full'] as $r) {
            $rows[] = [
                $r['opened_at_label'],
                $r['lab_confirmed_at_label'] ?? '—',
                $r['traveller_name'],
                $r['age']         ?? '',
                $r['sex']         ?? '',
                $r['nationality'] ?? '',
                $r['disease']     ?? '—',
                self::CLASS_LABELS[$r['classification']] ?? $r['classification'] ?? '—',
                $r['lab_method']  ?? '—',
                $r['ihr_tier']    ?? '—',
                ($r['ihr_notified'] ? 'Yes' : 'No'),
                $r['clinical_outcome'] ?? '—',
                $r['poe_name']    ?? '—',
                $r['alert_code']  ?? '—',
                $r['case_file_url'] ?? '',
            ];
        }

        return $this->writer->send(
            $this->reportKey,
            (string) $request->input('format', 'CSV'),
            $headers, $rows, $filters,
            (int) ($scope['user_id'] ?? 0),
            $this->reportTitle,
        );
    }

    public function buildPayload(array $scope, array $filters): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($filters);
        $windowLabel = $this->windowLabel($from, $to);

        // ── 1. Alert cohort (scope + filters applied) ──────────────────────
        $alertQ = DB::table('alerts')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to]);
        $this->scope->apply($alertQ, $scope);

        if (! empty($filters['poe']))      { $alertQ->where('poe_code', (string) $filters['poe']); }
        if (! empty($filters['ihr_tier'])) { $alertQ->where('ihr_tier', (int) $filters['ihr_tier']); }

        $alerts = $alertQ
            ->select([
                'id', 'client_uuid', 'alert_code', 'created_at', 'closed_at',
                'status', 'ihr_tier', 'poe_code', 'secondary_screening_id',
            ])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        // De-dup by client_uuid (same playbook as Suspected — mobile dup-bug).
        $byUuid = []; $dedup = [];
        foreach ($alerts as $a) {
            if (! $a->client_uuid) { $dedup[] = $a; continue; }
            if (! isset($byUuid[$a->client_uuid]) || (int) $a->id > (int) $byUuid[$a->client_uuid]->id) {
                $byUuid[$a->client_uuid] = $a;
            }
        }
        foreach ($byUuid as $a) { $dedup[] = $a; }
        usort($dedup, fn ($x, $y) => strcmp((string) $y->created_at, (string) $x->created_at) ?: ((int) $y->id <=> (int) $x->id));
        $alerts = collect($dedup);
        $alertIds = $alerts->pluck('id')->map(fn ($v) => (int) $v)->all();
        $secIds   = array_values(array_filter($alerts->pluck('secondary_screening_id')->map(fn ($v) => (int) $v)->all()));
        $totalAlerts = $alerts->count();

        // ── 2. Latest aco row per alert ────────────────────────────────────
        $acoRows = $alertIds ? DB::table('alert_case_outcomes')
            ->whereNull('deleted_at')
            ->whereIn('alert_id', $alertIds)
            ->orderBy('alert_id')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get(['alert_id', 'case_classification', 'lab_disease_code', 'lab_test_method',
                   'lab_confirmed_at', 'lab_status', 'clinical_outcome', 'clinical_outcome_at',
                   'ihr_notified', 'ihr_notified_at', 'outbreak_status', 'recorded_at'])
            : collect();
        $acoByAlert = [];
        foreach ($acoRows as $r) {
            $aid = (int) $r->alert_id;
            if (! isset($acoByAlert[$aid])) { $acoByAlert[$aid] = $r; }   // first wins → latest by ORDER BY
        }

        // ── 3. Secondary screening enrichment (traveller + suspected fallback) ─
        $sec = $secIds ? DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereIn('id', $secIds)
            ->get(['id', 'traveler_full_name', 'traveler_initials', 'traveler_anonymous_code',
                   'traveler_age_years', 'traveler_gender', 'traveler_nationality_country_code',
                   'opened_at'])
            : collect();
        $secById = [];
        foreach ($sec as $s) { $secById[(int) $s->id] = $s; }

        // Top-ranked suspected disease per secondary (only for un-confirmed rows).
        $suspectedTop = [];
        if ($secIds) {
            $rows = DB::table('secondary_suspected_diseases')
                ->whereIn('secondary_screening_id', $secIds)
                // 2026-05-20: exclude the 'no_specific_suspicion' placeholder
                // rows the mobile emits when the rule engine + officer override
                // produced fewer than three hypotheses. A confirmed case must
                // never be attributed to the padding code. If every row for a
                // case is placeholder, $suspectedTop[$sid] stays unset and the
                // case falls through to the lab_disease_code fallback below.
                ->where('disease_code', '!=', 'no_specific_suspicion')
                ->orderBy('secondary_screening_id')
                ->orderBy('rank_order')
                ->get(['secondary_screening_id', 'disease_code']);
            foreach ($rows as $r) {
                $sid = (int) $r->secondary_screening_id;
                if (! isset($suspectedTop[$sid])) { $suspectedTop[$sid] = (string) $r->disease_code; }
            }
        }

        // ── 4. Disease + POE display lookups (no JOIN — collation-safe) ───
        $diseaseCodes = collect()
            ->merge($acoRows->pluck('lab_disease_code'))
            ->merge(array_values($suspectedTop))
            ->filter()->unique()->values()->all();
        $diseaseNames = $diseaseCodes
            ? DB::table('ref_diseases')->whereIn('disease_code', $diseaseCodes)->pluck('display_name', 'disease_code')->all()
            : [];

        $poeCodes = $alerts->pluck('poe_code')->filter()->unique()->values()->all();
        $poeNames = $poeCodes
            ? DB::table('ref_poes')->whereIn('poe_code', $poeCodes)->pluck('poe_name', 'poe_code')->all()
            : [];

        // ── 5. Build per-row records (apply class/disease filters here) ────
        $diseaseFilter = ! empty($filters['disease']) ? (string) $filters['disease'] : null;
        $classFilter   = ! empty($filters['class'])   ? (string) $filters['class']   : null;
        $clinicalFilter= ! empty($filters['clinical']) ? (string) $filters['clinical'] : null;
        $ihrNotifiedFilter = array_key_exists('ihr_notified', $filters) && $filters['ihr_notified'] !== ''
            ? (int) $filters['ihr_notified'] : null;

        $rows = [];
        $classCounts = ['CONFIRMED'=>0,'PROBABLE'=>0,'SUSPECTED'=>0,'DISCARDED'=>0,'LOST_TO_FOLLOWUP'=>0,'PENDING'=>0];
        $confirmedDiseaseFreq = [];
        $poeBuckets = [];
        $dayBuckets = [];
        $kpi24h = 0;
        $now24h = Carbon::now()->subDay();

        foreach ($alerts as $a) {
            $aid    = (int) $a->id;
            $aco    = $acoByAlert[$aid] ?? null;
            $rawCls = $aco?->case_classification ?: 'PENDING';
            $cls    = in_array($rawCls, ['CONFIRMED','PROBABLE','SUSPECTED','DISCARDED','LOST_TO_FOLLOWUP'], true)
                      ? $rawCls : 'PENDING';

            $sid   = (int) ($a->secondary_screening_id ?? 0);
            $s     = $sid && isset($secById[$sid]) ? $secById[$sid] : null;
            $diseaseCode = $aco?->lab_disease_code ?: ($sid ? ($suspectedTop[$sid] ?? null) : null);
            $diseaseName = $diseaseCode ? ($diseaseNames[$diseaseCode] ?? $diseaseCode) : null;

            // Apply post-fetch filters (cheap PHP filtering keeps SQL simple).
            if ($classFilter    && $cls !== $classFilter)                                            { continue; }
            if ($diseaseFilter  && (string) $diseaseCode !== $diseaseFilter)                         { continue; }
            if ($clinicalFilter && strcasecmp((string) ($aco?->clinical_outcome ?? ''), $clinicalFilter) !== 0) { continue; }
            if ($ihrNotifiedFilter !== null && (int) ($aco?->ihr_notified ?? 0) !== $ihrNotifiedFilter)        { continue; }
            if (! empty($filters['q'])) {
                $needle = strtolower((string) $filters['q']);
                $hay    = strtolower(implode(' ', array_filter([
                    $s?->traveler_full_name, $s?->traveler_initials, $s?->traveler_anonymous_code,
                    $a->alert_code, $diseaseName,
                ])));
                if (strpos($hay, $needle) === false) { continue; }
            }

            $classCounts[$cls]++;
            if ($cls === 'CONFIRMED' && $diseaseName) {
                $confirmedDiseaseFreq[$diseaseName] = ($confirmedDiseaseFreq[$diseaseName] ?? 0) + 1;
            }
            $poeKey = (string) ($a->poe_code ?? '');
            if ($poeKey !== '') { $poeBuckets[$poeKey] = ($poeBuckets[$poeKey] ?? 0) + 1; }
            try {
                $dKey = Carbon::parse((string) $a->created_at)->setTimezone(config('app.timezone', 'Africa/Kampala'))->format('M j');
                $dayBuckets[$dKey] = ($dayBuckets[$dKey] ?? 0) + 1;
                if (Carbon::parse((string) $a->created_at)->greaterThanOrEqualTo($now24h)) { $kpi24h++; }
            } catch (\Throwable $e) { /* skip */ }

            $rows[] = [
                'alert_id'              => $aid,
                'alert_code'            => $a->alert_code,
                'opened_at_iso'         => (string) $a->created_at,
                'opened_at_label'       => $this->humanDate((string) $a->created_at),
                'lab_confirmed_at_iso'  => $aco?->lab_confirmed_at,
                'lab_confirmed_at_label'=> $aco?->lab_confirmed_at ? $this->humanDate((string) $aco->lab_confirmed_at) : null,
                'traveller_name'        => $this->displayName($s),
                'age'                   => $s?->traveler_age_years !== null ? (int) $s?->traveler_age_years : null,
                'sex'                   => $s?->traveler_gender,
                'nationality'           => $s?->traveler_nationality_country_code,
                'disease_code'          => $diseaseCode,
                'disease'               => $diseaseName,
                'classification'        => $cls,
                'classification_label'  => self::CLASS_LABELS[$cls] ?? $cls,
                'lab_method'            => $aco?->lab_test_method,
                'lab_status'            => $aco?->lab_status,
                'ihr_tier'              => $a->ihr_tier !== null ? (int) $a->ihr_tier : null,
                'ihr_notified'          => (int) ($aco?->ihr_notified ?? 0) === 1,
                'clinical_outcome'      => $aco?->clinical_outcome,
                'outbreak_status'       => $aco?->outbreak_status,
                'poe_name'              => $poeNames[$poeKey] ?? $poeKey ?: null,
                'case_file_url'         => url("/admin/alerts/{$aid}/case-file"),
            ];
        }

        $shownTotal = count($rows);

        // Re-sort: CONFIRMED first, then PROBABLE, then by created_at desc.
        $clsOrder = ['CONFIRMED'=>1,'PROBABLE'=>2,'SUSPECTED'=>3,'DISCARDED'=>4,'LOST_TO_FOLLOWUP'=>5,'PENDING'=>6];
        usort($rows, function ($a, $b) use ($clsOrder) {
            $oa = $clsOrder[$a['classification']] ?? 99;
            $ob = $clsOrder[$b['classification']] ?? 99;
            if ($oa !== $ob) { return $oa <=> $ob; }
            return strcmp((string) $b['opened_at_iso'], (string) $a['opened_at_iso']);
        });

        $tableVisible = array_slice($rows, 0, self::TABLE_LIMIT);

        // ── 6. KPI block ───────────────────────────────────────────────────
        $kpis = [
            'total'        => $shownTotal,
            'confirmed'    => $classCounts['CONFIRMED'],
            'probable'     => $classCounts['PROBABLE'],
            'ruled_out'    => $classCounts['DISCARDED'],
            'pending'      => $classCounts['SUSPECTED'] + $classCounts['LOST_TO_FOLLOWUP'] + $classCounts['PENDING'],
            'last_24h'     => $kpi24h,
        ];

        // ── 7. Adaptive chart ──────────────────────────────────────────────
        $chart = $this->pickChart(
            $confirmedDiseaseFreq, $classCounts, $poeBuckets, $dayBuckets,
            $poeNames, $windowLabel, $shownTotal, $kpis['confirmed'],
        );

        // ── 8. Filter dropdown meta (scope-aware) ──────────────────────────
        return [
            'window' => [
                'from'  => $from->toIso8601String(),
                'to'    => $to->toIso8601String(),
                'days'  => (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400) + 1,
                'label' => $windowLabel,
            ],
            'kpis'      => $kpis,
            'chart'     => $chart,
            'table'     => $tableVisible,
            'table_full'=> $rows,
            'total_rows'=> $shownTotal,
            'shown_rows'=> count($tableVisible),
            'meta' => [
                'poes'             => $this->scope->allowedPoes($scope),
                'classifications'  => array_keys(self::CLASS_LABELS),
                'class_labels'     => self::CLASS_LABELS,
                'ihr_tiers'        => [1, 2, 3],
                'clinical_outcomes'=> ['RECOVERED', 'UNDER_TREATMENT', 'REFERRED', 'DIED', 'UNKNOWN'],
                'diseases'         => $diseaseNames, // [code => label] for already-touched diseases
            ],
        ];
    }

    /**
     * Adaptive chart picker. Priority:
     *   A. Top confirmed diseases       (when ≥1 confirmed case has a lab disease)
     *   B. Lab pipeline by classification (always meaningful when any alerts exist)
     *   C. Confirmations by point of entry
     *   D. Per-day time series
     *
     * @return array{kind:string,title:string,subtitle:string,labels:array<int,string>,values:array<int,int>,colors:array<int,string>,unit:string}
     */
    private function pickChart(
        array $confirmedDiseaseFreq,
        array $classCounts,
        array $poeBuckets,
        array $dayBuckets,
        array $poeNames,
        string $windowLabel,
        int $totalAlerts,
        int $confirmedCount,
    ): array {
        // A — top confirmed diseases
        if (! empty($confirmedDiseaseFreq)) {
            arsort($confirmedDiseaseFreq);
            $labels = []; $values = []; $i = 0;
            foreach ($confirmedDiseaseFreq as $label => $count) {
                if ($i >= self::CHART_TOP_N) { break; }
                $labels[] = (string) $label; $values[] = (int) $count; $i++;
            }
            return [
                'kind'     => 'confirmed_diseases',
                'title'    => sprintf('Lab-confirmed diseases · %d %s', $confirmedCount, $confirmedCount === 1 ? 'case' : 'cases'),
                'subtitle' => 'What the laboratory said about cases closed with a CONFIRMED classification.',
                'labels'   => $labels,
                'values'   => $values,
                'colors'   => $this->cyclePalette(count($labels)),
                'unit'     => 'cases',
            ];
        }

        // B — lab pipeline by classification
        $nonZero = array_filter($classCounts);
        if (count($nonZero) >= 1) {
            $labels = []; $values = []; $colors = [];
            foreach ($classCounts as $key => $count) {
                if ($count === 0) { continue; }
                $labels[] = self::CLASS_LABELS[$key] ?? $key;
                $values[] = $count;
                $colors[] = self::CLASS_COLORS[$key] ?? '#546E7A';
            }
            return [
                'kind'     => 'classification',
                'title'    => 'Lab pipeline by classification',
                'subtitle' => 'Every alert in the window, grouped by what the laboratory has told us so far.',
                'labels'   => $labels,
                'values'   => $values,
                'colors'   => $colors,
                'unit'     => 'cases',
            ];
        }

        // C — by point of entry
        if (! empty($poeBuckets)) {
            arsort($poeBuckets);
            $labels = []; $values = []; $i = 0;
            foreach ($poeBuckets as $code => $count) {
                if ($i >= self::CHART_TOP_N) { break; }
                $labels[] = $poeNames[$code] ?? $code;
                $values[] = $count;
                $i++;
            }
            return [
                'kind'     => 'poe',
                'title'    => 'Alerts by point of entry',
                'subtitle' => 'Where the alerts came from. The lab pipeline has not yet produced classifications.',
                'labels'   => $labels,
                'values'   => $values,
                'colors'   => $this->cyclePalette(count($labels)),
                'unit'     => 'alerts',
            ];
        }

        // D — per day
        if (! empty($dayBuckets)) {
            return [
                'kind'     => 'day',
                'title'    => 'Alerts per day',
                'subtitle' => 'When the alerts were opened, day by day.',
                'labels'   => array_keys($dayBuckets),
                'values'   => array_values($dayBuckets),
                'colors'   => $this->cyclePalette(count($dayBuckets)),
                'unit'     => 'alerts',
            ];
        }

        return [
            'kind'     => 'empty',
            'title'    => 'No alerts',
            'subtitle' => 'No alerts were opened in this window. Widen the date range or clear a filter.',
            'labels'   => [], 'values' => [], 'colors' => [], 'unit' => 'cases',
        ];
    }

    private function cyclePalette(int $n): array
    {
        $out = []; $p = self::MATERIAL_PALETTE; $len = count($p);
        for ($i = 0; $i < $n; $i++) { $out[] = $p[$i % $len]; }
        return $out;
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
        try {
            return Carbon::parse($iso)->setTimezone(config('app.timezone', 'Africa/Kampala'))->format('M j, H:i');
        } catch (\Throwable $e) {
            return $iso;
        }
    }
}
