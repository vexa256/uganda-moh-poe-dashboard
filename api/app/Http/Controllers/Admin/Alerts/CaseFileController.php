<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Alerts;

use App\Http\Controllers\Controller;
use App\Services\AlertAdvisor;
use App\Support\Alerts\HumanLabels;
use App\Support\Scope\ScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin · Alert Case File (alerts refactor §3.4).
 *
 * Renders the 6-tab Case File workspace and the JSON payload that powers it.
 * The Case File is the canonical "100% of the data the mobile wrote" view per
 * the master refactor brief §6 — every column on secondary_screenings + every
 * row on secondary_symptoms / _exposures / _actions / _travel_countries /
 * _suspected_diseases / _samples + the alerts row + the originating
 * notifications row + the running intelligence-layer derivatives + the full
 * append-only timeline + every notification_log dispatch.
 *
 * The deterministic advisor (§3.5) lives behind the same JSON payload via
 * AlertAdvisor::compute() — pure rules over ref_engine_config + ref_diseases
 * + the case state. Cited rule names accompany every recommendation.
 *
 * Reads only — every state-changing button on the view delegates to the
 * existing AlertsController (acknowledge, close, escalate, reassign, reopen)
 * and CaseRoomController (collaborators, comments, evidence, handoffs).
 */
final class CaseFileController extends Controller
{
    public function show(Request $r, int $id)
    {
        $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $alert) abort(404, 'Alert not found.');
        $scope = ScopeFilter::fromRequest($r);
        if (! ScopeFilter::canSeeAlert($scope, $alert)) abort(403, 'Alert is outside your scope.');

        $cast = $this->castAlert($alert);

        return view('admin.alerts.casefile.show', [
            'alertId'   => $id,
            'alertCode' => $alert->alert_code,
            'alert'     => $cast,
        ]);
    }

    public function data(Request $r, int $id): JsonResponse
    {
        try {
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Alert not found.');
            $scope = ScopeFilter::fromRequest($r);
            if (! ScopeFilter::canSeeAlert($scope, $alert)) {
                return $this->err(403, 'Alert is outside your scope.');
            }

            $screening = $alert->secondary_screening_id
                ? DB::table('secondary_screenings')
                    ->where('id', $alert->secondary_screening_id)
                    ->whereNull('deleted_at')
                    ->first()
                : null;

            $notification = ($screening && $screening->notification_id)
                ? DB::table('notifications')
                    ->where('id', $screening->notification_id)
                    ->whereNull('deleted_at')
                    ->first()
                : null;

            $symptoms       = $screening ? $this->loadSymptoms((int) $screening->id)        : collect();
            $exposures      = $screening ? $this->loadExposures((int) $screening->id)       : collect();
            $actions        = $screening ? $this->loadActions((int) $screening->id)         : collect();
            $travel         = $screening ? $this->loadTravelCountries((int) $screening->id) : collect();
            $suspected      = $screening ? $this->loadSuspectedDiseases((int) $screening->id) : collect();
            $samples        = $screening ? $this->loadSamples((int) $screening->id)         : collect();

            $owner = $alert->current_owner_user_id
                ? DB::table('users')->where('id', $alert->current_owner_user_id)->first(['id','full_name','role_key','email'])
                : null;
            $ack = $alert->acknowledged_by_user_id
                ? DB::table('users')->where('id', $alert->acknowledged_by_user_id)->first(['id','full_name','role_key','email'])
                : null;
            $opener = $screening && $screening->opened_by_user_id
                ? DB::table('users')->where('id', $screening->opened_by_user_id)->first(['id','full_name','role_key','email'])
                : null;

            $followups     = DB::table('alert_followups')->where('alert_id', $id)->whereNull('deleted_at')
                ->orderByRaw("FIELD(status,'BLOCKED','PENDING','IN_PROGRESS','COMPLETED','NOT_APPLICABLE')")
                ->orderBy('due_at')->get();
            $collaborators = DB::table('alert_collaborators as c')
                ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
                ->where('c.alert_id', $id)
                ->select(['c.*', 'u.full_name', 'u.role_key', 'u.email'])
                ->orderByDesc('c.is_active')->orderBy('c.id')->get();
            $handoffs      = DB::table('alert_handoffs as h')
                ->leftJoin('users as f', 'f.id', '=', 'h.from_user_id')
                ->leftJoin('users as t', 't.id', '=', 'h.to_user_id')
                ->where('h.alert_id', $id)
                ->select(['h.*','f.full_name as from_name','f.role_key as from_role','t.full_name as to_name','t.role_key as to_role'])
                ->orderByDesc('h.id')->limit(40)->get();
            $breaches      = DB::table('alert_breach_reports')->where('alert_id', $id)->orderByDesc('id')->get();
            $timeline      = DB::table('alert_timeline_events')->where('alert_id', $id)->orderByDesc('id')->limit(200)->get();
            $comments      = DB::table('alert_comments as c')
                ->leftJoin('users as u', 'u.id', '=', 'c.author_user_id')
                ->where('c.alert_id', $id)->whereNull('c.deleted_at')
                ->select(['c.*','u.full_name as author_name','u.role_key as author_role'])
                ->orderByDesc('c.is_pinned')->orderByDesc('c.id')->limit(80)->get();
            $dispatch      = DB::table('notification_log')
                ->where('related_entity_type', 'ALERT')
                ->where('related_entity_id', $id)
                ->orderByDesc('id')->limit(120)->get();

            $intel       = $this->buildIntelligenceDerivatives($alert, $screening, $symptoms, $exposures, $suspected);
            $vitalsChart = $this->buildVitalsChart($screening);
            $advisor     = AlertAdvisor::compute($alert, $screening, $symptoms, $exposures, $actions, $suspected, $intel);

            $sections = $this->buildSections(
                $screening, $symptoms, $exposures, $travel, $suspected, $samples,
                $followups, $collaborators, $handoffs, $timeline, $comments, $dispatch
            );

            $closure = $this->buildClosure($alert, $followups, $timeline);

            return $this->ok([
                'alert'         => $this->castAlert($alert),
                'sections'      => $sections,
                'closure'       => $closure,
                'screening'     => $screening ? $this->castScreening($screening) : null,
                'notification'  => $notification ? (array) $notification : null,
                'people'        => [
                    'owner'   => $owner,
                    'ack'     => $ack,
                    'opener'  => $opener,
                ],
                'symptoms'              => $symptoms->map(fn ($s) => (array) $s)->all(),
                'exposures'             => $exposures->map(fn ($e) => (array) $e)->all(),
                'actions'               => $actions->map(fn ($a) => (array) $a)->all(),
                'travel_countries'      => $travel->map(fn ($t) => (array) $t)->all(),
                'suspected_diseases'    => $suspected->map(fn ($d) => HumanLabels::disease((string) $d->disease_code) + (array) $d)->all(),
                'samples'               => $samples->map(fn ($s) => (array) $s)->all(),
                'followups'             => $followups->map(fn ($f) => HumanLabels::wrapFollowup((array) $f))->all(),
                'collaborators'         => $collaborators->map(fn ($c) => (array) $c)->all(),
                'handoffs'              => $handoffs->map(fn ($h) => (array) $h)->all(),
                'breach_reports'        => $breaches->map(fn ($b) => (array) $b)->all(),
                'timeline'              => $timeline->map(fn ($t) => (array) $t + ['human' => ['when_human' => HumanLabels::dueHuman((string) $t->created_at)]])->all(),
                'comments'              => $comments->map(fn ($c) => (array) $c)->all(),
                'dispatch_receipt'      => $dispatch->map(fn ($d) => (array) $d)->all(),
                'intelligence'          => $intel,
                'vitals_chart'          => $vitalsChart,
                'advisor'               => $advisor,
                'links' => [
                    'war_room'  => "/admin/alerts/{$id}",
                    'case_file' => "/admin/alerts/{$id}/case-file",
                    'api_show'  => "/admin/alerts/{$id}",
                ],
                'permissions' => [
                    'is_super' => ScopeFilter::isSuper($scope),
                    'role'     => (string) ($r->user()->role_key ?? ''),
                ],
            ], 'Case file payload.');
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    /* ─── child loaders (joined with ref tables for display polish) ─── */

    private function loadSymptoms(int $screeningId)
    {
        // Two-step load to avoid collation mismatch between
        // secondary_symptoms.symptom_code (utf8mb4_unicode_ci) and
        // ref_symptoms.symptom_code (utf8mb4_0900_ai_ci) — see laravel.log.
        $rows = DB::table('secondary_symptoms')
            ->where('secondary_screening_id', $screeningId)
            ->orderBy('id')
            ->get(['id','symptom_code','is_present','explicit_absent','onset_date','details']);
        if ($rows->isEmpty()) return $rows;

        $codes = $rows->pluck('symptom_code')->filter()->unique()->all();
        $refRows = $codes
            ? DB::table('ref_symptoms')->whereIn('symptom_code', $codes)
                ->get(['symptom_code','display_name','category','is_red_flag','is_hallmark','syndrome_tags','display_order'])
                ->keyBy('symptom_code')
            : collect();
        return $rows->map(function ($r) use ($refRows) {
            $ref = $refRows[$r->symptom_code] ?? null;
            $r->display_name   = $ref->display_name   ?? null;
            $r->category       = $ref->category       ?? null;
            $r->is_red_flag    = (bool) ($ref->is_red_flag ?? false);
            $r->is_hallmark    = (bool) ($ref->is_hallmark ?? false);
            $r->syndrome_tags  = $ref->syndrome_tags  ?? null;
            $r->display_order  = (int) ($ref->display_order ?? 999);
            return $r;
        })->sortBy(fn ($r) => [$r->display_order, $r->id])->values();
    }

    private function loadExposures(int $screeningId)
    {
        $rows = DB::table('secondary_exposures')
            ->where('secondary_screening_id', $screeningId)
            ->get(['id','exposure_code','response','details']);
        if ($rows->isEmpty()) return $rows;

        $codes = $rows->pluck('exposure_code')->filter()->unique()->all();
        $refRows = $codes
            ? DB::table('ref_exposures')->whereIn('exposure_code', $codes)
                ->get(['exposure_code','display_name','category','is_high_risk','prompt_text','triggers_diseases'])
                ->keyBy('exposure_code')
            : collect();
        return $rows->map(function ($r) use ($refRows) {
            $ref = $refRows[$r->exposure_code] ?? null;
            $r->display_name      = $ref->display_name      ?? null;
            $r->category          = $ref->category          ?? null;
            $r->is_high_risk      = (bool) ($ref->is_high_risk ?? false);
            $r->prompt_text       = $ref->prompt_text       ?? null;
            $r->triggers_diseases = $ref->triggers_diseases ?? null;
            return $r;
        })->sortBy(fn ($r) => [
            ['YES' => 0, 'UNKNOWN' => 1, 'NO' => 2][strtoupper((string) $r->response)] ?? 3,
            $r->id,
        ])->values();
    }

    private function loadActions(int $screeningId)
    {
        return DB::table('secondary_actions')
            ->where('secondary_screening_id', $screeningId)
            ->orderByDesc('is_done')->orderBy('id')->get();
    }

    private function loadTravelCountries(int $screeningId)
    {
        return DB::table('secondary_travel_countries')
            ->where('secondary_screening_id', $screeningId)
            ->orderByDesc('arrival_date')->orderBy('id')->get();
    }

    private function loadSuspectedDiseases(int $screeningId)
    {
        $rows = DB::table('secondary_suspected_diseases')
            ->where('secondary_screening_id', $screeningId)
            ->orderBy('rank_order')
            ->get(['id','disease_code','rank_order','confidence','reasoning']);
        if ($rows->isEmpty()) return $rows;

        $codes = $rows->pluck('disease_code')->filter()->unique()->all();
        $refRows = $codes
            ? DB::table('ref_diseases')->whereIn('disease_code', $codes)
                ->get(['disease_code','display_name','ihr_tier','who_syndrome','incubation_days_min','incubation_days_max','case_definition','gates','symptom_weights','exposure_weights','sources'])
                ->keyBy('disease_code')
            : collect();
        return $rows->map(function ($r) use ($refRows) {
            $ref = $refRows[$r->disease_code] ?? null;
            $r->display_name        = $ref->display_name        ?? null;
            $r->ihr_tier            = $ref->ihr_tier            ?? null;
            $r->who_syndrome        = $ref->who_syndrome        ?? null;
            $r->incubation_days_min = $ref->incubation_days_min ?? null;
            $r->incubation_days_max = $ref->incubation_days_max ?? null;
            $r->case_definition     = $ref->case_definition     ?? null;
            $r->gates               = $ref->gates               ?? null;
            $r->symptom_weights     = $ref->symptom_weights     ?? null;
            $r->exposure_weights    = $ref->exposure_weights    ?? null;
            $r->sources             = $ref->sources             ?? null;
            return $r;
        });
    }

    private function loadSamples(int $screeningId)
    {
        try {
            // secondary_samples has NO deleted_at column (verified 2026-05-20).
            // Earlier whereNull('deleted_at') silently threw and was swallowed
            // by the catch — the SAMPLES panel was permanently empty.
            return DB::table('secondary_samples')
                ->where('secondary_screening_id', $screeningId)
                ->orderByDesc('id')->get();
        } catch (Throwable $e) {
            return collect();
        }
    }

    /**
     * Build intelligence-layer derivatives from the persisted case data.
     *
     * The mobile engine (Diseases_intelligence.js) only persists what reaches
     * the screening row (syndrome_classification, risk_level, suspected
     * diseases, exposures, symptoms). It does NOT persist its in-memory
     * top_diagnoses / global_flags / clinical_validation payload. We
     * synthesise a server-side equivalent here — best-effort, deterministic,
     * traceable — so the Case File can render the §6 intelligence surface
     * without re-running the JS engine.
     */
    private function buildIntelligenceDerivatives(object $alert, ?object $screening, $symptoms, $exposures, $suspected): array
    {
        $tier = is_string($alert->ihr_tier ?? null) ? $alert->ihr_tier : null;
        $tierOne = is_string($tier) && str_contains($tier, 'TIER_1');
        $tierTwo = is_string($tier) && str_contains($tier, 'TIER_2');

        $globalFlags = [];
        if ($tierOne) $globalFlags[] = ['code' => 'IHR_TIER_1',           'label' => 'IHR Tier 1 — always notifiable'];
        if ($tierTwo) $globalFlags[] = ['code' => 'IHR_TIER_2',           'label' => 'IHR Tier 2 — Annex 2 assessment required'];
        if ($screening && (int) ($screening->emergency_signs_present ?? 0) === 1) {
            $globalFlags[] = ['code' => 'EMERGENCY_SIGNS', 'label' => 'Emergency signs present (triage emergency)'];
        }
        if ($screening && strtoupper((string) ($screening->triage_category ?? '')) === 'EMERGENCY') {
            $globalFlags[] = ['code' => 'TRIAGE_EMERGENCY', 'label' => 'Triage category: EMERGENCY'];
        }

        $hallmarkSyndromes = ['VHF','HEMORRHAGIC','AFP','CHOLERA','RABIES','BIOTERROR','VESICULOPUSTULAR_RASH','MENINGITIS','SARI'];
        $sc = strtoupper((string) ($screening?->syndrome_classification ?? ''));
        foreach ($hallmarkSyndromes as $h) {
            if (str_contains($sc, $h)) $globalFlags[] = ['code' => 'SYNDROME_' . $h, 'label' => 'Syndrome: ' . $h];
        }

        $clinicalWarnings = [];
        $vitalAlerts      = [];
        if ($screening) {
            $temp = (float) ($screening->temperature_value ?? 0);
            $unit = strtoupper((string) ($screening->temperature_unit ?? 'C'));
            $tempC = $unit === 'F' ? ($temp - 32) * 5 / 9 : $temp;
            if ($tempC >= 38.5) $vitalAlerts[] = ['kind' => 'TEMP_HIGH', 'label' => sprintf('Fever %.1f °C', $tempC), 'severity' => 'WARN'];
            if ($tempC >= 40)   $vitalAlerts[] = ['kind' => 'HYPERPYREXIA', 'label' => sprintf('Hyperpyrexia %.1f °C', $tempC), 'severity' => 'CRITICAL'];

            $pulse = (int) ($screening->pulse_rate ?? 0);
            if ($pulse > 120) $vitalAlerts[] = ['kind' => 'TACHYCARDIA', 'label' => "Tachycardia {$pulse} bpm", 'severity' => 'WARN'];
            if ($pulse > 0 && $pulse < 50) $vitalAlerts[] = ['kind' => 'BRADYCARDIA', 'label' => "Bradycardia {$pulse} bpm", 'severity' => 'WARN'];

            $rr = (int) ($screening->respiratory_rate ?? 0);
            if ($rr > 30) $vitalAlerts[] = ['kind' => 'TACHYPNOEA', 'label' => "Tachypnoea {$rr}/min", 'severity' => 'WARN'];

            $sp = (int) ($screening->oxygen_saturation ?? 0);
            if ($sp > 0 && $sp < 92) $vitalAlerts[] = ['kind' => 'HYPOXIA', 'label' => "Hypoxia SpO₂ {$sp}%", 'severity' => 'CRITICAL'];

            $sysBp = (int) ($screening->bp_systolic ?? 0);
            if ($sysBp > 0 && $sysBp < 90) $vitalAlerts[] = ['kind' => 'HYPOTENSION', 'label' => "Hypotension {$sysBp}/{$screening->bp_diastolic}", 'severity' => 'CRITICAL'];
        }

        $highRiskExposureCount = $exposures->where('is_high_risk', 1)->where('response', 'YES')->count();
        if ($highRiskExposureCount > 0) {
            $clinicalWarnings[] = "{$highRiskExposureCount} high-risk exposure(s) confirmed YES";
        }
        $unknownExposures = $exposures->where('response', 'UNKNOWN')->count();
        if ($unknownExposures > 0) {
            $clinicalWarnings[] = "{$unknownExposures} exposure question(s) UNKNOWN — recontact recommended";
        }

        $topDiagnoses = $suspected->take(5)->map(function ($d) {
            $conf = (float) ($d->confidence ?? 0);
            return [
                'disease_code'   => $d->disease_code,
                'display_name'   => $d->display_name ?? $d->disease_code,
                'rank'           => (int) ($d->rank_order ?? 0),
                'confidence'     => round($conf, 4),
                'confidence_pct' => max(0, min(100, (int) round($conf * 100))),
                'confidence_band' => $this->confidenceBand($conf),
                'ihr_tier'       => $this->ihrTierLabel($d->ihr_tier ?? null),
                'who_syndrome'   => $d->who_syndrome,
                'reasoning'      => $d->reasoning,
                'is_officer_override' => is_string($d->reasoning ?? null) && str_contains((string) $d->reasoning, 'OFFICER'),
                'sources'        => $this->jsonOrNull($d->sources ?? null),
                'case_definition'=> $this->jsonOrNull($d->case_definition ?? null),
            ];
        })->values()->all();

        // final_disposition is a tight enum (RELEASED/DELAYED/QUARANTINED/ISOLATED/REFERRED/
        // TRANSFERRED/DENIED_BOARDING/OTHER) and CANNOT equal 'NON_CASE'.
        // screening_outcome is varchar(40) and is where the engine writes 'NON_CASE'.
        $isNonCase = strtoupper((string) ($screening?->screening_outcome ?? '')) === 'NON_CASE';

        $whoCriteriaMet = [];
        if ($sc !== '') $whoCriteriaMet[] = 'Syndrome classified: ' . $sc;
        if ($vitalAlerts) $whoCriteriaMet[] = count($vitalAlerts) . ' vital threshold(s) breached';
        if ($highRiskExposureCount > 0) $whoCriteriaMet[] = 'High-risk exposure(s) present';

        return [
            'global_flags'        => $globalFlags,
            'clinical_validation' => [
                'vital_alerts'           => $vitalAlerts,
                'critical_flags'         => array_values(array_filter($vitalAlerts, fn ($v) => ($v['severity'] ?? '') === 'CRITICAL')),
                'clinical_warnings'      => $clinicalWarnings,
                'needs_emergency_triage' => count(array_filter($vitalAlerts, fn ($v) => ($v['severity'] ?? '') === 'CRITICAL')) > 0,
            ],
            'top_diagnoses'      => $topDiagnoses,
            'syndrome'           => [
                'syndrome'        => $sc,
                'reasoning'       => $screening?->officer_notes ?? null,
                'who_criteria_met'=> $whoCriteriaMet,
            ],
            'non_case'           => [
                'isNonCase'   => $isNonCase,
                'reasons'     => $isNonCase ? ['Officer dispositioned this case as NON_CASE'] : [],
            ],
            'ihr_risk'           => [
                'risk_level'           => (string) ($alert->risk_level ?? ''),
                'routing_level'        => (string) ($alert->routed_to_level ?? ''),
                'ihr_alert_required'   => $tierOne || $tierTwo,
                'ihr_tier'             => $this->ihrTierLabel($tier),
            ],
            'exposure_summary' => [
                'yes_count'      => $exposures->where('response', 'YES')->count(),
                'no_count'       => $exposures->where('response', 'NO')->count(),
                'unknown_count'  => $exposures->where('response', 'UNKNOWN')->count(),
                'high_risk_yes'  => $highRiskExposureCount,
            ],
            'symptom_summary' => [
                'present_count' => $symptoms->where('is_present', 1)->count(),
                'absent_count'  => $symptoms->where('explicit_absent', 1)->count(),
                'red_flag_count' => $symptoms->where('is_present', 1)->where('is_red_flag', 1)->count(),
                'hallmark_count' => $symptoms->where('is_present', 1)->where('is_hallmark', 1)->count(),
            ],
        ];
    }

    private function buildVitalsChart(?object $screening): array
    {
        if (! $screening) return ['ranges' => [], 'values' => []];
        $temp = (float) ($screening->temperature_value ?? 0);
        $unit = strtoupper((string) ($screening->temperature_unit ?? 'C'));
        $tempC = $unit === 'F' ? ($temp - 32) * 5 / 9 : $temp;
        return [
            'values' => [
                ['key' => 'temp_c',  'label' => 'Temperature (°C)', 'value' => round($tempC, 1), 'unit' => '°C', 'normal_min' => 36.1, 'normal_max' => 38.0],
                ['key' => 'pulse',   'label' => 'Pulse (bpm)',      'value' => (int) ($screening->pulse_rate ?? 0), 'unit' => 'bpm', 'normal_min' => 60,  'normal_max' => 100],
                ['key' => 'rr',      'label' => 'Resp rate /min',   'value' => (int) ($screening->respiratory_rate ?? 0), 'unit' => '/min', 'normal_min' => 12, 'normal_max' => 20],
                ['key' => 'sys',     'label' => 'BP systolic',      'value' => (int) ($screening->bp_systolic ?? 0), 'unit' => 'mmHg', 'normal_min' => 90, 'normal_max' => 140],
                ['key' => 'dia',     'label' => 'BP diastolic',     'value' => (int) ($screening->bp_diastolic ?? 0), 'unit' => 'mmHg', 'normal_min' => 60, 'normal_max' => 90],
                ['key' => 'spo2',    'label' => 'SpO₂',              'value' => (int) ($screening->oxygen_saturation ?? 0), 'unit' => '%', 'normal_min' => 95, 'normal_max' => 100],
            ],
        ];
    }

    private function confidenceBand(float $conf): string
    {
        if ($conf >= 0.75) return 'HIGH';
        if ($conf >= 0.50) return 'MODERATE';
        if ($conf >= 0.25) return 'LOW';
        return 'TRACE';
    }

    /**
     * Normalise IHR tier to the long-form string used everywhere downstream.
     *
     * Accepts int (ref_diseases.ihr_tier = 1|2|3) OR string (alerts.ihr_tier
     * = varchar holding 'TIER_1_ALWAYS_NOTIFIABLE' etc.). Previous `?string`
     * type hint crashed with a TypeError when called from $d->ihr_tier
     * (decimal/int from ref_diseases). All callers protected; output is
     * always ?string for the downstream JSON encoder.
     *
     * @param int|string|null $raw
     */
    private function ihrTierLabel($raw): ?string
    {
        if ($raw === null || $raw === '') return null;
        $r = (string) $raw;
        if (str_contains($r, 'TIER_1') || $r === '1') return 'TIER_1_ALWAYS_NOTIFIABLE';
        if (str_contains($r, 'TIER_2') || $r === '2') return 'TIER_2_ANNEX2';
        if (str_contains($r, 'TIER_3') || $r === '3') return 'TIER_3_ROUTINE';
        return $r;
    }

    private function jsonOrNull($raw)
    {
        if ($raw === null || $raw === '') return null;
        if (is_array($raw)) return $raw;
        $d = json_decode((string) $raw, true);
        return is_array($d) ? $d : null;
    }

    private function castAlert(object $a): array
    {
        $base = array_merge((array) $a, [
            'age_minutes' => (int) Carbon::parse($a->created_at)->diffInMinutes(Carbon::now()),
        ]);

        $topDisease    = null;
        $travellerName = null;
        if (!empty($a->secondary_screening_id)) {
            try {
                $topDisease = (string) DB::table('secondary_suspected_diseases')
                    ->where('secondary_screening_id', $a->secondary_screening_id)
                    ->where('rank_order', 1)
                    ->value('disease_code') ?: null;

                $sc = DB::table('secondary_screenings')
                    ->where('id', $a->secondary_screening_id)
                    ->first(['traveler_full_name', 'traveler_initials', 'traveler_anonymous_code']);
                if ($sc) {
                    $travellerName = trim((string) ($sc->traveler_full_name ?: ''));
                    if ($travellerName === '') $travellerName = trim((string) ($sc->traveler_initials ?: ''));
                    if ($travellerName === '') $travellerName = trim((string) ($sc->traveler_anonymous_code ?: ''));
                    if ($travellerName === '') $travellerName = null;
                }
            } catch (Throwable) {
                // Non-fatal — fall back to "Unnamed traveller (Under review)".
            }
        }

        return HumanLabels::wrapAlert($base, $topDisease, $travellerName);
    }

    /**
     * Builds the "What would you like to see?" section summary.
     *
     * Each section reports:
     *   code        — internal section key
     *   label       — plain-English question
     *   hint        — one-line explainer
     *   status      — 'available' | 'partial' | 'missing'
     *   status_label — 'Available' / 'Some details missing' / 'Not yet recorded'
     *   icon        — lucide name
     *   count       — relevant count (when applicable)
     *
     * @param iterable<object> $symptoms
     * @param iterable<object> $exposures
     * @param iterable<object> $travel
     * @param iterable<object> $suspected
     * @param iterable<object> $samples
     * @param iterable<object> $followups
     * @param iterable<object> $collaborators
     * @param iterable<object> $handoffs
     * @param iterable<object> $timeline
     * @param iterable<object> $comments
     * @param iterable<object> $dispatch
     * @return array<int,array<string,mixed>>
     */
    private function buildSections(
        ?object $screening,
        $symptoms, $exposures, $travel, $suspected, $samples,
        $followups, $collaborators, $handoffs, $timeline, $comments, $dispatch
    ): array {
        $patientFields = [
            'traveler_full_name', 'traveler_age_years', 'traveler_gender',
            'traveler_nationality_country_code', 'traveler_occupation',
            'residence_address_text', 'phone_number', 'email',
        ];
        $travelFields = [
            'travel_direction', 'arrival_datetime', 'embarkation_port',
            'conveyance_type', 'conveyance_id', 'purpose_of_travel', 'length_of_stay',
        ];
        $clinicalFields = [
            'temperature_value', 'pulse_rate', 'respiratory_rate',
            'oxygen_saturation', 'bp_systolic', 'general_appearance',
            'triage_category', 'syndrome_classification',
        ];

        $sym  = $this->collect($symptoms)->count();
        $exp  = $this->collect($exposures)->count();
        $trav = $this->collect($travel)->count();
        $susp = $this->collect($suspected)->count();
        $samp = $this->collect($samples)->count();
        $fu   = $this->collect($followups);
        $fuOpen = $fu->filter(fn ($f) => !in_array($f->status ?? '', ['COMPLETED', 'NOT_APPLICABLE'], true))->count();
        $col  = $this->collect($collaborators)->count();
        $ho   = $this->collect($handoffs)->count();
        $tl   = $this->collect($timeline)->count();
        $com  = $this->collect($comments)->count();
        $disp = $this->collect($dispatch);
        $sent = $disp->where('status', 'SENT')->count();

        return [
            $this->fieldSection('PATIENT', 'Who is the patient?', 'Name, age, where they live, how to reach them.', 'user', $screening, $patientFields),
            $this->fieldSection('TRAVEL', 'When and where did they travel?', 'Direction of travel, dates, vehicle, countries traversed.', 'plane', $screening, $travelFields, $trav, 'countries traversed'),
            $this->fieldSection('CLINICAL', 'What did the screening find?', 'Vitals, general appearance, triage, syndrome.', 'stethoscope', $screening, $clinicalFields),
            $this->countSection('SYMPTOMS', 'What symptoms were recorded?', 'Each sign and symptom captured at the screening.', 'activity', $sym, 'symptom(s) recorded'),
            $this->countSection('EXPOSURES', 'What exposures were noted?', 'Possible exposures the patient mentioned.', 'biohazard', $exp, 'exposure(s) recorded'),
            $this->countSection('SUSPECTED', 'What might this be?', 'The diseases that match what was found, ranked.', 'flask-conical', $susp, 'suspected illness(es)'),
            $this->countSection('SAMPLES', 'What samples were collected?', 'Lab samples collected and where they are now.', 'test-tube', $samp, 'sample(s) collected'),
            $this->countSection('STEPS', 'What steps are still to do?', 'The actions that still need attention before we can close.', 'list-checks', $fuOpen, 'step(s) still open'),
            $this->countSection('PEOPLE', 'Who is involved?', 'Teammates, handovers, and other people on this case.', 'users', $col + $ho, 'people on this case'),
            $this->countSection('NOTIFICATIONS', 'Who has been told?', 'Emails sent so far and which ones got through.', 'mail', $sent, 'notification(s) sent'),
            $this->countSection('NOTES', 'What has been said about this case?', 'Notes and attached documents.', 'message-square', $com, 'note(s) written'),
            $this->countSection('TIMELINE', 'What has happened in this case?', 'Every action, in order, from start to now.', 'history', $tl, 'event(s) recorded'),
        ];
    }

    /**
     * Section based on whether specific fields are present on a single record.
     *
     * @param string[] $fields
     */
    private function fieldSection(string $code, string $label, string $hint, string $icon, ?object $rec, array $fields, int $extra = 0, string $extraNoun = ''): array
    {
        if (!$rec) {
            return $this->finishSection($code, $label, $hint, $icon, 'missing', 0, count($fields), 'Not yet recorded', '');
        }
        $present = 0;
        foreach ($fields as $f) {
            $v = $rec->{$f} ?? null;
            if ($v !== null && $v !== '' && $v !== 0 && $v !== '0') $present++;
        }
        $total  = count($fields);
        $status = $present === 0 ? 'missing' : ($present === $total ? 'available' : 'partial');
        $label_extra = $extraNoun !== '' && $extra > 0 ? " · {$extra} {$extraNoun}" : '';

        return $this->finishSection(
            $code, $label, $hint, $icon, $status,
            $present, $total,
            match ($status) {
                'available' => 'Available',
                'partial'   => 'Some details missing',
                default     => 'Not yet recorded',
            },
            "{$present} of {$total} details on file{$label_extra}"
        );
    }

    /**
     * Section based on how many child rows exist (followups, comments, etc.).
     */
    private function countSection(string $code, string $label, string $hint, string $icon, int $count, string $unit): array
    {
        $status = $count > 0 ? 'available' : 'missing';
        $label_status = $count > 0 ? 'Available' : 'Not yet recorded';

        return $this->finishSection(
            $code, $label, $hint, $icon, $status,
            $count, max($count, 1),
            $label_status,
            $count > 0 ? "{$count} {$unit}" : "Nothing recorded yet"
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function finishSection(string $code, string $label, string $hint, string $icon, string $status, int $present, int $total, string $statusLabel, string $countLabel): array
    {
        return [
            'code'         => $code,
            'label'        => $label,
            'hint'         => $hint,
            'icon'         => $icon,
            'status'       => $status,
            'status_label' => $statusLabel,
            'count_label'  => $countLabel,
            'present'      => $present,
            'total'        => $total,
        ];
    }

    private function collect($it)
    {
        if ($it instanceof \Illuminate\Support\Collection) return $it;
        if (is_array($it)) return collect($it);
        if (is_iterable($it)) return collect(iterator_to_array($it));
        return collect([]);
    }

    /**
     * Builds the closure-summary block for the case-file view.
     *
     * Returns null for an open case. For a closed case, includes:
     *   is_closed, status, close_category {code,label,help}, close_note,
     *   merged_into_alert_id, closed_at, closed_at_human, closed_by_name,
     *   closed_by_role, override_reason (if master close), wizard_decisions
     *   (every {step, option, who, when, reason, evidence}), closure_events.
     *
     * @param iterable<object> $followups
     * @param iterable<object> $timeline
     * @return array<string,mixed>|null
     */
    private function buildClosure(object $alert, $followups, $timeline): ?array
    {
        if (($alert->status ?? '') !== 'CLOSED') {
            return null;
        }

        // Closure event(s) — match the codes canonical writers actually emit.
        // Canonical AlertsController::close writes 'CLOSED'; the admin override
        // path writes 'CLOSURE_OVERRIDE_USED' alongside. Legacy ALERT_CLOSED*
        // codes are kept in the union for back-compat with any pre-refactor rows.
        $closureEvents = $this->collect($timeline)
            ->filter(fn ($e) => in_array((string) $e->event_code, [
                'CLOSED', 'CLOSURE_OVERRIDE_USED',
                'ALERT_CLOSED', 'ALERT_CLOSED_FALSE_ALARM', 'ALERT_MASTER_CLOSED',
            ], true))
            ->values();

        // Pick the most recent CLOSED (or override) as the authoritative close.
        // Sort newest-first so we get the actor of the LATEST close in the case
        // a reopen → reclose happened.
        $closureEvents = $closureEvents->sortByDesc(
            fn ($e) => (string) ($e->created_at ?? '')
        )->values();

        $primaryClose = $closureEvents->first();
        $closedByName = $primaryClose?->actor_name ?: null;
        $closedByRole = $primaryClose?->actor_role ?: null;
        $closeMode    = match ((string) ($primaryClose?->event_code ?? 'CLOSED')) {
            'ALERT_MASTER_CLOSED'      => 'on_behalf_of_team',
            'ALERT_CLOSED_FALSE_ALARM' => 'false_alarm',
            'CLOSURE_OVERRIDE_USED'    => 'override',
            default                    => 'normal',
        };

        $overrideReason = null;
        if ($primaryClose?->payload_json) {
            try {
                $payload = json_decode((string) $primaryClose->payload_json, true);
                if (is_array($payload)) {
                    $overrideReason = $payload['override_reason'] ?? $payload['reason'] ?? null;
                }
            } catch (Throwable) {
                $overrideReason = null;
            }
        }

        // Wizard decision history (chronological).
        $wizardDecisions = [];
        try {
            $state = DB::table('alert_wizard_state')
                ->where('alert_id', $alert->id)
                ->whereNull('deleted_at')
                ->first(['decisions']);
            if ($state && $state->decisions) {
                $list = json_decode((string) $state->decisions, true);
                if (is_array($list)) {
                    foreach ($list as $d) {
                        $stepCode   = (string) ($d['step']   ?? '');
                        $optionCode = (string) ($d['option'] ?? '');
                        $action     = HumanLabels::action($stepCode);
                        $optionLabel = match ($optionCode) {
                            'YES_DONE'                => 'Marked done',
                            'IN_PROGRESS'             => 'Marked in progress',
                            'NOT_APPLICABLE'          => 'Marked not applicable',
                            'NEED_HELP'               => 'Asked for help',
                            'NOT_APPLICABLE_FALSE_ALARM' => 'Swept as part of false-alarm closure',
                            'MASTER_COMPLETE_BY_ADMIN' => 'Marked done by admin during master close',
                            'FALSE_ALARM'             => 'Closed as a false alarm',
                            'MASTER_CLOSE'            => 'Closed on behalf of the team',
                            default                   => HumanLabels::prettify($optionCode),
                        };
                        $wizardDecisions[] = [
                            'step_code'   => $stepCode,
                            'step_title'  => $action['title'] ?: HumanLabels::prettify($stepCode),
                            'option_code' => $optionCode,
                            'option_label'=> $optionLabel,
                            'reason'      => $d['extra']['reason'] ?? null,
                            'evidence'    => $d['extra']['evidence_ref'] ?? null,
                            'note'        => $d['extra']['note'] ?? null,
                            'when'        => $d['ts'] ?? null,
                            'when_human'  => isset($d['ts']) ? HumanLabels::dueHuman((string) $d['ts']) : null,
                            'actor_id'    => $d['actor'] ?? null,
                        ];
                    }
                }
            }
        } catch (Throwable) {
            // Non-fatal — closure summary still renders without decision history.
        }

        // Followup summary at closure: how many done vs. not applicable.
        $fu = $this->collect($followups);

        // WHO-aligned outcome (auto-recorded at every close path).
        $outcome = null;
        try {
            $outcomeRow = DB::table('alert_case_outcomes')
                ->where('alert_id', $alert->id)
                ->whereNull('deleted_at')
                ->first();
            if ($outcomeRow) {
                $outcome = [
                    'case_classification'      => (string) $outcomeRow->case_classification,
                    'case_classification_label'=> $this->classificationLabel((string) $outcomeRow->case_classification),
                    'lab_status'               => $outcomeRow->lab_status ? (string) $outcomeRow->lab_status : null,
                    'lab_status_label'         => $outcomeRow->lab_status ? $this->labStatusLabel((string) $outcomeRow->lab_status) : null,
                    'lab_disease_code'         => $outcomeRow->lab_disease_code ? (string) $outcomeRow->lab_disease_code : null,
                    'lab_disease'              => $outcomeRow->lab_disease_code ? HumanLabels::disease((string) $outcomeRow->lab_disease_code) : null,
                    'clinical_outcome'         => $outcomeRow->clinical_outcome ? (string) $outcomeRow->clinical_outcome : null,
                    'clinical_outcome_label'   => $outcomeRow->clinical_outcome ? $this->clinicalOutcomeLabel((string) $outcomeRow->clinical_outcome) : null,
                    'ph_action'                => $outcomeRow->ph_action ? (string) $outcomeRow->ph_action : null,
                    'ph_action_label'          => $outcomeRow->ph_action ? $this->phActionLabel((string) $outcomeRow->ph_action) : null,
                    'outbreak_status'          => $outcomeRow->outbreak_status ? (string) $outcomeRow->outbreak_status : 'NONE',
                    'ihr_notified'             => (bool) $outcomeRow->ihr_notified,
                    'ihr_notified_at'          => $outcomeRow->ihr_notified_at,
                    'recorded_at'              => $outcomeRow->recorded_at,
                    'source'                   => (string) $outcomeRow->source,
                ];
            }
        } catch (Throwable) {
            $outcome = null;
        }

        return [
            'is_closed'          => true,
            'mode'               => $closeMode,
            'close_category'     => HumanLabels::closeCategory((string) ($alert->close_category ?? 'RESOLVED')),
            'close_note'         => (string) ($alert->close_note ?? ''),
            'merged_into_alert_id' => $alert->merged_into_alert_id ? (int) $alert->merged_into_alert_id : null,
            'closed_at'          => $alert->closed_at,
            'closed_at_human'    => $alert->closed_at ? HumanLabels::dueHuman((string) $alert->closed_at) : null,
            'closed_by_name'     => $closedByName,
            'closed_by_role'     => $closedByRole ? HumanLabels::prettify((string) $closedByRole) : null,
            'override_reason'    => $overrideReason ? (string) $overrideReason : null,
            'wizard_decisions'   => $wizardDecisions,
            'who_outcome'        => $outcome,
            'followup_summary'   => [
                'completed_count'      => $fu->where('status', 'COMPLETED')->count(),
                'not_applicable_count' => $fu->where('status', 'NOT_APPLICABLE')->count(),
                'total_count'          => $fu->count(),
            ],
            'closure_events'     => $closureEvents->map(fn ($e) => [
                'when_human' => HumanLabels::dueHuman((string) $e->created_at),
                'actor_name' => $e->actor_name,
                'summary'    => (string) $e->summary,
            ])->values()->all(),
        ];
    }

    private function classificationLabel(string $code): string
    {
        return match ($code) {
            'SUSPECTED'        => 'Suspected case',
            'PROBABLE'         => 'Probable case',
            'CONFIRMED'        => 'Confirmed by laboratory',
            'DISCARDED'        => 'Discarded — not a case',
            'LOST_TO_FOLLOWUP' => 'Lost to follow-up',
            default            => 'Unknown',
        };
    }

    private function labStatusLabel(string $code): string
    {
        return match ($code) {
            'POSITIVE'             => 'Positive — confirmed',
            'NEGATIVE'             => 'Negative — ruled out',
            'INCONCLUSIVE'         => 'Inconclusive',
            'INSUFFICIENT_SAMPLE'  => 'Sample was not enough',
            'PENDING'              => 'Result pending',
            'NOT_TESTED'           => 'Not tested',
            default                => HumanLabels::prettify($code),
        };
    }

    private function clinicalOutcomeLabel(string $code): string
    {
        return match ($code) {
            'RECOVERED'        => 'Recovered',
            'CONVALESCING'     => 'Recovering',
            'DECEASED'         => 'Passed away',
            'LOST_TO_FOLLOWUP' => 'Lost to follow-up',
            'TRANSFERRED'      => 'Transferred onward',
            default            => 'Unknown',
        };
    }

    private function phActionLabel(string $code): string
    {
        return match ($code) {
            'STANDARD_SURVEILLANCE'   => 'Standard surveillance only',
            'ENHANCED_SURVEILLANCE'   => 'Enhanced surveillance in place',
            'OUTBREAK_INVESTIGATION'  => 'Outbreak investigation under way',
            'OUTBREAK_RESPONSE'       => 'Outbreak response activated',
            'IHR_NOTIFIED'            => 'International partners notified',
            default                   => HumanLabels::prettify($code),
        };
    }

    private function castScreening(object $s): array
    {
        return array_merge((array) $s, [
            'temperature_c' => $this->celsius($s),
            'mews' => $this->modifiedEws($s),
        ]);
    }

    private function celsius(object $s): ?float
    {
        $v = (float) ($s->temperature_value ?? 0);
        if ($v <= 0) return null;
        return strtoupper((string) ($s->temperature_unit ?? 'C')) === 'F'
            ? round(($v - 32) * 5 / 9, 1) : round($v, 1);
    }

    /**
     * Single-row Modified Early Warning Score (MEWS) — a classic vital-signs
     * triage gestalt. Used by the Clinical tab as a sparkline overlay.
     */
    private function modifiedEws(object $s): int
    {
        $score = 0;
        $temp = $this->celsius($s);
        if ($temp !== null) {
            if ($temp < 35)              $score += 2;
            elseif ($temp >= 38.5)       $score += 2;
            elseif ($temp >= 38)         $score += 1;
        }
        $pulse = (int) ($s->pulse_rate ?? 0);
        if ($pulse > 0) {
            if ($pulse < 40 || $pulse > 130) $score += 3;
            elseif ($pulse < 50 || $pulse > 110) $score += 2;
            elseif ($pulse < 60 || $pulse > 100) $score += 1;
        }
        $rr = (int) ($s->respiratory_rate ?? 0);
        if ($rr > 0) {
            if ($rr < 9 || $rr > 30) $score += 3;
            elseif ($rr > 20)        $score += 2;
            elseif ($rr > 14)        $score += 1;
        }
        $sys = (int) ($s->bp_systolic ?? 0);
        if ($sys > 0) {
            if ($sys < 70 || $sys > 200) $score += 3;
            elseif ($sys < 80 || $sys > 180) $score += 2;
            elseif ($sys < 100) $score += 1;
        }
        $sp = (int) ($s->oxygen_saturation ?? 0);
        if ($sp > 0 && $sp < 90) $score += 3;
        elseif ($sp > 0 && $sp < 95) $score += 1;
        return $score;
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if(!empty($meta)){$b['meta']=$meta;} return response()->json($b); }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    { return response()->json(['success'=>false,'message'=>$message,'error'=>$detail], $status); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Alerts\\CaseFile][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
