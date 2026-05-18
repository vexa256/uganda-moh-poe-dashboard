<?php

declare (strict_types = 1);

namespace App\Services;

use App\Mail\SentinelMail;
use App\Services\CaseContextBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * NotificationDispatcher
 *
 * Thin fire-and-forget helper used by controllers (AlertsController,
 * PrimaryScreeningController, AggregatedController, etc.) to trigger emails
 * WITHOUT the ceremony of going through the HTTP /notifications endpoints.
 *
 * Every public method catches all exceptions — a failed notification NEVER
 * fails the primary operation (create alert, create screening, etc.).
 *
 * DESIGN CONTRACT
 *   • Uses the same notification_templates + notification_log tables as the
 *     HTTP controller, so admin visibility is identical.
 *   • Uses the SAME {{token}} mustache render pipeline as the HTTP
 *     controller — templates work identically regardless of trigger path.
 *   • Level fan-out: for a DISTRICT alert, notifies contacts registered at
 *     DISTRICT, PHEOC and NATIONAL (the IHR escalation ladder). For a
 *     PHEOC alert, notifies PHEOC + NATIONAL. For NATIONAL, notifies
 *     NATIONAL + WHO. This is why the NATIONAL-level Uganda contacts
 *     receive alerts routed to any level.
 */
final class NotificationDispatcher
{
    /**
     * Resolve the canonical TO address for every executive alert email.
     * Resolution order: env(MAIL_PRIMARY_TO_ADDRESS) → config('country.admin_email').
     * The national lead is in TO; everybody else is in CC. BCC is never used.
     */
    public static function primaryToAddress(): string
    {
        // Resolution order: config('country.primary_to_address') — which is
        // populated from env(MAIL_PRIMARY_TO_ADDRESS) at config:cache time —
        // then config('country.admin_email') as a last-resort fallback.
        // env() is NOT consulted at runtime because Laravel discards env
        // values after config:cache.
        return (string) (config('country.primary_to_address')
            ?: config('country.admin_email')
            ?: 'admin@ug-poe.ecsahc.com');
    }

    /**
     * Per-template suppression windows in MINUTES. The same (template,
     * entity, contact) triple cannot send more than once inside this window.
     *
     * Cadence law (Intelligence Console §2.1) — non-negotiable:
     *   Every reminder fires AT MOST once per 24 hours per (recipient, case,
     *   reminder-type) tuple, until the case is resolved. Reminders are
     *   periodic, cron-driven dispatches that nudge a recipient about an
     *   unresolved case (FOLLOWUP_*, BREACH_717, RESPONDER_INFO_REQUEST,
     *   *_REMINDER). They are pinned to ≥ 1440 minutes here.
     *
     * One-shot dispatches (ALERT_CRITICAL, ALERT_HIGH, ALERT_CLOSED,
     * TIER1_ADVISORY, PHEIC_ADVISORY, ESCALATION, ANNEX2_HIT, ALERT_CASE_FILE)
     * fire on real-world state transitions, not on a clock. Their windows
     * exist purely to dedupe retry-storms from the queue worker. They are
     * deliberately short and are NOT subject to §2.1 — escalations beat the
     * dedup window by changing template_code.
     *
     * Digests (DAILY_REPORT, WEEKLY_REPORT, NATIONAL_INTELLIGENCE) are
     * scheduled summaries, not reminders, and are exempt from §2.1 per §2.8
     * of the brief. Their windows still prevent same-cycle double-fire.
     */
    private const SUPPRESSION_MINUTES = [
        // One-shot state-transition dispatches (NOT reminders) — keep short for retry dedup only.
        'ALERT_CRITICAL'   => 30,
        'ALERT_HIGH'       => 120,
        'ALERT_CASE_FILE'  => 360,
        'TIER1_ADVISORY'   => 30,
        'ANNEX2_HIT'       => 360,
        'PHEIC_ADVISORY'   => 60,
        'ALERT_CLOSED'     => 60,
        'ESCALATION'       => 30,

        // Reminders — minimum interval per (recipient, alert) tuple.
        // §2.1 mandate: operators reported inbox flood at 24h cadence.
        // Per executive directive (2026-05-17), breach re-pages are the
        // LEAST frequent re-page of all reminders — pushed to 14 days.
        'BREACH_717'       => 20160, // 14 days — SLA breach re-page (least frequent of all reminders)
        'BUNDLED_FOLLOWUP' => 10080, // 7 days — weekly action bundle (followups + reminders rollup)
        'FOLLOWUP_DUE'     => 10080, // 7 days — due-soon nudge (now bundled)
        'FOLLOWUP_OVERDUE' => 10080, // 7 days — overdue nudge (now bundled)
        'RESPONDER_INFO_REQUEST' => 10080, // 7 days

        // Digests — exempt from §2.1; window deduplicates same-cycle re-runs.
        'DAILY_REPORT'     => 60,
        'WEEKLY_REPORT'    => 60,
        'NATIONAL_INTELLIGENCE' => 60,
    ];
    private const DEFAULT_SUPPRESSION_MINUTES = 60;


    /**
     * Fire emails for a newly-created alert. Picks the right template
     * (Tier 1 → TIER1_ADVISORY, otherwise risk-based) and fans out across
     * the escalation ladder.
     *
     * @param object $alert      alerts row (freshly inserted)
     * @param int    $userId     who triggered (for notification_log.triggered_by)
     */
    public static function dispatchAlertCreated(object $alert, int $userId = 0): array
    {
        return static::safely(function () use ($alert, $userId) {
            $tierOne = is_string($alert->ihr_tier ?? null) && str_contains($alert->ihr_tier, 'TIER_1');
            $tierTwo = is_string($alert->ihr_tier ?? null) && str_contains($alert->ihr_tier, 'TIER_2');

            $templateCode = $tierOne
                ? 'TIER1_ADVISORY'
                : (($alert->risk_level ?? 'HIGH') === 'CRITICAL' ? 'ALERT_CRITICAL' : 'ALERT_HIGH');

            // Build the full decision-grade payload (demographics, vitals,
            // travel, symptoms, exposures, differential, disease intel, etc.)
            $vars = CaseContextBuilder::forAlert($alert);

            // Fan out across the IHR ladder (level + all levels above) — but
            // collapse the loop into ONE single mass email per executive
            // directive: TO=primary national lead, CC=every other resolved
            // contact + the national OPS_CC roster, no BCC.
            $levels = static::ladderFrom((string) ($alert->routed_to_level ?? 'DISTRICT'));
            $contacts = static::resolveContactsForAlert($alert, $levels);

            $sent = 0; $skipped = 0; $failed = 0;
            $blast = static::sendUnifiedBlast($contacts, $templateCode, $vars, $alert,
                'USER:' . $userId, 'ALERT', (int) $alert->id);
            $sent   += (int) ($blast['sent'] ?? 0);
            $failed += (int) ($blast['failed'] ?? 0);

            // Extra: if Tier 1 also send PHEIC advisory to NATIONAL + WHO — also
            // a single mass email targeting only the Tier-1 subset.
            if ($tierOne) {
                $pheicContacts = DB::table('poe_notification_contacts')
                    ->whereIn('level', ['NATIONAL', 'WHO'])
                    ->where('country_code', $alert->country_code)
                    ->where('is_active', 1)->whereNull('deleted_at')
                    ->where('receives_tier1', 1)
                    ->get();
                $pheicBlast = static::sendUnifiedBlast($pheicContacts, 'PHEIC_ADVISORY', $vars, $alert,
                    'USER:' . $userId, 'ALERT', (int) $alert->id);
                $sent   += (int) ($pheicBlast['sent'] ?? 0);
                $failed += (int) ($pheicBlast['failed'] ?? 0);
            }

            // §3.2 user-event fan-out — pages the assignee + team + originator
            // + management with an executive summary email so internal staff
            // are not blind to an alert landing in their jurisdiction.
            $riskLabel    = static::humaniseEnumValue(strtoupper((string) ($alert->risk_level ?? 'HIGH')));
            $routedLabel  = static::humaniseRouted((string) ($alert->routed_to_level ?? 'DISTRICT'));
            $poeName      = static::resolvePoeName((string) ($alert->poe_code ?? ''));
            $titlePolished= static::humaniseDiseaseOrCode((string) ($alert->alert_title ?? ''));
            $head   = $riskLabel . ' alert raised at ' . ($poeName !== '' ? $poeName : '—');
            $ctx    = 'Reference ' . (string) ($alert->alert_code ?? '') . ' — ' . $titlePolished
                . "\nRouting: " . $routedLabel
                . ($tierOne ? ' · IHR Tier 1 — always notifiable' : ($tierTwo ? ' · IHR Tier 2 — Annex 2 assessment' : ''))
                . "\nOpen the case file for clinical, exposure, and differential detail.";
            static::dispatchUserEvent($alert, 'ALERT_CREATED', $head, $ctx, $userId);

            // IHR-dispatched event: fire a second user-event when the alert
            // carries an IHR tier, so management receives a separate "IHR
            // notification obligation triggered" email. Idempotency window in
            // userEventRecentlyFired prevents double-send.
            if ($tierOne || $tierTwo) {
                $ihrHead = 'IHR notification obligation triggered (' . ($tierOne ? 'Tier 1' : 'Tier 2') . ')';
                $ihrCtx  = 'Alert ' . (string) ($alert->alert_code ?? '') . ' meets IHR ' . ($tierOne ? 'Tier 1 — always notifiable' : 'Tier 2 — Annex 2 assessment') . '.'
                    . "\nAction required: follow Article 6/9 notification to WHO through the IHR National Focal Point."
                    . "\nThe alert is routed to " . $routedLabel . '; escalate as required.';
                static::dispatchUserEvent($alert, 'ALERT_IHR_DISPATCHED', $ihrHead, $ihrCtx, $userId);
            }

            return ['template' => $templateCode, 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed];
        }, 'dispatchAlertCreated');
    }

    /**
     * Fire email when an alert is closed. Informational only — goes to the
     * same ladder that received the creation notification.
     */
    public static function dispatchAlertClosed(object $alert, int $userId, string $closedByName, string $closeReason): array
    {
        return static::safely(function () use ($alert, $userId, $closedByName, $closeReason) {

            // Look up the WHO-aligned outcome row (recorded on every close
            // path before this dispatcher fires) so the email body can carry
            // case + triage + outcome detail back to the original recipients.
            $outcomeOverrides = static::buildOutcomeOverrides((int) $alert->id);

            $vars = CaseContextBuilder::forAlert($alert, array_merge([
                'closed_by_name'     => $closedByName,
                'close_reason'       => mb_substr($closeReason, 0, 500),
                'close_reason_short' => mb_substr($closeReason, 0, 80),
            ], $outcomeOverrides));

            $levels = static::ladderFrom((string) ($alert->routed_to_level ?? 'DISTRICT'));
            $contacts = static::resolveContactsForAlert($alert, $levels);
            $blast = static::sendUnifiedBlast($contacts, 'ALERT_CLOSED', $vars, $alert,
                'USER:' . $userId, 'ALERT', (int) $alert->id);
            $sent    = (int) ($blast['sent'] ?? 0);
            $skipped = 0;
            $failed  = (int) ($blast['failed'] ?? 0);

            // §3.2 — duplicate the news to internal users (response team +
            // originator + management) as a rich closure summary. Includes a
            // human-readable case + triage + outcome digest so anyone reading
            // their inbox sees what happened without opening the console.
            $body  = 'The alert is now in the CLOSED state.';
            if ($closeReason !== '') $body .= "\nReason: " . mb_substr($closeReason, 0, 500);
            if (!empty($outcomeOverrides['outcome_summary_text'])) {
                $body .= "\n\n" . (string) $outcomeOverrides['outcome_summary_text'];
            }
            $caseTriage = static::buildCaseTriageDigest($alert);
            if ($caseTriage !== '') $body .= "\n\n" . $caseTriage;

            static::dispatchUserEvent(
                $alert, 'ALERT_CLOSED',
                'Alert closed by ' . $closedByName,
                $body,
                $userId,
            );
            return ['template' => 'ALERT_CLOSED', 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed];
        }, 'dispatchAlertClosed');
    }

    /**
     * Loads alert_case_outcomes for the alert and returns a flat array of
     * mustache-friendly variables the email template can inline. Every key
     * is safe to render as text (empty string when nothing was recorded).
     *
     * Variables exposed:
     *   outcome_classification_label, outcome_lab_label, outcome_clinical_label,
     *   outcome_ph_action_label, outcome_outbreak_label, outcome_ihr_text,
     *   outcome_lab_disease_name, outcome_summary_text (multi-line digest).
     *
     * @return array<string,string>
     */
    private static function buildOutcomeOverrides(int $alertId): array
    {
        $blank = [
            'outcome_classification_label' => '',
            'outcome_lab_label'            => '',
            'outcome_clinical_label'       => '',
            'outcome_ph_action_label'      => '',
            'outcome_outbreak_label'       => '',
            'outcome_ihr_text'             => '',
            'outcome_lab_disease_name'     => '',
            'outcome_summary_text'         => '',
        ];

        try {
            $row = DB::table('alert_case_outcomes')
                ->where('alert_id', $alertId)
                ->whereNull('deleted_at')
                ->first();
        } catch (Throwable) {
            return $blank;
        }
        if (!$row) return $blank;

        $cls = match ((string) $row->case_classification) {
            'SUSPECTED'        => 'Suspected case',
            'PROBABLE'         => 'Probable case',
            'CONFIRMED'        => 'Confirmed by laboratory',
            'DISCARDED'        => 'Discarded — not a case',
            'LOST_TO_FOLLOWUP' => 'Lost to follow-up',
            default            => 'Unknown',
        };
        $lab = $row->lab_status ? match ((string) $row->lab_status) {
            'POSITIVE'             => 'Lab positive — confirmed',
            'NEGATIVE'             => 'Lab negative — ruled out',
            'INCONCLUSIVE'         => 'Lab result inconclusive',
            'INSUFFICIENT_SAMPLE'  => 'Lab sample was insufficient',
            'PENDING'              => 'Lab result pending',
            'NOT_TESTED'           => 'Not tested',
            default                => (string) $row->lab_status,
        } : '';
        $clin = $row->clinical_outcome ? match ((string) $row->clinical_outcome) {
            'RECOVERED'        => 'Recovered',
            'CONVALESCING'     => 'Recovering',
            'DECEASED'         => 'Passed away',
            'LOST_TO_FOLLOWUP' => 'Lost to follow-up',
            'TRANSFERRED'      => 'Transferred onward',
            default            => 'Unknown',
        } : '';
        $ph = $row->ph_action ? match ((string) $row->ph_action) {
            'STANDARD_SURVEILLANCE'   => 'Standard surveillance only',
            'ENHANCED_SURVEILLANCE'   => 'Enhanced surveillance in place',
            'OUTBREAK_INVESTIGATION'  => 'Outbreak investigation under way',
            'OUTBREAK_RESPONSE'       => 'Outbreak response activated',
            'IHR_NOTIFIED'            => 'International partners notified',
            default                   => (string) $row->ph_action,
        } : '';

        $diseaseName = '';
        if (!empty($row->lab_disease_code)) {
            try {
                $diseaseName = (string) (DB::table('ref_diseases')
                    ->where('disease_code', $row->lab_disease_code)
                    ->value('display_name') ?: '');
            } catch (Throwable) { $diseaseName = ''; }
        }

        $outbreak = $row->outbreak_status && $row->outbreak_status !== 'NONE'
            ? 'Outbreak status: ' . (string) $row->outbreak_status
            : '';

        $ihr = (bool) $row->ihr_notified ? 'International partners notified under IHR 2005.' : '';

        // Plain-text digest for any template that does not yet wire the
        // structured variables, plus the user-event email body.
        $lines = ['CASE OUTCOME (WHO)'];
        $lines[] = '  Classification: ' . $cls;
        if ($lab !== '')          $lines[] = '  Laboratory:     ' . $lab . ($diseaseName ? ' · ' . $diseaseName : '');
        if ($clin !== '')         $lines[] = '  Clinical:       ' . $clin;
        if ($ph !== '')           $lines[] = '  Public-health:  ' . $ph;
        if ($outbreak !== '')     $lines[] = '  ' . $outbreak;
        if ($ihr !== '')          $lines[] = '  ' . $ihr;
        $summary = implode("\n", $lines);

        return [
            'outcome_classification_label' => $cls,
            'outcome_lab_label'            => $lab,
            'outcome_clinical_label'       => $clin,
            'outcome_ph_action_label'      => $ph,
            'outcome_outbreak_label'       => $outbreak,
            'outcome_ihr_text'             => $ihr,
            'outcome_lab_disease_name'     => $diseaseName,
            'outcome_summary_text'         => $summary,
        ];
    }

    /**
     * Builds a short text digest of the case + triage details so the
     * closure user-event email never goes out empty-handed even if the
     * recipient's templating engine ignores the rich variables.
     */
    private static function buildCaseTriageDigest(object $alert): string
    {
        try {
            $sc = !empty($alert->secondary_screening_id)
                ? DB::table('secondary_screenings')->where('id', $alert->secondary_screening_id)->first([
                    'traveler_full_name','traveler_age_years','traveler_gender','traveler_nationality_country_code',
                    'phone_number','email','arrival_datetime','triage_category','general_appearance','syndrome_classification',
                    'temperature_value','temperature_unit','pulse_rate','respiratory_rate','oxygen_saturation','bp_systolic','bp_diastolic',
                    'final_disposition','officer_notes',
                ])
                : null;
        } catch (Throwable) { $sc = null; }
        if (!$sc) return '';

        $lines = ['CASE & TRIAGE'];
        if ($sc->traveler_full_name) {
            $genderLabel = static::humaniseEnumValue(strtoupper((string) ($sc->traveler_gender ?? '')));
            $lines[] = '  Patient:        ' . trim($sc->traveler_full_name . ' · ' . ($sc->traveler_age_years ? $sc->traveler_age_years . 'y · ' : '') . $genderLabel . ($sc->traveler_nationality_country_code ? ' · ' . $sc->traveler_nationality_country_code : ''), ' ·');
        }
        if ($sc->phone_number || $sc->email) $lines[] = '  Reach:          ' . trim(($sc->phone_number ?: '') . ($sc->email ? ' · ' . $sc->email : ''), ' ·');
        if ($sc->arrival_datetime) $lines[] = '  Arrived:        ' . $sc->arrival_datetime;
        if ($sc->triage_category)  $lines[] = '  Triage:         ' . static::humaniseEnumValue(strtoupper((string) $sc->triage_category)) . ($sc->general_appearance ? ' · ' . static::humaniseEnumValue(strtoupper((string) $sc->general_appearance)) : '');
        if ($sc->syndrome_classification) $lines[] = '  Syndrome:       ' . static::humaniseDiseaseOrCode((string) $sc->syndrome_classification);
        $vit = [];
        if ($sc->temperature_value)  $vit[] = 'Temp ' . $sc->temperature_value . ($sc->temperature_unit ? '°' . $sc->temperature_unit : '°C');
        if ($sc->pulse_rate)         $vit[] = 'HR ' . $sc->pulse_rate;
        if ($sc->respiratory_rate)   $vit[] = 'RR ' . $sc->respiratory_rate;
        if ($sc->oxygen_saturation)  $vit[] = 'SpO2 ' . $sc->oxygen_saturation . '%';
        if ($sc->bp_systolic)        $vit[] = 'BP ' . $sc->bp_systolic . '/' . ($sc->bp_diastolic ?: '?');
        if (!empty($vit))           $lines[] = '  Vitals:         ' . implode(' · ', $vit);
        if ($sc->final_disposition) $lines[] = '  Disposition:    ' . static::humaniseEnumValue(strtoupper((string) $sc->final_disposition));
        if ($sc->officer_notes)     $lines[] = '  Officer note:   ' . mb_substr($sc->officer_notes, 0, 200);

        return implode("\n", $lines);
    }

    /**
     * Fire email when a primary screening flags a referral. Uses the
     * HIGH-risk template so contacts see it in the same visual language.
     * Notifies the POE + DISTRICT + PHEOC + NATIONAL ladder.
     */
    public static function dispatchScreeningReferral(object $screening, ?object $notification, int $userId = 0): array
    {
        return static::safely(function () use ($screening, $notification, $userId) {
            // Only fire if a notification was actually created with HIGH/CRITICAL priority
            if (! $notification) {
                return ['skipped_no_notification' => true];
            }
            $priority = (string) ($notification->priority ?? 'NORMAL');
            if (! in_array($priority, ['HIGH', 'CRITICAL'], true)) {
                return ['skipped_low_priority' => true];
            }

            $vars = CaseContextBuilder::forScreening($screening, $notification);
            $templateCode = 'SCREENING_REFERRAL';

            $contacts = static::resolveContactsByScope(
                $screening->country_code ?? null,
                $screening->district_code ?? null,
                $screening->poe_code ?? null,
                ['POE', 'DISTRICT', 'PHEOC', 'NATIONAL'],
                $priority === 'CRITICAL' ? 'receives_critical' : 'receives_high',
            );

            // Use broadcast: ONE email, TO = highest-priority contact, CC = everyone else.
            // Never BCC — every recipient must see the full national taskforce on the thread.
            $out = static::sendBroadcast(collect($contacts->all()), $templateCode, $vars, 'USER:' . $userId, 'SCREENING');
            $sent    = ($out['status'] ?? '') === 'SENT'    ? 1 : 0;
            $skipped = ($out['status'] ?? '') === 'SKIPPED' ? 1 : 0;
            $failed  = ($out['status'] ?? '') === 'FAILED'  ? 1 : 0;
            return ['template' => $templateCode, 'priority' => $priority,
                    'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed,
                    'to' => $out['to'] ?? null, 'cc_count' => $out['cc_count'] ?? 0];
        }, 'dispatchScreeningReferral');
    }

    /**
     * The 14 RTSL early response actions per Resolve to Save Lives + WHO
     * 7-1-7. Auto-seeded against every newly created alert so the response
     * checklist is ready the moment operators open the case file.
     *
     * Format: [code, label, due_offset_hours, blocks_closure].
     */
    public const RTSL_14_ACTIONS = [
        ['CASE_INVESTIGATION',     'Case investigation started',                       4,  true ],
        ['ISOLATION',              'Index case isolated / treatment initiated',        4,  true ],
        ['CONTACT_LISTING',        'Close contacts identified and listed',             24, true ],
        ['CONTACT_TRACING',        'Contact tracing and follow-up operational',        24, true ],
        ['LAB_SPECIMENS',          'Laboratory specimens collected and transported',   48, false],
        ['LAB_CONFIRMATION',       'Laboratory confirmation obtained',                 48, false],
        ['LINE_LIST',              'Epidemiological line list maintained',             48, false],
        ['RISK_COMMS',             'Risk communication to the public initiated',       72, false],
        ['IPC',                    'Infection prevention & control (IPC) in facilities',72, false],
        ['VECTOR_CONTROL',         'Vector control measures (if applicable)',          72, false],
        ['POE_SURVEILLANCE',       'Cross-border / POE surveillance strengthened',     168, false],
        ['EOC_ACTIVATION',         'Coordination structure activated (EOC / PHEOC)',   24, true ],
        ['RESOURCE_MOBILISATION',  'Response resources mobilised',                     168, false],
        ['WHO_NOTIFICATION',       'WHO and partners notified per IHR Article 6',      24, true ],
    ];

    /**
     * Auto-seed the 14 RTSL early response actions against an alert. Idempotent
     * — re-runs add only the missing rows so it is safe to call repeatedly.
     */
    public static function seedRtsl14Followups(object $alert, int $userId): array
    {
        return static::safely(function () use ($alert, $userId) {
            $existing = DB::table('alert_followups')
                ->where('alert_id', $alert->id)
                ->whereNull('deleted_at')
                ->pluck('action_code')->all();
            $existingSet = array_flip($existing);
            $createdAt = strtotime((string) ($alert->created_at ?? now()));
            $created = 0;
            foreach (self::RTSL_14_ACTIONS as $row) {
                [$code, $label, $hours, $blocks] = $row;
                if (isset($existingSet[$code])) continue;
                $dueAt = date('Y-m-d H:i:s', $createdAt + $hours * 3600);
                DB::table('alert_followups')->insert([
                    'client_uuid'       => static::genUuid(),
                    'alert_id'          => $alert->id,
                    'alert_client_uuid' => $alert->client_uuid ?? null,
                    'action_code'       => $code,
                    'action_label'      => $label,
                    'status'            => 'PENDING',
                    'due_at'            => $dueAt,
                    'blocks_closure'    => $blocks ? 1 : 0,
                    'country_code'      => $alert->country_code ?? config('country.code'),
                    'district_code'     => $alert->district_code ?? '',
                    'poe_code'          => $alert->poe_code ?? '',
                    'created_by_user_id' => $userId,
                    'device_id'         => 'server',
                    'platform'          => 'WEB',
                    'record_version'    => 1,
                    'sync_status'       => 'SYNCED',
                    'synced_at'         => now(),
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
                $created++;
            }
            return ['seeded' => $created, 'already_present' => count($existing)];
        }, 'seedRtsl14Followups');
    }

    private static function genUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff));
    }

    /**
     * Rich case-file dispatch — used when a secondary screening reaches a
     * disposition that is NOT a confirmed non-case. Pulls the full case
     * context (traveller, exposures, samples, actions, suspected diseases)
     * and renders the ALERT_CASE_FILE template with all 12 contextual
     * fields the spec demands (what / where / when / who / why / status /
     * actions taken / next required / owner / deadline / case IDs).
     *
     * @param object $secondaryCase  secondary_screenings row
     * @param int    $userId         operator who triggered (for log)
     * @param ?object $alertOverride optional alert to attach the case file to
     */
    public static function dispatchCaseFile(object $secondaryCase, int $userId = 0, ?object $alertOverride = null): array
    {
        return static::safely(function () use ($secondaryCase, $userId, $alertOverride) {
            // Skip non-cases — the spec is explicit: only fire when the
            // case is something other than a confirmed non-case.
            $disp = strtoupper((string) ($secondaryCase->final_disposition ?? ''));
            if (in_array($disp, ['NON_CASE', 'NOT_A_CASE', 'NONE'], true)) {
                return ['skipped_non_case' => true];
            }

            // Locate (or create a virtual) alert for the case file
            $alert = $alertOverride
                ?? DB::table('alerts')
                    ->where('secondary_screening_id', $secondaryCase->id)
                    ->whereNull('deleted_at')
                    ->orderByDesc('id')
                    ->first();

            // Render-time vars — CaseContextBuilder produces the full
            // decision-grade payload. Fall back to a virtual alert object if
            // no alerts row exists yet (case file dispatched from secondary
            // screening before any alert has been raised).
            $virtualAlert = $alert ?? (object) [
                'id'              => 0,
                'alert_code'      => 'CASEFILE-' . ($secondaryCase->id ?? '?'),
                'alert_title'     => 'Case file dispatch · ' . ($secondaryCase->traveler_full_name ?? 'Anonymous traveller'),
                'alert_details'   => 'Full case file dispatched from secondary screening.',
                'risk_level'      => (string) ($secondaryCase->risk_level ?? 'HIGH'),
                'routed_to_level' => (string) ($secondaryCase->followup_assigned_level ?? 'DISTRICT'),
                'ihr_tier'        => 'none',
                'country_code'    => (string) ($secondaryCase->country_code ?? ''),
                'district_code'   => (string) ($secondaryCase->district_code ?? ''),
                'poe_code'        => (string) ($secondaryCase->poe_code ?? ''),
                'secondary_screening_id' => (int) ($secondaryCase->id ?? 0),
                'status'          => 'OPEN',
                'created_at'      => $secondaryCase->created_at ?? now(),
            ];
            $vars = CaseContextBuilder::forAlert($virtualAlert);
            $relatedEntityId = (int) ($alert->id ?? $secondaryCase->id);

            // Country-aware contact resolution: only contacts in the same
            // country as the case. Routes across the IHR ladder.
            $levels = ['POE', 'DISTRICT', 'PHEOC', 'NATIONAL'];
            $contacts = static::resolveContactsByScope(
                $secondaryCase->country_code ?? null,
                $secondaryCase->district_code ?? null,
                $secondaryCase->poe_code ?? null,
                $levels,
                'receives_high', // case files always go to high+
            );

            $blast = static::sendUnifiedBlast($contacts, 'ALERT_CASE_FILE', $vars,
                $alert ?? $secondaryCase, 'USER:' . $userId, 'CASE_FILE', $relatedEntityId);
            return ['template' => 'ALERT_CASE_FILE',
                    'sent' => (int) ($blast['sent'] ?? 0),
                    'skipped' => 0,
                    'failed' => (int) ($blast['failed'] ?? 0),
                    'recipients_resolved' => $contacts->count(),
                    'cc_count' => (int) ($blast['cc_count'] ?? 0)];
        }, 'dispatchCaseFile');
    }

    /**
     * Immediate notification fired on store() — the moment a secondary screening
     * case is opened at a POE, before any disposition is recorded.
     *
     * Uganda: notifies NATIONAL tier only (all national contacts).
     * Other countries: notifies full POE → DISTRICT → PHEOC → NATIONAL ladder.
     *
     * Uses the SECONDARY_SCREENING_OPENED template. Non-throwing.
     */
    public static function dispatchSecondaryScreeningOpened(object $newCase, int $userId = 0): array
    {
        return static::safely(function () use ($newCase, $userId) {
            $cc = strtoupper((string) ($newCase->country_code ?? ''));

            // Uganda: national level only — they see every case regardless of POE.
            $levels = $cc === 'UG'
                ? ['NATIONAL']
                : ['POE', 'DISTRICT', 'PHEOC', 'NATIONAL'];

            $contacts = static::resolveContactsByScope(
                $newCase->country_code ?? null,
                $newCase->district_code ?? null,
                $newCase->poe_code ?? null,
                $levels,
                'receives_high',
            );

            if ($contacts->isEmpty()) {
                return ['template' => 'SECONDARY_SCREENING_OPENED', 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'note' => 'no_contacts'];
            }

            $openedAt = $newCase->opened_at ?? $newCase->created_at ?? now()->format('Y-m-d H:i:s');
            try {
                $dt = new \DateTime($openedAt, new \DateTimeZone('Africa/Kampala'));
            } catch (\Throwable $_) {
                $dt = new \DateTime('now', new \DateTimeZone('Africa/Kampala'));
            }

            $vars = array_merge(\App\Services\AdminLinks::generalVars(), [
                'poe_code'         => $newCase->poe_code        ?? '—',
                'district_code'    => $newCase->district_code   ?? '—',
                'country_code'     => $newCase->country_code    ?? 'RW',
                'client_uuid'      => $newCase->client_uuid     ?? '—',
                'traveler_name'    => !empty($newCase->traveler_full_name)
                                        ? $newCase->traveler_full_name
                                        : (!empty($newCase->traveler_initials)
                                            ? $newCase->traveler_initials
                                            : 'Not yet recorded'),
                'opened_at_date'   => $dt->format('d M Y'),
                'opened_at_time'   => $dt->format('H:i'),
                'sent_at'          => now()->format('d M Y H:i T'),
                'records_url'      => rtrim(config('app.url'), '/') . '/secondary-screening/records',
            ]);

            $blast = static::sendUnifiedBlast($contacts, 'SECONDARY_SCREENING_OPENED', $vars, $newCase,
                'USER:' . $userId, 'CASE', (int) ($newCase->id ?? 0));
            $sent    = (int) ($blast['sent'] ?? 0);
            $skipped = 0;
            $failed  = (int) ($blast['failed'] ?? 0);

            Log::info('[NotificationDispatcher] dispatchSecondaryScreeningOpened', [
                'case_id'  => $newCase->id ?? '?',
                'poe_code' => $newCase->poe_code ?? '?',
                'sent'     => $sent, 'skipped' => $skipped, 'failed' => $failed,
                'cc_count' => (int) ($blast['cc_count'] ?? 0),
            ]);

            return ['template' => 'SECONDARY_SCREENING_OPENED', 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed,
                    'recipients_resolved' => $contacts->count(), 'cc_count' => (int) ($blast['cc_count'] ?? 0)];
        }, 'dispatchSecondaryScreeningOpened');
    }

    /**
     * Send a structured info-request to an external responder (hospital, lab,
     * partner agency) about a specific case. Persists a one-time token so a
     * future inbound endpoint can match the response back to the case.
     */
    public static function requestExternalResponderInfo(int $responderId, int $alertId, int $userId, string $requestBody, ?string $subjectOverride = null): array
    {
        return static::safely(function () use ($responderId, $alertId, $userId, $requestBody, $subjectOverride) {
            $responder = DB::table('external_responders')->where('id', $responderId)->whereNull('deleted_at')->first();
            if (! $responder) return ['error' => 'External responder not found'];
            $alert = DB::table('alerts')->where('id', $alertId)->whereNull('deleted_at')->first();
            if (! $alert) return ['error' => 'Alert not found'];

            $token = bin2hex(random_bytes(24)); // 48-char hex
            DB::table('responder_info_requests')->insert([
                'responder_id'           => $responderId,
                'alert_id'               => $alertId,
                'secondary_screening_id' => $alert->secondary_screening_id,
                'requested_by_user_id'   => $userId,
                'request_token'          => $token,
                'request_subject'        => $subjectOverride ?? "POE Sentinel · Information request · {$alert->alert_code}",
                'request_body'           => $requestBody,
                'status'                 => 'SENT',
                'expires_at'             => now()->addDays(7),
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            // Hand-craft a contact-shaped object so we can reuse send()
            $virtualContact = (object) [
                'id'             => null,
                'email'          => $responder->email,
                'phone'          => $responder->phone,
                'country_code'   => $responder->country_code,
                'district_code'  => $responder->district_code,
                'poe_code'       => null,
                'alternate_email' => null,
            ];
            $vars = CaseContextBuilder::forAlert($alert, [
                'responder_name' => (string) $responder->name,
                'request_body'   => $requestBody,
                'request_token'  => $token,
            ]);
            $out = static::send($virtualContact, 'RESPONDER_INFO_REQUEST', $vars, $alert,
                'USER:' . $userId, 'RESPONDER_REQUEST', (int) $alertId);
            return array_merge($out, ['token' => $token]);
        }, 'requestExternalResponderInfo');
    }

    /**
     * Fire email when a 7-1-7 breach is detected on an alert.
     */
    public static function dispatchBreach717(object $alert, string $bottleneckPhase, float $elapsedHours, int $targetHours, int $userId = 0): array
    {
        return static::safely(function () use ($alert, $bottleneckPhase, $elapsedHours, $targetHours, $userId) {
            $vars = CaseContextBuilder::forAlert($alert, [
                'bottleneck_phase' => strtoupper($bottleneckPhase),
                'elapsed_hours'    => (string) round($elapsedHours, 1),
                'target_hours'     => (string) $targetHours,
            ]);
            $levels = ['DISTRICT', 'PHEOC', 'NATIONAL'];
            $contacts = static::resolveContactsForAlert($alert, $levels, 'receives_breach_alerts');
            $blast = static::sendUnifiedBlast($contacts, 'BREACH_717', $vars, $alert,
                'USER:' . $userId, 'ALERT', (int) $alert->id);
            $sent    = (int) ($blast['sent'] ?? 0);
            $skipped = 0;
            $failed  = (int) ($blast['failed'] ?? 0);
            // §3.2 — fan out a user-event BREACH_717 to the response team +
            // management so individual inboxes are paged (not just roster
            // mailboxes). Keeps operators aware the 7-1-7 SLA has slipped.
            static::dispatchUserEvent(
                $alert, 'BREACH_717',
                '7-1-7 breach on ' . strtoupper($bottleneckPhase) . ' phase',
                sprintf("Alert %s breached the %s target (%.1f h elapsed vs %d h target).\nRoot cause must be filed and a mitigation plan committed within 24 hours.",
                    (string) ($alert->alert_code ?? ''),
                    strtoupper($bottleneckPhase),
                    $elapsedHours,
                    $targetHours
                ),
                $userId,
            );
            return ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed];
        }, 'dispatchBreach717');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  SCHEDULED JOBS — called from Laravel console scheduler
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Compute the last-24h digest for each country-scope + fan out to every
     * contact subscribed to receives_daily_report=1.
     */
    // ══════════════════════════════════════════════════════════════════════
    // BROADCAST HELPER — one email, everyone in BCC
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Send a single email to the primary contact with every other subscriber
     * in BCC. This is the ONLY correct pattern for scheduled reports.
     *
     * Why one email instead of N individual emails:
     *   - N=14 contacts × OPS_CC=14 = 196 emails per report run
     *   - Broadcast = exactly 1 email, everyone receives it once
     *   - Privacy: BCC means nobody sees each other's address
     *
     * The first contact in $contacts (sorted by priority_order) is the TO
     * address so the email has a real named recipient instead of "Undisclosed".
     * Every other contact is BCC.
     *
     * @param  \Illuminate\Support\Collection  $contacts  ordered by priority_order
     * @param  string                          $templateCode
     * @param  array                           $vars
     * @param  string                          $triggeredBy
     * @param  string                          $entityType
     * @return array  ['status','sent','skipped','failed']
     */
    private static function sendBroadcast(
        \Illuminate\Support\Collection $contacts,
        string $templateCode,
        array  $vars,
        string $triggeredBy,
        string $entityType = 'REPORT'
    ): array {
        // Delegate to the executive-aligned unified blast so every dispatch
        // path obeys the same TO/CC/no-BCC policy. The collection is fed in
        // unmodified — sendUnifiedBlast resolves the configured primary TO
        // address and merges the OPS_CC national roster automatically.
        $blast = static::sendUnifiedBlast(
            $contacts->all(), $templateCode, $vars, null, $triggeredBy, $entityType, null
        );
        $sent = (int) ($blast['sent'] ?? 0);
        return [
            'status'    => $sent ? 'SENT' : (($blast['failed'] ?? 0) ? 'FAILED' : 'SKIPPED'),
            'sent'      => $sent,
            'skipped'   => 0,
            'failed'    => (int) ($blast['failed'] ?? 0),
            'to'        => static::primaryToAddress(),
            'cc_count'  => (int) ($blast['cc_count'] ?? 0),
            'error'     => $blast['error'] ?? null,
        ];
    }

    public static function sendDailyDigest(string $triggeredBy = 'CRON:daily'): array
    {
        return static::safely(function () use ($triggeredBy) {
            $countries = DB::table('poe_notification_contacts')
                ->where('receives_daily_report', 1)
                ->where('is_active', 1)->whereNull('deleted_at')
                ->distinct()->pluck('country_code');

            $totalSent = 0; $totalFailed = 0; $totalSkipped = 0;
            foreach ($countries as $cc) {
                $stats = static::buildDailyDigestVars((string) $cc);
                // ONE email per country — TO: first subscriber, BCC: rest
                $contacts = DB::table('poe_notification_contacts')
                    ->where('country_code', $cc)
                    ->where('receives_daily_report', 1)
                    ->where('is_active', 1)->whereNull('deleted_at')
                    ->orderBy('priority_order')->orderBy('id')->get();
                $out = static::sendBroadcast($contacts, 'DAILY_REPORT', $stats, $triggeredBy);
                if (($out['status'] ?? '') === 'SENT') $totalSent++;
                elseif (($out['status'] ?? '') === 'SKIPPED') $totalSkipped++;
                else $totalFailed++;
            }
            return ['sent' => $totalSent, 'skipped' => $totalSkipped, 'failed' => $totalFailed];
        }, 'sendDailyDigest');
    }

    /**
     * Assemble the full DAILY_REPORT variable payload for a single country.
     * Includes aggregate counts, top/silent POEs, disposition breakdown, and
     * the syndromes-HTML fragment the template consumes.
     */
    public static function buildDailyDigestVars(string $cc): array
    {
        $since = now()->subDay()->format('Y-m-d H:i:s');
        $today = now()->format('Y-m-d');
        $eod   = now()->endOfDay()->format('Y-m-d H:i:s');

        $alertsCount = fn(?string $risk) => (string) DB::table('alerts')
            ->where('country_code', $cc)
            ->when($risk, fn($q) => $q->where('risk_level', $risk))
            ->where('created_at', '>=', $since)
            ->whereNull('deleted_at')->count();

        $dispositionCount = fn(array $d) => (string) DB::table('secondary_screenings')
            ->where('country_code', $cc)
            ->whereIn('final_disposition', $d)
            ->where('dispositioned_at', '>=', $since)
            ->whereNull('deleted_at')->count();

        // Top POEs by screening volume in window
        $topPoes = DB::table('primary_screenings')
            ->selectRaw('poe_code, COUNT(*) AS n, SUM(symptoms_present) AS symp')
            ->where('country_code', $cc)
            ->where('captured_at', '>=', $since)
            ->whereNull('deleted_at')
            ->groupBy('poe_code')
            ->orderByDesc('n')
            ->limit(5)->get();

        // Silent POEs — registered POEs with zero submissions in window
        $silent = DB::table('poe_notification_contacts')
            ->where('country_code', $cc)
            ->whereNotNull('poe_code')
            ->where('is_active', 1)->whereNull('deleted_at')
            ->whereNotIn('poe_code', DB::table('primary_screenings')
                ->select('poe_code')
                ->where('country_code', $cc)
                ->where('captured_at', '>=', $since)
                ->whereNull('deleted_at'))
            ->distinct()->pluck('poe_code')->take(10)->all();

        // Syndromes in window
        $syndromes = DB::table('secondary_screenings')
            ->selectRaw('COALESCE(syndrome_classification, "(none)") AS k, COUNT(*) AS n')
            ->where('country_code', $cc)
            ->where('opened_at', '>=', $since)
            ->whereNull('deleted_at')
            ->groupBy('k')->orderByDesc('n')->limit(8)->get();

        $topPoesHtml = $topPoes->isEmpty()
            ? '<p style="margin:0;color:#64748B;font-size:12px;">No primary screening activity captured.</p>'
            : '<ul style="margin:0;padding-left:18px;font-size:13px;">' . $topPoes->map(fn($r) =>
                '<li style="margin:3px 0;"><strong>' . htmlspecialchars((string) $r->poe_code, ENT_QUOTES, 'UTF-8') . '</strong> · ' . (int) $r->n . ' screenings (' . (int) $r->symp . ' symptomatic)</li>'
            )->implode('') . '</ul>';

        $silentPoesHtml = empty($silent)
            ? '<p style="margin:0;color:#047857;font-size:12px;">No silent POEs — every registered POE has reported.</p>'
            : '<ul style="margin:0;padding-left:18px;font-size:13px;">' . implode('', array_map(fn($p) =>
                '<li style="margin:3px 0;color:#B91C1C;">' . htmlspecialchars((string) $p, ENT_QUOTES, 'UTF-8') . '</li>', $silent)) . '</ul>';

        $syndromesHtml = $syndromes->isEmpty()
            ? '<p style="margin:0;color:#64748B;font-size:12px;">No secondary screenings in the window.</p>'
            : '<ul style="margin:0;padding-left:18px;font-size:13px;">' . $syndromes->map(fn($s) =>
                '<li style="margin:3px 0;">' . htmlspecialchars((string) $s->k, ENT_QUOTES, 'UTF-8') . ' — <strong>' . (int) $s->n . '</strong></li>'
            )->implode('') . '</ul>';

        // PRESERVED: multi-country name lookup (Category B reference, not system scope).
        return [
            'country_code' => $cc,
            'country_name' => match (strtoupper($cc)) {
                'UG' => 'Uganda', 'RW' => 'Rwanda', 'ZM' => 'Zambia',
                'MW' => 'Malawi', 'ST', 'STP' => 'São Tomé and Príncipe',
                default => $cc,
            },
            'report_date' => $today,
            'now'         => now()->format('Y-m-d H:i'),
            'now_date'    => $today,
            'console_url'   => \App\Services\AdminLinks::alertsHub(),
            'action_url'    => \App\Services\AdminLinks::dashboard(),
            'dashboard_url' => \App\Services\AdminLinks::dashboard(),
            'hub_url'       => \App\Services\AdminLinks::alertsHub(),
            'app_url'       => \App\Services\AdminLinks::base(),

            'primary_screenings_24h'  => (string) ($ps   = DB::table('primary_screenings')->where('country_code', $cc)->where('captured_at', '>=', $since)->whereNull('deleted_at')->count()),
            'primary_symptomatic_24h' => (string) ($symp = DB::table('primary_screenings')->where('country_code', $cc)->where('captured_at', '>=', $since)->where('symptoms_present', 1)->whereNull('deleted_at')->count()),
            'alerts_24h'              => $alertsCount(null),
            'alerts_critical_24h'     => $alertsCount('CRITICAL'),
            'alerts_high_24h'         => $alertsCount('HIGH'),
            'alerts_medium_24h'       => $alertsCount('MEDIUM'),
            'alerts_low_24h'          => $alertsCount('LOW'),
            'alerts_stuck_open'       => (string) DB::table('alerts')
                ->where('country_code', $cc)->where('status', 'OPEN')
                ->whereRaw('TIMESTAMPDIFF(HOUR, created_at, NOW()) > 24')
                ->whereNull('deleted_at')->count(),

            'followups_overdue_total' => (string) DB::table('alert_followups')
                ->where('country_code', $cc)
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['COMPLETED', 'NOT_APPLICABLE'])
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->count(),
            'followups_due_today' => (string) DB::table('alert_followups')
                ->where('country_code', $cc)
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['COMPLETED', 'NOT_APPLICABLE'])
                ->whereNotNull('due_at')
                ->where('due_at', '>=', now())
                ->where('due_at', '<=', $eod)
                ->count(),

            'disposition_released' => $dispositionCount(['RELEASED']),
            'disposition_referred' => $dispositionCount(['REFERRED', 'TRANSFERRED']),
            'disposition_isolated' => $dispositionCount(['ISOLATED', 'QUARANTINED']),
            'disposition_delayed'  => $dispositionCount(['DELAYED', 'DENIED_BOARDING']),

            'top_poes_html'    => $topPoesHtml,
            'silent_poes_html' => $silentPoesHtml,
            'syndromes_html'   => $syndromesHtml,

            // Computed rates — never show raw counts without denominators
            'primary_symptomatic_rate_pct' => $ps > 0 ? round(($symp / $ps) * 100, 1) . '%' : '0%',
            'primary_asymptomatic_24h'     => (string) max(0, $ps - $symp),

            // Fever count — the primary sentinel symptom at POEs
            'fever_count_24h' => (string) DB::table('primary_screenings')
                ->where('country_code', $cc)->where('captured_at', '>=', $since)
                ->whereNotNull('temperature_value')->where('temperature_value', '>=', 37.5)
                ->whereNull('deleted_at')->count(),
            'fever_rate_24h'  => $ps > 0
                ? round((DB::table('primary_screenings')->where('country_code',$cc)
                    ->where('captured_at','>=',$since)->whereNotNull('temperature_value')
                    ->where('temperature_value','>=',37.5)->whereNull('deleted_at')->count() / $ps) * 100, 1) . '%'
                : '0%',

            // Secondary screenings — new cases opened in window
            'secondary_screenings_24h' => (string) DB::table('secondary_screenings')
                ->where('country_code', $cc)->where('opened_at', '>=', $since)->whereNull('deleted_at')->count(),

            // POE denominator for context
            'poes_total_registered' => (string) DB::table('poe_notification_contacts')
                ->where('country_code', $cc)->whereNotNull('poe_code')
                ->where('is_active', 1)->whereNull('deleted_at')->distinct()->count('poe_code'),

            // IHR tier events in window
            'tier1_events_24h' => (string) DB::table('alerts')->where('country_code',$cc)
                ->where('created_at','>=',$since)->where('ihr_tier','LIKE','TIER_1%')->whereNull('deleted_at')->count(),
            'annex2_events_24h'=> (string) DB::table('alerts')->where('country_code',$cc)
                ->where('created_at','>=',$since)->whereNotNull('ihr_tier')->whereNull('deleted_at')->count(),
        ];
    }

    /**
     * Assemble NATIONAL_INTELLIGENCE vars for a country. Produces the HTML
     * fragments (silent_poes_html, stuck_alerts_html, etc.) the template
     * consumes, plus the counts and the narrative string.
     */
    public static function buildNationalIntelligenceVars(string $cc): array
    {
        $since24 = now()->subDay()->format('Y-m-d H:i:s');
        $since3d = now()->subDays(3)->format('Y-m-d H:i:s');
        $since14 = now()->subDays(14)->format('Y-m-d H:i:s');

        // Silent POEs (active in 7d, none in 24h)
        $activeRecent = DB::table('primary_screenings')
            ->where('country_code', $cc)
            ->where('captured_at', '>=', now()->subDays(7)->format('Y-m-d H:i:s'))
            ->whereNull('deleted_at')
            ->distinct()->pluck('poe_code')->all();
        $silent = [];
        foreach ($activeRecent as $poe) {
            $hadRecent = DB::table('primary_screenings')
                ->where('country_code', $cc)->where('poe_code', $poe)
                ->where('captured_at', '>=', $since24)->whereNull('deleted_at')->exists();
            if (! $hadRecent) $silent[] = $poe;
        }

        // Unsubmitted POEs (> 3d offline)
        $regulars = DB::table('aggregated_submissions')
            ->where('country_code', $cc)->where('created_at', '>=', now()->subDays(14)->format('Y-m-d H:i:s'))
            ->whereNull('deleted_at')->distinct()->pluck('poe_code')->all();
        $unsubmitted = [];
        foreach ($regulars as $poe) {
            $recent = DB::table('aggregated_submissions')
                ->where('country_code', $cc)->where('poe_code', $poe)
                ->where('created_at', '>=', $since3d)->whereNull('deleted_at')->exists();
            if (! $recent) $unsubmitted[] = $poe;
        }

        // Dormant accounts (no login in 14d)
        $dormant = DB::table('users')
            ->where('country_code', $cc)
            ->where('is_active', 1)
            ->where(function ($q) use ($since14) {
                $q->whereNull('last_login_at')->orWhere('last_login_at', '<', $since14);
            })
            ->select('full_name', 'email', 'last_login_at', 'role_key')
            ->limit(20)->get();

        // Stuck alerts (open past SLA)
        $stuck = DB::table('alerts')
            ->where('country_code', $cc)
            ->where('status', 'OPEN')
            ->whereNull('deleted_at')
            ->whereRaw("TIMESTAMPDIFF(HOUR, created_at, NOW()) > (CASE risk_level WHEN 'CRITICAL' THEN 4 WHEN 'HIGH' THEN 24 ELSE 48 END)")
            ->select('alert_code', 'risk_level', 'poe_code', 'alert_title', 'created_at')
            ->orderBy('created_at')
            ->limit(15)->get();

        // Overdue followups
        $overdueFu = DB::table('alert_followups as f')
            ->leftJoin('alerts as a', 'a.id', '=', 'f.alert_id')
            ->where('f.country_code', $cc)
            ->whereNull('f.deleted_at')
            ->whereNotIn('f.status', ['COMPLETED', 'NOT_APPLICABLE'])
            ->whereNotNull('f.due_at')
            ->where('f.due_at', '<', now())
            ->select('f.action_code', 'f.action_label', 'f.due_at', 'f.status', 'a.alert_code', 'f.poe_code', 'f.blocks_closure')
            ->orderBy('f.due_at')->limit(20)->get();

        // Case spikes — disease × district with >2x the 14d rolling daily average
        $spikes = DB::select("
            SELECT sd.disease_code, s.district_code, COUNT(*) AS n24,
                   (SELECT COUNT(*) FROM secondary_suspected_diseases sd2
                    INNER JOIN secondary_screenings s2 ON s2.id = sd2.secondary_screening_id
                    WHERE sd2.disease_code = sd.disease_code AND s2.district_code = s.district_code
                      AND s2.country_code = ? AND s2.opened_at >= ? AND s2.opened_at < ?) AS n14
            FROM secondary_suspected_diseases sd
            INNER JOIN secondary_screenings s ON s.id = sd.secondary_screening_id
            WHERE s.country_code = ? AND s.opened_at >= ?
            GROUP BY sd.disease_code, s.district_code
            HAVING n24 >= 2 AND (n14 = 0 OR n24 > (n14 / 14) * 2)
            ORDER BY n24 DESC
            LIMIT 10
        ", [$cc, now()->subDays(14)->format('Y-m-d H:i:s'), $since24, $cc, $since24]);

        // HTML fragments
        $silentHtml = empty($silent)
            ? '<p style="margin:0;color:#047857;font-size:12px;">No silent POEs.</p>'
            : '<ul style="margin:0;padding-left:18px;font-size:13px;">' . implode('', array_map(fn($p) =>
                '<li style="margin:3px 0;color:#B91C1C;">' . htmlspecialchars((string) $p, ENT_QUOTES, 'UTF-8') . '</li>', $silent)) . '</ul>';

        $unsubmittedHtml = empty($unsubmitted)
            ? '<p style="margin:0;color:#047857;font-size:12px;">All pipelines synced.</p>'
            : '<ul style="margin:0;padding-left:18px;font-size:13px;">' . implode('', array_map(fn($p) =>
                '<li style="margin:3px 0;color:#B45309;">' . htmlspecialchars((string) $p, ENT_QUOTES, 'UTF-8') . '</li>', $unsubmitted)) . '</ul>';

        $dormantHtml = $dormant->isEmpty()
            ? '<p style="margin:0;color:#047857;font-size:12px;">All officers logged in recently.</p>'
            : '<ul style="margin:0;padding-left:18px;font-size:13px;">' . $dormant->map(fn($u) =>
                '<li style="margin:3px 0;"><strong>' . htmlspecialchars((string) ($u->full_name ?? '—'), ENT_QUOTES, 'UTF-8') . '</strong> · ' . htmlspecialchars(static::humaniseEnumValue(strtoupper((string) ($u->role_key ?? ''))), ENT_QUOTES, 'UTF-8') . ' · last login: <em>' . htmlspecialchars((string) ($u->last_login_at ?? 'never'), ENT_QUOTES, 'UTF-8') . '</em></li>'
            )->implode('') . '</ul>';

        $stuckHtml = $stuck->isEmpty()
            ? '<p style="margin:0;color:#047857;font-size:12px;">No stuck alerts.</p>'
            : '<ul style="margin:0;padding-left:18px;font-size:13px;">' . $stuck->map(function($a) {
                $poeName = static::resolvePoeName((string) $a->poe_code);
                $poeLabel = $poeName !== '' ? $poeName : (string) $a->poe_code;
                return '<li style="margin:4px 0;"><strong>' . htmlspecialchars((string) $a->alert_code, ENT_QUOTES, 'UTF-8') . '</strong> · ' . htmlspecialchars(static::humaniseEnumValue(strtoupper((string) $a->risk_level)), ENT_QUOTES, 'UTF-8') . ' risk · ' . htmlspecialchars($poeLabel, ENT_QUOTES, 'UTF-8') . '<br><span style="color:#64748B;font-size:11px;">' . htmlspecialchars(static::humaniseDiseaseOrCode((string) $a->alert_title), ENT_QUOTES, 'UTF-8') . ' · opened ' . htmlspecialchars((string) $a->created_at, ENT_QUOTES, 'UTF-8') . '</span></li>';
            })->implode('') . '</ul>';

        $overdueHtml = $overdueFu->isEmpty()
            ? '<p style="margin:0;color:#047857;font-size:12px;">No overdue follow-ups.</p>'
            : '<ul style="margin:0;padding-left:18px;font-size:13px;">' . $overdueFu->map(fn($f) =>
                '<li style="margin:4px 0;">' . htmlspecialchars(static::humaniseEnumValue(strtoupper((string) $f->action_code)), ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars((string) $f->action_label, ENT_QUOTES, 'UTF-8') . ' · alert ' . htmlspecialchars((string) $f->alert_code, ENT_QUOTES, 'UTF-8') . ' · due ' . htmlspecialchars((string) $f->due_at, ENT_QUOTES, 'UTF-8') . (((int) $f->blocks_closure) === 1 ? ' · <span style="color:#B91C1C;">blocks closure</span>' : '') . '</li>'
            )->implode('') . '</ul>';

        $spikesHtml = empty($spikes)
            ? '<p style="margin:0;color:#047857;font-size:12px;">No cluster signals above baseline.</p>'
            : '<ul style="margin:0;padding-left:18px;font-size:13px;">' . implode('', array_map(function($s) {
                $distName = static::resolveDistrictName((string) $s->district_code);
                $distLabel = $distName !== '' ? $distName : (string) $s->district_code;
                return '<li style="margin:4px 0;"><strong>' . htmlspecialchars(static::humaniseDiseaseOrCode((string) $s->disease_code), ENT_QUOTES, 'UTF-8') . '</strong> in <strong>' . htmlspecialchars($distLabel, ENT_QUOTES, 'UTF-8') . '</strong> — ' . (int) $s->n24 . ' cases/24h vs. ' . number_format(((int) $s->n14) / 14, 2) . '/day 14d baseline</li>';
            }, $spikes)) . '</ul>';

        $narrative = static::buildIntelNarrative($cc, count($silent), count($unsubmitted),
            $dormant->count(), $stuck->count(), $overdueFu->count(), count($spikes));

        // PRESERVED: multi-country name lookup (Category B reference, not system scope).
        return [
            'country_code' => $cc,
            'country_name' => match (strtoupper($cc)) {
                'UG' => 'Uganda', 'RW' => 'Rwanda', 'ZM' => 'Zambia',
                'MW' => 'Malawi', 'ST', 'STP' => 'São Tomé and Príncipe',
                default => $cc,
            },
            'now'          => now()->format('Y-m-d H:i'),
            'now_date'     => now()->format('Y-m-d'),
            'console_url'   => \App\Services\AdminLinks::dashboard(),
            'action_url'    => \App\Services\AdminLinks::dashboard(),
            'dashboard_url' => \App\Services\AdminLinks::dashboard(),
            'hub_url'       => \App\Services\AdminLinks::alertsHub(),
            'app_url'       => \App\Services\AdminLinks::base(),
            'narrative'    => $narrative,

            'silent_poes_count'       => (string) count($silent),
            'silent_poes_html'        => $silentHtml,
            'unsubmitted_poes_count'  => (string) count($unsubmitted),
            'unsubmitted_poes_html'   => $unsubmittedHtml,
            'dormant_accounts_count'  => (string) $dormant->count(),
            'dormant_accounts_html'   => $dormantHtml,
            'stuck_alerts_count'      => (string) $stuck->count(),
            'stuck_alerts_html'       => $stuckHtml,
            'overdue_followups_count' => (string) $overdueFu->count(),
            'overdue_followups_html'  => $overdueHtml,
            'case_spikes_count'       => (string) count($spikes),
            'case_spikes_html'        => $spikesHtml,

            // IHR events — always-notifiable Tier 1 and Annex 2 triggers in the window.
            // These are the most critical metrics for national decision-making.
            'tier1_events_72h'  => (string) DB::table('alerts')->where('country_code',$cc)
                ->where('created_at','>=',$since3d)->where('ihr_tier','LIKE','TIER_1%')->whereNull('deleted_at')->count(),
            'annex2_events_72h' => (string) DB::table('alerts')->where('country_code',$cc)
                ->where('created_at','>=',$since3d)->whereNotNull('ihr_tier')->whereNull('deleted_at')->count(),
            'total_alerts_72h'  => (string) DB::table('alerts')->where('country_code',$cc)
                ->where('created_at','>=',$since3d)->whereNull('deleted_at')->count(),

            // IHR 7-1-7 compliance: % of alerts in 3d window acknowledged within 24h
            '7_1_7_notify_compliance_72h' => (function () use ($cc, $since3d) {
                $total = DB::table('alerts')->where('country_code',$cc)->where('created_at','>=',$since3d)->whereNull('deleted_at')->count();
                if (!$total) return 'N/A';
                $acked = DB::table('alerts')->where('country_code',$cc)->where('created_at','>=',$since3d)
                    ->whereNull('deleted_at')->whereNotNull('acknowledged_at')
                    ->whereRaw('TIMESTAMPDIFF(HOUR, created_at, acknowledged_at) <= 24')->count();
                return round(($acked / $total) * 100) . '% (' . $acked . '/' . $total . ')';
            })(),

            // POE denominator
            'poes_total_registered' => (string) DB::table('poe_notification_contacts')
                ->where('country_code',$cc)->whereNotNull('poe_code')
                ->where('is_active',1)->whereNull('deleted_at')->distinct()->count('poe_code'),
        ];
    }

    private static function buildIntelNarrative(string $cc, int $silent, int $unsub, int $dormant, int $stuck, int $overdue, int $spikes): string
    {
        $total = $silent + $unsub + $dormant + $stuck + $overdue + $spikes;
        if ($total === 0) {
            return "Country {$cc} is running clean: every active POE reported in the last 24h, every officer logged in within 14 days, no alerts are stuck past SLA, no follow-ups are overdue, and no cluster signal exceeded the 14-day baseline. Maintain posture.";
        }
        $parts = [];
        if ($silent > 0)  $parts[] = "$silent POE(s) have gone silent in the last 24h";
        if ($unsub > 0)   $parts[] = "$unsub POE(s) have offline data older than 3 days";
        if ($dormant > 0) $parts[] = "$dormant officer account(s) have not logged in for 14+ days";
        if ($stuck > 0)   $parts[] = "$stuck alert(s) are open past the acknowledgement SLA";
        if ($overdue > 0) $parts[] = "$overdue follow-up action(s) are overdue, some blocking closure";
        if ($spikes > 0)  $parts[] = "$spikes disease-by-district cluster signal(s) exceed the 14-day baseline";
        $list = count($parts) > 1
            ? implode('; ', array_slice($parts, 0, -1)) . '; and ' . end($parts)
            : $parts[0];
        return "{$cc} national surveillance brief — {$list}. Each section below is actionable today.";
    }

    /**
     * Scan alert_followups for DUE-SOON (<= 4h remaining) and OVERDUE items
     * and fan out FOLLOWUP_DUE / FOLLOWUP_OVERDUE emails.
     */
    public static function sendFollowupReminders(string $triggeredBy = 'CRON:followups'): array
    {
        return static::safely(function () use ($triggeredBy) {
            $now = now();
            $sent = 0; $skipped = 0; $failed = 0;

            // Overdue
            $overdue = DB::table('alert_followups')
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['COMPLETED', 'NOT_APPLICABLE'])
                ->whereNotNull('due_at')
                ->where('due_at', '<', $now)
                ->get();
            foreach ($overdue as $f) {
                $alert = DB::table('alerts')->where('id', $f->alert_id)->whereNull('deleted_at')->first();
                if (! $alert) continue;
                // Never remind on a CLOSED alert — the case is done.
                if (($alert->status ?? '') === 'CLOSED') continue;
                $contacts = DB::table('poe_notification_contacts')
                    ->where('country_code', $alert->country_code)
                    ->where('receives_followup_reminders', 1)
                    ->where('is_active', 1)->whereNull('deleted_at')
                    ->get();
                $vars = CaseContextBuilder::forFollowup($f, $alert);
                $blast = static::sendUnifiedBlast($contacts, 'FOLLOWUP_OVERDUE', $vars, $alert,
                    $triggeredBy, 'FOLLOWUP', (int) $f->id);
                $sent   += (int) ($blast['sent'] ?? 0);
                $failed += (int) ($blast['failed'] ?? 0);
            }

            // Due soon (≤ 4h)
            $soon = DB::table('alert_followups')
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['COMPLETED', 'NOT_APPLICABLE'])
                ->whereNotNull('due_at')
                ->where('due_at', '>=', $now)
                ->where('due_at', '<=', $now->copy()->addHours(4))
                ->get();
            foreach ($soon as $f) {
                $alert = DB::table('alerts')->where('id', $f->alert_id)->whereNull('deleted_at')->first();
                if (! $alert) continue;
                // Never remind on a CLOSED alert — the case is done.
                if (($alert->status ?? '') === 'CLOSED') continue;
                $hoursRemaining = max(0, (int) round((strtotime((string) $f->due_at) - $now->timestamp) / 3600));
                $contacts = DB::table('poe_notification_contacts')
                    ->where('country_code', $alert->country_code)
                    ->where('receives_followup_reminders', 1)
                    ->where('is_active', 1)->whereNull('deleted_at')
                    ->get();
                $vars = CaseContextBuilder::forFollowup($f, $alert, [
                    'followup_due_in_hours' => (string) $hoursRemaining,
                ]);
                $blast = static::sendUnifiedBlast($contacts, 'FOLLOWUP_DUE', $vars, $alert,
                    $triggeredBy, 'FOLLOWUP', (int) $f->id);
                $sent   += (int) ($blast['sent'] ?? 0);
                $failed += (int) ($blast['failed'] ?? 0);
            }

            return ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed,
                    'overdue_count' => $overdue->count(), 'due_soon_count' => $soon->count()];
        }, 'sendFollowupReminders');
    }

    /**
     * National Intelligence triennial digest (every 3 days).
     *
     * Runs the IntelligenceEngine for each distinct country with a
     * NATIONAL-tier subscriber, then emails the digest ONLY to
     * NATIONAL_ADMIN-tier contacts (priority_order 1) of that country —
     * the operational roster (priority 2-19) is intentionally excluded
     * to avoid spamming during a low-signal period.
     *
     * Country isolation: Uganda's digest goes only to Uganda's NATIONAL
     * contacts; future rosters for other countries each get a digest scoped
     * to their own country.
     */
    public static function sendNationalIntelligenceDigest(string $triggeredBy = 'CRON:national-intel'): array
    {
        return static::safely(function () use ($triggeredBy) {
            $countries = DB::table('poe_notification_contacts')
                ->where('level', 'NATIONAL')
                ->where('is_active', 1)->whereNull('deleted_at')
                ->distinct()->pluck('country_code');

            $totalSent = 0; $totalSkipped = 0; $totalFailed = 0; $countriesProcessed = 0;
            foreach ($countries as $cc) {
                if (! $cc) continue;
                $vars = static::buildNationalIntelligenceVars((string) $cc);
                $countriesProcessed++;

                // Recipients — NATIONAL tier only, priority 1-3 (the
                // strategic recipients), to keep this email signal-rich.
                $contacts = DB::table('poe_notification_contacts')
                    ->where('country_code', $cc)
                    ->where('level', 'NATIONAL')
                    ->where('is_active', 1)->whereNull('deleted_at')
                    ->where('priority_order', '<=', 3)
                    ->get();

                // ONE email — TO first contact (priority 1), BCC: priority 2 and 3
                $contactsColl = collect($contacts->all());
                $out = static::sendBroadcast($contactsColl, 'NATIONAL_INTELLIGENCE', $vars, $triggeredBy, 'INTEL_REPORT');
                if (($out['status'] ?? '') === 'SENT')        $totalSent++;
                elseif (($out['status'] ?? '') === 'SKIPPED') $totalSkipped++;
                else                                          $totalFailed++;
            }
            return [
                'countries_processed' => $countriesProcessed,
                'sent'    => $totalSent,
                'skipped' => $totalSkipped,
                'failed'  => $totalFailed,
            ];
        }, 'sendNationalIntelligenceDigest');
    }

    // ══════════════════════════════════════════════════════════════════════
    // WEEKLY REPORT — comprehensive 7-day scorecard
    // Sent every Monday 07:30 Kampala time to all contacts with
    // receives_weekly_report=1. Far richer than the daily digest:
    // includes trend comparison, IHR 7-1-7 compliance, disease breakdown,
    // nationality/direction/travel-mode splits, officer productivity, and
    // narrative executive summary.
    // ══════════════════════════════════════════════════════════════════════

    public static function sendWeeklyDigest(string $triggeredBy = 'CRON:weekly'): array
    {
        return static::safely(function () use ($triggeredBy) {
            $countries = DB::table('poe_notification_contacts')
                ->where('receives_weekly_report', 1)
                ->where('is_active', 1)->whereNull('deleted_at')
                ->distinct()->pluck('country_code');

            $totalSent = 0; $totalSkipped = 0; $totalFailed = 0;
            foreach ($countries as $cc) {
                $vars = static::buildWeeklyDigestVars((string) $cc);
                // ONE email — TO first subscriber, BCC rest
                $contacts = DB::table('poe_notification_contacts')
                    ->where('country_code', $cc)->where('receives_weekly_report', 1)
                    ->where('is_active', 1)->whereNull('deleted_at')
                    ->orderBy('priority_order')->orderBy('id')->get();
                $out = static::sendBroadcast($contacts, 'WEEKLY_REPORT', $vars, $triggeredBy);
                if (($out['status'] ?? '') === 'SENT')    $totalSent++;
                elseif (($out['status'] ?? '') === 'SKIPPED') $totalSkipped++;
                else $totalFailed++;
            }
            return ['sent' => $totalSent, 'skipped' => $totalSkipped, 'failed' => $totalFailed];
        }, 'sendWeeklyDigest');
    }

    /**
     * Build the comprehensive WEEKLY_REPORT variable payload for a country.
     *
     * Covers (in email order):
     *   §1  Executive summary narrative
     *   §2  7-day vs previous-7-day primary screening comparison
     *   §3  Symptomatic rate and fever/temperature breakdown
     *   §4  Direction split (arrivals / departures / transit)
     *   §5  Top nationalities (5)
     *   §6  Top travel conveyances (AIR / LAND / SEA)
     *   §7  Alert breakdown by risk level + IHR 7-1-7 compliance
     *   §8  Secondary screening outcomes (dispositions)
     *   §9  Top 5 suspected diseases this week
     *   §10 POE activity table (all POEs: screenings, symptomatic %, alerts)
     *   §11 Silent POEs (no activity in 7 days)
     *   §12 Officer productivity (active vs dormant)
     *   §13 Overdue follow-ups still blocking case closure
     *   §14 Aggregated report submission compliance
     */
    public static function buildWeeklyDigestVars(string $cc): array
    {
        $now     = now();
        $since7  = $now->copy()->subDays(7)->format('Y-m-d H:i:s');
        $since14 = $now->copy()->subDays(14)->format('Y-m-d H:i:s');
        $endPrev = $now->copy()->subDays(7)->format('Y-m-d H:i:s');
        $today   = $now->format('Y-m-d');
        $weekEnd   = $now->format('Y-m-d H:i');
        $weekStart = $now->copy()->subDays(7)->format('Y-m-d H:i');

        // §2 Primary screening volumes
        $ps7  = (int) DB::table('primary_screenings')->where('country_code', $cc)->where('captured_at', '>=', $since7)->whereNull('deleted_at')->count();
        $ps14 = (int) DB::table('primary_screenings')->where('country_code', $cc)->where('captured_at', '>=', $since14)->where('captured_at', '<', $endPrev)->whereNull('deleted_at')->count();
        $psDelta = $ps14 > 0 ? round((($ps7 - $ps14) / $ps14) * 100, 1) : 0;
        $psDeltaTxt = $psDelta >= 0 ? "+{$psDelta}%" : "{$psDelta}%";

        $symp7  = (int) DB::table('primary_screenings')->where('country_code', $cc)->where('captured_at', '>=', $since7)->where('symptoms_present', 1)->whereNull('deleted_at')->count();
        $sympRate = $ps7 > 0 ? round(($symp7 / $ps7) * 100, 1) : 0;
        $fever7 = (int) DB::table('primary_screenings')->where('country_code', $cc)->where('captured_at', '>=', $since7)->whereNotNull('temperature_value')->where('temperature_value', '>=', 37.5)->whereNull('deleted_at')->count();
        $feverRate = $ps7 > 0 ? round(($fever7 / $ps7) * 100, 1) : 0;

        // §4 Direction split
        $directions = DB::table('primary_screenings')->selectRaw('traveler_direction, COUNT(*) AS n')
            ->where('country_code', $cc)->where('captured_at', '>=', $since7)->whereNull('deleted_at')
            ->groupBy('traveler_direction')->pluck('n', 'traveler_direction');
        $dEntry   = (int) ($directions['ENTRY']   ?? $directions['ARRIVAL']   ?? $directions['ARRIVING'] ?? 0);
        $dExit    = (int) ($directions['EXIT']    ?? $directions['DEPARTURE']  ?? $directions['DEPARTING'] ?? 0);
        $dTransit = (int) ($directions['TRANSIT'] ?? 0);

        // §5 Top nationalities
        $natRows = DB::table('secondary_screenings')->selectRaw('traveler_nationality_country_code AS nat, COUNT(*) AS n')
            ->where('country_code', $cc)->where('opened_at', '>=', $since7)->whereNull('deleted_at')->whereNotNull('traveler_nationality_country_code')
            ->groupBy('nat')->orderByDesc('n')->limit(5)->get();
        $natHtml = $natRows->isEmpty() ? '<p style="margin:0;color:#64748B;font-size:12px;">No secondary screenings.</p>'
            : '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
              . '<tr style="background:#F1F5F9;"><th style="text-align:left;padding:5px 8px;">Nationality</th><th style="text-align:right;padding:5px 8px;">Cases</th></tr>'
              . $natRows->map(fn($r) => '<tr style="border-bottom:1px solid #F1F5F9;"><td style="padding:5px 8px;">' . htmlspecialchars((string)$r->nat,ENT_QUOTES,'UTF-8') . '</td><td style="text-align:right;padding:5px 8px;font-weight:600;">' . (int)$r->n . '</td></tr>')->implode('')
              . '</table>';

        // §6 Conveyance
        $convRows = DB::table('secondary_screenings')->selectRaw('COALESCE(conveyance_type,"UNKNOWN") AS cv, COUNT(*) AS n')
            ->where('country_code', $cc)->where('opened_at', '>=', $since7)->whereNull('deleted_at')
            ->groupBy('cv')->orderByDesc('n')->get();
        $convHtml = $convRows->isEmpty() ? '<p style="margin:0;color:#64748B;font-size:12px;">No data.</p>'
            : '<ul style="margin:0;padding-left:16px;font-size:12px;">'
              . $convRows->map(fn($r) => '<li style="margin:3px 0;">' . htmlspecialchars((string)$r->cv,ENT_QUOTES,'UTF-8') . ' — <strong>' . (int)$r->n . '</strong></li>')->implode('')
              . '</ul>';

        // §7 Alert breakdown + 7-1-7 compliance
        $totalAlerts  = (int) DB::table('alerts')->where('country_code',$cc)->where('created_at','>=',$since7)->whereNull('deleted_at')->count();
        $ackWithin24  = (int) DB::table('alerts')->where('country_code',$cc)->where('created_at','>=',$since7)->whereNull('deleted_at')
            ->whereNotNull('acknowledged_at')->whereRaw('TIMESTAMPDIFF(HOUR, created_at, acknowledged_at) <= 24')->count();
        $closedWithin7d = (int) DB::table('alerts')->where('country_code',$cc)->where('created_at','>=',$since7)->whereNull('deleted_at')
            ->where('status','CLOSED')->whereRaw('TIMESTAMPDIFF(DAY, created_at, closed_at) <= 7')->count();
        $compRate = $totalAlerts > 0 ? round(($ackWithin24 / $totalAlerts) * 100) : 100;
        $alertByRisk = DB::table('alerts')->selectRaw('risk_level, status, COUNT(*) AS n')
            ->where('country_code',$cc)->where('created_at','>=',$since7)->whereNull('deleted_at')
            ->groupBy('risk_level','status')->orderByRaw("FIELD(risk_level,'CRITICAL','HIGH','MEDIUM','LOW')")->get();
        $alertTableHtml = $alertByRisk->isEmpty()
            ? '<p style="margin:0;color:#047857;font-size:12px;">No new alerts this week.</p>'
            : '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
              . '<tr style="background:#F1F5F9;"><th style="text-align:left;padding:5px 8px;">Risk</th><th style="text-align:left;padding:5px 8px;">Status</th><th style="text-align:right;padding:5px 8px;">Count</th></tr>'
              . $alertByRisk->map(fn($r) => '<tr style="border-bottom:1px solid #F1F5F9;"><td style="padding:5px 8px;font-weight:600;color:' . match(strtoupper((string)$r->risk_level)){'CRITICAL'=>'#B91C1C','HIGH'=>'#B45309',default=>'#1D4ED8'} . ';">' . htmlspecialchars(static::humaniseEnumValue(strtoupper((string)$r->risk_level)),ENT_QUOTES,'UTF-8') . '</td><td style="padding:5px 8px;">' . htmlspecialchars(static::humaniseEnumValue(strtoupper((string)$r->status)),ENT_QUOTES,'UTF-8') . '</td><td style="text-align:right;padding:5px 8px;font-weight:700;">' . (int)$r->n . '</td></tr>')->implode('')
              . '</table>';

        // §8 Disposition breakdown
        $dispRows = DB::table('secondary_screenings')->selectRaw('COALESCE(final_disposition,"PENDING") AS d, COUNT(*) AS n')
            ->where('country_code',$cc)->where('opened_at','>=',$since7)->whereNull('deleted_at')
            ->groupBy('d')->orderByDesc('n')->get();
        $dispHtml = $dispRows->isEmpty() ? '<p style="margin:0;color:#64748B;font-size:12px;">No secondary screenings closed this week.</p>'
            : '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
              . '<tr style="background:#F1F5F9;"><th style="text-align:left;padding:5px 8px;">Disposition</th><th style="text-align:right;padding:5px 8px;">Cases</th></tr>'
              . $dispRows->map(fn($r) => '<tr style="border-bottom:1px solid #F1F5F9;"><td style="padding:5px 8px;">' . htmlspecialchars(static::humaniseEnumValue(strtoupper((string)$r->d)),ENT_QUOTES,'UTF-8') . '</td><td style="text-align:right;padding:5px 8px;font-weight:600;">' . (int)$r->n . '</td></tr>')->implode('')
              . '</table>';

        // §9 Top diseases
        $diseaseRows = DB::table('secondary_suspected_diseases as sd')
            ->join('secondary_screenings as s', 's.id', '=', 'sd.secondary_screening_id')
            ->selectRaw('sd.disease_code, COUNT(*) AS n, AVG(sd.confidence) AS avg_conf')
            ->where('s.country_code', $cc)->where('s.opened_at', '>=', $since7)->whereNull('s.deleted_at')
            ->where('sd.rank_order', 1)->groupBy('sd.disease_code')->orderByDesc('n')->limit(8)->get();
        $diseaseHtml = $diseaseRows->isEmpty() ? '<p style="margin:0;color:#64748B;font-size:12px;">No disease signals this week.</p>'
            : '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
              . '<tr style="background:#F1F5F9;"><th style="text-align:left;padding:5px 8px;">Disease</th><th style="text-align:right;padding:5px 8px;">Cases</th><th style="text-align:right;padding:5px 8px;">Avg Confidence</th></tr>'
              . $diseaseRows->map(fn($r) => '<tr style="border-bottom:1px solid #F1F5F9;"><td style="padding:5px 8px;font-weight:600;">' . htmlspecialchars(static::humaniseDiseaseOrCode((string)$r->disease_code),ENT_QUOTES,'UTF-8') . '</td><td style="text-align:right;padding:5px 8px;">' . (int)$r->n . '</td><td style="text-align:right;padding:5px 8px;color:#64748B;">' . round((float)$r->avg_conf) . '%</td></tr>')->implode('')
              . '</table>';

        // §10 POE activity table
        $poeRows = DB::table('primary_screenings')->selectRaw('poe_code, COUNT(*) AS total, SUM(symptoms_present) AS symp, SUM(CASE WHEN temperature_value >= 37.5 THEN 1 ELSE 0 END) AS fever')
            ->where('country_code',$cc)->where('captured_at','>=',$since7)->whereNull('deleted_at')
            ->groupBy('poe_code')->orderByDesc('total')->get();
        $poeAlerts = DB::table('alerts')->selectRaw('poe_code, COUNT(*) AS n')
            ->where('country_code',$cc)->where('created_at','>=',$since7)->whereNull('deleted_at')
            ->groupBy('poe_code')->pluck('n','poe_code');
        $poeHtml = $poeRows->isEmpty() ? '<p style="margin:0;color:#64748B;font-size:12px;">No POE activity recorded.</p>'
            : '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
              . '<tr style="background:#F1F5F9;"><th style="text-align:left;padding:5px 8px;">POE</th><th style="text-align:right;padding:5px 8px;">Screened</th><th style="text-align:right;padding:5px 8px;">Symptomatic</th><th style="text-align:right;padding:5px 8px;">Fever</th><th style="text-align:right;padding:5px 8px;">Alerts</th></tr>'
              . $poeRows->map(function($r) use ($poeAlerts) {
                    $sympRate = (int)$r->total > 0 ? round(((int)$r->symp / (int)$r->total) * 100) : 0;
                    $al = (int) ($poeAlerts[$r->poe_code] ?? 0);
                    $alCell = $al > 0 ? "<span style='color:#B91C1C;font-weight:700;'>{$al}</span>" : '0';
                    $poeName = static::resolvePoeName((string)$r->poe_code);
                    $poeLabel = $poeName !== '' ? $poeName : (string)$r->poe_code;
                    return '<tr style="border-bottom:1px solid #F1F5F9;">'
                        . '<td style="padding:5px 8px;font-weight:600;">' . htmlspecialchars($poeLabel,ENT_QUOTES,'UTF-8') . '</td>'
                        . '<td style="text-align:right;padding:5px 8px;">' . (int)$r->total . '</td>'
                        . '<td style="text-align:right;padding:5px 8px;">' . (int)$r->symp . ' (' . $sympRate . '%)</td>'
                        . '<td style="text-align:right;padding:5px 8px;">' . (int)$r->fever . '</td>'
                        . '<td style="text-align:right;padding:5px 8px;">' . $alCell . '</td></tr>';
                })->implode('') . '</table>';

        // §11 Silent POEs
        $activePoes = DB::table('primary_screenings')->where('country_code',$cc)->where('captured_at','>=',$since7)->whereNull('deleted_at')->distinct()->pluck('poe_code')->all();
        $allPoes    = DB::table('poe_notification_contacts')->where('country_code',$cc)->whereNotNull('poe_code')->where('is_active',1)->whereNull('deleted_at')->distinct()->pluck('poe_code')->all();
        $silentPoes = array_values(array_diff($allPoes, $activePoes));
        $silentHtml = empty($silentPoes)
            ? '<p style="margin:0;color:#047857;font-size:12px;">Every registered POE submitted activity this week.</p>'
            : '<ul style="margin:0;padding-left:16px;font-size:12px;">' . implode('', array_map(function($p) {
                $name = static::resolvePoeName((string)$p);
                $label = $name !== '' ? $name : (string)$p;
                return '<li style="color:#B91C1C;margin:3px 0;">' . htmlspecialchars($label,ENT_QUOTES,'UTF-8') . '</li>';
            }, $silentPoes)) . '</ul>';

        // §12 Officer productivity
        $activeOfficers  = (int) DB::table('users')->where('country_code',$cc)->where('is_active',1)->whereNotNull('last_activity_at')->where('last_activity_at','>=',$since7)->count();
        $dormantOfficers = (int) DB::table('users')->where('country_code',$cc)->where('is_active',1)->where(fn($q) => $q->whereNull('last_login_at')->orWhere('last_login_at','<',$since14))->count();
        $totalOfficers   = (int) DB::table('users')->where('country_code',$cc)->where('is_active',1)->count();

        // §13 Blocking overdue followups
        $blockingFu = DB::table('alert_followups as f')
            ->leftJoin('alerts as a','a.id','=','f.alert_id')
            ->where('f.country_code',$cc)->whereNull('f.deleted_at')
            ->whereNotIn('f.status',['COMPLETED','NOT_APPLICABLE'])
            ->whereNotNull('f.due_at')->where('f.due_at','<',now())
            ->where('f.blocks_closure',1)
            ->select('f.action_label','f.due_at','a.alert_code','a.risk_level','f.poe_code')
            ->orderBy('f.due_at')->limit(15)->get();
        $blockFuHtml = $blockingFu->isEmpty()
            ? '<p style="margin:0;color:#047857;font-size:12px;">No blocking follow-ups overdue.</p>'
            : '<ul style="margin:0;padding-left:16px;font-size:12px;">' . $blockingFu->map(fn($f) =>
                '<li style="margin:4px 0;"><strong>' . htmlspecialchars((string)$f->alert_code,ENT_QUOTES,'UTF-8') . '</strong> · ' . htmlspecialchars((string)$f->action_label,ENT_QUOTES,'UTF-8') . ' · due ' . htmlspecialchars((string)$f->due_at,ENT_QUOTES,'UTF-8') . ' · <span style="color:#B91C1C;">' . htmlspecialchars(static::humaniseEnumValue(strtoupper((string)$f->risk_level)),ENT_QUOTES,'UTF-8') . ' risk · blocks closure</span></li>'
            )->implode('') . '</ul>';

        // §14 Aggregated report compliance
        $aggPoes = (int) DB::table('aggregated_submissions')->where('country_code',$cc)->where('created_at','>=',$since7)->whereNull('deleted_at')->distinct()->count('poe_code');

        // Executive narrative
        $totalSS = (int) DB::table('secondary_screenings')->where('country_code',$cc)->where('opened_at','>=',$since7)->whereNull('deleted_at')->count();
        $stuckAlerts = (int) DB::table('alerts')->where('country_code',$cc)->where('status','OPEN')->whereNull('deleted_at')
            ->whereRaw("TIMESTAMPDIFF(HOUR, created_at, NOW()) > 24")->count();
        $narrative = "This weekly scorecard covers {$weekStart} to {$weekEnd} for Uganda POE surveillance. "
            . "{$ps7} travellers were screened at all POEs (" . ($psDelta >= 0 ? "up" : "down") . " {$psDelta}% vs. previous 7 days). "
            . "Of these, {$symp7} ({$sympRate}%) presented symptoms and {$fever7} ({$feverRate}%) had fever ≥37.5°C. "
            . "{$totalAlerts} new IHR alerts were raised; {$ackWithin24} acknowledged within 24 hours ({$compRate}% IHR-1 compliance). "
            . "{$stuckAlerts} alerts remain open past the 24-hour response window. "
            . count($silentPoes) > 0 ? count($silentPoes) . " POE(s) submitted no activity this week — follow up required. " : "All registered POEs reported activity. "
            . "{$dormantOfficers} of {$totalOfficers} officers have not logged in for 14+ days.";

        // PRESERVED: multi-country name lookup
        return [
            'country_code'       => $cc,
            'country_name'       => match(strtoupper($cc)) {
                'UG' => 'Uganda', 'RW' => 'Rwanda', 'ZM' => 'Zambia',
                'MW' => 'Malawi', 'ST', 'STP' => 'São Tomé and Príncipe', default => $cc,
            },
            'week_start'         => $weekStart,
            'week_end'           => $weekEnd,
            'now'                => $now->format('Y-m-d H:i'),
            'now_date'           => $today,
            'console_url'        => \App\Services\AdminLinks::alertsHub(),
            'action_url'         => \App\Services\AdminLinks::dashboard(),
            'dashboard_url'      => \App\Services\AdminLinks::dashboard(),
            'hub_url'            => \App\Services\AdminLinks::alertsHub(),
            'app_url'            => \App\Services\AdminLinks::base(),

            // Narrative
            'executive_summary'  => $narrative,

            // Primary screening metrics
            'screenings_7d'      => (string) $ps7,
            'screenings_prev_7d' => (string) $ps14,
            'screenings_delta'   => $psDeltaTxt,
            'symptomatic_7d'     => (string) $symp7,
            'symptomatic_rate'   => "{$sympRate}%",
            'fever_7d'           => (string) $fever7,
            'fever_rate'         => "{$feverRate}%",

            // Direction
            'direction_entry'    => (string) $dEntry,
            'direction_exit'     => (string) $dExit,
            'direction_transit'  => (string) $dTransit,

            // Alert + IHR compliance
            'alerts_7d'          => (string) $totalAlerts,
            'alerts_acked_24h'   => (string) $ackWithin24,
            'alerts_closed_7d'   => (string) $closedWithin7d,
            'ihr_1day_compliance'=> "{$compRate}%",
            'stuck_alerts'       => (string) $stuckAlerts,

            // Secondary screenings
            'secondary_cases_7d' => (string) $totalSS,

            // Officer productivity
            'officers_active_7d' => (string) $activeOfficers,
            'officers_dormant'   => (string) $dormantOfficers,
            'officers_total'     => (string) $totalOfficers,

            // Aggregated reports
            'agg_poes_submitted' => (string) $aggPoes,
            'silent_poes_count'  => (string) count($silentPoes),

            // HTML fragments
            'alert_table_html'   => $alertTableHtml,
            'disposition_html'   => $dispHtml,
            'disease_html'       => $diseaseHtml,
            'nationality_html'   => $natHtml,
            'conveyance_html'    => $convHtml,
            'poe_activity_html'  => $poeHtml,
            'silent_poes_html'   => $silentHtml,
            'blocking_fu_html'   => $blockFuHtml,
        ];
    }

    /**
     * Retry FAILED notification_log rows that still have retry_count < 4.
     */
    public static function retryFailed(string $triggeredBy = 'CRON:retry'): array
    {
        return static::safely(function () use ($triggeredBy) {
            $rows = DB::table('notification_log')
                ->where('status', 'FAILED')
                ->where('retry_count', '<', 4)
                ->orderBy('created_at')
                ->limit(100)
                ->get();

            $retried = 0; $stillFailed = 0;
            foreach ($rows as $row) {
                if (empty($row->to_email) || empty($row->body_full)) {
                    DB::table('notification_log')->where('id', $row->id)->update([
                        'status' => 'SKIPPED',
                        'error_message' => 'Missing to_email or body for retry',
                        'updated_at' => now(),
                    ]);
                    continue;
                }
                try {
                    $subject = (string) $row->subject;
                    $body = (string) $row->body_full;
                    $textBody = static::htmlToText($body);
                    Mail::send([], [], function ($m) use ($row, $subject, $body, $textBody) {
                        $m->to($row->to_email)
                            ->subject($subject)
                            ->html($body)
                            ->text($textBody);
                        try {
                            $headers = $m->getHeaders();
                            $headers->addTextHeader('X-Auto-Response-Suppress', 'OOF, AutoReply');
                            $headers->addTextHeader('Auto-Submitted', 'auto-generated');
                        } catch (\Throwable $he) { /* best-effort */ }
                    });
                    DB::table('notification_log')->where('id', $row->id)->update([
                        'status'        => 'SENT',
                        'sent_at'       => now(),
                        'error_message' => null,
                        'retry_count'   => (int) $row->retry_count + 1,
                        'triggered_by'  => $triggeredBy,
                        'updated_at'    => now(),
                    ]);
                    $retried++;
                } catch (Throwable $e) {
                    DB::table('notification_log')->where('id', $row->id)->update([
                        'retry_count'   => (int) $row->retry_count + 1,
                        'error_message' => mb_substr($e->getMessage(), 0, 500),
                        'updated_at'    => now(),
                    ]);
                    $stillFailed++;
                }
            }
            return ['retried' => $retried, 'still_failed' => $stillFailed, 'candidates' => $rows->count()];
        }, 'retryFailed');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  INTERNAL — resolution + rendering + send + log
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Expand a routing level into the ladder that should receive the event.
     *   DISTRICT → [DISTRICT, PHEOC, NATIONAL]
     *   PHEOC    → [PHEOC, NATIONAL]
     *   NATIONAL → [NATIONAL, WHO]
     *   WHO      → [WHO]
     */
    private static function ladderFrom(string $level): array
    {
        $all = ['POE', 'DISTRICT', 'PHEOC', 'NATIONAL'];
        if (strtoupper($level) === 'WHO') {
            return ['POE', 'DISTRICT', 'PHEOC', 'NATIONAL', 'WHO'];
        }
        return $all;
    }

    /**
     * Pull active contacts across a ladder of levels that match the alert's
     * geographic scope AND the appropriate receives_* flag.
     *
     * @param array $levels  list of levels to union across
     * @param string|null $flagOverride  specific receives_* column to require
     */
    private static function resolveContactsForAlert(object $alert, array $levels, ?string $flagOverride = null)
    {
        $risk = strtolower((string) ($alert->risk_level ?? 'high'));
        $flag = $flagOverride ?? "receives_{$risk}";
        $tierOne = is_string($alert->ihr_tier ?? null) && str_contains($alert->ihr_tier, 'TIER_1');
        $tierTwo = is_string($alert->ihr_tier ?? null) && str_contains($alert->ihr_tier, 'TIER_2');

        $q = DB::table('poe_notification_contacts')
            ->whereIn('level', $levels)
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->where('country_code', $alert->country_code);

        if (in_array($flag, ['receives_critical', 'receives_high', 'receives_medium', 'receives_low',
                             'receives_breach_alerts', 'receives_followup_reminders'], true)) {
            $q->where($flag, 1);
        }
        if ($tierOne) $q->where('receives_tier1', 1);
        if ($tierTwo) $q->where('receives_tier2', 1);

        return $q->orderByRaw("FIELD(level, 'POE','DISTRICT','PHEOC','NATIONAL','WHO')")
            ->orderBy('priority_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Scope-aware contact resolution when we don't have an alert object
     * (e.g. screening referral triggers).
     */
    private static function resolveContactsByScope(?string $countryCode, ?string $districtCode, ?string $poeCode, array $levels, string $flag)
    {
        $q = DB::table('poe_notification_contacts')
            ->whereIn('level', $levels)
            ->where('is_active', 1)
            ->whereNull('deleted_at');
        if ($countryCode) $q->where('country_code', $countryCode);
        if (in_array($flag, ['receives_critical', 'receives_high', 'receives_medium', 'receives_low'], true)) {
            $q->where($flag, 1);
        }
        return $q->orderByRaw("FIELD(level, 'POE','DISTRICT','PHEOC','NATIONAL','WHO')")
            ->orderBy('priority_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Render + send + log. Never throws.
     *
     * Anti-spam: checks notification_suppressions BEFORE send. If the same
     * (template, entity, contact) was sent within the suppression window
     * the call returns SKIPPED with reason — no SMTP traffic, no log row
     * other than the SKIPPED record so operators can audit it.
     */
    /**
     * Single-blast delivery — replaces the per-contact send() loop for every
     * dispatch path that fans out to multiple recipients.
     *
     * Executive directive (2026-05-05):
     *   ▸ ONE email per alert event — never N emails flooding inboxes.
     *   ▸ TO  = primaryToAddress() (national lead, resolved from env / config('country.admin_email'))
     *   ▸ CC  = every other resolved contact + the national OPS_CC roster, deduped
     *   ▸ BCC = never used (per executive directive)
     *
     * The loop preserves the existing audit trail by writing one notification_log
     * row per recipient (TO + each CC). Suppression checks remain per-contact so
     * a re-fire inside the dedup window still skips correctly.
     *
     * Returns: ['sent' => 0|1, 'cc_count' => int, 'recipients_logged' => int,
     *           'skipped' => int, 'failed' => 0|1, 'error' => ?string]
     */
    public static function sendUnifiedBlast(
        iterable $contacts,
        string $templateCode,
        array $vars,
        ?object $relatedEntity,
        string $triggeredBy,
        string $entityType = 'ALERT',
        ?int $entityId = null
    ): array {
        try {
            $tpl = DB::table('notification_templates')
                ->where('template_code', $templateCode)
                ->where('channel', 'EMAIL')
                ->where('is_active', 1)
                ->first();
            if (! $tpl) {
                return ['sent' => 0, 'cc_count' => 0, 'recipients_logged' => 0, 'skipped' => 0,
                        'failed' => 0, 'error' => "Template '$templateCode' not found"];
            }

            $vars = array_merge(\App\Services\AdminLinks::generalVars(), $vars);

            $subject  = static::render((string) $tpl->subject_template, $vars);
            $body     = static::render((string) $tpl->body_html_template, $vars);
            $textTpl  = (string) ($tpl->body_text_template ?? '');
            $textBody = $textTpl !== ''
                ? static::render($textTpl, $vars)
                : static::htmlToText($body);

            $ctaUrl   = (string) ($vars['action_url'] ?? $vars['warroom_url']
                ?? $vars['hub_url'] ?? \App\Services\AdminLinks::dashboard());
            $ctaLabel = $entityType === 'ALERT' && $entityId ? 'Open War Room' : 'Open Command Centre';
            $body     = \App\Services\AdminLinks::ensureCtaAppended($body, $ctaUrl, $ctaLabel);
            $textBody = $textBody . "\n\n" . $ctaLabel . ': ' . $ctaUrl;

            $primary    = mb_strtolower(trim(static::primaryToAddress()));
            $ccEmails   = [];
            $logTargets = [];
            $seen       = [$primary => true];

            // Sub-national + per-jurisdiction contacts → CC (with suppression check).
            foreach ($contacts as $c) {
                $email = trim((string) ($c->email ?? ($c->alternate_email ?? '')));
                if ($email === '' || ! self::isValidEmail($email)) continue;
                $low = mb_strtolower($email);
                if (isset($seen[$low])) continue;

                if ($entityId !== null && ($c->id ?? null) !== null) {
                    $supp = static::wasRecentlySent($templateCode, $entityType, (int) $entityId, (int) $c->id);
                    if ($supp !== null) {
                        static::log($c, $templateCode, $subject, $body, 'SKIPPED',
                            "Suppressed — last sent {$supp} min ago", $relatedEntity, $triggeredBy,
                            $entityType, $entityId);
                        continue;
                    }
                }

                $seen[$low]   = true;
                $ccEmails[]   = $email;
                $logTargets[] = $c;
            }

            // National OPS_CC roster — every transactional email reaches every
            // national contact in the same single blast.
            foreach (SentinelMail::OPS_CC as $addr) {
                $low = mb_strtolower(trim((string) $addr));
                if ($low === '' || isset($seen[$low])) continue;
                $seen[$low] = true;
                $ccEmails[] = $addr;
                $logTargets[] = (object) [
                    'id'              => null,
                    'email'           => $addr,
                    'phone'           => null,
                    'country_code'    => $relatedEntity->country_code  ?? null,
                    'district_code'   => $relatedEntity->district_code ?? null,
                    'poe_code'        => $relatedEntity->poe_code      ?? null,
                    'alternate_email' => null,
                ];
            }

            $primaryContact = (object) [
                'id'              => null,
                'email'           => static::primaryToAddress(),
                'phone'           => null,
                'country_code'    => $relatedEntity->country_code  ?? null,
                'district_code'   => $relatedEntity->district_code ?? null,
                'poe_code'        => $relatedEntity->poe_code      ?? null,
                'alternate_email' => null,
            ];

            try {
                $entityRef = 'poe-sentinel-' . bin2hex(random_bytes(8));
                $mailable  = new SentinelMail(
                    toAddress:      static::primaryToAddress(),
                    subjectLine:    $subject,
                    htmlBody:       $body,
                    textBody:       $textBody,
                    ccAddresses:    $ccEmails,
                    bccAddresses:   [], // executive directive — never BCC
                    replyToAddress: (config('mail.reply_to.address') ?: env('MAIL_REPLY_TO_ADDRESS')) ?: null,
                    replyToName:    (config('mail.reply_to.name')    ?: env('MAIL_REPLY_TO_NAME', '')) ?: null,
                    entityRefId:    $entityRef,
                );
                Mail::send($mailable);
            } catch (Throwable $e) {
                Log::info('[Mail:sendUnifiedBlast:fallback] subject=' . $subject . ' msg=' . $e->getMessage());
                static::log($primaryContact, $templateCode, $subject, $body, 'FAILED',
                    mb_substr($e->getMessage(), 0, 500), $relatedEntity, $triggeredBy,
                    $entityType, $entityId, null, null, $ccEmails);
                return ['sent' => 0, 'cc_count' => count($ccEmails), 'recipients_logged' => 0,
                        'skipped' => 0, 'failed' => 1, 'error' => $e->getMessage()];
            }

            // Audit trail — one log row for TO + each CC recipient.
            static::log($primaryContact, $templateCode, $subject, $body, 'SENT', null,
                $relatedEntity, $triggeredBy, $entityType, $entityId, null, null, $ccEmails);

            foreach ($logTargets as $c) {
                static::log($c, $templateCode, $subject, $body, 'SENT', null,
                    $relatedEntity, $triggeredBy, $entityType, $entityId, null, null, $ccEmails);
                if (($c->id ?? null) !== null) {
                    DB::table('poe_notification_contacts')->where('id', $c->id)
                        ->update(['last_notified_at' => now(), 'updated_at' => now()]);
                    if ($entityId !== null) {
                        static::recordSuppression($templateCode, $entityType, (int) $entityId, (int) $c->id);
                    }
                }
            }

            return [
                'sent'              => 1,
                'cc_count'          => count($ccEmails),
                'recipients_logged' => 1 + count($logTargets),
                'skipped'           => 0,
                'failed'            => 0,
                'error'             => null,
            ];
        } catch (Throwable $e) {
            Log::error('[NotificationDispatcher::sendUnifiedBlast] ' . $e->getMessage());
            return ['sent' => 0, 'cc_count' => 0, 'recipients_logged' => 0,
                    'skipped' => 0, 'failed' => 1, 'error' => $e->getMessage()];
        }
    }

    private static function send(object $contact, string $templateCode, array $vars, ?object $relatedEntity, string $triggeredBy, string $entityType = 'ALERT', ?int $entityId = null): array
    {
        try {
            $tpl = DB::table('notification_templates')
                ->where('template_code', $templateCode)
                ->where('channel', 'EMAIL')
                ->where('is_active', 1)
                ->first();
            if (! $tpl) {
                return static::log($contact, $templateCode, '(missing template)', '', 'SKIPPED',
                    "Template '$templateCode' not found", $relatedEntity, $triggeredBy, $entityType, $entityId);
            }

            $to = $contact->email ?: ($contact->alternate_email ?? null);
            if (! $to) {
                return static::log($contact, $templateCode, (string) $tpl->subject_template, '', 'SKIPPED',
                    'Contact has no email address', $relatedEntity, $triggeredBy, $entityType, $entityId);
            }

            // TEST-MODE whitelist gate REMOVED — production deployment. Every
            // notification now reaches its live recipient + OPS_CC national roster
            // regardless of NOTIFICATIONS_TEST_MODE / NOTIFICATIONS_TEST_WHITELIST
            // env vars. Disabling is intentional and matches the operational brief.

            // Anti-spam suppression check
            if ($entityId !== null && ($contact->id ?? null) !== null) {
                $supp = static::wasRecentlySent($templateCode, $entityType, (int) $entityId, (int) $contact->id);
                if ($supp !== null) {
                    return static::log($contact, $templateCode, (string) $tpl->subject_template, '', 'SKIPPED',
                        "Suppressed — last sent {$supp} min ago (window " . static::suppressionMinutes($templateCode) . " min)",
                        $relatedEntity, $triggeredBy, $entityType, $entityId);
                }
            }

            // Ensure the admin-panel URL bundle is available to every template.
            // CaseContextBuilder already injects these for alert-tied flows;
            // this covers the rest (digests, responder pings, etc.).
            $vars = array_merge(\App\Services\AdminLinks::generalVars(), $vars);

            $subject = static::render((string) $tpl->subject_template, $vars);
            $body    = static::render((string) $tpl->body_html_template, $vars);
            $textTpl = (string) ($tpl->body_text_template ?? '');
            $textBody = $textTpl !== ''
                ? static::render($textTpl, $vars)
                : static::htmlToText($body);

            // Append the standard "Open Command Centre" CTA if the template
            // body doesn't already link to the admin panel. The URL is the
            // most specific one available: War Room for alert-scoped emails,
            // Hub for digests and tripwires, dashboard as the fallback.
            $ctaUrl = (string) ($vars['action_url'] ?? $vars['warroom_url'] ?? $vars['hub_url'] ?? \App\Services\AdminLinks::dashboard());
            $ctaLabel = $entityType === 'ALERT' && $entityId ? 'Open War Room' : 'Open Command Centre';

            // §3.3 — when the contact recipient does NOT match an active user
            // account, swap the admin CTA URL for a single-use guest-token
            // landing URL. This stops sending strangers to /login forever and
            // grants them a scoped, audited, single-use read-only view of the
            // case file. We only mint tokens for ALERT-scoped emails (other
            // entity types — digest, intel — link to the hub which is always
            // login-gated and harmless for guests).
            if ($entityType === 'ALERT' && $entityId
                && self::isValidEmail((string) $to)
                && ! self::recipientHasUserAccount((string) $to)) {
                $issued = \App\Services\AlertGuestTokens::issue(
                    (int) $entityId,
                    (string) $to,
                    'view',
                    null,
                    null,
                    'auto-issued for non-account contact via NotificationDispatcher::send',
                );
                if (! empty($issued['url'])) {
                    $ctaUrl   = $issued['url'];
                    $ctaLabel = 'Open Read-Only Case View';
                }
            }
            $body = \App\Services\AdminLinks::ensureCtaAppended($body, $ctaUrl, $ctaLabel);
            $textBody = $textBody . "\n\n" . $ctaLabel . ': ' . $ctaUrl;
            $replyAddr = config('mail.reply_to.address') ?: env('MAIL_REPLY_TO_ADDRESS');
            $replyName = config('mail.reply_to.name')    ?: env('MAIL_REPLY_TO_NAME', '');

            try {
                // OPS_CC suppression: if the TO address is already one of the
                // hardcoded national contacts they are receiving this email
                // directly and must NOT be CC'd again. CC'ing all 14 on each
                // of 14 individual emails = 196 emails per alert run (the
                // "email blast" problem). We only add OPS_CC when the
                // recipient is NOT in the national list — i.e. when the TO is
                // a POE, district, or other contact who would otherwise be
                // invisible to the national team.
                $toNorm     = mb_strtolower(trim((string) $to));
                $opsNorm    = array_map('mb_strtolower', SentinelMail::OPS_CC);
                $toIsNational = in_array($toNorm, $opsNorm, true);
                // For report-type entity types there's no OPS_CC — broadcast
                // already handles the full subscriber list in BCC.
                $isReport   = in_array($entityType, ['REPORT','WEEKLY_REPORT','INTEL_REPORT'], true);
                $effectiveCC = ($toIsNational || $isReport) ? [] : SentinelMail::OPS_CC;

                $entityRef = 'poe-sentinel-' . bin2hex(random_bytes(8));
                $mailable = new SentinelMail(
                    toAddress:      (string) $to,
                    subjectLine:    $subject,
                    htmlBody:       $body,
                    textBody:       $textBody,
                    ccAddresses:    $effectiveCC,
                    bccAddresses:   [],
                    replyToAddress: $replyAddr ?: null,
                    replyToName:    $replyName ?: null,
                    entityRefId:    $entityRef,
                );
                Mail::send($mailable);

                $ccList = SentinelMail::OPS_CC;
                $row = static::log($contact, $templateCode, $subject, $body, 'SENT', null,
                    $relatedEntity, $triggeredBy, $entityType, $entityId,
                    null, null, $ccList);
                if ($contact->id ?? null) {
                    DB::table('poe_notification_contacts')->where('id', $contact->id)
                        ->update(['last_notified_at' => now(), 'updated_at' => now()]);
                    if ($entityId !== null) {
                        static::recordSuppression($templateCode, $entityType, (int) $entityId, (int) $contact->id);
                    }
                }
                return $row;
            } catch (Throwable $e) {
                Log::info("[Mail:fallback] to={$to} subject={$subject} msg=" . $e->getMessage());
                return static::log($contact, $templateCode, $subject, $body, 'FAILED',
                    mb_substr($e->getMessage(), 0, 500),
                    $relatedEntity, $triggeredBy, $entityType, $entityId,
                    null, null, SentinelMail::OPS_CC);
            }
        } catch (Throwable $e) {
            Log::error('[NotificationDispatcher::send] ' . $e->getMessage());
            return ['status' => 'FAILED', 'error' => $e->getMessage()];
        }
    }

    /**
     * Returns minutes since the last send if the (template, entity, contact)
     * triple is inside its suppression window; null if eligible to send.
     */
    private static function wasRecentlySent(string $templateCode, string $entityType, int $entityId, int $contactId): ?int
    {
        $row = DB::table('notification_suppressions')
            ->where('template_code', $templateCode)
            ->where('related_entity_type', $entityType)
            ->where('related_entity_id', $entityId)
            ->where('contact_id', $contactId)
            ->orderByDesc('last_sent_at')
            ->first();
        if (! $row) return null;
        $minutesSince = (int) round((time() - strtotime((string) $row->last_sent_at)) / 60);
        $window = static::suppressionMinutes($templateCode);
        return $minutesSince < $window ? $minutesSince : null;
    }

    private static function suppressionMinutes(string $templateCode): int
    {
        return self::SUPPRESSION_MINUTES[$templateCode] ?? self::DEFAULT_SUPPRESSION_MINUTES;
    }

    /**
     * Read-only accessors for the admin Governance surface (gov-reminders).
     * Exposed so the admin controller can render the per-template
     * suppression-window dial without hardcoding a parallel copy of the
     * map — preserves the "one source of truth" invariant.
     */
    public static function suppressionMinutesMap(): array
    {
        return self::SUPPRESSION_MINUTES;
    }

    public static function defaultSuppressionMinutes(): int
    {
        return self::DEFAULT_SUPPRESSION_MINUTES;
    }

    /**
     * Upsert the suppression row after a successful send.
     */
    private static function recordSuppression(string $templateCode, string $entityType, int $entityId, int $contactId): void
    {
        try {
            $now = now();
            DB::statement(
                'INSERT INTO notification_suppressions
                  (template_code, related_entity_type, related_entity_id, contact_id, last_sent_at, send_count, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE last_sent_at = VALUES(last_sent_at), send_count = send_count + 1, updated_at = VALUES(updated_at)',
                [$templateCode, $entityType, $entityId, $contactId, $now, $now, $now],
            );
        } catch (Throwable $e) {
            Log::warning('[NotificationDispatcher::recordSuppression] ' . $e->getMessage());
        }
    }

    /**
     * Build the variable bag for the ALERT_CASE_FILE template. Walks the
     * full secondary case context (case row, alerts row, primary screening,
     * actions, samples, suspected diseases, exposures) and produces 12+
     * structured fields the spec requires.
     */
    private static function buildCaseFileVars(object $case, ?object $alert): array
    {
        $opener = $case->opened_by_user_id
            ? DB::table('users')->where('id', $case->opened_by_user_id)->first()
            : null;

        $actions = DB::table('secondary_actions')
            ->where('secondary_screening_id', $case->id)
            ->orderByDesc('id')->limit(8)->get();
        $actionsList = $actions->isEmpty()
            ? 'No actions logged yet.'
            : $actions->map(fn ($a) => '• ' . ($a->action_label ?? $a->action_code ?? 'action'))->implode("\n");

        $topDisease = DB::table('secondary_suspected_diseases')
            ->where('secondary_screening_id', $case->id)
            ->orderBy('rank_order')->first();
        $samplesCount = DB::table('secondary_samples')
            ->where('secondary_screening_id', $case->id)
            ->whereNull('deleted_at')->count();

        $tier = is_string($alert->ihr_tier ?? null) ? $alert->ihr_tier : 'none';
        $whyParts = [];
        if (str_contains((string) $tier, 'TIER_1')) $whyParts[] = 'IHR Tier 1 always-notifiable disease — single case requires WHO notification within 24h.';
        if (str_contains((string) $tier, 'TIER_2')) $whyParts[] = 'IHR Tier 2 — Annex 2 4-criteria assessment required (notify WHO if ≥ 2/4 met).';
        if ($topDisease) $whyParts[] = 'Top suspected: ' . (string) ($topDisease->disease_name ?? $topDisease->disease_code ?? 'unknown') . '.';
        if (($alert->risk_level ?? '') === 'CRITICAL') $whyParts[] = 'Risk level CRITICAL — full clinical response + contact tracing required.';
        if (empty($whyParts)) $whyParts[] = 'Case has reached an actionable disposition that requires stakeholder awareness and structured follow-up per IDSR.';

        $owner = (string) ($case->case_owner_role ?? $alert->routed_to_level ?? 'DISTRICT');
        $deadlineHrs = ($alert->risk_level ?? '') === 'CRITICAL' ? 4 : 24;
        $deadline = now()->addHours($deadlineHrs)->format('Y-m-d H:i') . ' UTC';

        $nextActionLabel = match (strtoupper((string) ($case->final_disposition ?? ''))) {
            'CONFIRMED_CASE'  => 'Confirm laboratory diagnosis + notify WHO IHR focal point.',
            'PROBABLE_CASE'   => 'Collect lab samples + isolate + start contact tracing.',
            'SUSPECTED_CASE'  => 'Hold for medical review + collect lab samples.',
            'REFERRED'        => 'Confirm receiving facility + transfer custody.',
            default           => 'Acknowledge in POE Sentinel + assign clinical review.',
        };

        return [
            'alert_title'       => (string) ($alert->alert_title ?? 'Secondary case requires attention'),
            'alert_code'        => (string) ($alert->alert_code ?? 'CASE-' . $case->id),
            'poe_code'          => (string) ($case->poe_code ?? ''),
            'district_code'     => (string) ($case->district_code ?? ''),
            'country_code'      => (string) ($case->country_code ?? ''),
            'opened_at'         => (string) ($case->opened_at ?? ''),
            'alert_created_at'  => (string) ($alert->created_at ?? ''),
            'risk_level'        => (string) ($alert->risk_level ?? $case->risk_level ?? 'HIGH'),
            'ihr_tier'          => $tier,
            'routed_to_level'   => (string) ($alert->routed_to_level ?? 'DISTRICT'),
            'case_status'       => (string) ($case->case_status ?? 'OPEN'),
            'final_disposition' => (string) ($case->final_disposition ?? 'PENDING'),
            'secondary_case_id'   => (string) ($case->id ?? ''),
            'secondary_case_uuid' => (string) ($case->client_uuid ?? ''),
            'traveler_label'   => trim(($case->traveler_full_name ? (string) $case->traveler_full_name : 'Anonymous')
                                     . ($case->traveler_gender ? ' · ' . (string) $case->traveler_gender : '')),
            'opened_by_name'   => $opener ? ((string) ($opener->full_name ?? $opener->username ?? ('user#' . $opener->id))) : 'Unknown',
            'owner_role'       => $owner,
            'summary_what'     => 'Secondary screening case ' . ($case->id ? '#' . $case->id : '') . ' has reached disposition '
                                  . (string) ($case->final_disposition ?? 'IN_PROGRESS') . ' at ' . (string) ($case->poe_code ?? 'unknown POE')
                                  . '. ' . ($topDisease ? 'Top suspected disease: ' . (string) ($topDisease->disease_name ?? $topDisease->disease_code) . '.' : '')
                                  . ($samplesCount > 0 ? ' ' . $samplesCount . ' lab sample(s) collected.' : ''),
            'why_it_matters'   => implode(' ', $whyParts),
            'actions_taken'    => $actionsList,
            'next_action_label' => $nextActionLabel,
            'next_action_body' => 'Coordinate with ' . $owner . '-level health authorities. Document acknowledgement + activate the relevant RTSL early-response actions in the POE Sentinel intelligence hub.',
            'next_action_owner' => $owner,
            'next_action_deadline' => $deadline,
        ];
    }

    /**
     * Render mustache tokens. Two forms:
     *   {{{html_key}}}  → inserts the value verbatim (for pre-rendered HTML
     *                      fragments produced by CaseContextBuilder).
     *   {{key}}         → HTML-escaped substitution (default, XSS-safe).
     * Triple-brace MUST be processed first so double-brace does not consume it.
     *
     * Every $vars array is run through polishVarsForDisplay() first so raw
     * enum codes (HIGH/CRITICAL/CLOSED/DISTRICT/VHF_HIGH_CONFIDENCE/etc.) and
     * raw POE codes never reach a recipient inbox. Templates stored in the DB
     * stay untouched — humanisation happens at render time.
     */
    private static function render(string $tpl, array $vars): string
    {
        $vars = static::polishVarsForDisplay($vars);
        $out = preg_replace_callback('/\{\{\{\s*([a-z0-9_]+)\s*\}\}\}/i', function ($m) use ($vars) {
            return (string) ($vars[$m[1]] ?? '');
        }, $tpl) ?? $tpl;
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($vars) {
            $v = $vars[$m[1]] ?? '';
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        }, $out) ?? $out;
    }

    /**
     * Executive-grade humanisation pass over the template variable bag.
     *
     * Goal: an executive reading the inbox MUST see plain English with every
     * fact intact — never a raw enum code (HIGH, CRITICAL, DISTRICT, OPEN),
     * a snake_case label (SEVERE_ACUTE_RESPIRATORY_INFECTION), a placeholder
     * `[]` for an empty list, or a POE code where the POE name belongs.
     *
     * Rules:
     *   1. Known enum keys (risk_level, status, routed_to_level, gender, …)
     *      get their value replaced with a friendly label. Templates that
     *      reference {{risk_level}} therefore render "Critical" not "CRITICAL".
     *   2. POE code → POE name (resolved once per request, statically cached).
     *      The raw {{poe_code}} placeholder now renders the POE's display name;
     *      the underlying alert row is untouched.
     *   3. Disease codes (top_disease_code, alert_title, syndrome) get
     *      executive phrasing — "Viral haemorrhagic fever — suspected" instead
     *      of "VHF_HIGH_CONFIDENCE", "Severe acute respiratory infection"
     *      instead of "SEVERE_ACUTE_RESPIRATORY_INFECTION".
     *   4. Empty arrays / null / placeholder strings render as an em-dash.
     *   5. Any remaining UPPER_SNAKE_CASE value at any key is sentence-cased
     *      so a forgotten enum never reaches an inbox in raw form.
     *
     * Idempotent — calling twice returns the same shape.
     */
    private static function polishVarsForDisplay(array $vars): array
    {
        if (!empty($vars['__polished'])) return $vars;

        $RISK = [
            'CRITICAL' => 'Critical', 'HIGH' => 'High',
            'MEDIUM'   => 'Medium',   'LOW'  => 'Low',
        ];
        $STATUS = [
            'OPEN'         => 'Open',
            'ACKNOWLEDGED' => 'Acknowledged',
            'CLOSED'       => 'Closed',
            'REOPENED'     => 'Re-opened',
            'CANCELLED'    => 'Cancelled',
            'OVERDUE'      => 'Overdue',
        ];
        $ROUTED = [
            'DISTRICT' => 'District', 'PHEOC' => 'PHEOC (regional)',
            'NATIONAL' => 'National', 'POE'   => 'Point of entry',
        ];
        $GENDER = [
            'MALE' => 'Male', 'FEMALE' => 'Female',
            'OTHER' => 'Other', 'UNKNOWN' => 'Unknown',
        ];
        $TRAVEL = [
            'ARRIVAL' => 'Arrival', 'DEPARTURE' => 'Departure',
            'TRANSIT' => 'Transit', 'IN' => 'Arrival', 'OUT' => 'Departure',
        ];
        $YESNO = [
            'YES' => 'Yes', 'NO' => 'No', 'UNKNOWN' => 'Unknown',
            'TRUE' => 'Yes', 'FALSE' => 'No', '1' => 'Yes', '0' => 'No',
        ];
        $IHR = [
            'TIER_1_ALWAYS_NOTIFIABLE'  => 'IHR Tier 1 — always notifiable',
            'TIER_2_ANNEX_2_ASSESSMENT' => 'IHR Tier 2 — Annex 2 assessment',
            'NONE'                      => 'Not applicable',
            'SYNDROMIC'                 => 'Syndromic surveillance',
        ];
        $CONVEY = [
            'AIR' => 'Air', 'LAND' => 'Land', 'SEA' => 'Sea', 'LAKE' => 'Lake', 'RAIL' => 'Rail',
        ];

        $byField = [
            'risk_level'             => $RISK,
            'alert_risk_level'       => $RISK,
            'status'                 => $STATUS,
            'alert_status'           => $STATUS,
            'case_status'            => $STATUS,
            'routed_to_level'        => $ROUTED,
            'route_level'            => $ROUTED,
            'level'                  => $ROUTED,
            'traveler_gender'        => $GENDER,
            'gender'                 => $GENDER,
            'travel_type'            => $TRAVEL,
            'direction'              => $TRAVEL,
            'has_symptoms'           => $YESNO,
            'fever_at_screening'     => $YESNO,
            'ihr_tier'               => $IHR,
            'disease_ihr_tier'       => $IHR,
            'conveyance_type'        => $CONVEY,
        ];

        foreach ($byField as $key => $map) {
            if (!isset($vars[$key])) continue;
            $val = $vars[$key];
            // Defensive — only enum-style scalars get the friendly-label swap.
            // Arrays / objects fall through to the empty-placeholder pass below.
            if (!is_string($val) && !is_int($val) && !is_bool($val)) continue;
            $upper = strtoupper(trim((string) $val));
            if (isset($map[$upper])) $vars[$key] = $map[$upper];
        }

        // POE code → POE name. Static cache so repeated renders within a
        // request hit the DB only once per POE.
        static $poeNameCache = [];
        $poeCode = (string) ($vars['poe_code'] ?? '');
        if ($poeCode !== '') {
            if (!array_key_exists($poeCode, $poeNameCache)) {
                try {
                    $poeNameCache[$poeCode] = (string) (DB::table('geo_poes')
                        ->where('poe_code', $poeCode)
                        ->whereNull('deleted_at')
                        ->value('poe_name') ?? '');
                } catch (\Throwable) {
                    $poeNameCache[$poeCode] = '';
                }
            }
            $name = $poeNameCache[$poeCode];
            if ($name !== '') {
                // Show the friendly name in {{poe_code}} substitutions and also
                // expose {{poe_name}} for any template that already uses it.
                $vars['poe_name'] = $name;
                $vars['poe_code'] = $name;
                $vars['poe_display'] = $name;
            }
        }
        if (empty($vars['poe_name']) && $poeCode !== '') {
            $vars['poe_name'] = $poeCode;
        }

        // District code → district name.
        static $districtNameCache = [];
        $distCode = (string) ($vars['district_code'] ?? '');
        if ($distCode !== '') {
            if (!array_key_exists($distCode, $districtNameCache)) {
                try {
                    $districtNameCache[$distCode] = (string) (DB::table('geo_districts')
                        ->where('district_code', $distCode)
                        ->whereNull('deleted_at')
                        ->value('district_name') ?? '');
                } catch (\Throwable) {
                    $districtNameCache[$distCode] = '';
                }
            }
            $dname = $districtNameCache[$distCode];
            if ($dname !== '') {
                $vars['district_name'] = $dname;
                $vars['district_code'] = $dname;
            }
        }

        // Disease & syndrome humanisation. The classification engine emits
        // SEVERE_ACUTE_RESPIRATORY_INFECTION / VHF_HIGH_CONFIDENCE-style codes;
        // executives must read plain English.
        foreach (['top_disease_code', 'top_disease_name', 'disease_code', 'disease_name',
                  'alert_title', 'syndrome', 'syndrome_code', 'alert_code_label'] as $k) {
            if (!isset($vars[$k])) continue;
            $vars[$k] = static::humaniseDiseaseOrCode((string) $vars[$k]);
        }

        // Empty placeholders — never leak [] / null / "[]" / "{}" / "NULL".
        foreach ($vars as $k => $v) {
            if ($v === null) { $vars[$k] = '—'; continue; }
            if (is_array($v)) {
                $vars[$k] = empty($v) ? '—' : implode(', ', array_map(fn($x) => static::humaniseEnumValue((string) $x), $v));
                continue;
            }
            if (!is_string($v)) continue;
            $trim = trim($v);
            if ($trim === '' || $trim === '[]' || $trim === '{}'
                || strtoupper($trim) === 'NULL' || strtoupper($trim) === 'UNDEFINED') {
                $vars[$k] = '—';
            }
        }

        // Generic safety-net: any remaining all-caps SNAKE_CASE value of
        // reasonable length gets sentence-cased so a forgotten enum doesn't
        // sneak past as VHF_CONFIRMED, ENHANCED_SURVEILLANCE etc. We avoid
        // touching keys that are clearly machine identifiers.
        $machineKeys = [
            'alert_code', 'screening_code', 'case_code', 'alert_id', 'screening_id',
            'country_code', 'province_code', 'pheoc_code', 'action_url', 'warroom_url',
            'hub_url', 'dashboard_url', 'guest_token', 'public_url', 'magic_url',
            'unsubscribe_url', 'reset_url',
        ];
        foreach ($vars as $k => $v) {
            if (in_array($k, $machineKeys, true)) continue;
            if (!is_string($v) || $v === '—') continue;
            if (strlen($v) > 80) continue; // long bodies left intact
            if (preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $v)) {
                $vars[$k] = static::humaniseEnumValue($v);
            }
        }

        $vars['__polished'] = 1;
        return $vars;
    }

    /**
     * Routing-level enum → executive label.
     */
    private static function humaniseRouted(string $v): string
    {
        $v = strtoupper(trim($v));
        return match ($v) {
            'DISTRICT' => 'District',
            'PHEOC'    => 'PHEOC (regional)',
            'NATIONAL' => 'National',
            'POE'      => 'Point of entry',
            default    => static::humaniseEnumValue($v),
        };
    }

    /**
     * Resolve a POE code to its display name. Returns '' when not found so
     * callers can substitute the code or em-dash. Statically cached per request.
     */
    private static function resolvePoeName(string $poeCode): string
    {
        static $cache = [];
        $code = trim($poeCode);
        if ($code === '') return '';
        if (array_key_exists($code, $cache)) return $cache[$code];
        try {
            $name = (string) (DB::table('geo_poes')
                ->where('poe_code', $code)
                ->whereNull('deleted_at')
                ->value('poe_name') ?? '');
        } catch (\Throwable) {
            $name = '';
        }
        return $cache[$code] = $name;
    }

    /**
     * Resolve a district code to its display name. Returns '' when not found.
     */
    private static function resolveDistrictName(string $districtCode): string
    {
        static $cache = [];
        $code = trim($districtCode);
        if ($code === '') return '';
        if (array_key_exists($code, $cache)) return $cache[$code];
        try {
            $name = (string) (DB::table('geo_districts')
                ->where('district_code', $code)
                ->whereNull('deleted_at')
                ->value('district_name') ?? '');
        } catch (\Throwable) {
            $name = '';
        }
        return $cache[$code] = $name;
    }

    /**
     * Sentence-case a SCREAMING_SNAKE token: "ENHANCED_SURVEILLANCE" → "Enhanced surveillance".
     * Returns the string unchanged when the input doesn't look like an enum.
     */
    private static function humaniseEnumValue(string $v): string
    {
        $v = trim($v);
        if ($v === '') return '';
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $v)) return $v;
        $words = strtolower(str_replace('_', ' ', $v));
        return ucfirst($words);
    }

    /**
     * Disease / syndrome / alert-title codes get WHO-style executive phrasing.
     * Falls through to humaniseEnumValue() for unknown codes, so a new code
     * never blocks rendering with a raw token.
     */
    private static function humaniseDiseaseOrCode(string $v): string
    {
        $v = trim($v);
        if ($v === '') return '';
        $u = strtoupper($v);

        // Strip the legacy *_HIGH_CONFIDENCE / *_LOW_CONFIDENCE confidence-band
        // suffixes — WHO uses Suspected / Probable / Confirmed, not "high
        // confidence". We translate the prefix and tag with " — suspected".
        $confidenceTagged = false;
        if (preg_match('/^(.+?)_(HIGH|LOW|MEDIUM)_CONFIDENCE$/', $u, $m)) {
            $u = $m[1];
            $confidenceTagged = true;
        }

        $known = [
            'VHF'                                 => 'Viral haemorrhagic fever',
            'EBOLA'                               => 'Ebola virus disease',
            'EVD'                                 => 'Ebola virus disease',
            'EBOLA_VIRUS_DISEASE'                 => 'Viral haemorrhagic fever — suspected',
            'MARBURG_VIRUS_DISEASE'               => 'Viral haemorrhagic fever — suspected',
            'MARBURG'                             => 'Marburg virus disease',
            'MVD'                                 => 'Marburg virus disease',
            'LASSA_FEVER'                         => 'Viral haemorrhagic fever — suspected',
            'LASSA'                               => 'Lassa fever',
            'CCHF'                                => 'Crimean-Congo haemorrhagic fever',
            'CRIMEAN_CONGO_HAEMORRHAGIC_FEVER'    => 'Viral haemorrhagic fever — suspected',
            'RVF'                                 => 'Rift Valley fever',
            'RIFT_VALLEY_FEVER'                   => 'Rift Valley fever (VHF-family)',
            'HANTAVIRUS'                          => 'Viral haemorrhagic fever — Hantavirus type',
            'DENGUE_HAEMORRHAGIC'                 => 'Severe dengue / VHF-family',
            'DENGUE_SEVERE'                       => 'Severe dengue / VHF-family',
            'YELLOW_FEVER'                        => 'Yellow fever (VHF-family)',
            'DENGUE'                              => 'Dengue fever',
            'CHIKUNGUNYA'                         => 'Chikungunya',
            'ZIKA'                                => 'Zika virus disease',
            'MPOX'                                => 'Mpox',
            'MONKEYPOX'                           => 'Mpox',
            'MEASLES'                             => 'Measles',
            'RUBELLA'                             => 'Rubella',
            'CHOLERA'                             => 'Cholera',
            'TYPHOID'                             => 'Typhoid fever',
            'MENINGITIS'                          => 'Meningitis',
            'PLAGUE'                              => 'Plague',
            'ANTHRAX'                             => 'Anthrax',
            'POLIO'                               => 'Poliomyelitis',
            'COVID_19'                            => 'COVID-19',
            'COVID19'                             => 'COVID-19',
            'INFLUENZA'                           => 'Influenza',
            'AVIAN_INFLUENZA'                     => 'Avian influenza',
            'SARS'                                => 'SARS',
            'MERS'                                => 'MERS',
            'TUBERCULOSIS'                        => 'Tuberculosis',
            'TB'                                  => 'Tuberculosis',
            'HEPATITIS_A'                         => 'Hepatitis A',
            'HEPATITIS_B'                         => 'Hepatitis B',
            'HEPATITIS_C'                         => 'Hepatitis C',
            'HEPATITIS_E'                         => 'Hepatitis E',
            'MALARIA'                             => 'Malaria',
            'SEVERE_ACUTE_RESPIRATORY_INFECTION'  => 'Severe acute respiratory infection',
            'ACUTE_FEBRILE_ILLNESS'               => 'Acute febrile illness',
            'ACUTE_GASTROENTERITIS'               => 'Acute gastroenteritis',
            'UPPER_RESPIRATORY_TRACT_INFECTION'   => 'Upper respiratory tract infection',
            'URTI'                                => 'Upper respiratory tract infection',
            'SARI'                                => 'Severe acute respiratory infection',
            'AFI'                                 => 'Acute febrile illness',
            'AGE'                                 => 'Acute gastroenteritis',
            'SKIN_RASH_WITH_FEVER'                => 'Rash illness with fever',
            'NEUROLOGICAL_SYNDROME'               => 'Acute neurological syndrome',
            'JAUNDICE_SYNDROME'                   => 'Acute jaundice syndrome',
            'HAEMORRHAGIC_SYNDROME'               => 'Haemorrhagic syndrome',
        ];

        $label = $known[$u] ?? static::humaniseEnumValue($u);
        if ($confidenceTagged) $label .= ' — suspected';
        return $label;
    }

    /**
     * Cheap HTML→text fallback used when a template has no body_text_template.
     * Gmail requires a text/plain alternative in the multipart payload or it
     * downgrades the HTML and strips inline CSS.
     */
    private static function htmlToText(string $html): string
    {
        $s = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $s = preg_replace('/<br\s*\/?>/i', "\n", $s) ?? $s;
        $s = preg_replace('/<\/(p|div|tr|li|h[1-6])>/i', "\n", $s) ?? $s;
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace("/[ \t]+/", ' ', $s) ?? $s;
        $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;
        return trim($s);
    }

    /**
     * Persist send attempt to notification_log. Returns minimal shape.
     *
     * Optional trailing parameters identify the email-delivery-log surface
     * required by the alerts refactor brief:
     *   $recipientUserId — internal users.id when fan-out targets a user
     *                      (assignee / team / originator / management).
     *   $actionCode      — semantic event (ALERT_REASSIGNED / ALERT_ACKNOWLEDGED …)
     *                      distinct from $code which names the rendered template.
     *   $ccAddresses     — array of CC addresses actually sent (ops mailbox).
     *   $bccAddresses    — array of BCC addresses actually sent.
     */
    private static function log(object $contact, string $code, string $subject, string $body, string $status,
                                ?string $error, ?object $related, string $triggeredBy,
                                string $entityType, ?int $entityId,
                                ?int $recipientUserId = null, ?string $actionCode = null,
                                ?array $ccAddresses = null, ?array $bccAddresses = null): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $row = [
            'contact_id'          => $contact->id ?? null,
            'to_email'            => $contact->email ?? null,
            'to_phone'            => $contact->phone ?? null,
            'channel'             => 'EMAIL',
            'template_code'       => $code,
            'subject'             => mb_substr($subject, 0, 240),
            'body_preview'        => mb_substr(strip_tags($body), 0, 500),
            'body_full'           => $body,
            'related_entity_type' => $entityType,
            'related_entity_id'   => $entityId ?? ($related->id ?? null),
            'country_code'        => $related->country_code ?? ($contact->country_code ?? null),
            'district_code'       => $related->district_code ?? ($contact->district_code ?? null),
            'poe_code'            => $related->poe_code ?? ($contact->poe_code ?? null),
            'status'              => $status,
            'error_message'       => $error,
            'retry_count'         => 0,
            'sent_at'             => $status === 'SENT' ? $now : null,
            'failed_at'           => $status === 'FAILED' ? $now : null,
            'triggered_by'        => mb_substr($triggeredBy, 0, 40),
            'created_at'          => $now,
            'updated_at'          => $now,
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('notification_log', 'recipient_user_id')) {
            $row['recipient_user_id'] = $recipientUserId;
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('notification_log', 'action_code')) {
            $row['action_code'] = $actionCode ? mb_substr($actionCode, 0, 60) : null;
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('notification_log', 'cc_addresses')) {
            $row['cc_addresses'] = $ccAddresses ? json_encode(array_values($ccAddresses)) : null;
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('notification_log', 'bcc_addresses')) {
            $row['bcc_addresses'] = $bccAddresses ? json_encode(array_values($bccAddresses)) : null;
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('notification_log', 'queued_at')) {
            $row['queued_at'] = $status === 'SENT' ? $now : null;
        }
        $id = DB::table('notification_log')->insertGetId($row);
        return ['log_id' => $id, 'status' => $status, 'to' => $contact->email ?? null, 'error' => $error];
    }

    /* ═════════════════════════════════════════════════════════════════════
     *  USER-EVENT FAN-OUT (alerts refactor §3.2)
     *
     *  Fires emails to internal users (assignee + team + originator + management)
     *  on every critical action. Distinct from the contact-roster fan-out above:
     *    • recipients are users.id with valid emails
     *    • subject lines carry alert code + risk + traveler ID
     *    • emails are queued via SentinelMail (CC: ops mailboxes)
     *    • notification_log row written per recipient with action_code
     *    • idempotency: per (action_code, alert_id, user_id) inside 5-minute
     *      window — re-firing the same admin action does not double-email
     *  ═════════════════════════════════════════════════════════════════════ */

    /** RFC-5322-ish syntactic email check used to gate user recipients. */
    public static function isValidEmail(?string $email): bool
    {
        if (! is_string($email)) return false;
        $email = trim($email);
        if ($email === '' || mb_strlen($email) > 254) return false;
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * §3.3 helper — true when the email maps to an active platform user.
     * Used by send() to decide whether to swap the CTA URL for a guest token.
     */
    private static function recipientHasUserAccount(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') return false;
        return DB::table('users')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', 1)
            ->exists();
    }

    /**
     * Resolve the canonical user recipient set for an alert event.
     * Returns an array of objects (id, full_name, role_key, email) deduped by
     * email (case-insensitive). Only users with valid emails are included.
     *
     * Composition:
     *   • assignee  — alerts.current_owner_user_id
     *   • acker     — alerts.acknowledged_by_user_id
     *   • team      — every alert_collaborators.user_id where is_active=1
     *   • originator— secondary_screenings.opened_by_user_id (case officer)
     *   • management— users with role_key in NATIONAL_ADMIN / PHEOC_OFFICER /
     *                 DISTRICT_SUPERVISOR scoped to the alert's province /
     *                 district / poe via user_assignments
     */
    public static function resolveAlertUserRecipients(object $alert): array
    {
        $alertId = (int) ($alert->id ?? 0);
        $userIds = [];

        if (! empty($alert->current_owner_user_id))   $userIds[] = (int) $alert->current_owner_user_id;
        if (! empty($alert->acknowledged_by_user_id)) $userIds[] = (int) $alert->acknowledged_by_user_id;

        if ($alertId > 0) {
            $collabIds = DB::table('alert_collaborators')
                ->where('alert_id', $alertId)
                ->where('is_active', 1)
                ->pluck('user_id')->all();
            foreach ($collabIds as $cid) { $userIds[] = (int) $cid; }
        }

        $screeningId = (int) ($alert->secondary_screening_id ?? 0);
        if ($screeningId > 0) {
            $opener = DB::table('secondary_screenings')
                ->where('id', $screeningId)
                ->value('opened_by_user_id');
            if ($opener) $userIds[] = (int) $opener;
        }

        $province = (string) ($alert->province_code ?? '');
        $district = (string) ($alert->district_code ?? '');
        $poe      = (string) ($alert->poe_code      ?? '');

        $mgmt = DB::table('users as u')
            ->where('u.is_active', 1)
            ->where(function ($q) use ($province, $district, $poe) {
                $q->where('u.role_key', 'NATIONAL_ADMIN')
                  ->orWhereExists(function ($w) use ($province, $district, $poe) {
                      $w->select(DB::raw(1))
                        ->from('user_assignments as ua')
                        ->whereColumn('ua.user_id', 'u.id')
                        ->where('ua.is_active', 1)
                        ->whereNull('ua.ends_at')
                        ->whereIn('u.role_key', ['PHEOC_OFFICER','DISTRICT_SUPERVISOR'])
                        ->where(function ($w2) use ($province, $district, $poe) {
                            if ($province !== '') $w2->orWhere('ua.province_code', $province);
                            if ($district !== '') $w2->orWhere('ua.district_code', $district);
                            if ($poe      !== '') $w2->orWhere('ua.poe_code',      $poe);
                        });
                  });
            })
            ->pluck('u.id')->all();
        foreach ($mgmt as $mid) { $userIds[] = (int) $mid; }

        $userIds = array_values(array_unique(array_filter($userIds, fn ($v) => $v > 0)));
        if (empty($userIds)) return [];

        $rows = DB::table('users')
            ->whereIn('id', $userIds)
            ->where('is_active', 1)
            ->get(['id', 'full_name', 'role_key', 'email', 'country_code']);

        $out  = [];
        $seen = [];
        foreach ($rows as $u) {
            $email = (string) ($u->email ?? '');
            if (! self::isValidEmail($email)) continue;
            $low = strtolower($email);
            if (isset($seen[$low])) continue;
            $seen[$low] = true;
            $out[] = $u;
        }
        return $out;
    }

    /**
     * Render a critical-action email subject line per the brief.
     * Plain English only — no raw enum codes reach the inbox preview.
     *   <alert_code> · <Action label> · <Risk label> · traveller: <name>
     */
    private static function eventSubject(object $alert, string $actionLabel): string
    {
        $code     = (string) ($alert->alert_code ?? ('ALERT-' . ($alert->id ?? '?')));
        $risk     = static::humaniseEnumValue(strtoupper((string) ($alert->risk_level ?? 'HIGH')));
        $screening = (int) ($alert->secondary_screening_id ?? 0);
        $traveler = '—';
        if ($screening > 0) {
            $sc = DB::table('secondary_screenings')->where('id', $screening)
                ->first(['traveler_full_name','traveler_anonymous_code','client_uuid']);
            if ($sc) {
                $traveler = (string) ($sc->traveler_full_name
                    ?: $sc->traveler_anonymous_code
                    ?: substr((string) ($sc->client_uuid ?? '—'), 0, 8));
            }
        }
        $subject = "{$code} · {$actionLabel} · {$risk} risk · traveller: {$traveler}";
        return mb_substr($subject, 0, 200);
    }

    /** Idempotency check — prevents double-firing the same user-event. */
    private static function userEventRecentlyFired(string $actionCode, int $alertId, int $userId, int $minutes = 5): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('notification_log', 'action_code')) return false;
        if (! \Illuminate\Support\Facades\Schema::hasColumn('notification_log', 'recipient_user_id')) return false;
        return DB::table('notification_log')
            ->where('action_code', $actionCode)
            ->where('related_entity_type', 'ALERT')
            ->where('related_entity_id', $alertId)
            ->where('recipient_user_id', $userId)
            ->where('status', 'SENT')
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->exists();
    }

    /**
     * Render the Uber-style HTML body for a critical-action user email.
     * One headline + context + single CTA + footer with alert metadata.
     */
    private static function buildUserEventBody(object $alert, string $headline, string $context, string $ctaUrl, string $ctaLabel): array
    {
        $rawRisk  = strtoupper((string) ($alert->risk_level ?? 'HIGH'));
        $code     = htmlspecialchars((string) ($alert->alert_code ?? ''), ENT_QUOTES, 'UTF-8');
        $risk     = htmlspecialchars(static::humaniseEnumValue($rawRisk), ENT_QUOTES, 'UTF-8');
        $level    = htmlspecialchars(static::humaniseRouted((string) ($alert->routed_to_level ?? '')), ENT_QUOTES, 'UTF-8');
        $poeName  = static::resolvePoeName((string) ($alert->poe_code ?? ''));
        $poe      = htmlspecialchars($poeName !== '' ? $poeName : '—', ENT_QUOTES, 'UTF-8');
        $distName = static::resolveDistrictName((string) ($alert->district_code ?? ''));
        $district = htmlspecialchars($distName !== '' ? $distName : '—', ENT_QUOTES, 'UTF-8');
        $title    = htmlspecialchars(static::humaniseDiseaseOrCode((string) ($alert->alert_title ?? '')), ENT_QUOTES, 'UTF-8');
        $cta      = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');
        $ctaLab   = htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8');
        $head     = htmlspecialchars($headline, ENT_QUOTES, 'UTF-8');
        $ctxHtml  = nl2br(htmlspecialchars($context, ENT_QUOTES, 'UTF-8'));

        $riskColor = match ($rawRisk) {
            'CRITICAL' => '#B91C1C',
            'HIGH'     => '#C2410C',
            'MEDIUM'   => '#B45309',
            'LOW'      => '#047857',
            default    => '#0F172A',
        };

        $html = <<<HTML
<!doctype html><html><body style="margin:0;padding:0;background:#F8FAFC;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F8FAFC;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,0.08);">
        <tr><td style="padding:18px 24px;background:#0F172A;color:#FFFFFF;font-weight:600;font-size:14px;letter-spacing:.04em;">UGANDA POE SENTINEL · ALERT UPDATE</td></tr>
        <tr><td style="padding:24px 24px 8px 24px;">
          <div style="font-size:11px;color:#64748B;letter-spacing:.06em;text-transform:uppercase;">{$code} · <span style="color:{$riskColor};font-weight:600;">{$risk}</span></div>
          <h1 style="margin:8px 0 4px 0;font-size:20px;line-height:1.3;color:#0F172A;">{$head}</h1>
          <p style="margin:0;color:#475569;font-size:13.5px;">{$title}</p>
        </td></tr>
        <tr><td style="padding:8px 24px 16px 24px;">
          <div style="background:#F1F5F9;border-radius:8px;padding:14px 16px;font-size:13.5px;color:#0F172A;line-height:1.55;">{$ctxHtml}</div>
        </td></tr>
        <tr><td align="left" style="padding:4px 24px 24px 24px;">
          <a href="{$cta}" style="display:inline-block;padding:11px 20px;background:#0F172A;color:#FFFFFF;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">{$ctaLab} →</a>
        </td></tr>
        <tr><td style="padding:14px 24px;background:#F8FAFC;border-top:1px solid #E2E8F0;font-size:11px;color:#64748B;">
          Routing level: <strong>{$level}</strong> · POE <strong>{$poe}</strong> · District <strong>{$district}</strong><br>
          Alert {$code} · You are receiving this because you are on the response team or management for this alert.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;

        $text = "UGANDA POE SENTINEL — ALERT UPDATE\n"
            . "Alert: " . ($alert->alert_code ?? '') . " · Risk: " . static::humaniseEnumValue($rawRisk) . "\n\n"
            . $headline . "\n"
            . static::humaniseDiseaseOrCode((string) ($alert->alert_title ?? '')) . "\n\n"
            . $context . "\n\n"
            . $ctaLabel . ': ' . $ctaUrl . "\n\n"
            . "Routing: " . static::humaniseRouted((string) ($alert->routed_to_level ?? '')) . " · POE: " . ($poeName !== '' ? $poeName : '—') . " · District: " . ($distName !== '' ? $distName : '—') . "\n";

        return [$html, $text];
    }

    /**
     * Fan out a critical-action email to assignee + team + originator + management.
     *
     * @return array{sent:int, skipped:int, failed:int, recipients:int, action_code:string}
     */
    public static function dispatchUserEvent(
        object $alert,
        string $actionCode,
        string $headline,
        string $context,
        int $triggerUserId,
        ?string $ctaUrlOverride = null,
        ?string $ctaLabelOverride = null,
    ): array {
        return static::safely(function () use ($alert, $actionCode, $headline, $context, $triggerUserId, $ctaUrlOverride, $ctaLabelOverride) {
            $alertId = (int) ($alert->id ?? 0);
            if ($alertId <= 0) {
                return ['error' => 'alert.id missing', 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'recipients' => 0, 'action_code' => $actionCode];
            }

            $recipients = static::resolveAlertUserRecipients($alert);
            if (empty($recipients)) {
                return ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'recipients' => 0, 'action_code' => $actionCode];
            }

            $ctaUrl   = $ctaUrlOverride   ?: \App\Services\AdminLinks::alertWarRoom($alertId);
            $ctaLabel = $ctaLabelOverride ?: 'Open Case File';
            $actionLabel = ucwords(strtolower(str_replace('_', ' ', $actionCode)));
            $subject = static::eventSubject($alert, $actionLabel);
            [$html, $text] = static::buildUserEventBody($alert, $headline, $context, $ctaUrl, $ctaLabel);

            $sent = 0; $skipped = 0; $failed = 0;
            foreach ($recipients as $u) {
                $userId = (int) $u->id;
                if ($userId === $triggerUserId) {
                    $skipped++;
                    continue;
                }
                if (static::userEventRecentlyFired($actionCode, $alertId, $userId)) {
                    $skipped++;
                    continue;
                }

                $virtualContact = (object) [
                    'id'             => null,
                    'email'          => $u->email,
                    'phone'          => null,
                    'country_code'   => $u->country_code ?? ($alert->country_code ?? null),
                    'district_code'  => $alert->district_code ?? null,
                    'poe_code'       => $alert->poe_code ?? null,
                    'alternate_email'=> null,
                ];

                try {
                    $entityRef = 'poe-sentinel-' . bin2hex(random_bytes(8));
                    $mailable  = new SentinelMail(
                        toAddress:      (string) $u->email,
                        subjectLine:    $subject,
                        htmlBody:       $html,
                        textBody:       $text,
                        ccAddresses:    [],
                        bccAddresses:   [],
                        replyToAddress: null,
                        replyToName:    null,
                        entityRefId:    $entityRef,
                    );
                    Mail::send($mailable);
                    static::log($virtualContact, $actionCode, $subject, $html, 'SENT', null,
                        $alert, 'USER:' . $triggerUserId, 'ALERT', $alertId,
                        $userId, $actionCode, SentinelMail::OPS_CC);
                    $sent++;
                } catch (Throwable $e) {
                    static::log($virtualContact, $actionCode, $subject, $html, 'FAILED',
                        mb_substr($e->getMessage(), 0, 500),
                        $alert, 'USER:' . $triggerUserId, 'ALERT', $alertId,
                        $userId, $actionCode, SentinelMail::OPS_CC);
                    $failed++;
                }
            }

            return [
                'action_code' => $actionCode,
                'recipients'  => count($recipients),
                'sent'        => $sent,
                'skipped'     => $skipped,
                'failed'      => $failed,
            ];
        }, 'dispatchUserEvent');
    }

    /**
     * Wrap a callable in a try/catch so a failing notification never breaks
     * the caller. Every error is logged to Laravel's main log.
     */
    private static function safely(callable $fn, string $ctx): array
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            Log::error("[NotificationDispatcher::{$ctx}] " . $e->getMessage(), [
                'file' => basename($e->getFile()), 'line' => $e->getLine(),
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    // ════════════════════════════════════════════════════════════════════════════
    //  WEEKLY ACTION BUNDLE — single per-jurisdiction-clustered email,
    //  Monday 08:00 Africa/Kampala. Replaces the previous daily followup /
    //  national-digest cadence per executive directive (2026-05-17).
    //  BREACH_717 entries are filtered separately so they only appear once
    //  every 14 days (least-frequent of all reminders).
    // ════════════════════════════════════════════════════════════════════════════
    public static function sendWeeklyActionBundle(string $triggeredBy = 'CRON:weekly-action-bundle'): array
    {
        return static::safely(function () use ($triggeredBy) {
            $cc      = (string) (config('country.legacy_code') ?: 'Uganda');
            $weekKey = (int) now()->isoFormat('GGGGWW'); // e.g. 202620 for ISO week 20 of 2026
            $entityType = 'WEEKLY_BUNDLE';
            $entityId   = $weekKey;

            // Idempotency: skip if this exact bundle key has already been sent in the last 7 days.
            $alreadySent = DB::table('notification_log')
                ->where('related_entity_type', $entityType)
                ->where('related_entity_id', $entityId)
                ->where('status', 'SENT')
                ->where('sent_at', '>=', now()->subDays(7))
                ->exists();
            if ($alreadySent) {
                return ['sent' => 0, 'skipped' => 1, 'reason' => 'already_sent_this_week', 'week_key' => $weekKey];
            }

            // ── Pull this week's followup work ───────────────────────────────
            $now = now();
            $dueWindow = $now->copy()->addHours(96); // due-soon = within next 4 days

            $followupRows = DB::table('alert_followups as f')
                ->join('alerts as a', 'a.id', '=', 'f.alert_id')
                ->select('a.id as alert_id', 'a.alert_code', 'a.alert_title', 'a.risk_level',
                         'a.country_code', 'a.province_code', 'a.pheoc_code',
                         'a.district_code', 'a.poe_code', 'a.created_at as alert_created_at',
                         'f.id as followup_id', 'f.title as followup_title', 'f.due_at',
                         'f.status as followup_status')
                ->where('a.country_code', $cc)
                ->whereNull('a.deleted_at')->whereNull('f.deleted_at')
                ->whereIn('a.status', ['OPEN', 'IN_PROGRESS', 'ACKNOWLEDGED'])
                ->whereIn('f.status', ['OPEN', 'IN_PROGRESS'])
                ->where('f.due_at', '<=', $dueWindow)
                ->orderBy('a.country_code')->orderBy('a.province_code')
                ->orderBy('a.district_code')->orderBy('a.poe_code')
                ->orderBy('f.due_at')
                ->limit(200)
                ->get();

            // ── Pull breach reports unresolved in last 14 days ──────────────
            $breachRows = DB::table('alert_breach_reports as b')
                ->join('alerts as a', 'a.id', '=', 'b.alert_id')
                ->select('a.id as alert_id', 'a.alert_code', 'a.alert_title', 'a.risk_level',
                         'a.country_code', 'a.province_code', 'a.pheoc_code',
                         'a.district_code', 'a.poe_code',
                         'b.phase as breach_phase', 'b.target_hours', 'b.actual_hours',
                         'b.rca_status', 'b.created_at as breach_at')
                ->where('a.country_code', $cc)
                ->whereNull('a.deleted_at')
                ->where('b.rca_status', '!=', 'CLOSED')
                ->where('b.created_at', '>=', now()->subDays(14))
                ->orderBy('a.country_code')->orderBy('a.province_code')
                ->orderBy('a.district_code')->orderBy('a.poe_code')
                ->limit(200)
                ->get();

            if ($followupRows->isEmpty() && $breachRows->isEmpty()) {
                return ['sent' => 0, 'skipped' => 1, 'reason' => 'no_action_items', 'week_key' => $weekKey];
            }

            // ── Group by jurisdiction cluster for sectioning ─────────────────
            $clusterKey = fn($r) => trim(implode(' · ', array_filter([
                $r->province_code ?: $r->pheoc_code ?? null,
                $r->district_code ?? null,
                $r->poe_code ?? null,
            ])) ?: 'National');

            $clusters = [];
            foreach ($followupRows as $r) {
                $k = $clusterKey($r);
                $clusters[$k]['followups'][] = $r;
            }
            foreach ($breachRows as $r) {
                $k = $clusterKey($r);
                $clusters[$k]['breaches'][] = $r;
            }
            ksort($clusters);

            // ── Build the HTML body ──────────────────────────────────────────
            $totalAlerts = $followupRows->count() + $breachRows->count();
            $subject = "Uganda POE Sentinel · Weekly Action Digest · {$totalAlerts} item(s) · week of " . $now->format('Y-m-d');
            $html    = static::renderWeeklyBundleHtml($subject, $clusters, $now);
            $text    = static::renderWeeklyBundleText($subject, $clusters, $now);

            // ── Resolve target contacts: every NATIONAL contact with the followup flag ──
            $contacts = DB::table('poe_notification_contacts')
                ->where('country_code', 'UG')
                ->where('level', 'NATIONAL')
                ->where('is_active', 1)
                ->where('receives_followup_reminders', 1)
                ->whereNull('deleted_at')
                ->orderBy('priority_order')
                ->get();

            $primary  = mb_strtolower(trim(static::primaryToAddress()));
            $ccEmails = [];
            $seen     = [$primary => true];
            foreach ($contacts as $c) {
                $e = trim((string) ($c->email ?? ''));
                if ($e === '' || ! static::isValidEmail($e)) continue;
                $low = mb_strtolower($e);
                if (isset($seen[$low])) continue;
                $seen[$low] = true;
                $ccEmails[] = $e;
            }

            // ── Dispatch via SentinelMail (one envelope, all recipients) ────
            try {
                $mail = new \App\Mail\SentinelMail(
                    toAddress:      static::primaryToAddress(),
                    subjectLine:    $subject,
                    htmlBody:       $html,
                    textBody:       $text,
                    ccAddresses:    $ccEmails,
                    bccAddresses:   [],
                    replyToAddress: (config('mail.reply_to.address') ?: env('MAIL_REPLY_TO_ADDRESS')) ?: null,
                    replyToName:    (config('mail.reply_to.name')    ?: env('MAIL_REPLY_TO_NAME', '')) ?: null,
                    entityRefId:    'weekly-bundle-' . $weekKey,
                );
                Mail::send($mail);
            } catch (Throwable $e) {
                Log::error('[sendWeeklyActionBundle] dispatch failed: ' . $e->getMessage());
                return ['sent' => 0, 'failed' => 1, 'error' => $e->getMessage()];
            }

            // ── Audit: one notification_log row marking the bundle as SENT ───
            $logContact = (object) [
                'id' => null, 'email' => static::primaryToAddress(), 'phone' => null,
                'country_code' => 'UG', 'district_code' => null, 'poe_code' => null,
            ];
            static::log($logContact, 'BUNDLED_FOLLOWUP', $subject, $html, 'SENT', null,
                null, $triggeredBy, $entityType, $entityId, null, null, $ccEmails);

            // ── Per-alert suppression bumps so no other path re-pages within 7d ──
            $touchedAlerts = collect($followupRows)->pluck('alert_id')
                ->merge(collect($breachRows)->pluck('alert_id'))->unique()->values();
            foreach ($touchedAlerts as $aid) {
                foreach ($contacts as $c) {
                    if (($c->id ?? null) === null) continue;
                    // Followup-style suppression: 7 days
                    static::recordSuppression('BUNDLED_FOLLOWUP', 'ALERT', (int) $aid, (int) $c->id);
                }
            }
            // Breach-specific suppression: 14 days (template_code BREACH_717 has 20160 in SUPPRESSION_MINUTES)
            foreach ($breachRows as $b) {
                foreach ($contacts as $c) {
                    if (($c->id ?? null) === null) continue;
                    static::recordSuppression('BREACH_717', 'ALERT', (int) $b->alert_id, (int) $c->id);
                }
            }

            return [
                'sent'              => 1,
                'cc_count'          => count($ccEmails),
                'clusters'          => count($clusters),
                'followup_items'    => $followupRows->count(),
                'breach_items'      => $breachRows->count(),
                'alerts_touched'    => $touchedAlerts->count(),
                'week_key'          => $weekKey,
            ];
        }, 'sendWeeklyActionBundle');
    }

    /** HTML renderer for the weekly action bundle. */
    private static function renderWeeklyBundleHtml(string $subject, array $clusters, \Carbon\Carbon $now): string
    {
        $h  = '<!doctype html><html><body style="margin:0;background:#F8FAFC;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#0F172A;">';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F8FAFC;padding:24px 12px;"><tr><td align="center">';
        $h .= '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;background:#FFFFFF;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,0.08);">';
        $h .= '<tr><td style="padding:18px 24px;background:#0F172A;color:#FFFFFF;font-weight:600;font-size:14px;letter-spacing:.04em;">UGANDA POE SENTINEL · WEEKLY ACTION DIGEST</td></tr>';
        $h .= '<tr><td style="padding:24px 24px 8px 24px;">';
        $h .= '<div style="font-size:11px;color:#64748B;letter-spacing:.06em;text-transform:uppercase;">Week of ' . htmlspecialchars($now->format('Y-m-d'), ENT_QUOTES) . '</div>';
        $h .= '<h1 style="margin:8px 0 4px 0;font-size:20px;line-height:1.3;color:#0F172A;">Open action items requiring attention</h1>';
        $h .= '<p style="margin:0 0 4px 0;color:#475569;font-size:13.5px;">One email per week. Per executive directive, all reminders and breach re-pages are bundled here to prevent inbox flooding.</p>';
        $h .= '</td></tr>';

        foreach ($clusters as $cluster => $bucket) {
            $h .= '<tr><td style="padding:18px 24px 4px 24px;border-top:1px solid #E2E8F0;">';
            $h .= '<div style="font-size:11px;color:#64748B;letter-spacing:.05em;text-transform:uppercase;font-weight:600;">' . htmlspecialchars($cluster, ENT_QUOTES) . '</div>';
            $h .= '</td></tr>';

            $followups = $bucket['followups'] ?? [];
            if (!empty($followups)) {
                $h .= '<tr><td style="padding:6px 24px 8px 24px;">';
                $h .= '<div style="font-size:12px;color:#0F172A;font-weight:600;margin-bottom:6px;">▸ Due / overdue follow-ups (' . count($followups) . ')</div>';
                $h .= '<table style="width:100%;border-collapse:collapse;font-size:12.5px;">';
                $h .= '<tr style="background:#F1F5F9;"><th align="left" style="padding:6px 8px;">Alert</th><th align="left" style="padding:6px 8px;">Risk</th><th align="left" style="padding:6px 8px;">Follow-up</th><th align="left" style="padding:6px 8px;">Due</th></tr>';
                foreach ($followups as $r) {
                    $diseaseLabel = static::humaniseDiseaseOrCode((string) ($r->alert_title ?? ''));
                    $riskColor = match (strtoupper((string)$r->risk_level)) {
                        'CRITICAL' => '#B91C1C', 'HIGH' => '#B45309', default => '#1D4ED8',
                    };
                    $dueLabel = $r->due_at ? \Carbon\Carbon::parse($r->due_at)->diffForHumans() : '—';
                    $h .= '<tr style="border-bottom:1px solid #F1F5F9;">'
                        . '<td style="padding:6px 8px;font-weight:600;">' . htmlspecialchars((string) ($r->alert_code ?? ''), ENT_QUOTES) . '</td>'
                        . '<td style="padding:6px 8px;color:' . $riskColor . ';font-weight:600;">' . htmlspecialchars((string) ($r->risk_level ?? ''), ENT_QUOTES) . '</td>'
                        . '<td style="padding:6px 8px;">' . htmlspecialchars($diseaseLabel . ' — ' . (string) ($r->followup_title ?? ''), ENT_QUOTES) . '</td>'
                        . '<td style="padding:6px 8px;color:#64748B;">' . htmlspecialchars($dueLabel, ENT_QUOTES) . '</td></tr>';
                }
                $h .= '</table>';
                $h .= '</td></tr>';
            }

            $breaches = $bucket['breaches'] ?? [];
            if (!empty($breaches)) {
                $h .= '<tr><td style="padding:6px 24px 14px 24px;">';
                $h .= '<div style="font-size:12px;color:#B91C1C;font-weight:600;margin-bottom:6px;">▸ 7-1-7 SLA breaches (' . count($breaches) . ') — re-paged every 14 days max</div>';
                $h .= '<table style="width:100%;border-collapse:collapse;font-size:12.5px;">';
                $h .= '<tr style="background:#FEF2F2;"><th align="left" style="padding:6px 8px;">Alert</th><th align="left" style="padding:6px 8px;">Phase</th><th align="left" style="padding:6px 8px;">Target / Actual</th><th align="left" style="padding:6px 8px;">RCA Status</th></tr>';
                foreach ($breaches as $b) {
                    $h .= '<tr style="border-bottom:1px solid #FEE2E2;">'
                        . '<td style="padding:6px 8px;font-weight:600;">' . htmlspecialchars((string) ($b->alert_code ?? ''), ENT_QUOTES) . '</td>'
                        . '<td style="padding:6px 8px;color:#B91C1C;font-weight:600;">' . htmlspecialchars((string) ($b->breach_phase ?? ''), ENT_QUOTES) . '</td>'
                        . '<td style="padding:6px 8px;">' . htmlspecialchars((string) $b->target_hours . 'h / ' . round((float) $b->actual_hours, 1) . 'h', ENT_QUOTES) . '</td>'
                        . '<td style="padding:6px 8px;color:#64748B;">' . htmlspecialchars((string) ($b->rca_status ?? ''), ENT_QUOTES) . '</td></tr>';
                }
                $h .= '</table>';
                $h .= '</td></tr>';
            }
        }

        $h .= '<tr><td style="padding:14px 24px;background:#F8FAFC;border-top:1px solid #E2E8F0;font-size:11px;color:#64748B;">';
        $h .= 'Cadence: weekly (Mondays 08:00 Africa/Kampala). Breach re-pages: every 14 days max per (alert, recipient). Real-time alerts (CRITICAL, HIGH, TIER 1, ESCALATION, secondary-screening events) are NOT bundled and fire immediately.';
        $h .= '</td></tr>';
        $h .= '</table></td></tr></table></body></html>';
        return $h;
    }

    /** Plain-text version of the weekly bundle. */
    private static function renderWeeklyBundleText(string $subject, array $clusters, \Carbon\Carbon $now): string
    {
        $t = "UGANDA POE SENTINEL — WEEKLY ACTION DIGEST\n";
        $t .= "Week of " . $now->format('Y-m-d') . "\n";
        $t .= str_repeat('=', 60) . "\n\n";
        foreach ($clusters as $cluster => $bucket) {
            $t .= "{$cluster}\n" . str_repeat('-', strlen($cluster)) . "\n";
            foreach (($bucket['followups'] ?? []) as $r) {
                $due = $r->due_at ? \Carbon\Carbon::parse($r->due_at)->diffForHumans() : '—';
                $t .= "  [FOLLOWUP] " . ($r->alert_code ?? '') . " · " . ($r->risk_level ?? '') . " · " . ($r->followup_title ?? '') . " · due {$due}\n";
            }
            foreach (($bucket['breaches'] ?? []) as $b) {
                $t .= "  [BREACH]   " . ($b->alert_code ?? '') . " · " . ($b->breach_phase ?? '') . " · target " . ($b->target_hours ?? '') . "h actual " . round((float)($b->actual_hours ?? 0), 1) . "h · RCA " . ($b->rca_status ?? '') . "\n";
            }
            $t .= "\n";
        }
        $t .= "Cadence: weekly. Breach re-pages: 14 days max per (alert, recipient).\n";
        $t .= "Real-time alerts (CRITICAL, secondary screening) fire immediately and are NOT bundled.\n";
        return $t;
    }

    // ════════════════════════════════════════════════════════════════════════════
    //  DAILY EXECUTIVE BRIEF — midnight Africa/Kampala. Sent only to NATIONAL
    //  contacts with receives_daily_report=1 (the 10-person executive roster).
    //  Beautiful 24-hour analytics: KPI cards + bar charts + syndromic mix.
    // ════════════════════════════════════════════════════════════════════════════
    public static function sendDailyExecutiveBrief(string $triggeredBy = 'CRON:daily-executive-brief'): array
    {
        return static::safely(function () use ($triggeredBy) {
            $cc       = (string) (config('country.legacy_code') ?: 'Uganda');
            $dayKey   = (int) now()->subDay()->format('Ymd'); // covers the previous calendar day
            $entityType = 'DAILY_BRIEF';
            $entityId   = $dayKey;

            // Idempotency: skip if a brief for this day-key has already been sent.
            $alreadySent = DB::table('notification_log')
                ->where('related_entity_type', $entityType)
                ->where('related_entity_id', $entityId)
                ->where('status', 'SENT')
                ->where('sent_at', '>=', now()->subDays(2))
                ->exists();
            if ($alreadySent) {
                return ['sent' => 0, 'skipped' => 1, 'reason' => 'already_sent_for_day', 'day_key' => $dayKey];
            }

            // ── 24-hour windows ─────────────────────────────────────────────
            $endOfPrevDay  = now()->subDay()->endOfDay();
            $startOfPrevDay = now()->subDay()->startOfDay();

            // ── KPIs (last 24h, then 7-day comparison) ──────────────────────
            $screened24    = (int) DB::table('primary_screenings')->where('country_code', 'UG')
                ->whereBetween('captured_at', [$startOfPrevDay, $endOfPrevDay])->whereNull('deleted_at')->count();
            $symptomatic24 = (int) DB::table('primary_screenings')->where('country_code', 'UG')
                ->whereBetween('captured_at', [$startOfPrevDay, $endOfPrevDay])->whereNull('deleted_at')
                ->where('symptoms_present', 1)->count();
            $referrals24   = (int) DB::table('primary_screenings')->where('country_code', 'UG')
                ->whereBetween('captured_at', [$startOfPrevDay, $endOfPrevDay])->whereNull('deleted_at')
                ->where('referral_created', 1)->count();
            $alertsCreated24 = (int) DB::table('alerts')->where('country_code', 'UG')
                ->whereBetween('created_at', [$startOfPrevDay, $endOfPrevDay])->whereNull('deleted_at')->count();
            $alertsClosed24  = (int) DB::table('alerts')->where('country_code', 'UG')
                ->whereBetween('closed_at', [$startOfPrevDay, $endOfPrevDay])->whereNull('deleted_at')
                ->where('status', 'CLOSED')->count();
            $openAlerts      = (int) DB::table('alerts')->where('country_code', 'UG')
                ->whereIn('status', ['OPEN', 'IN_PROGRESS', 'ACKNOWLEDGED'])->whereNull('deleted_at')->count();
            $criticalOpen    = (int) DB::table('alerts')->where('country_code', 'UG')
                ->whereIn('status', ['OPEN', 'IN_PROGRESS', 'ACKNOWLEDGED'])->whereNull('deleted_at')
                ->where('risk_level', 'CRITICAL')->count();
            $sympRate = $screened24 > 0 ? round(($symptomatic24 / $screened24) * 100, 1) : 0;

            // ── Top 5 POEs by volume yesterday ──────────────────────────────
            $topPoes = DB::table('primary_screenings as ps')
                ->leftJoin('ref_poes as p', 'p.poe_code', '=', 'ps.poe_code')
                ->selectRaw('ps.poe_code, COALESCE(p.poe_name, ps.poe_code) AS poe_name, COUNT(*) AS n')
                ->where('ps.country_code', 'UG')
                ->whereBetween('ps.captured_at', [$startOfPrevDay, $endOfPrevDay])
                ->whereNull('ps.deleted_at')
                ->groupBy('ps.poe_code', 'p.poe_name')
                ->orderByDesc('n')->limit(5)->get();

            // ── 7-day trend ─────────────────────────────────────────────────
            $trendRows = DB::table('primary_screenings')
                ->selectRaw('DATE(captured_at) AS d, COUNT(*) AS n, SUM(symptoms_present) AS symp')
                ->where('country_code', 'UG')
                ->where('captured_at', '>=', now()->subDays(7)->startOfDay())
                ->whereNull('deleted_at')
                ->groupBy('d')->orderBy('d')->get();

            $subject = sprintf(
                "Uganda POE Sentinel · Executive Brief · %s · %d screened / %d alerts",
                now()->subDay()->format('Y-m-d'), $screened24, $alertsCreated24
            );
            $html = static::renderExecBriefHtml($subject, [
                'date'              => now()->subDay()->format('Y-m-d'),
                'screened24'        => $screened24,
                'symptomatic24'     => $symptomatic24,
                'symp_rate'         => $sympRate,
                'referrals24'       => $referrals24,
                'alerts_created24'  => $alertsCreated24,
                'alerts_closed24'   => $alertsClosed24,
                'open_alerts'       => $openAlerts,
                'critical_open'     => $criticalOpen,
                'top_poes'          => $topPoes,
                'trend_rows'        => $trendRows,
            ]);
            $text = static::renderExecBriefText($subject, $screened24, $symptomatic24, $sympRate, $alertsCreated24, $alertsClosed24, $openAlerts, $criticalOpen, $topPoes);

            // ── Resolve exec recipients ─────────────────────────────────────
            $contacts = DB::table('poe_notification_contacts')
                ->where('country_code', 'UG')
                ->where('level', 'NATIONAL')
                ->where('is_active', 1)
                ->where('receives_daily_report', 1)
                ->whereNull('deleted_at')
                ->orderBy('priority_order')
                ->get();

            $primary  = mb_strtolower(trim(static::primaryToAddress()));
            $ccEmails = [];
            $seen     = [$primary => true];
            foreach ($contacts as $c) {
                $e = trim((string) ($c->email ?? ''));
                if ($e === '' || ! static::isValidEmail($e)) continue;
                $low = mb_strtolower($e);
                if (isset($seen[$low])) continue;
                $seen[$low] = true;
                $ccEmails[] = $e;
            }

            // ── Dispatch ────────────────────────────────────────────────────
            try {
                $mail = new \App\Mail\SentinelMail(
                    toAddress:      static::primaryToAddress(),
                    subjectLine:    $subject,
                    htmlBody:       $html,
                    textBody:       $text,
                    ccAddresses:    $ccEmails,
                    bccAddresses:   [],
                    replyToAddress: (config('mail.reply_to.address') ?: env('MAIL_REPLY_TO_ADDRESS')) ?: null,
                    replyToName:    (config('mail.reply_to.name')    ?: env('MAIL_REPLY_TO_NAME', '')) ?: null,
                    entityRefId:    'exec-brief-' . $dayKey,
                );
                Mail::send($mail);
            } catch (Throwable $e) {
                Log::error('[sendDailyExecutiveBrief] dispatch failed: ' . $e->getMessage());
                return ['sent' => 0, 'failed' => 1, 'error' => $e->getMessage()];
            }

            // ── Audit ───────────────────────────────────────────────────────
            $logContact = (object) [
                'id' => null, 'email' => static::primaryToAddress(), 'phone' => null,
                'country_code' => 'UG', 'district_code' => null, 'poe_code' => null,
            ];
            static::log($logContact, 'DAILY_EXECUTIVE_BRIEF', $subject, $html, 'SENT', null,
                null, $triggeredBy, $entityType, $entityId, null, null, $ccEmails);

            return [
                'sent'       => 1,
                'cc_count'   => count($ccEmails),
                'day_key'    => $dayKey,
                'screened'   => $screened24,
                'alerts_new' => $alertsCreated24,
            ];
        }, 'sendDailyExecutiveBrief');
    }

    /** HTML renderer for the daily executive brief. */
    private static function renderExecBriefHtml(string $subject, array $d): string
    {
        $kpi = function (string $label, $value, string $tone = 'slate', ?string $sub = null) {
            $bg = match ($tone) { 'red' => '#FEF2F2', 'amber' => '#FFFBEB', 'green' => '#ECFDF5', default => '#F8FAFC' };
            $col= match ($tone) { 'red' => '#B91C1C', 'amber' => '#B45309', 'green' => '#047857', default => '#0F172A' };
            $subHtml = $sub ? '<div style="font-size:11px;color:#64748B;margin-top:2px;">' . htmlspecialchars($sub, ENT_QUOTES) . '</div>' : '';
            return '<td style="padding:14px 16px;background:' . $bg . ';border-radius:8px;text-align:left;vertical-align:top;width:25%;">'
                . '<div style="font-size:10.5px;color:#64748B;letter-spacing:.06em;text-transform:uppercase;">' . htmlspecialchars($label, ENT_QUOTES) . '</div>'
                . '<div style="font-size:24px;font-weight:700;color:' . $col . ';margin-top:4px;">' . htmlspecialchars((string) $value, ENT_QUOTES) . '</div>'
                . $subHtml . '</td>';
        };

        // ── Build the trend bar chart (inline SVG, no external deps) ────────
        $maxN = max(1, collect($d['trend_rows'])->max('n') ?? 1);
        $svgBars = '';
        $i = 0;
        foreach ($d['trend_rows'] as $row) {
            $bar = (int) round(((int) $row->n / $maxN) * 90);
            $x = 20 + $i * 56;
            $y = 100 - $bar;
            $svgBars .= '<rect x="' . $x . '" y="' . $y . '" width="40" height="' . $bar . '" fill="#0F172A" rx="3"/>';
            $svgBars .= '<text x="' . ($x + 20) . '" y="115" font-size="9" text-anchor="middle" fill="#64748B">' . htmlspecialchars(\Carbon\Carbon::parse($row->d)->format('M-d'), ENT_QUOTES) . '</text>';
            $svgBars .= '<text x="' . ($x + 20) . '" y="' . ($y - 4) . '" font-size="10" font-weight="600" text-anchor="middle" fill="#0F172A">' . (int) $row->n . '</text>';
            $i++;
        }
        $svg = '<svg width="100%" viewBox="0 0 420 120" xmlns="http://www.w3.org/2000/svg" style="background:#F8FAFC;border-radius:8px;">' . $svgBars . '</svg>';

        // ── Top POEs table ──────────────────────────────────────────────────
        $poeTable = '<table style="width:100%;border-collapse:collapse;font-size:13px;"><tr style="background:#F1F5F9;"><th align="left" style="padding:6px 10px;">POE</th><th align="right" style="padding:6px 10px;">Screened</th></tr>';
        if ($d['top_poes']->isEmpty()) {
            $poeTable .= '<tr><td colspan="2" style="padding:10px;color:#64748B;font-style:italic;">No screenings recorded in the last 24 hours.</td></tr>';
        } else {
            foreach ($d['top_poes'] as $p) {
                $poeTable .= '<tr style="border-bottom:1px solid #F1F5F9;"><td style="padding:6px 10px;">' . htmlspecialchars((string) $p->poe_name, ENT_QUOTES) . '</td><td align="right" style="padding:6px 10px;font-weight:600;">' . (int) $p->n . '</td></tr>';
            }
        }
        $poeTable .= '</table>';

        $h  = '<!doctype html><html><body style="margin:0;background:#F8FAFC;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#0F172A;">';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F8FAFC;padding:24px 12px;"><tr><td align="center">';
        $h .= '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;background:#FFFFFF;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,0.08);">';
        $h .= '<tr><td style="padding:18px 24px;background:#0F172A;color:#FFFFFF;font-weight:600;font-size:14px;letter-spacing:.04em;">UGANDA POE SENTINEL · EXECUTIVE BRIEF</td></tr>';
        $h .= '<tr><td style="padding:24px 24px 8px 24px;">';
        $h .= '<div style="font-size:11px;color:#64748B;letter-spacing:.06em;text-transform:uppercase;">' . htmlspecialchars($d['date'], ENT_QUOTES) . '</div>';
        $h .= '<h1 style="margin:8px 0 4px 0;font-size:20px;line-height:1.3;color:#0F172A;">24-hour surveillance snapshot</h1>';
        $h .= '<p style="margin:0;color:#475569;font-size:13.5px;">Daily executive brief — sent every midnight Africa/Kampala.</p>';
        $h .= '</td></tr>';

        // KPI grid
        $h .= '<tr><td style="padding:14px 18px;">';
        $h .= '<table style="width:100%;border-collapse:separate;border-spacing:6px;"><tr>';
        $h .= $kpi('Screened (24h)',     $d['screened24'],       'slate');
        $h .= $kpi('Symptomatic',        $d['symptomatic24'],    'amber', $d['symp_rate'] . '% of total');
        $h .= $kpi('Referrals created',  $d['referrals24'],      'amber');
        $h .= $kpi('Alerts created',     $d['alerts_created24'], $d['alerts_created24'] > 0 ? 'red' : 'green');
        $h .= '</tr><tr>';
        $h .= $kpi('Alerts closed (24h)', $d['alerts_closed24'], 'green');
        $h .= $kpi('Open alerts',        $d['open_alerts'],      $d['open_alerts'] > 10 ? 'amber' : 'slate');
        $h .= $kpi('Critical open',      $d['critical_open'],    $d['critical_open'] > 0 ? 'red' : 'green');
        $h .= $kpi('Net change',         ($d['alerts_created24'] - $d['alerts_closed24']),
                  ($d['alerts_created24'] - $d['alerts_closed24']) > 0 ? 'red' : 'green');
        $h .= '</tr></table>';
        $h .= '</td></tr>';

        // Trend chart
        $h .= '<tr><td style="padding:8px 24px 16px 24px;">';
        $h .= '<div style="font-size:12px;color:#64748B;font-weight:600;letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px;">7-DAY SCREENING TREND</div>';
        $h .= $svg;
        $h .= '</td></tr>';

        // Top POEs
        $h .= '<tr><td style="padding:8px 24px 18px 24px;">';
        $h .= '<div style="font-size:12px;color:#64748B;font-weight:600;letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px;">TOP 5 POEs (last 24h)</div>';
        $h .= $poeTable;
        $h .= '</td></tr>';

        $h .= '<tr><td style="padding:14px 24px;background:#F8FAFC;border-top:1px solid #E2E8F0;font-size:11px;color:#64748B;">';
        $h .= 'Daily executive brief · automated · Africa/Kampala midnight cadence. Real-time alerts (CRITICAL, secondary screening) reach you immediately and are not held for this digest.';
        $h .= '</td></tr>';
        $h .= '</table></td></tr></table></body></html>';
        return $h;
    }

    /** Plain-text version of the executive brief. */
    private static function renderExecBriefText(string $subject, int $screened, int $symptomatic, float $sympRate, int $alertsNew, int $alertsClosed, int $openAlerts, int $criticalOpen, $topPoes): string
    {
        $t = "UGANDA POE SENTINEL — EXECUTIVE BRIEF\n";
        $t .= now()->subDay()->format('Y-m-d') . "\n";
        $t .= str_repeat('=', 50) . "\n\n";
        $t .= "Screened (24h):     {$screened}\n";
        $t .= "Symptomatic:        {$symptomatic} ({$sympRate}%)\n";
        $t .= "Alerts created:     {$alertsNew}\n";
        $t .= "Alerts closed:      {$alertsClosed}\n";
        $t .= "Open alerts:        {$openAlerts}\n";
        $t .= "Critical open:      {$criticalOpen}\n\n";
        $t .= "Top POEs (24h):\n";
        foreach ($topPoes as $p) {
            $t .= "  " . str_pad((string) $p->poe_name, 36) . " " . (int) $p->n . "\n";
        }
        return $t;
    }
}
