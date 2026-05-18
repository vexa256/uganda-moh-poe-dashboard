<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Services\Reports\ExportWriter;
use App\Services\Reports\InsightThresholds;
use App\Services\Reports\Insights\ScreeningVolumeInsightEngine;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * R10 · rpt-volume — Screening Volume & WHO Indicators Analysis.
 *
 * Mirrors the legacy reports.ScreeningVolumeAnalysis blade contract from the
 * vexa256/uganda-moh-poe-dashboard repo, rewired to OUR schema:
 *
 *   primary_screenings        ← legacy ScreeningData
 *   secondary_screenings      ← legacy secondary_screenings + secondary_screenings_data
 *                                  (merged here via case_status enum)
 *   ref_poes                  ← legacy points_of_entry
 *
 * Legacy variable map (preserved verbatim in the payload so the blade can
 * read the WHO labels users expect):
 *
 *   P  = totalPrimary                  · primary_screenings rows in window
 *   R  = referredForSecondary          · secondary_screenings opened in window
 *   C  = totalSecondary                · secondary_screenings DISPOSITIONED / CLOSED in window
 *   N  = notifiableConditionsCount     · risk_level IN (HIGH, CRITICAL) on C cohort
 *   RF = travellersReferred            · final_disposition IN (REFERRED, TRANSFERRED)
 *   RN = referredMeetingNotifiable     · RF ∩ N
 *   H  = travellersInHolding           · case_status IN (OPEN, IN_PROGRESS) NOW (operational)
 *   T  = P + R                         · total screened
 *
 * Default date window when no explicit filter is supplied: past 7 days.
 */
final class ScreeningVolumeController extends BaseReportController
{
    /** Legacy R10 threshold: holding queue is "flagged" once it lingers ≥ 20 min. */
    private const HOLDING_THRESHOLD_MINUTES = 20;

    /** Default filter window when nothing else is supplied (R10 protocol). */
    private const DEFAULT_DAYS = 7;

    protected string $reportKey   = 'rpt-volume';
    protected string $reportTitle = 'Screening Volume';

    public function __construct(
        ReportScope $scope,
        ReportAccess $access,
        ExportWriter $writer,
        protected ScreeningVolumeInsightEngine $engine,
    ) {
        parent::__construct($scope, $access, $writer);
    }

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);

        return view('admin.reports.rpt-volume.index', [
            'scope'       => $scope,
            'reportKey'   => $this->reportKey,
            'reportTitle' => $this->reportTitle,
            'dataNotes'   => $this->dataNotes(),
            'defaultDays' => self::DEFAULT_DAYS,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->normalizeFilters($this->readFilters($request));

        $payload = $this->memoise(
            (int) ($scope['user_id'] ?? 0),
            $filters + ['__r' => 'r10v2'],
            fn () => $this->buildPayload($scope, $filters),
        );

        $payload['insights']   = $this->engine->evaluate($payload);
        $payload['filters']    = $filters;
        $payload['scope']      = [
            'label' => $scope['label'] ?? '—',
            'level' => $scope['scope_level'] ?? 'SELF',
        ];
        $payload['data_notes'] = $this->dataNotes();

        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->normalizeFilters($this->readFilters($request));
        $format  = strtoupper((string) $request->input('format', 'CSV'));
        $payload = $this->buildPayload($scope, $filters);

        $headers = [
            'Point of Entry',
            'Code',
            'Province',
            'District',
            'Type',
            'Travellers screened',
            'Sent for full check',
            'Cleared at booth',
            'Officers found risk',
            'Sent on for care',
            'Risk found %',
            'Care referral %',
        ];

        $rows = [];
        foreach ($payload['poe_breakdown_table'] as $r) {
            $rows[] = [
                $r['poe_name'],
                $r['poe_code'],
                $r['province'],
                $r['district'],
                $r['poe_type'],
                $r['primary'],
                $r['secondary'],
                $r['primary'] - $r['secondary'],
                $r['notifiable'],
                $r['referred'],
                $r['notifiable_pct'] ?? '—',
                $r['referred_pct']   ?? '—',
            ];
        }

        return $this->writer->send(
            $this->reportKey,
            $format,
            $headers,
            $rows,
            $filters,
            (int) ($scope['user_id'] ?? 0),
            $this->reportTitle,
        );
    }

    /* =================================================================
     * FILTER NORMALISATION — default-to-7-days protocol
     * ================================================================= */
    private function normalizeFilters(array $f): array
    {
        $hasExplicit =
            ! empty($f['year'])       ||
            ! empty($f['quarter'])    ||
            ! empty($f['month'])      ||
            ! empty($f['start_date']) ||
            ! empty($f['end_date']);

        if (! $hasExplicit) {
            $f['default_days'] = self::DEFAULT_DAYS;
        }
        return $f;
    }

    /* =================================================================
     * PAYLOAD BUILDER
     * ================================================================= */
    public function buildPayload(array $scope, array $filters): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($filters);
        $now         = Carbon::now();
        $sex         = $filters['sex'] ?? $filters['gender'] ?? null;
        $sexFilter   = $sex ? (string) $sex : null;

        /* ------------------------------------------------------------
         * Primary screenings (P, gender split, time / POE / month rolls)
         * ----------------------------------------------------------- */
        $pq = DB::table('primary_screenings')
            ->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->whereBetween('captured_at', [$from, $to]);
        $this->scope->apply($pq, $scope);
        $this->applySharedFilters($pq, $filters, 'primary');

        $primaryRows = (clone $pq)
            ->selectRaw('id, poe_code, gender, symptoms_present, captured_at')
            ->get();

        $primary     = $primaryRows->count();
        $primaryWithSymptoms = $primaryRows->where('symptoms_present', 1)->count();

        /* ------------------------------------------------------------
         * Secondary screenings (R, C, N, RF, RN, holding queue)
         * ----------------------------------------------------------- */
        $sqWindow = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($sqWindow, $scope);
        $this->applySharedFilters($sqWindow, $filters, 'secondary');

        $secondaryRows = (clone $sqWindow)
            ->selectRaw('id, poe_code, traveler_gender, traveler_age_years, risk_level, final_disposition, opened_at, closed_at, case_status')
            ->get();

        $referred  = $secondaryRows->count();                      // R
        $closed    = $secondaryRows->whereIn('case_status', ['DISPOSITIONED', 'CLOSED']);
        $completed = $closed->count();                              // C

        $notifiableCol = function ($r) {
            return in_array($r->risk_level, ['HIGH', 'CRITICAL'], true);
        };
        $notifiableCases   = $closed->filter($notifiableCol)->count();    // N
        $facilityReferred  = $secondaryRows->whereIn('final_disposition', ['REFERRED', 'TRANSFERRED'])->count();  // RF
        $referredNotifiable = $secondaryRows
            ->whereIn('final_disposition', ['REFERRED', 'TRANSFERRED'])
            ->filter($notifiableCol)
            ->count();                                                     // RN

        /* ------------------------------------------------------------
         * Holding queue (operational — not date-bounded; NOW state)
         * ----------------------------------------------------------- */
        $holdingQuery = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereIn('case_status', ['OPEN', 'IN_PROGRESS']);
        $this->scope->apply($holdingQuery, $scope);
        $this->applySharedFilters($holdingQuery, $filters, 'secondary');

        $holdingRows = $holdingQuery
            ->selectRaw('id, poe_code, opened_at, created_at')
            ->get();

        $holding         = $holdingRows->count();         // H
        $holdingUnder20  = 0;
        $holdingOver20   = 0;
        foreach ($holdingRows as $r) {
            $reference = $r->opened_at ?: $r->created_at;
            if (! $reference) {
                $holdingUnder20++;
                continue;
            }
            try {
                $mins = max(0, Carbon::parse((string) $reference)->diffInMinutes($now, false));
            } catch (\Throwable $e) {
                $mins = 0;
            }
            if ($mins >= self::HOLDING_THRESHOLD_MINUTES) {
                $holdingOver20++;
            } else {
                $holdingUnder20++;
            }
        }

        /* ------------------------------------------------------------
         * Derived totals + percentages (WHO indicator deck)
         * ----------------------------------------------------------- */
        $totalScreened = $primary + $referred;                  // T = P + R

        $pctUnwell      = $this->pct($referred,           $totalScreened);   // R / T
        $pctReferred    = $this->pct($referred,           $totalScreened);   // R / T (same — semantic naming)
        $pctNotifiable  = $this->pct($notifiableCases,    $completed);       // N / C
        $pctRefNotif    = $this->pct($referredNotifiable, $facilityReferred); // RN / RF
        $pctHoldingFlag = $this->pct($holdingOver20,      $holding);

        $genderCoverage = $primary + $completed;
        $genderGap      = max(0, $totalScreened - $genderCoverage);

        /* ------------------------------------------------------------
         * Spike ratio — last 7 days primary vs trailing 30-day baseline.
         * Same definition as the legacy engine so the insight rules
         * (SPIKE_VS_30D_BASELINE) keep firing on the new payload.
         * ----------------------------------------------------------- */
        $now7  = Carbon::now()->subDays(7);
        $now30 = Carbon::now()->subDays(30);
        $r7    = $primaryRows->filter(function ($r) use ($now7) {
            try { return $r->captured_at && Carbon::parse((string) $r->captured_at)->gte($now7); }
            catch (\Throwable $e) { return false; }
        })->count();
        $r30   = $primaryRows->filter(function ($r) use ($now7, $now30) {
            try {
                $d = Carbon::parse((string) $r->captured_at);
                return $d->between($now30, $now7);
            } catch (\Throwable $e) { return false; }
        })->count();
        $baseline   = max(1.0, $r30 / 23.0);
        $spikeRatio = round(($r7 / 7.0) / $baseline, 2);

        /* ------------------------------------------------------------
         * Classification breakdown (legacy: Suspected / PUS / POI / Non)
         * Our risk_level enum mapped to plain English buckets:
         *   CRITICAL → "Officers escalated immediately"
         *   HIGH     → "High risk – sent for care"
         *   MEDIUM   → "Watch list – monitor closely"
         *   LOW      → "Low risk – cleared"
         *   UNSET    → "Risk level not recorded"
         * ----------------------------------------------------------- */
        $riskLabels = [
            'CRITICAL'     => 'Officers escalated immediately',
            'HIGH'         => 'High risk · sent for care',
            'MEDIUM'       => 'Watch list · monitor closely',
            'LOW'          => 'Low risk · cleared',
            'UNCLASSIFIED' => 'Risk level not recorded',
        ];
        $riskCounts = ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0, 'UNCLASSIFIED' => 0];
        foreach ($secondaryRows as $r) {
            $key = $r->risk_level ?: 'UNCLASSIFIED';
            $riskCounts[$key] = ($riskCounts[$key] ?? 0) + 1;
        }
        $classificationBreakdown = [];
        foreach ($riskCounts as $code => $count) {
            $classificationBreakdown[] = [
                'code'   => $code,
                'label'  => $riskLabels[$code] ?? $code,
                'count'  => $count,
                'pct'    => $referred > 0 ? round(($count / $referred) * 100, 1) : 0.0,
            ];
        }

        /* ------------------------------------------------------------
         * POE master lookup (single hit, scoped)
         * ----------------------------------------------------------- */
        $poeCodes = $primaryRows->pluck('poe_code')
            ->merge($secondaryRows->pluck('poe_code'))
            ->merge($holdingRows->pluck('poe_code'))
            ->unique()->filter()->values()->all();

        $poeMeta = [];
        if (! empty($poeCodes)) {
            DB::table('ref_poes')
                ->whereNull('deleted_at')
                ->whereIn('poe_code', $poeCodes)
                ->get(['poe_code', 'poe_name', 'admin_level_1', 'district', 'poe_type'])
                ->each(function ($r) use (&$poeMeta) {
                    $poeMeta[$r->poe_code] = [
                        'poe_name' => (string) ($r->poe_name ?: $r->poe_code),
                        'province' => (string) ($r->admin_level_1 ?: '—'),
                        'district' => (string) ($r->district ?: '—'),
                        'poe_type' => (string) ($r->poe_type ?: '—'),
                    ];
                });
        }
        $poeName = fn (string $c) => $poeMeta[$c]['poe_name'] ?? $c;

        /* ------------------------------------------------------------
         * Per-POE breakdowns (primary + secondary side-by-side)
         * ----------------------------------------------------------- */
        $primaryByPoe   = $primaryRows->groupBy('poe_code')->map->count();
        $secondaryByPoe = $secondaryRows->groupBy('poe_code')->map->count();
        $notifiableByPoe = $closed->filter($notifiableCol)
            ->groupBy('poe_code')->map->count();
        $referredByPoe = $secondaryRows
            ->whereIn('final_disposition', ['REFERRED', 'TRANSFERRED'])
            ->groupBy('poe_code')->map->count();

        $poeBreakdownTable = [];
        foreach ($poeCodes as $code) {
            $p = (int) ($primaryByPoe[$code]    ?? 0);
            $s = (int) ($secondaryByPoe[$code]  ?? 0);
            $n = (int) ($notifiableByPoe[$code] ?? 0);
            $rf = (int) ($referredByPoe[$code]  ?? 0);
            $meta = $poeMeta[$code] ?? ['poe_name' => $code, 'province' => '—', 'district' => '—', 'poe_type' => '—'];
            $poeBreakdownTable[] = [
                'poe_code'        => $code,
                'poe_name'        => $meta['poe_name'],
                'province'        => $meta['province'],
                'district'        => $meta['district'],
                'poe_type'        => $meta['poe_type'],
                'primary'         => $p,
                'secondary'       => $s,
                'notifiable'      => $n,
                'referred'        => $rf,
                'notifiable_pct'  => $this->pct($n,  $s),
                'referred_pct'    => $this->pct($rf, $s),
            ];
        }
        usort($poeBreakdownTable, fn ($a, $b) => $b['primary'] <=> $a['primary']);

        $poeBreakdownPrimary = array_map(
            fn ($r) => ['poe_code' => $r['poe_code'], 'poe_name' => $r['poe_name'], 'count' => $r['primary']],
            $poeBreakdownTable,
        );
        $poeBreakdownSecondary = array_map(
            fn ($r) => ['poe_code' => $r['poe_code'], 'poe_name' => $r['poe_name'], 'count' => $r['secondary']],
            $poeBreakdownTable,
        );
        usort($poeBreakdownSecondary, fn ($a, $b) => $b['count'] <=> $a['count']);

        /* ------------------------------------------------------------
         * Gender breakdowns
         * ----------------------------------------------------------- */
        $primaryGender = ['MALE' => 0, 'FEMALE' => 0, 'OTHER' => 0, 'UNKNOWN' => 0];
        foreach ($primaryRows as $r) {
            $k = $r->gender ?: 'UNKNOWN';
            if (isset($primaryGender[$k])) { $primaryGender[$k]++; } else { $primaryGender['UNKNOWN']++; }
        }
        $secondaryGender = ['MALE' => 0, 'FEMALE' => 0, 'OTHER' => 0, 'UNKNOWN' => 0];
        foreach ($secondaryRows as $r) {
            $k = $r->traveler_gender ?: 'UNKNOWN';
            if (isset($secondaryGender[$k])) { $secondaryGender[$k]++; } else { $secondaryGender['UNKNOWN']++; }
        }

        /* ------------------------------------------------------------
         * Monthly / Quarterly / Yearly trends
         * (primary + secondary, paired so blade can render grouped bars)
         * ----------------------------------------------------------- */
        $monthBuckets   = $this->bucketByPeriod($primaryRows, $secondaryRows, 'month');
        $quarterBuckets = $this->bucketByPeriod($primaryRows, $secondaryRows, 'quarter');
        $yearBuckets    = $this->bucketByPeriod($primaryRows, $secondaryRows, 'year');

        /* ------------------------------------------------------------
         * Filter dropdown data — scoped & always-valid
         * ----------------------------------------------------------- */
        $poesAllowed = $this->scope->allowedPoes($scope);
        $years       = $this->availableYears($scope);

        /* ------------------------------------------------------------
         * Assemble payload (legacy variable names preserved)
         * ----------------------------------------------------------- */
        return [
            'window' => [
                'from'  => $from->toDateString(),
                'to'    => $to->toDateString(),
                'label' => $this->windowLabel($filters, $from, $to),
            ],
            'kpis' => [
                'total_screened'         => $totalScreened,            // T
                'primary'                => $primary,                  // P
                'referred_for_secondary' => $referred,                 // R
                'total_secondary'        => $completed,                // C
                'notifiable_cases'       => $notifiableCases,          // N
                'facility_referrals'     => $facilityReferred,         // RF
                'referred_notifiable'    => $referredNotifiable,       // RN
                'holding'                => $holding,                  // H
                'holding_under_20'       => $holdingUnder20,
                'holding_over_20'        => $holdingOver20,
                'pct_unwell'             => $pctUnwell,
                'pct_referred_secondary' => $pctReferred,
                'pct_notifiable'         => $pctNotifiable,
                'pct_referred_notif'     => $pctRefNotif,
                'pct_holding_flagged'    => $pctHoldingFlag,
                'gender_coverage'        => $genderCoverage,
                'gender_gap'             => $genderGap,
                'primary_with_symptoms'  => $primaryWithSymptoms,
                'holding_threshold_min'  => self::HOLDING_THRESHOLD_MINUTES,
                'spike_ratio'            => $spikeRatio,

                // Aliases the legacy ScreeningVolumeInsightEngine reads — kept
                // so the rule engine fires on the same payload without forking.
                'secondary'              => $completed,
                'notifiable'             => $notifiableCases,
                'holding_flagged'        => $holdingOver20,
            ],
            'classification_breakdown' => $classificationBreakdown,
            'poe_breakdown_table'      => $poeBreakdownTable,
            'poe_breakdown_primary'    => array_slice($poeBreakdownPrimary, 0, 15),
            'poe_breakdown_secondary'  => array_slice($poeBreakdownSecondary, 0, 15),
            'gender' => [
                'primary'   => $primaryGender,
                'secondary' => $secondaryGender,
            ],
            'trend' => [
                'monthly'   => $monthBuckets,
                'quarterly' => $quarterBuckets,
                'yearly'    => $yearBuckets,
            ],
            'meta' => [
                'poes'     => $poesAllowed,
                'years'    => $years,
                'quarters' => [1, 2, 3, 4],
                'months'   => [
                    1 => 'January',  2 => 'February', 3 => 'March',     4 => 'April',
                    5 => 'May',      6 => 'June',     7 => 'July',      8 => 'August',
                    9 => 'September',10 => 'October', 11 => 'November', 12 => 'December',
                ],
                'sex_options' => [
                    'MALE'    => 'Men',
                    'FEMALE'  => 'Women',
                    'OTHER'   => 'Other',
                    'UNKNOWN' => 'Not recorded',
                ],
                'sex_filter_active' => $sexFilter !== null,
                'default_days'      => self::DEFAULT_DAYS,
            ],
        ];
    }

    /* =================================================================
     * SUPPORT
     * ================================================================= */

    /**
     * Apply the shared filter set to a query targeting either the primary or
     * secondary surveillance table. Gender column name differs by surface,
     * so the $surface argument switches between gender / traveler_gender.
     */
    private function applySharedFilters(\Illuminate\Database\Query\Builder $q, array $filters, string $surface): void
    {
        if (! empty($filters['poe'])) {
            $list = is_array($filters['poe']) ? $filters['poe'] : array_filter(explode(',', (string) $filters['poe']));
            if (! empty($list)) {
                $q->whereIn('poe_code', $list);
            }
        }
        $sex = $filters['sex'] ?? $filters['gender'] ?? null;
        if ($sex) {
            $col = $surface === 'primary' ? 'gender' : 'traveler_gender';
            $q->where($col, $sex);
        }
        if (! empty($filters['eoc'])) {
            $q->where('pheoc_code', $filters['eoc']);
        }
    }

    /**
     * Bucket rows by month / quarter / year, returning paired primary +
     * secondary counts so the blade can render grouped bars.
     */
    private function bucketByPeriod(
        \Illuminate\Support\Collection $primaryRows,
        \Illuminate\Support\Collection $secondaryRows,
        string $period,
    ): array {
        $keyFor = function ($value, string $period): ?string {
            if (! $value) { return null; }
            try {
                $dt = Carbon::parse((string) $value);
            } catch (\Throwable $e) {
                return null;
            }
            return match ($period) {
                'month'   => $dt->format('Y-m'),
                'quarter' => $dt->format('Y') . '-Q' . (int) ceil($dt->month / 3),
                'year'    => $dt->format('Y'),
                default   => null,
            };
        };

        $labelFor = function (string $key, string $period): string {
            if ($period === 'month') {
                try { return Carbon::parse($key . '-01')->format('M Y'); } catch (\Throwable $e) { return $key; }
            }
            if ($period === 'quarter') {
                [$y, $q] = explode('-Q', $key);
                return "Q{$q} {$y}";
            }
            return $key;
        };

        $primary = [];
        foreach ($primaryRows as $r) {
            $k = $keyFor($r->captured_at, $period);
            if ($k === null) { continue; }
            $primary[$k] = ($primary[$k] ?? 0) + 1;
        }

        $secondary = [];
        foreach ($secondaryRows as $r) {
            $k = $keyFor($r->opened_at, $period);
            if ($k === null) { continue; }
            $secondary[$k] = ($secondary[$k] ?? 0) + 1;
        }

        $allKeys = array_unique(array_merge(array_keys($primary), array_keys($secondary)));
        sort($allKeys);

        $rows = [];
        foreach ($allKeys as $k) {
            // PHP int-casts numeric-string array keys (eg '2026' → 2026), so
            // recover the string form before handing it to the typed closure.
            $kStr = (string) $k;
            $rows[] = [
                'period'    => $kStr,
                'label'     => $labelFor($kStr, $period),
                'primary'   => (int) ($primary[$k]   ?? 0),
                'secondary' => (int) ($secondary[$k] ?? 0),
            ];
        }
        return $rows;
    }

    /**
     * Discover distinct years that have any primary or secondary screening
     * data the user can see. Constrained to the scope so the dropdown only
     * advertises years the user could query.
     */
    private function availableYears(array $scope): array
    {
        $years = [];

        $q1 = DB::table('primary_screenings')
            ->whereNull('deleted_at')
            ->selectRaw('DISTINCT YEAR(captured_at) AS y');
        $this->scope->apply($q1, $scope);
        foreach ($q1->get() as $r) {
            if ($r->y) { $years[(int) $r->y] = true; }
        }

        $q2 = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->selectRaw('DISTINCT YEAR(opened_at) AS y');
        $this->scope->apply($q2, $scope);
        foreach ($q2->get() as $r) {
            if ($r->y) { $years[(int) $r->y] = true; }
        }

        $years[(int) Carbon::now()->year] = true;
        $list = array_keys($years);
        rsort($list);
        return array_values($list);
    }

    /**
     * Plain-English label for the resolved date window — feeds the header
     * chip the user reads to confirm what the report is showing.
     */
    private function windowLabel(array $f, Carbon $from, Carbon $to): string
    {
        $year    = (int) ($f['year']    ?? 0);
        $quarter = (int) ($f['quarter'] ?? 0);
        $month   = (int) ($f['month']   ?? 0);

        if ($year && $quarter) { return "Quarter {$quarter}, {$year}"; }
        if ($year && $month)   {
            return Carbon::create($year, $month, 1)->format('F Y');
        }
        if ($year) { return (string) $year; }

        if (isset($f['default_days'])) {
            $d = (int) $f['default_days'];
            return "Past {$d} days";
        }
        return $from->format('M j, Y') . ' – ' . $to->format('M j, Y');
    }

    /**
     * Percent with small-n suppression. Returns null when the denominator
     * is below the project-wide MIN_DENOMINATOR so the blade can render the
     * "— (n<5)" placeholder rather than misleading single-digit ratios.
     */
    private function pct(int $num, int $den): ?float
    {
        if ($den <= 0)                                  { return 0.0; }
        if ($den < InsightThresholds::MIN_DENOMINATOR)  { return null; }
        return round(($num / $den) * 100, 1);
    }
}
