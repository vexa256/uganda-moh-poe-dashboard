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
 * Admin · Section 03 · Case Room.
 *
 * Two surfaces in one route:
 *   • Per-alert lens (?alert_id=N)  — composite war-room read of the canonical
 *     warRoom() endpoint, augmented with a normalised payload the admin UI
 *     can render directly.
 *   • Cross-alert audit lens         — scope-filtered ledger of every
 *     COLLABORATOR_* / COMMENT_POSTED / EVIDENCE_* event across all alerts in
 *     the active scope. The "who is talking to my alerts" oversight view.
 *
 * Writes — every mutation (add/remove collaborator, post/edit/delete/pin/react
 * comment, add/delete evidence, create/accept/reject handoff) DELEGATES to
 * AlertCollaborationController. We never fork the canonical FSM. We DO add a
 * scope pre-flight before forwarding so non-super viewers cannot poke alerts
 * outside their jurisdiction even if they guess an id.
 */
final class CaseRoomController extends Controller
{
    /** Codes that constitute a "case-room" event. */
    public const CASEROOM_CODES = [
        'COLLABORATOR_ADDED', 'COLLABORATOR_UPDATED', 'COLLABORATOR_REMOVED',
        'COMMENT_POSTED', 'COMMENT_EDITED', 'COMMENT_DELETED', 'COMMENT_PINNED',
        'EVIDENCE_UPLOADED', 'EVIDENCE_DELETED',
        'HANDOFF_SENT', 'HANDOFF_ACKNOWLEDGED', 'HANDOFF_ACCEPTED', 'HANDOFF_REJECTED', 'HANDOFF_RECALLED',
    ];

    private const MAX_PER_PAGE = 100;
    private const COMMENT_MAX_LEN = 4000;
    private const EVIDENCE_MAX_FILE_BYTES = 25 * 1024 * 1024;
    private const EVIDENCE_MIME_WHITELIST = [
        'application/pdf', 'image/png', 'image/jpeg', 'image/heic', 'image/webp',
        'text/csv', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /* ═════════════════════════ views ═════════════════════════ */

    public function index(Request $r)
    {
        return view('admin.alertops.caseroom.index');
    }

    /* ═════════════════════════ READS ═════════════════════════ */

    /**
     * GET /admin/alerts/case-room/data
     * Cross-alert audit lens (default) or per-alert war-room (?alert_id=).
     */
    public function data(Request $r): JsonResponse
    {
        try {
            $scope   = ScopeFilter::fromRequest($r);
            $alertId = (int) $r->query('alert_id', 0);

            // ── per-alert lens ──────────────────────────────────────
            if ($alertId > 0) {
                $alert = DB::table('alerts')->where('id', $alertId)->whereNull('deleted_at')->first();
                if (! $alert) return $this->err(404, 'Alert not found.');
                if (! ScopeFilter::canSeeAlert($scope, $alert)) {
                    return $this->err(403, 'Alert is outside your scope.');
                }
                return $this->perAlert($alertId, $alert);
            }

            // ── cross-alert audit lens ──────────────────────────────
            $perPage = max(10, min(self::MAX_PER_PAGE, (int) $r->query('per_page', 50)));
            $cursor  = (int) $r->query('cursor', 0);
            $codesIn = trim((string) $r->query('event_code', ''));
            $codes   = $codesIn !== ''
                ? array_values(array_intersect(self::CASEROOM_CODES, array_map('trim', explode(',', $codesIn))))
                : self::CASEROOM_CODES;
            if (empty($codes)) $codes = self::CASEROOM_CODES;

            $base = function () use ($scope, $codes) {
                $q = DB::table('alert_timeline_events')->whereIn('event_code', $codes);
                return ScopeFilter::applyToTimelineEvents($q, $scope);
            };

            $rowsQ = $base();
            if ($cursor > 0) $rowsQ->where('alert_timeline_events.id', '<', $cursor);

            $sev = trim((string) $r->query('severity', ''));
            if ($sev !== '' && in_array($sev, ['INFO','WARN','ERROR','CRITICAL'], true)) {
                $rowsQ->where('alert_timeline_events.severity', $sev);
            }
            $qstr = trim((string) $r->query('q', ''));
            if ($qstr !== '') $rowsQ->where('alert_timeline_events.summary', 'like', '%'.$qstr.'%');

            $rows = $rowsQ
                ->leftJoin('alerts as a', 'a.id', '=', 'alert_timeline_events.alert_id')
                ->leftJoin('users  as u', 'u.id', '=', 'alert_timeline_events.actor_user_id')
                ->select([
                    'alert_timeline_events.*',
                    'a.alert_code', 'a.alert_title', 'a.risk_level',
                    'a.status as alert_status', 'a.routed_to_level',
                    'a.district_code', 'a.poe_code',
                    'u.full_name as actor_full_name', 'u.role_key as actor_role_key',
                ])
                ->orderByDesc('alert_timeline_events.id')
                ->limit($perPage + 1)
                ->get();

            $next = null;
            if ($rows->count() > $perPage) { $tail = $rows->pop(); $next = (int) $tail->id; }

            $counters = [];
            foreach (self::CASEROOM_CODES as $code) {
                $counters[$code] = (int) ScopeFilter::applyToTimelineEvents(
                    DB::table('alert_timeline_events')->where('event_code', $code), $scope
                )->count();
            }

            return $this->ok([
                'rows'        => $rows->map(fn ($e) => $this->castEvent($e))->all(),
                'count'       => $rows->count(),
                'next_cursor' => $next,
            ], 'Case-room audit (cross-alert).', [
                'counters' => $counters, 'codes' => self::CASEROOM_CODES, 'per_page' => $perPage,
                'scope_label' => $scope['label'] ?? null,
            ]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    /** Per-alert war-room composite. */
    private function perAlert(int $alertId, object $alert): JsonResponse
    {
        $collaborators = DB::table('alert_collaborators as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.alert_id', $alertId)
            ->select(['c.*', 'u.full_name', 'u.role_key', 'u.email'])
            ->orderByDesc('c.is_active')->orderBy('c.id')->get();

        $comments = DB::table('alert_comments as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.author_user_id')
            ->where('c.alert_id', $alertId)
            ->whereNull('c.deleted_at')
            ->select(['c.*', 'u.full_name as author_name', 'u.role_key as author_role'])
            ->orderByDesc('c.is_pinned')->orderByDesc('c.id')->limit(200)->get();

        $evidence = DB::table('alert_evidence as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.uploaded_by_user_id')
            ->where('e.alert_id', $alertId)
            ->whereNull('e.deleted_at')
            ->select(['e.*', 'u.full_name as uploader_full_name', 'u.role_key as uploader_role'])
            ->orderByDesc('e.id')->limit(200)->get();

        $handoffs = DB::table('alert_handoffs as h')
            ->leftJoin('users as f', 'f.id', '=', 'h.from_user_id')
            ->leftJoin('users as t', 't.id', '=', 'h.to_user_id')
            ->where('h.alert_id', $alertId)
            ->select([
                'h.*',
                'f.full_name as from_name', 'f.role_key as from_role',
                't.full_name as to_name',   't.role_key as to_role',
            ])
            ->orderByDesc('h.id')->get();

        return $this->ok([
            'alert' => [
                'id' => (int) $alert->id, 'alert_code' => (string) $alert->alert_code,
                'alert_title' => (string) $alert->alert_title, 'risk_level' => (string) $alert->risk_level,
                'status' => (string) $alert->status, 'routed_to_level' => (string) $alert->routed_to_level,
                'district_code' => (string) $alert->district_code, 'poe_code' => (string) $alert->poe_code,
                'current_owner_user_id' => $alert->current_owner_user_id ? (int) $alert->current_owner_user_id : null,
            ],
            'collaborators' => $collaborators->map(fn ($c) => (array) $c)->all(),
            'comments'      => $comments->map(fn ($c) => (array) $c)->all(),
            'evidence'      => $evidence->map(fn ($e) => $this->castEvidence($e))->all(),
            'handoffs'      => $handoffs->map(fn ($h) => (array) $h)->all(),
            'counters'      => [
                'collaborators_active' => $collaborators->where('is_active', 1)->count(),
                'comments'             => $comments->count(),
                'evidence'             => $evidence->count(),
                'evidence_external'    => $evidence->whereNotNull('external_url')->count() ?: $evidence->where('uploaded_by_user_id', 0)->count(),
                'handoffs_open'        => $handoffs->whereIn('status', ['SENT', 'ACKNOWLEDGED'])->count(),
            ],
        ], 'Case-room (per-alert).');
    }

    /** GET /admin/alerts/case-room/meta */
    public function meta(Request $r): JsonResponse
    {
        return $this->ok([
            'event_codes'         => self::CASEROOM_CODES,
            'comment_max_len'     => self::COMMENT_MAX_LEN,
            'evidence_max_bytes'  => self::EVIDENCE_MAX_FILE_BYTES,
            'evidence_mimes'      => self::EVIDENCE_MIME_WHITELIST,
            'evidence_categories' => [
                'DOCUMENT','PHOTO','LAB_RESULT','CONSENT','WHO_FORM','CONTACT_LIST',
                'SOP_SIGN_OFF','PPE_CHECKLIST','OTHER',
            ],
            'collaborator_roles'  => [
                'INCIDENT_COMMANDER','CASE_OWNER','CLINICAL_LEAD','LAB_LIAISON',
                'DISTRICT_LIAISON','PHEOC_LIAISON','NATIONAL_LIAISON','WHO_LIAISON',
                'CONTACT_TRACER','RISK_COMMS','LOGISTICS','OBSERVER',
            ],
            'visibilities'        => ['ALL','INTERNAL','EXTERNAL'],
            'comment_formats'     => ['MARKDOWN','PLAIN','HTML'],
        ], 'Case-room meta.');
    }

    /* ═════════════════════════ WRITES (delegated, scope-guarded) ═════════════════════════ */

    public function addCollaborator(Request $r, int $id): JsonResponse
    { return $this->guarded($r, $id, 'addCollaborator', fn ($r2) => app(AlertCollaborationController::class)->addCollaborator($r2, $id)); }

    public function updateCollaborator(Request $r, int $id): JsonResponse
    {
        // Map the row id back to the alert id for scope check.
        $row = DB::table('alert_collaborators')->where('id', $id)->first();
        if (! $row) return $this->err(404, 'Collaborator row not found.');
        return $this->guarded($r, (int) $row->alert_id, 'updateCollaborator',
            fn ($r2) => app(AlertCollaborationController::class)->updateCollaborator($r2, $id));
    }

    public function removeCollaborator(Request $r, int $id): JsonResponse
    {
        $row = DB::table('alert_collaborators')->where('id', $id)->first();
        if (! $row) return $this->err(404, 'Collaborator row not found.');
        return $this->guarded($r, (int) $row->alert_id, 'removeCollaborator',
            fn ($r2) => app(AlertCollaborationController::class)->removeCollaborator($r2, $id));
    }

    public function postComment(Request $r, int $id): JsonResponse
    {
        // Pre-flight length check (defence in depth — body column is TEXT but
        // we surface an explicit 422 instead of letting MySQL truncate).
        $body = (string) $r->input('body', '');
        if (mb_strlen(trim($body)) === 0) return $this->err(422, 'Comment body is required.');
        if (mb_strlen($body) > self::COMMENT_MAX_LEN) {
            return $this->err(422, 'Comment too long.', ['max' => self::COMMENT_MAX_LEN, 'got' => mb_strlen($body)]);
        }
        $resp = $this->guarded($r, $id, 'postComment', fn ($r2) => app(AlertCollaborationController::class)->postComment($r2, $id));

        // §3.2 — fan-out ALERT_NOTE_ADDED to assignee + team + originator + management
        // when the canonical post succeeded. Every recipient is paged via SentinelMail
        // (CC: ops mailboxes), with a notification_log row per recipient.
        $status = $resp->status();
        if ($status >= 200 && $status < 300) {
            try {
                $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
                if ($alert) {
                    $actorId = (int) ($r->user()->id ?? 0);
                    $actor   = $actorId > 0
                        ? DB::table('users')->where('id', $actorId)->first(['full_name','role_key'])
                        : (object) ['full_name' => 'System', 'role_key' => 'SYSTEM'];
                    $name = $actor->full_name ?? 'team member';
                    $excerpt = mb_substr(trim($body), 0, 240);
                    \App\Services\NotificationDispatcher::dispatchUserEvent(
                        $alert,
                        'ALERT_NOTE_ADDED',
                        'New case note from ' . $name,
                        $excerpt . (mb_strlen($body) > 240 ? '…' : '')
                            . "\n\nFrom: " . $name . ' · ' . ($actor->role_key ?? '—'),
                        $actorId,
                    );
                }
            } catch (Throwable $e) {
                Log::warning('[Admin\\Alerts\\CaseRoom][postComment][fanout] ' . $e->getMessage());
            }
        }
        return $resp;
    }

    public function editComment(Request $r, int $id): JsonResponse
    {
        $row = DB::table('alert_comments')->where('id', $id)->first();
        if (! $row) return $this->err(404, 'Comment not found.');
        return $this->guarded($r, (int) $row->alert_id, 'editComment',
            fn ($r2) => app(AlertCollaborationController::class)->editComment($r2, $id));
    }

    public function deleteComment(Request $r, int $id): JsonResponse
    {
        $row = DB::table('alert_comments')->where('id', $id)->first();
        if (! $row) return $this->err(404, 'Comment not found.');
        return $this->guarded($r, (int) $row->alert_id, 'deleteComment',
            fn ($r2) => app(AlertCollaborationController::class)->deleteComment($r2, $id));
    }

    public function togglePin(Request $r, int $id): JsonResponse
    {
        $row = DB::table('alert_comments')->where('id', $id)->first();
        if (! $row) return $this->err(404, 'Comment not found.');
        return $this->guarded($r, (int) $row->alert_id, 'togglePin',
            fn ($r2) => app(AlertCollaborationController::class)->togglePin($r2, $id));
    }

    public function reactToComment(Request $r, int $id): JsonResponse
    {
        $row = DB::table('alert_comments')->where('id', $id)->first();
        if (! $row) return $this->err(404, 'Comment not found.');
        return $this->guarded($r, (int) $row->alert_id, 'reactToComment',
            fn ($r2) => app(AlertCollaborationController::class)->reactToComment($r2, $id));
    }

    public function addEvidence(Request $r, int $id): JsonResponse
    {
        // Pre-flight MIME + size checks. The canonical addEvidence accepts an
        // external_url too, in which case file_* are empty — keep that path open.
        if ($r->hasFile('file')) {
            $f = $r->file('file');
            $bytes = (int) $f->getSize();
            $mime  = (string) $f->getMimeType();
            if ($bytes <= 0 || $bytes > self::EVIDENCE_MAX_FILE_BYTES) {
                return $this->err(422, 'Evidence file size out of range (0 .. '.self::EVIDENCE_MAX_FILE_BYTES.' bytes).', ['size' => $bytes]);
            }
            if (! in_array($mime, self::EVIDENCE_MIME_WHITELIST, true)) {
                return $this->err(422, 'Evidence MIME type not allowed.', [
                    'mime' => $mime, 'allowed' => self::EVIDENCE_MIME_WHITELIST,
                ]);
            }
        }
        return $this->guarded($r, $id, 'addEvidence',
            fn ($r2) => app(AlertCollaborationController::class)->addEvidence($r2, $id));
    }

    public function deleteEvidence(Request $r, int $id): JsonResponse
    {
        $row = DB::table('alert_evidence')->where('id', $id)->first();
        if (! $row) return $this->err(404, 'Evidence not found.');
        return $this->guarded($r, (int) $row->alert_id, 'deleteEvidence',
            fn ($r2) => app(AlertCollaborationController::class)->deleteEvidence($r2, $id));
    }

    public function createHandoff(Request $r, int $id): JsonResponse
    { return $this->guarded($r, $id, 'createHandoff', fn ($r2) => app(AlertCollaborationController::class)->createHandoff($r2, $id)); }

    public function acceptHandoff(Request $r, int $id): JsonResponse
    {
        $row = DB::table('alert_handoffs')->where('id', $id)->first();
        if (! $row) return $this->err(404, 'Handoff not found.');
        return $this->guarded($r, (int) $row->alert_id, 'acceptHandoff',
            fn ($r2) => app(AlertCollaborationController::class)->acceptHandoff($r2, $id));
    }

    public function rejectHandoff(Request $r, int $id): JsonResponse
    {
        $row = DB::table('alert_handoffs')->where('id', $id)->first();
        if (! $row) return $this->err(404, 'Handoff not found.');
        return $this->guarded($r, (int) $row->alert_id, 'rejectHandoff',
            fn ($r2) => app(AlertCollaborationController::class)->rejectHandoff($r2, $id));
    }

    /* ═════════════════════════ helpers ═════════════════════════ */

    /**
     * Wrap a delegate call with:
     *   1. Scope pre-flight on the parent alert (hostile-auditor angle: an
     *      attacker who guesses an internal id MUST be blocked).
     *   2. Actor injection — the downstream `actorId()` reads X-User-Id /
     *      actor_user_id; the web session has $r->user() set but the
     *      downstream doesn't read it. Inject both for belt-and-braces.
     */
    private function guarded(Request $r, int $alertId, string $ctx, callable $fn): JsonResponse
    {
        try {
            $alert = DB::table('alerts')->where('id', $alertId)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Alert not found.');
            if (! ScopeFilter::canSeeAlert(ScopeFilter::fromRequest($r), $alert)) {
                return $this->err(403, 'Alert is outside your scope.');
            }
            $actorId = (int) ($r->user()->id ?? 0);
            if ($actorId > 0) {
                $r->headers->set('X-User-Id', (string) $actorId);
                $r->merge(['actor_user_id' => $actorId, 'user_id' => $actorId]);
            }
            return $fn($r);
        } catch (Throwable $e) { return $this->serverError($e, $ctx); }
    }

    private function castEvent(object $e): array
    {
        return [
            'id'              => (int) $e->id,
            'alert_id'        => (int) $e->alert_id,
            'alert_code'      => $e->alert_code,
            'alert_title'     => $e->alert_title,
            'alert_status'    => $e->alert_status,
            'risk_level'      => $e->risk_level,
            'routed_to_level' => $e->routed_to_level,
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
                'title'      => $this->humaniseEventCode((string) $e->event_code),
                'when_human' => HumanLabels::dueHuman((string) $e->created_at),
                'tone'       => match ((string) $e->severity) { 'CRITICAL', 'ERROR' => 'urgent', 'WARN' => 'watch', default => 'info' },
                'risk_label' => !empty($e->risk_level) ? HumanLabels::risk((string) $e->risk_level)['short'] : null,
                'routed_to'  => !empty($e->routed_to_level) ? HumanLabels::routedTo((string) $e->routed_to_level) : null,
            ],
        ];
    }

    private function humaniseEventCode(string $code): string
    {
        return match ($code) {
            'COMMENT_POSTED'        => 'A note was added',
            'COMMENT_EDITED'        => 'A note was edited',
            'COMMENT_DELETED'       => 'A note was removed',
            'COMMENT_PINNED'        => 'A note was pinned',
            'COMMENT_REACTED'       => 'Someone reacted to a note',
            'EVIDENCE_ADDED'        => 'A document or photo was attached',
            'EVIDENCE_REMOVED'      => 'A document or photo was removed',
            'COLLABORATOR_ADDED'    => 'A teammate was added',
            'COLLABORATOR_UPDATED'  => 'A teammate role was updated',
            'COLLABORATOR_REMOVED'  => 'A teammate was removed',
            'HANDOFF_CREATED'       => 'A handover was started',
            'HANDOFF_ACCEPTED'      => 'A handover was accepted',
            'HANDOFF_REJECTED'      => 'A handover was declined',
            'WIZARD_DECISION'       => 'Wizard decision recorded',
            'STAKEHOLDER_CONTACT'   => 'Reached out to a stakeholder',
            default                 => HumanLabels::prettify($code),
        };
    }

    private function castEvidence(object $e): array
    {
        return [
            'id'                => (int) $e->id,
            'alert_id'          => (int) $e->alert_id,
            'followup_id'       => $e->followup_id ? (int) $e->followup_id : null,
            'category'          => (string) $e->category,
            'title'             => (string) $e->title,
            'description'       => $e->description,
            'file_ref'          => $e->file_ref,
            'file_mime'         => $e->file_mime,
            'file_size_bytes'   => $e->file_size_bytes !== null ? (int) $e->file_size_bytes : null,
            'external_url'      => $e->external_url,
            'visibility'        => (string) $e->visibility,
            'uploaded_by_user_id'=> $e->uploaded_by_user_id ? (int) $e->uploaded_by_user_id : null,
            'uploader_name'     => $e->uploader_full_name ?? $e->uploader_name,
            'uploader_role'     => $e->uploader_role,
            'is_external'       => isset($e->external_responder_id) && $e->external_responder_id !== null,
            'external_responder_id' => isset($e->external_responder_id) && $e->external_responder_id !== null ? (int) $e->external_responder_id : null,
            'responder_request_id'  => isset($e->responder_request_id)  && $e->responder_request_id  !== null ? (int) $e->responder_request_id  : null,
            'created_at'        => $e->created_at,
        ];
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if(!empty($meta)){$b['meta']=$meta;} return response()->json($b); }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    { return response()->json(['success'=>false,'message'=>$message,'error'=>$detail], $status); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Alerts\\CaseRoom][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
