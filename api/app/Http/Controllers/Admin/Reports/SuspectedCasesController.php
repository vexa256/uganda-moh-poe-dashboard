<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Services\Reports\ExportWriter;
use App\Services\Reports\Insights\SuspectedCasesInsightEngine;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * R2 · rpt-suspected — Suspected Cases Surveillance
 *
 * Premium chassis (rebuild 2026-04-26): the payload keeps every key the
 * SuspectedCasesInsightEngine consumes (total_suspected, confirmed, pending,
 * unique_conditions, top_conditions[*].confirmed/disease_code) and adds the
 * surfaces the new view renders — risk × disposition, syndromes, gender ×
 * risk, hourly tempo, case register, symptom & action distributions, and
 * per-POE / per-province rollups.
 */
final class SuspectedCasesController extends BaseReportController
{
    protected string $reportKey   = 'rpt-suspected';
    protected string $reportTitle = 'Suspected Cases Surveillance';

    /** Stable display order across the view's matrices and pyramids. */
    private const RISK_LEVELS = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'];

    /** Stable display order for the disposition matrix columns. */
    private const DISPOSITIONS = [
        'RELEASED', 'REFERRED', 'ISOLATED', 'QUARANTINED',
        'TRANSFERRED', 'DELAYED', 'DENIED_BOARDING', 'OTHER',
    ];

    /** Genders rendered explicitly; UNKNOWN/OTHER are folded into "Other". */
    private const SEX_PRIMARY = ['MALE', 'FEMALE'];

    public function __construct(
        ReportScope $scope,
        ReportAccess $access,
        ExportWriter $writer,
        protected SuspectedCasesInsightEngine $engine,
    ) {
        parent::__construct($scope, $access, $writer);
    }

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);

        return view('admin.reports.rpt-suspected.index', [
            'scope'       => $scope,
            'reportKey'   => $this->reportKey,
            'reportTitle' => $this->reportTitle,
            'dataNotes'   => $this->dataNotes(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->readFilters($request);

        $payload = $this->memoise(
            (int) ($scope['user_id'] ?? 0),
            $filters + ['__r' => 'r2'],
            fn () => $this->buildPayload($scope, $filters),
        );

        $payload['insights']   = $this->engine->evaluate($payload);
        $payload['filters']    = $filters;
        $payload['scope']      = [
            'label' => $scope['label']       ?? '—',
            'level' => $scope['scope_level'] ?? 'SELF',
        ];
        $payload['data_notes'] = $this->dataNotes();

        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->readFilters($request);
        $payload = $this->buildPayload($scope, $filters);

        $headers = ['Disease code', 'Suspected', 'Confirmed', 'Pending', 'POEs', 'Latest detected'];
        $rows    = [];
        foreach ($payload['top_conditions'] as $r) {
            $rows[] = [
                $r['disease_code'], $r['suspected'], $r['confirmed'],
                $r['pending'], $r['poes'], $r['latest'],
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
     * Build the analytical payload. Returns a deterministic shape — the view
     * binds against every key listed in the bottom-of-method `return [...]`.
     */
    public function buildPayload(array $scope, array $filters): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($filters);

        // ── Pull the in-scope cohort once (single DB hit, then derive) ──────
        $secQ = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($secQ, $scope);

        if (! empty($filters['poe'])) {
            $poes = is_array($filters['poe']) ? $filters['poe'] : explode(',', (string) $filters['poe']);
            $secQ->whereIn('poe_code', array_filter($poes));
        }
        if (! empty($filters['sex']) || ! empty($filters['gender'])) {
            $secQ->where('traveler_gender', $filters['sex'] ?? $filters['gender']);
        }
        if (! empty($filters['eoc'])) {
            $secQ->where('province_code', $filters['eoc']);
        }
        if (! empty($filters['classification'])) {
            $secQ->where('syndrome_classification', $filters['classification']);
        }
        if (! empty($filters['outcome'])) {
            $secQ->where('final_disposition', $filters['outcome']);
        }

        $sec = (clone $secQ)->select([
            'id', 'poe_code', 'province_code', 'district_code',
            'case_status', 'opened_at', 'dispositioned_at', 'closed_at',
            'risk_level', 'final_disposition', 'syndrome_classification',
            'traveler_gender', 'sync_status', 'traveler_anonymous_code',
            'traveler_initials', 'traveler_age_years',
        ])->orderBy('opened_at', 'desc')->get();

        $secIds = $sec->pluck('id')->all();

        // ── Related rows (one query each, indexed lookups in PHP) ──────────
        $diseases = $secIds ? DB::table('secondary_suspected_diseases')
            ->whereIn('secondary_screening_id', $secIds)
            ->select('secondary_screening_id', 'disease_code', 'rank_order', 'confidence')
            ->get() : collect();

        $alertRows = $secIds ? DB::table('alerts')
            ->whereIn('secondary_screening_id', $secIds)
            ->whereNull('deleted_at')
            ->select('secondary_screening_id', 'status', 'ihr_tier')
            ->get() : collect();

        $symptomRows = $secIds ? DB::table('secondary_symptoms')
            ->whereIn('secondary_screening_id', $secIds)
            ->where('is_present', 1)
            ->select('secondary_screening_id', 'symptom_code')
            ->get() : collect();

        $actionRows = $secIds ? DB::table('secondary_actions')
            ->whereIn('secondary_screening_id', $secIds)
            ->where('is_done', 1)
            ->select('secondary_screening_id', 'action_code')
            ->get() : collect();

        // ── PoE name lookup (display label for tables / charts) ────────────
        $poeNames = [];
        $poeCodes = $sec->pluck('poe_code')->filter()->unique()->values()->all();
        if ($poeCodes) {
            $poeNames = DB::table('ref_poes')
                ->whereIn('poe_code', $poeCodes)
                ->pluck('poe_name', 'poe_code')->all();
        }

        // ── Confirmed / suspected sets (insight engine contract) ───────────
        $notifiableClosedSet = [];
        foreach ($alertRows as $a) {
            if ($a->ihr_tier !== null && $a->status === 'CLOSED') {
                $notifiableClosedSet[(int) $a->secondary_screening_id] = true;
            }
        }
        $suspectedSecIds = $diseases->pluck('secondary_screening_id')->unique()->values()->all();
        $suspectedSet    = array_flip(array_map('intval', $suspectedSecIds));

        $totalCohort   = $sec->count();
        $totalSuspected = count($suspectedSet);
        $pendingCount   = 0;
        foreach ($sec as $s) {
            if (isset($suspectedSet[(int) $s->id])
                && in_array($s->case_status, ['OPEN', 'IN_PROGRESS'], true)) {
                $pendingCount++;
            }
        }
        $confirmedCount = 0;
        foreach ($suspectedSecIds as $sid) {
            if (isset($notifiableClosedSet[(int) $sid])) { $confirmedCount++; }
        }
        $uniqueConditions = $diseases->pluck('disease_code')->unique()->count();

        // ── Risk × Disposition matrix + risk / disposition / syndrome ──────
        $riskCounts        = array_fill_keys(self::RISK_LEVELS, 0);
        $dispCounts        = array_fill_keys(self::DISPOSITIONS, 0);
        $matrix            = [];
        foreach (self::RISK_LEVELS as $r) {
            $matrix[$r] = array_fill_keys(self::DISPOSITIONS, 0);
        }
        $sexRisk = [];
        foreach (self::SEX_PRIMARY as $g) {
            $sexRisk[$g] = array_fill_keys(self::RISK_LEVELS, 0);
        }
        $sexRisk['OTHER'] = array_fill_keys(self::RISK_LEVELS, 0);

        $syndromeCounts = [];
        $hourCounts     = array_fill(0, 24, 0);
        $dispoSeconds   = [];
        $latestOpenedAt = null;

        foreach ($sec as $s) {
            $r = in_array($s->risk_level, self::RISK_LEVELS, true) ? $s->risk_level : null;
            $d = in_array($s->final_disposition, self::DISPOSITIONS, true) ? $s->final_disposition : null;
            if ($r) {
                $riskCounts[$r]++;
                if ($d) { $matrix[$r][$d]++; }
            }
            if ($d) {
                $dispCounts[$d]++;
            }

            $g = in_array($s->traveler_gender, self::SEX_PRIMARY, true) ? $s->traveler_gender : 'OTHER';
            if ($r) { $sexRisk[$g][$r]++; }

            if (! empty($s->syndrome_classification)) {
                $code = $s->syndrome_classification;
                $syndromeCounts[$code] = ($syndromeCounts[$code] ?? 0) + 1;
            }

            if ($s->opened_at) {
                $h = (int) Carbon::parse((string) $s->opened_at)->hour;
                $hourCounts[$h]++;
                if (! $latestOpenedAt || (string) $s->opened_at > $latestOpenedAt) {
                    $latestOpenedAt = (string) $s->opened_at;
                }
            }

            if ($s->opened_at && $s->dispositioned_at) {
                $diff = Carbon::parse((string) $s->dispositioned_at)
                    ->diffInSeconds(Carbon::parse((string) $s->opened_at));
                if ($diff >= 0) { $dispoSeconds[] = $diff; }
            }
        }

        arsort($syndromeCounts);

        $riskDistribution = [];
        foreach (self::RISK_LEVELS as $r) {
            $riskDistribution[] = [
                'level' => $r,
                'count' => $riskCounts[$r],
                'pct'   => $totalCohort > 0 ? round($riskCounts[$r] / $totalCohort * 100, 1) : 0.0,
            ];
        }

        $dispositionDistribution = [];
        foreach (self::DISPOSITIONS as $d) {
            if ($dispCounts[$d] === 0) { continue; }
            $dispositionDistribution[] = [
                'disposition' => $d,
                'count'       => $dispCounts[$d],
                'pct'         => $totalCohort > 0 ? round($dispCounts[$d] / $totalCohort * 100, 1) : 0.0,
            ];
        }

        $syndromeDistribution = [];
        foreach ($syndromeCounts as $code => $count) {
            $syndromeDistribution[] = [
                'code'  => $code,
                'count' => $count,
                'pct'   => $totalCohort > 0 ? round($count / $totalCohort * 100, 1) : 0.0,
            ];
        }

        // Risk pathway: how each risk-tier was distributed across dispositions
        $riskPathway = [];
        foreach (self::RISK_LEVELS as $r) {
            $row = [
                'risk'   => $r,
                'total'  => $riskCounts[$r],
                'splits' => [],
            ];
            if ($riskCounts[$r] > 0) {
                foreach (self::DISPOSITIONS as $d) {
                    $c = $matrix[$r][$d];
                    if ($c > 0) {
                        $row['splits'][] = [
                            'disposition' => $d,
                            'count'       => $c,
                            'pct'         => round($c / $riskCounts[$r] * 100, 1),
                        ];
                    }
                }
            }
            $riskPathway[] = $row;
        }

        // Hour-of-day rollup
        $hourSeries = [];
        for ($h = 0; $h < 24; $h++) {
            $hourSeries[] = ['hour' => $h, 'count' => $hourCounts[$h]];
        }

        // ── Symptoms & actions prevalence ─────────────────────────────────
        $symptomCounts = [];
        $caseSymptoms  = [];
        foreach ($symptomRows as $row) {
            $symptomCounts[$row->symptom_code] = ($symptomCounts[$row->symptom_code] ?? 0) + 1;
            $caseSymptoms[(int) $row->secondary_screening_id][] = $row->symptom_code;
        }
        arsort($symptomCounts);
        $symptomPrevalence = [];
        foreach ($symptomCounts as $code => $count) {
            $symptomPrevalence[] = [
                'code'  => $code,
                'count' => $count,
                'pct'   => $totalCohort > 0 ? round($count / $totalCohort * 100, 1) : 0.0,
            ];
        }

        $actionCounts = [];
        $caseActions  = [];
        foreach ($actionRows as $row) {
            $actionCounts[$row->action_code] = ($actionCounts[$row->action_code] ?? 0) + 1;
            $caseActions[(int) $row->secondary_screening_id][] = $row->action_code;
        }
        arsort($actionCounts);
        $actionDistribution = [];
        foreach ($actionCounts as $code => $count) {
            $actionDistribution[] = [
                'code'  => $code,
                'count' => $count,
                'pct'   => $totalCohort > 0 ? round($count / $totalCohort * 100, 1) : 0.0,
            ];
        }

        // ── Per-POE table (rolled from cohort) ─────────────────────────────
        $perPoe = [];
        foreach ($sec as $s) {
            $code = $s->poe_code ?: 'UNASSIGNED';
            if (! isset($perPoe[$code])) {
                $perPoe[$code] = [
                    'poe_code'      => $code,
                    'poe_name'      => $poeNames[$code] ?? $code,
                    'screened'      => 0,
                    'critical'      => 0, 'high' => 0, 'medium' => 0, 'low' => 0,
                    'isolated'      => 0, 'referred' => 0, 'released' => 0, 'delayed' => 0,
                    'suspected'     => 0,
                ];
            }
            $perPoe[$code]['screened']++;
            $r = strtolower((string) $s->risk_level);
            if (in_array($r, ['critical', 'high', 'medium', 'low'], true)) { $perPoe[$code][$r]++; }
            $d = strtolower((string) $s->final_disposition);
            if (in_array($d, ['isolated', 'referred', 'released', 'delayed'], true)) { $perPoe[$code][$d]++; }
            if (isset($suspectedSet[(int) $s->id])) { $perPoe[$code]['suspected']++; }
        }
        $perPoeTable = array_values($perPoe);
        usort($perPoeTable, fn ($a, $b) => $b['screened'] <=> $a['screened']);
        foreach ($perPoeTable as &$r) {
            $r['critical_pct'] = $r['screened'] > 0 ? round($r['critical'] / $r['screened'] * 100, 1) : 0.0;
            $r['escalated']    = $r['critical'] + $r['high'];
            $r['escalated_pct'] = $r['screened'] > 0 ? round($r['escalated'] / $r['screened'] * 100, 1) : 0.0;
        }
        unset($r);

        // ── Per-province rollup (EOC) ─────────────────────────────────────
        $perProv = [];
        foreach ($sec as $s) {
            $code = $s->province_code ?: 'UNASSIGNED';
            if (! isset($perProv[$code])) {
                $perProv[$code] = [
                    'province' => $code,
                    'screened' => 0, 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0,
                    'suspected' => 0,
                ];
            }
            $perProv[$code]['screened']++;
            $r = strtolower((string) $s->risk_level);
            if (in_array($r, ['critical', 'high', 'medium', 'low'], true)) { $perProv[$code][$r]++; }
            if (isset($suspectedSet[(int) $s->id])) { $perProv[$code]['suspected']++; }
        }
        $perProvinceTable = array_values($perProv);
        usort($perProvinceTable, fn ($a, $b) => $b['screened'] <=> $a['screened']);

        // ── Case register (line-list) ─────────────────────────────────────
        $caseRegister = [];
        foreach ($sec as $s) {
            $sid     = (int) $s->id;
            $code    = $s->traveler_anonymous_code
                ?: ($s->traveler_initials ? 'ID-' . str_pad((string) $sid, 4, '0', STR_PAD_LEFT) : 'SC-' . str_pad((string) $sid, 4, '0', STR_PAD_LEFT));
            $seconds = null;
            if ($s->opened_at && $s->dispositioned_at) {
                $seconds = Carbon::parse((string) $s->dispositioned_at)
                    ->diffInSeconds(Carbon::parse((string) $s->opened_at));
            }
            $caseRegister[] = [
                'id'             => $sid,
                'code'           => $code,
                'sex'            => $s->traveler_gender,
                'age'            => $s->traveler_age_years,
                'syndrome'       => $s->syndrome_classification,
                'risk'           => $s->risk_level,
                'disposition'    => $s->final_disposition,
                'case_status'    => $s->case_status,
                'sync_status'    => $s->sync_status,
                'poe_code'       => $s->poe_code,
                'poe_name'       => $poeNames[$s->poe_code] ?? $s->poe_code,
                'province'       => $s->province_code,
                'opened_at'      => $s->opened_at ? (string) $s->opened_at : null,
                'time_seconds'   => $seconds,
                'symptoms'       => $caseSymptoms[$sid] ?? [],
                'actions'        => $caseActions[$sid]  ?? [],
                'is_suspected'   => isset($suspectedSet[$sid]),
                'is_confirmed'   => isset($notifiableClosedSet[$sid]),
            ];
        }

        // ── Top conditions / EOC / POE / Trend / Outcomes (legacy keys) ──
        $topPerScreening = [];
        foreach ($diseases as $d) {
            $sid = (int) $d->secondary_screening_id;
            if (! isset($topPerScreening[$sid]) || $topPerScreening[$sid]->rank_order > $d->rank_order) {
                $topPerScreening[$sid] = $d;
            }
        }
        $conditions = [];
        foreach ($topPerScreening as $sid => $d) {
            $code = $d->disease_code;
            if (! isset($conditions[$code])) {
                $conditions[$code] = [
                    'disease_code' => $code, 'suspected' => 0,
                    'confirmed' => 0, 'pending' => 0, 'poes' => [], 'latest' => null,
                ];
            }
            $conditions[$code]['suspected']++;
            if (isset($notifiableClosedSet[$sid])) { $conditions[$code]['confirmed']++; }
            $row = $sec->firstWhere('id', $sid);
            if ($row) {
                if (in_array($row->case_status, ['OPEN', 'IN_PROGRESS'], true)) { $conditions[$code]['pending']++; }
                if ($row->poe_code) { $conditions[$code]['poes'][$row->poe_code] = true; }
                $openedAt = $row->opened_at ? (string) $row->opened_at : null;
                if ($openedAt && (! $conditions[$code]['latest'] || $openedAt > $conditions[$code]['latest'])) {
                    $conditions[$code]['latest'] = $openedAt;
                }
            }
        }
        foreach ($conditions as &$c) { $c['poes'] = count($c['poes']); }
        unset($c);
        $conditions = array_values($conditions);
        usort($conditions, fn ($a, $b) => $b['suspected'] <=> $a['suspected']);
        $top10 = array_slice($conditions, 0, 10);

        $eoc = [];
        foreach ($sec as $s) {
            if (! isset($suspectedSet[(int) $s->id])) { continue; }
            $key = $s->province_code ?: 'UNASSIGNED';
            $eoc[$key] = ($eoc[$key] ?? 0) + 1;
        }
        arsort($eoc);

        $poePoints = [];
        foreach ($sec as $s) {
            if (! isset($suspectedSet[(int) $s->id])) { continue; }
            $key = $s->poe_code ?: 'UNASSIGNED';
            $poePoints[$key] = ($poePoints[$key] ?? 0) + 1;
        }
        arsort($poePoints);

        // Weekly trend keyed by ISO week label, plus a parallel labels array
        // so the view can render dates without re-parsing on the client.
        $weeks = [];
        $weekLabels = [];
        $cur = $from->copy()->startOfWeek();
        while ($cur->lte($to)) {
            $weeks[] = $cur->format('Y-\WW');
            $weekLabels[] = $cur->copy()->endOfWeek()->toDateString();
            $cur->addWeek();
        }
        $topKeys = array_slice(array_column($top10, 'disease_code'), 0, 5);
        $trendSeries = [];
        foreach ($topKeys as $k) { $trendSeries[$k] = array_fill_keys($weeks, 0); }
        foreach ($topPerScreening as $sid => $d) {
            if (! in_array($d->disease_code, $topKeys, true)) { continue; }
            $row = $sec->firstWhere('id', $sid);
            if (! $row || ! $row->opened_at) { continue; }
            $wk = Carbon::parse((string) $row->opened_at)->startOfWeek()->format('Y-\WW');
            if (isset($trendSeries[$d->disease_code][$wk])) {
                $trendSeries[$d->disease_code][$wk]++;
            }
        }

        $outcomes = [];
        foreach ($top10 as $c) {
            $outcomes[] = [
                'disease_code' => $c['disease_code'],
                'suspected'    => $c['suspected'],
                'confirmed'    => $c['confirmed'],
                'pending'      => $c['pending'],
            ];
        }

        // ── Aggregated KPIs ───────────────────────────────────────────────
        $avgDispositionSeconds = $dispoSeconds
            ? (int) round(array_sum($dispoSeconds) / max(1, count($dispoSeconds)))
            : null;
        $medianDispositionSeconds = null;
        if ($dispoSeconds) {
            $sorted = $dispoSeconds;
            sort($sorted);
            $n = count($sorted);
            $medianDispositionSeconds = $n % 2 === 1
                ? (int) $sorted[(int) ($n / 2)]
                : (int) round(($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2);
        }

        $kpis = [
            // Insight-engine contract — DO NOT REMOVE
            'total_suspected'   => $totalSuspected,
            'unique_conditions' => $uniqueConditions,
            'confirmed'         => $confirmedCount,
            'pending'           => $pendingCount,

            // Premium-view extensions
            'total_cases'       => $totalCohort,
            'critical'          => $riskCounts['CRITICAL'],
            'high'              => $riskCounts['HIGH'],
            'medium'            => $riskCounts['MEDIUM'],
            'low'               => $riskCounts['LOW'],
            'isolated'          => $dispCounts['ISOLATED'],
            'referred'          => $dispCounts['REFERRED'],
            'released'          => $dispCounts['RELEASED'],
            'delayed'           => $dispCounts['DELAYED'],
            'transferred'       => $dispCounts['TRANSFERRED'],
            'quarantined'       => $dispCounts['QUARANTINED'],
            'avg_disposition_seconds'    => $avgDispositionSeconds,
            'median_disposition_seconds' => $medianDispositionSeconds,
            'critical_pct'      => $totalCohort > 0 ? round($riskCounts['CRITICAL'] / $totalCohort * 100, 1) : 0.0,
            'escalation_pct'    => $totalCohort > 0
                ? round(($riskCounts['CRITICAL'] + $riskCounts['HIGH']) / $totalCohort * 100, 1)
                : 0.0,
            'reporting_poes'    => count($perPoe),
        ];

        return [
            'window' => [
                'from'      => $from->toDateString(),
                'to'        => $to->toDateString(),
                'generated' => now()->toIso8601String(),
                'latest'    => $latestOpenedAt,
            ],
            'kpis'                       => $kpis,

            // Premium analytics
            'risk_distribution'          => $riskDistribution,
            'disposition_distribution'   => $dispositionDistribution,
            'syndrome_distribution'      => $syndromeDistribution,
            'risk_x_disposition'         => $matrix,
            'risk_x_disposition_keys'    => [
                'risks'        => self::RISK_LEVELS,
                'dispositions' => self::DISPOSITIONS,
            ],
            'risk_pathway'               => $riskPathway,
            'sex_risk'                   => $sexRisk,
            'hour_of_day'                => $hourSeries,
            'symptom_prevalence'         => $symptomPrevalence,
            'action_distribution'        => $actionDistribution,
            'per_poe_table'              => $perPoeTable,
            'per_province_table'         => $perProvinceTable,
            'case_register'              => $caseRegister,

            // Legacy keys consumed by the insight engine + export writer
            'top_conditions'             => $top10,
            'eoc'                        => $eoc,
            'poe_points'                 => $poePoints,
            'trend'                      => [
                'weeks'       => $weeks,
                'week_labels' => $weekLabels,
                'series'      => $trendSeries,
            ],
            'outcomes'                   => $outcomes,
        ];
    }
}
