<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Services\Reports\ExportWriter;
use App\Services\Reports\Insights\SymptomDistributionInsightEngine;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * R12 · rpt-symptom-distribution — Symptom Distribution
 *
 * Premium chassis (rebuild 2026-04-26): preserves every key the
 * SymptomDistributionInsightEngine consumes (kpis.cases_with_symptoms,
 * kpis.dictionary_coverage, frequency[*].rate). Adds only what the lean
 * 2-tab clinical-signal view actually renders:
 *   • per-symptom outcome matrix (which symptoms predict confirmation)
 *   • symptom-load bands (honestly relabelled — no clinical severity grade
 *     exists in the schema; this is symptoms-per-case)
 *   • top co-occurrence pairs (≥3, capped at 10)
 *   • per-symptom drilldown (outcomes, top diseases, co-occurring)
 *   • multi-symptom KPI + dictionary-coverage sparkline tile
 *
 * The legacy by_poe key stays in the payload for backward compatibility but
 * the lean view does not render it (geographic cuts belong in R7 / R13).
 */
final class SymptomDistributionController extends BaseReportController
{
    protected string $reportKey   = 'rpt-symptom-distribution';
    protected string $reportTitle = 'Symptom Distribution';

    /** Cap on co-occurrence pairs surfaced (drops noise below n=3). */
    private const COOCCUR_MIN  = 3;
    private const COOCCUR_TOP  = 10;

    /** Top-N symptoms whose outcome matrix the view renders. */
    private const TOP_SYMPTOMS = 15;

    public function __construct(
        ReportScope $scope,
        ReportAccess $access,
        ExportWriter $writer,
        protected SymptomDistributionInsightEngine $engine,
    ) {
        parent::__construct($scope, $access, $writer);
    }

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);

        return view('admin.reports.rpt-symptom-distribution.index', [
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
            $filters + ['__r' => 'r12'],
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

        $headers = ['Symptom', 'Cases', 'Prevalence %', 'Top outcome', 'Top suspected disease'];
        $rows = [];
        foreach ($payload['frequency'] as $r) {
            $rows[] = [
                $r['symptom_code'],
                $r['count'],
                $r['rate'],
                $r['top_outcome'] ?? '—',
                $r['top_disease'] ?? '—',
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

        // ── Cohort + filters ──────────────────────────────────────────────
        $secQ = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->where('sync_status', '!=', 'FAILED')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($secQ, $scope);

        if (! empty($filters['poe'])) {
            $poes = is_array($filters['poe']) ? $filters['poe'] : explode(',', (string) $filters['poe']);
            $secQ->whereIn('poe_code', array_filter($poes));
        }

        $sec = (clone $secQ)->select([
            'id', 'poe_code', 'opened_at',
            'final_disposition', 'risk_level', 'case_status',
        ])->get();
        $secIds = $sec->pluck('id')->all();

        // ── Related rows ─────────────────────────────────────────────────
        $symptoms = $secIds ? DB::table('secondary_symptoms')
            ->whereIn('secondary_screening_id', $secIds)
            ->where('is_present', 1)
            ->select('secondary_screening_id', 'symptom_code', 'onset_date')
            ->get() : collect();

        $diseases = $secIds ? DB::table('secondary_suspected_diseases')
            ->whereIn('secondary_screening_id', $secIds)
            ->select('secondary_screening_id', 'disease_code', 'rank_order')
            ->get()->groupBy('secondary_screening_id') : collect();

        $alertRows = $secIds ? DB::table('alerts')
            ->whereIn('secondary_screening_id', $secIds)
            ->whereNull('deleted_at')
            ->select('secondary_screening_id', 'status', 'ihr_tier')
            ->get() : collect();

        $confirmedSet = [];
        foreach ($alertRows as $a) {
            if ($a->ihr_tier !== null && $a->status === 'CLOSED') {
                $confirmedSet[(int) $a->secondary_screening_id] = true;
            }
        }

        // ── Build per-case symptom list, plus all per-symptom buckets ─────
        $totalScreened       = $sec->count();
        $symptomsByScreening = $symptoms->groupBy('secondary_screening_id');
        $screeningsWithSymptoms = $symptomsByScreening->keys()->count();

        $symptomCounts    = [];
        $symptomCases     = [];                             // symptom → [secondary_screening_id...]
        $coOccurrence     = [];
        $bySymptomDisease = [];
        $bySymptomOutcome = [];                             // symptom → outcome → count
        $bySymptomRisk    = [];                             // symptom → risk → count
        $bySymptomConfirm = [];                             // symptom → confirmed_count
        $loadBands        = ['0' => 0, '1-2' => 0, '3-5' => 0, '6+' => 0];
        $byPoe            = [];                             // legacy compatibility
        $seasonality      = [];                             // legacy compatibility
        $multiSymptomCount = 0;

        foreach ($sec as $row) {
            $codes = collect($symptomsByScreening->get($row->id, collect()))
                ->pluck('symptom_code')->unique()->values()->all();
            $count = count($codes);

            // Symptom-load bands (relabelled honestly — these are not clinical severity).
            if ($count === 0)        { $loadBands['0']++; }
            elseif ($count <= 2)     { $loadBands['1-2']++; }
            elseif ($count <= 5)     { $loadBands['3-5']++; }
            else                     { $loadBands['6+']++; }

            if ($count >= 3) { $multiSymptomCount++; }

            $diseaseTop = optional($diseases->get($row->id, collect())->sortBy('rank_order')->first())->disease_code;
            $confirmed  = isset($confirmedSet[(int) $row->id]);
            $outcome    = $row->final_disposition ?: ($confirmed ? 'CONFIRMED' : ($row->case_status === 'OPEN' ? 'PENDING' : 'PENDING'));

            foreach ($codes as $c) {
                $symptomCounts[$c] = ($symptomCounts[$c] ?? 0) + 1;
                $symptomCases[$c][] = (int) $row->id;

                if ($diseaseTop) {
                    $bySymptomDisease[$c][$diseaseTop] = ($bySymptomDisease[$c][$diseaseTop] ?? 0) + 1;
                }
                $bySymptomOutcome[$c][$outcome] = ($bySymptomOutcome[$c][$outcome] ?? 0) + 1;
                if ($row->risk_level) {
                    $bySymptomRisk[$c][$row->risk_level] = ($bySymptomRisk[$c][$row->risk_level] ?? 0) + 1;
                }
                if ($confirmed) {
                    $bySymptomConfirm[$c] = ($bySymptomConfirm[$c] ?? 0) + 1;
                }

                $byPoe[$row->poe_code ?? 'UNASSIGNED'][$c] =
                    ($byPoe[$row->poe_code ?? 'UNASSIGNED'][$c] ?? 0) + 1;

                if ($row->opened_at) {
                    $month = (int) Carbon::parse((string) $row->opened_at)->format('n');
                    $seasonality[$c][$month] = ($seasonality[$c][$month] ?? 0) + 1;
                }
            }

            for ($i = 0; $i < count($codes); $i++) {
                for ($j = $i + 1; $j < count($codes); $j++) {
                    $pair = [$codes[$i], $codes[$j]];
                    sort($pair);
                    $key = implode('|', $pair);
                    $coOccurrence[$key] = ($coOccurrence[$key] ?? 0) + 1;
                }
            }
        }

        arsort($symptomCounts);

        // ── Top-15 league with per-symptom signal ─────────────────────────
        $top = [];
        $i   = 0;
        foreach ($symptomCounts as $code => $count) {
            $outcomeMap   = $bySymptomOutcome[$code] ?? [];
            $diseaseMap   = $bySymptomDisease[$code] ?? [];
            $riskMap      = $bySymptomRisk[$code] ?? [];
            arsort($outcomeMap);
            arsort($diseaseMap);
            arsort($riskMap);
            $topOutcome = array_key_first($outcomeMap);
            $topDisease = array_key_first($diseaseMap);
            $topRisk    = array_key_first($riskMap);
            $confirmed  = $bySymptomConfirm[$code] ?? 0;

            $top[] = [
                'symptom_code'  => $code,
                'count'         => $count,
                'rate'          => $totalScreened > 0 ? round($count / $totalScreened * 100, 1) : 0.0,
                'top_outcome'   => $topOutcome,
                'top_outcome_n' => $topOutcome ? $outcomeMap[$topOutcome] : 0,
                'top_disease'   => $topDisease,
                'top_disease_n' => $topDisease ? $diseaseMap[$topDisease] : 0,
                'top_risk'      => $topRisk,
                'confirmed'     => $confirmed,
                'confirmed_pct' => $count >= 5 ? round($confirmed / max(1, $count) * 100, 1) : null,
            ];
            if (++$i >= self::TOP_SYMPTOMS) { break; }
        }

        // ── Top co-occurrence pairs (≥ COOCCUR_MIN, capped at TOP) ────────
        $topPairs = [];
        foreach ($coOccurrence as $pair => $count) {
            if ($count < self::COOCCUR_MIN) { continue; }
            [$a, $b] = explode('|', $pair);
            $topPairs[] = ['a' => $a, 'b' => $b, 'count' => $count];
        }
        usort($topPairs, fn ($x, $y) => $y['count'] <=> $x['count']);
        $topPairs = array_slice($topPairs, 0, self::COOCCUR_TOP);

        // ── Per-symptom drill-down structure (for the second tab) ─────────
        $perSymptom = [];
        foreach ($top as $row) {
            $code  = $row['symptom_code'];
            $cases = $symptomCases[$code] ?? [];
            $cobs  = [];
            foreach ($coOccurrence as $pair => $count) {
                [$a, $b] = explode('|', $pair);
                if ($a !== $code && $b !== $code) { continue; }
                if ($count < self::COOCCUR_MIN)   { continue; }
                $partner = $a === $code ? $b : $a;
                $cobs[] = ['partner' => $partner, 'count' => $count];
            }
            usort($cobs, fn ($x, $y) => $y['count'] <=> $x['count']);

            $outcomeBreakdown = [];
            foreach (($bySymptomOutcome[$code] ?? []) as $oc => $n) {
                $outcomeBreakdown[] = [
                    'outcome' => $oc,
                    'count'   => $n,
                    'pct'     => $row['count'] > 0 ? round($n / $row['count'] * 100, 1) : 0.0,
                ];
            }
            usort($outcomeBreakdown, fn ($x, $y) => $y['count'] <=> $x['count']);

            $diseaseBreakdown = [];
            foreach (($bySymptomDisease[$code] ?? []) as $dc => $n) {
                $diseaseBreakdown[] = ['disease' => $dc, 'count' => $n];
            }
            usort($diseaseBreakdown, fn ($x, $y) => $y['count'] <=> $x['count']);
            $diseaseBreakdown = array_slice($diseaseBreakdown, 0, 8);

            $riskBreakdown = [];
            foreach (($bySymptomRisk[$code] ?? []) as $rl => $n) {
                $riskBreakdown[] = ['risk' => $rl, 'count' => $n];
            }
            usort($riskBreakdown, fn ($x, $y) => $y['count'] <=> $x['count']);

            $perSymptom[$code] = [
                'symptom_code'      => $code,
                'count'             => $row['count'],
                'rate'              => $row['rate'],
                'cases'             => count($cases),
                'cobs'              => array_slice($cobs, 0, 8),
                'outcome_breakdown' => $outcomeBreakdown,
                'disease_breakdown' => $diseaseBreakdown,
                'risk_breakdown'    => $riskBreakdown,
                'confirmed'         => $row['confirmed'],
                'confirmed_pct'     => $row['confirmed_pct'],
            ];
        }

        // ── Outcome matrix (top symptoms × top outcomes) ──────────────────
        $outcomeBuckets = ['RELEASED', 'REFERRED', 'ISOLATED', 'DELAYED', 'CONFIRMED', 'PENDING'];
        $outcomeMatrix  = [];
        foreach ($top as $row) {
            $code = $row['symptom_code'];
            $cell = array_fill_keys($outcomeBuckets, 0);
            foreach (($bySymptomOutcome[$code] ?? []) as $oc => $n) {
                $bucket = in_array($oc, $outcomeBuckets, true) ? $oc : 'PENDING';
                $cell[$bucket] += (int) $n;
            }
            // Confirmed is sourced from the alert join, not disposition.
            $cell['CONFIRMED'] = (int) ($bySymptomConfirm[$code] ?? 0);
            $outcomeMatrix[$code] = $cell;
        }

        // ── Window span (used by view to decide whether to render seasonality) ─
        $windowMonths = (int) round($from->diffInDays($to) / 30);

        // ── Aggregated KPIs ───────────────────────────────────────────────
        $coverage = $totalScreened > 0
            ? round($screeningsWithSymptoms / $totalScreened * 100, 1)
            : 0.0;
        $topCode = $top[0]['symptom_code'] ?? null;
        $topPct  = $top[0]['rate']         ?? 0.0;

        $kpis = [
            // Engine contract — DO NOT REMOVE
            'total_screened'       => $totalScreened,
            'cases_with_symptoms'  => $screeningsWithSymptoms,
            'distinct_symptoms'    => count($symptomCounts),
            'dictionary_coverage'  => $coverage,

            // Premium-view extensions
            'top_symptom'          => $topCode,
            'top_symptom_count'    => $top[0]['count'] ?? 0,
            'top_symptom_pct'      => $topPct,
            'multi_symptom'        => $multiSymptomCount,
            'multi_symptom_pct'    => $totalScreened > 0
                ? round($multiSymptomCount / $totalScreened * 100, 1) : 0.0,
            'avg_symptoms_per_case'=> $screeningsWithSymptoms > 0
                ? round($symptoms->count() / $screeningsWithSymptoms, 1) : 0.0,
        ];

        return [
            'window' => [
                'from'       => $from->toDateString(),
                'to'         => $to->toDateString(),
                'months'     => $windowMonths,
                'generated'  => now()->toIso8601String(),
            ],
            'kpis'             => $kpis,

            // Premium analytics
            'frequency'        => $top,
            'top_pairs'        => $topPairs,
            'per_symptom'      => $perSymptom,
            'outcome_matrix'   => [
                'symptoms' => array_column($top, 'symptom_code'),
                'outcomes' => $outcomeBuckets,
                'matrix'   => $outcomeMatrix,
            ],
            'load_bands'       => $loadBands,

            // Legacy keys retained for compatibility
            'co_occurrence'    => $coOccurrence,
            'symptom_disease'  => $bySymptomDisease,
            'symptom_outcome'  => $bySymptomOutcome,
            'severity_bands'   => $loadBands,
            'seasonality'      => $seasonality,
            'by_poe'           => $byPoe,
            'quality' => [
                'screenings_no_symptoms'   => $totalScreened - $screeningsWithSymptoms,
                'symptomatic_no_records'   => 0,
                'symptom_records_total'    => $symptoms->count(),
            ],
        ];
    }
}
