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

/**
 * R9 · rpt-symptom-distribution — Symptom Distribution (secondary tier only).
 *
 * Symptoms are stored only at secondary screening tier (`secondary_symptoms`
 * joined to `ref_symptoms`). Primary tier carries only the boolean
 * `symptoms_present`. The view must communicate this on every chart.
 */
final class SymptomDistributionController extends BaseReportController
{
    protected string $reportKey   = 'rpt-symptom-distribution';
    protected string $reportTitle = 'Symptom Distribution';

    public function index(Request $request): View
    {
        $this->ensureAccess($request);
        return view('admin.reports.v2.rpt-symptom-distribution', [
            'reportKey'   => $this->reportKey,
            'reportTitle' => $this->reportTitle,
        ]);
    }

    public function meta(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $cats  = DB::table('ref_symptoms')->where('is_active', 1)
            ->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all();
        return $this->ok([
            'poes'       => $this->scope->allowedPoes($scope),
            'categories' => $cats,
            'scope'      => ['label' => $scope['label'] ?? '—', 'level' => $scope['scope_level'] ?? 'SELF'],
        ]);
    }

    public function kpis(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $secCount = $this->scopedSecondaryQuery($scope, $f, $from, $to)->count();

        $secIds = $this->scopedSecondaryIds($scope, $f, $from, $to);

        $base = DB::table('secondary_symptoms')->where('is_present', 1);
        if (! empty($secIds)) {
            $base->whereIn('secondary_screening_id', $secIds);
        } else {
            $base->whereRaw('1=0');
        }

        $agg = (clone $base)->selectRaw("
            COUNT(*) AS total_present,
            COUNT(DISTINCT symptom_code) AS distinct_symptoms,
            COUNT(DISTINCT secondary_screening_id) AS secondary_with_symptoms
        ")->first();

        $top = (clone $base)
            ->select('symptom_code', DB::raw('COUNT(*) AS c'))
            ->groupBy('symptom_code')->orderByDesc('c')->limit(1)->first();

        $topName = $top ? (DB::table('ref_symptoms')->where('symptom_code', $top->symptom_code)->value('display_name') ?: $top->symptom_code) : '—';

        $redIds = DB::table('ref_symptoms')->where('is_red_flag', 1)->pluck('symptom_code')->all();
        $redCount = $redIds ? (clone $base)->whereIn('symptom_code', $redIds)->count() : 0;

        $totalPresent = (int) ($agg->total_present ?? 0);
        $secWithSx    = (int) ($agg->secondary_with_symptoms ?? 0);
        $redPct       = $totalPresent > 0 ? round(($redCount / max(1, $totalPresent)) * 100, 1) : null;
        $sxPerSec     = $secWithSx > 0 ? round($totalPresent / max(1, $secWithSx), 2) : null;

        return $this->ok([
            'window' => [
                'from'  => $from->toDateString(),
                'to'    => $to->toDateString(),
                'label' => $from->format('d M Y') . ' – ' . $to->format('d M Y'),
            ],
            'kpis' => [
                ['key' => 'sec_total',    'label' => 'Secondary Screenings',  'value' => number_format($secCount), 'tone' => 'brand', 'hint' => 'Window total — symptoms only recorded at this tier.'],
                ['key' => 'with_symptoms','label' => 'With Symptoms',         'value' => number_format($secWithSx), 'tone' => 'info',  'hint' => 'Secondary screenings with ≥1 symptom recorded.'],
                ['key' => 'distinct',     'label' => 'Distinct Symptoms',     'value' => number_format((int) ($agg->distinct_symptoms ?? 0)), 'tone' => 'neutral', 'hint' => 'Unique symptom codes captured.'],
                ['key' => 'top',          'label' => 'Top Symptom',           'value' => $topName, 'tone' => 'success', 'hint' => 'Most-recorded symptom in window.'],
                ['key' => 'red_pct',      'label' => 'Red-Flag Share',        'value' => $redPct === null ? '—' : ($redPct . '%'), 'tone' => $redPct !== null && $redPct >= 20 ? 'critical' : ($redPct !== null && $redPct >= 10 ? 'warning' : 'success'), 'hint' => 'Of all symptom records, those flagged red.'],
            ],
            'extra' => ['symptoms_per_secondary' => $sxPerSec],
        ]);
    }

    public function chart(Request $request, string $chart): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        return match ($chart) {
            'top_symptoms'      => $this->ok($this->chartTopSymptoms($scope, $f, $from, $to)),
            'symptoms_by_category' => $this->ok($this->chartByCategory($scope, $f, $from, $to)),
            default             => $this->fail(404, 'Unknown chart key.'),
        };
    }

    public function chartCsv(Request $request, string $chart): StreamedResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $payload = match ($chart) {
            'top_symptoms'         => $this->chartTopSymptoms($scope, $f, $from, $to),
            'symptoms_by_category' => $this->chartByCategory($scope, $f, $from, $to),
            default                => abort(404, 'Unknown chart key.'),
        };

        return $this->streamCsv("rpt-symptom-distribution__{$chart}", $payload['csv_headers'], $payload['csv_rows']);
    }

    public function records(Request $request): JsonResponse
    {
        $scope    = $this->ensureAccess($request);
        $f        = $this->readFilters($request);
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = 10;
        $q        = trim((string) $request->input('q', ''));
        $sort     = (string) $request->input('sort', 'count');
        $dir      = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $cat      = (string) $request->input('cat', 'all');
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $secIds  = $this->scopedSecondaryIds($scope, $f, $from, $to);
        $secCount = count($secIds);

        if (empty($secIds)) {
            return $this->ok([
                'rows' => [],
                'pagination' => ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 1, 'from' => 0, 'to' => 0],
                'controls'   => ['sort' => $sort, 'dir' => $dir, 'q' => $q, 'cat' => $cat],
                'category_counts' => ['all' => 0, 'red_flag' => 0],
            ]);
        }

        // Aggregate by symptom_code only — joining ref_symptoms here triggers
        // SQLSTATE 1267 collation mismatch (utf8mb4_unicode_ci vs 0900_ai_ci).
        // Two-query lookup pattern (same as R3 disease join).
        $aggRows = DB::table('secondary_symptoms')
            ->whereIn('secondary_screening_id', $secIds)
            ->where('is_present', 1)
            ->selectRaw('symptom_code, COUNT(*) AS c, COUNT(DISTINCT secondary_screening_id) AS uniq')
            ->groupBy('symptom_code')
            ->get();

        $codes = $aggRows->pluck('symptom_code')->all();
        $refMap = empty($codes) ? collect() : DB::table('ref_symptoms')
            ->whereIn('symptom_code', $codes)
            ->get(['symptom_code', 'display_name', 'category', 'is_red_flag', 'is_hallmark'])
            ->keyBy('symptom_code');

        $rows = $aggRows->map(function ($r) use ($refMap, $secCount) {
            $ref = $refMap[$r->symptom_code] ?? null;
            return [
                'symptom_code' => $r->symptom_code,
                'display_name' => ($ref->display_name ?? null) ?: $r->symptom_code,
                'category'     => $ref->category ?? null,
                'is_red_flag'  => (bool) ($ref->is_red_flag ?? false),
                'is_hallmark'  => (bool) ($ref->is_hallmark ?? false),
                'count'        => (int) $r->c,
                'uniq'         => (int) $r->uniq,
                'pct_secondary'=> $secCount > 0 ? round(((int) $r->uniq / max(1, $secCount)) * 100, 1) : null,
            ];
        });

        if ($cat === 'red_flag') {
            $rows = $rows->filter(fn ($r) => $r['is_red_flag']);
        }

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower((string) $r['display_name']), $needle)
                || str_contains(mb_strtolower((string) $r['symptom_code']), $needle)
                || str_contains(mb_strtolower((string) ($r['category'] ?? '')), $needle));
        }

        $sortKey = in_array($sort, ['display_name', 'category', 'count', 'uniq', 'pct_secondary'], true) ? $sort : 'count';
        $rows = $rows->sortBy([[$sortKey, $dir]])->values();
        if ($dir === 'desc') $rows = $rows->reverse()->values();

        $total      = $rows->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $slice      = $rows->forPage($page, $perPage)->values();

        // Category counts (for chips).
        $allCount = $rows->count();
        $redCount = $rows->where('is_red_flag', true)->count();

        return $this->ok([
            'rows' => $slice,
            'pagination' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to'   => min($page * $perPage, $total),
            ],
            'controls' => ['sort' => $sortKey, 'dir' => $dir, 'q' => $q, 'cat' => $cat],
            'category_counts' => ['all' => $allCount, 'red_flag' => $redCount],
        ]);
    }

    public function recordDetail(Request $request, string $symptom): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $info = DB::table('ref_symptoms')->where('symptom_code', $symptom)->first(['symptom_code', 'display_name', 'category', 'syndrome_tags', 'sensitivity', 'is_red_flag', 'is_hallmark']);

        $secIds = $this->scopedSecondaryIds($scope, $f, $from, $to);
        if (empty($secIds)) {
            return $this->ok([
                'symptom' => $info ? (array) $info : ['symptom_code' => $symptom],
                'totals'  => ['present' => 0, 'unique_secondary' => 0],
                'gender'  => ['MALE' => 0, 'FEMALE' => 0, 'OTHER' => 0, 'UNKNOWN' => 0],
                'top_poes' => [], 'co_occurring' => [], 'recent' => [],
                'risk_grid' => [], 'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            ]);
        }

        $sxIds = DB::table('secondary_symptoms')
            ->whereIn('secondary_screening_id', $secIds)
            ->where('is_present', 1)
            ->where('symptom_code', $symptom)
            ->pluck('secondary_screening_id')->unique()->values()->all();

        $totals = DB::table('secondary_symptoms')
            ->whereIn('secondary_screening_id', $secIds)
            ->where('is_present', 1)
            ->where('symptom_code', $symptom)
            ->selectRaw('COUNT(*) AS present, COUNT(DISTINCT secondary_screening_id) AS uniq')
            ->first();

        $gender = empty($sxIds) ? null : DB::table('secondary_screenings')
            ->whereIn('id', $sxIds)
            ->selectRaw("
                SUM(traveler_gender='MALE')    AS male,
                SUM(traveler_gender='FEMALE')  AS female,
                SUM(traveler_gender='OTHER')   AS other_,
                SUM(traveler_gender='UNKNOWN') AS unknown_
            ")->first();

        $topPoes = empty($sxIds) ? collect() : DB::table('secondary_screenings')
            ->whereIn('id', $sxIds)
            ->select('poe_code', DB::raw('COUNT(*) AS c'))
            ->groupBy('poe_code')->orderByDesc('c')->limit(8)->get();

        $riskGrid = empty($sxIds) ? null : DB::table('secondary_screenings')
            ->whereIn('id', $sxIds)
            ->selectRaw("
                SUM(triage_category='URGENT')                    AS urgent,
                SUM(triage_category='EMERGENCY')                 AS emergency,
                SUM(risk_level='HIGH')                           AS high_risk,
                SUM(risk_level='CRITICAL')                       AS critical_risk,
                SUM(final_disposition='RELEASED')                AS released,
                SUM(final_disposition IN ('REFERRED','TRANSFERRED')) AS referred,
                SUM(final_disposition IN ('QUARANTINED','ISOLATED')) AS isolated
            ")->first();

        // Co-occurring symptoms — collation-safe two-query pattern.
        $coRaw = empty($sxIds) ? collect() : DB::table('secondary_symptoms')
            ->whereIn('secondary_screening_id', $sxIds)
            ->where('is_present', 1)
            ->where('symptom_code', '<>', $symptom)
            ->selectRaw('symptom_code, COUNT(*) AS c')
            ->groupBy('symptom_code')
            ->orderByDesc('c')->limit(10)->get();
        $coCodes = $coRaw->pluck('symptom_code')->all();
        $coRef = empty($coCodes) ? collect() : DB::table('ref_symptoms')
            ->whereIn('symptom_code', $coCodes)
            ->get(['symptom_code', 'display_name', 'is_red_flag'])->keyBy('symptom_code');
        $coOccur = $coRaw->map(fn ($r) => (object) [
            'symptom_code' => $r->symptom_code,
            'display_name' => ($coRef[$r->symptom_code]->display_name ?? null),
            'is_red_flag'  => (bool) ($coRef[$r->symptom_code]->is_red_flag ?? false),
            'c'            => (int) $r->c,
        ]);

        $recent = empty($sxIds) ? collect() : DB::table('secondary_screenings')
            ->whereIn('id', $sxIds)
            ->orderByDesc('opened_at')->limit(10)
            ->get(['traveler_full_name', 'traveler_gender', 'traveler_age_years', 'poe_code', 'risk_level', 'final_disposition', 'opened_at']);

        return $this->ok([
            'symptom' => $info ? (array) $info : ['symptom_code' => $symptom],
            'totals'  => ['present' => (int) ($totals->present ?? 0), 'unique_secondary' => (int) ($totals->uniq ?? 0)],
            'gender'  => [
                'MALE'    => (int) ($gender->male ?? 0),
                'FEMALE'  => (int) ($gender->female ?? 0),
                'OTHER'   => (int) ($gender->other_ ?? 0),
                'UNKNOWN' => (int) ($gender->unknown_ ?? 0),
            ],
            'risk_grid' => $riskGrid ? [
                'Urgent'      => (int) $riskGrid->urgent,
                'Emergency'   => (int) $riskGrid->emergency,
                'High Risk'   => (int) $riskGrid->high_risk,
                'Critical'    => (int) $riskGrid->critical_risk,
                'Released'    => (int) $riskGrid->released,
                'Referred'    => (int) $riskGrid->referred,
                'Isolated'    => (int) $riskGrid->isolated,
            ] : [],
            'top_poes' => $topPoes,
            'co_occurring' => $coOccur,
            'recent'   => $recent,
            'window'   => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    /* ───── chart builders ───── */

    private function chartTopSymptoms(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        $secIds   = $this->scopedSecondaryIds($scope, $f, $from, $to);
        $secCount = count($secIds);

        if (empty($secIds)) {
            return ['labels' => [], 'datasets' => [['label' => 'Records', 'data' => []]],
                    'csv_headers' => ['Symptom', 'Records', '% of Secondary'], 'csv_rows' => []];
        }

        $rows = DB::table('secondary_symptoms')
            ->whereIn('secondary_screening_id', $secIds)
            ->where('is_present', 1)
            ->selectRaw('symptom_code, COUNT(*) AS c, COUNT(DISTINCT secondary_screening_id) AS uniq')
            ->groupBy('symptom_code')
            ->orderByDesc('c')
            ->limit(15)->get();

        $codes = $rows->pluck('symptom_code')->all();
        $refMap = empty($codes) ? collect() : DB::table('ref_symptoms')
            ->whereIn('symptom_code', $codes)
            ->get(['symptom_code', 'display_name', 'is_red_flag'])->keyBy('symptom_code');

        $labels = $data = $colors = $csv = [];
        foreach ($rows as $r) {
            $ref  = $refMap[$r->symptom_code] ?? null;
            $name = ($ref->display_name ?? null) ?: $r->symptom_code;
            $labels[]  = $name;
            $data[]    = (int) $r->c;
            $colors[]  = ($ref->is_red_flag ?? false) ? '#ef4444' : '#10b981';
            $pct = $secCount > 0 ? round(((int) $r->uniq / max(1, $secCount)) * 100, 1) : null;
            $csv[] = [$name, (int) $r->c, $pct === null ? '' : ($pct . '%')];
        }

        return [
            'labels'   => $labels,
            'datasets' => [['label' => 'Records', 'data' => $data, 'colors' => $colors]],
            'csv_headers' => ['Symptom', 'Records', '% of Secondary'],
            'csv_rows'    => $csv,
        ];
    }

    private function chartByCategory(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        $secIds = $this->scopedSecondaryIds($scope, $f, $from, $to);
        if (empty($secIds)) {
            return ['labels' => [], 'datasets' => [['label' => 'Records', 'data' => []]],
                    'csv_headers' => ['Category', 'Records'], 'csv_rows' => []];
        }

        // Aggregate symptom counts by symptom_code, then bucket by category in
        // PHP via a code→category map (collation-safe).
        $codeCounts = DB::table('secondary_symptoms')
            ->whereIn('secondary_screening_id', $secIds)
            ->where('is_present', 1)
            ->selectRaw('symptom_code, COUNT(*) AS c')
            ->groupBy('symptom_code')
            ->get();

        $codes = $codeCounts->pluck('symptom_code')->all();
        $catMap = empty($codes) ? [] : DB::table('ref_symptoms')
            ->whereIn('symptom_code', $codes)
            ->pluck('category', 'symptom_code')->all();

        $bucketed = [];
        foreach ($codeCounts as $r) {
            $cat = $catMap[$r->symptom_code] ?? null;
            $cat = $cat ?: 'Uncategorised';
            $bucketed[$cat] = ($bucketed[$cat] ?? 0) + (int) $r->c;
        }
        arsort($bucketed);

        $labels = $data = $csv = [];
        foreach ($bucketed as $cat => $n) {
            $labels[] = $cat;
            $data[]   = (int) $n;
            $csv[]    = [$cat, (int) $n];
        }

        return [
            'labels'   => $labels,
            'datasets' => [['label' => 'Records', 'data' => $data]],
            'csv_headers' => ['Category', 'Records'],
            'csv_rows'    => $csv,
        ];
    }

    /* ───── helpers ───── */

    private function scopedSecondaryQuery(array $scope, array $f, Carbon $from, Carbon $to)
    {
        $q = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($q, $scope);
        if (! empty($f['poe']))     $q->where('poe_code', $f['poe']);
        if (! empty($f['gender']))  $q->where('traveler_gender', $f['gender']);
        return $q;
    }

    private function scopedSecondaryIds(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        return $this->scopedSecondaryQuery($scope, $f, $from, $to)->pluck('id')->all();
    }

    private function streamCsv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $r) fputcsv($out, $r);
            fclose($out);
        }, $filename . '__' . now()->format('Ymd-Hi') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
