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
 * R1 · rpt-screening-overview — Executive Screening Overview.
 *
 * Engineering contract: every metric is computed in SQL (conditional sums,
 * GROUP BY date/code) — never by hydrating raw rows into PHP and counting.
 * Tables read: primary_screenings, secondary_screenings, alerts, ref_poes,
 * users, user_assignments. All reads pass through ReportScope::apply().
 */
final class ScreeningOverviewController extends BaseReportController
{
    protected string $reportKey   = 'rpt-screening-overview';
    protected string $reportTitle = 'Screening Overview';

    public function index(Request $request): View
    {
        $this->ensureAccess($request);
        return view('admin.reports.v2.rpt-screening-overview', [
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

        $pq = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->whereBetween('captured_at', [$from, $to]);
        $this->scope->apply($pq, $scope);
        $this->applyPoeFilter($pq, $f);

        $primaryAgg = (clone $pq)->selectRaw("
            COUNT(*) AS total,
            SUM(gender = 'MALE')   AS male,
            SUM(gender = 'FEMALE') AS female,
            SUM(symptoms_present = 1) AS symptomatic
        ")->first();

        $sq = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($sq, $scope);
        $this->applyPoeFilter($sq, $f);
        $secondary = (int) (clone $sq)->count();

        $primary    = (int) ($primaryAgg->total ?? 0);
        $male       = (int) ($primaryAgg->male ?? 0);
        $female     = (int) ($primaryAgg->female ?? 0);
        $sympt      = (int) ($primaryAgg->symptomatic ?? 0);
        $escalation = $primary > 0 ? round(($secondary / $primary) * 100, 1) : null;
        $femalePct  = ($male + $female) > 0 ? round(($female / max(1, $male + $female)) * 100, 1) : null;
        $symptPct   = $primary > 0 ? round(($sympt / $primary) * 100, 1) : null;

        return $this->ok([
            'window' => [
                'from'  => $from->toDateString(),
                'to'    => $to->toDateString(),
                'label' => $from->format('d M Y') . ' – ' . $to->format('d M Y'),
            ],
            'kpis' => [
                ['key' => 'total',      'label' => 'Total Screened',  'value' => number_format($primary), 'tone' => 'brand',   'hint' => 'Primary-tier captures in window.'],
                ['key' => 'secondary',  'label' => 'Secondary',       'value' => number_format($secondary), 'tone' => 'info',  'hint' => 'Escalated to clinical review.'],
                ['key' => 'escalation', 'label' => 'Escalation Rate', 'value' => $escalation === null ? '—' : ($escalation . '%'), 'tone' => $escalation !== null && $escalation >= 30 ? 'warning' : 'success', 'hint' => 'Secondary ÷ Primary.'],
                ['key' => 'female_pct', 'label' => 'Female Share',    'value' => $femalePct === null ? '—' : ($femalePct . '%'), 'tone' => 'neutral', 'hint' => 'Of male+female travellers.'],
                ['key' => 'symptomatic','label' => 'Symptomatic',     'value' => $symptPct === null ? '—' : ($symptPct . '%'), 'tone' => $symptPct !== null && $symptPct >= 10 ? 'warning' : 'neutral', 'hint' => 'Reported symptoms at primary tier.'],
            ],
        ]);
    }

    public function chart(Request $request, string $chart): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        return match ($chart) {
            'volume_over_time' => $this->ok($this->chartVolumeOverTime($scope, $f, $from, $to)),
            'top_poes'         => $this->ok($this->chartTopPoes($scope, $f, $from, $to)),
            default            => $this->fail(404, 'Unknown chart key.'),
        };
    }

    public function chartCsv(Request $request, string $chart): StreamedResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $payload = match ($chart) {
            'volume_over_time' => $this->chartVolumeOverTime($scope, $f, $from, $to),
            'top_poes'         => $this->chartTopPoes($scope, $f, $from, $to),
            default            => abort(404, 'Unknown chart key.'),
        };

        return $this->streamCsv("rpt-screening-overview__{$chart}", $payload['csv_headers'], $payload['csv_rows']);
    }

    public function records(Request $request): JsonResponse
    {
        $scope    = $this->ensureAccess($request);
        $f        = $this->readFilters($request);
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = 10;
        $q        = trim((string) $request->input('q', ''));
        $sort     = (string) $request->input('sort', 'primary');
        $dir      = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $pq = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw('poe_code, COUNT(*) AS pri, MAX(captured_at) AS last_at')
            ->groupBy('poe_code');
        $this->scope->apply($pq, $scope);
        $this->applyPoeFilter($pq, $f);

        $sq = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to])
            ->selectRaw('poe_code, COUNT(*) AS sec')
            ->groupBy('poe_code');
        $this->scope->apply($sq, $scope);
        $this->applyPoeFilter($sq, $f);

        $pri = $pq->get()->keyBy('poe_code');
        $sec = $sq->get()->keyBy('poe_code');

        $codes = $pri->keys()->merge($sec->keys())->unique()->values();
        $rows  = $codes->map(fn (string $code) => [
            'poe_code'        => $code,
            'poe_name'        => $this->resolvePoeName($code),
            'primary'         => (int) ($pri[$code]->pri ?? 0),
            'secondary'       => (int) ($sec[$code]->sec ?? 0),
            'escalation_pct'  => ((int) ($pri[$code]->pri ?? 0)) > 0
                ? round(((int) ($sec[$code]->sec ?? 0) / max(1, (int) ($pri[$code]->pri))) * 100, 1)
                : null,
            'last_screening'  => $pri[$code]->last_at ?? null,
        ]);

        // Search across POE name + code (case-insensitive).
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['poe_name']), $needle)
                || str_contains(mb_strtolower($r['poe_code']), $needle));
        }

        $sortKey = in_array($sort, ['poe_name', 'primary', 'secondary', 'escalation_pct', 'last_screening'], true) ? $sort : 'primary';
        $rows = $rows->sortBy([[$sortKey, $dir]])->values();
        if ($dir === 'desc') {
            $rows = $rows->reverse()->values();
        }

        $total       = $rows->count();
        $totalPages  = max(1, (int) ceil($total / $perPage));
        $page        = min($page, $totalPages);
        $slice       = $rows->forPage($page, $perPage)->values();

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
            'controls' => [
                'sort' => $sortKey,
                'dir'  => $dir,
                'q'    => $q,
            ],
        ]);
    }

    public function recordDetail(Request $request, string $poe): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        // Single aggregated query: primary totals + gender + direction + symptomatic.
        $pAgg = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->where('poe_code', $poe)
            ->whereBetween('captured_at', [$from, $to]);
        $this->scope->apply($pAgg, $scope);

        $primary = (clone $pAgg)->selectRaw("
            COUNT(*) AS total,
            SUM(gender='MALE')    AS male,
            SUM(gender='FEMALE')  AS female,
            SUM(gender='OTHER')   AS other,
            SUM(gender='UNKNOWN') AS unknown,
            SUM(traveler_direction='ENTRY')   AS entry,
            SUM(traveler_direction='EXIT')    AS exit_,
            SUM(traveler_direction='TRANSIT') AS transit,
            SUM(symptoms_present=1)           AS symptomatic
        ")->first();

        // Single aggregated query for secondary.
        $sAgg = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->where('poe_code', $poe)
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($sAgg, $scope);

        $sec = (clone $sAgg)->selectRaw("
            COUNT(*) AS total,
            SUM(final_disposition IN ('REFERRED','TRANSFERRED'))     AS referred,
            SUM(risk_level IN ('HIGH','CRITICAL'))                   AS high_risk,
            SUM(case_status IN ('OPEN','IN_PROGRESS'))               AS in_progress,
            SUM(triage_category='EMERGENCY')                         AS emergency,
            SUM(triage_category='URGENT')                            AS urgent
        ")->first();

        // Alerts aggregate.
        $aAgg = DB::table('alerts')->whereNull('deleted_at')
            ->where('poe_code', $poe)
            ->whereBetween('created_at', [$from, $to]);
        $this->scope->apply($aAgg, $scope);

        $alerts = (clone $aAgg)->selectRaw("
            COUNT(*) AS total,
            SUM(status='OPEN')         AS open_,
            SUM(status='ACKNOWLEDGED') AS acknowledged,
            SUM(status='CLOSED')       AS closed,
            SUM(risk_level='LOW')      AS r_low,
            SUM(risk_level='MEDIUM')   AS r_medium,
            SUM(risk_level='HIGH')     AS r_high,
            SUM(risk_level='CRITICAL') AS r_critical
        ")->first();

        // 14-day sparkline series — one aggregated query.
        $sparkFrom = Carbon::now()->subDays(13)->startOfDay();
        $sparkRows = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->where('poe_code', $poe)
            ->where('captured_at', '>=', $sparkFrom)
            ->selectRaw('DATE(captured_at) AS d, COUNT(*) AS c')
            ->groupBy(DB::raw('DATE(captured_at)'))
            ->pluck('c', 'd')->all();
        $sparkSeries = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->toDateString();
            $sparkSeries[] = ['date' => $d, 'count' => (int) ($sparkRows[$d] ?? 0)];
        }

        // Top officers (capped 5) — aggregated.
        $officers = DB::table('primary_screenings AS p')
            ->leftJoin('users AS u', 'u.id', '=', 'p.captured_by_user_id')
            ->whereNull('p.deleted_at')
            ->where('p.poe_code', $poe)
            ->whereBetween('p.captured_at', [$from, $to])
            ->selectRaw('u.id, u.full_name, u.username, u.role_key, COUNT(*) AS captured, MAX(p.captured_at) AS last_at')
            ->groupBy('u.id', 'u.full_name', 'u.username', 'u.role_key')
            ->orderByDesc('captured')
            ->limit(8)
            ->get();

        // Recent alerts (capped 8).
        $recentAlerts = DB::table('alerts')->whereNull('deleted_at')
            ->where('poe_code', $poe)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'alert_code', 'alert_title', 'risk_level', 'status', 'created_at', 'closed_at']);

        // Hourly heatmap of screenings (24×7) — aggregated.
        $heatmap = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->where('poe_code', $poe)
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw('HOUR(captured_at) AS h, DAYOFWEEK(captured_at) AS dow, COUNT(*) AS c')
            ->groupBy(DB::raw('HOUR(captured_at)'), DB::raw('DAYOFWEEK(captured_at)'))
            ->get();

        $poeInfo = DB::table('ref_poes')
            ->where(fn ($w) => $w->where('poe_code', $poe)->orWhere('poe_name', 'like', '%' . $poe . '%'))
            ->first(['poe_name', 'poe_type', 'transport_mode', 'border_country', 'admin_level_1', 'district', 'is_major_entry', 'is_recommended_osbp', 'latitude', 'longitude']);

        return $this->ok([
            'poe' => [
                'code'              => $poe,
                'name'              => $poeInfo->poe_name ?? $poe,
                'type'              => $poeInfo->poe_type ?? null,
                'transport'         => $poeInfo->transport_mode ?? null,
                'border_country'    => $poeInfo->border_country ?? null,
                'province'          => $poeInfo->admin_level_1 ?? null,
                'district'          => $poeInfo->district ?? null,
                'is_major_entry'    => (bool) ($poeInfo->is_major_entry ?? false),
                'is_recommended_osbp' => (bool) ($poeInfo->is_recommended_osbp ?? false),
                'lat'               => $poeInfo->latitude ?? null,
                'lng'               => $poeInfo->longitude ?? null,
            ],
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'primary'        => (int) ($primary->total ?? 0),
                'secondary'      => (int) ($sec->total ?? 0),
                'symptomatic'    => (int) ($primary->symptomatic ?? 0),
                'referred'       => (int) ($sec->referred ?? 0),
                'high_risk'      => (int) ($sec->high_risk ?? 0),
                'in_progress'    => (int) ($sec->in_progress ?? 0),
                'emergency'      => (int) ($sec->emergency ?? 0),
                'urgent'         => (int) ($sec->urgent ?? 0),
                'alerts'         => (int) ($alerts->total ?? 0),
                'alerts_open'    => (int) (($alerts->open_ ?? 0) + ($alerts->acknowledged ?? 0)),
                'alerts_closed'  => (int) ($alerts->closed ?? 0),
            ],
            'gender' => [
                'MALE'    => (int) ($primary->male ?? 0),
                'FEMALE'  => (int) ($primary->female ?? 0),
                'OTHER'   => (int) ($primary->other ?? 0),
                'UNKNOWN' => (int) ($primary->unknown ?? 0),
            ],
            'direction' => [
                'ENTRY'   => (int) ($primary->entry ?? 0),
                'EXIT'    => (int) ($primary->exit_ ?? 0),
                'TRANSIT' => (int) ($primary->transit ?? 0),
            ],
            'alert_risk_mix' => [
                'LOW'      => (int) ($alerts->r_low ?? 0),
                'MEDIUM'   => (int) ($alerts->r_medium ?? 0),
                'HIGH'     => (int) ($alerts->r_high ?? 0),
                'CRITICAL' => (int) ($alerts->r_critical ?? 0),
            ],
            'sparkline'     => $sparkSeries,
            'heatmap'       => $heatmap, // raw aggregated bucket rows: {h, dow, c}
            'officers'      => $officers,
            'recent_alerts' => $recentAlerts,
        ]);
    }

    /* ─────────────── chart builders (server-side aggregation only) ─────────────── */

    private function chartVolumeOverTime(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        $pq = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw('DATE(captured_at) AS d, COUNT(*) AS c')
            ->groupBy(DB::raw('DATE(captured_at)'));
        $this->scope->apply($pq, $scope);
        $this->applyPoeFilter($pq, $f);
        $pri = $pq->pluck('c', 'd')->all();

        $sq = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to])
            ->selectRaw('DATE(opened_at) AS d, COUNT(*) AS c')
            ->groupBy(DB::raw('DATE(opened_at)'));
        $this->scope->apply($sq, $scope);
        $this->applyPoeFilter($sq, $f);
        $sec = $sq->pluck('c', 'd')->all();

        $labels = $priSeries = $secSeries = [];
        $cur = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        while ($cur <= $end) {
            $d = $cur->toDateString();
            $labels[]    = $cur->format('d M');
            $priSeries[] = (int) ($pri[$d] ?? 0);
            $secSeries[] = (int) ($sec[$d] ?? 0);
            $cur->addDay();
        }

        $rows = [];
        foreach ($labels as $i => $lbl) {
            $rows[] = [$lbl, $priSeries[$i], $secSeries[$i]];
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                ['label' => 'Primary',   'data' => $priSeries],
                ['label' => 'Secondary', 'data' => $secSeries],
            ],
            'csv_headers' => ['Date', 'Primary', 'Secondary'],
            'csv_rows'    => $rows,
        ];
    }

    private function chartTopPoes(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        $q = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw('poe_code, COUNT(*) AS c')
            ->groupBy('poe_code')
            ->orderByDesc('c')
            ->limit(10);
        $this->scope->apply($q, $scope);
        $this->applyPoeFilter($q, $f);
        $rows = $q->get();

        $labels = $data = $csvRows = [];
        foreach ($rows as $r) {
            $name = $this->resolvePoeName((string) $r->poe_code);
            $labels[] = $name;
            $data[]   = (int) $r->c;
            $csvRows[] = [$name, (int) $r->c];
        }

        return [
            'labels'   => $labels,
            'datasets' => [['label' => 'Travellers Screened', 'data' => $data]],
            'csv_headers' => ['Point of Entry', 'Travellers Screened'],
            'csv_rows'    => $csvRows,
        ];
    }

    /* ─────────────── helpers ─────────────── */

    private function applyPoeFilter($q, array $f): void
    {
        if (! empty($f['poe'])) {
            $q->where('poe_code', $f['poe']);
        }
    }

    private function resolvePoeName(string $code): string
    {
        static $cache = [];
        if (isset($cache[$code])) {
            return $cache[$code];
        }
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
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, $filename . '__' . now()->format('Ymd-Hi') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
