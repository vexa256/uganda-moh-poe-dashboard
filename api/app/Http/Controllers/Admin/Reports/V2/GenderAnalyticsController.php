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
 * R2 · rpt-gender — Gender Analytics.
 *
 * Server-side aggregation only. Built to remain flat at millions of rows.
 */
final class GenderAnalyticsController extends BaseReportController
{
    protected string $reportKey   = 'rpt-gender';
    protected string $reportTitle = 'Gender Analytics';

    public function index(Request $request): View
    {
        $this->ensureAccess($request);
        return view('admin.reports.v2.rpt-gender', [
            'reportKey'   => $this->reportKey,
            'reportTitle' => $this->reportTitle,
        ]);
    }

    public function meta(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        return $this->ok([
            'poes'  => $this->scope->allowedPoes($scope),
            'scope' => ['label' => $scope['label'] ?? '—', 'level' => $scope['scope_level'] ?? 'SELF'],
        ]);
    }

    public function kpis(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $q = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->whereBetween('captured_at', [$from, $to]);
        $this->scope->apply($q, $scope);
        $this->applyPoeFilter($q, $f);

        $agg = (clone $q)->selectRaw("
            COUNT(*) AS total,
            SUM(gender='MALE')    AS male,
            SUM(gender='FEMALE')  AS female,
            SUM(gender='OTHER')   AS other_,
            SUM(gender='UNKNOWN') AS unknown_
        ")->first();

        $total      = (int) ($agg->total ?? 0);
        $male       = (int) ($agg->male ?? 0);
        $female     = (int) ($agg->female ?? 0);
        $other      = (int) ($agg->other_ ?? 0);
        $unknown    = (int) ($agg->unknown_ ?? 0);
        $femalePct  = ($male + $female) > 0 ? round(($female / max(1, $male + $female)) * 100, 1) : null;
        $otherPct   = $total > 0 ? round((($other + $unknown) / max(1, $total)) * 100, 1) : null;

        return $this->ok([
            'window' => [
                'from'  => $from->toDateString(),
                'to'    => $to->toDateString(),
                'label' => $from->format('d M Y') . ' – ' . $to->format('d M Y'),
            ],
            'kpis' => [
                ['key' => 'total',     'label' => 'Total Screened',  'value' => number_format($total),  'tone' => 'brand',   'hint' => 'Primary-tier captures in window.'],
                ['key' => 'male',      'label' => 'Male',            'value' => number_format($male),   'tone' => 'info',    'hint' => 'Travellers recorded as male.'],
                ['key' => 'female',    'label' => 'Female',          'value' => number_format($female), 'tone' => 'info',    'hint' => 'Travellers recorded as female.'],
                ['key' => 'femalePct', 'label' => 'Female Share',    'value' => $femalePct === null ? '—' : ($femalePct . '%'), 'tone' => 'success', 'hint' => 'Of male+female travellers.'],
                ['key' => 'otherPct',  'label' => 'Other / Unknown', 'value' => $otherPct === null ? '—' : ($otherPct . '%'), 'tone' => $otherPct !== null && $otherPct >= 5 ? 'warning' : 'neutral', 'hint' => 'Data-quality signal — high values mean missing data.'],
            ],
        ]);
    }

    public function chart(Request $request, string $chart): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        return match ($chart) {
            'gender_over_time' => $this->ok($this->chartOverTime($scope, $f, $from, $to)),
            'gender_by_poe'    => $this->ok($this->chartByPoe($scope, $f, $from, $to)),
            default            => $this->fail(404, 'Unknown chart key.'),
        };
    }

    public function chartCsv(Request $request, string $chart): StreamedResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $payload = match ($chart) {
            'gender_over_time' => $this->chartOverTime($scope, $f, $from, $to),
            'gender_by_poe'    => $this->chartByPoe($scope, $f, $from, $to),
            default            => abort(404, 'Unknown chart key.'),
        };

        return $this->streamCsv("rpt-gender__{$chart}", $payload['csv_headers'], $payload['csv_rows']);
    }

    public function records(Request $request): JsonResponse
    {
        $scope    = $this->ensureAccess($request);
        $f        = $this->readFilters($request);
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = 10;
        $q        = trim((string) $request->input('q', ''));
        $sort     = (string) $request->input('sort', 'total');
        $dir      = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $qb = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw("
                poe_code,
                COUNT(*) AS total,
                SUM(gender='MALE')    AS male,
                SUM(gender='FEMALE')  AS female,
                SUM(gender='OTHER')   AS other_,
                SUM(gender='UNKNOWN') AS unknown_
            ")
            ->groupBy('poe_code');
        $this->scope->apply($qb, $scope);
        $this->applyPoeFilter($qb, $f);
        $rows = $qb->get()->map(function ($r) {
            $male   = (int) $r->male;
            $female = (int) $r->female;
            return [
                'poe_code'   => $r->poe_code,
                'poe_name'   => $this->resolvePoeName((string) $r->poe_code),
                'total'      => (int) $r->total,
                'male'       => $male,
                'female'     => $female,
                'other'      => (int) $r->other_,
                'unknown'    => (int) $r->unknown_,
                'female_pct' => ($male + $female) > 0 ? round(($female / max(1, $male + $female)) * 100, 1) : null,
            ];
        });

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['poe_name']), $needle)
                || str_contains(mb_strtolower($r['poe_code']), $needle));
        }

        $sortKey = in_array($sort, ['poe_name', 'total', 'male', 'female', 'female_pct'], true) ? $sort : 'total';
        $rows = $rows->sortBy([[$sortKey, $dir]])->values();
        if ($dir === 'desc') $rows = $rows->reverse()->values();

        $total      = $rows->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $slice      = $rows->forPage($page, $perPage)->values();

        return $this->ok([
            'rows' => $slice,
            'pagination' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to'   => min($page * $perPage, $total),
            ],
            'controls' => ['sort' => $sortKey, 'dir' => $dir, 'q' => $q],
        ]);
    }

    public function recordDetail(Request $request, string $poe): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $pAgg = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->where('poe_code', $poe)
            ->whereBetween('captured_at', [$from, $to]);
        $this->scope->apply($pAgg, $scope);

        $primary = (clone $pAgg)->selectRaw("
            COUNT(*) AS total,
            SUM(gender='MALE')    AS male,
            SUM(gender='FEMALE')  AS female,
            SUM(gender='OTHER')   AS other_,
            SUM(gender='UNKNOWN') AS unknown_,
            SUM(gender='MALE'    AND traveler_direction='ENTRY')   AS m_entry,
            SUM(gender='FEMALE'  AND traveler_direction='ENTRY')   AS f_entry,
            SUM(gender='MALE'    AND traveler_direction='EXIT')    AS m_exit,
            SUM(gender='FEMALE'  AND traveler_direction='EXIT')    AS f_exit,
            SUM(gender='MALE'    AND traveler_direction='TRANSIT') AS m_transit,
            SUM(gender='FEMALE'  AND traveler_direction='TRANSIT') AS f_transit
        ")->first();

        $sAgg = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->where('poe_code', $poe)
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($sAgg, $scope);

        $secondary = (clone $sAgg)->selectRaw("
            COUNT(*) AS total,
            SUM(traveler_gender='MALE')    AS male,
            SUM(traveler_gender='FEMALE')  AS female,
            SUM(traveler_gender='OTHER')   AS other_,
            SUM(traveler_gender='UNKNOWN') AS unknown_,
            SUM(traveler_gender='MALE'   AND triage_category='URGENT')    AS m_urgent,
            SUM(traveler_gender='FEMALE' AND triage_category='URGENT')    AS f_urgent,
            SUM(traveler_gender='MALE'   AND triage_category='EMERGENCY') AS m_emergency,
            SUM(traveler_gender='FEMALE' AND triage_category='EMERGENCY') AS f_emergency,
            SUM(traveler_gender='MALE'   AND risk_level IN ('HIGH','CRITICAL')) AS m_highrisk,
            SUM(traveler_gender='FEMALE' AND risk_level IN ('HIGH','CRITICAL')) AS f_highrisk
        ")->first();

        $sparkRows = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->where('poe_code', $poe)
            ->where('captured_at', '>=', Carbon::now()->subDays(13)->startOfDay())
            ->selectRaw("DATE(captured_at) AS d, SUM(gender='MALE') AS m, SUM(gender='FEMALE') AS fe")
            ->groupBy(DB::raw('DATE(captured_at)'))
            ->get()->keyBy('d');

        $spark = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->toDateString();
            $r = $sparkRows[$d] ?? null;
            $spark[] = ['date' => $d, 'male' => (int) ($r->m ?? 0), 'female' => (int) ($r->fe ?? 0)];
        }

        $recent = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->where('poe_code', $poe)
            ->orderByDesc('captured_at')->limit(10)
            ->get(['traveler_full_name', 'gender', 'traveler_direction', 'symptoms_present', 'captured_at']);

        $poeInfo = DB::table('ref_poes')
            ->where(fn ($w) => $w->where('poe_code', $poe)->orWhere('poe_name', 'like', '%' . $poe . '%'))
            ->first(['poe_name', 'poe_type', 'transport_mode', 'border_country', 'admin_level_1', 'district']);

        return $this->ok([
            'poe' => [
                'code' => $poe,
                'name' => $poeInfo->poe_name ?? $poe,
                'type' => $poeInfo->poe_type ?? null,
                'transport' => $poeInfo->transport_mode ?? null,
                'border_country' => $poeInfo->border_country ?? null,
                'province' => $poeInfo->admin_level_1 ?? null,
                'district' => $poeInfo->district ?? null,
            ],
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'primary' => [
                'total'   => (int) ($primary->total ?? 0),
                'MALE'    => (int) ($primary->male ?? 0),
                'FEMALE'  => (int) ($primary->female ?? 0),
                'OTHER'   => (int) ($primary->other_ ?? 0),
                'UNKNOWN' => (int) ($primary->unknown_ ?? 0),
            ],
            'secondary' => [
                'total'   => (int) ($secondary->total ?? 0),
                'MALE'    => (int) ($secondary->male ?? 0),
                'FEMALE'  => (int) ($secondary->female ?? 0),
                'OTHER'   => (int) ($secondary->other_ ?? 0),
                'UNKNOWN' => (int) ($secondary->unknown_ ?? 0),
            ],
            'direction_grid' => [
                ['direction' => 'ENTRY',   'male' => (int) ($primary->m_entry ?? 0),   'female' => (int) ($primary->f_entry ?? 0)],
                ['direction' => 'EXIT',    'male' => (int) ($primary->m_exit ?? 0),    'female' => (int) ($primary->f_exit ?? 0)],
                ['direction' => 'TRANSIT', 'male' => (int) ($primary->m_transit ?? 0), 'female' => (int) ($primary->f_transit ?? 0)],
            ],
            'risk_grid' => [
                ['bucket' => 'Urgent',     'male' => (int) ($secondary->m_urgent ?? 0),    'female' => (int) ($secondary->f_urgent ?? 0)],
                ['bucket' => 'Emergency',  'male' => (int) ($secondary->m_emergency ?? 0), 'female' => (int) ($secondary->f_emergency ?? 0)],
                ['bucket' => 'High-Risk',  'male' => (int) ($secondary->m_highrisk ?? 0),  'female' => (int) ($secondary->f_highrisk ?? 0)],
            ],
            'sparkline' => $spark,
            'recent'    => $recent,
        ]);
    }

    private function chartOverTime(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        $q = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw("DATE(captured_at) AS d,
                SUM(gender='MALE')    AS male,
                SUM(gender='FEMALE')  AS female,
                SUM(gender='OTHER')   AS other_,
                SUM(gender='UNKNOWN') AS unknown_
            ")
            ->groupBy(DB::raw('DATE(captured_at)'));
        $this->scope->apply($q, $scope);
        $this->applyPoeFilter($q, $f);
        $rows = $q->get()->keyBy('d');

        $labels = $male = $female = $other = $unknown = $csv = [];
        $cur = $from->copy();
        while ($cur <= $to) {
            $d = $cur->toDateString();
            $r = $rows[$d] ?? null;
            $labels[]   = $cur->format('d M');
            $male[]     = (int) ($r->male ?? 0);
            $female[]   = (int) ($r->female ?? 0);
            $other[]    = (int) ($r->other_ ?? 0);
            $unknown[]  = (int) ($r->unknown_ ?? 0);
            $csv[]      = [$cur->format('d M'), (int) ($r->male ?? 0), (int) ($r->female ?? 0), (int) ($r->other_ ?? 0), (int) ($r->unknown_ ?? 0)];
            $cur->addDay();
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                ['label' => 'Male',    'data' => $male],
                ['label' => 'Female',  'data' => $female],
                ['label' => 'Other',   'data' => $other],
                ['label' => 'Unknown', 'data' => $unknown],
            ],
            'csv_headers' => ['Date', 'Male', 'Female', 'Other', 'Unknown'],
            'csv_rows'    => $csv,
        ];
    }

    private function chartByPoe(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        $q = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw("poe_code,
                COUNT(*) AS total,
                SUM(gender='MALE')    AS male,
                SUM(gender='FEMALE')  AS female,
                SUM(gender='OTHER')   AS other_,
                SUM(gender='UNKNOWN') AS unknown_
            ")
            ->groupBy('poe_code')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit(10);
        $this->scope->apply($q, $scope);
        $this->applyPoeFilter($q, $f);
        $rows = $q->get();

        $labels = $male = $female = $other = $unknown = $csv = [];
        foreach ($rows as $r) {
            $name = $this->resolvePoeName((string) $r->poe_code);
            $labels[]  = $name;
            $male[]    = (int) $r->male;
            $female[]  = (int) $r->female;
            $other[]   = (int) $r->other_;
            $unknown[] = (int) $r->unknown_;
            $csv[]     = [$name, (int) $r->total, (int) $r->male, (int) $r->female, (int) $r->other_, (int) $r->unknown_];
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                ['label' => 'Male',    'data' => $male],
                ['label' => 'Female',  'data' => $female],
                ['label' => 'Other',   'data' => $other],
                ['label' => 'Unknown', 'data' => $unknown],
            ],
            'csv_headers' => ['Point of Entry', 'Total', 'Male', 'Female', 'Other', 'Unknown'],
            'csv_rows'    => $csv,
        ];
    }

    private function applyPoeFilter($q, array $f): void
    {
        if (! empty($f['poe'])) $q->where('poe_code', $f['poe']);
    }

    private function resolvePoeName(string $code): string
    {
        static $cache = [];
        if (isset($cache[$code])) return $cache[$code];
        $row = DB::table('ref_poes')
            ->where('poe_code', $code)
            ->orWhere('poe_name', 'like', '%' . $code . '%')
            ->value('poe_name');
        return $cache[$code] = $row ?: $code;
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
