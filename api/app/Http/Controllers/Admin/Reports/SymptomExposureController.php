<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Services\Reports\ExportWriter;
use App\Services\Reports\InsightThresholds;
use App\Services\Reports\Insights\SymptomExposureInsightEngine;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * R7 · rpt-symptom-exposure — Symptom Pattern & Exposure Risk
 *
 * Premium chassis (rebuild 2026-04-26): every key the
 * SymptomExposureInsightEngine consumes (kpis.secondary, kpis.high_risk,
 * tripwires, classification_mix) is preserved; the payload is widened with
 * symptom co-occurrence pairs, exposure response detail, per-POE rollup,
 * sex × symptom-load distribution, and a paginated case register.
 */
final class SymptomExposureController extends BaseReportController
{
    protected string $reportKey   = 'rpt-symptom-exposure';
    protected string $reportTitle = 'Symptom Pattern & Exposure Risk';

    /** Maximum rows in the symptom co-occurrence matrix (NxN). */
    private const COOCCUR_MAX = 12;

    public function __construct(
        ReportScope $scope,
        ReportAccess $access,
        ExportWriter $writer,
        protected SymptomExposureInsightEngine $engine,
    ) {
        parent::__construct($scope, $access, $writer);
    }

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);

        return view('admin.reports.rpt-symptom-exposure.index', [
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

        // Default window: past 7 days when no other temporal filter is set.
        if (empty($filters['start_date']) && empty($filters['end_date']) && empty($filters['year']) && empty($filters['quarter']) && empty($filters['month'])) {
            $filters['default_days'] = 7;
        }

        $payload = $this->memoise(
            (int) ($scope['user_id'] ?? 0),
            $filters + ['__r' => 'r7'],
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

        $headers = ['Symptom', 'Reports', 'Cases with ≥3 symptoms', 'Last-7d', 'Spike ratio', 'Trending'];
        $rows = [];
        foreach ($payload['top_symptoms'] as $r) {
            $rows[] = [
                $r['symptom'], $r['count'], $r['coOccurrences'],
                $r['recent7'], $r['ratio'], $r['trending'] ? 'YES' : 'no',
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
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($secQ, $scope);

        if (! empty($filters['poe'])) {
            $poes = is_array($filters['poe']) ? $filters['poe'] : explode(',', (string) $filters['poe']);
            $secQ->whereIn('poe_code', array_filter($poes));
        }
        if (! empty($filters['sex']) || ! empty($filters['gender'])) {
            $secQ->where('traveler_gender', $filters['sex'] ?? $filters['gender']);
        }
        if (! empty($filters['classification'])) {
            $secQ->where('syndrome_classification', $filters['classification']);
        }

        $sec = (clone $secQ)->select([
            'id', 'opened_at', 'syndrome_classification', 'risk_level',
            'poe_code', 'province_code', 'traveler_gender', 'traveler_age_years',
            'traveler_anonymous_code',
        ])->orderBy('opened_at', 'desc')->get();
        $secIds = $sec->pluck('id')->all();

        // ── Symptoms / exposures lookups ──────────────────────────────────
        $symptomRows = $secIds ? DB::table('secondary_symptoms')
            ->whereIn('secondary_screening_id', $secIds)
            ->where('is_present', 1)
            ->select('secondary_screening_id', 'symptom_code', 'onset_date')
            ->get() : collect();

        $exposureRows = $secIds ? DB::table('secondary_exposures')
            ->whereIn('secondary_screening_id', $secIds)
            ->select('secondary_screening_id', 'exposure_code', 'response')
            ->get() : collect();

        $poeNames = [];
        $poeCodes = $sec->pluck('poe_code')->filter()->unique()->values()->all();
        if ($poeCodes) {
            $poeNames = DB::table('ref_poes')
                ->whereIn('poe_code', $poeCodes)
                ->pluck('poe_name', 'poe_code')->all();
        }

        // ── Symptom counts + per-case lists ───────────────────────────────
        $sympCount = [];
        $sympBySec = [];
        $sympRecent7 = [];
        $sympRecent30 = [];

        $now7  = Carbon::now()->subDays(7);
        $now30 = Carbon::now()->subDays(30);

        foreach ($symptomRows as $r) {
            $sympCount[$r->symptom_code] = ($sympCount[$r->symptom_code] ?? 0) + 1;
            $sympBySec[(int) $r->secondary_screening_id][] = $r->symptom_code;

            $d = null;
            if ($r->onset_date) {
                $d = Carbon::parse((string) $r->onset_date);
            } else {
                $opened = $sec->firstWhere('id', $r->secondary_screening_id)?->opened_at;
                if ($opened) { $d = Carbon::parse((string) $opened); }
            }
            if (! $d) { continue; }
            if ($d->between($now7, Carbon::now())) {
                $sympRecent7[$r->symptom_code] = ($sympRecent7[$r->symptom_code] ?? 0) + 1;
            }
            if ($d->between($now30, $now7)) {
                $sympRecent30[$r->symptom_code] = ($sympRecent30[$r->symptom_code] ?? 0) + 1;
            }
        }
        arsort($sympCount);
        $top15keys = array_slice(array_keys($sympCount), 0, 15);

        // ── Top-15 detail with spike detection ────────────────────────────
        $tripwires   = [];
        $topSymptoms = [];
        foreach ($top15keys as $key) {
            $recent = (int) ($sympRecent7[$key] ?? 0);
            $base   = max(1.0, ($sympRecent30[$key] ?? 0) / 23.0);
            $ratio  = $recent > 0 ? round(($recent / 7.0) / $base, 2) : 0.0;

            $coOccurCount = 0;
            foreach ($sympBySec as $codes) {
                if (count($codes) >= 3 && in_array($key, $codes, true)) { $coOccurCount++; }
            }

            $trending = $ratio >= InsightThresholds::SPIKE_RATIO;
            if ($trending) {
                $tripwires[] = ['symptom' => $key, 'recent' => $recent, 'ratio' => $ratio];
            }

            $topSymptoms[] = [
                'symptom'       => $key,
                'count'         => $sympCount[$key],
                'pct'           => $sec->count() > 0 ? round($sympCount[$key] / $sec->count() * 100, 1) : 0.0,
                'coOccurrences' => $coOccurCount,
                'trending'      => $trending,
                'recent7'       => $recent,
                'ratio'         => $ratio,
            ];
        }

        // ── Co-occurrence matrix on top-N (NxN) ────────────────────────────
        $coTop = array_slice($top15keys, 0, self::COOCCUR_MAX);
        $coMatrix = [];
        foreach ($coTop as $a) {
            $coMatrix[$a] = array_fill_keys($coTop, 0);
        }
        $coTopSet = array_flip($coTop);
        foreach ($sympBySec as $codes) {
            $relevant = array_values(array_intersect($codes, $coTop));
            $relevant = array_unique($relevant);
            $n = count($relevant);
            if ($n < 2) { continue; }
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $relevant[$i]; $b = $relevant[$j];
                    $coMatrix[$a][$b]++;
                    $coMatrix[$b][$a]++;
                }
            }
        }
        // Diagonal = symptom's total count (visual reference).
        foreach ($coTop as $a) {
            $coMatrix[$a][$a] = $sympCount[$a] ?? 0;
        }

        // ── Symptom × syndrome classification stacked bar ─────────────────
        $classMix = [];
        foreach ($sec as $s) {
            $key = $s->syndrome_classification ?: 'UNSET';
            $classMix[$key] = ($classMix[$key] ?? 0) + 1;
        }
        arsort($classMix);
        $topClasses = array_slice(array_keys($classMix), 0, 5);

        $symptomByClass = [];
        $stackSymptoms  = array_slice($top15keys, 0, 8);
        foreach ($topClasses as $cls) {
            $symptomByClass[$cls] = array_fill_keys($stackSymptoms, 0);
        }
        foreach ($symptomRows as $r) {
            $s = $sec->firstWhere('id', $r->secondary_screening_id);
            if (! $s) { continue; }
            $cls = $s->syndrome_classification ?: 'UNSET';
            if (! isset($symptomByClass[$cls])) { continue; }
            if (! isset($symptomByClass[$cls][$r->symptom_code])) { continue; }
            $symptomByClass[$cls][$r->symptom_code]++;
        }

        // ── Exposure category rollup + per-code detail ────────────────────
        $expCategories  = [];
        $exposureDetail = [];
        $highRiskSecSet = [];
        foreach ($exposureRows as $r) {
            $cat = strtok($r->exposure_code, '_') ?: 'OTHER';
            $expCategories[$cat] = ($expCategories[$cat] ?? 0) + 1;
            if (! isset($exposureDetail[$r->exposure_code])) {
                $exposureDetail[$r->exposure_code] = [
                    'code'    => $r->exposure_code,
                    'category'=> $cat,
                    'yes'     => 0, 'no' => 0, 'unknown' => 0, 'total' => 0,
                ];
            }
            $exposureDetail[$r->exposure_code]['total']++;
            if ($r->response === 'YES')     { $exposureDetail[$r->exposure_code]['yes']++; }
            elseif ($r->response === 'NO')  { $exposureDetail[$r->exposure_code]['no']++; }
            else                            { $exposureDetail[$r->exposure_code]['unknown']++; }

            if ($r->response === 'YES') {
                $highRiskSecSet[(int) $r->secondary_screening_id] = true;
            }
        }
        arsort($expCategories);
        $exposureDetail = array_values($exposureDetail);
        usort($exposureDetail, fn ($a, $b) => $b['yes'] <=> $a['yes'] ?: $b['total'] <=> $a['total']);

        // High-risk cohort = ≥3 symptoms OR YES exposure OR HIGH/CRITICAL risk.
        foreach ($sympBySec as $sid => $list) {
            if (count($list) >= 3) { $highRiskSecSet[(int) $sid] = true; }
        }
        foreach ($sec as $s) {
            if (in_array($s->risk_level, ['HIGH', 'CRITICAL'], true)) {
                $highRiskSecSet[(int) $s->id] = true;
            }
        }
        $highRisk = count($highRiskSecSet);

        // ── Weekly stream of high-risk cases ──────────────────────────────
        $weeks = [];
        $weekLabels = [];
        $cur = $from->copy()->startOfWeek();
        while ($cur->lte($to)) {
            $weeks[]      = $cur->format('Y-\WW');
            $weekLabels[] = $cur->copy()->endOfWeek()->toDateString();
            $cur->addWeek();
        }
        $stream      = array_fill_keys($weeks, 0);
        $totalStream = array_fill_keys($weeks, 0);
        foreach ($sec as $s) {
            if (! $s->opened_at) { continue; }
            $wk = Carbon::parse((string) $s->opened_at)->startOfWeek()->format('Y-\WW');
            if (! isset($stream[$wk])) { continue; }
            $totalStream[$wk]++;
            if (isset($highRiskSecSet[(int) $s->id])) { $stream[$wk]++; }
        }

        // ── Per-POE rollup ───────────────────────────────────────────────
        $perPoe = [];
        foreach ($sec as $s) {
            $code = $s->poe_code ?: 'UNASSIGNED';
            if (! isset($perPoe[$code])) {
                $perPoe[$code] = [
                    'poe_code'   => $code,
                    'poe_name'   => $poeNames[$code] ?? $code,
                    'screened'   => 0,
                    'high_risk'  => 0,
                    'multi_symptom' => 0,
                    'with_exposure' => 0,
                ];
            }
            $perPoe[$code]['screened']++;
            if (isset($highRiskSecSet[(int) $s->id])) { $perPoe[$code]['high_risk']++; }
            if (count($sympBySec[(int) $s->id] ?? []) >= 3) { $perPoe[$code]['multi_symptom']++; }
        }
        // Mark cases with at least one YES exposure
        $yesExposureBySec = [];
        foreach ($exposureRows as $r) {
            if ($r->response === 'YES') {
                $yesExposureBySec[(int) $r->secondary_screening_id] = true;
            }
        }
        foreach ($sec as $s) {
            if (isset($yesExposureBySec[(int) $s->id])) {
                $code = $s->poe_code ?: 'UNASSIGNED';
                if (isset($perPoe[$code])) { $perPoe[$code]['with_exposure']++; }
            }
        }
        $perPoeTable = array_values($perPoe);
        foreach ($perPoeTable as &$row) {
            $row['high_risk_pct'] = $row['screened'] > 0
                ? round($row['high_risk'] / $row['screened'] * 100, 1) : 0.0;
        }
        unset($row);
        usort($perPoeTable, fn ($a, $b) => $b['high_risk'] <=> $a['high_risk'] ?: $b['screened'] <=> $a['screened']);

        // ── Sex × symptom load (0, 1-2, 3+) ───────────────────────────────
        $sexSymptomLoad = [
            'MALE'   => ['none' => 0, 'low' => 0, 'high' => 0],
            'FEMALE' => ['none' => 0, 'low' => 0, 'high' => 0],
            'OTHER'  => ['none' => 0, 'low' => 0, 'high' => 0],
        ];
        foreach ($sec as $s) {
            $g = in_array($s->traveler_gender, ['MALE', 'FEMALE'], true) ? $s->traveler_gender : 'OTHER';
            $count = count($sympBySec[(int) $s->id] ?? []);
            if ($count === 0)        { $sexSymptomLoad[$g]['none']++; }
            elseif ($count <= 2)     { $sexSymptomLoad[$g]['low']++; }
            else                     { $sexSymptomLoad[$g]['high']++; }
        }

        // ── Onset-day distribution (when symptoms began, last 30 days) ────
        $onsetByDay = [];
        $window30Start = Carbon::now()->subDays(29)->startOfDay();
        for ($i = 0; $i < 30; $i++) {
            $d = $window30Start->copy()->addDays($i)->toDateString();
            $onsetByDay[$d] = 0;
        }
        foreach ($symptomRows as $r) {
            if (! $r->onset_date) { continue; }
            $d = (string) $r->onset_date;
            if (isset($onsetByDay[$d])) { $onsetByDay[$d]++; }
        }

        // ── High-risk case register (line list, last 50) ──────────────────
        $highCases = [];
        foreach ($sec as $s) {
            if (! isset($highRiskSecSet[(int) $s->id])) { continue; }
            $sid = (int) $s->id;
            $exposuresHere = [];
            foreach ($exposureRows as $r) {
                if ((int) $r->secondary_screening_id === $sid && $r->response === 'YES') {
                    $exposuresHere[] = $r->exposure_code;
                }
            }
            $highCases[] = [
                'id'        => $sid,
                'code'      => $s->traveler_anonymous_code ?: 'SC-' . str_pad((string) $sid, 4, '0', STR_PAD_LEFT),
                'opened_at' => $s->opened_at ? (string) $s->opened_at : null,
                'poe_code'  => $s->poe_code,
                'poe_name'  => $poeNames[$s->poe_code] ?? $s->poe_code,
                'province'  => $s->province_code,
                'sex'       => $s->traveler_gender,
                'age'       => $s->traveler_age_years,
                'risk'      => $s->risk_level,
                'syndrome'  => $s->syndrome_classification,
                'symptoms'  => array_values(array_unique($sympBySec[$sid] ?? [])),
                'exposures' => $exposuresHere,
            ];
        }
        usort($highCases, fn ($a, $b) => strcmp((string) $b['opened_at'], (string) $a['opened_at']));
        $highCases = array_slice($highCases, 0, 100);

        // ── Aggregated KPIs ───────────────────────────────────────────────
        $totalCohort = $sec->count();
        $multiSymptomCount = 0;
        foreach ($sympBySec as $codes) {
            if (count($codes) >= 3) { $multiSymptomCount++; }
        }
        $exposureYesCases = count($yesExposureBySec);
        $topSymptomPct = $top15keys && $totalCohort > 0
            ? round(($sympCount[$top15keys[0]] ?? 0) / $totalCohort * 100, 1)
            : 0.0;

        $kpis = [
            // Insight-engine contract — DO NOT REMOVE
            'secondary'          => $totalCohort,
            'high_risk'          => $highRisk,

            // Premium-view extensions
            'distinct_symptoms'  => count($sympCount),
            'distinct_exposures' => count($expCategories),
            'tripwire_count'     => count($tripwires),
            'multi_symptom'      => $multiSymptomCount,
            'multi_symptom_pct'  => $totalCohort > 0 ? round($multiSymptomCount / $totalCohort * 100, 1) : 0.0,
            'exposure_yes_cases' => $exposureYesCases,
            'high_risk_pct'      => $totalCohort > 0 ? round($highRisk / $totalCohort * 100, 1) : 0.0,
            'top_symptom'        => $top15keys[0] ?? null,
            'top_symptom_count'  => $top15keys ? ($sympCount[$top15keys[0]] ?? 0) : 0,
            'top_symptom_pct'    => $topSymptomPct,
            'reporting_poes'     => count($perPoe),
        ];

        return [
            'window' => [
                'from'      => $from->toDateString(),
                'to'        => $to->toDateString(),
                'generated' => now()->toIso8601String(),
            ],
            'kpis'                => $kpis,

            // Premium analytics
            'top_symptoms'        => $topSymptoms,
            'cooccurrence'        => [
                'symptoms' => $coTop,
                'matrix'   => $coMatrix,
            ],
            'symptom_by_class'    => $symptomByClass,
            'classification_mix'  => $classMix,
            'exposure_categories' => $expCategories,
            'exposure_detail'     => $exposureDetail,
            'sex_symptom_load'    => $sexSymptomLoad,
            'onset_by_day'        => $onsetByDay,
            'per_poe_table'       => $perPoeTable,
            'stream'              => [
                'weeks'        => $weeks,
                'week_labels'  => $weekLabels,
                'high_risk'    => $stream,
                'total'        => $totalStream,
            ],
            'high_cases'          => $highCases,
            'tripwires'           => $tripwires,
        ];
    }
}
