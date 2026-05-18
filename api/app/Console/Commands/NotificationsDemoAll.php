<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CaseContextBuilder;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * notifications:demo-all
 *
 * Seeds a fully-populated synthetic case (traveller + primary + secondary +
 * symptoms + exposures + samples + travel countries + suspected diseases +
 * alert + RTSL-14 follow-ups) and then sends one fully-rendered email per
 * notification_templates row to the TEST whitelist.
 *
 * Guardrails:
 *   • Refuses to run if NOTIFICATIONS_TEST_MODE is not 1 — protects the real
 *     Zambia roster from being spammed.
 *   • Clears notification_suppressions for the synthetic case before each
 *     run so repeated invocations actually send rather than SKIP.
 *   • Uses a unique alert_code per invocation so nothing collides in logs.
 *
 * Run: php artisan notifications:demo-all
 */
class NotificationsDemoAll extends Command
{
    protected $signature = 'notifications:demo-all {--keep : do not delete the synthetic case after the run}';
    protected $description = 'Render + send one email per aggressive template with a fully-populated synthetic case';

    public function handle(): int
    {
        if ((int) env('NOTIFICATIONS_TEST_MODE', 0) !== 1) {
            $this->error('Refusing to run — NOTIFICATIONS_TEST_MODE is not 1.');
            $this->warn('Set NOTIFICATIONS_TEST_MODE=1 + NOTIFICATIONS_TEST_WHITELIST in .env before running this.');
            return self::FAILURE;
        }

        $whitelist = array_filter(array_map('trim', explode(',', (string) env('NOTIFICATIONS_TEST_WHITELIST', ''))));
        if (empty($whitelist)) {
            $this->error('Whitelist is empty. Aborting.');
            return self::FAILURE;
        }
        $this->info('Test whitelist: ' . implode(', ', $whitelist));

        // ── 1 · Seed synthetic case ───────────────────────────────────
        $this->line('Seeding synthetic case + alert …');
        [$alertId, $secId, $alertCode] = $this->seedSyntheticCase();
        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->info("Alert seeded: id={$alertId} code={$alertCode} secondary_screening_id={$secId}");

        // ── 2 · Upsert a test-only contact for each whitelisted address ─
        $contactIds = [];
        foreach ($whitelist as $email) {
            $id = DB::table('poe_notification_contacts')->updateOrInsert(
                ['email' => $email, 'country_code' => 'ZM'],
                [
                    'country_code' => 'ZM',
                    'district_code' => 'Chongwe District',
                    'poe_code' => 'Kenneth Kaunda International Airport',
                    'level' => 'NATIONAL',
                    'full_name' => 'DEMO · ' . $email,
                    'priority_order' => 1,
                    'is_active' => 1,
                    'receives_critical' => 1, 'receives_high' => 1, 'receives_medium' => 1, 'receives_low' => 1,
                    'receives_tier1' => 1, 'receives_tier2' => 1, 'receives_breach_alerts' => 1,
                    'receives_followup_reminders' => 1,
                    'receives_daily_report' => 1, 'receives_weekly_report' => 1,
                    'preferred_channel' => 'EMAIL',
                    'created_by_user_id' => 6,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $row = DB::table('poe_notification_contacts')->where('email', $email)->where('country_code', 'ZM')->first();
            $contactIds[] = $row->id;
        }

        // Clear any prior suppression rows that would SKIP these sends
        DB::table('notification_suppressions')
            ->where('related_entity_type', 'ALERT')
            ->where('related_entity_id', $alertId)
            ->delete();

        // ── 3 · Render + send one email per template ──────────────────
        $this->line('Sending one email per aggressive template…');
        $results = [];

        // Build reusable payloads
        $alertVars = CaseContextBuilder::forAlert($alert);
        $followup = DB::table('alert_followups')->where('alert_id', $alertId)->orderBy('id')->first();
        $followupVars = CaseContextBuilder::forFollowup($followup, $alert, [
            'followup_due_in_hours' => '3',
            'followup_overdue_hours' => '5',
            'followup_overdue_minutes' => '330',
        ]);
        $digestVars = NotificationDispatcher::buildDailyDigestVars('ZM');
        $intelVars  = NotificationDispatcher::buildNationalIntelligenceVars('ZM');
        $weeklyVars = array_merge($digestVars, [
            'primary_screenings_7d' => $digestVars['primary_screenings_24h'],
            'secondary_screenings_7d' => '0',
            'alerts_high_plus_7d' => $digestVars['alerts_24h'],
            'primary_trend' => '↑ 12 % vs previous week',
            'secondary_trend' => '→ flat',
            'alerts_trend' => '↓ 3 %',
            'followups_ontime_pct' => '82',
            'detect_compliance' => '92', 'detect_breach_count' => '1',
            'notify_compliance' => '88', 'notify_breach_count' => '2',
            'respond_compliance' => '74', 'respond_breach_count' => '4',
            'top_diseases_html' => '<ul style="margin:0;padding-left:18px;font-size:13px;"><li style="margin:3px 0;"><strong>Ebola Virus Disease</strong> · 4 suspected</li><li style="margin:3px 0;"><strong>Cholera</strong> · 3 suspected</li><li style="margin:3px 0;"><strong>Measles</strong> · 2 suspected</li></ul>',
            'lag_poes_html' => '<ul style="margin:0;padding-left:18px;font-size:13px;"><li style="margin:3px 0;color:#B91C1C;"><strong>KENNETH KAUNDA INTL</strong> · 4 breaches · 72h silent</li><li style="margin:3px 0;color:#B91C1C;"><strong>CHIRUNDU</strong> · 2 breaches</li></ul>',
        ]);

        // Closed overrides
        $closedVars = CaseContextBuilder::forAlert((object) array_merge((array) $alert, [
            'status' => 'CLOSED',
            'closed_at' => now()->format('Y-m-d H:i:s'),
        ]), [
            'closed_by_name' => 'Dr. Ayebare Timothy',
            'close_reason'   => 'Laboratory PCR negative for Ebola Virus Disease. Patient clinically improved, discharged with 7-day home monitoring. Contacts traced and cleared. National PHEOC briefed, WHO country office informed.',
        ]);

        // Mapping: template_code → $vars
        $perTemplate = [
            'ALERT_CRITICAL'         => $alertVars,
            'ALERT_HIGH'             => $alertVars,
            'TIER1_ADVISORY'         => $alertVars,
            'ANNEX2_HIT'             => $alertVars,
            'ALERT_CASE_FILE'        => $alertVars,
            'BREACH_717'             => $alertVars,
            'ESCALATION'             => $alertVars,
            'PHEIC_ADVISORY'         => $alertVars,
            'ALERT_CLOSED'           => $closedVars,
            'FOLLOWUP_DUE'           => $followupVars,
            'FOLLOWUP_OVERDUE'       => $followupVars,
            'RESPONDER_INFO_REQUEST' => array_merge($alertVars, [
                'responder_name' => 'Dr. Jane External Partner',
                'request_body'   => 'Please confirm whether this traveller is known to your facility and whether lab results for the Ebola PCR run at UVRI have been finalised.',
                'request_token'  => bin2hex(random_bytes(12)),
            ]),
            'DAILY_REPORT'           => $digestVars,
            'WEEKLY_REPORT'          => $weeklyVars,
            'NATIONAL_INTELLIGENCE'  => $intelVars,
        ];

        // Use reflection to invoke the private send() helper directly so we
        // iterate one template at a time rather than going through the
        // dispatcher's fan-out logic (which would hit suppression windows).
        $refSend = new \ReflectionMethod(NotificationDispatcher::class, 'send');
        $refSend->setAccessible(true);

        foreach ($perTemplate as $templateCode => $vars) {
            // Clear suppression for this specific (template, entity)
            DB::table('notification_suppressions')
                ->where('template_code', $templateCode)
                ->where('related_entity_type', 'ALERT')
                ->where('related_entity_id', $alertId)
                ->delete();

            $sent = 0; $failed = 0; $skipped = 0;
            foreach ($contactIds as $cid) {
                $contact = DB::table('poe_notification_contacts')->where('id', $cid)->first();
                if (! $contact) { $failed++; continue; }

                $result = $refSend->invoke(null, $contact, $templateCode, $vars, $alert, 'CMD:demo-all', 'ALERT', $alertId);
                $status = (string) ($result['status'] ?? 'UNKNOWN');
                if ($status === 'SENT') $sent++;
                elseif ($status === 'SKIPPED') $skipped++;
                else $failed++;
            }
            $results[] = [$templateCode, $sent, $skipped, $failed];
            $this->line(sprintf('  %-24s  SENT=%d  SKIP=%d  FAIL=%d', $templateCode, $sent, $skipped, $failed));
        }

        // ── 4 · Summary table ─────────────────────────────────────────
        $this->line('');
        $this->table(['Template', 'Sent', 'Skipped', 'Failed'], $results);
        $totalSent = array_sum(array_column($results, 1));
        $totalSkip = array_sum(array_column($results, 2));
        $totalFail = array_sum(array_column($results, 3));
        $this->info("TOTAL  sent={$totalSent}  skipped={$totalSkip}  failed={$totalFail}");

        // ── 5 · Optional teardown ────────────────────────────────────
        if (! $this->option('keep')) {
            $this->line('Tearing down synthetic case (pass --keep to retain) …');
            DB::table('alert_followups')->where('alert_id', $alertId)->delete();
            DB::table('secondary_symptoms')->where('secondary_screening_id', $secId)->delete();
            DB::table('secondary_exposures')->where('secondary_screening_id', $secId)->delete();
            DB::table('secondary_samples')->where('secondary_screening_id', $secId)->delete();
            DB::table('secondary_travel_countries')->where('secondary_screening_id', $secId)->delete();
            DB::table('secondary_suspected_diseases')->where('secondary_screening_id', $secId)->delete();
            DB::table('secondary_actions')->where('secondary_screening_id', $secId)->delete();
            DB::table('notification_suppressions')->where('related_entity_type', 'ALERT')->where('related_entity_id', $alertId)->delete();
            DB::table('alerts')->where('id', $alertId)->delete();
            DB::table('secondary_screenings')->where('id', $secId)->delete();
            DB::table('notifications')->where('primary_screening_id', '>', 0)
                ->where('reason_code', 'SYMPTOMATIC_VHF_EXPOSURE')
                ->where('country_code', 'ZM')
                ->where('district_code', 'KYOTERA')
                ->delete();
            DB::table('primary_screenings')->where('traveler_full_name', 'Amina Nakato Okello')->delete();
        }

        return self::SUCCESS;
    }

    /**
     * Create a fully-populated synthetic case. Returns [alert_id, secondary_screening_id, alert_code].
     */
    private function seedSyntheticCase(): array
    {
        $now   = now();
        $today = $now->format('Y-m-d H:i:s');
        $code  = 'DEMO_' . $now->format('His');

        // Primary screening
        $primaryId = DB::table('primary_screenings')->insertGetId([
            'client_uuid' => $this->uuid(),
            'reference_data_version' => 'v1',
            'server_received_at' => $today,
            'country_code' => 'ZM',
            'district_code' => 'KYOTERA',
            'poe_code' => 'MUTUKULA',
            'captured_by_user_id' => 6,
            'gender' => 'FEMALE',
            'traveler_direction' => 'ENTRY',
            'traveler_full_name' => 'Amina Nakato Okello',
            'temperature_value' => 39.1,
            'temperature_unit' => 'C',
            'symptoms_present' => 1,
            'captured_at' => $today,
            'device_id' => 'demo-device',
            'platform' => 'WEB',
            'sync_status' => 'SYNCED',
            'synced_at' => $today,
            'created_at' => $today, 'updated_at' => $today,
        ]);

        // Referral notification row (secondary screening FK target)
        $notifId = DB::table('notifications')->insertGetId([
            'client_uuid' => $this->uuid(),
            'reference_data_version' => 'v1',
            'server_received_at' => $today,
            'country_code' => 'ZM',
            'province_code' => 'CENTRAL',
            'pheoc_code' => 'PHEOC-UG',
            'district_code' => 'KYOTERA',
            'poe_code' => 'MUTUKULA',
            'primary_screening_id' => $primaryId,
            'created_by_user_id' => 6,
            'notification_type' => 'SECONDARY_REFERRAL',
            'status' => 'CLOSED',
            'priority' => 'CRITICAL',
            'reason_code' => 'SYMPTOMATIC_VHF_EXPOSURE',
            'reason_text' => 'Febrile traveller ex-DRC with haemorrhagic signs and contact history',
            'assigned_role_key' => 'POE_OFFICER',
            'opened_at' => $now->copy()->subHours(5)->format('Y-m-d H:i:s'),
            'closed_at' => $now->copy()->subHours(1)->format('Y-m-d H:i:s'),
            'device_id' => 'demo-device',
            'platform' => 'WEB',
            'sync_status' => 'SYNCED',
            'synced_at' => $today,
            'created_at' => $today, 'updated_at' => $today,
        ]);

        // Secondary screening — rich demographics + travel + vitals + disposition
        $secId = DB::table('secondary_screenings')->insertGetId([
            'client_uuid' => $this->uuid(),
            'reference_data_version' => 'v1',
            'server_received_at' => $today,
            'country_code' => 'ZM',
            'province_code' => 'CENTRAL',
            'pheoc_code' => 'PHEOC-UG',
            'district_code' => 'KYOTERA',
            'poe_code' => 'MUTUKULA',
            'primary_screening_id' => $primaryId,
            'notification_id' => $notifId,
            'opened_by_user_id' => 6,
            'case_status' => 'DISPOSITIONED',
            'traveler_full_name' => 'Amina Nakato Okello',
            'traveler_initials' => 'A.N.O.',
            'traveler_anonymous_code' => 'UG-' . substr(md5((string) $now->timestamp), 0, 8),
            'travel_document_type' => 'PASSPORT',
            'travel_document_number' => 'A0' . random_int(1000000, 9999999),
            'traveler_gender' => 'FEMALE',
            'traveler_age_years' => 34,
            'traveler_dob' => '1991-08-22',
            'traveler_nationality_country_code' => 'ZM',
            'traveler_occupation' => 'Cross-border trader / market vendor',
            'residence_country_code' => 'ZM',
            'residence_address_text' => 'Plot 14, Great East Road, Lusaka',
            'phone_number' => '+256702458619',
            'alternative_phone' => '+256772312001',
            'email' => 'amina.nakato@example.org',
            'destination_address_text' => 'University Teaching Hospital (UTH), Lusaka',
            'destination_district_code' => 'KAMPALA',
            'emergency_contact_name' => 'Robert Okello (spouse)',
            'emergency_contact_phone' => '+256751000111',
            'journey_start_country_code' => 'CD',
            'embarkation_port_city' => 'Goma',
            'conveyance_type' => 'LAND',
            'conveyance_identifier' => 'BUS-KBZ-2314',
            'seat_number' => '14B',
            'arrival_datetime' => $now->copy()->subHours(6)->format('Y-m-d H:i:s'),
            'departure_datetime' => $now->copy()->subHours(30)->format('Y-m-d H:i:s'),
            'purpose_of_travel' => 'TRADE / commerce · sourcing goods',
            'planned_length_of_stay_days' => 14,
            'triage_category' => 'URGENT',
            'emergency_signs_present' => 1,
            'general_appearance' => 'UNWELL',
            'temperature_value' => 39.4,
            'temperature_unit' => 'C',
            'pulse_rate' => 112,
            'respiratory_rate' => 24,
            'bp_systolic' => 98,
            'bp_diastolic' => 60,
            'oxygen_saturation' => 94.0,
            'syndrome_classification' => 'VIRAL_HAEMORRHAGIC_FEVER',
            'risk_level' => 'CRITICAL',
            'officer_notes' => 'Patient febrile with scleral injection and retrosternal pain. Reports contact with relative who died of unspecified haemorrhagic illness in Butembo 12 days ago. Isolated on arrival per VHF protocol. IV fluids in situ. Samples double-bagged pending UVRI courier. Recommend immediate ring-investigation of bus cohort (seats 13-15).',
            'final_disposition' => 'ISOLATED',
            'disposition_details' => 'Transferred to Mulago VHF isolation ward under ambulance escort with full PPE team',
            'screening_outcome' => 'SUSPECT_CASE',
            'followup_required' => 1,
            'followup_assigned_level' => 'NATIONAL',
            'opened_at' => $now->copy()->subHours(5)->format('Y-m-d H:i:s'),
            'dispositioned_at' => $now->copy()->subHours(1)->format('Y-m-d H:i:s'),
            'device_id' => 'demo-device',
            'platform' => 'WEB',
            'sync_status' => 'SYNCED',
            'synced_at' => $today,
            'created_at' => $today, 'updated_at' => $today,
        ]);

        // Symptoms
        foreach ([
            ['fever', 1, 0, $now->copy()->subDays(3)->format('Y-m-d'), 'Continuous high-grade fever since arrival'],
            ['very_high_fever', 1, 0, $now->copy()->subDays(2)->format('Y-m-d'), '39.4 °C at triage'],
            ['severe_fatigue', 1, 0, $now->copy()->subDays(3)->format('Y-m-d'), 'Prostrate, unable to walk unassisted'],
            ['retrosternal_pain', 1, 0, $now->copy()->subDays(2)->format('Y-m-d'), 'Severe burning'],
            ['bleeding_gums_or_nose', 1, 0, $now->copy()->subHours(18)->format('Y-m-d'), 'Gums bled twice while brushing teeth'],
            ['conjunctivitis', 1, 0, null, 'Bilateral scleral injection'],
            ['rash_vesicular_pustular', 0, 1, null, ''],
            ['paralysis_acute_flaccid', 0, 1, null, ''],
        ] as $r) {
            DB::table('secondary_symptoms')->insert([
                'secondary_screening_id' => $secId, 'symptom_code' => $r[0],
                'is_present' => $r[1], 'explicit_absent' => $r[2],
                'onset_date' => $r[3], 'details' => $r[4],
            ]);
        }

        // Exposures
        foreach ([
            ['close_contact_case', 'YES', 'Relative died of haemorrhagic illness in Butembo 12d ago'],
            ['travel_from_outbreak_area', 'YES', 'Returned from DRC North Kivu via Goma'],
            ['affected_healthcare_facility_exposure', 'UNKNOWN', 'Visited local clinic in Goma for malaria test 5d ago'],
            ['consumed_bushmeat', 'NO', ''],
            ['poultry_or_live_bird_exposure', 'NO', ''],
            ['contact_with_paralysis_case', 'NO', ''],
        ] as $r) {
            DB::table('secondary_exposures')->insert([
                'secondary_screening_id' => $secId,
                'exposure_code' => $r[0], 'response' => $r[1], 'details' => $r[2],
            ]);
        }

        // Samples
        foreach ([
            // PRESERVED: UVRI is the regional BSL-4 filovirus reference lab (Category B clinical fact).
            ['WHOLE_BLOOD_EDTA', 'UVRI-' . $now->format('His') . '-01', 'Uganda Virus Research Institute (UVRI), Entebbe'],
            ['SERUM', 'UVRI-' . $now->format('His') . '-02', 'UVRI BSL-4 lab'],
            ['ORAL_SWAB', 'UVRI-' . $now->format('His') . '-03', 'UVRI'],
        ] as $r) {
            DB::table('secondary_samples')->insert([
                'secondary_screening_id' => $secId, 'sample_collected' => 1,
                'sample_type' => $r[0], 'sample_identifier' => $r[1],
                'lab_destination' => $r[2],
                'collected_at' => $now->copy()->subHours(2)->format('Y-m-d H:i:s'),
            ]);
        }

        // Travel history
        foreach ([
            ['CD', 'VISITED', $now->copy()->subDays(18)->format('Y-m-d'), $now->copy()->subDays(2)->format('Y-m-d')],
            ['RW', 'TRANSIT', $now->copy()->subDays(2)->format('Y-m-d'), $now->copy()->subDays(1)->format('Y-m-d')],
        ] as $r) {
            DB::table('secondary_travel_countries')->insert([
                'secondary_screening_id' => $secId,
                'country_code' => $r[0], 'travel_role' => $r[1],
                'arrival_date' => $r[2], 'departure_date' => $r[3],
            ]);
        }

        // Suspected diseases
        foreach ([
            ['ebola_virus_disease', 1, 92.0, 'Travel from North Kivu + haemorrhagic signs + contact with deceased case in Butembo'],
            ['marburg_virus_disease', 2, 48.0, 'Cannot exclude filovirus co-differential; fruit-bat exposure not confirmed'],
            ['malaria_severe', 3, 30.0, 'Patient from DRC with fever — rule-out required before attributing bleeding to VHF'],
            ['lassa_fever', 4, 10.0, 'West-Africa travel absent but pharyngitis + retrosternal pain patterns similar'],
        ] as $r) {
            DB::table('secondary_suspected_diseases')->insert([
                'secondary_screening_id' => $secId,
                'disease_code' => $r[0], 'rank_order' => $r[1],
                'confidence' => $r[2], 'reasoning' => $r[3],
            ]);
        }

        // Secondary actions
        foreach ([
            ['ISOLATION', 1, 'Isolated in dedicated VHF room on arrival'],
            ['PPE_DEPLOYED', 1, 'Full VHF PPE for triage team'],
            ['SAMPLE_COLLECTION', 1, 'Three specimens packaged per BSL-4 SOPs'],
            ['AMBULANCE_TRANSFER', 1, 'Ambulance team briefed + escorted'],
            ['CONTACT_LINE_LIST', 0, 'Pending — bus cohort (seats 13–15) to be traced'],
        ] as $r) {
            DB::table('secondary_actions')->insert([
                'secondary_screening_id' => $secId,
                'action_code' => $r[0], 'is_done' => $r[1], 'details' => $r[2],
            ]);
        }

        // Alert
        $alertId = DB::table('alerts')->insertGetId([
            'client_uuid' => $this->uuid(),
            'reference_data_version' => 'v1',
            'server_received_at' => $today,
            'country_code' => 'ZM',
            'province_code' => 'CENTRAL',
            'pheoc_code' => 'PHEOC-UG',
            'district_code' => 'KYOTERA',
            'poe_code' => 'MUTUKULA',
            'secondary_screening_id' => $secId,
            'generated_from' => 'RULE_BASED',
            'risk_level' => 'CRITICAL',
            'alert_code' => $code,
            'alert_title' => 'Suspected VHF — returned traveller from DRC North Kivu',
            'alert_details' => 'Female 34y, arrived overland via Mutukula. High fever 39.4°C, gum bleeding, scleral injection, contact with deceased VHF-compatible case 12 days ago in Butembo. Patient isolated; UVRI specimens dispatched; bus cohort to be traced.',
            'routed_to_level' => 'NATIONAL',
            'ihr_tier' => 'IHR_TIER_2_ANNEX2',
            'status' => 'OPEN',
            'device_id' => 'demo-device',
            'platform' => 'WEB',
            'sync_status' => 'SYNCED',
            'synced_at' => $today,
            'created_at' => $now->copy()->subMinutes(30)->format('Y-m-d H:i:s'),
            'updated_at' => $today,
        ]);

        // Seed the 14 RTSL follow-ups for realism
        $alertObj = DB::table('alerts')->where('id', $alertId)->first();
        NotificationDispatcher::seedRtsl14Followups($alertObj, 6);

        // Mark one follow-up overdue so OVERDUE template has real data
        $firstFu = DB::table('alert_followups')->where('alert_id', $alertId)->orderBy('id')->first();
        if ($firstFu) {
            DB::table('alert_followups')->where('id', $firstFu->id)->update([
                'due_at' => $now->copy()->subHours(5)->format('Y-m-d H:i:s'),
                'status' => 'IN_PROGRESS',
                'assigned_to_role' => 'DISTRICT_SUPERVISOR',
                'notes' => 'Case investigator dispatched but awaiting PPE resupply.',
            ]);
        }

        return [$alertId, $secId, $code];
    }

    private function uuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff));
    }
}
