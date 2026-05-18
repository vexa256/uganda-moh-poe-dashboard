<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports\V2;

use App\Http\Controllers\Admin\Reports\BaseReportController;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * R8 · rpt-user-activity — Officer Activity Index.
 *
 * Executive question: "Who is actually doing the work, who is silently
 * inactive, and is the workforce healthy?"
 *
 * PII rule: `ReportAccess::canSeeNamedResponders($scope)` returns true ONLY
 * for `is_super`. Every non-super viewer sees `role_key · scope_label`,
 * never `users.full_name`. Auth events are aggregated rather than listed
 * for non-super to prevent IP/UA-based re-identification.
 *
 * Engineering contract: aggregation-only SQL, no JOIN ref_*, all reads
 * scope-applied via `whereExists(user_assignments scope-applied)` because
 * `users` does not carry the standard country/province/district/poe scope
 * columns directly.
 */
final class UserActivityController extends BaseReportController
{
    protected string $reportKey   = 'rpt-user-activity';
    protected string $reportTitle = 'User Activity';

    private const RECORDS_LIMIT      = 10;
    private const DORMANT_DAYS       = 14;
    private const ACTIVE_WINDOW_DAYS = 30;
    private const TOP_LIMIT          = 10;
    private const FAILED_LOGIN_THRESHOLD = 5;

    /* ──────────────────────────────────────────────────────────────────
     * 1.  index — Blade shell.
     * ────────────────────────────────────────────────────────────────── */
    public function index(Request $request): View
    {
        $this->ensureAccess($request);
        return view('admin.reports.v2.rpt-user-activity', [
            'reportKey'   => $this->reportKey,
            'reportTitle' => $this->reportTitle,
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 2.  meta — filter dropdowns + scope + role list.
     * ────────────────────────────────────────────────────────────────── */
    public function meta(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);

        // Roles seen on users matching scope (small N).
        $rq = DB::table('users AS u')
            ->whereNotNull('u.role_key')
            ->select('u.role_key')
            ->distinct();
        $this->applyUserScope($rq, $scope);
        $roles = $rq->pluck('role_key')->filter()->values()->all();

        return $this->ok([
            'poes'      => $this->scope->allowedPoes($scope),
            'roles'     => $roles,
            'scope'     => [
                'label'    => $scope['label'] ?? '—',
                'level'    => $scope['scope_level'] ?? 'SELF',
                'is_super' => (bool) ($scope['is_super'] ?? false),
            ],
            'statuses'  => ['all', 'active', 'dormant', 'locked', 'flagged'],
            'can_name'  => $this->access->canSeeNamedResponders($scope),
            'data_notes' => $this->dataNotes(),
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 3.  kpis — 5 tiles.
     * ────────────────────────────────────────────────────────────────── */
    public function kpis(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        // Active officers: users in scope with last_activity_at >= now-30d.
        $aThreshold = Carbon::now()->subDays(self::ACTIVE_WINDOW_DAYS);
        $aq = DB::table('users AS u')
            ->where('u.is_active', 1)
            ->where('u.last_activity_at', '>=', $aThreshold);
        $this->applyUserScope($aq, $scope);
        $activeOfficers = (int) $aq->count('u.id');

        // Dormant officers: in scope, no activity in last 14 days (or never).
        $dThreshold = Carbon::now()->subDays(self::DORMANT_DAYS);
        $dq = DB::table('users AS u')
            ->where('u.is_active', 1)
            ->where(fn ($w) => $w->where('u.last_activity_at', '<', $dThreshold)
                                 ->orWhereNull('u.last_activity_at'));
        $this->applyUserScope($dq, $scope);
        $dormantOfficers = (int) $dq->count('u.id');

        // Median output per active officer in window — pull aggregated per-user
        // count via single grouped query, then median in PHP (bounded by N≈users).
        $perUser = $this->perUserOutputCounts($scope, $from, $to);
        $values = array_values($perUser);
        sort($values, SORT_NUMERIC);
        $median = $this->medianOf($values);

        // Training expired count (rollup of user_training_records.status='EXPIRED').
        $expired = 0;
        if (Schema::hasTable('user_training_records')) {
            $tq = DB::table('user_training_records AS t')->whereNull('t.deleted_at')
                ->where('t.status', 'EXPIRED')
                ->whereExists(function ($q) use ($scope) {
                    $q->select(DB::raw(1))->from('users AS u')
                        ->whereColumn('u.id', 't.user_id');
                    $this->applyUserScope($q, $scope);
                });
            $expired = (int) $tq->count('t.id');
        }

        // Login anomalies: locked OR (failed_login_count > threshold).
        $lq = DB::table('users AS u')
            ->where('u.is_active', 1)
            ->where(function ($w) {
                $w->where('u.failed_login_count', '>', self::FAILED_LOGIN_THRESHOLD)
                  ->orWhere('u.locked_until', '>', Carbon::now());
            });
        $this->applyUserScope($lq, $scope);
        $loginAnomalies = (int) $lq->count('u.id');

        return $this->ok([
            'kpis' => [
                ['key' => 'active_officers',  'label' => 'Active Officers',         'value' => number_format($activeOfficers),  'tone' => 'brand', 'hint' => 'Last activity ≤ ' . self::ACTIVE_WINDOW_DAYS . ' days.'],
                ['key' => 'dormant_officers', 'label' => 'Dormant (≥ ' . self::DORMANT_DAYS . 'd)', 'value' => number_format($dormantOfficers), 'tone' => $dormantOfficers > 0 ? 'warning' : 'success', 'hint' => 'No activity in last ' . self::DORMANT_DAYS . ' days.'],
                ['key' => 'median_output',    'label' => 'Median Output / Officer', 'value' => $median === null ? '—' : number_format($median), 'tone' => 'info', 'hint' => 'Screenings + alerts handled, per active officer.'],
                ['key' => 'training_expired', 'label' => 'Training Expired',        'value' => number_format($expired),         'tone' => $expired > 0 ? 'danger' : 'success', 'hint' => 'Records with status EXPIRED.'],
                ['key' => 'login_anomalies',  'label' => 'Login Anomalies',         'value' => number_format($loginAnomalies),  'tone' => $loginAnomalies > 0 ? 'critical' : 'success', 'hint' => 'Locked or > ' . self::FAILED_LOGIN_THRESHOLD . ' failed logins.'],
            ],
            'window' => [$from->toDateString(), $to->toDateString()],
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 4.  chart / chart CSV
     * ────────────────────────────────────────────────────────────────── */
    public function chart(Request $request, string $chart): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        return match ($chart) {
            'output_by_role'   => $this->ok($this->chartOutputByRole($scope, $f)),
            'activity_heatmap' => $this->ok($this->chartActivityHeatmap($scope, $f)),
            default            => $this->fail(404, 'Unknown chart key.'),
        };
    }

    public function chartCsv(Request $request, string $chart): StreamedResponse|Response
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        $payload = match ($chart) {
            'output_by_role'   => $this->chartOutputByRole($scope, $f),
            'activity_heatmap' => $this->chartActivityHeatmap($scope, $f),
            default            => abort(404, 'Unknown chart key.'),
        };
        return $this->writer->send(
            $this->reportKey,
            'CSV',
            $payload['csv_headers'],
            $payload['csv_rows'],
            $f,
            (int) ($request->user()->id ?? 0),
            $this->reportTitle . ' · ' . $chart,
        );
    }

    /* ──────────────────────────────────────────────────────────────────
     * 5.  records — 10-row paginated Officer Activity Index.
     * ────────────────────────────────────────────────────────────────── */
    public function records(Request $request): JsonResponse
    {
        $scope    = $this->ensureAccess($request);
        $f        = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $page    = max(1, (int) $request->input('page', 1));
        $perPage = self::RECORDS_LIMIT;
        $q       = trim((string) $request->input('q', ''));
        $sort    = (string) $request->input('sort', 'screenings');
        $dir     = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $status  = (string) $request->input('status', 'all');
        $roleKey = trim((string) $request->input('role_key', ''));

        $canName = $this->access->canSeeNamedResponders($scope);

        // Base scoped users query (id pull only — bounded by scope).
        $base = DB::table('users AS u');
        $this->applyUserScope($base, $scope);

        if ($q !== '') {
            $needle = '%' . $q . '%';
            $base->where(function ($w) use ($needle) {
                $w->where('u.full_name', 'like', $needle)
                  ->orWhere('u.username', 'like', $needle)
                  ->orWhere('u.role_key', 'like', $needle);
            });
        }
        if ($roleKey !== '') { $base->where('u.role_key', $roleKey); }

        // Status filter pre-aggregation.
        $now      = Carbon::now();
        $dThresh  = $now->copy()->subDays(self::DORMANT_DAYS);
        if ($status === 'active') {
            $base->where('u.last_activity_at', '>=', $dThresh)->where('u.is_active', 1);
        } elseif ($status === 'dormant') {
            $base->where('u.is_active', 1)
                 ->where(fn ($w) => $w->where('u.last_activity_at', '<', $dThresh)
                                       ->orWhereNull('u.last_activity_at'));
        } elseif ($status === 'locked') {
            $base->where('u.locked_until', '>', $now);
        } elseif ($status === 'flagged') {
            $base->where('u.failed_login_count', '>', self::FAILED_LOGIN_THRESHOLD);
        }

        // Pull the universe of in-scope (and search-filtered) users once.
        // For typical Zambia workforce scale (≤ a few thousand officers), this
        // is bounded; sort + paginate happens in PHP after enrichment.
        $allUsers = (clone $base)
            ->select([
                'u.id', 'u.full_name', 'u.username', 'u.role_key', 'u.account_type',
                'u.is_active', 'u.last_activity_at', 'u.last_login_at',
                'u.failed_login_count', 'u.locked_until', 'u.risk_score', 'u.country_code',
            ])
            ->get();
        $allIds = $allUsers->pluck('id')->map(fn ($v) => (int) $v)->all();

        // Aggregate maps over the filtered universe.
        $screensMap   = $this->countScreeningsByUser($allIds, $from, $to);
        $alertsMap    = $this->countAlertsByUser($allIds, $from, $to);
        $followupsMap = $this->countFollowupsByUser($allIds, $from, $to);
        $trainingMap  = $this->trainingStatusByUser($allIds);

        $rows = [];
        foreach ($allUsers as $u) {
            $uid = (int) $u->id;
            $accountStatus = $this->computeAccountStatus($u, $now, $dThresh);
            $rows[] = [
                'id'             => $uid,
                'name'           => $canName
                    ? ((string) ($u->full_name ?: $u->username ?: ('User #' . $uid)))
                    : $this->maskedDisplay($u, $scope),
                'role_key'       => (string) ($u->role_key ?? ''),
                'account_status' => $accountStatus,
                'screenings'     => (int) ($screensMap[$uid] ?? 0),
                // 'alerts_' suffix to dodge MySQL reserved-word collisions when
                // referenced by the JS sortBy expression.
                'alerts_'        => (int) ($alertsMap[$uid] ?? 0),
                'followups'      => (int) ($followupsMap[$uid] ?? 0),
                'training'       => (string) ($trainingMap[$uid] ?? 'NONE'),
                'last_act'       => $u->last_activity_at,
                'is_locked'      => $u->locked_until && Carbon::parse((string) $u->locked_until)->isFuture(),
                'risk_score'     => (int) ($u->risk_score ?? 0),
            ];
        }

        // Sort.
        $sortable = ['name', 'role_key', 'account_status', 'screenings', 'alerts_', 'followups', 'training', 'last_act'];
        $sortKey  = in_array($sort, $sortable, true) ? $sort : 'screenings';
        usort($rows, function ($a, $b) use ($sortKey, $dir) {
            $av = $a[$sortKey] ?? null; $bv = $b[$sortKey] ?? null;
            if ($av === null && $bv === null) return 0;
            if ($av === null) return 1;
            if ($bv === null) return -1;
            $cmp = is_numeric($av) && is_numeric($bv) ? ($av <=> $bv) : strcmp((string) $av, (string) $bv);
            return $dir === 'asc' ? $cmp : -$cmp;
        });

        $totalRows  = count($rows);   // matches $total — both come from $base.
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page       = min($page, $totalPages);
        $slice      = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return $this->ok([
            'rows' => $slice,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $totalRows,
                'total_pages' => $totalPages,
                'from'        => $totalRows === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to'          => min($page * $perPage, $totalRows),
            ],
            'controls' => [
                'sort' => $sortKey, 'dir' => $dir, 'q' => $q,
                'status' => $status, 'role_key' => $roleKey,
            ],
            'pii'      => ['can_name' => $canName],
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 6.  recordDetail — drill-down by user_id.
     * ────────────────────────────────────────────────────────────────── */
    public function recordDetail(Request $request, string $key): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $userId = (int) $key;
        if ($userId <= 0) { return $this->fail(404, 'User not found.'); }

        // Verify scope: the user must intersect the descriptor.
        $scopedUser = DB::table('users AS u')
            ->where('u.id', $userId);
        $this->applyUserScope($scopedUser, $scope);
        $u = $scopedUser->first();
        if (! $u) { return $this->fail(404, 'User not in scope or not found.'); }

        $canName = $this->access->canSeeNamedResponders($scope);
        $now     = Carbon::now();
        $dThresh = $now->copy()->subDays(self::DORMANT_DAYS);

        // Profile (with PII masking).
        $profile = [
            'id'                  => (int) $u->id,
            'name'                => $canName ? ($u->full_name ?: $u->username) : $this->maskedDisplay($u, $scope),
            'username'            => $canName ? $u->username : $this->maskedToken((string) ($u->username ?? '')),
            'role_key'            => $u->role_key,
            'account_type'        => $u->account_type,
            'country_code'        => $u->country_code ?? null,
            'is_active'           => (bool) ($u->is_active ?? 0),
            'last_login_at'       => $u->last_login_at ?? null,
            'last_activity_at'    => $u->last_activity_at ?? null,
            'locked_until'        => $u->locked_until ?? null,
            'failed_login_count'  => (int) ($u->failed_login_count ?? 0),
            'risk_score'          => (int) ($u->risk_score ?? 0),
            'created_at'          => $u->created_at ?? null,
            'account_status'      => $this->computeAccountStatus($u, $now, $dThresh),
        ];
        $piiRow = $this->access->maskPii([
            'phone_number'      => $u->phone ?? null,
            'email'             => $u->email ?? null,
        ], $scope);
        $profile['phone'] = $piiRow['phone_number'];
        $profile['email'] = $piiRow['email'];

        // Assignments.
        $assignments = DB::table('user_assignments')
            ->where('user_id', $userId)
            ->orderByDesc('is_active')
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get([
                'id', 'country_code', 'province_code', 'pheoc_code', 'district_code',
                'poe_code', 'is_primary', 'is_active', 'starts_at', 'ends_at',
            ]);

        // Training records (latest 20).
        $training = collect();
        if (Schema::hasTable('user_training_records')) {
            $training = DB::table('user_training_records')->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->orderByDesc('completed_on')->limit(20)
                ->get(['training_code', 'training_title', 'competency_domain', 'provider', 'completed_on', 'expires_on', 'status', 'score']);
        }

        // Anomaly flags (open).
        $flags = collect();
        if (Schema::hasTable('user_anomaly_flags')) {
            $flags = DB::table('user_anomaly_flags')->where('user_id', $userId)
                ->whereNull('cleared_at')
                ->orderByDesc('last_seen_at')->limit(20)
                ->get(['flag_code', 'severity', 'evidence_json', 'first_seen_at', 'last_seen_at']);
        }

        // Output sparkline (30 days). Two grouped queries → merged in PHP.
        $w30from = $now->copy()->subDays(29)->startOfDay();
        $w30to   = $now->copy()->endOfDay();
        $screensDaily = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('captured_by_user_id', $userId)
            ->whereBetween('captured_at', [$w30from, $w30to])
            ->selectRaw('DATE(captured_at) AS d, COUNT(*) AS c')
            ->groupBy(DB::raw('DATE(captured_at)'))
            ->pluck('c', 'd')->all();
        $alertsDaily = DB::table('alerts')->whereNull('deleted_at')
            ->where('acknowledged_by_user_id', $userId)
            ->whereBetween('acknowledged_at', [$w30from, $w30to])
            ->selectRaw('DATE(acknowledged_at) AS d, COUNT(*) AS c')
            ->groupBy(DB::raw('DATE(acknowledged_at)'))
            ->pluck('c', 'd')->all();
        $spark = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $now->copy()->subDays($i)->toDateString();
            $spark[] = [
                'date'        => $d,
                'screenings'  => (int) ($screensDaily[$d] ?? 0),
                'alerts'      => (int) ($alertsDaily[$d] ?? 0),
            ];
        }

        // KPI strip.
        $screen30 = (int) DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('captured_by_user_id', $userId)
            ->whereBetween('captured_at', [$w30from, $w30to])
            ->count();
        $alert30 = (int) DB::table('alerts')->whereNull('deleted_at')
            ->where('acknowledged_by_user_id', $userId)
            ->whereBetween('acknowledged_at', [$w30from, $w30to])
            ->count();
        $followup30 = 0;
        if (Schema::hasTable('alert_followups')) {
            $followup30 = (int) DB::table('alert_followups')
                ->where('completed_by_user_id', $userId)
                ->where('status', 'COMPLETED')
                ->whereBetween('completed_at', [$w30from, $w30to])
                ->count();
        }

        // Auth events vs aggregated summary based on PII gate.
        $authPayload = null;
        if ($canName && Schema::hasTable('auth_events')) {
            $events = DB::table('auth_events')
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['event_type', 'ip', 'user_agent', 'created_at']);
            $authPayload = ['mode' => 'events', 'events' => $events];
        } elseif (Schema::hasTable('auth_events')) {
            $sumRow = DB::table('auth_events')->where('user_id', $userId)
                ->where('created_at', '>=', $w30from)
                ->selectRaw("
                    SUM(event_type='LOGIN_OK')   AS login_ok_30d,
                    SUM(event_type='LOGIN_FAIL') AS login_fail_30d,
                    COUNT(DISTINCT ip)           AS distinct_ips_30d,
                    COUNT(DISTINCT user_agent)   AS distinct_uas_30d
                ")->first();
            $authPayload = [
                'mode'    => 'summary',
                'summary' => [
                    'login_ok_30d'    => (int) ($sumRow->login_ok_30d ?? 0),
                    'login_fail_30d'  => (int) ($sumRow->login_fail_30d ?? 0),
                    'distinct_ips_30d'=> (int) ($sumRow->distinct_ips_30d ?? 0),
                    'distinct_uas_30d'=> (int) ($sumRow->distinct_uas_30d ?? 0),
                ],
            ];
        } else {
            $authPayload = ['mode' => 'unavailable'];
        }

        return $this->ok([
            'user'        => $profile,
            'kpi_strip'   => [
                'screenings_30d' => $screen30,
                'alerts_30d'     => $alert30,
                'followups_30d'  => $followup30,
                'risk_score'     => (int) ($u->risk_score ?? 0),
            ],
            'assignments' => $assignments,
            'training'    => $training,
            'flags'       => $flags,
            'auth'        => $authPayload,
            'sparkline'   => $spark,
            'pii'         => ['can_name' => $canName],
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 7.  filterRules — extends parent.
     * ────────────────────────────────────────────────────────────────── */
    protected function filterRules(): array
    {
        return parent::filterRules() + [
            'sort'     => ['nullable', 'in:name,role_key,account_status,screenings,alerts_,followups,training,last_act'],
            'dir'      => ['nullable', 'in:asc,desc'],
            'status'   => ['nullable', 'in:all,active,dormant,locked,flagged'],
            'role_key' => ['nullable', 'string', 'max:60'],
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     * Internal — chart builders (aggregated SQL only).
     * ────────────────────────────────────────────────────────────────── */
    private function chartOutputByRole(array $scope, array $f): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($f);

        // Pull the universe of in-scope user_ids and their role_keys (small N).
        $userQ = DB::table('users AS u')
            ->select('u.id', 'u.role_key');
        $this->applyUserScope($userQ, $scope);
        $users = $userQ->get();
        if ($users->isEmpty()) {
            return [
                'labels'      => [],
                'datasets'    => [
                    ['label' => 'Screenings', 'data' => []],
                    ['label' => 'Alerts',     'data' => []],
                    ['label' => 'Followups',  'data' => []],
                ],
                'csv_headers' => ['Role', 'Screenings', 'Alerts', 'Followups'],
                'csv_rows'    => [],
            ];
        }
        $roleByUser = [];
        $allIds = [];
        foreach ($users as $u) { $roleByUser[(int) $u->id] = (string) ($u->role_key ?: 'UNKNOWN'); $allIds[] = (int) $u->id; }

        $screensMap   = $this->countScreeningsByUser($allIds, $from, $to);
        $alertsMap    = $this->countAlertsByUser($allIds, $from, $to);
        $followupsMap = $this->countFollowupsByUser($allIds, $from, $to);

        $byRole = [];
        foreach ($allIds as $uid) {
            $r = $roleByUser[$uid] ?? 'UNKNOWN';
            $byRole[$r] ??= ['screenings' => 0, 'alerts_' => 0, 'followups' => 0];
            $byRole[$r]['screenings'] += (int) ($screensMap[$uid]   ?? 0);
            $byRole[$r]['alerts_']    += (int) ($alertsMap[$uid]    ?? 0);
            $byRole[$r]['followups']  += (int) ($followupsMap[$uid] ?? 0);
        }
        // Sort by total desc, top N.
        uasort($byRole, fn ($a, $b) =>
            ($b['screenings'] + $b['alerts_'] + $b['followups'])
            <=> ($a['screenings'] + $a['alerts_'] + $a['followups'])
        );
        $byRole = array_slice($byRole, 0, self::TOP_LIMIT, true);

        $labels = $screens = $alertsArr = $follows = $csv = [];
        foreach ($byRole as $role => $r) {
            $labels[]    = $role;
            $screens[]   = (int) $r['screenings'];
            $alertsArr[] = (int) $r['alerts_'];
            $follows[]   = (int) $r['followups'];
            $csv[]       = [$role, (int) $r['screenings'], (int) $r['alerts_'], (int) $r['followups']];
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                ['label' => 'Screenings', 'data' => $screens],
                ['label' => 'Alerts',     'data' => $alertsArr],
                ['label' => 'Followups',  'data' => $follows],
            ],
            'csv_headers' => ['Role', 'Screenings', 'Alerts', 'Followups'],
            'csv_rows'    => $csv,
        ];
    }

    private function chartActivityHeatmap(array $scope, array $f): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($f);

        // No auth_events table → degrade to "no data" gracefully.
        if (! Schema::hasTable('auth_events')) {
            return [
                'labels'      => array_map(fn ($h) => sprintf('%02d:00', $h), range(0, 23)),
                'datasets'    => array_map(fn ($d) => ['label' => $d, 'data' => array_fill(0, 24, 0)], ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']),
                'csv_headers' => ['DayOfWeek', 'Hour', 'Logins'],
                'csv_rows'    => [],
            ];
        }

        // Aggregate logins by (DAYOFWEEK, HOUR) restricted to in-scope users.
        $userIds = DB::table('users AS u');
        $this->applyUserScope($userIds, $scope);
        $ids = $userIds->pluck('u.id')->all();

        if (empty($ids)) {
            return [
                'labels'      => array_map(fn ($h) => sprintf('%02d:00', $h), range(0, 23)),
                'datasets'    => array_map(fn ($d) => ['label' => $d, 'data' => array_fill(0, 24, 0)], ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']),
                'csv_headers' => ['DayOfWeek', 'Hour', 'Logins'],
                'csv_rows'    => [],
            ];
        }

        $rows = DB::table('auth_events')
            ->whereIn('user_id', $ids)
            ->where('event_type', 'LOGIN_OK')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DAYOFWEEK(created_at) AS dow, HOUR(created_at) AS h, COUNT(*) AS c')
            ->groupBy(DB::raw('DAYOFWEEK(created_at)'), DB::raw('HOUR(created_at)'))
            ->get();

        // MySQL DAYOFWEEK: 1=Sunday … 7=Saturday. Remap to Mon=0..Sun=6.
        $mysqlToIdx = [2 => 0, 3 => 1, 4 => 2, 5 => 3, 6 => 4, 7 => 5, 1 => 6];
        $dayLabels  = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        $grid       = array_fill(0, 7, array_fill(0, 24, 0));

        foreach ($rows as $r) {
            $idx  = $mysqlToIdx[(int) $r->dow] ?? null;
            $hour = (int) $r->h;
            if ($idx === null || $hour < 0 || $hour > 23) { continue; }
            $grid[$idx][$hour] = (int) $r->c;
        }

        $labels   = array_map(fn ($h) => sprintf('%02d:00', $h), range(0, 23));
        $datasets = [];
        $csv      = [];
        foreach ($dayLabels as $i => $name) {
            $datasets[] = ['label' => $name, 'data' => $grid[$i]];
            for ($h = 0; $h < 24; $h++) {
                $csv[] = [$name, sprintf('%02d:00', $h), $grid[$i][$h]];
            }
        }

        return [
            'labels'      => $labels,
            'datasets'    => $datasets,
            'csv_headers' => ['DayOfWeek', 'Hour', 'Logins'],
            'csv_rows'    => $csv,
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     * Internal — per-user aggregate maps (all aggregation in SQL).
     * ────────────────────────────────────────────────────────────────── */
    private function countScreeningsByUser(array $userIds, Carbon $from, Carbon $to): array
    {
        if (empty($userIds)) { return []; }
        return DB::table('primary_screenings')->whereNull('deleted_at')
            ->whereIn('captured_by_user_id', $userIds)
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw('captured_by_user_id AS uid, COUNT(*) AS c')
            ->groupBy('captured_by_user_id')
            ->pluck('c', 'uid')->all();
    }

    private function countAlertsByUser(array $userIds, Carbon $from, Carbon $to): array
    {
        if (empty($userIds)) { return []; }
        return DB::table('alerts')->whereNull('deleted_at')
            ->whereIn('acknowledged_by_user_id', $userIds)
            ->whereBetween('acknowledged_at', [$from, $to])
            ->selectRaw('acknowledged_by_user_id AS uid, COUNT(*) AS c')
            ->groupBy('acknowledged_by_user_id')
            ->pluck('c', 'uid')->all();
    }

    private function countFollowupsByUser(array $userIds, Carbon $from, Carbon $to): array
    {
        if (empty($userIds) || ! Schema::hasTable('alert_followups')) { return []; }
        return DB::table('alert_followups')
            ->whereIn('completed_by_user_id', $userIds)
            ->where('status', 'COMPLETED')
            ->whereBetween('completed_at', [$from, $to])
            ->selectRaw('completed_by_user_id AS uid, COUNT(*) AS c')
            ->groupBy('completed_by_user_id')
            ->pluck('c', 'uid')->all();
    }

    /**
     * Worst training status per user across all training_records — single
     * grouped query collapses to {user_id => EXPIRED|EXPIRING|VALID|REVOKED}.
     */
    private function trainingStatusByUser(array $userIds): array
    {
        if (empty($userIds) || ! Schema::hasTable('user_training_records')) { return []; }
        $rows = DB::table('user_training_records')
            ->whereIn('user_id', $userIds)
            ->whereNull('deleted_at')
            ->selectRaw('user_id, status, COUNT(*) AS c')
            ->groupBy('user_id', 'status')
            ->get();
        $rank = ['REVOKED' => 4, 'EXPIRED' => 3, 'EXPIRING' => 2, 'VALID' => 1];
        $best = [];
        foreach ($rows as $r) {
            $uid = (int) $r->user_id;
            $s   = (string) $r->status;
            $sr  = $rank[$s] ?? 0;
            $cur = isset($best[$uid]) ? ($rank[$best[$uid]] ?? 0) : 0;
            if ($sr > $cur) {
                $best[$uid] = $s;
            }
        }
        return $best;
    }

    /**
     * Per-user output count (screenings + alerts) over $from..$to. Returns
     * { user_id => count } map across the in-scope users.
     */
    private function perUserOutputCounts(array $scope, Carbon $from, Carbon $to): array
    {
        $userIds = DB::table('users AS u')->where('u.is_active', 1);
        $this->applyUserScope($userIds, $scope);
        $ids = $userIds->pluck('u.id')->all();
        if (empty($ids)) { return []; }
        $screens = $this->countScreeningsByUser($ids, $from, $to);
        $alerts  = $this->countAlertsByUser($ids, $from, $to);
        $out = [];
        foreach ($ids as $uid) {
            $out[(int) $uid] = (int) (($screens[$uid] ?? 0) + ($alerts[$uid] ?? 0));
        }
        return $out;
    }

    private function medianOf(array $values): ?int
    {
        $n = count($values);
        if ($n === 0) { return null; }
        if ($n % 2 === 1) { return (int) $values[intdiv($n, 2)]; }
        return (int) (($values[intdiv($n, 2) - 1] + $values[intdiv($n, 2)]) / 2);
    }

    /* ──────────────────────────────────────────────────────────────────
     * Internal — scope helper for `users` (no direct poe_code column).
     * Adds: whereExists user_assignments scope-applied as `uax`.
     * ────────────────────────────────────────────────────────────────── */
    private function applyUserScope($q, array $scope): void
    {
        if (! empty($scope['is_super'])) {
            return; // super sees all users.
        }
        $q->whereExists(function ($sub) use ($scope) {
            $sub->select(DB::raw(1))->from('user_assignments AS uax')
                ->whereColumn('uax.user_id', 'u.id')
                ->where('uax.is_active', 1);
            $this->scope->apply($sub, $scope, 'uax');
        });
    }

    /* ──────────────────────────────────────────────────────────────────
     * Internal — display + status helpers.
     * ────────────────────────────────────────────────────────────────── */
    private function computeAccountStatus(object $u, Carbon $now, Carbon $dThresh): string
    {
        if (! ($u->is_active ?? 0)) { return 'inactive'; }
        if (isset($u->locked_until) && $u->locked_until && Carbon::parse((string) $u->locked_until)->isFuture()) {
            return 'locked';
        }
        if (! isset($u->last_activity_at) || $u->last_activity_at === null
            || Carbon::parse((string) $u->last_activity_at)->lt($dThresh)) {
            return 'dormant';
        }
        if ((int) ($u->failed_login_count ?? 0) > self::FAILED_LOGIN_THRESHOLD) {
            return 'flagged';
        }
        return 'active';
    }

    /**
     * For non-super viewers, render an officer as `ROLE · scope_label`.
     * Falls back to a masked username token if the role_key is missing.
     */
    private function maskedDisplay(object $u, array $scope): string
    {
        $role = (string) ($u->role_key ?? 'OFFICER');
        $tok  = $this->maskedToken((string) ($u->username ?: ''));
        $sl   = (string) ($scope['label'] ?? '—');
        return $role . ' · ' . ($tok !== '' ? $tok : $sl);
    }

    private function maskedToken(string $s): string
    {
        $s = trim($s);
        if ($s === '') { return ''; }
        $len = function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
        if ($len <= 4) { return str_repeat('•', $len); }
        $first = function_exists('mb_substr') ? mb_substr($s, 0, 2) : substr($s, 0, 2);
        $last  = function_exists('mb_substr') ? mb_substr($s, -2)   : substr($s, -2);
        return $first . '••' . $last;
    }
}
