<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CaseContextBuilder;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AlertCollaborationController
 * ─────────────────────────────────────────────────────────────────────────
 * The "war-room" controller. Coordinates multi-stakeholder response to an
 * alert across the POE → DISTRICT → PHEOC → NATIONAL → WHO ladder. Every
 * mutation emits a row into alert_timeline_events so the UI can render a
 * single chronological feed that includes machine + human + email events.
 *
 * Endpoint map
 *   GET    /alerts/{id}/war-room                 war-room snapshot (one fetch)
 *   GET    /alerts/{id}/timeline                 full timeline feed
 *   GET    /alerts/{id}/collaborators            list active collaborators
 *   POST   /alerts/{id}/collaborators            add user to the case
 *   PATCH  /alert-collaborators/{id}             change role / level / notes
 *   DELETE /alert-collaborators/{id}             remove (soft)
 *   GET    /alerts/{id}/comments                 threaded comments
 *   POST   /alerts/{id}/comments                 post comment (supports replies + @mentions)
 *   PATCH  /alert-comments/{id}                  edit own comment
 *   DELETE /alert-comments/{id}                  soft-delete comment
 *   POST   /alert-comments/{id}/pin              pin / unpin a comment
 *   POST   /alert-comments/{id}/react            add reaction (✓, 👍, ⚠)
 *   GET    /alerts/{id}/evidence                 list attachments
 *   POST   /alerts/{id}/evidence                 register an attachment ref
 *   DELETE /alert-evidence/{id}                  soft-delete attachment
 *   GET    /alerts/{id}/handoffs                 list handoffs
 *   POST   /alerts/{id}/handoffs                 formally hand over to next level
 *   POST   /alert-handoffs/{id}/accept           receiving side accepts
 *   POST   /alert-handoffs/{id}/reject           receiving side rejects
 *   POST   /alerts/{id}/escalate                 escalate + notify + timeline
 *   POST   /alerts/{id}/reopen                   reopen a closed alert
 *   POST   /alerts/{id}/reassign                 change case owner
 *   POST   /alerts/{id}/breach-report            log a 7-1-7 root-cause report
 *   GET    /alerts/{id}/breach-reports           list breach reports on this alert
 *   PATCH  /alert-breach-reports/{id}            progress/resolve a breach report
 *   POST   /alerts/{id}/pheic-declare            mark PHEIC pathway entered
 *   POST   /alerts/{id}/request-external-info    email an external responder
 *
 * Design notes
 * ───────────────────────────────────────────────────────────────────────
 * • Every mutation is wrapped in DB::transaction + always writes a
 *   timeline row before committing.
 * • All collaboration actions can optionally trigger an email fan-out via
 *   NotificationDispatcher — the dispatcher guards suppression so repeated
 *   noisy actions do not cross into the recipient's inbox.
 * • Responses follow the envelope { ok: bool, data?: …, error?: string }.
 * • IDs resolved from X-User-Id header (shim for pre-auth deployment);
 *   replace with auth()->id() once Sanctum is wired.
 */
final class AlertCollaborationController extends Controller
{
    // ══════════════════════════════════════════════════════════════════════
    //  WAR-ROOM SNAPSHOT & TIMELINE
    // ══════════════════════════════════════════════════════════════════════

    /**
     * GET /alerts/{id}/war-room
     *
     * One-shot payload so the UI can render the full war-room page without
     * chaining six requests. Includes the alert, case context, collaborators,
     * comments thread, evidence list, timeline events, follow-ups, handoffs,
     * breach reports, and disease intelligence card.
     */
    public function warRoom(int $id): JsonResponse
    {
        try {
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err('Alert not found', 404);

            $vars = CaseContextBuilder::forAlert($alert);

            $collaborators = DB::table('alert_collaborators as c')
                ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
                ->where('c.alert_id', $id)
                ->where('c.is_active', 1)
                ->select('c.*', 'u.full_name', 'u.email as user_email', 'u.role_key')
                ->orderByRaw("FIELD(c.role,'INCIDENT_COMMANDER','CASE_OWNER','CLINICAL_LEAD','LAB_LIAISON','DISTRICT_LIAISON','PHEOC_LIAISON','NATIONAL_LIAISON','WHO_LIAISON','CONTACT_TRACER','RISK_COMMS','LOGISTICS','OBSERVER')")
                ->get();

            $comments = $this->fetchThreadedComments($id);
            $evidence = DB::table('alert_evidence')
                ->where('alert_id', $id)->whereNull('deleted_at')
                ->orderByDesc('created_at')->get();
            $timeline = DB::table('alert_timeline_events')
                ->where('alert_id', $id)
                ->orderByDesc('created_at')->limit(200)->get();
            $followups = DB::table('alert_followups')
                ->where('alert_id', $id)->whereNull('deleted_at')
                ->orderBy('due_at')->get();
            $handoffs = DB::table('alert_handoffs')
                ->where('alert_id', $id)->orderByDesc('created_at')->get();
            $breaches = DB::table('alert_breach_reports')
                ->where('alert_id', $id)->orderByDesc('created_at')->get();

            // Email + notification activity for this alert
            $notifActivity = DB::table('notification_log')
                ->where('related_entity_type', 'ALERT')
                ->where('related_entity_id', $id)
                ->orderByDesc('created_at')->limit(100)
                ->select('id', 'template_code', 'to_email', 'status', 'subject', 'error_message', 'created_at')
                ->get();

            // ── Secondary-screening bundle (full extraction per memory rule) ──
            // Attached as raw arrays so the admin view can drive visualisations
            // without parsing HTML blobs. Rank-1 suspected disease is resolved
            // against Diseases.js via SsotRegistry so the UI can render a
            // plain disease name ("Ebola Virus Disease") instead of leaking
            // engine codes like TIER1_ALWAYS_CRITICAL.
            $screening = null;
            $symptoms  = collect();
            $exposures = collect();
            $samples   = collect();
            $actions   = collect();
            $countries = collect();
            $suspected = collect();
            $resolvedDisease = null;
            $diseaseCatalog  = [];

            if ($alert->secondary_screening_id) {
                $sid = (int) $alert->secondary_screening_id;
                $screening = DB::table('secondary_screenings')->where('id', $sid)->first();
                $symptoms  = DB::table('secondary_symptoms')->where('secondary_screening_id', $sid)->get();
                $exposures = DB::table('secondary_exposures')->where('secondary_screening_id', $sid)->get();
                $samples   = DB::table('secondary_samples')->where('secondary_screening_id', $sid)->orderByDesc('collected_at')->get();
                $actions   = DB::table('secondary_actions')->where('secondary_screening_id', $sid)->get();
                $countries = DB::table('secondary_travel_countries')->where('secondary_screening_id', $sid)->orderBy('arrival_date')->get();
                $suspected = DB::table('secondary_suspected_diseases')->where('secondary_screening_id', $sid)->orderBy('rank_order')->get();
            }

            // Build a disease-code → plain-name lookup from the parsed Diseases.js
            // catalogue, then enrich each suspected row with a plain-English
            // disease_name so the UI never has to touch raw codes.
            try {
                foreach (\App\Services\SsotRegistry::diseases() as $d) {
                    if (! empty($d['id']) && ! empty($d['name'])) {
                        $diseaseCatalog[strtolower((string) $d['id'])] = [
                            'name'         => (string) $d['name'],
                            'priority_tier'=> (string) ($d['priority_tier'] ?? ''),
                            'severity'     => $d['severity'] ?? null,
                            'cfr_pct'      => $d['case_fatality_rate_pct'] ?? null,
                            'syndromes'    => $d['syndromes'] ?? [],
                            'incubation'   => $d['incubation_days']['typical'] ?? null,
                        ];
                    }
                }
            } catch (Throwable) { /* catalogue optional — view falls back gracefully */ }

            $suspected = $suspected->map(function ($r) use ($diseaseCatalog) {
                $key = strtolower((string) ($r->disease_code ?? ''));
                $meta = $diseaseCatalog[$key] ?? null;
                $r->disease_name     = $meta['name']          ?? self::prettifyDiseaseCode((string) $r->disease_code);
                $r->priority_tier    = $meta['priority_tier'] ?? null;
                $r->severity         = $meta['severity']      ?? null;
                $r->cfr_pct          = $meta['cfr_pct']       ?? null;
                return $r;
            });

            // Rank-1 (top) suspected disease is the authoritative label for
            // this case. If missing, leave null — the view decides the fallback.
            $top = $suspected->firstWhere('rank_order', 1);
            if ($top) {
                $resolvedDisease = [
                    'disease_code' => $top->disease_code,
                    'disease_name' => $top->disease_name,
                    'confidence'   => $top->confidence !== null ? (float) $top->confidence : null,
                    'priority_tier'=> $top->priority_tier,
                    'severity'     => $top->severity,
                    'cfr_pct'      => $top->cfr_pct,
                ];
            }

            return $this->ok([
                'alert'              => $alert,
                'context_vars'       => $vars,
                'screening'          => $screening,
                'symptoms'           => $symptoms,
                'exposures'          => $exposures,
                'samples'            => $samples,
                'actions'            => $actions,
                'travel_countries'   => $countries,
                'suspected_diseases' => $suspected,
                'resolved_disease'   => $resolvedDisease,
                'collaborators'      => $collaborators,
                'collaborators_count'=> $collaborators->count(),
                'comments'           => $comments,
                'comments_count'     => count($comments),
                'evidence'           => $evidence,
                'evidence_count'     => $evidence->count(),
                'timeline'           => $timeline,
                'timeline_count'     => $timeline->count(),
                'followups'          => $followups,
                'handoffs'           => $handoffs,
                'breach_reports'     => $breaches,
                'notification_activity' => $notifActivity,
            ]);
        } catch (Throwable $e) {
            return $this->fail($e, 'warRoom');
        }
    }

    /**
     * Fallback humaniser for disease codes we don't have in the catalogue.
     * Turns `cchf` → `CCHF`, `ebola_virus_disease` → `Ebola virus disease`.
     */
    private static function prettifyDiseaseCode(string $code): string
    {
        if ($code === '') return 'Suspected case';
        $short = strtoupper($code);
        // Keep common short acronyms as-is
        if (in_array($short, ['CCHF','SARS','MERS','VHF','AFP','AFI','ILI','SARI'], true)) return $short;
        $clean = str_replace('_', ' ', strtolower($code));
        return ucfirst($clean);
    }

    /**
     * GET /alerts/{id}/timeline
     *
     * Returns the full timeline for an alert — all SYSTEM / HUMAN / EMAIL /
     * WORKFLOW / BREACH / CLINICAL events merged and sorted. Supports
     * ?since=ISO8601 for incremental polling.
     */
    public function timeline(Request $r, int $id): JsonResponse
    {
        try {
            $q = DB::table('alert_timeline_events')->where('alert_id', $id);
            if ($since = $r->query('since')) {
                $q->where('created_at', '>', $since);
            }
            if ($category = $r->query('category')) {
                $q->where('event_category', $category);
            }
            $rows = $q->orderByDesc('created_at')->limit((int) $r->query('limit', 200))->get();
            return $this->ok(['events' => $rows, 'count' => $rows->count()]);
        } catch (Throwable $e) {
            return $this->fail($e, 'timeline');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  COLLABORATORS
    // ══════════════════════════════════════════════════════════════════════

    /** GET /alerts/{id}/collaborators */
    public function collaborators(int $id): JsonResponse
    {
        try {
            $rows = DB::table('alert_collaborators as c')
                ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
                ->where('c.alert_id', $id)
                ->where('c.is_active', 1)
                ->select('c.*', 'u.full_name', 'u.email as user_email', 'u.role_key', 'u.country_code')
                ->orderBy('c.created_at')->get();
            return $this->ok(['collaborators' => $rows]);
        } catch (Throwable $e) {
            return $this->fail($e, 'collaborators');
        }
    }

    /**
     * POST /alerts/{id}/collaborators
     * Body: { user_id, role, level?, notes? }
     *
     * Business rules:
     *  – same (alert_id, user_id) can only be active once (unique key)
     *  – adds a timeline event COLLABORATOR_ADDED
     *  – if user has an email, posts a system comment @mentioning them
     */
    public function addCollaborator(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $alert = $this->mustAlert($id);
            $actorId = $this->actorId($r);
            $data = $r->validate([
                'user_id' => 'required|integer',
                'role'    => 'required|string|max:60',
                'level'   => 'nullable|string|max:30',
                'notes'   => 'nullable|string|max:500',
            ]);

            // Reactivate an existing soft-removed row, else insert
            $existing = DB::table('alert_collaborators')
                ->where('alert_id', $id)->where('user_id', $data['user_id'])->first();
            if ($existing) {
                DB::table('alert_collaborators')->where('id', $existing->id)->update([
                    'role' => $data['role'], 'level' => $data['level'] ?? null,
                    'notes' => $data['notes'] ?? null, 'is_active' => 1,
                    'removed_at' => null, 'removed_by_user_id' => null,
                    'added_by_user_id' => $actorId, 'updated_at' => now(),
                ]);
                $cid = $existing->id;
            } else {
                $cid = DB::table('alert_collaborators')->insertGetId([
                    'alert_id' => $id, 'user_id' => $data['user_id'],
                    'role' => $data['role'], 'level' => $data['level'] ?? null,
                    'added_by_user_id' => $actorId, 'notes' => $data['notes'] ?? null,
                    'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $user = DB::table('users')->where('id', $data['user_id'])->first();
            $this->emitTimeline($id, 'COLLABORATOR_ADDED', 'WORKFLOW', $actorId,
                ($user->full_name ?? '—') . ' joined the war-room as ' . $data['role'],
                ['collaborator_id' => $cid, 'user_id' => $data['user_id'], 'role' => $data['role'], 'level' => $data['level'] ?? null],
                'COLLABORATOR', $cid);

            return $this->ok(['collaborator_id' => $cid]);
        }, 'addCollaborator');
    }

    /** PATCH /alert-collaborators/{id} */
    public function updateCollaborator(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $row = DB::table('alert_collaborators')->where('id', $id)->first();
            if (! $row) return $this->err('Collaborator not found', 404);
            $data = $r->validate([
                'role'  => 'nullable|string|max:60',
                'level' => 'nullable|string|max:30',
                'notes' => 'nullable|string|max:500',
            ]);
            DB::table('alert_collaborators')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
            $this->emitTimeline((int) $row->alert_id, 'COLLABORATOR_UPDATED', 'WORKFLOW', $this->actorId($r),
                'Collaborator role updated', array_merge($data, ['collaborator_id' => $id]),
                'COLLABORATOR', $id);
            return $this->ok(['updated' => true]);
        }, 'updateCollaborator');
    }

    /** DELETE /alert-collaborators/{id} (soft) */
    public function removeCollaborator(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $row = DB::table('alert_collaborators')->where('id', $id)->first();
            if (! $row) return $this->err('Collaborator not found', 404);
            DB::table('alert_collaborators')->where('id', $id)->update([
                'is_active' => 0, 'removed_at' => now(),
                'removed_by_user_id' => $this->actorId($r), 'updated_at' => now(),
            ]);
            $this->emitTimeline((int) $row->alert_id, 'COLLABORATOR_REMOVED', 'WORKFLOW', $this->actorId($r),
                'Collaborator removed from the war-room',
                ['collaborator_id' => $id], 'COLLABORATOR', $id);
            return $this->ok(['removed' => true]);
        }, 'removeCollaborator');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  COMMENTS (threaded)
    // ══════════════════════════════════════════════════════════════════════

    /** GET /alerts/{id}/comments */
    public function comments(int $id): JsonResponse
    {
        try {
            return $this->ok(['comments' => $this->fetchThreadedComments($id)]);
        } catch (Throwable $e) {
            return $this->fail($e, 'comments');
        }
    }

    /**
     * POST /alerts/{id}/comments
     * Body: { body, parent_id?, body_format?, mentions?, visibility? }
     * – mentions: array of user ids to @mention. These become collaborator
     *   candidates and a derived timeline event.
     */
    public function postComment(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $this->mustAlert($id);
            $actorId = $this->actorId($r);
            $data = $r->validate([
                'body'        => 'required|string|max:20000',
                'parent_id'   => 'nullable|integer',
                'body_format' => 'nullable|in:MARKDOWN,PLAIN,HTML',
                'mentions'    => 'nullable|array',
                'mentions.*'  => 'integer',
                'visibility'  => 'nullable|in:ALL,INTERNAL,EXTERNAL',
            ]);

            $user = $actorId ? DB::table('users')->where('id', $actorId)->first() : null;
            $mentionsDetails = [];
            if (! empty($data['mentions'])) {
                $mentionsDetails = DB::table('users')->whereIn('id', $data['mentions'])
                    ->select('id', 'full_name', 'role_key')->get()
                    ->map(fn($u) => ['user_id' => $u->id, 'name' => $u->full_name, 'role' => $u->role_key])
                    ->all();
            }

            $cid = DB::table('alert_comments')->insertGetId([
                'alert_id'       => $id,
                'parent_id'      => $data['parent_id'] ?? null,
                'author_user_id' => $actorId,
                'author_role'    => $user->role_key ?? null,
                'author_level'   => null,
                'body'           => $data['body'],
                'body_format'    => $data['body_format'] ?? 'MARKDOWN',
                'mentions_json'  => ! empty($mentionsDetails) ? json_encode($mentionsDetails) : null,
                'visibility'     => $data['visibility'] ?? 'ALL',
                'is_system'      => 0,
                'created_at'     => now(), 'updated_at' => now(),
            ]);

            $this->emitTimeline($id, 'COMMENT_POSTED', 'HUMAN', $actorId,
                self::summarise($data['body'], 140),
                ['comment_id' => $cid, 'parent_id' => $data['parent_id'] ?? null, 'mentions' => $mentionsDetails],
                'COMMENT', $cid);

            return $this->ok(['comment_id' => $cid]);
        }, 'postComment');
    }

    /** PATCH /alert-comments/{id} — edit body */
    public function editComment(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $row = DB::table('alert_comments')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $row) return $this->err('Comment not found', 404);
            $actorId = $this->actorId($r);
            if ($row->author_user_id && $actorId && (int) $row->author_user_id !== $actorId) {
                return $this->err('Only the author can edit this comment', 403);
            }
            $data = $r->validate(['body' => 'required|string|max:20000']);
            DB::table('alert_comments')->where('id', $id)->update([
                'body' => $data['body'], 'edited_at' => now(),
                'edited_by_user_id' => $actorId, 'updated_at' => now(),
            ]);
            return $this->ok(['updated' => true]);
        }, 'editComment');
    }

    /** DELETE /alert-comments/{id} (soft) */
    public function deleteComment(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $row = DB::table('alert_comments')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $row) return $this->err('Comment not found', 404);
            DB::table('alert_comments')->where('id', $id)->update(['deleted_at' => now()]);
            return $this->ok(['deleted' => true]);
        }, 'deleteComment');
    }

    /** POST /alert-comments/{id}/pin — toggles pinned state */
    public function togglePin(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $row = DB::table('alert_comments')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $row) return $this->err('Comment not found', 404);
            $flip = ((int) $row->is_pinned) === 1 ? 0 : 1;
            DB::table('alert_comments')->where('id', $id)->update(['is_pinned' => $flip, 'updated_at' => now()]);
            return $this->ok(['is_pinned' => $flip]);
        }, 'togglePin');
    }

    /** POST /alert-comments/{id}/react — body { code: 'ack'|'thumbs_up'|'flag'|'done' } */
    public function reactToComment(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $row = DB::table('alert_comments')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $row) return $this->err('Comment not found', 404);
            $code = (string) $r->input('code', 'ack');
            $r->validate(['code' => 'in:ack,thumbs_up,thumbs_down,flag,done,question']);
            $cur = $row->reactions_json ? (array) json_decode((string) $row->reactions_json, true) : [];
            $cur[$code] = (int) ($cur[$code] ?? 0) + 1;
            DB::table('alert_comments')->where('id', $id)->update([
                'reactions_json' => json_encode($cur), 'updated_at' => now(),
            ]);
            return $this->ok(['reactions' => $cur]);
        }, 'reactToComment');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  EVIDENCE
    // ══════════════════════════════════════════════════════════════════════

    /** GET /alerts/{id}/evidence */
    public function evidence(int $id): JsonResponse
    {
        try {
            $rows = DB::table('alert_evidence')
                ->where('alert_id', $id)->whereNull('deleted_at')
                ->orderByDesc('created_at')->get();
            return $this->ok(['evidence' => $rows]);
        } catch (Throwable $e) {
            return $this->fail($e, 'evidence');
        }
    }

    /**
     * POST /alerts/{id}/evidence
     * Body: { category, title, description?, file_ref?, file_mime?, file_size_bytes?,
     *         external_url?, followup_id?, visibility? }
     *
     * The controller does NOT accept raw file uploads — in the PWA we upload
     * to the storage layer first (S3 / local disk) and POST the resulting
     * file_ref. This keeps the controller stateless + storage-agnostic.
     */
    public function addEvidence(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $this->mustAlert($id);
            $actorId = $this->actorId($r);
            $data = $r->validate([
                'category'        => 'required|string|max:40',
                'title'           => 'required|string|max:200',
                'description'     => 'nullable|string|max:1000',
                'file_ref'        => 'nullable|string|max:500',
                'file_mime'       => 'nullable|string|max:80',
                'file_size_bytes' => 'nullable|integer',
                'external_url'    => 'nullable|url|max:500',
                'followup_id'     => 'nullable|integer',
                'visibility'      => 'nullable|in:ALL,INTERNAL,EXTERNAL',
            ]);
            $uploader = $actorId ? DB::table('users')->where('id', $actorId)->value('full_name') : null;
            $eid = DB::table('alert_evidence')->insertGetId(array_merge($data, [
                'alert_id' => $id,
                'uploaded_by_user_id' => $actorId,
                'uploader_name' => $uploader,
                'visibility' => $data['visibility'] ?? 'ALL',
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->emitTimeline($id, 'EVIDENCE_UPLOADED', 'WORKFLOW', $actorId,
                '[' . $data['category'] . '] ' . $data['title'],
                ['evidence_id' => $eid, 'category' => $data['category']],
                'EVIDENCE', $eid);
            return $this->ok(['evidence_id' => $eid]);
        }, 'addEvidence');
    }

    /** DELETE /alert-evidence/{id} (soft) */
    public function deleteEvidence(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $row = DB::table('alert_evidence')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $row) return $this->err('Evidence not found', 404);
            DB::table('alert_evidence')->where('id', $id)->update(['deleted_at' => now()]);
            $this->emitTimeline((int) $row->alert_id, 'EVIDENCE_DELETED', 'WORKFLOW', $this->actorId($r),
                'Evidence removed: ' . $row->title,
                ['evidence_id' => $id], 'EVIDENCE', $id);
            return $this->ok(['deleted' => true]);
        }, 'deleteEvidence');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  HANDOFFS (ladder transitions)
    // ══════════════════════════════════════════════════════════════════════

    /** GET /alerts/{id}/handoffs */
    public function handoffs(int $id): JsonResponse
    {
        try {
            $rows = DB::table('alert_handoffs as h')
                ->leftJoin('users as uf', 'uf.id', '=', 'h.from_user_id')
                ->leftJoin('users as ut', 'ut.id', '=', 'h.to_user_id')
                ->where('h.alert_id', $id)
                ->select('h.*', 'uf.full_name as from_name', 'ut.full_name as to_name')
                ->orderByDesc('h.created_at')->get();
            return $this->ok(['handoffs' => $rows]);
        } catch (Throwable $e) {
            return $this->fail($e, 'handoffs');
        }
    }

    /**
     * POST /alerts/{id}/handoffs
     * Body: { from_level, to_level, to_user_id?, reason, handoff_notes?, notify? }
     */
    public function createHandoff(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $alert = $this->mustAlert($id);
            $actorId = $this->actorId($r);
            $data = $r->validate([
                'from_level'    => 'required|string|max:30',
                'to_level'      => 'required|string|max:30',
                'to_user_id'    => 'nullable|integer',
                'reason'        => 'required|string|max:500',
                'handoff_notes' => 'nullable|string',
                'notify'        => 'nullable|boolean',
            ]);
            $hid = DB::table('alert_handoffs')->insertGetId([
                'alert_id'      => $id,
                'from_level'    => $data['from_level'],
                'to_level'      => $data['to_level'],
                'from_user_id'  => $actorId,
                'to_user_id'    => $data['to_user_id'] ?? null,
                'reason'        => $data['reason'],
                'handoff_notes' => $data['handoff_notes'] ?? null,
                'status'        => 'SENT',
                'created_at'    => now(), 'updated_at' => now(),
            ]);
            $this->emitTimeline($id, 'HANDOFF_SENT', 'WORKFLOW', $actorId,
                sprintf('Alert handed off %s → %s', $data['from_level'], $data['to_level']),
                $data, 'HANDOFF', $hid);

            // Update alert routed_to_level + current owner
            DB::table('alerts')->where('id', $id)->update([
                'routed_to_level'       => $data['to_level'],
                'current_owner_user_id' => $data['to_user_id'] ?? null,
                'current_owner_level'   => $data['to_level'],
                'updated_at'            => now(),
            ]);

            // Optionally fire escalation email fan-out
            if (! empty($data['notify'])) {
                NotificationDispatcher::dispatchAlertCreated(
                    (object) array_merge((array) $alert, [
                        'routed_to_level' => $data['to_level'],
                        'risk_level'      => $alert->risk_level,
                    ]),
                    $actorId ?? 0,
                );
            }
            return $this->ok(['handoff_id' => $hid]);
        }, 'createHandoff');
    }

    /** POST /alert-handoffs/{id}/accept   Body: { decision_notes? } */
    public function acceptHandoff(Request $r, int $id): JsonResponse
    {
        return $this->decideHandoff($r, $id, 'ACCEPTED', 'HANDOFF_ACCEPTED', 'accepted');
    }

    /** POST /alert-handoffs/{id}/reject   Body: { decision_notes } */
    public function rejectHandoff(Request $r, int $id): JsonResponse
    {
        return $this->decideHandoff($r, $id, 'REJECTED', 'HANDOFF_REJECTED', 'rejected');
    }

    private function decideHandoff(Request $r, int $id, string $newStatus, string $eventCode, string $verb): JsonResponse
    {
        return $this->tx(function () use ($r, $id, $newStatus, $eventCode, $verb) {
            $row = DB::table('alert_handoffs')->where('id', $id)->first();
            if (! $row) return $this->err('Handoff not found', 404);
            $data = $r->validate(['decision_notes' => 'nullable|string|max:500']);
            DB::table('alert_handoffs')->where('id', $id)->update([
                'status'             => $newStatus,
                'decided_at'         => now(),
                'decided_by_user_id' => $this->actorId($r),
                'decision_notes'     => $data['decision_notes'] ?? null,
                'updated_at'         => now(),
            ]);
            $this->emitTimeline((int) $row->alert_id, $eventCode, 'WORKFLOW', $this->actorId($r),
                "Handoff {$verb}: {$row->from_level} → {$row->to_level}",
                ['handoff_id' => $id, 'decision_notes' => $data['decision_notes'] ?? null],
                'HANDOFF', $id);
            return $this->ok(['status' => $newStatus]);
        }, 'decideHandoff');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  ESCALATION · REOPEN · REASSIGN · BREACH · PHEIC
    // ══════════════════════════════════════════════════════════════════════

    /** POST /alerts/{id}/escalate   Body: { to_level, reason, notify? } */
    public function escalate(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $alert = $this->mustAlert($id);
            $actorId = $this->actorId($r);
            if ($err = $this->guardScopeAndRole($alert, $actorId, ['DISTRICT_SUPERVISOR','PHEOC_OFFICER','NATIONAL_ADMIN'])) return $err;
            $data = $r->validate([
                'to_level' => 'required|in:DISTRICT,PHEOC,NATIONAL',
                'reason'   => 'required|string|min:10|max:500',
                'notify'   => 'nullable|boolean',
            ]);
            DB::table('alerts')->where('id', $id)->update([
                'routed_to_level'    => $data['to_level'],
                'current_owner_level' => $data['to_level'],
                'updated_at'         => now(),
            ]);
            $this->emitTimeline($id, 'ESCALATED', 'WORKFLOW', $actorId,
                'Alert escalated to ' . $data['to_level'] . ' — ' . $data['reason'],
                $data, 'ALERT', $id);

            if (! empty($data['notify'])) {
                // Use ESCALATION template via dispatcher — fan out to new level
                $alert->routed_to_level = $data['to_level'];
                NotificationDispatcher::dispatchAlertCreated($alert, $actorId ?? 0);
            }
            return $this->ok(['to_level' => $data['to_level']]);
        }, 'escalate');
    }

    /**
     * POST /alerts/{id}/reopen   Body: { reason }
     *
     * Reopen is a FORWARD transition, not a state revert (confirmed by
     * dashboard.txt §M2):
     *   · CLOSED → ACKNOWLEDGED (preserves the SLA-clock semantics — we don't
     *     forget the alert was already once ack'd).
     *   · reopen_count is incremented and reopened_at is stamped so the
     *     history shows lineage.
     *   · The previous close_category/close_note are left on the row as the
     *     timeline event carries the full before/after snapshot.
     */
    public function reopen(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $alert = $this->mustAlert($id);
            $actorId = $this->actorId($r);
            // Spec §M2: reopen is restricted to PHEOC_OFFICER+ regardless of
            // routed_to_level — districts cannot resurrect a closed alert.
            if ($err = $this->guardScopeAndRole($alert, $actorId, ['PHEOC_OFFICER','NATIONAL_ADMIN'])) return $err;
            $data = $r->validate(['reason' => 'required|string|min:10|max:500']);
            if ((string) $alert->status !== 'CLOSED') {
                return $this->err('Alert is not in CLOSED state', 409);
            }
            $now = now();
            DB::table('alerts')->where('id', $id)->update([
                'status'       => 'ACKNOWLEDGED',
                'closed_at'    => null,
                'reopened_at'  => $now,
                'reopen_count' => (int) ($alert->reopen_count ?? 0) + 1,
                'updated_at'   => $now,
            ]);
            $this->emitTimeline($id, 'ALERT_REOPENED', 'WORKFLOW', $actorId,
                'Alert reopened — ' . $data['reason'],
                [
                    'reason' => $data['reason'],
                    'previous_close_category' => $alert->close_category,
                    'previous_close_note' => $alert->close_note,
                    'previous_closed_at' => $alert->closed_at,
                    'reopen_count' => (int) ($alert->reopen_count ?? 0) + 1,
                ],
                'ALERT', $id, 'WARN');
            return $this->ok([
                'status'       => 'ACKNOWLEDGED',
                'reopen_count' => (int) ($alert->reopen_count ?? 0) + 1,
                'reason'       => $data['reason'],
            ]);
        }, 'reopen');
    }

    /** POST /alerts/{id}/reassign   Body: { owner_user_id, level?, reason? } */
    public function reassign(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $alert = $this->mustAlert($id);
            $actorId = $this->actorId($r);
            if ($err = $this->guardScopeAndRole($alert, $actorId, ['DISTRICT_SUPERVISOR','PHEOC_OFFICER','NATIONAL_ADMIN'])) return $err;
            $data = $r->validate([
                'owner_user_id' => 'required|integer|min:1',
                'level'         => 'nullable|in:DISTRICT,PHEOC,NATIONAL',
                'reason'        => 'nullable|string|max:500',
            ]);
            $user = DB::table('users')->where('id', $data['owner_user_id'])->first();
            if (! $user) return $this->err('Target user not found', 404);
            DB::table('alerts')->where('id', $id)->update([
                'current_owner_user_id' => $data['owner_user_id'],
                'current_owner_level'   => $data['level'] ?? null,
                'updated_at'            => now(),
            ]);
            $this->emitTimeline($id, 'REASSIGNED', 'WORKFLOW', $this->actorId($r),
                'Case owner set to ' . ($user->full_name ?? ('#' . $user->id)),
                $data, 'ALERT', $id);
            return $this->ok(['owner' => $user->full_name ?? null]);
        }, 'reassign');
    }

    /**
     * POST /alerts/{id}/breach-report
     * Body: { phase, target_hours, elapsed_hours, root_cause_category,
     *         root_cause_text, mitigation_plan, owner_user_id?, owner_level?,
     *         target_resolve_at?, contributing_factors?: array }
     */
    public function logBreachReport(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $alert = $this->mustAlert($id);
            $actorId = $this->actorId($r);
            if ($err = $this->guardScopeAndRole($alert, $actorId, ['DISTRICT_SUPERVISOR','PHEOC_OFFICER','NATIONAL_ADMIN'])) return $err;
            $data = $r->validate([
                'phase'               => 'required|in:DETECT,NOTIFY,RESPOND',
                'target_hours'        => 'required|integer',
                'elapsed_hours'       => 'required|numeric',
                // Canonical RTSL bottleneck taxonomy. Keeping as enum prevents
                // free-text cruft poisoning the breach-cause analytics dashboard.
                'root_cause_category' => 'required|in:CAPACITY,TRAINING,COMMS,LAB,LEADERSHIP,COORDINATION,LEGAL,SUPPLIES,OTHER',
                'root_cause_text'     => 'required|string|min:10',
                'mitigation_plan'     => 'required|string|min:10',
                'owner_user_id'       => 'nullable|integer',
                'owner_level'         => 'nullable|string|max:30',
                'target_resolve_at'   => 'nullable|date',
                'contributing_factors' => 'nullable|array',
            ]);
            $breach = max(0, (int) (((float) $data['elapsed_hours'] - (int) $data['target_hours']) * 60));
            $bid = DB::table('alert_breach_reports')->insertGetId([
                'alert_id'            => $id,
                'phase'               => $data['phase'],
                'target_hours'        => (int) $data['target_hours'],
                'elapsed_hours'       => (float) $data['elapsed_hours'],
                'breach_minutes'      => $breach,
                'root_cause_category' => $data['root_cause_category'],
                'root_cause_text'     => $data['root_cause_text'],
                'contributing_factors'=> ! empty($data['contributing_factors']) ? json_encode($data['contributing_factors']) : null,
                'mitigation_plan'     => $data['mitigation_plan'],
                'owner_user_id'       => $data['owner_user_id'] ?? $actorId,
                'owner_level'         => $data['owner_level'] ?? 'DISTRICT',
                'target_resolve_at'   => $data['target_resolve_at'] ?? null,
                'status'              => 'OPEN',
                'reported_by_user_id' => $actorId,
                'created_at'          => now(), 'updated_at' => now(),
            ]);
            $this->emitTimeline($id, 'BREACH_ROOT_CAUSE_LOGGED', 'BREACH', $actorId,
                $data['phase'] . ' breach by ' . $breach . ' min — cause: ' . $data['root_cause_category'],
                ['breach_id' => $bid] + $data, 'BREACH', $bid, 'WARN');
            return $this->ok(['breach_id' => $bid]);
        }, 'logBreachReport');
    }

    /** GET /alerts/{id}/breach-reports */
    public function breachReports(int $id): JsonResponse
    {
        try {
            $rows = DB::table('alert_breach_reports')->where('alert_id', $id)
                ->orderByDesc('created_at')->get();
            return $this->ok(['breach_reports' => $rows]);
        } catch (Throwable $e) {
            return $this->fail($e, 'breachReports');
        }
    }

    /** PATCH /alert-breach-reports/{id}   Body: { status, resolution_notes?, resolved_at? } */
    public function updateBreachReport(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $row = DB::table('alert_breach_reports')->where('id', $id)->first();
            if (! $row) return $this->err('Breach report not found', 404);
            $data = $r->validate([
                'status'            => 'nullable|in:OPEN,IN_PROGRESS,RESOLVED,CANCELLED',
                'resolution_notes'  => 'nullable|string',
                'resolved_at'       => 'nullable|date',
                'mitigation_plan'   => 'nullable|string',
            ]);
            if (($data['status'] ?? null) === 'RESOLVED' && empty($data['resolved_at'])) {
                $data['resolved_at'] = now();
            }
            DB::table('alert_breach_reports')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
            $this->emitTimeline((int) $row->alert_id, 'BREACH_UPDATED', 'BREACH', $this->actorId($r),
                'Breach report updated', array_merge($data, ['breach_id' => $id]),
                'BREACH', $id);
            return $this->ok(['updated' => true]);
        }, 'updateBreachReport');
    }

    /**
     * POST /alerts/{id}/pheic-declare
     * Flags the alert as entering the WHO PHEIC coordination path. Emits a
     * PHEIC_ADVISORY email to NATIONAL + WHO contacts.
     */
    public function declarePheic(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $alert = $this->mustAlert($id);
            $actorId = $this->actorId($r);
            $data = $r->validate(['reason' => 'required|string|max:500', 'notify' => 'nullable|boolean']);
            DB::table('alerts')->where('id', $id)->update([
                'pheic_declared_at'         => now(),
                'pheic_declared_by_user_id' => $actorId,
                'routed_to_level'           => 'NATIONAL',
                'updated_at'                => now(),
            ]);
            $this->emitTimeline($id, 'PHEIC_DECLARED', 'WORKFLOW', $actorId,
                'PHEIC pathway entered — ' . $data['reason'],
                $data, 'ALERT', $id, 'CRITICAL');
            if (! empty($data['notify'])) {
                NotificationDispatcher::dispatchAlertCreated($alert, $actorId ?? 0);
            }
            return $this->ok(['pheic_declared_at' => now()->toIso8601String()]);
        }, 'declarePheic');
    }

    /**
     * POST /alerts/{id}/request-external-info
     * Body: { responder_id, message, subject? }
     * Dispatches a RESPONDER_INFO_REQUEST email and stores a token row
     * so the response can be tied back.
     */
    public function requestExternalInfo(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $this->mustAlert($id);
            $actorId = $this->actorId($r);
            $data = $r->validate([
                'responder_id' => 'required|integer',
                'message'      => 'required|string',
                'subject'      => 'nullable|string|max:240',
            ]);
            $res = NotificationDispatcher::requestExternalResponderInfo(
                (int) $data['responder_id'], $id, $actorId ?? 0,
                $data['message'], $data['subject'] ?? null);
            $this->emitTimeline($id, 'EXTERNAL_INFO_REQUESTED', 'EMAIL', $actorId,
                'Information request sent to external responder #' . $data['responder_id'],
                ['responder_id' => $data['responder_id'], 'token' => $res['token'] ?? null],
                'RESPONDER_REQUEST', (int) $data['responder_id']);
            return $this->ok($res);
        }, 'requestExternalInfo');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════════════════════════════

    private function fetchThreadedComments(int $alertId): array
    {
        $rows = DB::table('alert_comments as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.author_user_id')
            ->where('c.alert_id', $alertId)
            ->whereNull('c.deleted_at')
            ->select('c.*', 'u.full_name as author_name', 'u.role_key as author_role_key')
            ->orderBy('c.created_at')->get();

        $byParent = [];
        foreach ($rows as $r) {
            $key = $r->parent_id ? (int) $r->parent_id : 0;
            $byParent[$key][] = $r;
        }
        $attach = function (array $parents) use (&$attach, $byParent): array {
            $out = [];
            foreach ($parents as $p) {
                $children = $byParent[(int) $p->id] ?? [];
                $p->replies = $attach($children);
                $out[] = $p;
            }
            return $out;
        };
        return $attach($byParent[0] ?? []);
    }

    private function emitTimeline(
        int $alertId, string $code, string $category, ?int $actorId,
        string $summary, ?array $payload = null, ?string $relatedType = null, ?int $relatedId = null,
        string $severity = 'INFO'
    ): void {
        $actor = $actorId ? DB::table('users')->where('id', $actorId)->first() : null;
        DB::table('alert_timeline_events')->insert([
            'alert_id'       => $alertId,
            'event_code'     => $code,
            'event_category' => $category,
            'actor_user_id'  => $actorId,
            'actor_name'     => $actor->full_name ?? null,
            'actor_role'     => $actor->role_key ?? null,
            'payload_json'   => $payload ? json_encode($payload) : null,
            'summary'        => mb_substr($summary, 0, 500),
            'severity'       => $severity,
            'related_entity_type' => $relatedType,
            'related_entity_id'   => $relatedId,
            'created_at'     => now(),
        ]);
    }

    /**
     * Paranoid guard: verifies the actor exists, has an active assignment,
     * matches the alert's geographic scope, AND holds one of $allowedRoles.
     * Returns a JsonResponse to short-circuit the action, or null on success.
     */
    private function guardScopeAndRole(object $alert, ?int $actorId, array $allowedRoles): ?JsonResponse
    {
        if (! $actorId || $actorId <= 0) {
            return $this->err('actor_user_id is required (header X-User-Id or body actor_user_id).', 422);
        }
        $user = DB::table('users')->where('id', $actorId)->first();
        if (! $user) return $this->err('Actor user not found.', 404);

        $assignment = DB::table('user_assignments')
            ->where('user_id', $actorId)->where('is_primary', 1)->where('is_active', 1)
            ->where(function ($q) { $q->whereNull('ends_at')->orWhere('ends_at', '>', now()); })
            ->first();
        if (! $assignment) return $this->err('Actor has no active assignment.', 403);

        $role = $user->role_key ?? '';
        $poeRoles = ['POE_PRIMARY','POE_SECONDARY','POE_DATA_OFFICER','POE_ADMIN','SCREENER'];
        if (in_array($role, $poeRoles, true)) {
            if (($alert->poe_code ?? null) !== ($assignment->poe_code ?? null)) {
                return $this->err('Out of geographic scope (POE mismatch).', 403);
            }
        } elseif ($role === 'DISTRICT_SUPERVISOR') {
            if (($alert->district_code ?? null) !== ($assignment->district_code ?? null)) {
                return $this->err('Out of geographic scope (district mismatch).', 403);
            }
        } elseif ($role === 'PHEOC_OFFICER') {
            if (($alert->pheoc_code ?? null) !== ($assignment->pheoc_code ?? null)) {
                return $this->err('Out of geographic scope (PHEOC mismatch).', 403);
            }
        } else {
            if (($alert->country_code ?? null) !== ($assignment->country_code ?? null)) {
                return $this->err('Out of geographic scope (country mismatch).', 403);
            }
        }
        if (! in_array($role, $allowedRoles, true)) {
            return $this->err("Role {$role} is not authorised for this action.", 403);
        }
        return null;
    }

    private function mustAlert(int $id): object
    {
        $a = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $a) abort(response()->json(['ok' => false, 'error' => 'Alert not found'], 404));
        return $a;
    }

    private function actorId(Request $r): ?int
    {
        $hdr = $r->header('X-User-Id');
        if ($hdr !== null && ctype_digit((string) $hdr)) return (int) $hdr;
        $body = $r->input('actor_user_id');
        return is_numeric($body) ? (int) $body : null;
    }

    private static function summarise(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/', ' ', strip_tags($s)) ?: $s);
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    private function ok(array $data = [], int $code = 200): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $data], $code);
    }

    private function err(string $msg, int $code = 400): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $msg], $code);
    }

    private function fail(Throwable $e, string $ctx): JsonResponse
    {
        Log::error("[AlertCollaboration::{$ctx}] " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return response()->json(['ok' => false, 'error' => $e->getMessage(), 'ctx' => $ctx], 500);
    }

    private function tx(callable $fn, string $ctx): JsonResponse
    {
        try {
            return DB::transaction($fn);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->fail($e, $ctx);
        }
    }
}
