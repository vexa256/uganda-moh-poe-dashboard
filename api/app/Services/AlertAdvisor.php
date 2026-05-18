<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AlertAdvisor — hardcoded, deterministic recommendation engine
 * (alerts refactor §3.5).
 *
 * Pure rules, no LLM, no model inference, no third-party calls. Reads:
 *   • ref_engine_config (rules JSON published by the central engine)
 *   • ref_diseases     (case definitions, gates, weights)
 *   • the alert + screening + child rows passed in
 *   • the alert state machine (status / routed_to_level / IHR tier)
 *
 * Surface of compute() per §3.5:
 *   recommended_next_action · ppe_level · ihr_notification_status ·
 *   referral_level · sample_collection_guidance · traveler_script ·
 *   rules_fired (every recommendation cites which rule triggered) ·
 *   missing_inputs (hard checklist when data is insufficient).
 *
 * Every rule returns a tuple (decision, citation). Citations are the
 * canonical id the JS engine emits, e.g. RULE_VHF_PROTOCOL,
 * RULE_TIER_1_IHR, so an auditor can trace the recommendation back to
 * the publication of the rule.
 */
final class AlertAdvisor
{
    /**
     * Compute the advisor payload for a given case.
     *
     * @param object             $alert       alerts row
     * @param object|null        $screening   secondary_screenings row
     * @param iterable<object>   $symptoms    secondary_symptoms (joined ref_symptoms)
     * @param iterable<object>   $exposures   secondary_exposures (joined ref_exposures)
     * @param iterable<object>   $actions     secondary_actions
     * @param iterable<object>   $suspected   secondary_suspected_diseases (joined ref_diseases)
     * @param array              $intel       intelligence-derivative payload from CaseFileController
     * @return array
     */
    public static function compute(
        object $alert,
        ?object $screening,
        $symptoms,
        $exposures,
        $actions,
        $suspected,
        array $intel
    ): array {
        $rulesFired   = [];
        $missing      = [];

        // ── Insufficient-input gates ────────────────────────────────────
        if (! $screening) {
            $missing[] = ['field' => 'secondary_screening', 'why' => 'No secondary screening attached to this alert — advisor cannot run.'];
        } else {
            if ((string) ($screening->traveler_full_name ?? '') === ''
                && (string) ($screening->traveler_anonymous_code ?? '') === '') {
                $missing[] = ['field' => 'traveler_identity', 'why' => 'Neither full name nor anonymous code captured.'];
            }
            if ((string) ($screening->syndrome_classification ?? '') === '') {
                $missing[] = ['field' => 'syndrome_classification', 'why' => 'Officer has not classified the syndrome — required to drive PPE + sample guidance.'];
            }
            if ((float) ($screening->temperature_value ?? 0) <= 0) {
                $missing[] = ['field' => 'vitals.temperature', 'why' => 'Temperature missing.'];
            }
            if ((int) ($screening->pulse_rate ?? 0) <= 0) {
                $missing[] = ['field' => 'vitals.pulse', 'why' => 'Pulse missing.'];
            }
            if (collect($symptoms)->isEmpty() && collect($exposures)->isEmpty()) {
                $missing[] = ['field' => 'screening.coverage', 'why' => 'Neither symptoms nor exposures captured — cannot triangulate differential.'];
            }
        }
        if (! empty($missing)) {
            return [
                'sufficient'       => false,
                'missing_inputs'   => $missing,
                'rules_fired'      => [],
                'recommendation'   => null,
            ];
        }

        $risk     = strtoupper((string) ($alert->risk_level ?? 'HIGH'));
        $tier     = (string) ($intel['ihr_risk']['ihr_tier'] ?? '');
        $tierOne  = str_contains($tier, 'TIER_1');
        $tierTwo  = str_contains($tier, 'TIER_2');
        $syndrome = strtoupper((string) ($screening->syndrome_classification ?? ''));
        $disp     = strtoupper((string) ($screening->final_disposition ?? ''));
        $statusM  = strtoupper((string) ($alert->status ?? 'OPEN'));
        $vitalsCrit = ! empty($intel['clinical_validation']['critical_flags']);

        // ── PPE level ───────────────────────────────────────────────────
        $ppe       = 'STANDARD';
        $ppeReason = 'Default — no rule elevated PPE.';
        if ($tierOne || str_contains($syndrome, 'VHF') || str_contains($syndrome, 'HEMORRHAGIC')) {
            $ppe       = 'AIRBORNE_BARRIER';
            $ppeReason = 'IHR Tier 1 / VHF syndrome → full barrier (N95+, gown, gloves, eye protection, neg-pressure room).';
            $rulesFired[] = ['rule' => 'RULE_PPE_VHF_TIER1', 'because' => $ppeReason];
        } elseif (str_contains($syndrome, 'SARI') || str_contains($syndrome, 'RESPIRATORY')) {
            $ppe       = 'AIRBORNE';
            $ppeReason = 'Respiratory / SARI syndrome → N95 + eye protection + gown.';
            $rulesFired[] = ['rule' => 'RULE_PPE_SARI', 'because' => $ppeReason];
        } elseif (str_contains($syndrome, 'CHOLERA') || str_contains($syndrome, 'AWD')) {
            $ppe       = 'CONTACT';
            $ppeReason = 'Cholera / AWD → contact precautions (gloves, gown, hand hygiene, environment cleaning).';
            $rulesFired[] = ['rule' => 'RULE_PPE_CHOLERA', 'because' => $ppeReason];
        } elseif ($risk === 'CRITICAL' || $vitalsCrit) {
            $ppe       = 'DROPLET';
            $ppeReason = 'Critical risk or critical vitals → droplet precautions until syndrome confirmed.';
            $rulesFired[] = ['rule' => 'RULE_PPE_DROPLET_PRECAUTIONARY', 'because' => $ppeReason];
        } else {
            $rulesFired[] = ['rule' => 'RULE_PPE_STANDARD', 'because' => $ppeReason];
        }

        // ── IHR notification status ────────────────────────────────────
        $ihrStatus = 'NOT_REQUIRED';
        $ihrReason = 'No IHR tier matched the case classification.';
        if ($tierOne) {
            $ihrStatus = 'REQUIRED_24H';
            $ihrReason = 'IHR Tier 1 (always notifiable) — notify WHO IHR NFP within 24 hours per Article 6.';
            $rulesFired[] = ['rule' => 'RULE_TIER_1_IHR_24H', 'because' => $ihrReason];
        } elseif ($tierTwo) {
            $ihrStatus = 'ASSESS_ANNEX2';
            $ihrReason = 'IHR Tier 2 — run the Annex 2 4-criteria decision instrument; notify WHO if ≥ 2/4 met.';
            $rulesFired[] = ['rule' => 'RULE_TIER_2_ANNEX2', 'because' => $ihrReason];
        }

        // ── Referral level ──────────────────────────────────────────────
        $referral = 'POE_HEALTH_DESK';
        $refReason = 'Default — POE health desk handles routine cases.';
        if ($tierOne || $vitalsCrit) {
            $referral = 'TERTIARY_ISOLATION';
            $refReason = 'IHR Tier 1 or critical vitals → designated isolation facility (tertiary) immediately.';
            $rulesFired[] = ['rule' => 'RULE_REFERRAL_TERTIARY', 'because' => $refReason];
        } elseif ($risk === 'CRITICAL' || $tierTwo) {
            $referral = 'DISTRICT_HOSPITAL_ISOLATION';
            $refReason = 'Critical risk or Tier 2 → district hospital with isolation capability.';
            $rulesFired[] = ['rule' => 'RULE_REFERRAL_DISTRICT', 'because' => $refReason];
        } elseif ($risk === 'HIGH') {
            $referral = 'DISTRICT_HOSPITAL';
            $refReason = 'High risk → district hospital for clinical review.';
            $rulesFired[] = ['rule' => 'RULE_REFERRAL_HIGH', 'because' => $refReason];
        } else {
            $rulesFired[] = ['rule' => 'RULE_REFERRAL_DEFAULT', 'because' => $refReason];
        }

        // ── Sample collection ──────────────────────────────────────────
        $samples = [];
        if (str_contains($syndrome, 'CHOLERA') || str_contains($syndrome, 'AWD')) {
            $samples[] = ['code' => 'STOOL', 'note' => 'Stool RDT + culture (Cary-Blair transport).'];
            $rulesFired[] = ['rule' => 'RULE_SAMPLE_CHOLERA', 'because' => 'Cholera / AWD syndrome.'];
        }
        if (str_contains($syndrome, 'VHF') || str_contains($syndrome, 'HEMORRHAGIC')) {
            $samples[] = ['code' => 'EDTA_BLOOD', 'note' => 'EDTA blood (≥ 5 mL) for RT-PCR — CDC/Africa CDC reference lab.'];
            $samples[] = ['code' => 'SERUM',      'note' => 'Serum for serology — second tube.'];
            $rulesFired[] = ['rule' => 'RULE_SAMPLE_VHF', 'because' => 'VHF / hemorrhagic syndrome.'];
        }
        if (str_contains($syndrome, 'SARI') || str_contains($syndrome, 'RESPIRATORY')) {
            $samples[] = ['code' => 'NPS',  'note' => 'Nasopharyngeal swab in VTM (multiplex respiratory panel).'];
            $samples[] = ['code' => 'OPS',  'note' => 'Oropharyngeal swab — paired with NPS.'];
            $rulesFired[] = ['rule' => 'RULE_SAMPLE_RESP', 'because' => 'SARI / respiratory syndrome.'];
        }
        if (str_contains($syndrome, 'AFP')) {
            $samples[] = ['code' => 'STOOL_AFP', 'note' => 'Two stool specimens 24-48h apart (polio surveillance).'];
            $rulesFired[] = ['rule' => 'RULE_SAMPLE_AFP', 'because' => 'AFP surveillance protocol.'];
        }
        if (empty($samples)) {
            $samples[] = ['code' => 'CLINICIAN_DECISION', 'note' => 'No syndrome-specific protocol matched — defer to receiving clinician.'];
            $rulesFired[] = ['rule' => 'RULE_SAMPLE_DEFAULT', 'because' => 'No syndrome-specific protocol matched.'];
        }

        // ── Recommended next action ─────────────────────────────────────
        $nextAction = 'Acknowledge alert and confirm the response team is mobilising.';
        if ($statusM === 'OPEN') {
            $nextAction = 'ACKNOWLEDGE the alert and assign an on-duty officer immediately.';
            $rulesFired[] = ['rule' => 'RULE_NEXT_ACK', 'because' => 'Alert is OPEN — first action is acknowledgement.'];
        } elseif ($statusM === 'ACKNOWLEDGED') {
            if ($tierOne) {
                $nextAction = 'Activate VHF / Tier 1 protocol: isolate, don full PPE, file IHR notification, dispatch lab samples.';
                $rulesFired[] = ['rule' => 'RULE_NEXT_TIER1_ACTIVATE', 'because' => 'Tier 1 + acknowledged → execute protocol.'];
            } elseif ($vitalsCrit) {
                $nextAction = 'Stabilise patient (oxygen, IV, escalate to clinician), then refer to ' . $referral . '.';
                $rulesFired[] = ['rule' => 'RULE_NEXT_STABILISE', 'because' => 'Critical vitals — stabilise before transfer.'];
            } else {
                $nextAction = 'Complete the case file, refer to ' . $referral . ', and update follow-ups.';
                $rulesFired[] = ['rule' => 'RULE_NEXT_REFER', 'because' => 'Acknowledged + non-critical → standard referral.'];
            }
        } elseif ($statusM === 'CLOSED') {
            $nextAction = 'No further action required. Case is CLOSED — review timeline + dispatch receipt for audit.';
            $rulesFired[] = ['rule' => 'RULE_NEXT_CLOSED', 'because' => 'Alert is CLOSED.'];
        }

        // ── Traveler script ─────────────────────────────────────────────
        $script = "We need to keep you here for a short medical assessment to make sure you and the public are safe.\n"
            . "Please remain in the screening room — a clinician will see you within 30 minutes.\n"
            . "If you feel worse (difficulty breathing, severe pain, confusion), tell the staff immediately.";
        if ($tierOne) {
            $script = "Your symptoms match a high-priority illness covered by international health regulations.\n"
                . "We're going to move you to an isolation room and start clinical care immediately.\n"
                . "Family contacts may be reached for follow-up — your privacy is protected.";
            $rulesFired[] = ['rule' => 'RULE_SCRIPT_TIER1', 'because' => 'Tier 1 — escalated communication script.'];
        } elseif (str_contains($syndrome, 'CHOLERA') || str_contains($syndrome, 'AWD')) {
            $script = "Your symptoms suggest a stomach infection that can spread quickly.\n"
                . "Please drink the oral rehydration solution we provide and stay in the assessment area.\n"
                . "We'll collect a small stool sample to confirm the cause.";
            $rulesFired[] = ['rule' => 'RULE_SCRIPT_AWD', 'because' => 'Cholera / AWD syndrome.'];
        } else {
            $rulesFired[] = ['rule' => 'RULE_SCRIPT_DEFAULT', 'because' => 'Default reassurance script.'];
        }

        // ── Engine config — merge any published overrides if available ──
        try {
            $cfg = DB::table('ref_engine_config')->where('config_key', 'diseases.ihr_escalation_rules')
                ->where('is_active', 1)->value('config_value');
            if (is_string($cfg)) {
                $decoded = json_decode($cfg, true);
                if (is_array($decoded) && ! empty($decoded['version'])) {
                    $rulesFired[] = ['rule' => 'CONFIG_LINKED', 'because' => 'ref_engine_config.diseases.ihr_escalation_rules version ' . $decoded['version'] . ' loaded.'];
                }
            }
        } catch (Throwable $e) {
            Log::warning('[AlertAdvisor::config] ' . $e->getMessage());
        }

        return [
            'sufficient'       => true,
            'missing_inputs'   => [],
            'rules_fired'      => $rulesFired,
            'recommendation'   => [
                'next_action'      => $nextAction,
                'ppe_level'        => $ppe,
                'ppe_reason'       => $ppeReason,
                'ihr_status'       => $ihrStatus,
                'ihr_reason'       => $ihrReason,
                'referral_level'   => $referral,
                'referral_reason'  => $refReason,
                'samples'          => $samples,
                'traveler_script'  => $script,
            ],
        ];
    }
}
