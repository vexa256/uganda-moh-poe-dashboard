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
 * R4 · rpt-response-time — Alert Response Timeliness.
 *
 * SLA tiers (per risk_level):
 *   CRITICAL → 4h, HIGH → 24h, MEDIUM/LOW → 48h.
 * Acknowledgement time = acknowledged_at - created_at.
 * Resolution time      = closed_at        - created_at.
 */
final class ResponseTimelinessController extends BaseReportController
{
    protected string $reportKey   = 'rpt-response-time';
    protected string $reportTitle = 'Response Timeliness';

    public function index(Request $request): View
    {
        $this->ensureAccess($request);
        return view('admin.reports.v2.rpt-response-time', [
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
        $now = Carbon::now();

        $q = DB::table('alerts')->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to]);
        $this->scopeAlerts($q, $scope, $f);

        $agg = (clone $q)->selectRaw("
            COUNT(*) AS total,
            AVG(CASE WHEN acknowledged_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, acknowledged_at) END) AS avg_ack_min,
            AVG(CASE WHEN closed_at       IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, closed_at)       END) AS avg_close_min,
            SUM(acknowledged_at IS NOT NULL AND TIMESTAMPDIFF(HOUR,   created_at, acknowledged_at) <= 1)  AS ack_within_1h,
            SUM(closed_at       IS NOT NULL AND TIMESTAMPDIFF(HOUR,   created_at, closed_at)       <= 24) AS res_within_24h,
            SUM(closed_at IS NOT NULL) AS closed_n,
            SUM(acknowledged_at IS NOT NULL) AS ack_n
        ")->first();

        // Open > 24h.
        $openOverdue = (clone $q)->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])
            ->where('created_at', '<', $now->copy()->subHours(24))->count();

        // SLA breach (per-tier).
        $slaAgg = (clone $q)->selectRaw("
            SUM(CASE WHEN closed_at IS NULL AND status IN ('OPEN','ACKNOWLEDGED') THEN
                CASE
                    WHEN risk_level='CRITICAL' AND TIMESTAMPDIFF(HOUR, created_at, ?) > 4  THEN 1
                    WHEN risk_level='HIGH'     AND TIMESTAMPDIFF(HOUR, created_at, ?) > 24 THEN 1
                    WHEN risk_level NOT IN ('CRITICAL','HIGH') AND TIMESTAMPDIFF(HOUR, created_at, ?) > 48 THEN 1
                    ELSE 0 END
                ELSE 0 END
            ) AS breached
        ", [$now, $now, $now])->first();

        $total      = (int) ($agg->total ?? 0);
        $avgAck     = $agg->avg_ack_min ? (float) $agg->avg_ack_min : null;
        $avgClose   = $agg->avg_close_min ? (float) $agg->avg_close_min : null;
        $closedN    = (int) ($agg->closed_n ?? 0);
        $ackN       = (int) ($agg->ack_n ?? 0);
        $ackPct     = $ackN > 0    ? round((((int) ($agg->ack_within_1h ?? 0)) / max(1, $ackN)) * 100, 1) : null;
        $resPct     = $closedN > 0 ? round((((int) ($agg->res_within_24h ?? 0)) / max(1, $closedN)) * 100, 1) : null;

        return $this->ok([
            'window' => [
                'from'  => $from->toDateString(),
                'to'    => $to->toDateString(),
                'label' => $from->format('d M Y') . ' – ' . $to->format('d M Y'),
            ],
            'kpis' => [
                ['key' => 'avg_ack',   'label' => 'Avg Ack Time',       'value' => $this->humaniseMinutes($avgAck), 'tone' => 'info', 'hint' => 'Mean minutes from creation to acknowledgement.'],
                ['key' => 'avg_close', 'label' => 'Avg Resolution',     'value' => $this->humaniseMinutes($avgClose), 'tone' => 'brand', 'hint' => 'Mean time from creation to closure.'],
                ['key' => 'ack_pct',   'label' => 'Acked ≤ 1h',         'value' => $ackPct === null ? '—' : ($ackPct . '%'), 'tone' => $ackPct !== null && $ackPct >= 80 ? 'success' : 'warning', 'hint' => 'Of acknowledged alerts.'],
                ['key' => 'res_pct',   'label' => 'Resolved ≤ 24h',     'value' => $resPct === null ? '—' : ($resPct . '%'), 'tone' => $resPct !== null && $resPct >= 80 ? 'success' : 'warning', 'hint' => 'Of closed alerts.'],
                ['key' => 'breached',  'label' => 'SLA Breached Now',   'value' => number_format((int) ($slaAgg->breached ?? 0)), 'tone' => (int) ($slaAgg->breached ?? 0) > 0 ? 'critical' : 'success', 'hint' => 'Open beyond risk-tier SLA.'],
            ],
            'extra' => ['open_overdue' => $openOverdue],
        ]);
    }

    public function chart(Request $request, string $chart): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        return match ($chart) {
            'ack_time_distribution' => $this->ok($this->chartAckDistribution($scope, $f, $from, $to)),
            'median_resolution_by_poe' => $this->ok($this->chartMedianByPoe($scope, $f, $from, $to)),
            default => $this->fail(404, 'Unknown chart key.'),
        };
    }

    public function chartCsv(Request $request, string $chart): StreamedResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $payload = match ($chart) {
            'ack_time_distribution'    => $this->chartAckDistribution($scope, $f, $from, $to),
            'median_resolution_by_poe' => $this->chartMedianByPoe($scope, $f, $from, $to),
            default => abort(404),
        };
        return $this->streamCsv("rpt-response-time__{$chart}", $payload['csv_headers'], $payload['csv_rows']);
    }

    public function records(Request $request): JsonResponse
    {
        $scope    = $this->ensureAccess($request);
        $f        = $this->readFilters($request);
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = 10;
        $q        = trim((string) $request->input('q', ''));
        $sort     = (string) $request->input('sort', 'created_at');
        $dir      = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $cat      = (string) $request->input('cat', 'all');
        [$from, $to] = $this->scope->resolveDateWindow($f);
        $now = Carbon::now();

        $base = function () use ($scope, $f, $from, $to) {
            $q = DB::table('alerts AS a')->whereNull('a.deleted_at')
                ->whereBetween('a.created_at', [$from, $to])
                ->leftJoin('users AS u', 'u.id', '=', 'a.acknowledged_by_user_id');
            $this->scopeAlerts($q, $scope, $f, 'a');
            return $q;
        };

        $qb = $base();
        if ($cat === 'breached')   $qb->whereIn('a.status', ['OPEN', 'ACKNOWLEDGED'])->whereRaw($this->slaBreachExpr(), [$now, $now, $now]);
        elseif ($cat === 'pending') $qb->whereIn('a.status', ['OPEN', 'ACKNOWLEDGED']);
        elseif ($cat === 'closed') $qb->where('a.status', 'CLOSED');

        if ($q !== '') {
            $qb->where(function ($w) use ($q) {
                $w->where('a.alert_code', 'like', '%' . $q . '%')
                  ->orWhere('a.alert_title', 'like', '%' . $q . '%')
                  ->orWhere('a.poe_code', 'like', '%' . $q . '%')
                  ->orWhere('u.full_name', 'like', '%' . $q . '%');
            });
        }

        $sortMap = [
            'created_at'  => 'a.created_at',
            'risk_level'  => 'a.risk_level',
            'status'      => 'a.status',
            'poe_code'    => 'a.poe_code',
            'ack_minutes' => DB::raw('TIMESTAMPDIFF(MINUTE, a.created_at, a.acknowledged_at)'),
            'res_minutes' => DB::raw('TIMESTAMPDIFF(MINUTE, a.created_at, a.closed_at)'),
        ];
        $sortCol = $sortMap[$sort] ?? 'a.created_at';
        $qb->orderBy($sortCol, $dir);

        // Total count.
        $totalQb = $base();
        if ($cat === 'breached')   $totalQb->whereIn('a.status', ['OPEN', 'ACKNOWLEDGED'])->whereRaw($this->slaBreachExpr(), [$now, $now, $now]);
        elseif ($cat === 'pending') $totalQb->whereIn('a.status', ['OPEN', 'ACKNOWLEDGED']);
        elseif ($cat === 'closed') $totalQb->where('a.status', 'CLOSED');
        if ($q !== '') {
            $totalQb->where(function ($w) use ($q) {
                $w->where('a.alert_code', 'like', '%' . $q . '%')
                  ->orWhere('a.alert_title', 'like', '%' . $q . '%')
                  ->orWhere('a.poe_code', 'like', '%' . $q . '%')
                  ->orWhere('u.full_name', 'like', '%' . $q . '%');
            });
        }
        $total      = (int) $totalQb->count('a.id');
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);

        $rows = $qb->forPage($page, $perPage)->get([
            'a.id', 'a.alert_code', 'a.alert_title', 'a.poe_code', 'a.risk_level', 'a.status',
            'a.created_at', 'a.acknowledged_at', 'a.closed_at',
            'u.full_name AS owner_name',
            DB::raw('TIMESTAMPDIFF(MINUTE, a.created_at, a.acknowledged_at) AS ack_minutes'),
            DB::raw('TIMESTAMPDIFF(MINUTE, a.created_at, a.closed_at) AS res_minutes'),
        ])->map(fn ($r) => [
            'id'              => (int) $r->id,
            'alert_code'      => $r->alert_code,
            'alert_title'     => $r->alert_title ?: ($r->alert_code ?: ('Alert #' . $r->id)),
            'poe_code'        => $r->poe_code,
            'risk_level'      => $r->risk_level,
            'status'          => $r->status,
            'owner_name'      => $r->owner_name,
            'created_at'      => $r->created_at,
            'acknowledged_at' => $r->acknowledged_at,
            'closed_at'       => $r->closed_at,
            'ack_minutes'     => $r->ack_minutes !== null ? (int) $r->ack_minutes : null,
            'res_minutes'     => $r->res_minutes !== null ? (int) $r->res_minutes : null,
            'sla_hours'       => match ((string) $r->risk_level) { 'CRITICAL' => 4, 'HIGH' => 24, default => 48 },
            'sla_breached'    => $r->closed_at === null && in_array($r->status, ['OPEN', 'ACKNOWLEDGED'], true)
                && Carbon::parse((string) $r->created_at)->diffInHours($now) > (match ((string) $r->risk_level) { 'CRITICAL' => 4, 'HIGH' => 24, default => 48 }),
        ]);

        // Category counts.
        $catRow = (clone $base())->selectRaw("
            COUNT(*) AS all_,
            SUM(a.status IN ('OPEN','ACKNOWLEDGED')) AS pending_,
            SUM(a.status='CLOSED') AS closed
        ")->first();
        $breachedCnt = (int) (clone $base())
            ->whereIn('a.status', ['OPEN', 'ACKNOWLEDGED'])
            ->whereRaw($this->slaBreachExpr(), [$now, $now, $now])
            ->count('a.id');

        return $this->ok([
            'rows' => $rows,
            'pagination' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to'   => min($page * $perPage, $total),
            ],
            'controls' => ['sort' => $sort, 'dir' => $dir, 'q' => $q, 'cat' => $cat],
            'category_counts' => [
                'all'      => (int) ($catRow->all_ ?? 0),
                'pending'  => (int) ($catRow->pending_ ?? 0),
                'closed'   => (int) ($catRow->closed ?? 0),
                'breached' => $breachedCnt,
            ],
        ]);
    }

    public function recordDetail(Request $request, int $id): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $a = DB::table('alerts')->where('id', $id)->first();
        abort_if(! $a, 404);

        $sec = $a->secondary_screening_id ? DB::table('secondary_screenings')->where('id', $a->secondary_screening_id)->first() : null;

        $owner = $a->acknowledged_by_user_id
            ? DB::table('users')->where('id', $a->acknowledged_by_user_id)->first(['id', 'full_name', 'username', 'role_key'])
            : null;

        $followups = DB::table('alert_followups')->where('alert_id', $id)
            ->orderBy('due_at')->limit(20)
            ->get(['action_label', 'status', 'due_at', 'completed_at', 'completed_by_user_id', 'blocks_closure']);

        $followupAgg = DB::table('alert_followups')->where('alert_id', $id)->selectRaw("
            COUNT(*) AS total,
            SUM(status='COMPLETED') AS completed,
            SUM(status='PENDING')   AS pending,
            SUM(status='BLOCKED')   AS blocked
        ")->first();

        $timeline = DB::table('alert_timeline_events')->where('alert_id', $id)
            ->orderByDesc('created_at')->limit(30)
            ->get(['event_code', 'event_category', 'actor_name', 'summary', 'severity', 'created_at']);

        $now    = Carbon::now();
        $sla    = match ((string) $a->risk_level) { 'CRITICAL' => 4, 'HIGH' => 24, default => 48 };
        $created = Carbon::parse((string) $a->created_at);
        $ackAt   = $a->acknowledged_at ? Carbon::parse((string) $a->acknowledged_at) : null;
        $closeAt = $a->closed_at ? Carbon::parse((string) $a->closed_at) : null;

        $minutesOpen = (int) round($closeAt ? $created->diffInMinutes($closeAt) : $created->diffInMinutes($now));
        $hoursOpen   = intdiv($minutesOpen, 60);
        $ackMinutes  = $ackAt   ? (int) round($created->diffInMinutes($ackAt))   : null;
        $resMinutes  = $closeAt ? (int) round($created->diffInMinutes($closeAt)) : null;

        return $this->ok([
            'alert' => [
                'id'              => $a->id,
                'code'            => $a->alert_code,
                'title'           => $a->alert_title,
                'risk_level'      => $a->risk_level,
                'status'          => $a->status,
                'poe_code'        => $a->poe_code,
                'created_at'      => $a->created_at,
                'acknowledged_at' => $a->acknowledged_at,
                'closed_at'       => $a->closed_at,
                'close_category'  => $a->close_category,
                'reopen_count'    => $a->reopen_count,
                'ack_minutes'     => $ackMinutes,
                'res_minutes'     => $resMinutes,
                'minutes_open'    => $minutesOpen,
                'hours_open'      => $hoursOpen,
                'sla_hours'       => $sla,
                'sla_breached'    => $hoursOpen > $sla,
            ],
            'owner' => $owner,
            'traveller' => $sec ? [
                'name'       => $sec->traveler_full_name,
                'gender'     => $sec->traveler_gender,
                'age'        => $sec->traveler_age_years,
                'risk_level' => $sec->risk_level,
                'triage'     => $sec->triage_category,
            ] : null,
            'followups' => $followups,
            'followup_agg' => $followupAgg,
            'timeline'  => $timeline,
        ]);
    }

    /* ───── chart builders ───── */

    private function chartAckDistribution(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        $q = DB::table('alerts')->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('acknowledged_at');
        $this->scopeAlerts($q, $scope, $f);

        $row = (clone $q)->selectRaw("
            SUM(TIMESTAMPDIFF(MINUTE, created_at, acknowledged_at) <= 30)                                                               AS b1,
            SUM(TIMESTAMPDIFF(MINUTE, created_at, acknowledged_at) >  30 AND TIMESTAMPDIFF(MINUTE, created_at, acknowledged_at) <= 60)  AS b2,
            SUM(TIMESTAMPDIFF(MINUTE, created_at, acknowledged_at) >  60 AND TIMESTAMPDIFF(MINUTE, created_at, acknowledged_at) <= 240) AS b3,
            SUM(TIMESTAMPDIFF(MINUTE, created_at, acknowledged_at) > 240 AND TIMESTAMPDIFF(HOUR,   created_at, acknowledged_at) <= 24)  AS b4,
            SUM(TIMESTAMPDIFF(HOUR,   created_at, acknowledged_at) >  24)                                                               AS b5
        ")->first();

        $buckets = [
            '≤ 30 min'   => (int) ($row->b1 ?? 0),
            '30–60 min'  => (int) ($row->b2 ?? 0),
            '1–4 h'      => (int) ($row->b3 ?? 0),
            '4–24 h'     => (int) ($row->b4 ?? 0),
            '> 24 h'     => (int) ($row->b5 ?? 0),
        ];

        return [
            'labels'   => array_keys($buckets),
            'datasets' => [['label' => 'Acknowledgements', 'data' => array_values($buckets)]],
            'csv_headers' => ['Bucket', 'Acknowledgements'],
            'csv_rows'    => array_map(null, array_keys($buckets), array_values($buckets)),
        ];
    }

    private function chartMedianByPoe(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        // MySQL has no MEDIAN. Use AVG of the (count-window) middle values via SUBSTRING_INDEX(GROUP_CONCAT...).
        // For O(N) stability and millions-of-rows safety, group by POE in SQL and pull only POE summary rows.
        $q = DB::table('alerts')->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('closed_at')
            ->selectRaw("poe_code,
                COUNT(*) AS n,
                AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) AS avg_hours,
                MIN(TIMESTAMPDIFF(HOUR, created_at, closed_at)) AS min_hours,
                MAX(TIMESTAMPDIFF(HOUR, created_at, closed_at)) AS max_hours,
                SUBSTRING_INDEX(SUBSTRING_INDEX(GROUP_CONCAT(TIMESTAMPDIFF(HOUR, created_at, closed_at) ORDER BY TIMESTAMPDIFF(HOUR, created_at, closed_at)), ',', CEIL(COUNT(*)/2)), ',', -1) AS median_str
            ")
            ->groupBy('poe_code')
            ->orderByDesc(DB::raw('AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at))'))
            ->limit(10);
        $this->scopeAlerts($q, $scope, $f);
        $rows = $q->get();

        $labels = $median = $avg = $csv = [];
        foreach ($rows as $r) {
            $name = $this->resolvePoeName((string) $r->poe_code);
            $labels[] = $name;
            $median[] = (float) $r->median_str;
            $avg[]    = round((float) $r->avg_hours, 2);
            $csv[]    = [$name, (int) $r->n, (float) $r->median_str, round((float) $r->avg_hours, 2), (int) $r->min_hours, (int) $r->max_hours];
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                ['label' => 'Median (h)', 'data' => $median],
                ['label' => 'Mean (h)',   'data' => $avg],
            ],
            'csv_headers' => ['Point of Entry', 'Closed Alerts', 'Median Hours', 'Mean Hours', 'Min Hours', 'Max Hours'],
            'csv_rows'    => $csv,
        ];
    }

    /* ───── helpers ───── */

    private function slaBreachExpr(): string
    {
        return "(
            (a.risk_level='CRITICAL' AND TIMESTAMPDIFF(HOUR, a.created_at, ?) > 4)
            OR (a.risk_level='HIGH'     AND TIMESTAMPDIFF(HOUR, a.created_at, ?) > 24)
            OR (a.risk_level NOT IN ('CRITICAL','HIGH') AND TIMESTAMPDIFF(HOUR, a.created_at, ?) > 48)
        )";
    }

    private function scopeAlerts($q, array $scope, array $f, string $alias = ''): void
    {
        $this->scope->apply($q, $scope, $alias ?: null);
        $col = $alias ? "{$alias}.poe_code" : 'poe_code';
        if (! empty($f['poe'])) $q->where($col, $f['poe']);
    }

    private function humaniseMinutes(?float $minutes): string
    {
        if ($minutes === null) return '—';
        if ($minutes < 60)   return round($minutes) . ' min';
        if ($minutes < 1440) return round($minutes / 60, 1) . ' h';
        return round($minutes / 1440, 1) . ' d';
    }

    private function resolvePoeName(string $code): string
    {
        static $cache = [];
        if (isset($cache[$code])) return $cache[$code];
        $row = DB::table('ref_poes')->where('poe_code', $code)
            ->orWhere('poe_name', 'like', '%' . $code . '%')->value('poe_name');
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
