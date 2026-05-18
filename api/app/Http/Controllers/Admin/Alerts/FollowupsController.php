<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Alerts;

use App\Http\Controllers\AlertFollowupsController as ApiFollowupsController;
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
 * Admin · Section 03 · Followups (RTSL 14 ledger).
 *
 * ─── DESIGN ─────────────────────────────────────────────────────────────
 * Reads — owned here. Cross-alert lens by default; per-alert lens when
 * `?alert_id=` is supplied. Tabs: Pending · In progress · Completed ·
 * Blocked · NA · Overdue · All.  Filter chips: action_code, blocks_closure,
 * due_window, assigned_to_role, district, poe.
 *
 * Writes — single-row update DELEGATES to the canonical
 * AlertFollowupsController::update.  Bulk-status iterates and forwards.
 * Both write paths additionally emit an alert_timeline_events row when the
 * status changes — the existing API does NOT emit timeline events for
 * followup transitions, so this is a strict additive audit enhancement
 * (the existing API is left UNTOUCHED).
 *
 * Auth + scope are enforced by route middleware. Writes additionally
 * require POE_OFFICER+ via route role:… group.
 * ────────────────────────────────────────────────────────────────────────
 */
final class FollowupsController extends Controller
{
    private const TABS = ['pending', 'in_progress', 'completed', 'blocked', 'na', 'overdue', 'all'];

    private const STATUSES = ['PENDING', 'IN_PROGRESS', 'COMPLETED', 'BLOCKED', 'NOT_APPLICABLE'];

    private const DUE_WINDOWS = ['next_6h', 'next_24h', 'overdue_24h', 'overdue_72h_plus'];

    /* ═════════════════════════ views ═════════════════════════ */

    public function index(Request $r)
    {
        return view('admin.alertops.followups.index');
    }

    /* ═════════════════════════ reads ═════════════════════════ */

    /**
     * GET /admin/alerts/followups/data
     * Cross-alert lens by default · per-alert lens via ?alert_id=
     */
    public function data(Request $r): JsonResponse
    {
        try {
            $scope   = ScopeFilter::fromRequest($r);
            $tab     = strtolower((string) $r->query('status', 'pending'));
            if (! in_array($tab, self::TABS, true)) $tab = 'pending';

            $alertId = (int) $r->query('alert_id', 0);

            $base = function () use ($scope, $alertId) {
                $q = DB::table('alert_followups')->whereNull('alert_followups.deleted_at');
                $q = ScopeFilter::applyToAlertFollowups($q, $scope);
                if ($alertId > 0) $q->where('alert_followups.alert_id', $alertId);
                return $q;
            };

            $rowsQ = $base();
            $this->applyTab($rowsQ, $tab);
            $this->applyFilters($rowsQ, $r);

            $rows = $rowsQ
                ->leftJoin('alerts as a', 'a.id', '=', 'alert_followups.alert_id')
                ->leftJoin('users as u', 'u.id', '=', 'alert_followups.completed_by_user_id')
                ->select([
                    'alert_followups.*',
                    'a.alert_code', 'a.alert_title', 'a.risk_level',
                    'a.status as alert_status', 'a.routed_to_level',
                    'u.full_name as completed_by_name', 'u.role_key as completed_by_role',
                ])
                ->orderByRaw("FIELD(alert_followups.status,'BLOCKED','PENDING','IN_PROGRESS','COMPLETED','NOT_APPLICABLE')")
                ->orderBy('alert_followups.due_at')
                ->limit(500)
                ->get();

            $tabs = $this->countTabs($base, $r);

            return $this->ok([
                'rows'  => $rows->map(fn ($f) => $this->castRow($f))->all(),
                'count' => $rows->count(),
            ], 'Followups.', [
                'tabs'        => $tabs,
                'scope_label' => $scope['label'] ?? null,
                'alert_id'    => $alertId ?: null,
            ]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    /** GET /admin/alerts/followups/meta */
    public function meta(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);

            $codes = DB::table('alert_followups')
                ->whereNull('deleted_at')
                ->select('action_code', 'action_label', 'blocks_closure')
                ->groupBy('action_code', 'action_label', 'blocks_closure')
                ->orderBy('action_code')
                ->get()
                ->map(fn ($r) => [
                    'action_code'    => (string) $r->action_code,
                    'action_label'   => (string) $r->action_label,
                    'blocks_closure' => (bool) $r->blocks_closure,
                ])->all();

            $distQ = DB::table('ref_districts')->whereNull('deleted_at');
            $distQ = ScopeFilter::applyToDistricts($distQ, $scope);
            $districts = $distQ->orderBy('name')->pluck('name')->all();

            $poeQ = DB::table('ref_poes')->whereNull('deleted_at');
            $poeQ = ScopeFilter::applyToPoes($poeQ, $scope);
            $poes = $poeQ->orderBy('display_order')->orderBy('poe_name')
                ->get(['poe_code', 'poe_name', 'admin_level_1', 'district'])
                ->map(fn ($p) => [
                    'poe_code' => (string) $p->poe_code, 'poe_name' => (string) $p->poe_name,
                    'province' => (string) $p->admin_level_1, 'district' => (string) $p->district,
                ])->all();

            return $this->ok([
                'tabs'         => self::TABS,
                'statuses'     => self::STATUSES,
                'due_windows'  => self::DUE_WINDOWS,
                'action_codes' => $codes,
                'districts'    => $districts,
                'poes'         => $poes,
            ], 'Meta.');
        } catch (Throwable $e) { return $this->serverError($e, 'meta'); }
    }

    /* ═════════════════════════ writes ═════════════════════════ */

    /**
     * PATCH /admin/alerts/followups/{id}
     * Wraps AlertFollowupsController::update — injects user_id from session,
     * captures before-state for audit, emits a timeline event on status
     * transitions (the existing API does not emit; this is a strict addition).
     */
    public function update(Request $r, int $id): JsonResponse
    {
        try {
            $before = DB::table('alert_followups')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $before) return $this->err(404, 'Followup not found.');

            // Scope guard against the parent alert.
            $alert = DB::table('alerts')->where('id', $before->alert_id)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Parent alert not found.');
            if (! ScopeFilter::canSeeAlert(ScopeFilter::fromRequest($r), $alert)) {
                return $this->err(403, 'Followup is outside your scope.');
            }

            $userId = (int) ($r->user()->id ?? 0);
            if ($userId <= 0) return $this->err(422, 'No authenticated user.');

            // Forward to canonical update (it pulls user_id from input).
            $r->merge(['user_id' => $userId]);
            $resp = app(ApiFollowupsController::class)->update($r, $id);

            // If the canonical call succeeded and the status changed, emit a
            // timeline event. Distinguish FOLLOWUP_COMPLETED / BLOCKED /
            // IN_PROGRESS to mirror dashboard.txt event-code coverage.
            $body = json_decode($resp->getContent(), true);
            if (($body['success'] ?? false) === true) {
                $after = DB::table('alert_followups')->where('id', $id)->first();
                if ($after && $after->status !== $before->status) {
                    $event = match ($after->status) {
                        'COMPLETED'      => 'FOLLOWUP_COMPLETED',
                        'BLOCKED'        => 'FOLLOWUP_BLOCKED',
                        'IN_PROGRESS'    => 'FOLLOWUP_IN_PROGRESS',
                        'NOT_APPLICABLE' => 'FOLLOWUP_NOT_APPLICABLE',
                        default          => 'FOLLOWUP_UPDATED',
                    };
                    $severity = $after->status === 'BLOCKED' ? 'WARN' : 'INFO';
                    $this->emitTimeline(
                        (int) $after->alert_id, $event, 'WORKFLOW', $userId,
                        sprintf('Followup [%s] %s → %s%s',
                            $after->action_code, $before->status, $after->status,
                            $after->blocks_closure ? ' (blocks closure)' : ''),
                        [
                            'followup_id'    => (int) $id,
                            'action_code'    => $after->action_code,
                            'before_status'  => $before->status,
                            'after_status'   => $after->status,
                            'blocks_closure' => (bool) $after->blocks_closure,
                            'notes'          => $after->notes,
                        ],
                        'FOLLOWUP', (int) $id, $severity
                    );

                    // §3.2 — fan-out user email when a follow-up changes state.
                    // BLOCKED is the highest-impact state (it freezes closure)
                    // so we promote that into its own action_code so
                    // recipients can route on it. All other transitions share
                    // the ALERT_FOLLOWUP_STATUS namespace.
                    try {
                        $actionCode = $after->status === 'BLOCKED'
                            ? 'ALERT_FOLLOWUP_BLOCKED'
                            : ($after->status === 'COMPLETED'
                                ? 'ALERT_FOLLOWUP_COMPLETED'
                                : 'ALERT_FOLLOWUP_STATUS');
                        $headline = sprintf('Followup [%s] %s → %s',
                            $after->action_code, $before->status, $after->status);
                        $context  = sprintf("Action: %s\nFrom: %s → To: %s%s\nNotes: %s",
                            (string) ($after->action_label ?? $after->action_code),
                            $before->status, $after->status,
                            (int) $after->blocks_closure === 1 ? ' (BLOCKS closure)' : '',
                            mb_substr((string) ($after->notes ?? '(none)'), 0, 400)
                        );
                        \App\Services\NotificationDispatcher::dispatchUserEvent(
                            $alert, $actionCode, $headline, $context, $userId
                        );
                    } catch (Throwable $fe) {
                        Log::warning('[Admin\\Alerts\\Followups][update][fanout] ' . $fe->getMessage());
                    }
                }
            }
            return $resp;
        } catch (Throwable $e) { return $this->serverError($e, 'update'); }
    }

    /**
     * POST /admin/alerts/followups/bulk-status
     * Body: { ids: [int...], status: ENUM, notes?: string }
     *
     * Iterates ids; per row applies the same scope guard + delegated update +
     * timeline emission. Reports per-id success/failure so the UI can render
     * a partial-success toast.
     */
    public function bulkStatus(Request $r): JsonResponse
    {
        $userId = (int) ($r->user()->id ?? 0);
        if ($userId <= 0) return $this->err(422, 'No authenticated user.');

        $ids    = array_values(array_filter(array_map('intval', (array) $r->input('ids', []))));
        $status = strtoupper((string) $r->input('status', ''));
        $notes  = trim((string) $r->input('notes', ''));

        if (empty($ids))                                        return $this->err(422, 'ids[] is required.');
        if (count($ids) > 200)                                  return $this->err(422, 'Max 200 ids per request.');
        if (! in_array($status, self::STATUSES, true))          return $this->err(422, 'Invalid status.', ['allowed' => self::STATUSES]);

        $okIds = []; $failed = [];
        foreach ($ids as $id) {
            $sub = clone $r;
            $sub->replace([
                'user_id' => $userId,
                'status'  => $status,
            ] + ($notes !== '' ? ['notes' => $notes] : []));
            try {
                $resp = $this->update($sub, $id);
                $body = json_decode($resp->getContent(), true);
                if (($body['success'] ?? false) === true) { $okIds[] = $id; }
                else { $failed[] = ['id' => $id, 'reason' => $body['message'] ?? 'unknown']; }
            } catch (Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }
        return $this->ok([
            'updated_ids' => $okIds, 'failed' => $failed,
            'updated'     => count($okIds), 'failed_count' => count($failed),
        ], sprintf('%d updated · %d failed.', count($okIds), count($failed)));
    }

    /* ═════════════════════════ helpers ═════════════════════════ */

    private function applyTab($q, string $tab): void
    {
        $now = Carbon::now()->toDateTimeString();
        switch ($tab) {
            case 'pending':       $q->where('alert_followups.status', 'PENDING'); break;
            case 'in_progress':   $q->where('alert_followups.status', 'IN_PROGRESS'); break;
            case 'completed':     $q->where('alert_followups.status', 'COMPLETED'); break;
            case 'blocked':       $q->where('alert_followups.status', 'BLOCKED'); break;
            case 'na':            $q->where('alert_followups.status', 'NOT_APPLICABLE'); break;
            case 'overdue':
                $q->whereIn('alert_followups.status', ['PENDING', 'IN_PROGRESS', 'BLOCKED'])
                  ->whereNotNull('alert_followups.due_at')
                  ->where('alert_followups.due_at', '<', $now);
                break;
            case 'all':
            default: break;
        }
    }

    private function applyFilters($q, Request $r): void
    {
        if (($v = trim((string) $r->query('action_code', ''))) !== '') {
            $q->where('alert_followups.action_code', $v);
        }
        if ($r->query('blocks_closure') !== null && $r->query('blocks_closure') !== '') {
            $q->where('alert_followups.blocks_closure', (int) (bool) $r->query('blocks_closure'));
        }
        if (($v = trim((string) $r->query('district', ''))) !== '') {
            $q->where('alert_followups.district_code', $v);
        }
        if (($v = trim((string) $r->query('poe', ''))) !== '') {
            $q->where('alert_followups.poe_code', $v);
        }
        if (($v = trim((string) $r->query('assigned_to_role', ''))) !== '') {
            $q->where('alert_followups.assigned_to_role', $v);
        }
        if (($w = trim((string) $r->query('due_window', ''))) !== '' && in_array($w, self::DUE_WINDOWS, true)) {
            $now  = Carbon::now();
            switch ($w) {
                case 'next_6h':         $q->whereBetween('alert_followups.due_at', [$now->toDateTimeString(), $now->copy()->addHours(6)->toDateTimeString()]); break;
                case 'next_24h':        $q->whereBetween('alert_followups.due_at', [$now->toDateTimeString(), $now->copy()->addHours(24)->toDateTimeString()]); break;
                case 'overdue_24h':     $q->whereNotNull('alert_followups.due_at')->whereBetween('alert_followups.due_at', [$now->copy()->subHours(24)->toDateTimeString(), $now->toDateTimeString()]); break;
                case 'overdue_72h_plus':$q->whereNotNull('alert_followups.due_at')->where('alert_followups.due_at', '<', $now->copy()->subHours(72)->toDateTimeString()); break;
            }
        }
        if (($q_str = trim((string) $r->query('q', ''))) !== '') {
            $like = '%' . $q_str . '%';
            $q->where(function ($w) use ($like) {
                $w->where('alert_followups.action_label','like',$like)
                  ->orWhere('alert_followups.notes','like',$like)
                  ->orWhere('alert_followups.evidence_ref','like',$like);
            });
        }
    }

    private function countTabs(callable $base, Request $r): array
    {
        $count = function (string $tab) use ($base, $r) {
            $q = $base();
            $this->applyTab($q, $tab);
            $this->applyFilters($q, $r);
            return $q->count();
        };
        return [
            'pending'     => $count('pending'),
            'in_progress' => $count('in_progress'),
            'completed'   => $count('completed'),
            'blocked'     => $count('blocked'),
            'na'          => $count('na'),
            'overdue'     => $count('overdue'),
            'all'         => $count('all'),
        ];
    }

    private function castRow(object $f): array
    {
        $now = Carbon::now();
        $due = $f->due_at ? Carbon::parse($f->due_at) : null;
        $minutesToDue = $due ? (int) $now->diffInMinutes($due, false) : null;
        $isOverdue = $due && in_array($f->status, ['PENDING','IN_PROGRESS','BLOCKED'], true) && $due->isPast();

        $base = [
            'id'                 => (int) $f->id,
            'alert_id'           => (int) $f->alert_id,
            'alert_code'         => $f->alert_code  ?? null,
            'alert_title'        => $f->alert_title ?? null,
            'alert_status'       => $f->alert_status ?? null,
            'risk_level'         => $f->risk_level  ?? null,
            'routed_to_level'    => $f->routed_to_level ?? null,
            'action_code'        => (string) $f->action_code,
            'action_label'       => (string) $f->action_label,
            'status'             => (string) $f->status,
            'blocks_closure'     => (bool) $f->blocks_closure,
            'due_at'             => $f->due_at,
            'minutes_to_due'     => $minutesToDue,
            'is_overdue'         => $isOverdue,
            'started_at'         => $f->started_at,
            'completed_at'       => $f->completed_at,
            'completed_by_name'  => $f->completed_by_name ?? null,
            'completed_by_role'  => $f->completed_by_role ?? null,
            'assigned_to_role'   => $f->assigned_to_role,
            'notes'              => $f->notes,
            'evidence_ref'       => $f->evidence_ref,
            'district_code'      => (string) $f->district_code,
            'poe_code'           => (string) $f->poe_code,
            'updated_at'         => $f->updated_at,
        ];

        $cast = HumanLabels::wrapFollowup($base);

        // Augment with parent-alert overlays for the cross-alert lens.
        if (!empty($f->risk_level)) {
            $risk = HumanLabels::risk((string) $f->risk_level);
            $cast['human']['alert_risk_label'] = $risk['short'];
            $cast['human']['alert_risk_tone']  = $risk['tone'];
        }
        if (!empty($f->alert_status)) {
            $st = HumanLabels::alertStatus((string) $f->alert_status);
            $cast['human']['alert_status_label'] = $st['label'];
        }
        if (!empty($f->routed_to_level)) {
            $cast['human']['alert_routed_to'] = HumanLabels::routedTo((string) $f->routed_to_level);
        }
        if ($isOverdue) {
            $cast['human']['status_label'] = 'Overdue';
            $cast['human']['status_tone']  = 'urgent';
        }

        return $cast;
    }

    private function emitTimeline(int $alertId, string $eventCode, string $category, ?int $actorId,
        string $summary, array $payload = [], ?string $relType = null, ?int $relId = null, string $severity = 'INFO'): void
    {
        try {
            $actor = $actorId ? DB::table('users')->where('id', $actorId)->first(['full_name', 'role_key']) : null;
            DB::table('alert_timeline_events')->insert([
                'alert_id'            => $alertId,
                'event_code'          => $eventCode,
                'event_category'      => $category,
                'actor_user_id'       => $actorId,
                'actor_name'          => $actor->full_name ?? null,
                'actor_role'          => $actor->role_key  ?? null,
                'payload_json'        => json_encode($payload),
                'summary'             => mb_substr($summary, 0, 500),
                'severity'            => $severity,
                'related_entity_type' => $relType,
                'related_entity_id'   => $relId,
                'created_at'          => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('[Admin\\Alerts\\Followups][emitTimeline] ' . $e->getMessage());
        }
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if (!empty($meta)) {$b['meta'] = $meta;} return response()->json($b); }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    { return response()->json(['success'=>false,'message'=>$message,'error'=>$detail], $status); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Alerts\\Followups][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
