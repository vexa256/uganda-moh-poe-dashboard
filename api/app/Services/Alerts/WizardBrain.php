<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Http\Controllers\AlertsController as CanonicalAlertsController;
use App\Services\AlertAdvisor;
use App\Services\NotificationDispatcher;
use App\Support\Alerts\HumanLabels;
use App\Support\Alerts\PriorityRules;
use App\Support\Scope\ScopeFilter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The deterministic resolution-wizard reasoning engine.
 *
 * Reads the alert + suspected diseases + follow-ups + clinical vitals,
 * applies PriorityRules, and returns one structured step at a time. Every
 * decision the officer takes is appended to alert_wizard_state.decisions
 * and a corresponding alert_followups row is mutated.
 *
 * Hard rules:
 *   - Scope is honoured on every read AND every write.
 *   - All writes go through DB::transaction so a partial failure never
 *     leaves the wizard half-stepped.
 *   - All mutations record an alert_timeline_event via TimelineRecorder.
 *   - All user-facing strings come from HumanLabels (no raw enums leak).
 *
 * Public surface:
 *   nextStep()              — current step or closure summary
 *   applyDecision()         — records the answer, advances, returns next
 *   bulkResolveFalseAlarm() — sweeps all gating items NOT_APPLICABLE + closes FALSE_POSITIVE
 *   masterCloseSweep()      — NATIONAL_ADMIN sweeps gating items COMPLETED + closes
 *   recordContact()         — logs a stakeholder contact (resend, called, ask new)
 *   progress()              — sidebar checklist data
 *   gatewayOptions()        — bottom-sheet five-options for the smart-action gateway
 */
final class WizardBrain
{
    public function __construct(
        private readonly TimelineRecorder $timeline,
        private readonly StakeholderPicker $stakeholders,
        private readonly CaseOutcomeRecorder $outcomes,
    ) {}

    /**
     * Returns either the next gating step or a closure summary.
     *
     * Shape (step):
     *   { kind: 'step', step_code, title, why, options[], evidence_prompt,
     *     who_can_help[], auto_actions[] }
     *
     * Shape (closure):
     *   { kind: 'closure', summary, can_master_close, gating_remaining: [] }
     *
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    public function nextStep(int $alertId, array $scope): array
    {
        $alert = $this->loadAlert($alertId, $scope);
        if (!$alert) return $this->kind('forbidden', ['reason' => 'Alert not visible to this account.']);

        $disease   = $this->topDisease($alert);
        $followups = $this->loadFollowups($alertId);
        $gating    = $this->gatingSet($followups);

        if (empty($gating)) {
            return $this->closureStep($alert, $followups, $scope);
        }

        $criticalVitals = $this->hasCriticalVitals($alert);
        $order   = PriorityRules::orderFor($disease['code'], $criticalVitals);
        $autoNa  = PriorityRules::notApplicableFor($disease['code']);

        $nextRow = $this->findNextByPriority($order, $gating);
        if (!$nextRow) {
            // Gating contains codes not in the priority list — fall back to earliest due.
            $nextRow = $this->fallbackByDue($gating);
        }

        return $this->stepPayload($alert, $disease, $nextRow, $autoNa, $followups, $scope, $criticalVitals);
    }

    /**
     * Records the officer's answer to the current step and returns the next.
     *
     * Option codes:
     *   YES_DONE        — follow-up COMPLETED, optional evidence_ref / note
     *   IN_PROGRESS     — follow-up IN_PROGRESS (started_at set if null)
     *   NOT_APPLICABLE  — follow-up NOT_APPLICABLE, requires reason ≥1 char
     *   NEED_HELP       — no follow-up change; suggestions surfaced
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function applyDecision(
        int $alertId,
        string $stepCode,
        string $optionCode,
        array $extra,
        array $scope,
        ?int $actorUserId = null
    ): array {
        $alert = $this->loadAlert($alertId, $scope);
        if (!$alert) return $this->kind('forbidden', ['reason' => 'Alert not visible.']);

        $actorUserId ??= (int) (Auth::id() ?? 0) ?: null;

        $row = DB::table('alert_followups')
            ->where('alert_id', $alertId)
            ->where('action_code', $stepCode)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();

        if (!$row) {
            return $this->kind('error', ['reason' => 'That step is not on this case.']);
        }

        $now = Carbon::now();

        try {
            DB::transaction(function () use ($alertId, $stepCode, $optionCode, $extra, $row, $now, $actorUserId) {
                $update = [];
                $summary = '';

                switch ($optionCode) {
                    case 'YES_DONE':
                        $update = [
                            'status'                => 'COMPLETED',
                            'completed_at'          => $now,
                            'completed_by_user_id'  => $actorUserId,
                            'updated_at'            => $now,
                        ];
                        if (!empty($extra['evidence_ref'])) $update['evidence_ref'] = mb_substr((string) $extra['evidence_ref'], 0, 190);
                        if (!empty($extra['note']))         $update['notes']        = mb_substr((string) $extra['note'], 0, 500);
                        $summary = 'Marked done by officer.';
                        break;

                    case 'IN_PROGRESS':
                        $update = [
                            'status'     => 'IN_PROGRESS',
                            'started_at' => $row->started_at ?: $now,
                            'updated_at' => $now,
                        ];
                        if (!empty($extra['note'])) $update['notes'] = mb_substr((string) $extra['note'], 0, 500);
                        $summary = 'Marked in progress.';
                        break;

                    case 'NOT_APPLICABLE':
                        $reason = trim((string) ($extra['reason'] ?? ''));
                        $update = [
                            'status'     => 'NOT_APPLICABLE',
                            'notes'      => mb_substr($reason ?: 'Marked not applicable.', 0, 500),
                            'updated_at' => $now,
                        ];
                        $summary = 'Marked not applicable: ' . mb_substr($reason, 0, 200);
                        break;

                    case 'NEED_HELP':
                        // No status change; we just record the ask in the timeline below.
                        $summary = 'Officer asked for help on this step.';
                        break;

                    default:
                        $summary = 'Unknown option.';
                        break;
                }

                if (!empty($update)) {
                    DB::table('alert_followups')->where('id', $row->id)->update($update);
                }

                $this->appendDecision($alertId, $stepCode, $optionCode, $extra, $actorUserId, $now);

                $this->timeline->recordWizardDecision(
                    $alertId,
                    $stepCode,
                    $optionCode,
                    $summary,
                    ['followup_id' => (int) $row->id, 'extra' => $extra],
                    $actorUserId
                );
            });
        } catch (Throwable $e) {
            Log::error('[WizardBrain][applyDecision] ' . $e->getMessage(), [
                'alert' => $alertId, 'step' => $stepCode, 'option' => $optionCode,
            ]);
            return $this->kind('error', ['reason' => 'Could not save your answer. Please try again.']);
        }

        // WHO-aligned outcome capture: when LAB_CONFIRMATION is marked done,
        // record the positive lab signal so analytics see "confirmed" cases.
        // The user can refine via the case-file outcome panel later.
        if ($stepCode === 'LAB_CONFIRMATION' && $optionCode === 'YES_DONE') {
            $alert     = $this->loadAlert($alertId, $scope);
            $disease   = $alert ? $this->topDisease($alert)['code'] : null;
            $this->outcomes->recordLabResult(
                $alertId,
                'POSITIVE',
                $disease ?: null,
                null,
                $actorUserId,
                'Lab confirmation marked done from the wizard.'
            );
        }

        return $this->nextStep($alertId, $scope);
    }

    /**
     * False-alarm sweep: all gating followups → NOT_APPLICABLE, then close
     * the alert with close_category=FALSE_POSITIVE. Single transaction.
     *
     * @return array<string,mixed>
     */
    public function bulkResolveFalseAlarm(
        int $alertId,
        string $reason,
        array $scope,
        ?int $actorUserId = null
    ): array {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            return $this->kind('error', ['reason' => 'Please give a slightly longer reason — at least 10 characters.']);
        }

        $alert = $this->loadAlert($alertId, $scope);
        if (!$alert) return $this->kind('forbidden', ['reason' => 'Alert not visible.']);
        if (($alert->status ?? '') === 'CLOSED') return $this->kind('error', ['reason' => 'This case is already closed.']);

        $actorUserId ??= (int) (Auth::id() ?? 0) ?: null;

        $now      = Carbon::now();
        $template = (string) trans('alerts.wizard.sweep.note_template', ['reason' => mb_substr($reason, 0, 200)]);

        try {
            $closedRows = DB::transaction(function () use ($alertId, $reason, $template, $now, $actorUserId) {
                $rows = DB::table('alert_followups')
                    ->where('alert_id', $alertId)
                    ->whereNotIn('status', ['COMPLETED', 'NOT_APPLICABLE'])
                    ->whereNull('deleted_at')
                    ->get(['id', 'action_code']);

                foreach ($rows as $r) {
                    DB::table('alert_followups')->where('id', $r->id)->update([
                        'status'     => 'NOT_APPLICABLE',
                        'notes'      => mb_substr($template, 0, 500),
                        'updated_at' => $now,
                    ]);
                    $this->timeline->recordWizardDecision(
                        $alertId,
                        (string) $r->action_code,
                        'NOT_APPLICABLE_FALSE_ALARM',
                        'Marked not applicable as part of a false-alarm closure.',
                        ['followup_id' => (int) $r->id, 'reason' => mb_substr($reason, 0, 500)],
                        $actorUserId
                    );
                }

                DB::table('alerts')->where('id', $alertId)->update([
                    'status'         => 'CLOSED',
                    'closed_at'      => $now,
                    'close_category' => 'FALSE_POSITIVE',
                    'close_note'     => mb_substr($reason, 0, 1000),
                    'updated_at'     => $now,
                ]);

                $this->timeline->record(
                    $alertId,
                    'ALERT_CLOSED_FALSE_ALARM',
                    'CLOSURE',
                    mb_substr('Closed as a false alarm: ' . $reason, 0, 500),
                    ['close_category' => 'FALSE_POSITIVE', 'reason' => $reason, 'sweep_count' => count($rows)],
                    TimelineRecorder::SEVERITY_INFO,
                    $actorUserId
                );

                $this->appendDecision($alertId, 'CLOSURE', 'FALSE_ALARM', ['reason' => $reason, 'sweep_count' => count($rows)], $actorUserId, $now);

                return count($rows);
            });
        } catch (Throwable $e) {
            Log::error('[WizardBrain][bulkResolveFalseAlarm] ' . $e->getMessage());
            return $this->kind('error', ['reason' => 'Could not complete the false-alarm closure. Please try again.']);
        }

        // Record the WHO outcome FIRST so the closure email picks it up.
        $this->outcomes->recordFromFalseAlarm($alertId, $reason, $actorUserId);

        $this->fanOutClosureNotice($alertId, $actorUserId, 'FALSE_POSITIVE', $reason);

        return $this->kind('closed', [
            'category'         => 'FALSE_POSITIVE',
            'category_label'   => 'It was not a real case (false alarm)',
            'sweep_count'      => $closedRows,
            'message'          => 'The case has been closed as a false alarm. Everyone we told has been notified of the closure.',
        ]);
    }

    /**
     * NATIONAL_ADMIN sweeps every still-gating followup as COMPLETED-by-admin
     * with a single shared override reason, then closes the alert with the
     * supplied close category. Reuses the existing AlertsController close
     * gate logic by writing through the same fields.
     *
     * @return array<string,mixed>
     */
    public function masterCloseSweep(
        int $alertId,
        string $overrideReason,
        string $closeCategory,
        ?string $closeNote,
        ?int $mergedIntoAlertId,
        array $scope,
        ?int $actorUserId = null
    ): array {
        $overrideReason = trim($overrideReason);
        if (mb_strlen($overrideReason) < 30) {
            return $this->kind('error', ['reason' => 'Please give us at least 30 characters of explanation.']);
        }
        if (!array_key_exists($closeCategory, CanonicalAlertsController::CLOSE_CATEGORIES)) {
            return $this->kind('error', ['reason' => 'That is not a recognised closing reason.']);
        }
        if ($closeCategory === 'OTHER' && trim((string) $closeNote) === '') {
            return $this->kind('error', ['reason' => 'Please add a short note explaining the reason.']);
        }
        if ($closeCategory === 'DUPLICATE' && !$mergedIntoAlertId) {
            return $this->kind('error', ['reason' => 'Tell us which alert this one is a duplicate of.']);
        }

        $alert = $this->loadAlert($alertId, $scope);
        if (!$alert) return $this->kind('forbidden', ['reason' => 'Alert not visible.']);
        if (!ScopeFilter::isSuper($scope)) {
            return $this->kind('forbidden', ['reason' => 'Only national admins can close on behalf of the team.']);
        }
        if (($alert->status ?? '') === 'CLOSED') return $this->kind('error', ['reason' => 'This case is already closed.']);

        $actorUserId ??= (int) (Auth::id() ?? 0) ?: null;
        $now = Carbon::now();
        $note = mb_substr('Closed on behalf of field team — see override reason on alert.', 0, 500);

        try {
            $closedRows = DB::transaction(function () use ($alertId, $overrideReason, $closeCategory, $closeNote, $mergedIntoAlertId, $now, $actorUserId, $note) {
                $rows = DB::table('alert_followups')
                    ->where('alert_id', $alertId)
                    ->whereNotIn('status', ['COMPLETED', 'NOT_APPLICABLE'])
                    ->whereNull('deleted_at')
                    ->get(['id', 'action_code']);

                foreach ($rows as $r) {
                    DB::table('alert_followups')->where('id', $r->id)->update([
                        'status'                => 'COMPLETED',
                        'completed_at'          => $now,
                        'completed_by_user_id'  => $actorUserId,
                        'notes'                 => $note,
                        'updated_at'            => $now,
                    ]);
                    $this->timeline->recordWizardDecision(
                        $alertId,
                        (string) $r->action_code,
                        'MASTER_COMPLETE_BY_ADMIN',
                        'Marked done by national admin during master close.',
                        ['followup_id' => (int) $r->id, 'override_reason' => mb_substr($overrideReason, 0, 200)],
                        $actorUserId
                    );
                }

                DB::table('alerts')->where('id', $alertId)->update([
                    'status'                => 'CLOSED',
                    'closed_at'             => $now,
                    'close_category'        => $closeCategory,
                    'close_note'            => $closeNote ? mb_substr((string) $closeNote, 0, 1000) : null,
                    'merged_into_alert_id'  => $mergedIntoAlertId,
                    'updated_at'            => $now,
                ]);

                $this->timeline->record(
                    $alertId,
                    'ALERT_MASTER_CLOSED',
                    'CLOSURE',
                    mb_substr('Master closed by admin: ' . $overrideReason, 0, 500),
                    [
                        'close_category'  => $closeCategory,
                        'override_reason' => $overrideReason,
                        'sweep_count'     => count($rows),
                    ],
                    TimelineRecorder::SEVERITY_INFO,
                    $actorUserId
                );

                $this->appendDecision($alertId, 'CLOSURE', 'MASTER_CLOSE', [
                    'category'        => $closeCategory,
                    'override_reason' => $overrideReason,
                    'sweep_count'     => count($rows),
                ], $actorUserId, $now);

                return count($rows);
            });
        } catch (Throwable $e) {
            Log::error('[WizardBrain][masterCloseSweep] ' . $e->getMessage());
            return $this->kind('error', ['reason' => 'Could not complete the master close. Please try again.']);
        }

        // Record the WHO outcome FIRST so the closure email picks it up.
        $this->outcomes->recordFromMasterClose(
            $alertId,
            $closeCategory,
            $closeNote,
            $overrideReason,
            $actorUserId
        );

        $this->fanOutClosureNotice($alertId, $actorUserId, $closeCategory, $overrideReason);

        return $this->kind('closed', [
            'category'         => $closeCategory,
            'category_label'   => HumanLabels::closeCategory($closeCategory)['label'],
            'sweep_count'      => $closedRows,
            'message'          => 'The case has been closed on behalf of the field team. Everyone we told has been notified.',
        ]);
    }

    /**
     * Records a stakeholder contact in the timeline. Optionally fires a
     * notification template (WIZARD_ASK_LAB / WIZARD_REMIND_RESPONDER, etc.)
     * via the existing dispatcher.
     *
     * @param array<string,mixed> $extra
     */
    public function recordContact(
        int $alertId,
        string $action,
        string $message,
        array $extra,
        array $scope,
        ?int $actorUserId = null
    ): array {
        $alert = $this->loadAlert($alertId, $scope);
        if (!$alert) return $this->kind('forbidden', ['reason' => 'Alert not visible.']);

        $actorUserId ??= (int) (Auth::id() ?? 0) ?: null;

        $summary = match ($action) {
            'RESEND_EMAIL'   => 'Resent the alert email to a stakeholder.',
            'MARKED_CALLED'  => 'Marked that we spoke with a stakeholder by phone.',
            'ASKED_NEW'      => 'Reached out to a new stakeholder.',
            default          => 'Recorded a stakeholder contact.',
        };

        $this->timeline->recordStakeholderContact(
            $alertId,
            $action,
            $summary . (trim($message) !== '' ? ' Note: ' . mb_substr(trim($message), 0, 300) : ''),
            $extra,
            $actorUserId
        );

        return $this->kind('ok', [
            'action'   => $action,
            'summary'  => $summary,
        ]);
    }

    /**
     * Sidebar checklist data — every RTSL action with its current state and
     * a tone hint, in priority order, with the next-step marker.
     *
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    public function progress(int $alertId, array $scope): array
    {
        $alert = $this->loadAlert($alertId, $scope);
        if (!$alert) return ['items' => [], 'message' => 'Alert not visible.'];

        $disease   = $this->topDisease($alert);
        $followups = $this->loadFollowups($alertId);
        $gating    = $this->gatingSet($followups);

        $criticalVitals = $this->hasCriticalVitals($alert);
        $order   = PriorityRules::orderFor($disease['code'], $criticalVitals);

        $byCode = [];
        foreach ($followups as $f) {
            $byCode[(string) $f->action_code] = $f;
        }

        $next = $this->findNextByPriority($order, $gating);
        $nextCode = $next ? (string) $next->action_code : null;

        $items = [];
        foreach ($order as $code) {
            $row = $byCode[$code] ?? null;
            if (!$row) continue;
            $h = HumanLabels::wrapFollowup($row);
            $items[] = [
                'code'        => $code,
                'title'       => $h['human']['title'],
                'short'       => $h['human']['short'],
                'status_label'=> $h['human']['status_label'],
                'status_tone' => $h['human']['status_tone'],
                'icon'        => $h['human']['icon'],
                'is_next'     => ($nextCode === $code),
                'is_blocking' => $h['human']['blocks_close'],
                'due_human'   => $h['human']['due_human'],
            ];
        }

        return [
            'items'           => $items,
            'gating_count'    => count($gating),
            'next_step_code'  => $nextCode,
        ];
    }

    /**
     * Bottom-sheet smart-action gateway — five user-facing options.
     *
     * @return array<string,mixed>
     */
    public function gatewayOptions(int $alertId, array $scope): array
    {
        $alert = $this->loadAlert($alertId, $scope);
        if (!$alert) return ['options' => [], 'message' => 'Alert not visible.'];

        $isClosed = ($alert->status ?? '') === 'CLOSED';
        $isSuper  = ScopeFilter::isSuper($scope);

        $opts = (array) trans('alerts.wizard.gateway.options');
        $build = static fn (string $key, string $action) => [
            'code'   => $action,
            'label'  => (string) ($opts[$key]['label'] ?? $key),
            'help'   => (string) ($opts[$key]['help']  ?? ''),
            'icon'   => (string) ($opts[$key]['icon']  ?? 'circle'),
        ];

        $list = [];
        if (!$isClosed) {
            $list[] = $build('walk_through', 'OPEN_WIZARD');
        }
        $list[] = $build('see_file', 'OPEN_CASEFILE');
        if (!$isClosed) {
            $list[] = $build('reassign', 'OPEN_REASSIGN');
            $list[] = $build('escalate', 'OPEN_ESCALATE');
            $list[] = $build('false_alarm', 'OPEN_FALSE_ALARM');
            if ($isSuper) {
                $list[] = $build('master_close', 'OPEN_MASTER_CLOSE');
            }
        }

        return [
            'title'    => (string) trans('alerts.wizard.gateway.title'),
            'subtitle' => (string) trans('alerts.wizard.gateway.subtitle'),
            'options'  => $list,
        ];
    }

    // ------------------------------------------------------------------
    //  Internals
    // ------------------------------------------------------------------

    /** @return object|null */
    private function loadAlert(int $alertId, array $scope)
    {
        $q = DB::table('alerts')->where('id', $alertId)->whereNull('deleted_at');
        ScopeFilter::applyToAlerts($q, $scope);
        return $q->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function loadFollowups(int $alertId)
    {
        return DB::table('alert_followups')
            ->where('alert_id', $alertId)
            ->whereNull('deleted_at')
            ->orderBy('action_code')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param \Illuminate\Support\Collection<int,object> $followups
     * @return array<int,object>
     */
    private function gatingSet($followups): array
    {
        return $followups->filter(static fn ($f) =>
            (int) ($f->blocks_closure ?? 0) === 1
            && !in_array((string) ($f->status ?? ''), ['COMPLETED', 'NOT_APPLICABLE'], true)
        )->values()->all();
    }

    /**
     * @param string[] $order
     * @param array<int,object> $gating
     */
    private function findNextByPriority(array $order, array $gating): ?object
    {
        $byCode = [];
        foreach ($gating as $g) {
            $byCode[(string) $g->action_code] = $g;
        }
        foreach ($order as $code) {
            if (isset($byCode[$code])) return $byCode[$code];
        }
        return null;
    }

    /**
     * @param array<int,object> $gating
     */
    private function fallbackByDue(array $gating): ?object
    {
        usort($gating, static function ($a, $b) {
            $aDue = $a->due_at ? strtotime((string) $a->due_at) : PHP_INT_MAX;
            $bDue = $b->due_at ? strtotime((string) $b->due_at) : PHP_INT_MAX;
            return $aDue <=> $bDue;
        });
        return $gating[0] ?? null;
    }

    /**
     * Builds the step payload the controller hands back to the Blade.
     *
     * @param array<string,mixed> $disease
     * @param string[] $autoNa
     * @param \Illuminate\Support\Collection<int,object> $followups
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function stepPayload(
        object $alert,
        array $disease,
        object $row,
        array $autoNa,
        $followups,
        array $scope,
        bool $criticalVitals
    ): array {
        $stepCode = (string) $row->action_code;
        $action   = HumanLabels::action($stepCode);

        $why = $action['why'];
        if ($criticalVitals && in_array($stepCode, ['CASE_INVESTIGATION', 'ISOLATION'], true)) {
            $why = 'The patient is unstable. This step has to come first.';
        }
        if (PriorityRules::isTopTier((string) $disease['code']) && $stepCode === 'WHO_NOTIFICATION') {
            $why = 'For this illness we are required to inform international partners within 24 hours. Do not delay.';
        }

        $options = [
            ['code' => 'YES_DONE',       'label' => (string) trans('alerts.wizard.step.option_yes'),   'tone' => 'done'],
            ['code' => 'IN_PROGRESS',    'label' => (string) trans('alerts.wizard.step.option_doing'), 'tone' => 'watch'],
            ['code' => 'NOT_APPLICABLE', 'label' => (string) trans('alerts.wizard.step.option_na'),    'tone' => 'skipped', 'requires_reason' => true],
            ['code' => 'NEED_HELP',      'label' => (string) trans('alerts.wizard.step.option_help'),  'tone' => 'urgent'],
        ];

        $help = $this->stakeholders->forStep((int) $alert->id, $stepCode, $scope);

        return [
            'kind'              => 'step',
            'step_code'         => $stepCode,
            'title'             => $action['title'],
            'short'             => $action['short'],
            'why'               => $why,
            'icon'              => $action['icon'],
            'evidence_prompt'   => 'A photo, the name of the facility, or anything that proves it is done.',
            'options'           => $options,
            'who_can_help'      => $help['suggestions'],
            'who_can_help_hint' => $help['reason'],
            'auto_actions'      => [], // populated by the controller after applyDecision returns
            'meta'              => [
                'alert_id'        => (int) $alert->id,
                'alert_title'     => (string) $alert->alert_title,
                'disease'         => $disease,
                'top_tier'        => PriorityRules::isTopTier((string) $disease['code']),
                'critical_vitals' => $criticalVitals,
                'auto_na'         => $autoNa,
            ],
        ];
    }

    /**
     * Closure step payload — shown when no gating items remain.
     *
     * @param \Illuminate\Support\Collection<int,object> $followups
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function closureStep(object $alert, $followups, array $scope): array
    {
        $closeCats = [];
        foreach (CanonicalAlertsController::CLOSE_CATEGORIES as $code => $_label) {
            $closeCats[] = HumanLabels::closeCategory($code);
        }

        $summary = [
            'completed_count'      => $followups->whereIn('status', ['COMPLETED'])->count(),
            'not_applicable_count' => $followups->whereIn('status', ['NOT_APPLICABLE'])->count(),
            'total_count'          => $followups->count(),
        ];

        $isSuper = ScopeFilter::isSuper($scope);

        return [
            'kind'              => 'closure',
            'title'             => (string) trans('alerts.wizard.closure.title'),
            'subtitle'          => (string) trans('alerts.wizard.closure.subtitle'),
            'category_label'    => (string) trans('alerts.wizard.closure.category_label'),
            'note_label'        => (string) trans('alerts.wizard.closure.note_label'),
            'duplicate_label'   => (string) trans('alerts.wizard.closure.duplicate_label'),
            'override_label'    => (string) trans('alerts.wizard.closure.override_label'),
            'submit'            => (string) trans('alerts.wizard.closure.submit'),
            'submit_master'     => (string) trans('alerts.wizard.closure.submit_master'),
            'close_categories'  => $closeCats,
            'can_master_close'  => $isSuper,
            'summary'           => $summary,
            'meta'              => [
                'alert_id'    => (int) $alert->id,
                'alert_title' => (string) $alert->alert_title,
            ],
        ];
    }

    /**
     * Picks the top suspected disease for the alert (rank_order=1 from
     * secondary_suspected_diseases). Falls back to a syndromic placeholder.
     *
     * @return array{code:string,name:string,group:string,group_label:string,headline:string,tier:array<string,mixed>}
     */
    private function topDisease(object $alert): array
    {
        $diseaseCode = null;
        if (!empty($alert->secondary_screening_id)) {
            $row = DB::table('secondary_suspected_diseases')
                ->where('secondary_screening_id', $alert->secondary_screening_id)
                ->where('rank_order', 1)
                ->first(['disease_code']);
            $diseaseCode = $row?->disease_code;
        }

        return HumanLabels::disease($diseaseCode);
    }

    /**
     * Pulls vitals from the secondary screening (if any) and returns true
     * for any of the AlertAdvisor critical thresholds.
     */
    private function hasCriticalVitals(object $alert): bool
    {
        if (empty($alert->secondary_screening_id)) return false;

        try {
            $row = DB::table('secondary_screenings')
                ->where('id', $alert->secondary_screening_id)
                ->first(['pulse_rate', 'respiratory_rate', 'oxygen_saturation', 'bp_systolic']);
        } catch (Throwable) {
            return false;
        }

        if (!$row) return false;

        $rr   = (int) ($row->respiratory_rate  ?? 0);
        $spo2 = (int) ($row->oxygen_saturation ?? 0);
        $sbp  = (int) ($row->bp_systolic       ?? 0);
        $hr   = (int) ($row->pulse_rate        ?? 0);

        if ($rr   > 30  && $rr   > 0) return true;
        if ($spo2 < 90  && $spo2 > 0) return true;
        if ($sbp  < 90  && $sbp  > 0) return true;
        if ($hr   > 130 && $hr   > 0) return true;

        return false;
    }

    /**
     * Append-only decision history on alert_wizard_state.
     *
     * @param array<string,mixed> $extra
     */
    private function appendDecision(int $alertId, string $stepCode, string $optionCode, array $extra, ?int $actorUserId, Carbon $when): void
    {
        try {
            $existing = DB::table('alert_wizard_state')
                ->where('alert_id', $alertId)
                ->whereNull('deleted_at')
                ->first(['id', 'decisions']);

            $entry = [
                'step'    => $stepCode,
                'option'  => $optionCode,
                'actor'   => $actorUserId,
                'ts'      => $when->toIso8601String(),
                'extra'   => $extra,
            ];

            if (!$existing) {
                DB::table('alert_wizard_state')->insert([
                    'alert_id'           => $alertId,
                    'current_step_code'  => $stepCode,
                    'last_step_at'       => $when,
                    'last_actor_user_id' => $actorUserId,
                    'decisions'          => json_encode([$entry], JSON_UNESCAPED_UNICODE),
                    'created_at'         => $when,
                    'updated_at'         => $when,
                ]);
                return;
            }

            $decisions = json_decode((string) $existing->decisions, true) ?: [];
            if (!is_array($decisions)) $decisions = [];
            $decisions[] = $entry;

            DB::table('alert_wizard_state')->where('id', $existing->id)->update([
                'current_step_code'  => $stepCode,
                'last_step_at'       => $when,
                'last_actor_user_id' => $actorUserId,
                'decisions'          => json_encode($decisions, JSON_UNESCAPED_UNICODE),
                'updated_at'         => $when,
            ]);
        } catch (Throwable $e) {
            Log::warning('[WizardBrain][appendDecision] ' . $e->getMessage());
        }
    }

    /**
     * Best-effort fan-out of the WIZARD_CLOSURE_NOTICE template to the same
     * recipients as the original alert. Failures are logged, never thrown.
     */
    private function fanOutClosureNotice(int $alertId, ?int $actorUserId, string $closeCategory, string $reason): void
    {
        try {
            $alert = DB::table('alerts')->where('id', $alertId)->first();
            if (!$alert) return;

            // Reuse the dispatcher's existing fan-out for closure events.
            NotificationDispatcher::dispatchAlertClosed(
                $alert,
                (int) ($actorUserId ?? 0),
                self::actorName($actorUserId),
                $reason
            );
        } catch (Throwable $e) {
            Log::warning('[WizardBrain][fanOutClosureNotice] ' . $e->getMessage());
        }
    }

    private static function actorName(?int $userId): string
    {
        if (!$userId) return 'a national admin';
        try {
            $u = DB::table('users')->where('id', $userId)->first(['full_name']);
            return (string) ($u->full_name ?? 'a national admin');
        } catch (Throwable) {
            return 'a national admin';
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function kind(string $kind, array $payload): array
    {
        return array_merge(['kind' => $kind], $payload);
    }
}
