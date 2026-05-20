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
 * Quick Report · Suspected Cases.
 *
 * Surface URL:  /admin/quick-reports/suspected-cases
 * Data URL:     /admin/quick-reports/suspected-cases/data
 * CSV URL:      /admin/quick-reports/suspected-cases/export
 *
 * Question this report answers: "Right now, who is under clinical
 * suspicion — what do we suspect they have, how risky are they, and
 * is the case still open?"
 *
 * Suspect definition (epidemiologically defensible):
 *   Every secondary screening represents a clinical suspect — the act of
 *   escalating to secondary screening means a screener already suspected
 *   something. Diseases attached via secondary_suspected_diseases enrich
 *   the case with a diagnostic hypothesis but are not required.
 *
 * Paranoia rules applied to every count in the payload:
 *   1. soft-deletes excluded (deleted_at IS NULL on every operational table)
 *   2. duplicate secondary rows collapsed by client_uuid (mobile sync bug —
 *      see memory project-dup-secondary-screenings-bug). The de-dupe picks
 *      the highest id per uuid which always matches the latest server-side
 *      record (id is auto-increment and never reused).
 *   3. RBAC scope applied to every cohort query, no filter-dropdown exposes
 *      a value outside the user's allowed set.
 *   4. cross-collation joins avoided: every ref_* lookup is a separate
 *      whereIn() + pluck() rather than a JOIN, because the operational
 *      tables are utf8mb4_0900_ai_ci and the reference tables are
 *      utf8mb4_unicode_ci.
 *   5. table page limited to 20 rows; the total cohort size is reported
 *      separately so the footer can honestly say "Showing 20 of N".
 *   6. all numbers are derived from one cohort fetch — chart, KPIs, and
 *      table cannot drift away from each other under any filter combo.
 */
final class SuspectedCasesController extends BaseQuickReportController
{
    protected string $reportKey   = 'qr-suspected';
    protected string $reportTitle = 'Suspected Cases';

    /** Hard cap on rows rendered in the table; the rest live in the CSV. */
    private const TABLE_LIMIT = 20;

    /** Hard cap on chart categories so labels never collide. */
    private const CHART_TOP_N = 12;

    /**
     * 2026-05-20: placeholder code emitted by mobile when the rule engine +
     * officer override produced fewer than three differential hypotheses
     * (see SecondaryScreening.vue dispositionCase pad-to-3 block). Treated
     * as an explicit "no specific suspicion captured" signal — excluded
     * from the chart, the per-case disease list, and the "with diagnosis"
     * KPI so the chart never shows a synthetic top-disease and the KPI
     * stays clinically honest.
     */
    private const PLACEHOLDER_DISEASE_CODE = 'no_specific_suspicion';
    private const PLACEHOLDER_DISEASE_LABEL = 'No specific suspicion';

    /**
     * Material Design vivid palette (saturation ≥70%). Used for categorical
     * charts (diseases / POEs / days). Cycles in order. Yellow and lime are
     * deliberately omitted — they fail the contrast bar on a white card.
     */
    private const MATERIAL_PALETTE = [
        '#E53935', // red 600
        '#1E88E5', // blue 600
        '#43A047', // green 600
        '#FB8C00', // orange 600
        '#8E24AA', // purple 600
        '#00ACC1', // cyan 600
        '#F4511E', // deep-orange 600
        '#3949AB', // indigo 600
        '#7CB342', // light-green 600
        '#D81B60', // pink 600
        '#FFB300', // amber 600
        '#00897B', // teal 600
        '#5E35B1', // deep-purple 600
        '#6D4C41', // brown 600
    ];

    /** Semantic palette for risk-level charts. */
    private const RISK_COLORS = [
        'LOW'      => '#43A047', // green 600
        'MEDIUM'   => '#FB8C00', // orange 600
        'HIGH'     => '#E64A19', // deep-orange 700
        'CRITICAL' => '#C62828', // red 800
        'UNKNOWN'  => '#546E7A', // blue-grey 600
    ];

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);

        return view('admin.quick.suspected.index', [
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
        $payload['scope']   = [
            'label' => $scope['label']       ?? '—',
            'level' => $scope['scope_level'] ?? 'SELF',
        ];

        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->applyDefaultWindow($this->readFilters($request));
        $payload = $this->buildPayload($scope, $filters);

        $headers = [
            'Opened (Africa/Kampala)',
            'Traveller name',
            'Age',
            'Sex',
            'Nationality',
            'Suspected diseases',
            'Risk level',
            'Case status',
            'Point of entry',
            'Syndrome',
            'Disposition',
            'Alert reference',
            'Case file URL',
        ];

        // Export pulls the FULL cohort, not just the 20 visible. Stream-friendly.
        $rows = [];
        foreach ($payload['table_full'] as $r) {
            $rows[] = [
                $r['opened_at_label'],
                $r['traveller_name'],
                $r['age']         ?? '',
                $r['sex']         ?? '',
                $r['nationality'] ?? '',
                implode(' · ', $r['diseases'] ?? []) ?: '—',
                $r['risk']        ?? '—',
                $r['status']      ?? '—',
                $r['poe_name']    ?? '—',
                $r['classification'] ?? '—',
                $r['disposition'] ?? '—',
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

    /**
     * Assemble the deterministic payload. Single cohort fetch → many derived
     * facets. Everything the view consumes is built here so the network has
     * exactly one round-trip per render.
     *
     * @return array{
     *   window:array{from:string,to:string,label:string,days:int},
     *   kpis:array{total:int,with_disease:int,high_risk:int,open:int,last_24h:int},
     *   chart:array{labels:array<int,string>,values:array<int,int>,unit:string,title:string,other:int},
     *   table:array<int,array<string,mixed>>,
     *   table_full:array<int,array<string,mixed>>,
     *   total_rows:int,
     *   shown_rows:int,
     *   meta:array{poes:array<string,string>,risks:array<int,string>,statuses:array<int,string>,dispositions:array<int,string>},
     * }
     */
    public function buildPayload(array $scope, array $filters): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($filters);
        $days = (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400) + 1;

        // ── 1. Pull in-scope cohort (single hit, then derive) ──────────────
        $secQ = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($secQ, $scope);

        if (! empty($filters['poe'])) {
            $secQ->where('poe_code', (string) $filters['poe']);
        }
        if (! empty($filters['risk'])) {
            $secQ->where('risk_level', (string) $filters['risk']);
        }
        if (! empty($filters['status'])) {
            $secQ->where('case_status', (string) $filters['status']);
        }
        if (! empty($filters['sex']) || ! empty($filters['gender'])) {
            $secQ->where('traveler_gender', (string) ($filters['sex'] ?? $filters['gender']));
        }
        if (! empty($filters['classification'])) {
            $secQ->where('syndrome_classification', (string) $filters['classification']);
        }
        if (! empty($filters['disposition'])) {
            $secQ->where('final_disposition', (string) $filters['disposition']);
        }
        if (! empty($filters['q'])) {
            $needle = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['q']) . '%';
            $secQ->where(function ($w) use ($needle) {
                $w->where('traveler_full_name', 'like', $needle)
                  ->orWhere('traveler_anonymous_code', 'like', $needle)
                  ->orWhere('travel_document_number', 'like', $needle);
            });
        }

        $sec = (clone $secQ)
            ->select([
                'id', 'client_uuid', 'opened_at', 'case_status', 'risk_level',
                'final_disposition', 'syndrome_classification',
                'traveler_full_name', 'traveler_initials', 'traveler_anonymous_code',
                'traveler_age_years', 'traveler_gender', 'traveler_nationality_country_code',
                'poe_code', 'province_code', 'district_code',
            ])
            ->orderBy('opened_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        // ── 2. De-duplicate by client_uuid (mobile sync bug) ───────────────
        //    Keep the highest id per uuid; rows with NULL uuid are passed through.
        $byUuid = [];
        $dedup  = [];
        foreach ($sec as $row) {
            $uuid = $row->client_uuid;
            if (! $uuid) { $dedup[] = $row; continue; }
            if (! isset($byUuid[$uuid]) || (int) $row->id > (int) $byUuid[$uuid]->id) {
                $byUuid[$uuid] = $row;
            }
        }
        foreach ($byUuid as $r) { $dedup[] = $r; }
        usort($dedup, function ($a, $b) {
            return strcmp((string) $b->opened_at, (string) $a->opened_at) ?: ((int) $b->id <=> (int) $a->id);
        });
        $sec = collect($dedup);
        $totalCohort = $sec->count();

        $secIds = $sec->pluck('id')->map(fn ($v) => (int) $v)->all();

        // ── 3. Diseases per case (separate query — collation safe) ─────────
        $diseaseRows = $secIds ? DB::table('secondary_suspected_diseases')
            ->whereIn('secondary_screening_id', $secIds)
            ->orderBy('secondary_screening_id')
            ->orderBy('rank_order')
            ->get(['secondary_screening_id', 'disease_code', 'rank_order'])
            : collect();

        $diseaseCodes = $diseaseRows->pluck('disease_code')->filter()->unique()->values()->all();
        $diseaseNames = [];
        if ($diseaseCodes) {
            $diseaseNames = DB::table('ref_diseases')
                ->whereIn('disease_code', $diseaseCodes)
                ->pluck('display_name', 'disease_code')
                ->all();
        }

        $diseasesByCase = [];          // [secondary_id => ["Cholera", "Measles", …]]
        $diseaseFreq    = [];          // ["Cholera" => 4, "Measles" => 1, …]
        $caseHasDisease = [];          // [secondary_id => true] — REAL hypotheses only
        $caseHasPlaceholderOnly = []; // [secondary_id => true] — only padding rows
        foreach ($diseaseRows as $d) {
            $sid   = (int) $d->secondary_screening_id;
            $code  = (string) $d->disease_code;

            // Placeholder rows are emitted by the mobile when neither the
            // engine nor the officer override produced enough hypotheses to
            // fill the always-three slots. They are recorded for audit but
            // must not appear in the chart, the per-case disease chip list,
            // or the "with diagnosis" KPI — otherwise a NON_CASE that was
            // dispositioned would look like it had three suspected diseases.
            if ($code === self::PLACEHOLDER_DISEASE_CODE) {
                if (! isset($caseHasDisease[$sid])) {
                    $caseHasPlaceholderOnly[$sid] = true;
                }
                continue;
            }

            $label = $diseaseNames[$code] ?? $code;
            $diseasesByCase[$sid][] = $label;
            $diseaseFreq[$label] = ($diseaseFreq[$label] ?? 0) + 1;
            $caseHasDisease[$sid] = true;
            unset($caseHasPlaceholderOnly[$sid]);
        }

        // ── 4. Alert linkage (case file deep link) ─────────────────────────
        $alertRows = $secIds ? DB::table('alerts')
            ->whereIn('secondary_screening_id', $secIds)
            ->whereNull('deleted_at')
            ->get(['id', 'alert_code', 'secondary_screening_id'])
            : collect();
        $alertBySec = [];
        foreach ($alertRows as $a) {
            $sid = (int) $a->secondary_screening_id;
            // Keep the first alert (lowest id) deterministically.
            if (! isset($alertBySec[$sid]) || (int) $a->id < (int) $alertBySec[$sid]['id']) {
                $alertBySec[$sid] = ['id' => (int) $a->id, 'code' => (string) $a->alert_code];
            }
        }

        // ── 5. POE name lookup (display label) ─────────────────────────────
        $poeCodes = $sec->pluck('poe_code')->filter()->unique()->values()->all();
        $poeNames = $poeCodes
            ? DB::table('ref_poes')->whereIn('poe_code', $poeCodes)->pluck('poe_name', 'poe_code')->all()
            : [];

        // ── 6. Derive KPIs (every count comes from the same de-duped set) ──
        $now24h     = Carbon::now()->subDay();
        $kpiTotal   = $totalCohort;
        $kpiWithDx  = count($caseHasDisease);
        $kpiHigh    = 0;
        $kpiOpen    = 0;
        $kpi24h     = 0;
        foreach ($sec as $r) {
            $risk = strtoupper((string) ($r->risk_level ?? ''));
            if ($risk === 'HIGH' || $risk === 'CRITICAL') {
                $kpiHigh++;
            }
            $status = strtoupper((string) ($r->case_status ?? ''));
            if ($status === 'OPEN' || $status === 'IN_PROGRESS') {
                $kpiOpen++;
            }
            try {
                if ($r->opened_at && Carbon::parse((string) $r->opened_at)->greaterThanOrEqualTo($now24h)) {
                    $kpi24h++;
                }
            } catch (\Throwable $e) {
                // Defensive — skip rows with malformed timestamps.
            }
        }

        // ── 7. Adaptive chart pick ─────────────────────────────────────────
        // We always want a chart with real signal. Priority:
        //   A. Top suspected diseases — when ≥1 case has a diagnosis attached.
        //   B. Cases by risk level    — when risk is populated on ≥1 case.
        //   C. Cases by point of entry — always populated when there are cases.
        //   D. Cases per day          — last-resort time-series.
        // Each option emits labels + values + per-bar colours so the front
        // end can render without re-deciding semantics.
        $chart = $this->pickChart($sec, $diseaseFreq, $poeNames, $from, $to, $totalCohort);

        // ── 8. Table rows (full + visible-20) ──────────────────────────────
        $tableFull = [];
        foreach ($sec as $r) {
            $sid = (int) $r->id;
            $alert = $alertBySec[$sid] ?? null;
            $tableFull[] = [
                'id'              => $sid,
                'opened_at_iso'   => (string) $r->opened_at,
                'opened_at_label' => $this->humanDate((string) $r->opened_at),
                'traveller_name'  => $this->displayName($r),
                'age'             => $r->traveler_age_years !== null ? (int) $r->traveler_age_years : null,
                'sex'             => $r->traveler_gender,
                'nationality'     => $r->traveler_nationality_country_code,
                'diseases'        => $diseasesByCase[$sid] ?? [],
                'risk'            => $r->risk_level ?: null,
                'status'          => $r->case_status ?: null,
                'classification'  => $r->syndrome_classification ?: null,
                'disposition'     => $r->final_disposition ?: null,
                'poe_name'        => $poeNames[$r->poe_code] ?? $r->poe_code,
                'alert_code'      => $alert['code'] ?? null,
                'case_file_url'   => $alert
                    ? url("/admin/alerts/{$alert['id']}/case-file")
                    : url("/admin/reports/rpt-case-files/{$sid}"),
            ];
        }
        $tableVisible = array_slice($tableFull, 0, self::TABLE_LIMIT);

        // ── 9. Filter dropdown meta (scope-aware) ──────────────────────────
        $poeOptions = $this->scope->allowedPoes($scope);

        return [
            'window' => [
                'from'  => $from->toIso8601String(),
                'to'    => $to->toIso8601String(),
                'days'  => $days,
                'label' => $this->windowLabel($from, $to),
            ],
            'kpis' => [
                'total'        => $kpiTotal,
                'with_disease' => $kpiWithDx,
                'high_risk'    => $kpiHigh,
                'open'         => $kpiOpen,
                'last_24h'     => $kpi24h,
            ],
            'chart' => $chart,
            'table'       => $tableVisible,
            'table_full'  => $tableFull,
            'total_rows'  => $totalCohort,
            'shown_rows'  => count($tableVisible),
            'meta' => [
                'poes'         => $poeOptions,
                'risks'        => ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'],
                'statuses'     => ['OPEN', 'IN_PROGRESS', 'DISPOSITIONED', 'CLOSED', 'REOPENED'],
                'dispositions' => ['RELEASED', 'REFERRED', 'ISOLATED', 'QUARANTINED', 'TRANSFERRED', 'DELAYED', 'DENIED_BOARDING', 'OTHER'],
                'sexes'        => ['MALE', 'FEMALE', 'OTHER', 'UNKNOWN'],
                'days_presets' => [7, 14, 30, 60, 90],
            ],
        ];
    }

    /**
     * Best-available display name. Falls back to initials → anonymous code →
     * "Unknown traveller". Names already stored in plain text in the mobile
     * payload — no PII masking applied here because every viewer of this
     * report has been gated through ReportAccess.
     */
    private function displayName(object $r): string
    {
        $full = trim((string) ($r->traveler_full_name ?? ''));
        if ($full !== '') { return $full; }
        $init = trim((string) ($r->traveler_initials ?? ''));
        if ($init !== '') { return $init; }
        $anon = trim((string) ($r->traveler_anonymous_code ?? ''));
        if ($anon !== '') { return $anon; }
        return 'Unknown traveller';
    }

    /**
     * Adaptive chart picker. Walks four candidates in priority order and
     * returns the first one with real signal (≥1 non-zero bucket and
     * ≥2 distinct categories where applicable). Each result includes a
     * `kind` tag the front-end uses to label the chart, plus a per-bar
     * colour array so the rendering layer never picks colour itself.
     *
     * @return array{kind:string,title:string,subtitle:string,labels:array<int,string>,values:array<int,int>,colors:array<int,string>,unit:string,other:int}
     */
    private function pickChart(
        \Illuminate\Support\Collection $sec,
        array $diseaseFreq,
        array $poeNames,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        int $totalCohort,
    ): array {
        $windowLabel = $this->windowLabel($from, $to);

        // ── Candidate A: top suspected diseases ────────────────────────
        if (! empty($diseaseFreq)) {
            arsort($diseaseFreq);
            $labels = []; $values = []; $other = 0; $i = 0;
            foreach ($diseaseFreq as $label => $count) {
                if ($i < self::CHART_TOP_N) { $labels[] = (string) $label; $values[] = (int) $count; }
                else                         { $other  += (int) $count; }
                $i++;
            }
            if ($other > 0) { $labels[] = 'Other'; $values[] = $other; }
            return [
                'kind'     => 'diseases',
                'title'    => sprintf('Top suspected diseases · %d %s', $totalCohort, $totalCohort === 1 ? 'case' : 'cases'),
                'subtitle' => 'How many cases flagged each suspected disease as a possibility.',
                'labels'   => $labels,
                'values'   => $values,
                'colors'   => $this->cyclePalette(count($labels), $other > 0),
                'unit'     => 'cases',
                'other'    => $other,
            ];
        }

        // ── Candidate B: cases by risk level ───────────────────────────
        $riskBuckets = ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 0, 'CRITICAL' => 0, 'UNKNOWN' => 0];
        foreach ($sec as $r) {
            $risk = strtoupper((string) ($r->risk_level ?? ''));
            $key  = in_array($risk, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'], true) ? $risk : 'UNKNOWN';
            $riskBuckets[$key]++;
        }
        $riskNonZero = array_filter($riskBuckets);
        if (count($riskNonZero) >= 2 || ($riskNonZero && ! isset($riskNonZero['UNKNOWN']))) {
            $labels = []; $values = []; $colors = [];
            foreach ($riskBuckets as $k => $v) {
                if ($v === 0) { continue; }
                $labels[] = $k === 'UNKNOWN' ? 'Not assessed' : ucfirst(strtolower($k));
                $values[] = $v;
                $colors[] = self::RISK_COLORS[$k];
            }
            return [
                'kind'     => 'risk',
                'title'    => 'Suspected cases by risk level',
                'subtitle' => 'Risk tier assigned by the screening officer. Diseases were not yet attached to these cases.',
                'labels'   => $labels,
                'values'   => $values,
                'colors'   => $colors,
                'unit'     => 'cases',
                'other'    => 0,
            ];
        }

        // ── Candidate C: cases by point of entry ───────────────────────
        $poeBuckets = [];
        foreach ($sec as $r) {
            $code = (string) ($r->poe_code ?? '');
            if ($code === '') { continue; }
            $poeBuckets[$code] = ($poeBuckets[$code] ?? 0) + 1;
        }
        if (count($poeBuckets) >= 1) {
            arsort($poeBuckets);
            $labels = []; $values = []; $i = 0; $other = 0;
            foreach ($poeBuckets as $code => $count) {
                if ($i < self::CHART_TOP_N) {
                    $labels[] = $poeNames[$code] ?? $code;
                    $values[] = $count;
                } else {
                    $other += $count;
                }
                $i++;
            }
            if ($other > 0) { $labels[] = 'Other'; $values[] = $other; }
            return [
                'kind'     => 'poe',
                'title'    => 'Suspected cases by point of entry',
                'subtitle' => 'Where the cases were opened. Diseases and risk were not yet recorded for this window.',
                'labels'   => $labels,
                'values'   => $values,
                'colors'   => $this->cyclePalette(count($labels), $other > 0),
                'unit'     => 'cases',
                'other'    => $other,
            ];
        }

        // ── Candidate D: cases per day ─────────────────────────────────
        $dayBuckets = [];
        foreach ($sec as $r) {
            try {
                $d = Carbon::parse((string) $r->opened_at)
                    ->setTimezone(config('app.timezone', 'Africa/Kampala'))
                    ->format('M j');
                $dayBuckets[$d] = ($dayBuckets[$d] ?? 0) + 1;
            } catch (\Throwable $e) { /* skip */ }
        }
        if ($dayBuckets) {
            $labels = array_keys($dayBuckets);
            $values = array_values($dayBuckets);
            return [
                'kind'     => 'day',
                'title'    => 'Suspected cases per day',
                'subtitle' => 'When the cases were opened, day by day.',
                'labels'   => $labels,
                'values'   => $values,
                'colors'   => $this->cyclePalette(count($labels), false),
                'unit'     => 'cases',
                'other'    => 0,
            ];
        }

        // ── No data anywhere ───────────────────────────────────────────
        return [
            'kind'     => 'empty',
            'title'    => 'No suspected cases',
            'subtitle' => 'Nothing to plot in this window. Widen the date range or clear a filter.',
            'labels'   => [],
            'values'   => [],
            'colors'   => [],
            'unit'     => 'cases',
            'other'    => 0,
        ];
    }

    /**
     * Generate `$n` colours cycling through MATERIAL_PALETTE. When `$other`
     * is true, the final bar gets a neutral blue-grey so the "Other" bucket
     * reads as a residual rather than a category.
     *
     * @return array<int,string>
     */
    private function cyclePalette(int $n, bool $other): array
    {
        $out = [];
        $palette = self::MATERIAL_PALETTE;
        $paletteLen = count($palette);
        for ($i = 0; $i < $n; $i++) {
            $out[] = $palette[$i % $paletteLen];
        }
        if ($other && $n > 0) {
            $out[$n - 1] = '#90A4AE'; // blue-grey 300 — quiet residual colour
        }
        return $out;
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
