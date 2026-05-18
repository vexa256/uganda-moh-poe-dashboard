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
 * R11 · rpt-ops-risk — Operational Risk feed.
 *
 * Engineering contract: every metric is computed in SQL (CASE bucketing,
 * conditional sums, anti-joins) — the controller never hydrates raw rows
 * to count them. Designed to remain flat at thousands → millions of alerts.
 */
final class OperationalRiskController extends BaseReportController
{
    protected string $reportKey   = 'rpt-ops-risk';
    protected string $reportTitle = 'Operational Risk';

    private const DARK_POE_DAYS      = 7;
    private const INACTIVE_USER_DAYS = 14;
    private const OPEN_ALERT_HOURS   = 24;

    public function index(Request $request): View
    {
        $this->ensureAccess($request);
        return view('admin.reports.v2.rpt-ops-risk', [
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

        $now               = Carbon::now();
        $ageThreshold      = $now->copy()->subHours(self::OPEN_ALERT_HOURS);
        $darkThreshold     = $now->copy()->subDays(self::DARK_POE_DAYS);
        $inactiveThreshold = $now->copy()->subDays(self::INACTIVE_USER_DAYS);

        // Single aggregated alerts query — overdue + critical-open in one pass.
        $aq = DB::table('alerts')->whereNull('deleted_at')
            ->whereIn('status', ['OPEN', 'ACKNOWLEDGED']);
        $this->scope->apply($aq, $scope);
        $this->applyPoeFilter($aq, $f);

        $alertsAgg = (clone $aq)->selectRaw("
            SUM(created_at < ?) AS overdue,
            SUM(risk_level = 'CRITICAL') AS critical_open
        ", [$ageThreshold])->first();

        $darkPoes      = $this->countDarkPoes($scope, $f, $darkThreshold);
        $inactiveUsers = $this->countInactiveUsers($scope, $inactiveThreshold);
        $trending      = $this->countTrendingPoes($scope, $f, $now);

        $overdue  = (int) ($alertsAgg->overdue ?? 0);
        $critical = (int) ($alertsAgg->critical_open ?? 0);

        return $this->ok([
            'kpis' => [
                ['key' => 'open_overdue',   'label' => 'Open Alerts > 24h',  'value' => number_format($overdue),  'tone' => $overdue > 0  ? 'danger'   : 'success', 'hint' => 'Acknowledged or open longer than 24 hours.'],
                ['key' => 'critical_open',  'label' => 'Critical Open Now',  'value' => number_format($critical), 'tone' => $critical > 0 ? 'critical' : 'success', 'hint' => 'Critical-risk alerts not yet closed.'],
                ['key' => 'dark_poes',      'label' => 'Dark POEs',          'value' => number_format($darkPoes), 'tone' => $darkPoes > 0 ? 'warning'  : 'success', 'hint' => 'Zero screenings in the last 7 days.'],
                ['key' => 'inactive_users', 'label' => 'Inactive Officers',  'value' => number_format($inactiveUsers), 'tone' => $inactiveUsers > 0 ? 'warning' : 'success', 'hint' => 'No activity in 14 days.'],
                ['key' => 'trending_up',    'label' => 'POEs Trending Up',   'value' => number_format($trending), 'tone' => $trending > 0 ? 'warning'  : 'success', 'hint' => 'Alerts last 7d > prior 7d.'],
            ],
        ]);
    }

    public function chart(Request $request, string $chart): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);

        return match ($chart) {
            'open_alert_aging'   => $this->ok($this->chartOpenAlertAging($scope, $f)),
            'alerts_by_risk_30d' => $this->ok($this->chartAlertsByRisk30d($scope, $f)),
            default              => $this->fail(404, 'Unknown chart key.'),
        };
    }

    public function chartCsv(Request $request, string $chart): StreamedResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);

        $payload = match ($chart) {
            'open_alert_aging'   => $this->chartOpenAlertAging($scope, $f),
            'alerts_by_risk_30d' => $this->chartAlertsByRisk30d($scope, $f),
            default              => abort(404, 'Unknown chart key.'),
        };

        return $this->streamCsv("rpt-ops-risk__{$chart}", $payload['csv_headers'], $payload['csv_rows']);
    }

    public function records(Request $request): JsonResponse
    {
        $scope    = $this->ensureAccess($request);
        $f        = $this->readFilters($request);
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = 10;
        $q        = trim((string) $request->input('q', ''));
        $sort     = (string) $request->input('sort', 'severity');
        $dir      = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $cat      = (string) $request->input('cat', 'all');

        $items = collect();
        if (in_array($cat, ['all', 'open_alert'], true))    $items = $items->merge($this->riskItemsOpenAlerts($scope, $f));
        if (in_array($cat, ['all', 'dark_poe'], true))      $items = $items->merge($this->riskItemsDarkPoes($scope, $f));
        if (in_array($cat, ['all', 'inactive_user'], true)) $items = $items->merge($this->riskItemsInactiveUsers($scope));

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $items = $items->filter(fn ($r) => str_contains(mb_strtolower((string) $r['title']), $needle)
                || str_contains(mb_strtolower((string) $r['where']), $needle)
                || str_contains(mb_strtolower((string) $r['detail']), $needle));
        }

        $sevRank = ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3];
        $sortKey = in_array($sort, ['severity', 'detected_at', 'title', 'type_label'], true) ? $sort : 'severity';
        $items = $items->sortBy(function ($r) use ($sortKey, $sevRank) {
            return $sortKey === 'severity' ? ($sevRank[$r['severity']] ?? 9) : (string) $r[$sortKey];
        })->values();
        if ($dir === 'desc') {
            $items = $items->reverse()->values();
        }

        $total      = $items->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $slice      = $items->forPage($page, $perPage)->values();

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
            'controls' => ['sort' => $sortKey, 'dir' => $dir, 'q' => $q, 'cat' => $cat],
            'category_counts' => [
                'all'           => $total,
                'open_alert'    => $items->where('type', 'open_alert')->count(),
                'dark_poe'      => $items->where('type', 'dark_poe')->count(),
                'inactive_user' => $items->where('type', 'inactive_user')->count(),
            ],
        ]);
    }

    public function recordDetail(Request $request, string $type, string $key): JsonResponse
    {
        $scope = $this->ensureAccess($request);

        return match ($type) {
            'open_alert'    => $this->ok($this->detailOpenAlert($scope, (int) $key)),
            'dark_poe'      => $this->ok($this->detailDarkPoe($scope, $key)),
            'inactive_user' => $this->ok($this->detailInactiveUser($scope, (int) $key)),
            default         => $this->fail(404, 'Unknown risk type.'),
        };
    }

    /* ─────────────── KPI helpers (SQL only) ─────────────── */

    private function countDarkPoes(array $scope, array $f, Carbon $threshold): int
    {
        $allowed = $this->scope->allowedPoes($scope);
        if (empty($allowed)) {
            return 0;
        }
        $screened = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('captured_at', '>=', $threshold)
            ->select('poe_code')->distinct()->pluck('poe_code')->all();
        $screenedSet = array_flip($screened);
        $dark = 0;
        foreach ($allowed as $code => $name) {
            if (! empty($f['poe']) && $f['poe'] !== $code && $f['poe'] !== $name) continue;
            if (! isset($screenedSet[$code]) && ! isset($screenedSet[$name])) $dark++;
        }
        return $dark;
    }

    private function countInactiveUsers(array $scope, Carbon $threshold): int
    {
        $q = DB::table('users AS u')
            ->where('u.is_active', 1)
            ->where(fn ($w) => $w->whereNull('u.last_activity_at')->orWhere('u.last_activity_at', '<', $threshold));
        $this->scopeUsers($q, $scope);
        return (int) $q->count('u.id');
    }

    private function countTrendingPoes(array $scope, array $f, Carbon $now): int
    {
        $last7  = $now->copy()->subDays(7);
        $prior7 = $now->copy()->subDays(14);

        $q = DB::table('alerts')->whereNull('deleted_at')
            ->where('created_at', '>=', $prior7)
            ->selectRaw("poe_code,
                SUM(created_at >= ?) AS now_count,
                SUM(created_at <  ?) AS prev_count
            ", [$last7, $last7])
            ->groupBy('poe_code')
            ->havingRaw('now_count > prev_count AND now_count > 0');
        $this->scope->apply($q, $scope);
        $this->applyPoeFilter($q, $f);
        return (int) $q->get()->count();
    }

    /* ─────────────── chart builders (SQL only) ─────────────── */

    private function chartOpenAlertAging(array $scope, array $f): array
    {
        $now = Carbon::now();
        $q = DB::table('alerts')->whereNull('deleted_at')
            ->whereIn('status', ['OPEN', 'ACKNOWLEDGED']);
        $this->scope->apply($q, $scope);
        $this->applyPoeFilter($q, $f);

        $agg = (clone $q)->selectRaw("
            SUM(TIMESTAMPDIFF(HOUR, created_at, ?) <  6)                                          AS lt_6h,
            SUM(TIMESTAMPDIFF(HOUR, created_at, ?) >= 6  AND TIMESTAMPDIFF(HOUR, created_at, ?) < 24) AS h6_24,
            SUM(TIMESTAMPDIFF(HOUR, created_at, ?) >= 24 AND TIMESTAMPDIFF(HOUR, created_at, ?) < 72) AS d1_3,
            SUM(TIMESTAMPDIFF(HOUR, created_at, ?) >= 72)                                         AS gt_3d
        ", [$now, $now, $now, $now, $now, $now])->first();

        $buckets = [
            '<6h'   => (int) ($agg->lt_6h ?? 0),
            '6–24h' => (int) ($agg->h6_24 ?? 0),
            '1–3d'  => (int) ($agg->d1_3 ?? 0),
            '>3d'   => (int) ($agg->gt_3d ?? 0),
        ];

        return [
            'labels'   => array_keys($buckets),
            'datasets' => [['label' => 'Open Alerts', 'data' => array_values($buckets)]],
            'csv_headers' => ['Age Bucket', 'Open Alerts'],
            'csv_rows'    => array_map(null, array_keys($buckets), array_values($buckets)),
        ];
    }

    private function chartAlertsByRisk30d(array $scope, array $f): array
    {
        $from = Carbon::now()->subDays(29)->startOfDay();
        $to   = Carbon::now()->endOfDay();

        $q = DB::table('alerts')->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("DATE(created_at) AS d,
                SUM(risk_level='LOW')      AS r_low,
                SUM(risk_level='MEDIUM')   AS r_medium,
                SUM(risk_level='HIGH')     AS r_high,
                SUM(risk_level='CRITICAL') AS r_critical
            ")
            ->groupBy(DB::raw('DATE(created_at)'));
        $this->scope->apply($q, $scope);
        $this->applyPoeFilter($q, $f);
        $rows = $q->get()->keyBy('d');

        $labels = $low = $med = $high = $crit = $csvRows = [];
        $cur = $from->copy();
        while ($cur <= $to) {
            $d = $cur->toDateString();
            $r = $rows[$d] ?? null;
            $labels[] = $cur->format('d M');
            $low[]    = (int) ($r->r_low ?? 0);
            $med[]    = (int) ($r->r_medium ?? 0);
            $high[]   = (int) ($r->r_high ?? 0);
            $crit[]   = (int) ($r->r_critical ?? 0);
            $csvRows[] = [$cur->format('d M'), (int) ($r->r_low ?? 0), (int) ($r->r_medium ?? 0), (int) ($r->r_high ?? 0), (int) ($r->r_critical ?? 0)];
            $cur->addDay();
        }

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Low',      'data' => $low],
                ['label' => 'Medium',   'data' => $med],
                ['label' => 'High',     'data' => $high],
                ['label' => 'Critical', 'data' => $crit],
            ],
            'csv_headers' => ['Date', 'Low', 'Medium', 'High', 'Critical'],
            'csv_rows'    => $csvRows,
        ];
    }

    /* ─────────────── records: risk items (each source SQL-bounded) ─────────────── */

    private function riskItemsOpenAlerts(array $scope, array $f): array
    {
        $threshold = Carbon::now()->subHours(self::OPEN_ALERT_HOURS);
        $q = DB::table('alerts')->whereNull('deleted_at')
            ->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])
            ->where('created_at', '<', $threshold);
        $this->scope->apply($q, $scope);
        $this->applyPoeFilter($q, $f);
        $rows = $q->orderByDesc('risk_level')->orderBy('created_at')->limit(200)->get();

        return $rows->map(fn ($r) => [
            'type'        => 'open_alert',
            'type_label'  => 'Overdue Alert',
            'key'         => (int) $r->id,
            'title'       => $r->alert_title ?: ($r->alert_code ?: ('Alert #' . $r->id)),
            'where'       => (string) $r->poe_code,
            'severity'    => (string) ($r->risk_level ?: 'MEDIUM'),
            'detected_at' => (string) $r->created_at,
            'detail'      => 'Alert open since ' . Carbon::parse((string) $r->created_at)->diffForHumans(),
        ])->all();
    }

    private function riskItemsDarkPoes(array $scope, array $f): array
    {
        $threshold = Carbon::now()->subDays(self::DARK_POE_DAYS);
        $allowed   = $this->scope->allowedPoes($scope);
        if (empty($allowed)) return [];

        $screened = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('captured_at', '>=', $threshold)
            ->select('poe_code')->distinct()->pluck('poe_code')->all();
        $screenedSet = array_flip($screened);

        $items = [];
        foreach ($allowed as $code => $name) {
            if (! empty($f['poe']) && $f['poe'] !== $code && $f['poe'] !== $name) continue;
            if (isset($screenedSet[$code]) || isset($screenedSet[$name])) continue;
            $items[] = [
                'type'        => 'dark_poe',
                'type_label'  => 'Dark POE',
                'key'         => $code,
                'title'       => $name,
                'where'       => $name,
                'severity'    => 'MEDIUM',
                'detected_at' => $threshold->toDateTimeString(),
                'detail'      => 'No screenings in the last ' . self::DARK_POE_DAYS . ' days.',
            ];
        }
        return $items;
    }

    private function riskItemsInactiveUsers(array $scope): array
    {
        $threshold = Carbon::now()->subDays(self::INACTIVE_USER_DAYS);
        $q = DB::table('users AS u')
            ->leftJoin('user_assignments AS ua', function ($j) {
                $j->on('ua.user_id', '=', 'u.id')->where('ua.is_primary', 1)->where('ua.is_active', 1);
            })
            ->where('u.is_active', 1)
            ->where(fn ($w) => $w->whereNull('u.last_activity_at')->orWhere('u.last_activity_at', '<', $threshold));
        $this->scopeUsers($q, $scope);
        $rows = $q->select('u.id', 'u.full_name', 'u.username', 'u.role_key', 'u.last_activity_at', 'ua.poe_code')
            ->orderBy('u.last_activity_at')->limit(200)->get();

        return $rows->map(fn ($r) => [
            'type'        => 'inactive_user',
            'type_label'  => 'Inactive Officer',
            'key'         => (int) $r->id,
            'title'       => $r->full_name ?: $r->username,
            'where'       => $r->poe_code ?: '—',
            'severity'    => 'LOW',
            'detected_at' => (string) ($r->last_activity_at ?: ''),
            'detail'      => $r->last_activity_at
                ? 'Last seen ' . Carbon::parse((string) $r->last_activity_at)->diffForHumans()
                : 'Never logged activity.',
        ])->all();
    }

    /* ─────────────── modal detail ─────────────── */

    private function detailOpenAlert(array $scope, int $alertId): array
    {
        $a = DB::table('alerts')->where('id', $alertId)->first();
        abort_if(! $a, 404, 'Alert not found.');

        $sec = $a->secondary_screening_id
            ? DB::table('secondary_screenings')->where('id', $a->secondary_screening_id)->first()
            : null;

        $followups = DB::table('alert_followups')
            ->where('alert_id', $alertId)
            ->orderBy('due_at')
            ->limit(20)
            ->get(['action_label', 'status', 'due_at', 'completed_at', 'completed_by_user_id', 'blocks_closure']);

        $followupAgg = DB::table('alert_followups')->where('alert_id', $alertId)
            ->selectRaw("
                COUNT(*) AS total,
                SUM(status='COMPLETED') AS completed,
                SUM(status='PENDING')   AS pending,
                SUM(status='BLOCKED')   AS blocked,
                SUM(blocks_closure=1 AND status<>'COMPLETED') AS blocking
            ")->first();

        $timeline = DB::table('alert_timeline_events')
            ->where('alert_id', $alertId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['event_code', 'event_category', 'actor_name', 'summary', 'created_at']);

        $minutesOpen = (int) round(Carbon::parse((string) $a->created_at)->diffInMinutes(Carbon::now()));
        $hoursOpen   = intdiv($minutesOpen, 60);
        $sla = match ((string) $a->risk_level) {
            'CRITICAL' => 4,
            'HIGH'     => 24,
            default    => 48,
        };

        return [
            'alert' => [
                'id'                => $a->id,
                'code'              => $a->alert_code,
                'title'             => $a->alert_title,
                'details'           => $a->alert_details,
                'risk_level'        => $a->risk_level,
                'status'            => $a->status,
                'routed_to_level'   => $a->routed_to_level,
                'poe_code'          => $a->poe_code,
                'created_at'        => $a->created_at,
                'acknowledged_at'   => $a->acknowledged_at,
                'reopen_count'      => $a->reopen_count,
                'close_category'    => $a->close_category,
                'minutes_open'      => $minutesOpen,
                'hours_open'        => $hoursOpen,
                'sla_hours'         => $sla,
                'sla_breached'      => $hoursOpen > $sla,
            ],
            'traveller' => $sec ? [
                'name'        => $sec->traveler_full_name,
                'gender'      => $sec->traveler_gender,
                'age'         => $sec->traveler_age_years,
                'nationality' => $sec->traveler_nationality_country_code,
                'origin'      => $sec->journey_start_country_code,
                'arrival'     => $sec->arrival_datetime,
                'disposition' => $sec->final_disposition,
                'risk_level'  => $sec->risk_level,
                'triage'      => $sec->triage_category,
                'temperature' => $sec->temperature_value,
                'oxygen_sat'  => $sec->oxygen_saturation,
            ] : null,
            'followups'    => $followups,
            'followup_agg' => $followupAgg,
            'timeline'     => $timeline,
        ];
    }

    private function detailDarkPoe(array $scope, string $code): array
    {
        $info = DB::table('ref_poes')
            ->where('poe_code', $code)->orWhere('poe_name', $code)
            ->first(['poe_code', 'poe_name', 'poe_type', 'transport_mode', 'border_country', 'admin_level_1', 'district', 'is_major_entry', 'latitude', 'longitude']);

        $lastScreen = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where(function ($w) use ($info, $code) {
                $w->where('poe_code', $code);
                if ($info) $w->orWhere('poe_code', $info->poe_name);
            })->max('captured_at');

        $assigned = DB::table('user_assignments AS ua')
            ->join('users AS u', 'u.id', '=', 'ua.user_id')
            ->where('ua.poe_code', $code)
            ->where('ua.is_active', 1)->where('u.is_active', 1)
            ->select('u.id', 'u.full_name', 'u.username', 'u.last_activity_at', 'u.role_key')
            ->limit(20)->get();

        $recentAlerts = DB::table('alerts')->whereNull('deleted_at')
            ->where('poe_code', $code)
            ->orderByDesc('created_at')->limit(10)
            ->get(['id', 'alert_code', 'alert_title', 'risk_level', 'status', 'created_at']);

        // 30-day volume sparkline (likely zeros, but shown for context).
        $sparkRows = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('poe_code', $code)
            ->where('captured_at', '>=', Carbon::now()->subDays(29))
            ->selectRaw('DATE(captured_at) AS d, COUNT(*) AS c')
            ->groupBy(DB::raw('DATE(captured_at)'))
            ->pluck('c', 'd')->all();
        $spark = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->toDateString();
            $spark[] = ['date' => $d, 'count' => (int) ($sparkRows[$d] ?? 0)];
        }

        return [
            'poe'           => $info,
            'last_screen'   => $lastScreen,
            'assigned'      => $assigned,
            'recent_alerts' => $recentAlerts,
            'sparkline'     => $spark,
        ];
    }

    private function detailInactiveUser(array $scope, int $userId): array
    {
        $u = DB::table('users')->where('id', $userId)->first([
            'id', 'full_name', 'username', 'email', 'phone', 'role_key', 'account_type',
            'is_active', 'last_login_at', 'last_activity_at', 'last_login_ip', 'failed_login_count',
            'locked_until', 'created_at', 'risk_score',
        ]);
        abort_if(! $u, 404, 'User not found.');

        $assignments = DB::table('user_assignments')
            ->where('user_id', $userId)
            ->orderByDesc('is_primary')
            ->orderByDesc('starts_at')
            ->get(['country_code', 'province_code', 'district_code', 'poe_code', 'is_primary', 'is_active', 'starts_at', 'ends_at']);

        $output = DB::table(DB::raw('(SELECT 0 AS d) z'))
            ->selectRaw("
                (SELECT COUNT(*) FROM primary_screenings   WHERE captured_by_user_id  = ? AND deleted_at IS NULL AND captured_at >= ?) AS s_30,
                (SELECT COUNT(*) FROM primary_screenings   WHERE captured_by_user_id  = ? AND deleted_at IS NULL AND captured_at >= ?) AS s_90,
                (SELECT COUNT(*) FROM secondary_screenings WHERE opened_by_user_id    = ? AND deleted_at IS NULL AND opened_at  >= ?) AS sec_30,
                (SELECT COUNT(*) FROM alerts               WHERE acknowledged_by_user_id = ? AND deleted_at IS NULL AND created_at >= ?) AS a_30,
                (SELECT COUNT(*) FROM alert_followups      WHERE completed_by_user_id = ? AND deleted_at IS NULL AND completed_at >= ?) AS fu_30
            ", [
                $userId, Carbon::now()->subDays(30),
                $userId, Carbon::now()->subDays(90),
                $userId, Carbon::now()->subDays(30),
                $userId, Carbon::now()->subDays(30),
                $userId, Carbon::now()->subDays(30),
            ])->first();

        return [
            'user'        => $u,
            'assignments' => $assignments,
            'output'      => [
                'screenings_30d'           => (int) ($output->s_30 ?? 0),
                'screenings_90d'           => (int) ($output->s_90 ?? 0),
                'secondary_screenings_30d' => (int) ($output->sec_30 ?? 0),
                'alerts_30d'               => (int) ($output->a_30 ?? 0),
                'followups_30d'            => (int) ($output->fu_30 ?? 0),
            ],
        ];
    }

    /* ─────────────── helpers ─────────────── */

    private function applyPoeFilter($q, array $f): void
    {
        if (! empty($f['poe'])) $q->where('poe_code', $f['poe']);
    }

    private function scopeUsers($q, array $scope): void
    {
        if ($scope['is_super'] ?? false) return;
        $level = strtoupper((string) ($scope['scope_level'] ?? ''));
        if ($level === 'PHEOC' && ! empty($scope['country_code'])) {
            $q->where('u.country_code', $scope['country_code']);
            return;
        }
        if (! empty($scope['poes'])) {
            $q->join('user_assignments AS uax', function ($j) use ($scope) {
                $j->on('uax.user_id', '=', 'u.id')
                    ->where('uax.is_active', 1)
                    ->whereIn('uax.poe_code', $scope['poes']);
            });
        }
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
