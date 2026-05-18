<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\WizardAnswerRequest;
use App\Http\Requests\Admin\Alerts\WizardContactRequest;
use App\Http\Requests\Admin\Alerts\WizardFalseAlarmRequest;
use App\Http\Requests\Admin\Alerts\WizardMasterCloseRequest;
use App\Http\Responses\AlertEnvelope;
use App\Services\Alerts\StakeholderPicker;
use App\Services\Alerts\WizardBrain;
use App\Support\Alerts\HumanLabels;
use App\Support\Scope\ScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Resolution Wizard — the centerpiece of the Admin Alerts UX rewrite.
 *
 * One server-driven page (Blade + Alpine.js for in-page transitions) that
 * walks an officer from "alert just landed" to "case closed" with one
 * clear question on screen at a time. The reasoning lives in WizardBrain
 * (PriorityRules + live alert state); this controller is a thin transport
 * layer that pipes JSON through HumanLabels and AlertEnvelope.
 *
 * Mobile-app safety:
 *   This is /admin/alerts/{id}/wizard/* on the web routes only. The mobile
 *   app's API at /api/alerts/* is untouched.
 */
final class WizardController extends Controller
{
    public function __construct(
        private readonly WizardBrain $brain,
        private readonly StakeholderPicker $stakeholders,
    ) {}

    /**
     * Blade shell for the wizard. Hydration happens client-side via /step,
     * /progress, /stakeholders so the initial HTML is small.
     */
    public function show(Request $request, int $id)
    {
        try {
            $scope = ScopeFilter::fromRequest($request);
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at');
            ScopeFilter::applyToAlerts($alert, $scope);
            $row = $alert->first(['id','alert_code','alert_title','status','risk_level','ihr_tier','poe_code','district_code','province_code','secondary_screening_id','created_at']);

            if (!$row) {
                return AlertEnvelope::err('not_found', 404);
            }

            // Closed cases never enter the wizard — redirect to the case-file
            // view, which carries the full closure summary and read-only
            // dossier. Matches the user-facing rule that a closed case never
            // shows "Walk me through how to close this case".
            if (($row->status ?? '') === 'CLOSED') {
                return redirect()->route('admin.alerts.case-file', ['id' => $row->id]);
            }

            $diseaseCode    = $this->topDiseaseCode((int) $row->id);
            $travellerName  = $this->travellerName($row);

            $wrapped = HumanLabels::wrapAlert((array) $row, $diseaseCode, $travellerName);

            return view('admin.alerts.wizard.show', [
                'alert'   => $wrapped,
                'isSuper' => ScopeFilter::isSuper($scope),
            ]);
        } catch (Throwable $e) {
            return AlertEnvelope::fromThrowable($e, 'wizard.show');
        }
    }

    private function travellerName(object $alert): ?string
    {
        if (empty($alert->secondary_screening_id)) return null;
        try {
            $row = DB::table('secondary_screenings')
                ->where('id', $alert->secondary_screening_id)
                ->first(['traveler_full_name', 'traveler_initials', 'traveler_anonymous_code']);
            if (!$row) return null;
            $name = trim((string) ($row->traveler_full_name ?: ''));
            if ($name === '') $name = trim((string) ($row->traveler_initials ?: ''));
            if ($name === '') $name = trim((string) ($row->traveler_anonymous_code ?: ''));
            return $name !== '' ? $name : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Returns the current step (or closure summary). */
    public function step(Request $request, int $id): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($request);
            return AlertEnvelope::ok($this->brain->nextStep($id, $scope), 'OK');
        } catch (Throwable $e) {
            return AlertEnvelope::fromThrowable($e, 'wizard.step');
        }
    }

    /** Sidebar checklist data. */
    public function progress(Request $request, int $id): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($request);
            return AlertEnvelope::ok($this->brain->progress($id, $scope), 'OK');
        } catch (Throwable $e) {
            return AlertEnvelope::fromThrowable($e, 'wizard.progress');
        }
    }

    /** Right-rail "who is involved" panel. */
    public function stakeholders(Request $request, int $id): JsonResponse
    {
        try {
            $scope   = ScopeFilter::fromRequest($request);
            $payload = $this->stakeholders->forAlert($id, $scope);
            return AlertEnvelope::ok($payload, 'OK');
        } catch (Throwable $e) {
            return AlertEnvelope::fromThrowable($e, 'wizard.stakeholders');
        }
    }

    /** Smart-action gateway options for the bottom-sheet. */
    public function gateway(Request $request, int $id): JsonResponse
    {
        try {
            $scope   = ScopeFilter::fromRequest($request);
            $payload = $this->brain->gatewayOptions($id, $scope);
            return AlertEnvelope::ok($payload, 'OK');
        } catch (Throwable $e) {
            return AlertEnvelope::fromThrowable($e, 'wizard.gateway');
        }
    }

    /** Records the officer's answer to the current step, returns the next step. */
    public function answer(WizardAnswerRequest $request, int $id): JsonResponse
    {
        try {
            $scope  = ScopeFilter::fromRequest($request);
            $extra  = array_filter([
                'reason'       => $request->input('reason'),
                'evidence_ref' => $request->input('evidence_ref'),
                'note'         => $request->input('note'),
            ], static fn ($v) => $v !== null && $v !== '');

            $result = $this->brain->applyDecision(
                $id,
                (string) $request->input('step_code'),
                (string) $request->input('option_code'),
                $extra,
                $scope
            );

            if (($result['kind'] ?? '') === 'forbidden') {
                return AlertEnvelope::err('forbidden', 403, ['reason' => $result['reason'] ?? '']);
            }
            if (($result['kind'] ?? '') === 'error') {
                return AlertEnvelope::err('invalid_state', 422, ['reason' => $result['reason'] ?? '']);
            }

            return AlertEnvelope::ok($result, 'OK');
        } catch (Throwable $e) {
            return AlertEnvelope::fromThrowable($e, 'wizard.answer');
        }
    }

    /** Records a stakeholder contact (resend, called, ask new). */
    public function contact(WizardContactRequest $request, int $id): JsonResponse
    {
        try {
            $scope  = ScopeFilter::fromRequest($request);
            $extra  = array_filter([
                'contact_email' => $request->input('contact_email'),
                'contact_name'  => $request->input('contact_name'),
                'contact_id'    => $request->input('contact_id'),
                'contact_kind'  => $request->input('contact_kind'),
                'template_code' => $request->input('template_code'),
            ], static fn ($v) => $v !== null && $v !== '');

            $result = $this->brain->recordContact(
                $id,
                (string) $request->input('action'),
                (string) $request->input('message', ''),
                $extra,
                $scope
            );

            if (($result['kind'] ?? '') === 'forbidden') {
                return AlertEnvelope::err('forbidden', 403, ['reason' => $result['reason'] ?? '']);
            }

            return AlertEnvelope::ok($result, 'OK');
        } catch (Throwable $e) {
            return AlertEnvelope::fromThrowable($e, 'wizard.contact');
        }
    }

    /** Mass-resolve sweep for false-alarm closures. */
    public function falseAlarm(WizardFalseAlarmRequest $request, int $id): JsonResponse
    {
        try {
            $scope  = ScopeFilter::fromRequest($request);
            $result = $this->brain->bulkResolveFalseAlarm(
                $id,
                (string) $request->input('reason'),
                $scope
            );

            if (($result['kind'] ?? '') === 'forbidden') {
                return AlertEnvelope::err('forbidden', 403, ['reason' => $result['reason'] ?? '']);
            }
            if (($result['kind'] ?? '') === 'error') {
                return AlertEnvelope::err('invalid_state', 422, ['reason' => $result['reason'] ?? '']);
            }

            return AlertEnvelope::ok($result, 'OK');
        } catch (Throwable $e) {
            return AlertEnvelope::fromThrowable($e, 'wizard.false-alarm');
        }
    }

    /** NATIONAL_ADMIN-only sweep + close on behalf of the field team. */
    public function masterClose(WizardMasterCloseRequest $request, int $id): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($request);
            if (!ScopeFilter::isSuper($scope)) {
                return AlertEnvelope::err('forbidden', 403, ['reason' => 'Only national admins can close on behalf of the team.']);
            }

            $result = $this->brain->masterCloseSweep(
                $id,
                (string) $request->input('override_reason'),
                (string) $request->input('close_category'),
                $request->input('close_note'),
                $request->input('merged_into_alert_id') ? (int) $request->input('merged_into_alert_id') : null,
                $scope
            );

            if (($result['kind'] ?? '') === 'forbidden') {
                return AlertEnvelope::err('forbidden', 403, ['reason' => $result['reason'] ?? '']);
            }
            if (($result['kind'] ?? '') === 'error') {
                return AlertEnvelope::err('invalid_state', 422, ['reason' => $result['reason'] ?? '']);
            }

            return AlertEnvelope::ok($result, 'OK');
        } catch (Throwable $e) {
            return AlertEnvelope::fromThrowable($e, 'wizard.master-close');
        }
    }

    private function topDiseaseCode(int $alertId): ?string
    {
        try {
            $row = DB::table('alerts as a')
                ->where('a.id', $alertId)
                ->whereNotNull('a.secondary_screening_id')
                ->join('secondary_suspected_diseases as s', 's.secondary_screening_id', '=', 'a.secondary_screening_id')
                ->where('s.rank_order', 1)
                ->orderBy('s.id')
                ->limit(1)
                ->value('s.disease_code');
            return $row ? (string) $row : null;
        } catch (Throwable) {
            return null;
        }
    }
}
