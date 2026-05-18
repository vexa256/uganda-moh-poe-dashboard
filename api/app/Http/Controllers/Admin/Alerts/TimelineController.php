<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Alerts;

use App\Http\Controllers\Controller;
use App\Support\Scope\ScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin · Alert Operations · Case History (alert-timeline).
 *
 * Read-only cross-case lens over alert_timeline_events. The per-alert
 * dossier (admin.alerts.case-file) is the canonical anchor for one-case
 * history; this view gives operators the cross-case stream + by-event-
 * type aggregates + search.
 *
 * Writes are NOT exposed here — every event row is produced by the
 * canonical mutation paths (AlertsController, FollowupsController,
 * CaseRoomController, etc.) via the TimelineRecorder service. The
 * timeline is append-only by design.
 *
 * Scope is enforced through ScopeFilter::applyToTimelineEvents which
 * joins the events to the parent alert and restricts to the user's
 * geography. NATIONAL_ADMIN sees all; PHEOC its province + below;
 * DISTRICT its district + below; POE its POE.
 */
final class TimelineController extends Controller
{
    private const PER_PAGE = 80;
    private const MAX_PER_PAGE = 200;

    public function index(Request $r)
    {
        return view('admin.alertops.timeline.index');
    }

    /**
     * GET /admin/alerts/timeline/data
     * Stream of recent timeline events filtered by category / severity /
     * event_code / alert_id / search / window. Cursor-paginated.
     */
    public function data(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);

            $perPage = (int) $r->query('per_page', (string) self::PER_PAGE);
            if ($perPage < 1)              $perPage = self::PER_PAGE;
            if ($perPage > self::MAX_PER_PAGE) $perPage = self::MAX_PER_PAGE;

            $cursor = (int) $r->query('cursor', '0');

            $q = DB::table('alert_timeline_events as e')
                ->leftJoin('alerts as a', 'a.id', '=', 'e.alert_id');
            $q = ScopeFilter::applyToTimelineEvents($q, $scope, 'e');

            // ── filters ─────────────────────────────────────────────
            if (($v = trim((string) $r->query('alert_id', ''))) !== '') {
                $q->where('e.alert_id', (int) $v);
            }
            $codes = array_values(array_filter(array_map('trim', explode(',', (string) $r->query('event_code', '')))));
            if (!empty($codes)) {
                $q->whereIn('e.event_code', $codes);
            }
            $cats = array_values(array_filter(array_map('trim', explode(',', (string) $r->query('category', '')))));
            if (!empty($cats)) {
                $q->whereIn('e.event_category', $cats);
            }
            if (($v = trim((string) $r->query('severity', ''))) !== '') {
                $q->where('e.severity', strtoupper($v));
            }
            if (($v = trim((string) $r->query('actor_user_id', ''))) !== '') {
                $q->where('e.actor_user_id', (int) $v);
            }
            if (($v = trim((string) $r->query('district', ''))) !== '') {
                $q->where('a.district_code', $v);
            }
            if (($v = trim((string) $r->query('poe', ''))) !== '') {
                $q->where('a.poe_code', $v);
            }
            // Date window — accepts ISO date or "past_24h" / "past_7d" / "past_14d" / "past_30d".
            $window = trim((string) $r->query('window', ''));
            if ($window !== '') {
                $now = Carbon::now();
                $cut = match ($window) {
                    'past_24h' => $now->copy()->subHours(24),
                    'past_7d'  => $now->copy()->subDays(7),
                    'past_14d' => $now->copy()->subDays(14),
                    'past_30d' => $now->copy()->subDays(30),
                    default    => null,
                };
                if ($cut) $q->where('e.created_at', '>=', $cut);
            }
            if (($qq = trim((string) $r->query('q', ''))) !== '') {
                $like = '%' . $qq . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('e.summary', 'like', $like)
                      ->orWhere('a.alert_code', 'like', $like)
                      ->orWhere('a.alert_title', 'like', $like)
                      ->orWhere('e.actor_name', 'like', $like);
                });
            }
            if ($cursor > 0) $q->where('e.id', '<', $cursor);

            $rows = $q
                ->select([
                    'e.id', 'e.alert_id', 'e.event_code', 'e.event_category',
                    'e.actor_user_id', 'e.actor_name', 'e.actor_role',
                    'e.summary', 'e.severity', 'e.payload_json',
                    'e.related_entity_type', 'e.related_entity_id',
                    'e.created_at',
                    'a.alert_code', 'a.alert_title',
                    'a.risk_level', 'a.status as alert_status', 'a.routed_to_level',
                    'a.poe_code', 'a.district_code',
                ])
                ->orderByDesc('e.id')
                ->limit($perPage + 1)
                ->get();

            $next = null;
            if ($rows->count() > $perPage) {
                $next = (int) $rows[$perPage - 1]->id;
                $rows = $rows->slice(0, $perPage)->values();
            }

            return $this->ok([
                'rows'        => $rows->map(fn ($e) => $this->castEvent($e))->all(),
                'count'       => $rows->count(),
                'next_cursor' => $next,
            ], 'Timeline.', [
                'scope_label' => $scope['label'] ?? null,
                'window'      => $window ?: null,
            ]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    /**
     * GET /admin/alerts/timeline/meta
     * Distinct event codes / categories / severities present in scope, plus
     * counters for the chip bar.
     */
    public function meta(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);

            $base = function () use ($scope) {
                $q = DB::table('alert_timeline_events as e');
                return ScopeFilter::applyToTimelineEvents($q, $scope, 'e');
            };

            $codes = $base()
                ->select('e.event_code', DB::raw('COUNT(*) as c'))
                ->groupBy('e.event_code')
                ->orderByDesc('c')
                ->limit(40)
                ->get()
                ->map(fn ($r) => ['code' => (string) $r->event_code, 'count' => (int) $r->c, 'label' => self::humanizeCode((string) $r->event_code)])
                ->all();

            $cats = $base()
                ->select('e.event_category', DB::raw('COUNT(*) as c'))
                ->groupBy('e.event_category')
                ->orderByDesc('c')
                ->get()
                ->map(fn ($r) => [
                    'code'  => (string) $r->event_category,
                    'count' => (int) $r->c,
                    'label' => self::categoryLabel((string) $r->event_category),
                ])->all();

            // Counters bucketed by 24h-window so the operator can pick "today" vs "this week" at a glance.
            $now = Carbon::now();
            $counters = [
                'past_24h' => (clone $base())->where('e.created_at', '>=', $now->copy()->subHours(24))->count(),
                'past_7d'  => (clone $base())->where('e.created_at', '>=', $now->copy()->subDays(7))->count(),
                'past_30d' => (clone $base())->where('e.created_at', '>=', $now->copy()->subDays(30))->count(),
                'all'      => (clone $base())->count(),
            ];

            return $this->ok([
                'event_codes' => $codes,
                'categories'  => $cats,
                'severities'  => ['INFO','WARN','ERROR','CRITICAL'],
                'counters'    => $counters,
                'scope_label' => $scope['label'] ?? null,
            ], 'Meta.');
        } catch (Throwable $e) { return $this->serverError($e, 'meta'); }
    }

    /**
     * GET /admin/alerts/timeline/case/{id}
     * Per-case stream — every event for one alert, oldest first. Used by the
     * "Walk me through one case" wizard.
     */
    public function caseStream(Request $r, int $id): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);

            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Case not found.');
            if (! ScopeFilter::canSeeAlert($scope, $alert)) {
                return $this->err(403, 'Case is outside your scope.');
            }

            $rows = DB::table('alert_timeline_events as e')
                ->leftJoin('alerts as a', 'a.id', '=', 'e.alert_id')
                ->where('e.alert_id', $id)
                ->select([
                    'e.id','e.alert_id','e.event_code','e.event_category',
                    'e.actor_user_id','e.actor_name','e.actor_role',
                    'e.summary','e.severity','e.payload_json',
                    'e.related_entity_type','e.related_entity_id',
                    'e.created_at',
                    'a.alert_code','a.alert_title','a.risk_level',
                    'a.status as alert_status','a.routed_to_level',
                    'a.poe_code','a.district_code',
                ])
                ->orderBy('e.id', 'asc')
                ->limit(500)
                ->get();

            return $this->ok([
                'alert' => [
                    'id'              => (int) $alert->id,
                    'alert_code'      => (string) $alert->alert_code,
                    'alert_title'     => (string) $alert->alert_title,
                    'risk_level'      => (string) ($alert->risk_level ?? ''),
                    'status'          => (string) ($alert->status ?? ''),
                    'routed_to_level' => (string) ($alert->routed_to_level ?? ''),
                    'poe_code'        => (string) ($alert->poe_code ?? ''),
                    'district_code'   => (string) ($alert->district_code ?? ''),
                ],
                'events' => $rows->map(fn ($e) => $this->castEvent($e))->all(),
                'count'  => $rows->count(),
            ], 'Case stream.');
        } catch (Throwable $e) { return $this->serverError($e, 'caseStream'); }
    }

    /* ─────────────────── helpers ─────────────────── */

    private function castEvent(object $e): array
    {
        $payload = null;
        if (!empty($e->payload_json)) {
            $decoded = json_decode($e->payload_json, true);
            if (is_array($decoded)) $payload = $decoded;
        }
        $createdAt = $e->created_at ? (string) $e->created_at : null;

        return [
            'id'                  => (int) $e->id,
            'alert_id'            => (int) $e->alert_id,
            'event_code'          => (string) $e->event_code,
            'event_category'      => (string) $e->event_category,
            'severity'            => (string) $e->severity,
            'actor_user_id'       => $e->actor_user_id ? (int) $e->actor_user_id : null,
            'actor_name'          => $e->actor_name ?? null,
            'actor_role'          => $e->actor_role ?? null,
            'summary'             => (string) ($e->summary ?? ''),
            'payload'             => $payload,
            'related_entity_type' => $e->related_entity_type ?? null,
            'related_entity_id'   => $e->related_entity_id ? (int) $e->related_entity_id : null,
            'created_at'          => $createdAt,
            'alert_code'          => $e->alert_code ?? null,
            'alert_title'         => $e->alert_title ?? null,
            'risk_level'          => $e->risk_level ?? null,
            'alert_status'        => $e->alert_status ?? null,
            'routed_to_level'     => $e->routed_to_level ?? null,
            'poe_code'            => $e->poe_code ?? null,
            'district_code'       => $e->district_code ?? null,
            'human' => [
                'event_label'    => self::humanizeCode((string) $e->event_code),
                'category_label' => self::categoryLabel((string) $e->event_category),
                'severity_label' => self::severityLabel((string) $e->severity),
                'severity_tone'  => self::severityTone((string) $e->severity),
            ],
        ];
    }

    /**
     * Translate an event_code into a plain-English label.
     * Hardcoded — no external dependency. Versioned for review.
     * v1 — domain sign-off pending.
     */
    private static function humanizeCode(string $code): string
    {
        static $map = [
            'OPENED'                  => 'Case opened',
            'ACKNOWLEDGED'            => 'Case picked up',
            'CLOSED'                  => 'Case closed',
            'REOPENED'                => 'Case reopened',
            'ESCALATED'               => 'Escalated to a higher level',
            'REASSIGNED'              => 'Case reassigned',
            'HANDOFF_SENT'            => 'Handoff sent',
            'HANDOFF_ACKNOWLEDGED'    => 'Handoff seen',
            'HANDOFF_ACCEPTED'        => 'Handoff accepted',
            'HANDOFF_REJECTED'        => 'Handoff rejected',
            'HANDOFF_RECALLED'        => 'Handoff withdrawn',
            'COLLABORATOR_ADDED'      => 'Someone joined the case',
            'COLLABORATOR_UPDATED'    => 'Collaborator role changed',
            'COLLABORATOR_REMOVED'    => 'Someone left the case',
            'COMMENT_POSTED'          => 'Comment posted',
            'COMMENT_EDITED'          => 'Comment edited',
            'COMMENT_DELETED'         => 'Comment removed',
            'COMMENT_PINNED'          => 'Comment pinned',
            'EVIDENCE_UPLOADED'       => 'Evidence added',
            'EVIDENCE_DELETED'        => 'Evidence removed',
            'FOLLOWUP_COMPLETED'      => 'Follow-up step finished',
            'FOLLOWUP_BLOCKED'        => 'Follow-up step stuck',
            'FOLLOWUP_IN_PROGRESS'    => 'Follow-up step picked up',
            'FOLLOWUP_NOT_APPLICABLE' => 'Follow-up step skipped',
            'FOLLOWUP_UPDATED'        => 'Follow-up step updated',
            'BREACH_DETECTED'         => 'Deadline missed',
            'BREACH_ROOT_CAUSE_LOGGED'=> 'Reason for missed deadline recorded',
            'BREACH_UPDATED'          => 'Missed-deadline note updated',
            'EXTERNAL_INFO_REQUESTED' => 'External request sent',
            'EXTERNAL_INFO_RECEIVED'  => 'External response received',
            'EXTERNAL_LINK_REVOKED'   => 'External request cancelled',
            'EXTERNAL_LINK_RESENT'    => 'External request resent',
            'NOTIFICATION_SENT'       => 'Notification sent',
            'NOTIFICATION_FAILED'     => 'Notification failed',
            'WIZARD_STEP'             => 'Wizard step taken',
            'WIZARD_DECISION'         => 'Decision recorded',
            'FALSE_ALARM_RECORDED'    => 'Marked false alarm',
        ];
        if (isset($map[$code])) return $map[$code];
        // Fallback: prettify SCREAMING_SNAKE_CASE → "Sentence case".
        $s = strtolower(str_replace('_', ' ', $code));
        return ucfirst($s);
    }

    private static function categoryLabel(string $cat): string
    {
        return match ($cat) {
            'SYSTEM'   => 'System',
            'HUMAN'    => 'Person did something',
            'EMAIL'    => 'Notification',
            'WORKFLOW' => 'Step in the case',
            'BREACH'   => 'Deadline',
            'CLINICAL' => 'Clinical record',
            default    => ucfirst(strtolower($cat)),
        };
    }

    private static function severityLabel(string $sev): string
    {
        return match ($sev) {
            'INFO'     => 'Routine',
            'WARN'     => 'Heads up',
            'ERROR'    => 'Problem',
            'CRITICAL' => 'Urgent',
            default    => ucfirst(strtolower($sev)),
        };
    }

    private static function severityTone(string $sev): string
    {
        return match ($sev) {
            'INFO'     => 'info',
            'WARN'     => 'watch',
            'ERROR'    => 'urgent',
            'CRITICAL' => 'urgent',
            default    => 'info',
        };
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if (!empty($meta)) {$b['meta']=$meta;} return response()->json($b); }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    { return response()->json(['success'=>false,'message'=>$message,'error'=>$detail], $status); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Alerts\\Timeline][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
