<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * AlertsLifecycleController
 *
 * Mobile-first endpoints that close the gap between the existing canonical
 * AlertsController (FSM: list/show/store/acknowledge/close) and the
 * AlertCollaborationController (war-room ops). This controller exposes:
 *
 *   GET   /alerts/{id}/case-file                  Aggregated investigation surface
 *   GET   /alerts/{id}/advisor                    AlertAdvisor recommendation
 *   GET   /alerts/{id}/case-outcome               Latest alert_case_outcomes row
 *   POST  /alerts/{id}/case-outcome               Upsert outcome (mobile wizard)
 *   POST  /alert-followups/{id}/resolve-blocker   Mark a blocker COMPLETED|NOT_APPLICABLE
 *
 * Every endpoint is paranoid:
 *   - resolves the actor + primary assignment
 *   - enforces geographic scope (PheocScope-style: POE/DISTRICT/PHEOC/COUNTRY)
 *   - role-gates writes (acknowledge-roles ladder for outcome + blocker resolve)
 *   - emits timeline events for every state change
 */
final class AlertsLifecycleController extends Controller
{
    private const POE_ROLES = ['POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'POE_ADMIN', 'SCREENER'];

    /** Roles that may record/edit case outcomes or resolve blockers, by alert.routed_to_level */
    private const WRITE_ROLES = [
        'POE'      => ['POE_ADMIN', 'DISTRICT_SUPERVISOR', 'PHEOC_OFFICER', 'NATIONAL_ADMIN'],
        'DISTRICT' => ['DISTRICT_SUPERVISOR', 'PHEOC_OFFICER', 'NATIONAL_ADMIN'],
        'PHEOC'    => ['DISTRICT_SUPERVISOR', 'PHEOC_OFFICER', 'NATIONAL_ADMIN'],
        'NATIONAL' => ['PHEOC_OFFICER', 'NATIONAL_ADMIN'],
    ];

    private const CASE_CLASSIFICATIONS = ['SUSPECTED','PROBABLE','CONFIRMED','DISCARDED','LOST_TO_FOLLOWUP','UNKNOWN'];
    private const LAB_STATUSES         = ['PENDING','POSITIVE','NEGATIVE','INCONCLUSIVE','INSUFFICIENT_SAMPLE','NOT_TESTED'];
    private const CLINICAL_OUTCOMES    = ['RECOVERED','CONVALESCING','DECEASED','LOST_TO_FOLLOWUP','TRANSFERRED','UNKNOWN'];
    private const PH_ACTIONS           = ['STANDARD_SURVEILLANCE','ENHANCED_SURVEILLANCE','OUTBREAK_INVESTIGATION','OUTBREAK_RESPONSE','IHR_NOTIFIED'];
    private const OUTBREAK_STATUSES    = ['SPORADIC','CLUSTER','OUTBREAK','EPIDEMIC','PANDEMIC','NONE'];
    private const OUTCOME_SOURCES      = ['WIZARD','MASTER_CLOSE','FALSE_ALARM','LAB_RESULT','MANUAL'];

    // ════════════════════════════════════════════════════════════════════════
    //  GET /alerts/{id}/case-file
    //  Returns the full investigation surface for the war-room: alert + screening
    //  + symptoms + exposures + travel + samples + suspected diseases + followups
    //  + comments + evidence + timeline + case_outcome + advisor recommendation.
    //  Mobile renders this in a single page with tabs.
    // ════════════════════════════════════════════════════════════════════════
    public function caseFile(Request $request, int $id): JsonResponse
    {
        [$user, $assignment, $err] = $this->actor($request);
        if ($err) return $err;

        try {
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Alert not found.', ['id' => $id]);

            if ($scopeErr = $this->checkScope($alert, $assignment, $user)) return $scopeErr;

            $screeningId = (int) ($alert->secondary_screening_id ?? 0);
            $screening   = $screeningId > 0
                ? DB::table('secondary_screenings')->where('id', $screeningId)->first()
                : null;

            // 3-G FIX (defense in depth): refuse to surface a screening that lives
            // in a different country than the requesting user, even if the alert
            // header passes scope (production has retro-patched alerts whose linked
            // screening is still in another country). Drop the screening payload
            // rather than exposing a notification_client_uuid that points to data
            // the user cannot read — the war-room "Open case file" CTA stays
            // disabled instead of leading to a stuck loader.
            if ($screening && !empty($screening->country_code)
                && $screening->country_code !== ($assignment->country_code ?? null)) {
                $screening = null;
            }

            // FIX: the mobile case-viewer route is /secondary-screening/:notificationId
            // and the param is the notification's client_uuid (per the route comment in
            // src/router/index.js). Resolve it once here so the war-room can link
            // straight to the full case viewer instead of inlining a partial render.
            if ($screening && !empty($screening->notification_id)) {
                $notif = DB::table('notifications')
                    ->where('id', $screening->notification_id)
                    ->select('client_uuid')->first();
                if ($notif) {
                    $screening->notification_client_uuid = $notif->client_uuid;
                }
            }

            $symptoms        = $screeningId ? DB::table('secondary_symptoms')->where('secondary_screening_id', $screeningId)->get() : collect();
            $exposures       = $screeningId ? DB::table('secondary_exposures')->where('secondary_screening_id', $screeningId)->get() : collect();
            $travel          = $screeningId ? DB::table('secondary_travel_countries')->where('secondary_screening_id', $screeningId)->get() : collect();
            $samples         = $screeningId ? DB::table('secondary_samples')->where('secondary_screening_id', $screeningId)->get() : collect();
            $actions         = $screeningId ? DB::table('secondary_actions')->where('secondary_screening_id', $screeningId)->get() : collect();
            // Hydrate suspected diseases. We intentionally avoid a JOIN with
            // ref_diseases because (a) the column for the human-readable name
            // drifts (dev: disease_name; prod: display_name) and (b) the two
            // tables can have different collations on the disease_code column,
            // which raises an "Illegal mix of collations" error in MySQL. Two
            // queries + a PHP merge is cheap (typically ≤6 rows) and robust.
            $suspected = collect();
            if ($screeningId) {
                $sdRows = DB::table('secondary_suspected_diseases')
                    ->where('secondary_screening_id', $screeningId)
                    ->orderByDesc('confidence')
                    ->get();
                $codes = $sdRows->pluck('disease_code')->filter()->unique()->values()->all();
                $diseaseMap = [];
                if ($codes) {
                    $nameCol = \Schema::hasColumn('ref_diseases', 'disease_name') ? 'disease_name'
                        : (\Schema::hasColumn('ref_diseases', 'display_name') ? 'display_name' : null);
                    $cols = ['disease_code', 'case_definition', 'incubation_days_min', 'incubation_days_max'];
                    if ($nameCol) $cols[] = $nameCol;
                    foreach (['case_fatality_rate', 'notifiable_who'] as $opt) {
                        if (\Schema::hasColumn('ref_diseases', $opt)) $cols[] = $opt;
                    }
                    $rows = DB::table('ref_diseases')->whereIn('disease_code', $codes)->select($cols)->get();
                    foreach ($rows as $r) {
                        $arr = (array) $r;
                        if ($nameCol && $nameCol !== 'disease_name') {
                            $arr['disease_name'] = $arr[$nameCol] ?? null;
                        }
                        $diseaseMap[$r->disease_code] = $arr;
                    }
                }
                $suspected = $sdRows->map(function ($sd) use ($diseaseMap) {
                    $merged = (array) $sd;
                    if (isset($diseaseMap[$sd->disease_code])) {
                        $merged = array_merge($diseaseMap[$sd->disease_code], $merged);
                    }
                    return (object) $merged;
                });
            }

            $followups = DB::table('alert_followups')
                ->where('alert_id', $id)->whereNull('deleted_at')
                ->orderBy('due_at')->orderBy('id')->get();

            $blockers = $followups->filter(fn ($f) => (int) ($f->blocks_closure ?? 0) === 1
                && ! in_array($f->status, ['COMPLETED','NOT_APPLICABLE'], true))->values();

            $timeline = DB::table('alert_timeline_events')
                ->where('alert_id', $id)->orderByDesc('created_at')->limit(200)->get();

            $comments = DB::table('alert_comments')
                ->where('alert_id', $id)->whereNull('deleted_at')
                ->orderByDesc('created_at')->limit(200)->get();

            $evidence = DB::table('alert_evidence')
                ->where('alert_id', $id)->whereNull('deleted_at')
                ->orderByDesc('created_at')->get();

            $outcome = DB::table('alert_case_outcomes')
                ->where('alert_id', $id)->whereNull('deleted_at')->first();

            $breaches = DB::table('alert_breach_reports')
                ->where('alert_id', $id)->orderByDesc('created_at')->get();

            $advisor = $this->safeAdvisor($id);

            return $this->ok([
                'alert'      => (array) $alert,
                'scope'      => $this->scopeDescriptor($user, $assignment),
                'screening'  => $screening ? (array) $screening : null,
                'symptoms'   => $symptoms->values()->all(),
                'exposures'  => $exposures->values()->all(),
                'travel'     => $travel->values()->all(),
                'samples'    => $samples->values()->all(),
                'actions'    => $actions->values()->all(),
                'suspected_diseases' => $suspected->values()->all(),
                'followups'  => $followups->values()->all(),
                'blockers'   => $blockers->values()->all(),
                'comments'   => $comments->values()->all(),
                'evidence'   => $evidence->values()->all(),
                'timeline'   => $timeline->values()->all(),
                'case_outcome' => $outcome ? (array) $outcome : null,
                'breach_reports' => $breaches->values()->all(),
                'advisor'    => $advisor,
                'permissions' => $this->permissionsFor($user, $alert),
            ], 'Case file retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'caseFile');
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  GET /alerts/{id}/advisor
    //  Returns the AlertAdvisor recommendation in isolation (PPE / IHR /
    //  referral / samples / traveler script + rule citations).
    // ════════════════════════════════════════════════════════════════════════
    public function advisor(Request $request, int $id): JsonResponse
    {
        [$user, $assignment, $err] = $this->actor($request);
        if ($err) return $err;

        $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $alert) return $this->err(404, 'Alert not found.');
        if ($scopeErr = $this->checkScope($alert, $assignment, $user)) return $scopeErr;

        $payload = $this->safeAdvisor($id);
        // FIX: returning 503 forced the mobile client to log an error and the
        // Advisor tab to show an error UI. Return a graceful "insufficient"
        // payload so the war-room shows the empty-state card cleanly.
        if ($payload === null) {
            $payload = [
                'sufficient'     => false,
                'missing_inputs' => [['field' => 'service', 'why' => 'Advisor temporarily unavailable.']],
                'rules_fired'    => [],
                'recommendation' => null,
            ];
        }
        return $this->ok($payload, 'Advisor recommendation.');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  GET /alerts/{id}/case-outcome
    //  Returns the current outcome row (or null). Read-scoped.
    // ════════════════════════════════════════════════════════════════════════
    public function getOutcome(Request $request, int $id): JsonResponse
    {
        [$user, $assignment, $err] = $this->actor($request);
        if ($err) return $err;

        $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $alert) return $this->err(404, 'Alert not found.');
        if ($scopeErr = $this->checkScope($alert, $assignment, $user)) return $scopeErr;

        $row = DB::table('alert_case_outcomes')->where('alert_id', $id)->whereNull('deleted_at')->first();
        return $this->ok(['case_outcome' => $row ? (array) $row : null], 'Outcome retrieved.');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  POST /alerts/{id}/case-outcome
    //  Upsert the outcome row from the mobile wizard. Soft-deletes any prior
    //  active row to keep the (alert_id, deleted_at) unique constraint clean,
    //  preserving audit history.
    //
    //  Body fields (all validated against the migration enums):
    //    case_classification (req)  case_classification_reason
    //    lab_status                  lab_disease_code   lab_test_method
    //    lab_confirmed_at            clinical_outcome   clinical_outcome_at
    //    ph_action                   outbreak_status
    //    ihr_notified (bool)         ihr_notified_at    ihr_reference
    //    notes                       source (default WIZARD)
    // ════════════════════════════════════════════════════════════════════════
    public function upsertOutcome(Request $request, int $id): JsonResponse
    {
        [$user, $assignment, $err] = $this->actor($request);
        if ($err) return $err;

        $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $alert) return $this->err(404, 'Alert not found.');
        if ($scopeErr = $this->checkScope($alert, $assignment, $user)) return $scopeErr;

        if ($roleErr = $this->checkWriteRole($alert, $user)) return $roleErr;

        $cls = strtoupper(trim((string) $request->input('case_classification', '')));
        if (! in_array($cls, self::CASE_CLASSIFICATIONS, true)) {
            return $this->err(422, 'case_classification is required.', ['allowed' => self::CASE_CLASSIFICATIONS]);
        }
        $lab = $this->enumOrNull($request->input('lab_status'), self::LAB_STATUSES);
        if ($request->has('lab_status') && $lab === null && trim((string) $request->input('lab_status')) !== '') {
            return $this->err(422, 'Invalid lab_status.', ['allowed' => self::LAB_STATUSES]);
        }
        $clinical = $this->enumOrNull($request->input('clinical_outcome'), self::CLINICAL_OUTCOMES);
        if ($request->has('clinical_outcome') && $clinical === null && trim((string) $request->input('clinical_outcome')) !== '') {
            return $this->err(422, 'Invalid clinical_outcome.', ['allowed' => self::CLINICAL_OUTCOMES]);
        }
        $phAction = $this->enumOrNull($request->input('ph_action'), self::PH_ACTIONS);
        $outbreak = $this->enumOrNull($request->input('outbreak_status'), self::OUTBREAK_STATUSES) ?? 'NONE';
        $source   = $this->enumOrNull($request->input('source'), self::OUTCOME_SOURCES) ?? 'WIZARD';

        $ihrNotified = (bool) $request->input('ihr_notified', false);
        $ihrAt       = $ihrNotified ? ($request->input('ihr_notified_at') ?: now()->format('Y-m-d H:i:s')) : null;
        $ihrRef      = $ihrNotified ? mb_substr((string) $request->input('ihr_reference', ''), 0, 120) : null;

        $now = now()->format('Y-m-d H:i:s');

        try {
            return DB::transaction(function () use ($id, $alert, $user, $cls, $lab, $clinical, $phAction, $outbreak, $source, $ihrNotified, $ihrAt, $ihrRef, $request, $now) {
                // Soft-delete prior active row so the unique index stays clean.
                DB::table('alert_case_outcomes')
                    ->where('alert_id', $id)->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);

                $newId = DB::table('alert_case_outcomes')->insertGetId([
                    'alert_id'                  => $id,
                    'case_classification'       => $cls,
                    'case_classification_reason'=> $this->trimOrNull($request->input('case_classification_reason'), 2000),
                    'lab_status'                => $lab,
                    'lab_disease_code'          => $this->trimOrNull($request->input('lab_disease_code'), 80),
                    'lab_test_method'           => $this->trimOrNull($request->input('lab_test_method'), 120),
                    'lab_confirmed_at'          => $request->input('lab_confirmed_at') ?: null,
                    'clinical_outcome'          => $clinical,
                    'clinical_outcome_at'       => $request->input('clinical_outcome_at') ?: null,
                    'ph_action'                 => $phAction,
                    'outbreak_status'           => $outbreak,
                    'ihr_notified'              => $ihrNotified ? 1 : 0,
                    'ihr_notified_at'           => $ihrAt,
                    'ihr_reference'             => $ihrRef,
                    'recorded_by_user_id'       => (int) $user->id,
                    'recorded_at'               => $now,
                    'source'                    => $source,
                    'notes'                     => $this->trimOrNull($request->input('notes'), 2000),
                    'payload'                   => $request->input('payload') ? json_encode($request->input('payload')) : null,
                    'created_at'                => $now,
                    'updated_at'                => $now,
                ]);

                $this->emitTimeline($id, 'CASE_OUTCOME_RECORDED', 'CLINICAL', (int) $user->id,
                    'Case classified as ' . $cls . ($lab ? ' (lab ' . $lab . ')' : ''),
                    [
                        'outcome_id' => $newId,
                        'case_classification' => $cls,
                        'lab_status' => $lab,
                        'clinical_outcome' => $clinical,
                        'ph_action' => $phAction,
                        'outbreak_status' => $outbreak,
                        'ihr_notified' => $ihrNotified,
                        'source' => $source,
                    ], 'CASE_OUTCOME', $newId, $cls === 'CONFIRMED' ? 'WARN' : 'INFO',
                    (string) ($user->full_name ?? $user->username ?? ''),
                    (string) ($user->role_key ?? ''));

                $row = DB::table('alert_case_outcomes')->where('id', $newId)->first();
                return $this->ok((array) $row, 'Case outcome recorded.');
            });
        } catch (Throwable $e) {
            return $this->serverError($e, 'upsertOutcome');
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  POST /alert-followups/{id}/resolve-blocker
    //  Body: { resolution: COMPLETED|NOT_APPLICABLE, reason (≥10 chars), evidence_ref? }
    //
    //  Mobile flow: from CloseWizard, when the user hits the "blockers" card,
    //  each open blocker exposes an "Unblock" button → posts here. Logs a
    //  timeline event tagged BLOCKER_RESOLVED so the audit trail records both
    //  the followup transition AND the resolution rationale.
    // ════════════════════════════════════════════════════════════════════════
    public function resolveBlocker(Request $request, int $followupId): JsonResponse
    {
        [$user, $assignment, $err] = $this->actor($request);
        if ($err) return $err;

        $row = DB::table('alert_followups')->where('id', $followupId)->whereNull('deleted_at')->first();
        if (! $row) return $this->err(404, 'Follow-up not found.');

        $alert = DB::table('alerts')->where('id', $row->alert_id)->whereNull('deleted_at')->first();
        if (! $alert) return $this->err(404, 'Parent alert not found.');
        if ($scopeErr = $this->checkScope($alert, $assignment, $user)) return $scopeErr;
        if ($roleErr = $this->checkWriteRole($alert, $user)) return $roleErr;

        $resolution = strtoupper(trim((string) $request->input('resolution', '')));
        if (! in_array($resolution, ['COMPLETED','NOT_APPLICABLE'], true)) {
            return $this->err(422, 'resolution must be COMPLETED or NOT_APPLICABLE.');
        }
        $reason = trim((string) $request->input('reason', ''));
        if (mb_strlen($reason) < 10) {
            return $this->err(422, 'A resolution reason of at least 10 characters is required.');
        }
        $evidenceRef = $this->trimOrNull($request->input('evidence_ref'), 500);
        $now = now()->format('Y-m-d H:i:s');

        try {
            return DB::transaction(function () use ($row, $resolution, $reason, $evidenceRef, $now, $user, $alert) {
                $update = [
                    'status'         => $resolution,
                    'updated_at'     => $now,
                    'record_version' => (int) $row->record_version + 1,
                    'notes'          => mb_substr((string) ($row->notes ? $row->notes . "\n— " : '') . '[BLOCKER_RESOLVED] ' . $reason, 0, 500),
                ];
                if ($evidenceRef) $update['evidence_ref'] = $evidenceRef;
                if ($resolution === 'COMPLETED') {
                    $update['completed_at']        = $now;
                    $update['completed_by_user_id'] = (int) $user->id;
                }
                DB::table('alert_followups')->where('id', $row->id)->update($update);

                $this->emitTimeline((int) $row->alert_id, 'BLOCKER_RESOLVED', 'WORKFLOW', (int) $user->id,
                    'Blocker "' . ($row->action_label ?? $row->action_code) . '" → ' . $resolution,
                    [
                        'followup_id'    => $row->id,
                        'action_code'    => $row->action_code,
                        'previous_status'=> $row->status,
                        'new_status'     => $resolution,
                        'reason'         => $reason,
                        'evidence_ref'   => $evidenceRef,
                    ], 'FOLLOWUP', (int) $row->id, 'INFO',
                    (string) ($user->full_name ?? $user->username ?? ''),
                    (string) ($user->role_key ?? ''));

                $fresh = DB::table('alert_followups')->where('id', $row->id)->first();
                $remaining = DB::table('alert_followups')
                    ->where('alert_id', $row->alert_id)
                    ->where('blocks_closure', 1)
                    ->whereNotIn('status', ['COMPLETED','NOT_APPLICABLE'])
                    ->whereNull('deleted_at')->count();

                return $this->ok([
                    'followup'           => (array) $fresh,
                    'remaining_blockers' => $remaining,
                    'closure_unblocked'  => $remaining === 0,
                ], 'Blocker resolved.');
            });
        } catch (Throwable $e) {
            return $this->serverError($e, 'resolveBlocker');
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  GET /alerts/{id}/comms-inbox
    //  Returns notification_log entries scoped to this alert. Read-only.
    // ════════════════════════════════════════════════════════════════════════
    public function commsInbox(Request $request, int $id): JsonResponse
    {
        [$user, $assignment, $err] = $this->actor($request);
        if ($err) return $err;
        $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $alert) return $this->err(404, 'Alert not found.');
        if ($scopeErr = $this->checkScope($alert, $assignment, $user)) return $scopeErr;

        try {
            // FIX: notification_log columns are `related_entity_type` / `related_entity_id`
            // (not `entity_type` / `entity_id`). Verified against the live ecsa_rwanda_2026
            // schema. Using the wrong column names produced a 500 on every request.
            $rows = DB::table('notification_log')
                ->where('related_entity_type', 'ALERT')
                ->where('related_entity_id', $id)
                ->orderByDesc('created_at')
                ->limit(200)
                ->get();
            return $this->ok(['notifications' => $rows->values()->all()], 'Notifications retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'commsInbox');
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    //  HELPERS
    // ────────────────────────────────────────────────────────────────────────

    private function actor(Request $r): array
    {
        $userId = (int) ($r->user()?->id ?? $r->input('user_id', $r->query('user_id', 0)));
        if ($userId <= 0) return [null, null, $this->err(422, 'user_id is required.')];
        $user = DB::table('users')->where('id', $userId)->first();
        if (! $user) return [null, null, $this->err(404, 'User not found.')];
        $assignment = DB::table('user_assignments')
            ->where('user_id', $userId)->where('is_primary', 1)->where('is_active', 1)
            ->where(function ($q) { $q->whereNull('ends_at')->orWhere('ends_at', '>', now()); })
            ->first();
        if (! $assignment) return [$user, null, $this->err(403, 'No active assignment.')];
        return [$user, $assignment, null];
    }

    private function checkScope(object $alert, object $assignment, object $user): ?JsonResponse
    {
        $role = $user->role_key ?? '';
        if (in_array($role, self::POE_ROLES, true)) {
            if (($alert->poe_code ?? null) !== ($assignment->poe_code ?? null)) {
                return $this->err(403, 'Alert is in a different POE.');
            }
        } elseif ($role === 'DISTRICT_SUPERVISOR') {
            if (($alert->district_code ?? null) !== ($assignment->district_code ?? null)) {
                return $this->err(403, 'Alert is in a different district.');
            }
        } elseif ($role === 'PHEOC_OFFICER') {
            if (($alert->pheoc_code ?? null) !== ($assignment->pheoc_code ?? null)) {
                return $this->err(403, 'Alert is in a different PHEOC region.');
            }
        } else {
            // NATIONAL_ADMIN — country gate only
            if (($alert->country_code ?? null) !== ($assignment->country_code ?? null)) {
                return $this->err(403, 'Alert is in a different country.');
            }
        }
        return null;
    }

    private function checkWriteRole(object $alert, object $user): ?JsonResponse
    {
        $allowed = self::WRITE_ROLES[$alert->routed_to_level] ?? [];
        $role    = $user->role_key ?? '';
        if (! in_array($role, $allowed, true)) {
            return $this->err(403, "Your role ({$role}) cannot write at {$alert->routed_to_level} level.", [
                'required_roles' => $allowed,
                'your_role'      => $role,
            ]);
        }
        return null;
    }

    private function permissionsFor(object $user, object $alert): array
    {
        $role  = $user->role_key ?? '';
        $level = $alert->routed_to_level ?? '';
        $writeAllowed   = in_array($role, self::WRITE_ROLES[$level] ?? [], true);
        $isNational     = $role === 'NATIONAL_ADMIN';
        $isDistrictPlus = in_array($role, ['DISTRICT_SUPERVISOR', 'PHEOC_OFFICER', 'NATIONAL_ADMIN'], true);
        $isPheocPlus    = in_array($role, ['PHEOC_OFFICER', 'NATIONAL_ADMIN'], true);
        return [
            'can_acknowledge'        => $writeAllowed && $alert->status === 'OPEN',
            'can_close'              => $writeAllowed && in_array($alert->status, ['OPEN','ACKNOWLEDGED'], true),
            'can_reopen'             => $isPheocPlus && $alert->status === 'CLOSED',
            'can_reassign'           => $writeAllowed,
            'can_escalate'           => $writeAllowed && $alert->status !== 'CLOSED',
            'can_record_outcome'     => $writeAllowed,
            'can_resolve_blocker'    => $writeAllowed,
            'can_log_breach'         => $writeAllowed,
            'can_override_blockers'  => $isNational,
            'can_declare_pheic'      => $isNational,
            'can_upload_evidence'    => true,
            'can_comment'            => $role !== 'OBSERVER',
        ];
    }

    private function scopeDescriptor(object $user, object $assignment): array
    {
        return [
            'user_id'       => (int) $user->id,
            'role_key'      => $user->role_key ?? null,
            'country_code'  => $assignment->country_code ?? null,
            'pheoc_code'    => $assignment->pheoc_code ?? null,
            'district_code' => $assignment->district_code ?? null,
            'poe_code'      => $assignment->poe_code ?? null,
        ];
    }

    /**
     * FIX: AlertAdvisor::compute() is STATIC and takes 7 args
     * (alert, screening, symptoms, exposures, actions, suspected, intel).
     * The previous implementation called `$svc->compute($alertId)` which
     * always threw an "ArgumentCountError" → null → controller 503'd on
     * every request. Now we gather the needed data and call compute()
     * with the correct signature.
     */
    private function safeAdvisor(int $alertId): ?array
    {
        try {
            $alert = DB::table('alerts')->where('id', $alertId)->whereNull('deleted_at')->first();
            if (!$alert) return null;

            $screeningId = (int) ($alert->secondary_screening_id ?? 0);
            $screening = $screeningId > 0
                ? DB::table('secondary_screenings')->where('id', $screeningId)->first()
                : null;

            $symptoms  = $screeningId ? DB::table('secondary_symptoms')->where('secondary_screening_id', $screeningId)->get() : collect();
            $exposures = $screeningId ? DB::table('secondary_exposures')->where('secondary_screening_id', $screeningId)->get() : collect();
            $actions   = $screeningId ? DB::table('secondary_actions')->where('secondary_screening_id', $screeningId)->get() : collect();
            $suspected = $screeningId ? DB::table('secondary_suspected_diseases')
                ->where('secondary_screening_id', $screeningId)->orderByDesc('confidence')->get() : collect();

            $intel = [
                'ihr_risk' => [
                    'ihr_tier' => (string) ($alert->ihr_tier ?? ''),
                ],
                'risk_level'      => (string) ($alert->risk_level ?? ''),
                'routed_to_level' => (string) ($alert->routed_to_level ?? ''),
            ];

            $payload = \App\Services\AlertAdvisor::compute(
                (object) $alert,
                $screening ? (object) $screening : null,
                $symptoms,
                $exposures,
                $actions,
                $suspected,
                $intel
            );
            return is_array($payload) ? $payload : (array) $payload;
        } catch (Throwable $e) {
            Log::warning('[AlertsLifecycle][advisor] ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
        return null;
    }

    private function emitTimeline(int $alertId, string $code, string $category, ?int $actorId,
                                  string $summary, ?array $payload = null,
                                  ?string $relatedType = null, ?int $relatedId = null,
                                  string $severity = 'INFO',
                                  string $actorName = '', string $actorRole = ''): void
    {
        try {
            DB::table('alert_timeline_events')->insert([
                'alert_id'            => $alertId,
                'event_code'          => $code,
                'event_category'      => $category,
                'actor_user_id'       => $actorId,
                'actor_name'          => $actorName ?: null,
                'actor_role'          => $actorRole ?: null,
                'payload_json'        => $payload ? json_encode($payload) : null,
                'summary'             => mb_substr($summary, 0, 500),
                'severity'            => $severity,
                'related_entity_type' => $relatedType,
                'related_entity_id'   => $relatedId,
                'created_at'          => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('[AlertsLifecycle] timeline emit failed: ' . $e->getMessage());
        }
    }

    private function enumOrNull(mixed $raw, array $allowed): ?string
    {
        if ($raw === null) return null;
        $s = strtoupper(trim((string) $raw));
        return in_array($s, $allowed, true) ? $s : null;
    }

    private function trimOrNull(mixed $raw, int $maxLen): ?string
    {
        if ($raw === null) return null;
        $s = trim((string) $raw);
        return $s === '' ? null : mb_substr($s, 0, $maxLen);
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'message' => $message, 'data' => $data];
        if (! empty($meta)) $body['meta'] = $meta;
        return response()->json($body, 200);
    }

    private function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error' => $detail], $status);
    }

    private function serverError(Throwable $e, string $ctx): JsonResponse
    {
        Log::error("[AlertsLifecycle][ERROR] {$ctx}", [
            'exception' => get_class($e), 'message' => $e->getMessage(),
            'file' => basename($e->getFile()), 'line' => $e->getLine(),
        ]);
        return response()->json([
            'success' => false,
            'message' => "Server error during: {$ctx}",
            'error'   => ['exception' => get_class($e), 'message' => $e->getMessage()],
        ], 500);
    }
}
