<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\QuickReports;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quick Report · User Analysis.
 *
 * URL: /admin/quick-reports/user-analysis
 *
 * Question: "Which officers are active, who is dormant, and how is the workload
 * distributed across the team?"
 *
 * Cohort: users × user_assignments filtered to scope. Activity derived from
 * primary_screenings.captured_by_user_id, secondary_screenings.opened_by_user_id,
 * alerts.current_owner_user_id, and users.last_login_at.
 */
final class UserAnalysisController extends BaseQuickReportController
{
    protected string $reportKey   = 'qr-users';
    protected string $reportTitle = 'User Analysis';

    private const TABLE_LIMIT = 20;
    private const CHART_TOP_N = 12;

    private const MATERIAL_PALETTE = [
        '#E53935','#1E88E5','#43A047','#FB8C00','#8E24AA','#00ACC1',
        '#F4511E','#3949AB','#7CB342','#D81B60','#FFB300','#00897B',
    ];

    // Pretty labels for every role_key that has ever existed in production.
    // Live values observed in DB (2026-05-20): NATIONAL_ADMIN, PHEOC_OFFICER, DISTRICT_SUPERVISOR, SCREENER.
    // Older role_keys retained for compat with historical user rows.
    private const ROLE_LABELS = [
        'NATIONAL_ADMIN'      => 'National admin',
        'PHEOC'               => 'PHEOC',
        'PHEOC_OFFICER'       => 'PHEOC officer',
        'DISTRICT'            => 'District',
        'DISTRICT_SUPERVISOR' => 'District supervisor',
        'DISTRICT_OFFICER'    => 'District officer',
        'POE'                 => 'POE officer',
        'POE_OFFICER'         => 'POE officer',
        'SCREENER'            => 'Screener',
        'OBSERVER'            => 'Observer',
        'SERVICE'             => 'Service account',
    ];

    private const ROLE_COLOURS = [
        'NATIONAL_ADMIN'      => '#E53935',
        'PHEOC'               => '#FB8C00',
        'PHEOC_OFFICER'       => '#FB8C00',
        'DISTRICT'            => '#1E88E5',
        'DISTRICT_SUPERVISOR' => '#1E88E5',
        'DISTRICT_OFFICER'    => '#1E88E5',
        'POE'                 => '#43A047',
        'POE_OFFICER'         => '#43A047',
        'SCREENER'            => '#43A047',
        'OBSERVER'            => '#8E24AA',
        'SERVICE'             => '#00ACC1',
    ];

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.quick.users.index', [
            'scope' => $scope, 'reportKey' => $this->reportKey, 'reportTitle' => $this->reportTitle,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->applyDefaultWindow($this->readFilters($request));
        $payload = $this->memoise((int) ($scope['user_id'] ?? 0), $filters,
            fn () => $this->buildPayload($scope, $filters));
        $payload['filters'] = $filters;
        $payload['scope']   = ['label' => $scope['label'] ?? '—', 'level' => $scope['scope_level'] ?? 'SELF'];
        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->applyDefaultWindow($this->readFilters($request));
        $payload = $this->buildPayload($scope, $filters);

        $headers = ['Officer','Username','Role','Primary','Secondary','Alerts handled','Last activity','Status'];
        $rows = [];
        foreach ($payload['table_full'] as $r) {
            $rows[] = [
                $r['full_name'] ?: '—', $r['username'] ?: '—',
                $r['role_label'] ?: '—',
                (int) $r['primary'], (int) $r['secondary'], (int) $r['alerts'],
                $r['last_activity_label'] ?: 'No activity',
                $r['status_label'] ?: 'Unknown',
            ];
        }
        return $this->writer->send($this->reportKey, (string) $request->input('format', 'CSV'),
            $headers, $rows, $filters, (int) ($scope['user_id'] ?? 0), $this->reportTitle);
    }

    public function buildPayload(array $scope, array $filters): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($filters);
        $windowLabel = $this->windowLabel($from, $to);
        $tz = config('app.timezone', 'Africa/Kampala');

        $allowedPoes  = $this->scope->allowedPoes($scope);
        $allowedCodes = array_keys($allowedPoes);
        if (! empty($filters['poe'])) {
            $code = (string) $filters['poe'];
            if (in_array($code, $allowedCodes, true)) {
                $allowedCodes = [$code]; $allowedPoes = [$code => $allowedPoes[$code] ?? $code];
            } else { $allowedCodes = []; }
        }

        // ── Cohort: users who have an active assignment in the allowed POEs,
        //     PLUS the scope owner. NATIONAL has no POE filter; everyone else
        //     is gated by allowed POEs. ─────────────────────────────────────
        $userIdsAssigned = [];
        if ($allowedCodes) {
            $userIdsAssigned = DB::table('user_assignments')
                ->where('is_active', 1)
                ->whereIn('poe_code', $allowedCodes)
                ->pluck('user_id')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->all();
        }
        $isNational = ($scope['scope_level'] ?? '') === 'NATIONAL';
        $userQ = DB::table('users');
        if (! $isNational) {
            if (! $userIdsAssigned) { $userIdsAssigned = [0]; }
            $userQ->whereIn('id', $userIdsAssigned);
        }
        $users = $userQ
            ->select('id','role_key','full_name','username','is_active','last_login_at')
            ->get()
            ->keyBy(fn ($u) => (int) $u->id);

        $userIds = $users->keys()->all();

        // ── Activity counts in window for those users ────────────────────
        $primaryByUser = []; $secondaryByUser = []; $alertsByUser = [];
        $lastActivity  = []; // user_id => latest ISO

        if ($userIds) {
            $pq = DB::table('primary_screenings')
                ->whereNull('deleted_at')
                ->whereBetween('captured_at', [$from, $to])
                ->whereIn('captured_by_user_id', $userIds);
            if (! empty($allowedCodes)) { $pq->whereIn('poe_code', $allowedCodes); }
            $this->scope->apply($pq, $scope);
            foreach ($pq->select('captured_by_user_id','captured_at')->cursor() as $r) {
                $uid = (int) $r->captured_by_user_id;
                $primaryByUser[$uid] = ($primaryByUser[$uid] ?? 0) + 1;
                $this->bumpIso($lastActivity, $uid, (string) $r->captured_at);
            }

            $sq = DB::table('secondary_screenings')
                ->whereNull('deleted_at')
                ->whereBetween('opened_at', [$from, $to])
                ->whereIn('opened_by_user_id', $userIds);
            if (! empty($allowedCodes)) { $sq->whereIn('poe_code', $allowedCodes); }
            $this->scope->apply($sq, $scope);
            foreach ($sq->select('opened_by_user_id','opened_at')->cursor() as $r) {
                $uid = (int) $r->opened_by_user_id;
                $secondaryByUser[$uid] = ($secondaryByUser[$uid] ?? 0) + 1;
                $this->bumpIso($lastActivity, $uid, (string) $r->opened_at);
            }

            $aq = DB::table('alerts')
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$from, $to])
                ->whereIn('current_owner_user_id', $userIds);
            if (! empty($allowedCodes)) { $aq->whereIn('poe_code', $allowedCodes); }
            $this->scope->apply($aq, $scope);
            foreach ($aq->select('current_owner_user_id','created_at')->cursor() as $r) {
                $uid = (int) $r->current_owner_user_id;
                $alertsByUser[$uid] = ($alertsByUser[$uid] ?? 0) + 1;
                $this->bumpIso($lastActivity, $uid, (string) $r->created_at);
            }
        }

        // ── Per-user row build ───────────────────────────────────────────
        $rows = [];
        $rolesSeen = [];
        $kpiActiveCount = 0; $kpiDormantCount = 0;
        $topPerformer = null; $topPerformerScore = -1;
        $latestLogin  = null; $latestLoginUser = null;
        $perActiveTotals = []; // total events per active user (for median)
        $bucketsDormancy = [
            'Active today (<24h)' => 0,
            'Active this week (1-7d)' => 0,
            'Dormant >7d' => 0,
            'Never logged in' => 0,
        ];
        $now = Carbon::now($tz);

        foreach ($users as $u) {
            $uid = (int) $u->id;
            $pri = $primaryByUser[$uid]   ?? 0;
            $sec = $secondaryByUser[$uid] ?? 0;
            $alr = $alertsByUser[$uid]    ?? 0;
            $total = $pri + $sec + $alr;

            $hasContribution = $total > 0;
            $isInactive = (int) ($u->is_active ?? 0) === 0;
            $status = $isInactive
                ? 'Inactive'
                : ($hasContribution ? 'Active' : 'Dormant');
            if ($status === 'Active')   { $kpiActiveCount++; $perActiveTotals[] = $total; }
            if ($status === 'Dormant')  { $kpiDormantCount++; }

            // Last activity = max(captured_at, opened_at, alert created, last_login_at)
            $lastIso = $lastActivity[$uid] ?? null;
            if ($u->last_login_at && (! $lastIso || strcmp((string) $u->last_login_at, $lastIso) > 0)) {
                $lastIso = (string) $u->last_login_at;
            }

            // Dormancy bucketing (uses last_login_at)
            if (! $u->last_login_at) {
                $bucketsDormancy['Never logged in']++;
            } else {
                try {
                    $diffH = $now->diffInHours(Carbon::parse((string) $u->last_login_at), false);
                    $diffH = abs($diffH);
                    if ($diffH < 24)        { $bucketsDormancy['Active today (<24h)']++; }
                    elseif ($diffH <= 7*24) { $bucketsDormancy['Active this week (1-7d)']++; }
                    else                    { $bucketsDormancy['Dormant >7d']++; }
                } catch (\Throwable $e) { /* skip */ }
            }

            $role = (string) ($u->role_key ?? '');
            if ($hasContribution && $role !== '') { $rolesSeen[$role] = true; }

            $row = [
                'user_id'             => $uid,
                'full_name'           => (string) ($u->full_name ?? $u->username ?? ('user#'.$uid)),
                'username'            => (string) ($u->username ?? ''),
                'role_key'            => $role,
                'role_label'          => self::ROLE_LABELS[$role] ?? ($role !== '' ? $role : '—'),
                'primary'             => $pri,
                'secondary'           => $sec,
                'alerts'              => $alr,
                'total_activity'      => $total,
                'last_activity_iso'   => $lastIso,
                'last_activity_label' => $lastIso ? $this->humanDate($lastIso) : null,
                'last_login_iso'      => $u->last_login_at ? (string) $u->last_login_at : null,
                'status'              => $status,
                'status_label'        => $status,
                'is_active'           => ! $isInactive,
            ];
            $rows[] = $row;

            if ($status === 'Active' && $total > $topPerformerScore) {
                $topPerformer = $row; $topPerformerScore = $total;
            }
            if ($u->last_login_at && (! $latestLogin || strcmp((string) $u->last_login_at, $latestLogin) > 0)) {
                $latestLogin = (string) $u->last_login_at;
                $latestLoginUser = $row;
            }
        }

        // Optional filters: role + status + free-text
        if (! empty($filters['role'])) {
            $needle = (string) $filters['role'];
            $rows = array_values(array_filter($rows, fn ($r) => $r['role_key'] === $needle));
        }
        if (! empty($filters['status'])) {
            $needle = (string) $filters['status'];
            $rows = array_values(array_filter($rows, fn ($r) => $r['status'] === $needle));
        }
        if (! empty($filters['q'])) {
            $needle = strtolower((string) $filters['q']);
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                $hay = strtolower(($r['full_name'] ?? '') . ' ' . ($r['username'] ?? '') . ' ' . ($r['role_label'] ?? ''));
                return strpos($hay, $needle) !== false;
            }));
        }

        usort($rows, function ($a, $b) {
            if ($a['total_activity'] !== $b['total_activity']) {
                return $b['total_activity'] <=> $a['total_activity'];
            }
            return strcmp($a['full_name'], $b['full_name']);
        });
        $tableVisible = array_slice($rows, 0, self::TABLE_LIMIT);

        $kpis = [
            'active_officers'   => $kpiActiveCount,
            'dormant_officers'  => $kpiDormantCount,
            'top_performer'     => $topPerformer
                ? ['label' => $topPerformer['full_name'], 'value' => $topPerformer['total_activity']]
                : null,
            'latest_login'      => $latestLoginUser && $latestLogin
                ? ['label' => $latestLoginUser['full_name'], 'value' => $this->humanDate($latestLogin)]
                : null,
            'median_per_active' => $this->median($perActiveTotals),
            'roles_active'      => count($rolesSeen),
        ];

        $chart = $this->pickChart($rows, $bucketsDormancy, $windowLabel);

        // Role/status option lists (for the filter UI)
        $roleOpts = [];
        foreach ($users as $u) {
            $rk = (string) ($u->role_key ?? '');
            if ($rk === '') { continue; }
            $roleOpts[$rk] = self::ROLE_LABELS[$rk] ?? $rk;
        }
        ksort($roleOpts);

        return [
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
                         'days' => (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400) + 1,
                         'label' => $windowLabel],
            'kpis'       => $kpis,
            'chart'      => $chart,
            'table'      => $tableVisible,
            'table_full' => $rows,
            'total_rows' => count($rows),
            'shown_rows' => count($tableVisible),
            'meta'       => [
                'poes'      => $allowedPoes,
                'roles'     => $roleOpts,
                'statuses'  => ['Active' => 'Active', 'Dormant' => 'Dormant', 'Inactive' => 'Inactive'],
            ],
        ];
    }

    private function pickChart(array $rows, array $dormancy, string $windowLabel): array
    {
        // A — Top officers by total activity (most decision-relevant)
        $actives = array_values(array_filter($rows, fn ($r) => $r['total_activity'] > 0));
        if ($actives) {
            usort($actives, fn ($a, $b) => $b['total_activity'] <=> $a['total_activity']);
            $top = array_slice($actives, 0, self::CHART_TOP_N);
            $labels = []; $values = []; $colors = [];
            foreach ($top as $i => $r) {
                $labels[] = $r['full_name'];
                $values[] = (int) $r['total_activity'];
                // Top bar red to mark the leader; rest cycle Material palette
                $colors[] = $i === 0 ? '#E53935' : self::MATERIAL_PALETTE[($i + 1) % count(self::MATERIAL_PALETTE)];
            }
            return [
                'kind'     => 'top_officers',
                'title'    => 'Top officers by activity',
                'subtitle' => 'Sum of primary screenings, secondary screenings, and alerts handled. Red bar = leader.',
                'labels'   => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'events',
            ];
        }

        // B — Role distribution (any role with ≥1 user)
        $byRole = [];
        foreach ($rows as $r) {
            $rk = $r['role_key'] ?: 'UNKNOWN';
            $byRole[$rk] = ($byRole[$rk] ?? 0) + 1;
        }
        if (array_filter($byRole)) {
            arsort($byRole);
            $labels = []; $values = []; $colors = [];
            foreach ($byRole as $rk => $n) {
                $labels[] = self::ROLE_LABELS[$rk] ?? $rk;
                $values[] = (int) $n;
                $colors[] = self::ROLE_COLOURS[$rk] ?? '#1E88E5';
            }
            return [
                'kind'     => 'role',
                'title'    => 'Officers by role',
                'subtitle' => 'No officers logged activity in this window — falling back to role headcount.',
                'labels'   => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'officers',
            ];
        }

        // C — Dormancy buckets
        if (array_filter($dormancy)) {
            $labels = array_keys($dormancy); $values = array_values($dormancy);
            return [
                'kind'     => 'dormancy',
                'title'    => 'Login recency',
                'subtitle' => 'When each officer last signed in. "Never logged in" includes pending invitations.',
                'labels'   => $labels, 'values' => $values, 'colors' => $this->cycle(count($labels)), 'unit' => 'officers',
            ];
        }

        return [
            'kind' => 'empty',
            'title' => 'No officers in scope',
            'subtitle' => 'Either no users are assigned to a POE in scope or no activity in window.',
            'labels' => [], 'values' => [], 'colors' => [], 'unit' => 'officers',
        ];
    }

    private function median(array $values): ?int
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null && $v >= 0));
        if (! $values) { return null; }
        sort($values);
        $n = count($values); $mid = intdiv($n, 2);
        return $n % 2 ? (int) $values[$mid] : (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }

    private function bumpIso(array &$last, int $uid, string $iso): void
    {
        if (! isset($last[$uid]) || strcmp($iso, (string) $last[$uid]) > 0) { $last[$uid] = $iso; }
    }

    private function humanDate(string $iso): string
    {
        if ($iso === '') { return '—'; }
        try { return Carbon::parse($iso)->setTimezone(config('app.timezone','Africa/Kampala'))->format('M j, H:i'); }
        catch (\Throwable $e) { return $iso; }
    }

    private function cycle(int $n): array
    {
        $out = []; $p = self::MATERIAL_PALETTE; $len = count($p);
        for ($i = 0; $i < $n; $i++) { $out[] = $p[$i % $len]; }
        return $out;
    }
}
