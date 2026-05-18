<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Alerts;

use App\Http\Controllers\AlertCollaborationController;
use App\Http\Controllers\Controller;
use App\Support\Alerts\HumanLabels;
use App\Support\Scope\ScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin · Section 03 · SLA & Breaches.
 *
 *   data()      — at-risk feed: every OPEN/ACKNOWLEDGED alert with computed
 *                 elapsed/remaining per phase. Sortable by remaining_minutes.
 *   aggregate() — district / phase / risk_level / root-cause counters for
 *                 the SLA dashboard rings.
 *   reports()   — list alert_breach_reports (filterable + scope-aware).
 *   logBreach + updateBreach — DELEGATE to AlertCollaborationController so
 *                 the existing emitTimeline + payload structure is preserved.
 */
final class SlaController extends Controller
{
    /** dashboard.txt §B.6 7-1-7 SLA matrix (must stay in sync with ScanSlaBreaches). */
    public const SLA_HOURS = [
        'CRITICAL' => ['DETECT' => 4,  'NOTIFY' => 24, 'RESPOND' => 15 * 24],
        'HIGH'     => ['DETECT' => 24, 'NOTIFY' => 48, 'RESPOND' => 15 * 24],
        'MEDIUM'   => ['DETECT' => 48, 'NOTIFY' => 72, 'RESPOND' => 15 * 24],
        'LOW'      => ['DETECT' => 7 * 24, 'NOTIFY' => 14 * 24, 'RESPOND' => 30 * 24],
    ];

    private const PHASES = ['DETECT', 'NOTIFY', 'RESPOND'];
    private const RCA_STATUSES = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CANCELLED'];

    /* ═════════════════════════ views ═════════════════════════ */

    public function index(Request $r)
    {
        return view('admin.alertops.sla.index');
    }

    /* ═════════════════════════ READS ═════════════════════════ */

    /**
     * GET /admin/alerts/sla/data
     * At-risk + breached open/ack alerts. Filters: phase, risk_level, district,
     * poe, only_breached (1).
     */
    public function data(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $now   = Carbon::now();

            $base = ScopeFilter::applyToAlerts(
                DB::table('alerts')
                    ->whereNull('alerts.deleted_at')
                    ->whereIn('alerts.status', ['OPEN', 'ACKNOWLEDGED']),
                $scope, 'alerts'
            );

            if (($v = trim((string) $r->query('risk_level', ''))) !== '') $base->where('alerts.risk_level', $v);
            if (($v = trim((string) $r->query('district',   ''))) !== '') $base->where('alerts.district_code', $v);
            if (($v = trim((string) $r->query('poe',        ''))) !== '') $base->where('alerts.poe_code', $v);
            if (($v = trim((string) $r->query('routed_to_level',''))) !== '') $base->where('alerts.routed_to_level', $v);

            // Pull all candidates (≤500 to keep the page responsive); compute SLA in PHP
            // since the 7-1-7 maths cannot be expressed cleanly in MySQL across enums.
            $alerts = $base->orderBy('alerts.created_at')->limit(500)->get();

            $rows = [];
            $breachedCount = 0; $atRiskCount = 0;
            foreach ($alerts as $a) {
                $sla = $this->computeSla($a, $now);
                $row = $this->castRow($a, $sla);
                if ($sla['any_breached']) $breachedCount++;
                elseif ($sla['any_at_risk']) $atRiskCount++;
                $rows[] = $row;
            }

            // Sort: breached first, then by smallest remaining minutes.
            usort($rows, function ($a, $b) {
                if ($a['any_breached'] !== $b['any_breached']) return $a['any_breached'] ? -1 : 1;
                return ($a['min_remaining_minutes'] ?? PHP_INT_MAX) <=> ($b['min_remaining_minutes'] ?? PHP_INT_MAX);
            });

            $onlyBreached = (bool) $r->query('only_breached', 0);
            if ($onlyBreached) $rows = array_values(array_filter($rows, fn ($r) => $r['any_breached']));

            return $this->ok([
                'rows'   => $rows,
                'count'  => count($rows),
            ], 'SLA at-risk feed.', [
                'totals' => [
                    'breached'  => $breachedCount,
                    'at_risk'   => $atRiskCount,
                    'open_ack'  => count($alerts),
                ],
                'matrix' => self::SLA_HOURS,
                'scope_label' => $scope['label'] ?? null,
                'computed_at' => $now->toIso8601String(),
            ]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    /**
     * GET /admin/alerts/sla/aggregate
     * Roll-up counters for the SLA dashboard rings:
     *   per_district[district][phase] = { open, breached }
     *   per_phase[phase]              = { open, breached }
     *   per_risk[risk]                = { open, breached }
     *   per_root_cause[cat]           = count
     *   reports_by_status             = { OPEN, IN_PROGRESS, RESOLVED, CANCELLED }
     */
    public function aggregate(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $now = Carbon::now();

            // Per-phase / per-risk / per-district open + breached counts via the live SLA matrix.
            $alerts = ScopeFilter::applyToAlerts(
                DB::table('alerts')->whereNull('deleted_at')->whereIn('status', ['OPEN', 'ACKNOWLEDGED']),
                $scope, 'alerts'
            )->limit(2000)->get();

            $perPhase    = ['DETECT' => ['open' => 0, 'breached' => 0], 'NOTIFY' => ['open' => 0, 'breached' => 0], 'RESPOND' => ['open' => 0, 'breached' => 0]];
            $perRisk     = ['LOW' => ['open' => 0, 'breached' => 0], 'MEDIUM' => ['open' => 0, 'breached' => 0], 'HIGH' => ['open' => 0, 'breached' => 0], 'CRITICAL' => ['open' => 0, 'breached' => 0]];
            $perDistrict = [];
            foreach ($alerts as $a) {
                $sla = $this->computeSla($a, $now);
                $perRisk[$a->risk_level]['open']++;
                if ($sla['any_breached']) $perRisk[$a->risk_level]['breached']++;
                if (! isset($perDistrict[$a->district_code])) $perDistrict[$a->district_code] = ['open' => 0, 'breached' => 0];
                $perDistrict[$a->district_code]['open']++;
                if ($sla['any_breached']) $perDistrict[$a->district_code]['breached']++;
                foreach ($sla['phases'] as $p => $ph) {
                    $perPhase[$p]['open']++;
                    if ($ph['breached']) $perPhase[$p]['breached']++;
                }
            }

            // Root-cause aggregation from filed breach_reports (scope-filtered).
            $rcaQ = ScopeFilter::applyToBreachReports(
                DB::table('alert_breach_reports'), $scope, 'alert_breach_reports'
            );
            $perRootCause = $rcaQ->select('root_cause_category', DB::raw('COUNT(*) as c'))
                ->groupBy('root_cause_category')->pluck('c', 'root_cause_category')->all();
            $reportsByStatus = (clone $rcaQ)->select('status', DB::raw('COUNT(*) as c'))
                ->groupBy('status')->pluck('c', 'status')->all();

            return $this->ok([
                'per_phase'         => $perPhase,
                'per_risk'          => $perRisk,
                'per_district'      => $perDistrict,
                'per_root_cause'    => $perRootCause,
                'reports_by_status' => $reportsByStatus,
                'computed_at'       => $now->toIso8601String(),
            ], 'SLA aggregate.');
        } catch (Throwable $e) { return $this->serverError($e, 'aggregate'); }
    }

    /**
     * GET /admin/alerts/sla/reports
     * Scope-filtered list of alert_breach_reports.
     */
    public function reports(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $tab = strtoupper((string) $r->query('status', 'OPEN'));
            if ($tab !== 'ALL' && ! in_array($tab, self::RCA_STATUSES, true)) $tab = 'OPEN';

            $base = function () use ($scope) {
                return ScopeFilter::applyToBreachReports(
                    DB::table('alert_breach_reports'), $scope, 'alert_breach_reports'
                );
            };
            $rowsQ = $base();
            if ($tab !== 'ALL') $rowsQ->where('alert_breach_reports.status', $tab);
            if (($v = trim((string) $r->query('phase', ''))) !== '' && in_array($v, self::PHASES, true)) $rowsQ->where('alert_breach_reports.phase', $v);

            $rows = $rowsQ
                ->leftJoin('alerts as a', 'a.id', '=', 'alert_breach_reports.alert_id')
                ->leftJoin('users as u', 'u.id', '=', 'alert_breach_reports.owner_user_id')
                ->select([
                    'alert_breach_reports.*',
                    'a.alert_code', 'a.alert_title', 'a.risk_level', 'a.status as alert_status', 'a.district_code', 'a.poe_code',
                    'u.full_name as owner_name', 'u.role_key as owner_role',
                ])
                ->orderByDesc('alert_breach_reports.id')
                ->limit(500)->get();

            $tabs = [];
            foreach (array_merge(self::RCA_STATUSES, ['ALL']) as $s) {
                $q = $base();
                if ($s !== 'ALL') $q->where('alert_breach_reports.status', $s);
                $tabs[strtolower($s)] = (int) $q->count();
            }

            return $this->ok([
                'rows'  => $rows->map(fn ($r) => (array) $r)->all(),
                'count' => $rows->count(),
            ], 'Breach reports.', ['tabs' => $tabs]);
        } catch (Throwable $e) { return $this->serverError($e, 'reports'); }
    }

    /* ═════════════════════════ WRITES (delegated, scope-guarded) ═════════════════════════ */

    /** POST /admin/alerts/{id}/breach-reports — log a manual breach RCA. */
    public function logBreach(Request $r, int $id): JsonResponse
    {
        try {
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Alert not found.');
            if (! ScopeFilter::canSeeAlert(ScopeFilter::fromRequest($r), $alert)) {
                return $this->err(403, 'Alert is outside your scope.');
            }
            $this->injectActor($r);
            return app(AlertCollaborationController::class)->logBreachReport($r, $id);
        } catch (Throwable $e) { return $this->serverError($e, 'logBreach'); }
    }

    /** PATCH /admin/alerts/breach-reports/{id} */
    public function updateBreach(Request $r, int $id): JsonResponse
    {
        try {
            $row = DB::table('alert_breach_reports')->where('id', $id)->first();
            if (! $row) return $this->err(404, 'Breach report not found.');
            $alert = DB::table('alerts')->where('id', $row->alert_id)->first();
            if (! $alert || ! ScopeFilter::canSeeAlert(ScopeFilter::fromRequest($r), $alert)) {
                return $this->err(403, 'Breach report is outside your scope.');
            }
            $this->injectActor($r);
            return app(AlertCollaborationController::class)->updateBreachReport($r, $id);
        } catch (Throwable $e) { return $this->serverError($e, 'updateBreach'); }
    }

    /**
     * Inject the session user into the request so the downstream
     * AlertCollaborationController::actorId() (which reads X-User-Id /
     * actor_user_id) resolves to the authenticated admin.
     *
     * Also pre-fill owner_user_id when the caller didn't provide one — the
     * downstream's logBreachReport schema requires a NOT NULL owner.
     */
    private function injectActor(Request $r): void
    {
        $actorId = (int) ($r->user()->id ?? 0);
        if ($actorId <= 0) return;
        $r->headers->set('X-User-Id', (string) $actorId);
        $merge = ['actor_user_id' => $actorId, 'user_id' => $actorId];
        if (! $r->filled('owner_user_id'))  $merge['owner_user_id']  = $actorId;
        if (! $r->filled('owner_level'))    $merge['owner_level']    = $r->user()->role_key === 'NATIONAL_ADMIN' ? 'NATIONAL'
                                                                      : ($r->user()->role_key === 'PHEOC_OFFICER' ? 'PHEOC' : 'DISTRICT');
        $r->merge($merge);
    }

    /* ═════════════════════════ helpers ═════════════════════════ */

    /** SLA computation — must mirror ScanSlaBreaches matrix exactly. */
    private function computeSla(object $a, Carbon $now): array
    {
        $matrix = self::SLA_HOURS[$a->risk_level] ?? self::SLA_HOURS['MEDIUM'];

        $created = Carbon::parse($a->created_at);
        $ackAt   = $a->acknowledged_at ? Carbon::parse($a->acknowledged_at) : null;

        $detectElapsedH  = $ackAt ? $created->diffInMinutes($ackAt) / 60 : $created->diffInMinutes($now) / 60;
        $notifyAnchor    = $ackAt ?? $created;
        $notifyElapsedH  = $notifyAnchor->diffInMinutes($now) / 60;
        $respondElapsedH = $created->diffInMinutes($now) / 60;

        $phases = [];
        $minRemaining = null;
        foreach ([
            ['DETECT',  $detectElapsedH,  $matrix['DETECT'],  ! $ackAt],
            ['NOTIFY',  $notifyElapsedH,  $matrix['NOTIFY'],  true],
            ['RESPOND', $respondElapsedH, $matrix['RESPOND'], true],
        ] as [$name, $elapsed, $target, $applies]) {
            if (! $applies) {
                $phases[$name] = ['elapsed_h' => round($elapsed, 2), 'target_h' => $target,
                    'percent' => null, 'remaining_min' => null, 'breached' => false, 'at_risk' => false];
                continue;
            }
            $remainingMin = (int) round(($target - $elapsed) * 60);
            $percent      = $target > 0 ? min(100, (int) round(($elapsed / $target) * 100)) : 0;
            $breached     = $elapsed > $target;
            $atRisk       = $percent >= 75;
            $phases[$name] = ['elapsed_h' => round($elapsed, 2), 'target_h' => $target,
                'percent' => $percent, 'remaining_min' => $remainingMin, 'breached' => $breached, 'at_risk' => $atRisk];
            if ($remainingMin >= 0 && ($minRemaining === null || $remainingMin < $minRemaining)) {
                $minRemaining = $remainingMin;
            }
        }

        $anyBreached = (bool) array_filter($phases, fn ($p) => $p['breached']);
        $anyAtRisk   = (bool) array_filter($phases, fn ($p) => $p['at_risk'] && ! $p['breached']);

        return [
            'risk_level' => $a->risk_level,
            'phases'     => $phases,
            'any_breached' => $anyBreached,
            'any_at_risk'  => $anyAtRisk,
            'min_remaining_minutes' => $minRemaining,
        ];
    }

    private function castRow(object $a, array $sla): array
    {
        $base = [
            'id'                   => (int) $a->id,
            'alert_code'           => (string) $a->alert_code,
            'alert_title'          => (string) $a->alert_title,
            'risk_level'           => (string) $a->risk_level,
            'status'               => (string) $a->status,
            'routed_to_level'      => (string) $a->routed_to_level,
            'district_code'        => (string) $a->district_code,
            'poe_code'             => (string) $a->poe_code,
            'created_at'           => $a->created_at,
            'acknowledged_at'      => $a->acknowledged_at,
            'phases'               => $sla['phases'],
            'any_breached'         => $sla['any_breached'],
            'any_at_risk'          => $sla['any_at_risk'],
            'min_remaining_minutes'=> $sla['min_remaining_minutes'],
        ];

        $cast = HumanLabels::wrapAlert($base);

        // Human SLA tone for the page chips.
        if ($sla['any_breached']) {
            $cast['human']['sla_label'] = 'Past deadline';
            $cast['human']['sla_tone']  = 'urgent';
        } elseif ($sla['any_at_risk']) {
            $cast['human']['sla_label'] = 'About to be late';
            $cast['human']['sla_tone']  = 'watch';
        } else {
            $cast['human']['sla_label'] = 'On track';
            $cast['human']['sla_tone']  = 'done';
        }

        return $cast;
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if(!empty($meta)){$b['meta']=$meta;} return response()->json($b); }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    { return response()->json(['success'=>false,'message'=>$message,'error'=>$detail], $status); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Alerts\\Sla][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
