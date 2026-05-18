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
use Symfony\Component\HttpFoundation\Response;

/**
 * R7 · rpt-poe-performance — POE Performance Index.
 *
 * Executive question: "Which POEs are pulling their weight, and which are
 * silently underperforming or hot?"
 *
 * Engineering contract: aggregation-only SQL, two-query whereIn for ref_poes
 * lookup (cross-collation safe), reserved-word column aliases suffixed with _,
 * every read scope-applied via ReportScope::apply() before aggregation.
 */
final class PoePerformanceController extends BaseReportController
{
    protected string $reportKey   = 'rpt-poe-performance';
    protected string $reportTitle = 'POE Performance';

    private const DARK_DAYS    = 7;
    private const TOP_LIMIT    = 10;
    private const RECORDS_LIMIT = 10;

    /* ──────────────────────────────────────────────────────────────────
     * 1.  index — Blade shell.
     * ────────────────────────────────────────────────────────────────── */
    public function index(Request $request): View
    {
        $this->ensureAccess($request);
        return view('admin.reports.v2.rpt-poe-performance', [
            'reportKey'   => $this->reportKey,
            'reportTitle' => $this->reportTitle,
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 2.  meta — filter dropdowns + scope label.
     * ────────────────────────────────────────────────────────────────── */
    public function meta(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        return $this->ok([
            'poes'  => $this->scope->allowedPoes($scope),
            'scope' => [
                'label' => $scope['label'] ?? '—',
                'level' => $scope['scope_level'] ?? 'SELF',
                'is_super' => (bool) ($scope['is_super'] ?? false),
            ],
            'statuses' => ['all', 'OPEN', 'CLOSED', 'REDUCED_HOURS', 'EMERGENCY_CLOSED', 'MAINTENANCE'],
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
        $allowed = $this->scope->allowedPoes($scope);
        $allowedCodes = array_keys($allowed);
        if (empty($allowedCodes)) {
            return $this->ok(['kpis' => $this->emptyKpis()]);
        }

        // Aggregate primary_screenings by poe_code in window — ONE query.
        $screensByCode = $this->scopedScreeningCountsByPoe($scope, $f, $from, $to);
        $alertsByCode  = $this->scopedAlertCountsByPoe($scope, $f, $from, $to);

        // POEs with > 0 screenings in window → "active POEs".
        $activePoes = 0;
        $topPoeCode = null;
        $topPoeCount = 0;
        $totalScreens = 0;
        foreach ($allowedCodes as $code) {
            $name = $allowed[$code] ?? $code;
            $count = (int) ($screensByCode[$code] ?? $screensByCode[$name] ?? 0);
            $totalScreens += $count;
            if ($count > 0) { $activePoes++; }
            if ($count > $topPoeCount) { $topPoeCount = $count; $topPoeCode = $code; }
        }
        $topPoeName = $topPoeCode ? ($allowed[$topPoeCode] ?? $topPoeCode) : '—';

        // Dark POEs in last 7 days (separate window — always 7d).
        $darkThreshold = Carbon::now()->subDays(self::DARK_DAYS);
        $screen7d = $this->screeningCountsByPoeSince($scope, $darkThreshold);
        $darkCount = 0;
        foreach ($allowedCodes as $code) {
            $name = $allowed[$code] ?? $code;
            $any = ($screen7d[$code] ?? 0) + ($screen7d[$name] ?? 0);
            if ($any === 0) { $darkCount++; }
        }

        // Alert rate in window.
        $totalAlerts = 0;
        foreach ($alertsByCode as $c => $n) { $totalAlerts += (int) $n; }
        $alertRate = $this->safePct($totalAlerts, $totalScreens);

        // Officer coverage — % of allowed POEs with ≥ 1 active assignment.
        $covered = $this->countCoveredPoes($scope, $allowedCodes);
        $coveragePct = empty($allowedCodes) ? null : round(($covered / count($allowedCodes)) * 100, 1);

        return $this->ok([
            'kpis' => [
                ['key' => 'active_poes',     'label' => 'Active POEs',      'value' => number_format($activePoes), 'tone' => 'brand', 'hint' => 'POEs with at least one screening in the window.'],
                ['key' => 'dark_poes_7d',    'label' => 'Dark POEs (7d)',   'value' => number_format($darkCount),  'tone' => $darkCount > 0 ? 'warning' : 'success', 'hint' => 'No screenings in the last ' . self::DARK_DAYS . ' days.'],
                ['key' => 'alert_rate_pct',  'label' => 'Alert Rate',       'value' => $alertRate === null ? '—' : ($alertRate . '%'), 'tone' => $this->alertTone($alertRate), 'hint' => 'Alerts ÷ screenings in window.'],
                ['key' => 'top_poe',         'label' => 'Top POE',          'value' => $topPoeName . ($topPoeCount > 0 ? ' · ' . number_format($topPoeCount) : ''), 'tone' => 'info', 'hint' => 'Highest screening count in window.'],
                ['key' => 'staffing_coverage','label' => 'Officer Coverage','value' => $coveragePct === null ? '—' : ($coveragePct . '%'), 'tone' => $this->coverageTone($coveragePct), 'hint' => '% of POEs with ≥ 1 active assignment.'],
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
            'volume_30d'         => $this->ok($this->chartVolume30d($scope, $f)),
            'top_poes_by_volume' => $this->ok($this->chartTopPoesByVolume($scope, $f)),
            default              => $this->fail(404, 'Unknown chart key.'),
        };
    }

    public function chartCsv(Request $request, string $chart): StreamedResponse|Response
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        $payload = match ($chart) {
            'volume_30d'         => $this->chartVolume30d($scope, $f),
            'top_poes_by_volume' => $this->chartTopPoesByVolume($scope, $f),
            default              => abort(404, 'Unknown chart key.'),
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
     * 5.  records — 10-row paginated POE Performance Index.
     * ────────────────────────────────────────────────────────────────── */
    public function records(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $f       = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $page    = max(1, (int) $request->input('page', 1));
        $perPage = self::RECORDS_LIMIT;
        $q       = trim((string) $request->input('q', ''));
        $sort    = (string) $request->input('sort', 'screenings');
        $dir     = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $status  = (string) $request->input('status', 'all');

        $allowed = $this->scope->allowedPoes($scope);
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $allowed = array_filter($allowed, fn ($name, $code) =>
                str_contains(mb_strtolower((string) $name), $needle) ||
                str_contains(mb_strtolower((string) $code), $needle), ARRAY_FILTER_USE_BOTH);
        }
        $allowedCodes = array_keys($allowed);

        // Build aggregate maps keyed by poe_code OR poe_name (legacy data).
        $screensByCode = $this->scopedScreeningCountsByPoe($scope, $f, $from, $to);
        $alertsByCode  = $this->scopedAlertCountsByPoe($scope, $f, $from, $to);
        $lastActByCode = $this->lastActivityByPoe($scope, $allowedCodes);
        $officerByCode = $this->officerCountsByPoe($scope, $allowedCodes);
        $statusByCode  = $this->currentStatusByPoe($allowedCodes);

        // Build poe_type lookup via two-query whereIn (no cross-collation join).
        $typesByCode = DB::table('ref_poes')
            ->whereIn('poe_code', $allowedCodes)
            ->whereNull('deleted_at')
            ->pluck('poe_type', 'poe_code')->all();

        $rows = [];
        foreach ($allowed as $code => $name) {
            $screenings = (int) (($screensByCode[$code] ?? 0) + ($screensByCode[$name] ?? 0));
            $alertsN    = (int) (($alertsByCode[$code]  ?? 0) + ($alertsByCode[$name]  ?? 0));
            $rows[] = [
                'poe_code'    => $code,
                'poe_name'    => $name,
                'poe_type'    => $typesByCode[$code] ?? '—',
                'status_now'  => $statusByCode[$code] ?? 'OPEN',
                'screenings'  => $screenings,
                // 'alerts_' suffixed to avoid MySQL reserved-word collisions when
                // the alias is used in a sortBy expression.
                'alerts_'     => $alertsN,
                'alert_rate'  => $this->safePct($alertsN, $screenings),
                'officers'    => (int) ($officerByCode[$code] ?? 0),
                'last_act'    => $lastActByCode[$code] ?? null,
            ];
        }

        if ($status !== 'all' && $status !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['status_now'] === $status));
        }

        $sortable = ['poe_name', 'poe_type', 'status_now', 'screenings', 'alerts_', 'alert_rate', 'officers', 'last_act'];
        $sortKey = in_array($sort, $sortable, true) ? $sort : 'screenings';
        usort($rows, function ($a, $b) use ($sortKey, $dir) {
            $av = $a[$sortKey]; $bv = $b[$sortKey];
            if ($av === null && $bv === null) return 0;
            if ($av === null) return 1;
            if ($bv === null) return -1;
            $cmp = is_numeric($av) && is_numeric($bv) ? ($av <=> $bv) : strcmp((string) $av, (string) $bv);
            return $dir === 'asc' ? $cmp : -$cmp;
        });

        $total = count($rows);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

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
            'controls' => ['sort' => $sortKey, 'dir' => $dir, 'q' => $q, 'status' => $status],
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 6.  recordDetail — drill-down by poe_code.
     * ────────────────────────────────────────────────────────────────── */
    public function recordDetail(Request $request, string $key): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $allowed = $this->scope->allowedPoes($scope);
        if (! array_key_exists($key, $allowed)) {
            return $this->fail(404, 'POE not in scope or not found.');
        }
        $name = $allowed[$key] ?? $key;

        $info = DB::table('ref_poes')
            ->where('poe_code', $key)
            ->whereNull('deleted_at')
            ->first([
                'poe_code', 'poe_name', 'poe_type', 'transport_mode',
                'border_country', 'admin_level_1', 'district', 'regional_cluster',
                'is_major_entry', 'is_recommended_osbp',
                'latitude', 'longitude', 'gazette_source', 'is_active',
            ]);

        // Recent status events (latest 10).
        $statusEvents = DB::table('poe_status_events')
            ->where('poe_code', $key)
            ->orderByDesc('started_at')
            ->limit(10)
            ->get(['status', 'reason', 'started_at', 'ended_at']);
        $statusNow = (string) (DB::table('poe_status_events')
            ->where('poe_code', $key)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->value('status') ?: 'OPEN');

        // Latest non-DRAFT capacity assessment + scores.
        $assess = DB::table('poe_capacity_assessments')
            ->where('poe_code', $key)
            ->whereIn('status', ['SUBMITTED', 'REVIEWED'])
            ->whereNull('deleted_at')
            ->orderByDesc('assessment_date')
            ->first(['id', 'assessment_date', 'status', 'overall_score', 'summary', 'gaps_identified', 'action_plan', 'submitted_at', 'reviewed_at']);
        $scores = $assess
            ? DB::table('poe_capacity_scores')
                ->where('assessment_id', $assess->id)
                ->orderBy('capacity_code')
                ->get(['capacity_code', 'capacity_label', 'score', 'evidence', 'gap_notes'])
            : collect();

        // Assigned officers — 20 most recently active. PII gated by canSeeNamedResponders.
        $officersRaw = DB::table('user_assignments AS ua')
            ->join('users AS u', 'u.id', '=', 'ua.user_id')
            ->where('ua.poe_code', $key)
            ->where('ua.is_active', 1)
            ->where('u.is_active', 1)
            ->orderByDesc('u.last_activity_at')
            ->limit(20)
            ->get(['u.id', 'u.full_name', 'u.username', 'u.role_key', 'u.account_type', 'u.last_activity_at']);
        $canName = $this->access->canSeeNamedResponders($scope);
        $officers = $officersRaw->map(function ($u) use ($canName) {
            return [
                'id'                => (int) $u->id,
                'name'              => $canName ? ($u->full_name ?: $u->username) : ($u->role_key . ' · ' . substr((string) ($u->username ?: ''), 0, 2) . '••'),
                'role_key'          => $u->role_key,
                'account_type'      => $u->account_type,
                'last_activity_at'  => $u->last_activity_at,
            ];
        });

        // Training gap rollup for these officers.
        $userIds = $officersRaw->pluck('id')->all();
        $training = ['domains' => [], 'expired' => 0, 'expiring' => 0];
        if ($userIds) {
            $rows = DB::table('user_training_records')
                ->whereIn('user_id', $userIds)
                ->whereNull('deleted_at')
                ->selectRaw("competency_domain, status, COUNT(*) AS c")
                ->groupBy('competency_domain', 'status')
                ->get();
            foreach ($rows as $r) {
                $training['domains'][$r->competency_domain] = ($training['domains'][$r->competency_domain] ?? 0) + (int) $r->c;
                if ($r->status === 'EXPIRED') { $training['expired']  += (int) $r->c; }
                if ($r->status === 'EXPIRING'){ $training['expiring'] += (int) $r->c; }
            }
        }

        // Recent alerts (10) — scope-applied.
        $alertsQ = DB::table('alerts')->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('poe_code', $key)->orWhere('poe_code', $name));
        $this->scope->apply($alertsQ, $scope);
        $recentAlerts = $alertsQ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'alert_code', 'alert_title', 'risk_level', 'status', 'created_at']);

        // 30-day spark (primary screenings).
        $sparkRows = DB::table('primary_screenings')
            ->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('poe_code', $key)->orWhere('poe_code', $name))
            ->where('captured_at', '>=', Carbon::now()->subDays(29))
            ->selectRaw('DATE(captured_at) AS d, COUNT(*) AS c')
            ->groupBy(DB::raw('DATE(captured_at)'))
            ->pluck('c', 'd')->all();
        $spark = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->toDateString();
            $spark[] = ['date' => $d, 'count' => (int) ($sparkRows[$d] ?? 0)];
        }

        // KPI strip — 30d window.
        $w30from = Carbon::now()->subDays(29)->startOfDay();
        $w30to   = Carbon::now()->endOfDay();
        $screen30 = (int) DB::table('primary_screenings')->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('poe_code', $key)->orWhere('poe_code', $name))
            ->whereBetween('captured_at', [$w30from, $w30to])->count();
        $alert30Q = DB::table('alerts')->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('poe_code', $key)->orWhere('poe_code', $name))
            ->whereBetween('created_at', [$w30from, $w30to]);
        $this->scope->apply($alert30Q, $scope);
        $alert30 = (int) $alert30Q->count();
        $lastAct = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('poe_code', $key)->orWhere('poe_code', $name))
            ->max('captured_at');

        return $this->ok([
            'poe' => [
                'code'   => $key,
                'name'   => $name,
                'info'   => $info,
                'status_now' => $statusNow,
            ],
            'kpi_strip' => [
                'screenings_30d' => $screen30,
                'alerts_30d'     => $alert30,
                'alert_rate_30d' => $this->safePct($alert30, $screen30),
                'last_activity'  => $lastAct,
            ],
            'status_events' => $statusEvents,
            'capacity'      => ['header' => $assess, 'scores' => $scores],
            'officers'      => $officers,
            'training'      => $training,
            'recent_alerts' => $recentAlerts,
            'sparkline'     => $spark,
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 7.  filterRules — extends parent.
     * ────────────────────────────────────────────────────────────────── */
    protected function filterRules(): array
    {
        return parent::filterRules() + [
            'sort'   => ['nullable', 'in:poe_name,poe_type,status_now,screenings,alerts_,alert_rate,officers,last_act'],
            'dir'    => ['nullable', 'in:asc,desc'],
            'status' => ['nullable', 'in:all,OPEN,CLOSED,REDUCED_HOURS,EMERGENCY_CLOSED,MAINTENANCE'],
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     * Internal — chart builders (aggregated SQL only).
     * ────────────────────────────────────────────────────────────────── */
    private function chartVolume30d(array $scope, array $f): array
    {
        $from = Carbon::now()->subDays(29)->startOfDay();
        $to   = Carbon::now()->endOfDay();

        // Primary screenings daily.
        $pq = DB::table('primary_screenings')->whereNull('deleted_at')
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw('DATE(captured_at) AS d, COUNT(*) AS c')
            ->groupBy(DB::raw('DATE(captured_at)'));
        $this->scope->apply($pq, $scope);
        if (! empty($f['poe'])) { $pq->where('poe_code', $f['poe']); }
        $primary = $pq->pluck('c', 'd')->all();

        // Secondary screenings daily.
        $sq = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to])
            ->selectRaw('DATE(opened_at) AS d, COUNT(*) AS c')
            ->groupBy(DB::raw('DATE(opened_at)'));
        $this->scope->apply($sq, $scope);
        if (! empty($f['poe'])) { $sq->where('poe_code', $f['poe']); }
        $secondary = $sq->pluck('c', 'd')->all();

        $labels = $pri = $sec = $csv = [];
        $cur = $from->copy();
        while ($cur <= $to) {
            $d = $cur->toDateString();
            $labels[] = $cur->format('d M');
            $pri[]    = (int) ($primary[$d]   ?? 0);
            $sec[]    = (int) ($secondary[$d] ?? 0);
            $csv[]    = [$cur->format('d M'), (int) ($primary[$d] ?? 0), (int) ($secondary[$d] ?? 0)];
            $cur->addDay();
        }
        return [
            'labels'   => $labels,
            'datasets' => [
                ['label' => 'Primary',   'data' => $pri],
                ['label' => 'Secondary', 'data' => $sec],
            ],
            'csv_headers' => ['Date', 'Primary', 'Secondary'],
            'csv_rows'    => $csv,
        ];
    }

    private function chartTopPoesByVolume(array $scope, array $f): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($f);

        // Top by primary-screening count.
        $pq = DB::table('primary_screenings')->whereNull('deleted_at')
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw('poe_code, COUNT(*) AS c')
            ->groupBy('poe_code')
            ->orderByDesc('c')
            ->limit(self::TOP_LIMIT);
        $this->scope->apply($pq, $scope);
        if (! empty($f['poe'])) { $pq->where('poe_code', $f['poe']); }
        $topRows = $pq->get();
        $codes = $topRows->pluck('poe_code')->filter()->unique()->values()->all();

        // Two-query whereIn — alert counts for the same set of poe_codes.
        $alertCounts = [];
        if ($codes) {
            $aq = DB::table('alerts')->whereNull('deleted_at')
                ->whereBetween('created_at', [$from, $to])
                ->whereIn('poe_code', $codes)
                ->selectRaw('poe_code, COUNT(*) AS c')
                ->groupBy('poe_code');
            $this->scope->apply($aq, $scope);
            $alertCounts = $aq->pluck('c', 'poe_code')->all();
        }

        // Display name dictionary — separate whereIn lookup on ref_poes.
        $names = [];
        if ($codes) {
            $names = DB::table('ref_poes')
                ->whereIn('poe_code', $codes)
                ->whereNull('deleted_at')
                ->pluck('poe_name', 'poe_code')->all();
        }

        $labels = $screens = $alerts = $csv = [];
        foreach ($topRows as $r) {
            $code = (string) ($r->poe_code ?? '');
            if ($code === '') { continue; }
            $name = $names[$code] ?? $code;
            $labels[]  = $name;
            $screens[] = (int) $r->c;
            $alerts[]  = (int) ($alertCounts[$code] ?? 0);
            $csv[]     = [$name, (int) $r->c, (int) ($alertCounts[$code] ?? 0)];
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                ['label' => 'Screenings', 'data' => $screens],
                ['label' => 'Alerts',     'data' => $alerts],
            ],
            'csv_headers' => ['POE', 'Screenings', 'Alerts'],
            'csv_rows'    => $csv,
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     * Internal — per-POE aggregate maps (all aggregation in SQL).
     * ────────────────────────────────────────────────────────────────── */
    private function scopedScreeningCountsByPoe(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        $q = DB::table('primary_screenings')->whereNull('deleted_at')
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw('poe_code, COUNT(*) AS c')
            ->groupBy('poe_code');
        $this->scope->apply($q, $scope);
        if (! empty($f['poe'])) { $q->where('poe_code', $f['poe']); }
        return $q->pluck('c', 'poe_code')->all();
    }

    private function scopedAlertCountsByPoe(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        $q = DB::table('alerts')->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('poe_code, COUNT(*) AS c')
            ->groupBy('poe_code');
        $this->scope->apply($q, $scope);
        if (! empty($f['poe'])) { $q->where('poe_code', $f['poe']); }
        return $q->pluck('c', 'poe_code')->all();
    }

    private function screeningCountsByPoeSince(array $scope, Carbon $threshold): array
    {
        $q = DB::table('primary_screenings')->whereNull('deleted_at')
            ->where('captured_at', '>=', $threshold)
            ->selectRaw('poe_code, COUNT(*) AS c')
            ->groupBy('poe_code');
        $this->scope->apply($q, $scope);
        return $q->pluck('c', 'poe_code')->all();
    }

    private function lastActivityByPoe(array $scope, array $allowedCodes): array
    {
        if (empty($allowedCodes)) { return []; }
        $q = DB::table('primary_screenings')->whereNull('deleted_at')
            ->whereIn('poe_code', $allowedCodes)
            ->selectRaw('poe_code, MAX(captured_at) AS last_act')
            ->groupBy('poe_code');
        $this->scope->apply($q, $scope);
        return $q->pluck('last_act', 'poe_code')->all();
    }

    private function officerCountsByPoe(array $scope, array $allowedCodes): array
    {
        if (empty($allowedCodes)) { return []; }
        return DB::table('user_assignments AS ua')
            ->join('users AS u', 'u.id', '=', 'ua.user_id')
            ->whereIn('ua.poe_code', $allowedCodes)
            ->where('ua.is_active', 1)
            ->where('u.is_active', 1)
            ->selectRaw('ua.poe_code, COUNT(DISTINCT ua.user_id) AS c')
            ->groupBy('ua.poe_code')
            ->pluck('c', 'poe_code')->all();
    }

    private function countCoveredPoes(array $scope, array $allowedCodes): int
    {
        if (empty($allowedCodes)) { return 0; }
        return (int) DB::table('user_assignments AS ua')
            ->join('users AS u', 'u.id', '=', 'ua.user_id')
            ->whereIn('ua.poe_code', $allowedCodes)
            ->where('ua.is_active', 1)
            ->where('u.is_active', 1)
            ->distinct()
            ->count('ua.poe_code');
    }

    /**
     * Latest non-closed status by poe_code — single grouped query, no per-POE
     * loop. Uses a self-derived subquery (latest started_at per poe).
     */
    private function currentStatusByPoe(array $allowedCodes): array
    {
        if (empty($allowedCodes)) { return []; }
        $latest = DB::table('poe_status_events')
            ->whereIn('poe_code', $allowedCodes)
            ->whereNull('ended_at')
            ->selectRaw('poe_code, MAX(started_at) AS s')
            ->groupBy('poe_code');

        // Resolve the status at that started_at via secondary query (safe — bounded).
        $rows = DB::table('poe_status_events AS e')
            ->joinSub($latest, 'l', function ($j) {
                $j->on('l.poe_code', '=', 'e.poe_code')->on('l.s', '=', 'e.started_at');
            })
            ->whereIn('e.poe_code', $allowedCodes)
            ->get(['e.poe_code', 'e.status']);
        $out = [];
        foreach ($rows as $r) { $out[$r->poe_code] = $r->status; }
        return $out;
    }

    /* ──────────────────────────────────────────────────────────────────
     * Internal — tone helpers.
     * ────────────────────────────────────────────────────────────────── */
    private function alertTone(?float $rate): string
    {
        if ($rate === null) { return 'neutral'; }
        if ($rate > 5)      { return 'danger'; }
        if ($rate > 2)      { return 'warning'; }
        return 'success';
    }

    private function coverageTone(?float $pct): string
    {
        if ($pct === null) { return 'neutral'; }
        if ($pct < 50)     { return 'danger'; }
        if ($pct < 80)     { return 'warning'; }
        return 'success';
    }

    private function emptyKpis(): array
    {
        return [
            ['key' => 'active_poes',     'label' => 'Active POEs',      'value' => '0', 'tone' => 'neutral', 'hint' => 'No POEs in scope.'],
            ['key' => 'dark_poes_7d',    'label' => 'Dark POEs (7d)',   'value' => '0', 'tone' => 'neutral', 'hint' => 'No POEs in scope.'],
            ['key' => 'alert_rate_pct',  'label' => 'Alert Rate',       'value' => '—', 'tone' => 'neutral', 'hint' => 'Insufficient denominator.'],
            ['key' => 'top_poe',         'label' => 'Top POE',          'value' => '—', 'tone' => 'neutral', 'hint' => 'No data.'],
            ['key' => 'staffing_coverage','label' => 'Officer Coverage','value' => '—', 'tone' => 'neutral', 'hint' => 'No POEs in scope.'],
        ];
    }
}
