<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Alerts;

use App\Http\Controllers\AlertCollaborationController;
use App\Http\Controllers\AlertsController as ApiAlertsController;
use App\Http\Controllers\Controller;
use App\Support\Alerts\HumanLabels;
use App\Support\Alerts\PriorityRules;
use App\Support\Scope\ScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin · Section 03 · Alert Hub (master list + per-alert dossier).
 *
 * ─── DESIGN ─────────────────────────────────────────────────────────────
 * Reads are owned here (scope-aware via ScopeFilter::applyToAlerts).
 * Writes DELEGATE to the existing API controllers so the canonical FSM,
 * role-gating, idempotency, NotificationDispatcher fan-out, and timeline
 * emission stay in one place. We never bypass the FSM, never duplicate it.
 *
 * Pre-flight gate (NEW): close() refuses to forward when there is at least
 * one alert_followup with blocks_closure=1 AND status NOT IN
 * (COMPLETED, NOT_APPLICABLE) — unless the actor is NATIONAL_ADMIN AND the
 * request explicitly carries override_blocking_followups=1 with a ≥30-char
 * justification.  This is the contract from dashboard.txt §B.7 + the
 * Section 03 plan.
 *
 * Auth + scope are enforced by route middleware (web · auth · scope ·
 * role:…). All writes additionally honour the existing API controllers'
 * role gating (ACKNOWLEDGE_ROLES per routed_to_level).
 *
 * Append-only: this controller NEVER updates or deletes
 * alert_timeline_events. Every state change emits exactly one event via
 * the underlying API controller (which uses emitTimeline()).
 * ────────────────────────────────────────────────────────────────────────
 */
final class AlertsController extends Controller
{
    /** Tabs honoured by data() — keep in sync with the view's tab list. */
    private const TABS = ['open', 'acknowledged', 'closed', 'reopened', 'all'];

    private const RISKS  = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    // alerts.ihr_tier is varchar(40). CaseOutcomeRecorder + CaseFileController
    // both work with the long-form values that the IDSR engine emits.
    // The short forms ('TIER_1', 'NONE') match zero live rows — exposing them in
    // the filter dropdown produced a guaranteed-empty result set.
    private const TIERS  = ['TIER_1_ALWAYS_NOTIFIABLE', 'TIER_2_ANNEX2', 'TIER_3_ROUTINE'];
    private const LEVELS = ['DISTRICT', 'PHEOC', 'NATIONAL'];

    private const MAX_PER_PAGE   = 100;
    private const TIMELINE_LIMIT = 80;

    /* ═════════════════════════ views ═════════════════════════ */

    public function index(Request $r)
    {
        return view('admin.alerts.master.index');
    }

    /* ═════════════════════════ reads ═════════════════════════ */

    /**
     * GET /admin/alerts/data
     * Tabs · filter chips · cursor pagination · scope-filtered.
     *
     * Query: status (open|acknowledged|closed|reopened|all)
     *        risk_level, ihr_tier, routed_to_level, district, poe, owner_user_id
     *        q (free-text on alert_code, alert_title, alert_details)
     *        cursor (alerts.id, descending)
     *        per_page (clamped 10..100)
     */
    public function data(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);

            $tab     = strtolower((string) $r->query('status', 'open'));
            if (! in_array($tab, self::TABS, true)) $tab = 'open';

            $perPage = max(10, min(self::MAX_PER_PAGE, (int) $r->query('per_page', 50)));
            $cursor  = (int) $r->query('cursor', 0);

            $base = fn () => ScopeFilter::applyToAlerts(
                DB::table('alerts')->whereNull('deleted_at'), $scope, 'alerts'
            );

            $rowsQ = (clone $base());
            $this->applyTab($rowsQ, $tab);
            $this->applyFilters($rowsQ, $r);
            if ($cursor > 0) $rowsQ->where('alerts.id', '<', $cursor);

            $rows = $rowsQ
                ->leftJoin('users as owner', 'owner.id', '=', 'alerts.current_owner_user_id')
                ->leftJoin('users as ack',   'ack.id',   '=', 'alerts.acknowledged_by_user_id')
                ->select([
                    'alerts.*',
                    'owner.full_name as owner_name', 'owner.role_key as owner_role',
                    'ack.full_name as ack_name',     'ack.role_key as ack_role',
                ])
                ->orderByDesc('alerts.id')
                ->limit($perPage + 1)
                ->get();

            $nextCursor = null;
            if ($rows->count() > $perPage) {
                $tail       = $rows->pop();
                $nextCursor = (int) $tail->id;
            }

            // Followup blocking lookup for the visible page (one query).
            $alertIds = $rows->pluck('id')->all();
            $blockingByAlert = [];
            if ($alertIds) {
                $blockingByAlert = DB::table('alert_followups')
                    ->select('alert_id', DB::raw('COUNT(*) as c'))
                    ->whereIn('alert_id', $alertIds)
                    ->whereNull('deleted_at')
                    ->where('blocks_closure', 1)
                    ->whereNotIn('status', ['COMPLETED', 'NOT_APPLICABLE'])
                    ->groupBy('alert_id')->pluck('c', 'alert_id')->all();
            }

            // Suspected diseases (up to 3 per alert) + traveller name (batched joins).
            // Rank-1 disease drives the headline label; ranks 2–3 surface as
            // secondary chips on the master row so operators see all hypotheses
            // the engine produced, not just the top one.
            $diseasesByAlert  = $this->topDiseasesMulti($alertIds, 3);
            $travellerByAlert = $this->travellersFor($alertIds);

            $castRows = $rows->map(function ($a) use ($blockingByAlert, $diseasesByAlert, $travellerByAlert) {
                $aid = (int) $a->id;
                $list = $diseasesByAlert[$aid] ?? [];
                $top  = $list[0]['disease_code'] ?? null;
                return $this->castRow(
                    $a,
                    (int) ($blockingByAlert[$a->id] ?? 0),
                    $top,
                    $travellerByAlert[$aid] ?? null,
                    $list,
                );
            })->all();

            $tabs = $this->countTabs($base, $r);

            return $this->ok([
                'rows'        => $castRows,
                'count'       => $rows->count(),
                'next_cursor' => $nextCursor,
                'groups'      => $this->groupRowsByDisease($castRows),
            ], 'Alerts.', [
                'tabs'        => $tabs,
                'scope_label' => $scope['label'] ?? null,
                'per_page'    => $perPage,
            ]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    /**
     * GET /admin/alerts/meta
     * Filter dropdown bag + close-category enum + risk / level / tier enums.
     */
    public function meta(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);

            $distQ = DB::table('ref_districts')->whereNull('deleted_at');
            $distQ = ScopeFilter::applyToDistricts($distQ, $scope);
            $districts = $distQ->orderBy('name')->pluck('name')->all();

            $poeQ = DB::table('ref_poes')->whereNull('deleted_at');
            $poeQ = ScopeFilter::applyToPoes($poeQ, $scope);
            // Returns {code, name, poe_code, poe_name, province, district} per row.
            // `code`/`name` are the keys the master view's selector reads.
            // `poe_code`/`poe_name` are kept for any consumer that still reads them.
            $poes = $poeQ->orderBy('display_order')->orderBy('poe_name')
                ->get(['poe_code', 'poe_name', 'admin_level_1', 'district'])
                ->map(fn ($p) => [
                    'code'     => (string) $p->poe_code,
                    'name'     => (string) $p->poe_name,
                    'poe_code' => (string) $p->poe_code,
                    'poe_name' => (string) $p->poe_name,
                    'province' => (string) $p->admin_level_1,
                    'district' => (string) $p->district,
                ])->all();

            $closeCats = [];
            foreach (ApiAlertsController::CLOSE_CATEGORIES as $k => $label) {
                $closeCats[] = ['code' => $k, 'label' => $label];
            }

            // Disease groups for the master-list grouper.
            $diseaseGroups = [];
            foreach (array_unique(PriorityRules::DISEASE_GROUP) as $g) {
                $diseaseGroups[] = ['code' => $g, 'label' => (string) trans("alerts.disease_group.{$g}")];
            }
            $diseaseGroups[] = ['code' => 'syndromic_unknown', 'label' => (string) trans('alerts.disease_group.syndromic_unknown')];

            // Actor profile — surfaced for the master view's role-gated action
            // buttons (Acknowledge etc.) so we don't show options the user
            // can't fire. Server-side route middleware remains authoritative.
            $actor = $r->user();
            $actorPayload = $actor ? [
                'id'        => (int) $actor->id,
                'full_name' => (string) ($actor->full_name ?? $actor->username ?? ''),
                'role_key'  => (string) ($actor->role_key ?? ''),
            ] : null;

            return $this->ok([
                'risks'             => self::RISKS,
                'tiers'             => self::TIERS,
                'levels'            => self::LEVELS,
                'close_categories'  => $closeCats,
                'districts'         => $districts,
                'poes'              => $poes,
                'tabs'              => self::TABS,
                'actor'             => $actorPayload,

                // Plain-English chip palettes — Blade renders only the human side.
                'human' => [
                    'palette'        => HumanLabels::metaPalette(),
                    'disease_groups' => $diseaseGroups,
                ],
            ], 'Meta.');
        } catch (Throwable $e) { return $this->serverError($e, 'meta'); }
    }

    /**
     * GET /admin/alerts/insights
     *
     * Lightweight aggregates for the master-list hero strip — sparkline,
     * group distribution, tier ring, WHO outcome counts, hotspots. All
     * scope-filtered. Cheap enough to recompute on every page load.
     */
    public function insights(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $base  = fn () => ScopeFilter::applyToAlerts(
                DB::table('alerts')->whereNull('alerts.deleted_at'), $scope, 'alerts'
            );

            // 30-day trend
            $since = Carbon::now()->subDays(29)->startOfDay();
            $trendRows = (clone $base())
                ->where('alerts.created_at', '>=', $since)
                ->select(DB::raw('DATE(alerts.created_at) as d'), DB::raw('COUNT(*) as c'))
                ->groupBy('d')->orderBy('d')->get();
            $byDay = [];
            foreach ($trendRows as $row) $byDay[(string) $row->d] = (int) $row->c;
            $trend = [];
            for ($i = 29; $i >= 0; $i--) {
                $d = Carbon::now()->subDays($i)->toDateString();
                $trend[] = ['date' => $d, 'count' => (int) ($byDay[$d] ?? 0)];
            }
            $trendMax = max(1, ...array_column($trend, 'count'));

            // Status breakdown.
            $statusRows = (clone $base())
                ->select('alerts.status', DB::raw('COUNT(*) as c'))
                ->groupBy('alerts.status')->pluck('c', 'alerts.status');
            $totalRow = (clone $base())->count();

            // Risk breakdown (drives the tier ring on the hero).
            $riskRows = (clone $base())
                ->where('alerts.status', '!=', 'CLOSED')
                ->select('alerts.risk_level', DB::raw('COUNT(*) as c'))
                ->groupBy('alerts.risk_level')->pluck('c', 'alerts.risk_level');

            // Hotspots — top districts in last 30 days.
            $hotspots = (clone $base())
                ->where('alerts.created_at', '>=', $since)
                ->whereNotNull('alerts.district_code')
                ->select('alerts.district_code', DB::raw('COUNT(*) as c'))
                ->groupBy('alerts.district_code')->orderByDesc('c')->limit(5)
                ->get(['alerts.district_code', 'c'])
                ->map(fn ($r) => ['label' => (string) $r->district_code, 'count' => (int) $r->c])
                ->all();

            // Disease group distribution — pull top suspected per alert in scope.
            $alertIds = (clone $base())->pluck('alerts.id')->all();
            $byGroup = [];
            if (!empty($alertIds)) {
                $diseaseByAlert = $this->topDiseasesFor($alertIds);
                foreach ($diseaseByAlert as $aid => $code) {
                    $g = \App\Support\Alerts\PriorityRules::DISEASE_GROUP[$code] ?? 'syndromic_unknown';
                    $byGroup[$g] = ($byGroup[$g] ?? 0) + 1;
                }
                // Alerts with no top disease still count under syndromic_unknown.
                $missing = count($alertIds) - array_sum($byGroup);
                if ($missing > 0) {
                    $byGroup['syndromic_unknown'] = ($byGroup['syndromic_unknown'] ?? 0) + $missing;
                }
            }
            arsort($byGroup);
            $groups = [];
            foreach ($byGroup as $code => $count) {
                $groups[] = [
                    'code'  => $code,
                    'label' => (string) trans("alerts.disease_group.{$code}"),
                    'count' => $count,
                ];
            }

            // WHO outcome counts (case classification distribution) — analytics anchor.
            $outcomeRows = DB::table('alert_case_outcomes')
                ->join('alerts', 'alerts.id', '=', 'alert_case_outcomes.alert_id');
            ScopeFilter::applyToAlerts($outcomeRows, $scope, 'alerts');
            $outcomeRows = $outcomeRows
                ->whereNull('alert_case_outcomes.deleted_at')
                ->select('alert_case_outcomes.case_classification', DB::raw('COUNT(*) as c'))
                ->groupBy('alert_case_outcomes.case_classification')
                ->pluck('c', 'alert_case_outcomes.case_classification');

            return $this->ok([
                'totals' => [
                    'all'          => (int) $totalRow,
                    'open'         => (int) ($statusRows['OPEN']         ?? 0),
                    'acknowledged' => (int) ($statusRows['ACKNOWLEDGED'] ?? 0),
                    'closed'       => (int) ($statusRows['CLOSED']       ?? 0),
                ],
                'trend'    => $trend,
                'trend_max'=> (int) $trendMax,
                'risk'     => [
                    'CRITICAL' => (int) ($riskRows['CRITICAL'] ?? 0),
                    'HIGH'     => (int) ($riskRows['HIGH']     ?? 0),
                    'MEDIUM'   => (int) ($riskRows['MEDIUM']   ?? 0),
                    'LOW'      => (int) ($riskRows['LOW']      ?? 0),
                ],
                'groups'   => $groups,
                'hotspots' => $hotspots,
                'outcomes' => [
                    'CONFIRMED'        => (int) ($outcomeRows['CONFIRMED']        ?? 0),
                    'PROBABLE'         => (int) ($outcomeRows['PROBABLE']         ?? 0),
                    'SUSPECTED'        => (int) ($outcomeRows['SUSPECTED']        ?? 0),
                    'DISCARDED'        => (int) ($outcomeRows['DISCARDED']        ?? 0),
                    'LOST_TO_FOLLOWUP' => (int) ($outcomeRows['LOST_TO_FOLLOWUP'] ?? 0),
                    'UNKNOWN'          => (int) ($outcomeRows['UNKNOWN']          ?? 0),
                ],
            ], 'Insights.');
        } catch (Throwable $e) { return $this->serverError($e, 'insights'); }
    }

    /**
     * GET /admin/alerts/{id}
     * Full per-alert dossier — alert + followups + collaborators + handoffs
     * + breach reports + last 80 timeline events + dispatch receipt.
     */
    public function show(Request $r, int $id): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);

            $a = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $a) return $this->err(404, 'Alert not found.');
            if (! ScopeFilter::canSeeAlert($scope, $a)) {
                return $this->err(403, 'Alert is outside your scope.');
            }

            $owner = $a->current_owner_user_id
                ? DB::table('users')->where('id', $a->current_owner_user_id)->first(['id','full_name','role_key','email'])
                : null;
            $ack = $a->acknowledged_by_user_id
                ? DB::table('users')->where('id', $a->acknowledged_by_user_id)->first(['id','full_name','role_key','email'])
                : null;

            $followups = DB::table('alert_followups')
                ->where('alert_id', $id)->whereNull('deleted_at')
                ->orderByRaw("FIELD(status,'BLOCKED','PENDING','IN_PROGRESS','COMPLETED','NOT_APPLICABLE')")
                ->orderBy('due_at')
                ->get();

            $blockingPending = $followups->filter(fn ($f) => $f->blocks_closure
                && ! in_array($f->status, ['COMPLETED', 'NOT_APPLICABLE'], true))->values();

            $collaborators = DB::table('alert_collaborators as c')
                ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
                ->where('c.alert_id', $id)
                ->select(['c.*', 'u.full_name', 'u.role_key', 'u.email'])
                ->orderByDesc('c.is_active')->orderBy('c.id')->get();

            $handoffs = DB::table('alert_handoffs as h')
                ->leftJoin('users as f', 'f.id', '=', 'h.from_user_id')
                ->leftJoin('users as t', 't.id', '=', 'h.to_user_id')
                ->where('h.alert_id', $id)
                ->select([
                    'h.*',
                    'f.full_name as from_name', 'f.role_key as from_role',
                    't.full_name as to_name',   't.role_key as to_role',
                ])
                ->orderByDesc('h.id')->limit(20)->get();

            $breaches = DB::table('alert_breach_reports')
                ->where('alert_id', $id)
                ->orderByDesc('id')->get();

            $timeline = DB::table('alert_timeline_events')
                ->where('alert_id', $id)
                ->orderByDesc('id')
                ->limit(self::TIMELINE_LIMIT)
                ->get();

            $comments = DB::table('alert_comments as c')
                ->leftJoin('users as u', 'u.id', '=', 'c.author_user_id')
                ->where('c.alert_id', $id)
                ->whereNull('c.deleted_at')
                ->select(['c.*', 'u.full_name as author_name', 'u.role_key as author_role'])
                ->orderByDesc('c.is_pinned')->orderByDesc('c.id')
                ->limit(20)->get();

            $dispatch = DB::table('notification_log')
                ->where('related_entity_type', 'ALERT')
                ->where('related_entity_id', $id)
                ->orderByDesc('id')->limit(40)->get([
                    'id','template_code','to_email','status','sent_at','failed_at','error_message','retry_count','triggered_by','created_at',
                ]);

            $sla = $this->computeSla($a);

            return $this->ok([
                'alert'             => $this->castRow($a, $blockingPending->count()),
                'owner'             => $owner,
                'ack'               => $ack,
                'followups'         => $followups->map(fn ($f) => $this->castFollowup($f))->all(),
                'blocking_pending'  => $blockingPending->map(fn ($f) => [
                    'id' => (int) $f->id, 'action_code' => $f->action_code, 'action_label' => $f->action_label, 'status' => $f->status,
                ])->all(),
                'collaborators'     => $collaborators->map(fn ($c) => (array) $c)->all(),
                'handoffs'          => $handoffs->map(fn ($h) => (array) $h)->all(),
                'breach_reports'    => $breaches->map(fn ($b) => (array) $b)->all(),
                'timeline'          => $timeline->map(fn ($t) => $this->castEvent($t))->all(),
                'comments'          => $comments->map(fn ($c) => (array) $c)->all(),
                'dispatch_receipt'  => $dispatch->map(fn ($d) => (array) $d)->all(),
                'sla'               => $sla,
                'links'             => [
                    // case-file is the canonical user-facing dossier surface.
                    // (Removed war_room — pointed to this JSON endpoint, misleading.
                    //  Removed api_show — leaked the mobile API contract to admin UI.
                    //  timeline/followups still resolve; sidebar-deprecated but kept
                    //  here so the case-file modal can deep-link if it needs to.)
                    'case_file'  => "/admin/alerts/{$id}/case-file",
                    'timeline'   => "/admin/alerts/timeline?alert_id={$id}",
                    'followups'  => "/admin/alerts/followups?alert_id={$id}",
                ],
            ], 'Alert dossier.');
        } catch (Throwable $e) { return $this->serverError($e, 'show'); }
    }

    /* ═════════════════════════ writes (DELEGATED) ═════════════════════════ */

    /**
     * PATCH /admin/alerts/{id}/acknowledge
     * Forwards to the canonical AlertsController::acknowledge after a scope
     * pre-flight (it already enforces ACKNOWLEDGE_ROLES per routed_to_level).
     *
     * On a 2xx response, fans out an ALERT_ACKNOWLEDGED user-event email to
     * the assignee + team + originator + management (alerts refactor §3.2).
     */
    public function acknowledge(Request $r, int $id): JsonResponse
    {
        try {
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Alert not found.');
            if (! ScopeFilter::canSeeAlert(ScopeFilter::fromRequest($r), $alert)) {
                return $this->err(403, 'Alert is outside your scope.');
            }
            $this->injectActor($r);
            $resp = app(ApiAlertsController::class)->acknowledge($r, $id);
            $this->fanOutUserEvent($r, $id, $resp, 'ALERT_ACKNOWLEDGED', function ($actor, $fresh) {
                $name = $actor->full_name ?? ('user #' . ($actor->id ?? '?'));
                $role = $actor->role_key  ?? '—';
                return [
                    'headline' => 'Acknowledged by ' . $name . ' — owner has the case',
                    'context'  => "Acknowledged by: {$name} · {$role}\n"
                        . 'Alert is now in the ACKNOWLEDGED state — the owner is responsible for executing '
                        . 'the 7-1-7 detect/notify/respond ladder. Open the case file for the full clinical context.',
                ];
            });
            return $resp;
        } catch (Throwable $e) { return $this->serverError($e, 'acknowledge'); }
    }

    /**
     * PATCH /admin/alerts/{id}/close
     * Pre-flight close gate (blocking followups), then forward to the API
     * controller for FSM transition + dispatcher notify.
     *
     * Override path:
     *   POST body { override_blocking_followups: 1, override_reason: "≥30 chars" }
     *   ⇒ allowed only when actor's role_key === 'NATIONAL_ADMIN'.
     *   ⇒ writes a CRITICAL-severity timeline event capturing the overridden
     *     followups list and the justification, BEFORE forwarding so the
     *     audit lineage is correct even if the forward fails.
     */
    public function close(Request $r, int $id): JsonResponse
    {
        try {
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Alert not found.');

            $scope = ScopeFilter::fromRequest($r);
            if (! ScopeFilter::canSeeAlert($scope, $alert)) {
                return $this->err(403, 'Alert is outside your scope.');
            }

            // Idempotent — let the API short-circuit if already closed.
            if ($alert->status === 'CLOSED') {
                return app(ApiAlertsController::class)->close($r, $id);
            }

            // Compute blocking followups outstanding.
            $blocking = DB::table('alert_followups')
                ->where('alert_id', $id)
                ->whereNull('deleted_at')
                ->where('blocks_closure', 1)
                ->whereNotIn('status', ['COMPLETED', 'NOT_APPLICABLE'])
                ->get(['id', 'action_code', 'action_label', 'status', 'due_at']);

            $override        = (bool) $r->input('override_blocking_followups', false);
            $overrideReason  = trim((string) $r->input('override_reason', ''));
            $userRole        = strtoupper((string) ($r->user()->role_key ?? ''));

            if ($blocking->isNotEmpty() && ! $override) {
                return $this->err(422, 'Cannot close: blocking followups must be COMPLETED or NOT_APPLICABLE.', [
                    'blocking_count' => $blocking->count(),
                    'blocking'       => $blocking->map(fn ($b) => [
                        'id' => (int) $b->id, 'action_code' => $b->action_code,
                        'action_label' => $b->action_label, 'status' => $b->status, 'due_at' => $b->due_at,
                    ])->all(),
                    'override_hint'  => 'NATIONAL_ADMIN may pass override_blocking_followups=1 with override_reason (≥30 chars).',
                ]);
            }
            if ($blocking->isNotEmpty() && $override) {
                if ($userRole !== 'NATIONAL_ADMIN') {
                    return $this->err(403, 'Override of blocking followups is restricted to NATIONAL_ADMIN.');
                }
                if (mb_strlen($overrideReason) < 30) {
                    return $this->err(422, 'override_reason must be at least 30 characters.');
                }

                // ── Materialise the override ────────────────────────────
                // Record the override decision FIRST (append-only audit
                // survives any downstream failure), then auto-mark each
                // blocking followup NOT_APPLICABLE so the downstream
                // controller's own gate (AlertsController.php:691) is
                // satisfied. Emit per-followup FOLLOWUP_NOT_APPLICABLE
                // events so the lineage shows precisely WHICH actions
                // were waived and WHY.
                $actorId = (int) ($r->user()->id ?? 0);

                // CLOSURE_OVERRIDE_USED is the canonical code (matches what
                // CaseFileController::buildClosure reads). BREACH_UPDATED was
                // semantically wrong (that code is for breach-RCA updates).
                $this->emitTimeline($id, 'CLOSURE_OVERRIDE_USED', 'WORKFLOW', $actorId,
                    'Close override — ' . $blocking->count() . ' blocking followups overridden by NATIONAL_ADMIN.',
                    [
                        'override_reason'      => $overrideReason,
                        'overridden_followups' => $blocking->map(fn ($b) => [
                            'id' => (int) $b->id, 'action_code' => $b->action_code, 'status' => $b->status,
                        ])->values()->all(),
                    ],
                    'ALERT', $id, 'WARN'
                );

                $now = Carbon::now()->toDateTimeString();
                foreach ($blocking as $b) {
                    $waiveNote = '[AUTO-WAIVED on close override by NATIONAL_ADMIN] ' . $overrideReason;
                    DB::table('alert_followups')->where('id', $b->id)->update([
                        'status'                => 'NOT_APPLICABLE',
                        'completed_at'          => $now,
                        'completed_by_user_id'  => $actorId,
                        'notes'                 => mb_substr($waiveNote, 0, 500),
                        'updated_at'            => $now,
                    ]);
                    $this->emitTimeline($id, 'FOLLOWUP_NOT_APPLICABLE', 'WORKFLOW', $actorId,
                        sprintf('[OVERRIDE] Followup [%s] %s → NOT_APPLICABLE on close override.',
                            $b->action_code, $b->status),
                        [
                            'followup_id'    => (int) $b->id,
                            'action_code'    => $b->action_code,
                            'before_status'  => $b->status,
                            'override'       => true,
                            'override_reason'=> $overrideReason,
                        ],
                        'FOLLOWUP', (int) $b->id, 'WARN'
                    );
                }
            }

            // Record the WHO outcome FIRST so the canonical close's
            // dispatchAlertClosed picks it up. Roll back the row on failure
            // so we never leave an outcome anchored to an OPEN alert.
            $outcomeRecorder = app(\App\Services\Alerts\CaseOutcomeRecorder::class);
            $outcomeId = $outcomeRecorder->recordFromClose(
                $id,
                (string) $r->input('close_category', 'RESOLVED'),
                $r->input('close_note') ? (string) $r->input('close_note') : null,
                (int) ($r->user()->id ?? 0) ?: null,
                [
                    'merged_into_alert_id'      => $r->input('merged_into_alert_id'),
                    'override_blocking_used'    => $override,
                    'override_reason'           => $override ? $overrideReason : null,
                ]
            );

            // Forward to canonical close(). All FSM rules + dispatcher fire.
            // The canonical close() invokes NotificationDispatcher::dispatchAlertClosed
            // which itself fans out the closure email — now enriched with the
            // outcome row above — to assignee + team + originator + management.
            $this->injectActor($r);
            $resp = app(ApiAlertsController::class)->close($r, $id);

            if ($outcomeId && $resp instanceof JsonResponse && $resp->getStatusCode() >= 300) {
                try {
                    DB::table('alert_case_outcomes')->where('id', $outcomeId)->update(['deleted_at' => Carbon::now()]);
                } catch (Throwable) { /* best-effort rollback */ }
            }

            return $resp;
        } catch (Throwable $e) { return $this->serverError($e, 'close'); }
    }

    /**
     * POST /admin/alerts/{id}/reopen — delegate + user-event fan-out.
     * Reopen is high-impact — a closed case is being put back on the ladder —
     * so the email blast goes wider (assignee + team + originator + management).
     */
    public function reopen(Request $r, int $id): JsonResponse
    {
        try {
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Alert not found.');
            if (! ScopeFilter::canSeeAlert(ScopeFilter::fromRequest($r), $alert)) {
                return $this->err(403, 'Alert is outside your scope.');
            }
            $this->injectActor($r);
            $resp = app(AlertCollaborationController::class)->reopen($r, $id);
            $this->fanOutUserEvent($r, $id, $resp, 'ALERT_REOPENED', function ($actor, $fresh) use ($r) {
                $name   = $actor->full_name ?? ('user #' . ($actor->id ?? '?'));
                $reason = trim((string) $r->input('reason', ''));
                return [
                    'headline' => 'Reopened by ' . $name . ' — case is active again',
                    'context'  => 'Reopen reason: ' . ($reason !== '' ? $reason : '(not provided)')
                        . "\nThe alert has returned to the ACKNOWLEDGED state. Confirm the response actions and "
                        . 'update the 7-1-7 ladder. The case file holds the full clinical record.',
                ];
            });
            return $resp;
        } catch (Throwable $e) { return $this->serverError($e, 'reopen'); }
    }

    /**
     * POST /admin/alerts/{id}/escalate — delegate.
     *
     * Pre-flight enum guard: the canonical controller validates `to_level`
     * only as `string|max:30`, so a bad value (e.g. "MARS") would slip
     * through to MySQL and crash with a "Data truncated" 500. We block
     * here with a clean 422 listing the allowed values, and we ALSO
     * refuse no-op escalations (target == current level) and downgrade
     * paths (e.g. NATIONAL → DISTRICT) since dashboard.txt §B.6 only
     * authorises upward movement of the routed_to_level.
     */
    public function escalate(Request $r, int $id): JsonResponse
    {
        try {
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Alert not found.');
            if (! ScopeFilter::canSeeAlert(ScopeFilter::fromRequest($r), $alert)) {
                return $this->err(403, 'Alert is outside your scope.');
            }

            $to = strtoupper(trim((string) $r->input('to_level', '')));
            if (! in_array($to, self::LEVELS, true)) {
                return $this->err(422, 'Invalid to_level.', ['allowed' => self::LEVELS, 'got' => $to]);
            }
            $reason = trim((string) $r->input('reason', ''));
            if ($reason === '') return $this->err(422, 'reason is required.');

            $current = strtoupper((string) $alert->routed_to_level);
            $rank    = ['DISTRICT' => 1, 'PHEOC' => 2, 'NATIONAL' => 3];
            if (($rank[$to] ?? 0) <= ($rank[$current] ?? 0)) {
                return $this->err(422, 'Escalation must move UP the routing ladder.', [
                    'current' => $current, 'requested' => $to,
                    'hint'    => 'Use /reassign for lateral / downward owner changes.',
                ]);
            }

            $this->injectActor($r);
            $r->merge(['to_level' => $to, 'reason' => $reason]);
            $resp = app(AlertCollaborationController::class)->escalate($r, $id);
            $this->fanOutUserEvent($r, $id, $resp, 'ALERT_ESCALATED', function ($actor, $fresh) use ($current, $to, $reason) {
                $name = $actor->full_name ?? ('user #' . ($actor->id ?? '?'));
                return [
                    'headline' => 'Escalated ' . $current . ' → ' . $to . ' by ' . $name,
                    'context'  => 'Routing level moved up the IHR ladder: ' . $current . ' → ' . $to
                        . "\nReason: " . $reason
                        . "\nThe new ladder tier owns the alert; previous owners remain on the team for context.",
                ];
            });
            return $resp;
        } catch (Throwable $e) { return $this->serverError($e, 'escalate'); }
    }

    /**
     * POST /admin/alerts/{id}/reassign — pre-flight + delegate + user-event fan-out.
     *
     * Pre-flight (added by alerts refactor §3.1):
     *   • owner_user_id must reference a real, active users row
     *   • user must carry an RFC-5322-valid email (no "type-an-ID" footgun)
     *   • user must be in the actor's permitted geo scope
     *     (NATIONAL_ADMIN sees everyone; PHEOC/DISTRICT/POE constrained via
     *     ScopeFilter::applyToUsers)
     *   • optional `level` is enum-validated against LEVELS
     *
     * After the canonical reassign succeeds, fan out an ALERT_REASSIGNED user
     * email to assignee + team + originator + management (per §3.2). Errors
     * inside the fan-out are swallowed by NotificationDispatcher::safely so
     * a degraded mailer never breaks the reassign FSM transition.
     */
    public function reassign(Request $r, int $id): JsonResponse
    {
        try {
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Alert not found.');
            $scope = ScopeFilter::fromRequest($r);
            if (! ScopeFilter::canSeeAlert($scope, $alert)) {
                return $this->err(403, 'Alert is outside your scope.');
            }

            $newOwnerId = (int) $r->input('owner_user_id', 0);
            if ($newOwnerId <= 0) {
                return $this->err(422, 'owner_user_id is required and must be a positive integer.', [
                    'hint' => 'Use GET /admin/alerts/reassign-candidates to discover valid users.',
                ]);
            }

            $candidate = DB::table('users')->where('id', $newOwnerId)
                ->first(['id','full_name','role_key','email','is_active']);
            if (! $candidate) {
                return $this->err(422, 'Target user does not exist.', ['owner_user_id' => $newOwnerId]);
            }
            if (! (int) $candidate->is_active) {
                return $this->err(422, 'Target user is inactive.', ['owner_user_id' => $newOwnerId]);
            }
            if (! \App\Services\NotificationDispatcher::isValidEmail($candidate->email)) {
                return $this->err(422, 'Target user has no valid email on file. Reassignment requires a deliverable email so the owner is paged immediately.', [
                    'owner_user_id' => $newOwnerId,
                    'email_present' => ! empty($candidate->email),
                    'remediation'   => 'Update the user\'s email under Workforce → Users, then retry.',
                ]);
            }

            $level = strtoupper(trim((string) $r->input('level', '')));
            if ($level !== '' && ! in_array($level, self::LEVELS, true)) {
                return $this->err(422, 'Invalid level.', ['allowed' => self::LEVELS, 'got' => $level]);
            }

            // Scope guard: ensure the picked user is reachable by the actor's scope.
            // NATIONAL_ADMIN bypasses (isSuper). Otherwise the user must appear in
            // applyToUsers() over the active scope — which already enforces the
            // PHEOC→DISTRICT→POE foundational rule.
            if (! ScopeFilter::isSuper($scope)) {
                $inScope = ScopeFilter::applyToUsers(
                    DB::table('users')->where('users.id', $newOwnerId), $scope, 'users'
                )->exists();
                if (! $inScope) {
                    return $this->err(403, 'Target user is outside your assignment scope.', [
                        'owner_user_id' => $newOwnerId,
                        'scope_level'   => ScopeFilter::level($scope),
                    ]);
                }
            }

            // Forward to canonical AlertCollaborationController::reassign.
            $this->injectActor($r);
            $r->merge(['owner_user_id' => $newOwnerId, 'level' => $level !== '' ? $level : null]);
            $resp = app(AlertCollaborationController::class)->reassign($r, $id);

            // On success, fire the user-event fan-out. Only fire on a 2xx
            // delegated response so we don't double-email if the FSM rejected.
            $status = method_exists($resp, 'status') ? (int) $resp->status() : 200;
            if ($status >= 200 && $status < 300) {
                $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
                if ($alert) {
                    $reason  = trim((string) $r->input('reason', ''));
                    $actorId = (int) ($r->user()->id ?? 0);
                    $actor   = DB::table('users')->where('id', $actorId)->first(['full_name','role_key']);
                    $headline = 'Reassigned to ' . ($candidate->full_name ?? ('user #' . $candidate->id));
                    $context  = 'New owner: ' . ($candidate->full_name ?? ('user #' . $candidate->id))
                        . ' · ' . ($candidate->role_key ?? '—')
                        . ($level !== '' ? ' · level: ' . $level : '')
                        . "\nReassigned by: " . ($actor->full_name ?? ('user #' . $actorId)) . ' · ' . ($actor->role_key ?? '—')
                        . ($reason !== '' ? "\nReason: " . $reason : '');
                    \App\Services\NotificationDispatcher::dispatchUserEvent(
                        $alert,
                        'ALERT_REASSIGNED',
                        $headline,
                        $context,
                        $actorId,
                    );
                }
            }
            return $resp;
        } catch (Throwable $e) { return $this->serverError($e, 'reassign'); }
    }

    /**
     * GET /admin/alerts/reassign-candidates
     * Search the users roster for candidates the actor may legally reassign to.
     *
     * Query:
     *   alert_id        (required) — for current_open_alerts highlighting & scope ack
     *   q               substring on full_name / email / username (LIKE)
     *   role            users.role_key exact match
     *   level           DISTRICT|PHEOC|NATIONAL — filter by user's scope_level
     *   poe_code        user_assignments.poe_code
     *   district_code   user_assignments.district_code
     *   province_code   user_assignments.province_code
     *   active_only     0|1 (default 1)
     *   per_page        clamped 1..100 (default 50)
     *
     * Every row carries an `email_valid` boolean + a human-readable
     * `email_reason` so the picker can disable invalid candidates.
     */
    public function reassignCandidates(Request $r): JsonResponse
    {
        try {
            $alertId = (int) $r->query('alert_id', 0);
            if ($alertId <= 0) return $this->err(422, 'alert_id is required.');

            $alert = DB::table('alerts')->where('id', $alertId)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Alert not found.');

            $scope = ScopeFilter::fromRequest($r);
            if (! ScopeFilter::canSeeAlert($scope, $alert)) {
                return $this->err(403, 'Alert is outside your scope.');
            }

            $q              = trim((string) $r->query('q', ''));
            $roleFilter     = trim((string) $r->query('role', ''));
            $levelFilter    = strtoupper(trim((string) $r->query('level', '')));
            $poeFilter      = trim((string) $r->query('poe_code', ''));
            $districtFilter = trim((string) $r->query('district_code', ''));
            $provinceFilter = trim((string) $r->query('province_code', ''));
            $activeOnly     = (int) $r->query('active_only', 1) === 1;
            $perPage        = max(1, min(100, (int) $r->query('per_page', 50)));

            $base = DB::table('users')
                ->select(['users.id','users.full_name','users.email','users.role_key',
                          'users.country_code','users.is_active','users.last_login_at']);
            if ($activeOnly) $base->where('users.is_active', 1);
            $base = ScopeFilter::applyToUsers($base, $scope, 'users');

            if ($q !== '') {
                $like = '%' . $q . '%';
                $base->where(function ($w) use ($like) {
                    $w->where('users.full_name', 'like', $like)
                      ->orWhere('users.email',    'like', $like)
                      ->orWhere('users.username', 'like', $like);
                });
            }
            if ($roleFilter !== '') $base->where('users.role_key', $roleFilter);
            if ($levelFilter !== '') {
                $base->whereExists(function ($w) use ($levelFilter) {
                    $w->select(DB::raw(1))->from('role_registry as rr')
                      ->whereColumn('rr.role_key', 'users.role_key')
                      ->where('rr.scope_level', $levelFilter);
                });
            }
            if ($poeFilter !== '' || $districtFilter !== '' || $provinceFilter !== '') {
                $base->whereExists(function ($w) use ($poeFilter, $districtFilter, $provinceFilter) {
                    $w->select(DB::raw(1))->from('user_assignments as ua_f')
                      ->whereColumn('ua_f.user_id', 'users.id')
                      ->where('ua_f.is_active', 1)
                      ->whereNull('ua_f.ends_at');
                    if ($poeFilter      !== '') $w->where('ua_f.poe_code',      $poeFilter);
                    if ($districtFilter !== '') $w->where('ua_f.district_code', $districtFilter);
                    if ($provinceFilter !== '') $w->where('ua_f.province_code', $provinceFilter);
                });
            }

            $rows = $base->orderBy('users.full_name')->limit($perPage)->get();

            $userIds = $rows->pluck('id')->all();
            $loadByUser = [];
            if (! empty($userIds)) {
                $loadByUser = DB::table('alerts')
                    ->select('current_owner_user_id', DB::raw('COUNT(*) as c'))
                    ->whereIn('current_owner_user_id', $userIds)
                    ->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])
                    ->whereNull('deleted_at')
                    ->groupBy('current_owner_user_id')->pluck('c', 'current_owner_user_id')->all();
            }
            $assignByUser = [];
            if (! empty($userIds)) {
                $rawAssignments = DB::table('user_assignments')
                    ->whereIn('user_id', $userIds)
                    ->where('is_active', 1)
                    ->whereNull('ends_at')
                    ->get(['user_id','province_code','district_code','poe_code','pheoc_code']);
                foreach ($rawAssignments as $ua) {
                    $assignByUser[(int) $ua->user_id][] = [
                        'province_code' => $ua->province_code,
                        'district_code' => $ua->district_code,
                        'poe_code'      => $ua->poe_code,
                        'pheoc_code'    => $ua->pheoc_code,
                    ];
                }
            }
            $roleMeta = [];
            $roleKeys = $rows->pluck('role_key')->unique()->filter()->values()->all();
            if (! empty($roleKeys)) {
                $roleMeta = DB::table('role_registry')
                    ->whereIn('role_key', $roleKeys)
                    ->pluck('scope_level', 'role_key')->all();
            }

            $viewerRole = strtoupper((string) ($r->user()->role_key ?? ''));
            $maskEmails = ! in_array($viewerRole, ['NATIONAL_ADMIN','PHEOC_OFFICER'], true);

            $payload = $rows->map(function ($u) use ($loadByUser, $assignByUser, $roleMeta, $maskEmails) {
                $emailRaw   = (string) ($u->email ?? '');
                $emailValid = \App\Services\NotificationDispatcher::isValidEmail($emailRaw);
                $emailReason = $emailValid
                    ? 'OK'
                    : ($emailRaw === '' ? 'No email on file' : 'Email failed RFC-5322 validation');

                $emailDisplay = $emailRaw === '' ? null : ($maskEmails ? $this->maskEmail($emailRaw) : $emailRaw);

                return [
                    'id'                  => (int) $u->id,
                    'full_name'           => (string) ($u->full_name ?? ''),
                    'role_key'            => (string) ($u->role_key  ?? ''),
                    'scope_level'         => $roleMeta[$u->role_key] ?? null,
                    'email'               => $emailDisplay,
                    'email_valid'         => $emailValid,
                    'email_reason'        => $emailReason,
                    'is_active'           => (bool) $u->is_active,
                    'country_code'        => $u->country_code,
                    'assignments'         => $assignByUser[(int) $u->id] ?? [],
                    'open_alerts_count'   => (int) ($loadByUser[(int) $u->id] ?? 0),
                    'last_login_at'       => $u->last_login_at,
                ];
            })->all();

            $eligibleCount = collect($payload)->where('email_valid', true)->count();

            return $this->ok([
                'rows'           => $payload,
                'total'          => count($payload),
                'eligible'       => $eligibleCount,
            ], 'Reassignment candidates.', [
                'alert_id'    => $alertId,
                'scope_level' => ScopeFilter::level($scope),
                'is_super'    => ScopeFilter::isSuper($scope),
                'masked_emails' => $maskEmails,
                'filters'     => compact('q','roleFilter','levelFilter','poeFilter','districtFilter','provinceFilter','activeOnly','perPage'),
            ]);
        } catch (Throwable $e) { return $this->serverError($e, 'reassignCandidates'); }
    }

    /**
     * GET /admin/alerts/reassign-meta
     * Roles + scope-levels + districts + POEs available for the picker filters.
     */
    public function reassignMeta(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $roles = DB::table('role_registry')
                ->where('is_active', 1)
                ->whereNotIn('role_key', ['SCREENER','OBSERVER','SERVICE'])
                ->orderByRaw("FIELD(scope_level,'NATIONAL','PHEOC','DISTRICT','POE','SELF')")
                ->orderBy('display_name')
                ->get(['role_key','display_name','scope_level'])
                ->map(fn ($r) => [
                    'role_key'    => (string) $r->role_key,
                    'display_name'=> (string) $r->display_name,
                    'scope_level' => (string) $r->scope_level,
                ])->all();

            $poeQ = DB::table('ref_poes')->whereNull('deleted_at');
            $poeQ = ScopeFilter::applyToPoes($poeQ, $scope);
            $poes = $poeQ->orderBy('poe_name')
                ->get(['poe_code','poe_name','admin_level_1 as province','district'])
                ->map(fn ($p) => [
                    'poe_code' => (string) $p->poe_code,
                    'poe_name' => (string) $p->poe_name,
                    'province' => (string) $p->province,
                    'district' => (string) $p->district,
                ])->all();
            $districts = collect($poes)->pluck('district')->unique()->filter()->values()->all();
            $provinces = collect($poes)->pluck('province')->unique()->filter()->values()->all();

            return $this->ok([
                'roles'        => $roles,
                'scope_levels' => ['NATIONAL','PHEOC','DISTRICT','POE'],
                'poes'         => $poes,
                'districts'    => $districts,
                'provinces'    => $provinces,
            ], 'Reassign meta.');
        } catch (Throwable $e) { return $this->serverError($e, 'reassignMeta'); }
    }

    /** Mask an email for viewers without explicit visibility. e.g. ja***@d***.com */
    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false) return '***';
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $maskedLocal  = mb_substr($local, 0, 2) . str_repeat('*', max(1, mb_strlen($local) - 2));
        $dot          = strrpos($domain, '.');
        $domainHead   = $dot === false ? $domain : substr($domain, 0, $dot);
        $domainTld    = $dot === false ? '' : substr($domain, $dot);
        $maskedDomain = mb_substr($domainHead, 0, 1) . str_repeat('*', max(1, mb_strlen($domainHead) - 1)) . $domainTld;
        return $maskedLocal . '@' . $maskedDomain;
    }

    /**
     * Inject the session user into the request before forwarding to canonical
     * controllers. Their actorId() reads X-User-Id / actor_user_id / user_id
     * but NOT $r->user(). This is a defence-in-depth helper used by every
     * delegated write path.
     */
    private function injectActor(Request $r): void
    {
        $actorId = (int) ($r->user()->id ?? 0);
        if ($actorId <= 0) return;
        $r->headers->set('X-User-Id', (string) $actorId);
        $r->merge(['actor_user_id' => $actorId, 'user_id' => $actorId]);
    }

    /**
     * Centralised user-event fan-out helper used by every delegated write path
     * (ack / close / reopen / escalate / reassign). Re-reads the alert AFTER
     * the canonical controller succeeds so the email body reflects the new
     * state, then calls NotificationDispatcher::dispatchUserEvent which
     * resolves recipients (assignee + team + originator + management),
     * dedupes by email, queues the SentinelMail (ops mailboxes CC'd), and
     * writes a notification_log row per recipient.
     *
     * Errors inside the dispatcher are swallowed by ::safely so a degraded
     * mailer never breaks the underlying FSM transition. The 2xx pre-condition
     * means we never email when the canonical FSM rejected the operation.
     *
     * @param callable $bodyBuilder  fn (object $actor, object $alert): array{headline:string,context:string}
     */
    private function fanOutUserEvent(Request $r, int $alertId, JsonResponse $resp, string $actionCode, callable $bodyBuilder): void
    {
        $status = $resp->status();
        if ($status < 200 || $status >= 300) return;
        try {
            $fresh = DB::table('alerts')->where('id', $alertId)->whereNull('deleted_at')->first();
            if (! $fresh) return;
            $actorId = (int) ($r->user()->id ?? 0);
            $actor   = $actorId > 0
                ? DB::table('users')->where('id', $actorId)->first(['id','full_name','role_key'])
                : (object) ['id' => 0, 'full_name' => 'System', 'role_key' => 'SYSTEM'];
            $body = $bodyBuilder($actor, $fresh);
            \App\Services\NotificationDispatcher::dispatchUserEvent(
                $fresh,
                $actionCode,
                (string) ($body['headline'] ?? ($actionCode . ' fired')),
                (string) ($body['context']  ?? ''),
                $actorId,
            );
        } catch (Throwable $e) {
            Log::warning('[Admin\\Alerts\\fanOutUserEvent][' . $actionCode . '] ' . $e->getMessage());
        }
    }

    /**
     * GET /admin/alerts/{id}/dispatch-receipt
     * Read-only audit of every notification_log row attached to this alert,
     * grouped into a SENT / FAILED / SKIPPED / QUEUED summary.
     */
    public function dispatchReceipt(Request $r, int $id): JsonResponse
    {
        try {
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Alert not found.');
            if (! ScopeFilter::canSeeAlert(ScopeFilter::fromRequest($r), $alert)) {
                return $this->err(403, 'Alert is outside your scope.');
            }
            $rows = DB::table('notification_log')
                ->where('related_entity_type', 'ALERT')
                ->where('related_entity_id', $id)
                ->orderByDesc('id')->limit(200)->get();

            $summary = $rows->groupBy('status')->map->count();

            // Suppression history for this alert (helps explain SKIPPED rows).
            $supp = DB::table('notification_suppressions')
                ->where('related_entity_type', 'ALERT')
                ->where('related_entity_id', $id)
                ->orderByDesc('last_sent_at')->limit(50)->get();

            return $this->ok([
                'rows'         => $rows->map(fn ($r) => (array) $r)->all(),
                'suppressions' => $supp->map(fn ($s) => (array) $s)->all(),
                'summary'      => [
                    'queued'  => (int) ($summary['QUEUED']  ?? 0),
                    'sent'    => (int) ($summary['SENT']    ?? 0),
                    'failed'  => (int) ($summary['FAILED']  ?? 0),
                    'skipped' => (int) ($summary['SKIPPED'] ?? 0),
                    'bounced' => (int) ($summary['BOUNCED'] ?? 0),
                ],
            ], 'Dispatch receipt.');
        } catch (Throwable $e) { return $this->serverError($e, 'dispatchReceipt'); }
    }

    /* ═════════════════════════ helpers ═════════════════════════ */

    private function applyTab($q, string $tab): void
    {
        switch ($tab) {
            case 'open':         $q->where('alerts.status', 'OPEN'); break;
            case 'acknowledged': $q->where('alerts.status', 'ACKNOWLEDGED'); break;
            case 'closed':       $q->where('alerts.status', 'CLOSED'); break;
            case 'reopened':     $q->where('alerts.reopen_count', '>', 0); break;
            case 'all':
            default:             break;
        }
    }

    private function applyFilters($q, Request $r): void
    {
        if (($v = trim((string) $r->query('risk_level', '')))   !== '' && in_array($v, self::RISKS, true)) {
            $q->where('alerts.risk_level', $v);
        }
        if (($v = trim((string) $r->query('ihr_tier', '')))     !== '') {
            $q->where('alerts.ihr_tier', $v);
        }
        if (($v = trim((string) $r->query('routed_to_level',''))) !== '' && in_array($v, self::LEVELS, true)) {
            $q->where('alerts.routed_to_level', $v);
        }
        if (($v = trim((string) $r->query('district', '')))     !== '') {
            $q->where('alerts.district_code', $v);
        }
        if (($v = trim((string) $r->query('poe', '')))          !== '') {
            $q->where('alerts.poe_code', $v);
        }
        if (($v = (int) $r->query('owner_user_id', 0))           > 0) {
            $q->where('alerts.current_owner_user_id', $v);
        }
        if (($q_str = trim((string) $r->query('q', '')))         !== '') {
            $like = '%' . $q_str . '%';
            $q->where(function ($w) use ($like) {
                $w->where('alerts.alert_code',   'like', $like)
                  ->orWhere('alerts.alert_title','like', $like)
                  ->orWhere('alerts.alert_details','like', $like);
            });
        }

        // Province (geo filter beyond district/poe).
        if (($v = trim((string) $r->query('province', '')))      !== '') {
            $q->where('alerts.province_code', $v);
        }

        // Smart date window — past_24h | past_7d | past_14d | past_30d
        // | this_month | this_year | year:2026 | month:2026-04 | all
        $window = trim((string) $r->query('date_window', ''));
        if ($window !== '' && $window !== 'all') {
            $now = Carbon::now();
            $from = null;
            $to   = null;
            switch ($window) {
                case 'past_24h':   $from = $now->copy()->subDay(); break;
                case 'past_7d':    $from = $now->copy()->subDays(7); break;
                case 'past_14d':   $from = $now->copy()->subDays(14); break;
                case 'past_30d':   $from = $now->copy()->subDays(30); break;
                case 'this_month': $from = $now->copy()->startOfMonth(); $to = $now->copy()->endOfMonth(); break;
                case 'this_year':  $from = $now->copy()->startOfYear();  $to = $now->copy()->endOfYear();  break;
                default:
                    if (preg_match('/^year:(\d{4})$/', $window, $m)) {
                        $from = Carbon::createFromDate((int) $m[1], 1, 1)->startOfYear();
                        $to   = Carbon::createFromDate((int) $m[1], 1, 1)->endOfYear();
                    } elseif (preg_match('/^month:(\d{4})-(\d{1,2})$/', $window, $m)) {
                        $from = Carbon::createFromDate((int) $m[1], (int) $m[2], 1)->startOfMonth();
                        $to   = Carbon::createFromDate((int) $m[1], (int) $m[2], 1)->endOfMonth();
                    }
                    break;
            }
            if ($from) $q->where('alerts.created_at', '>=', $from);
            if ($to)   $q->where('alerts.created_at', '<=', $to);
        }
    }

    private function countTabs(callable $base, Request $r): array
    {
        $count = function (string $tab) use ($base, $r): int {
            $q = $base();
            $this->applyTab($q, $tab);
            $this->applyFilters($q, $r);
            return $q->count();
        };
        return [
            'open'         => $count('open'),
            'acknowledged' => $count('acknowledged'),
            'closed'       => $count('closed'),
            'reopened'     => $count('reopened'),
            'all'          => $count('all'),
        ];
    }

    /**
     * Compute live SLA snapshot per dashboard.txt §B.6 (4h/24h/15d ladder).
     * Returns hours-elapsed, target-by-phase, breach flags, % consumed.
     */
    private function computeSla(object $a): array
    {
        $created = Carbon::parse($a->created_at);
        $ackAt   = $a->acknowledged_at ? Carbon::parse($a->acknowledged_at) : null;
        $closed  = $a->closed_at       ? Carbon::parse($a->closed_at)       : null;
        $now     = Carbon::now();

        $matrix = [
            'CRITICAL' => ['detect' => 4,  'notify' => 24, 'respond' => 15 * 24],
            'HIGH'     => ['detect' => 24, 'notify' => 48, 'respond' => 15 * 24],
            'MEDIUM'   => ['detect' => 48, 'notify' => 72, 'respond' => 15 * 24],
            'LOW'      => ['detect' => 7 * 24, 'notify' => 14 * 24, 'respond' => 30 * 24],
        ];
        $row = $matrix[$a->risk_level] ?? $matrix['MEDIUM'];

        $detectElapsed  = $ackAt ? $created->diffInMinutes($ackAt) / 60 : $created->diffInMinutes($now) / 60;
        $respondElapsed = $closed ? $created->diffInMinutes($closed) / 60 : $created->diffInMinutes($now) / 60;
        $notifyAnchor   = $ackAt ?? $created;
        $notifyElapsed  = $notifyAnchor->diffInMinutes($now) / 60;

        return [
            'risk_level' => $a->risk_level,
            'targets_h'  => $row,
            'elapsed_h'  => [
                'detect'  => round($detectElapsed,  2),
                'notify'  => round($notifyElapsed,  2),
                'respond' => round($respondElapsed, 2),
            ],
            'breached' => [
                'detect'  => ! $ackAt && $detectElapsed   > $row['detect'],
                'notify'  => ! $closed && $notifyElapsed  > $row['notify'],
                'respond' => ! $closed && $respondElapsed > $row['respond'],
            ],
            'percent' => [
                'detect'  => $row['detect']  > 0 ? min(100, round($detectElapsed  / $row['detect']  * 100)) : 0,
                'notify'  => $row['notify']  > 0 ? min(100, round($notifyElapsed  / $row['notify']  * 100)) : 0,
                'respond' => $row['respond'] > 0 ? min(100, round($respondElapsed / $row['respond'] * 100)) : 0,
            ],
        ];
    }

    /**
     * @param object  $a                      raw alerts row with owner/ack joins
     * @param int     $blockingFollowupsCount blocking followups still outstanding
     * @param ?string $topDiseaseCode         rank-1 suspected disease code
     * @param ?string $travellerName          display name (resolved upstream)
     * @param array   $suspectedDiseases      list of up to 3 ranked diseases (see topDiseasesMulti)
     */
    private function castRow(
        object $a,
        int $blockingFollowupsCount,
        ?string $topDiseaseCode = null,
        ?string $travellerName = null,
        array $suspectedDiseases = []
    ): array {
        // Carbon::diffInMinutes returns a positive value when args are swapped;
        // pass $now first so future-clock-skew rows (mobile sync drift) come
        // back NEGATIVE, which the UI can render as "just submitted" rather
        // than "10 minutes ago" for a not-yet-existing event.
        $now = Carbon::now();
        $createdAt = Carbon::parse((string) $a->created_at);
        $ageMinutes = (int) $now->diffInMinutes($createdAt, false) * -1;

        $base = [
            'id'                       => (int) $a->id,
            'alert_code'               => (string) $a->alert_code,
            'alert_title'              => (string) $a->alert_title,
            'alert_details'            => $a->alert_details,
            'risk_level'               => (string) $a->risk_level,
            'ihr_tier'                 => $a->ihr_tier,
            'status'                   => (string) $a->status,
            'routed_to_level'          => (string) $a->routed_to_level,
            'province_code'            => $a->province_code,
            'district_code'            => (string) $a->district_code,
            'poe_code'                 => (string) $a->poe_code,
            'current_owner_user_id'    => $a->current_owner_user_id ? (int) $a->current_owner_user_id : null,
            'current_owner_level'      => $a->current_owner_level   ?? null,
            'current_owner_name'       => $a->owner_name            ?? null,
            'current_owner_role'       => $a->owner_role            ?? null,
            'acknowledged_by_user_id'  => $a->acknowledged_by_user_id ? (int) $a->acknowledged_by_user_id : null,
            'acknowledged_by_name'     => $a->ack_name              ?? null,
            'acknowledged_by_role'     => $a->ack_role              ?? null,
            'acknowledged_at'          => $a->acknowledged_at,
            'closed_at'                => $a->closed_at,
            'close_category'           => $a->close_category,
            'close_note'               => $a->close_note,
            'merged_into_alert_id'     => $a->merged_into_alert_id ? (int) $a->merged_into_alert_id : null,
            'reopen_count'             => (int) ($a->reopen_count ?? 0),
            'reopened_at'              => $a->reopened_at,
            'pheic_declared_at'        => $a->pheic_declared_at ?? null,
            'created_at'               => $a->created_at,
            'updated_at'               => $a->updated_at,
            'blocking_followups_count' => $blockingFollowupsCount,
            'age_minutes'              => $ageMinutes,
            // Up to 3 ranked suspected diseases. Each entry: {disease_code, rank_order, confidence}.
            // HumanLabels::wrapAlert below will inject `human.disease` (rank-1 pretty label);
            // the view renders additional ranks as secondary chips.
            'suspected_diseases'       => $suspectedDiseases,
        ];

        return HumanLabels::wrapAlert($base, $topDiseaseCode, $travellerName);
    }

    private function castFollowup(object $f): array
    {
        $base = [
            'id'              => (int) $f->id,
            'action_code'     => (string) $f->action_code,
            'action_label'    => (string) $f->action_label,
            'status'          => (string) $f->status,
            'blocks_closure'  => (bool) $f->blocks_closure,
            'due_at'          => $f->due_at,
            'started_at'      => $f->started_at,
            'completed_at'    => $f->completed_at,
            'notes'           => $f->notes,
            'evidence_ref'    => $f->evidence_ref,
            'assigned_to_role'=> $f->assigned_to_role,
        ];

        return HumanLabels::wrapFollowup($base);
    }

    private function castEvent(object $e): array
    {
        $code     = (string) $e->event_code;
        $category = (string) $e->event_category;
        $severity = (string) $e->severity;

        return [
            'id'                  => (int) $e->id,
            'event_code'          => $code,
            'event_category'      => $category,
            'severity'            => $severity,
            'actor_user_id'       => $e->actor_user_id ? (int) $e->actor_user_id : null,
            'actor_name'          => $e->actor_name,
            'actor_role'          => $e->actor_role,
            'summary'             => (string) $e->summary,
            'related_entity_type' => $e->related_entity_type,
            'related_entity_id'   => $e->related_entity_id ? (int) $e->related_entity_id : null,
            'payload'             => $e->payload_json ? json_decode($e->payload_json, true) : null,
            'created_at'          => $e->created_at,

            // Plain-English overlay so timeline views never render raw codes.
            'human' => [
                'title'      => $this->humaniseEventCode($code, $category),
                'tone'       => match ($severity) {
                    'CRITICAL' => 'urgent',
                    'ERROR'    => 'urgent',
                    'WARN'     => 'watch',
                    default    => 'info',
                },
                'when_human' => HumanLabels::dueHuman((string) $e->created_at),
            ],
        ];
    }

    /**
     * Translates timeline event codes into one-line human titles. Unknown
     * codes fall back to a prettifier so nothing reaches the UI as
     * SHOUTING_SNAKE_CASE.
     */
    private function humaniseEventCode(string $code, string $category): string
    {
        return match ($code) {
            'ALERT_CREATED'                  => 'Alert opened',
            'ALERT_ACKNOWLEDGED'             => 'Someone acknowledged this case',
            'ALERT_CLOSED'                   => 'Case closed',
            'ALERT_CLOSED_FALSE_ALARM'       => 'Closed as a false alarm',
            'ALERT_MASTER_CLOSED'            => 'Closed on behalf of the team',
            'ALERT_REOPENED'                 => 'Case reopened',
            'ALERT_ESCALATED'                => 'Sent higher up',
            'ALERT_REASSIGNED'               => 'Handed to someone else',
            'FOLLOWUP_COMPLETED'             => 'A step was marked done',
            'FOLLOWUP_NOT_APPLICABLE'        => 'A step was marked not applicable',
            'FOLLOWUP_BLOCKED'               => 'A step is stuck',
            'FOLLOWUP_IN_PROGRESS'           => 'A step is in progress',
            'WIZARD_DECISION'                => 'Wizard decision recorded',
            'STAKEHOLDER_CONTACT'            => 'Reached out to a stakeholder',
            'BREACH_LOGGED'                  => 'A delay was logged',
            'BREACH_UPDATED'                 => 'A delay record was updated',
            'EXTERNAL_INFO_REQUESTED'        => 'Asked an outside expert for information',
            'EXTERNAL_INFO_RECEIVED'         => 'An outside expert sent us information',
            'COLLABORATOR_ADDED'             => 'Added a teammate to this case',
            'COLLABORATOR_REMOVED'           => 'Removed a teammate from this case',
            'COMMENT_POSTED'                 => 'A note was added',
            'EVIDENCE_ADDED'                 => 'A document or photo was attached',
            'HANDOFF_CREATED'                => 'A handover was started',
            'HANDOFF_ACCEPTED'               => 'A handover was accepted',
            'HANDOFF_REJECTED'               => 'A handover was declined',
            default                          => HumanLabels::prettify($code),
        };
    }

    /**
     * Batched lookup of the top suspected disease per alert id (rank_order=1).
     * Used by callers that only need the headline disease string.
     *
     * @param int[] $alertIds
     * @return array<int,?string>
     */
    private function topDiseasesFor(array $alertIds): array
    {
        $multi = $this->topDiseasesMulti($alertIds, 1);
        $out = [];
        foreach ($multi as $aid => $list) {
            $out[$aid] = $list[0]['disease_code'] ?? null;
        }
        return $out;
    }

    /**
     * Batched lookup of up to N (default 3) ranked suspected diseases per alert.
     * Returns array<int, list<array{disease_code:string, rank_order:int, confidence:?float}>>
     * Ordered by rank_order asc (rank 1 first). Confidence is normalised to 0-100.
     *
     * @param int[] $alertIds
     * @param int   $perAlert  cap per alert (1..3 makes sense; we ship 3 to the master row)
     * @return array<int,list<array<string,mixed>>>
     */
    private function topDiseasesMulti(array $alertIds, int $perAlert = 3): array
    {
        if (empty($alertIds)) return [];
        $perAlert = max(1, min(5, $perAlert));

        $rows = DB::table('alerts as a')
            ->join('secondary_suspected_diseases as s', 's.secondary_screening_id', '=', 'a.secondary_screening_id')
            ->whereIn('a.id', $alertIds)
            ->where('s.rank_order', '<=', $perAlert)
            ->orderBy('a.id')->orderBy('s.rank_order')->orderBy('s.id')
            ->get(['a.id as alert_id', 's.disease_code', 's.rank_order', 's.confidence']);

        $out = [];
        foreach ($rows as $r) {
            $aid = (int) $r->alert_id;
            if (!isset($out[$aid])) { $out[$aid] = []; }
            if (count($out[$aid]) >= $perAlert) { continue; }
            // confidence is stored as decimal(5,2) — emit as float; 0-100 scale.
            $conf = $r->confidence === null ? null : (float) $r->confidence;
            $out[$aid][] = [
                'disease_code' => (string) $r->disease_code,
                'rank_order'   => (int) $r->rank_order,
                'confidence'   => $conf,
            ];
        }
        return $out;
    }

    /**
     * Batched lookup of the traveller display name per alert id. Falls back
     * through traveler_full_name → traveler_initials → traveler_anonymous_code.
     *
     * @param int[] $alertIds
     * @return array<int,?string>
     */
    private function travellersFor(array $alertIds): array
    {
        if (empty($alertIds)) return [];

        $rows = DB::table('alerts as a')
            ->join('secondary_screenings as s', 's.id', '=', 'a.secondary_screening_id')
            ->whereIn('a.id', $alertIds)
            ->get([
                'a.id as alert_id',
                's.traveler_full_name',
                's.traveler_initials',
                's.traveler_anonymous_code',
            ]);

        $out = [];
        foreach ($rows as $r) {
            $name = trim((string) ($r->traveler_full_name ?: ''));
            if ($name === '') $name = trim((string) ($r->traveler_initials ?: ''));
            if ($name === '') $name = trim((string) ($r->traveler_anonymous_code ?: ''));
            $out[(int) $r->alert_id] = $name !== '' ? $name : null;
        }
        return $out;
    }

    /**
     * Groups already-cast rows by disease group for the master list grouper.
     * Returns a list of { code, label, count, top_priority_count, alert_ids[] }.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function groupRowsByDisease(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $g    = (string) ($row['human']['disease']['group']       ?? 'syndromic_unknown');
            $gl   = (string) ($row['human']['disease']['group_label'] ?? '');
            $top  = ($row['human']['disease']['tier']['bucket'] ?? '') === 'top';
            $aid  = (int) ($row['id'] ?? 0);

            if (!isset($buckets[$g])) {
                $buckets[$g] = [
                    'code'                => $g,
                    'label'               => $gl,
                    'count'               => 0,
                    'top_priority_count'  => 0,
                    'alert_ids'           => [],
                ];
            }
            $buckets[$g]['count']++;
            if ($top) $buckets[$g]['top_priority_count']++;
            $buckets[$g]['alert_ids'][] = $aid;
        }

        // Sort: groups with top-priority cases first, then by count desc.
        $list = array_values($buckets);
        usort($list, static function ($a, $b) {
            return [$b['top_priority_count'], $b['count']] <=> [$a['top_priority_count'], $a['count']];
        });

        return $list;
    }

    /** Append-only timeline insert — mirrors AlertCollaborationController::emitTimeline shape. */
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
            Log::warning('[Admin\\Alerts][emitTimeline] ' . $e->getMessage());
        }
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if (!empty($meta)) {$b['meta'] = $meta;} return response()->json($b); }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    { return response()->json(['success'=>false,'message'=>$message,'error'=>$detail], $status); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Alerts][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
