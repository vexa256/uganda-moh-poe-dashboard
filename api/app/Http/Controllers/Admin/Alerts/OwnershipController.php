<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Alerts;

use App\Http\Controllers\Controller;
use App\Support\Alerts\HumanLabels;
use App\Support\Scope\ScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin · Section 03 · Ownership Trail.
 *
 * Three lenses on the same underlying data — alert_timeline_events with
 * event_code IN (ESCALATED, REASSIGNED, HANDOFF_SENT, HANDOFF_ACKNOWLEDGED,
 * HANDOFF_ACCEPTED, HANDOFF_REJECTED, HANDOFF_RECALLED, OPENED, ACKNOWLEDGED):
 *
 *   1. STREAM   ─ flat scope-filtered event feed, filter chips per code.
 *   2. MATRIX   ─ pivot of (current_owner_user_id × routed_to_level)
 *                 over OPEN/ACKNOWLEDGED alerts only — the now-state.
 *   3. CHAIN    ─ per-alert ownership lineage (?alert_id=) — every event
 *                 that ever changed routed_to_level OR current_owner_user_id.
 *
 * READ ONLY — every write that produces these events lives on the canonical
 * Admin\Alerts\AlertsController (escalate/reassign) or
 * AlertCollaborationController (handoffs). This surface only audits.
 *
 * Scope is applied via ScopeFilter::applyToTimelineEvents (reaches through
 * alert_id → alerts) and ScopeFilter::applyToAlerts for the matrix.
 */
final class OwnershipController extends Controller
{
    /**
     * Codes that constitute an "ownership-change" event.
     *
     * Aligned to what canonical writers actually emit (verified 2026-05-20):
     *   - ALERT_CREATED       — emitted on initial alert open (was "OPENED" — dead code)
     *   - ACKNOWLEDGED        — emitted by canonical AlertsController::acknowledge (T1.1 fix)
     *   - ESCALATED, REASSIGNED — emitted by their canonical writers
     *   - HANDOFF_*           — emitted by AlertCollaborationController
     */
    public const OWNERSHIP_CODES = [
        'ALERT_CREATED',
        'ACKNOWLEDGED',
        'ESCALATED',
        'REASSIGNED',
        'HANDOFF_SENT',
        'HANDOFF_ACKNOWLEDGED',
        'HANDOFF_ACCEPTED',
        'HANDOFF_REJECTED',
        'HANDOFF_RECALLED',
    ];

    private const MAX_PER_PAGE = 100;

    /* ═════════════════════════ views ═════════════════════════ */

    public function index(Request $r)
    {
        return view('admin.alertops.ownership.index');
    }

    /* ═════════════════════════ STREAM lens ═════════════════════════ */

    /**
     * GET /admin/alerts/ownership/data
     * Query: event_code (CSV — defaults to all OWNERSHIP_CODES)
     *        alert_id, severity (INFO|WARN|ERROR|CRITICAL),
     *        from (ISO date), to (ISO date), q (free-text on summary)
     *        cursor (event id desc), per_page (10..100)
     */
    public function data(Request $r): JsonResponse
    {
        try {
            $scope    = ScopeFilter::fromRequest($r);
            $perPage  = max(10, min(self::MAX_PER_PAGE, (int) $r->query('per_page', 50)));
            $cursor   = (int) $r->query('cursor', 0);
            $alertId  = (int) $r->query('alert_id', 0);
            $codesIn  = trim((string) $r->query('event_code', ''));
            $codes    = $codesIn !== ''
                ? array_values(array_intersect(self::OWNERSHIP_CODES, array_map('trim', explode(',', $codesIn))))
                : self::OWNERSHIP_CODES;
            if (empty($codes)) $codes = self::OWNERSHIP_CODES;

            $base = function () use ($scope, $codes, $alertId) {
                $q = DB::table('alert_timeline_events')
                    ->whereIn('alert_timeline_events.event_code', $codes);
                if ($alertId > 0) $q->where('alert_timeline_events.alert_id', $alertId);
                return ScopeFilter::applyToTimelineEvents($q, $scope);
            };

            $rowsQ = $base();
            if ($cursor > 0) $rowsQ->where('alert_timeline_events.id', '<', $cursor);

            $sev = trim((string) $r->query('severity', ''));
            if ($sev !== '' && in_array($sev, ['INFO','WARN','ERROR','CRITICAL'], true)) {
                $rowsQ->where('alert_timeline_events.severity', $sev);
            }
            $from = trim((string) $r->query('from', ''));
            $to   = trim((string) $r->query('to', ''));
            if ($from !== '') $rowsQ->where('alert_timeline_events.created_at', '>=', $from);
            if ($to   !== '') $rowsQ->where('alert_timeline_events.created_at', '<=', $to);
            $qstr = trim((string) $r->query('q', ''));
            if ($qstr !== '') {
                $like = '%' . $qstr . '%';
                $rowsQ->where('alert_timeline_events.summary', 'like', $like);
            }

            $rows = $rowsQ
                ->leftJoin('alerts as a', 'a.id', '=', 'alert_timeline_events.alert_id')
                ->leftJoin('users  as u', 'u.id', '=', 'alert_timeline_events.actor_user_id')
                ->select([
                    'alert_timeline_events.*',
                    'a.alert_code', 'a.alert_title', 'a.risk_level',
                    'a.status as alert_status', 'a.routed_to_level as alert_level',
                    'a.current_owner_user_id', 'a.district_code', 'a.poe_code',
                    'u.full_name as actor_full_name', 'u.role_key as actor_role_key',
                ])
                ->orderByDesc('alert_timeline_events.id')
                ->limit($perPage + 1)
                ->get();

            $next = null;
            if ($rows->count() > $perPage) { $tail = $rows->pop(); $next = (int) $tail->id; }

            // Per-code counters in the current scope (no extra filters except scope+codes).
            $counters = [];
            foreach (self::OWNERSHIP_CODES as $code) {
                $counters[$code] = (int) ScopeFilter::applyToTimelineEvents(
                    DB::table('alert_timeline_events')->where('event_code', $code), $scope
                )->count();
            }

            return $this->ok([
                'rows'        => $rows->map(fn ($e) => $this->castEvent($e))->all(),
                'count'       => $rows->count(),
                'next_cursor' => $next,
            ], 'Ownership stream.', [
                'counters'   => $counters,
                'codes'      => self::OWNERSHIP_CODES,
                'per_page'   => $perPage,
                'scope_label'=> $scope['label'] ?? null,
            ]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    /* ═════════════════════════ MATRIX lens ═════════════════════════ */

    /**
     * GET /admin/alerts/ownership/matrix
     * Returns a grid of {owner_user_id × routed_to_level} for ALERT.status
     * IN (OPEN, ACKNOWLEDGED) — i.e. the current load on each owner.
     *
     * Output:
     *   data.matrix  = [ {owner_user_id, owner_name, routed_to_level, count, risks: {LOW,MEDIUM,HIGH,CRITICAL}} ]
     *   data.totals  = { by_owner: {id:count}, by_level: {LEVEL:count}, by_risk: {RISK:count}, grand: int }
     */
    public function matrix(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);

            $base = ScopeFilter::applyToAlerts(
                DB::table('alerts')
                  ->whereNull('alerts.deleted_at')
                  ->whereIn('alerts.status', ['OPEN', 'ACKNOWLEDGED']),
                $scope, 'alerts'
            );

            $rows = (clone $base)
                ->leftJoin('users as u', 'u.id', '=', 'alerts.current_owner_user_id')
                ->select([
                    'alerts.current_owner_user_id as owner_user_id',
                    'alerts.routed_to_level',
                    'alerts.risk_level',
                    'u.full_name as owner_name',
                    'u.role_key  as owner_role',
                    DB::raw('COUNT(*) as c'),
                ])
                ->groupBy(
                    'alerts.current_owner_user_id', 'alerts.routed_to_level',
                    'alerts.risk_level', 'u.full_name', 'u.role_key'
                )
                ->get();

            // Pivot risk counts onto each (owner,level) cell.
            $cells = [];
            foreach ($rows as $r2) {
                $key = ($r2->owner_user_id ?? 'unassigned') . '|' . $r2->routed_to_level;
                if (! isset($cells[$key])) {
                    $cells[$key] = [
                        'owner_user_id'   => $r2->owner_user_id ? (int) $r2->owner_user_id : null,
                        'owner_name'      => $r2->owner_name ?? 'Unassigned',
                        'owner_role'      => $r2->owner_role,
                        'routed_to_level' => (string) $r2->routed_to_level,
                        'count'           => 0,
                        'risks'           => ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 0, 'CRITICAL' => 0],
                    ];
                }
                $cells[$key]['count']                    += (int) $r2->c;
                $cells[$key]['risks'][$r2->risk_level]   = ($cells[$key]['risks'][$r2->risk_level] ?? 0) + (int) $r2->c;
            }

            $matrix    = array_values($cells);
            $byOwner   = [];
            $byLevel   = ['DISTRICT' => 0, 'PHEOC' => 0, 'NATIONAL' => 0];
            $byRisk    = ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 0, 'CRITICAL' => 0];
            $grand     = 0;
            foreach ($matrix as $cell) {
                $oid = $cell['owner_user_id'] !== null ? (string) $cell['owner_user_id'] : 'unassigned';
                $byOwner[$oid] = ($byOwner[$oid] ?? 0) + $cell['count'];
                $byLevel[$cell['routed_to_level']] = ($byLevel[$cell['routed_to_level']] ?? 0) + $cell['count'];
                foreach ($cell['risks'] as $rk => $rc) { $byRisk[$rk] = ($byRisk[$rk] ?? 0) + $rc; }
                $grand += $cell['count'];
            }

            // Sort cells: highest count first, then by risk severity.
            usort($matrix, function ($a, $b) {
                if ($a['count'] !== $b['count']) return $b['count'] - $a['count'];
                $sev = ['CRITICAL' => 4, 'HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1];
                $as = ($a['risks']['CRITICAL'] ?? 0) * $sev['CRITICAL']
                    + ($a['risks']['HIGH']     ?? 0) * $sev['HIGH'];
                $bs = ($b['risks']['CRITICAL'] ?? 0) * $sev['CRITICAL']
                    + ($b['risks']['HIGH']     ?? 0) * $sev['HIGH'];
                return $bs - $as;
            });

            return $this->ok([
                'matrix' => $matrix,
                'totals' => [
                    'by_owner' => $byOwner, 'by_level' => $byLevel, 'by_risk' => $byRisk, 'grand' => $grand,
                ],
            ], 'Ownership matrix (now-state).', [
                'scope_label' => $scope['label'] ?? null,
                'computed_at' => now()->toIso8601String(),
            ]);
        } catch (Throwable $e) { return $this->serverError($e, 'matrix'); }
    }

    /* ═════════════════════════ helpers ═════════════════════════ */

    private function castEvent(object $e): array
    {
        return [
            'id'              => (int) $e->id,
            'alert_id'        => (int) $e->alert_id,
            'alert_code'      => $e->alert_code,
            'alert_title'     => $e->alert_title,
            'alert_status'    => $e->alert_status,
            'alert_level'     => $e->alert_level,
            'risk_level'      => $e->risk_level,
            'district_code'   => $e->district_code,
            'poe_code'        => $e->poe_code,
            'event_code'      => (string) $e->event_code,
            'event_category'  => (string) $e->event_category,
            'severity'        => (string) $e->severity,
            'actor_user_id'   => $e->actor_user_id ? (int) $e->actor_user_id : null,
            'actor_name'      => $e->actor_full_name ?? $e->actor_name,
            'actor_role'      => $e->actor_role_key  ?? $e->actor_role,
            'summary'         => (string) $e->summary,
            'payload'         => $e->payload_json ? json_decode($e->payload_json, true) : null,
            'created_at'      => $e->created_at,
            'human'           => [
                'title'      => match ((string) $e->event_code) {
                    'ALERT_CREATED'      => 'Alert opened',
                    'ALERT_ACKNOWLEDGED' => 'Acknowledged by an officer',
                    'ALERT_REASSIGNED'   => 'Handed to someone else',
                    'ALERT_ESCALATED'    => 'Sent higher up',
                    'ALERT_REOPENED'     => 'Case reopened',
                    'ALERT_CLOSED'       => 'Case closed',
                    'ALERT_MASTER_CLOSED'=> 'Closed on behalf of the team',
                    'ALERT_CLOSED_FALSE_ALARM' => 'Closed as a false alarm',
                    'OWNERSHIP_HANDOFF_CREATED'  => 'A handover was started',
                    'OWNERSHIP_HANDOFF_ACCEPTED' => 'A handover was accepted',
                    'OWNERSHIP_HANDOFF_REJECTED' => 'A handover was declined',
                    default              => HumanLabels::prettify((string) $e->event_code),
                },
                'when_human' => HumanLabels::dueHuman((string) $e->created_at),
                'tone'       => match ((string) $e->severity) { 'CRITICAL', 'ERROR' => 'urgent', 'WARN' => 'watch', default => 'info' },
                'risk_label' => !empty($e->risk_level) ? HumanLabels::risk((string) $e->risk_level)['short'] : null,
                'level_label'=> !empty($e->alert_level) ? HumanLabels::routedTo((string) $e->alert_level) : null,
            ],
        ];
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if(!empty($meta)){$b['meta']=$meta;} return response()->json($b); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Alerts\\Ownership][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
