<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\DiseaseIntel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * CaseContextBuilder
 *
 * Assembles a fully-populated template variable payload so every notification
 * email can carry aggressive case-level / epidemiological intelligence.
 *
 * Hydration sources per invocation:
 *   alerts                           — entry record
 *   secondary_screenings             — case file (demographics + clinical + disposition)
 *   primary_screenings               — traveller triage
 *   secondary_symptoms / exposures /
 *     samples / travel_countries /
 *     suspected_diseases / actions   — clinical detail lists
 *   alert_followups                  — the 14 RTSL response actions
 *   users                            — officer + closer names
 *   poe_notification_contacts        — audience roster (for volume stats)
 *   DiseaseIntel::REGISTRY           — CFR, incubation, PPE, ...
 *   IntelligenceEngine               — for digests + national briefs
 *
 * Every method is fire-and-forget — on any DB failure a graceful subset of
 * vars is returned rather than throwing. The dispatcher will still send.
 *
 * Rendered HTML fragments returned in vars:
 *   symptoms_html, exposures_html, vitals_html, disease_html,
 *   travel_html, samples_html, followups_html, immediate_actions_html,
 *   recommended_tests_html, key_distinguishers_html, prior_alerts_html,
 *   disease_intel_html (the full hero card for a disease)
 */
final class CaseContextBuilder
{
    private const APP_URL = null; // resolved via config('app.url') lazily

    /**
     * Given an alert row, assemble every variable a template might need —
     * including sub-HTML fragments for lists. Never throws.
     */
    public static function forAlert(object $alert, array $overrides = []): array
    {
        try {
            $alert = (object) $alert;
            $alertId = (int) ($alert->id ?? 0);
            $secId   = (int) ($alert->secondary_screening_id ?? 0);

            $sec = $secId > 0
                ? DB::table('secondary_screenings')->where('id', $secId)->first()
                : null;

            $primary = null;
            if ($sec && ($sec->primary_screening_id ?? 0) > 0) {
                $primary = DB::table('primary_screenings')->where('id', (int) $sec->primary_screening_id)->first();
            }

            $symptoms = $secId > 0
                ? DB::table('secondary_symptoms')->where('secondary_screening_id', $secId)->get()
                : collect();
            $exposures = $secId > 0
                ? DB::table('secondary_exposures')->where('secondary_screening_id', $secId)->get()
                : collect();
            $samples = $secId > 0
                ? DB::table('secondary_samples')->where('secondary_screening_id', $secId)->get()
                : collect();
            $travel = $secId > 0
                ? DB::table('secondary_travel_countries')->where('secondary_screening_id', $secId)->get()
                : collect();
            $suspected = $secId > 0
                ? DB::table('secondary_suspected_diseases')
                    ->where('secondary_screening_id', $secId)
                    ->orderBy('rank_order')->get()
                : collect();
            $secActions = $secId > 0
                ? DB::table('secondary_actions')->where('secondary_screening_id', $secId)->get()
                : collect();

            $followups = $alertId > 0
                ? DB::table('alert_followups')->where('alert_id', $alertId)->orderBy('due_at')->get()
                : collect();

            $capturedBy = null;
            if ($primary && ($primary->captured_by_user_id ?? 0) > 0) {
                $capturedBy = DB::table('users')->where('id', (int) $primary->captured_by_user_id)
                    ->value('full_name');
            }
            $openedBy = null;
            if ($sec && ($sec->opened_by_user_id ?? 0) > 0) {
                $openedBy = DB::table('users')->where('id', (int) $sec->opened_by_user_id)
                    ->value('full_name');
            }
            $ackBy = null;
            if (($alert->acknowledged_by_user_id ?? 0) > 0) {
                $ackBy = DB::table('users')->where('id', (int) $alert->acknowledged_by_user_id)
                    ->value('full_name');
            }

            // Prior alerts at same POE (last 30 days, excluding this one)
            $priorAlerts = $alertId > 0
                ? DB::table('alerts')
                    ->where('poe_code', $alert->poe_code ?? '')
                    ->where('id', '!=', $alertId)
                    ->where('created_at', '>=', now()->subDays(30))
                    ->whereNull('deleted_at')
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get()
                : collect();

            // Top suspected disease (rank 1), fall back to disease_code on the
            // alert or alert title. DiseaseIntel lookup hydrates CFR/incubation/etc.
            $topSuspected = $suspected->first();
            $diseaseCode = strtolower((string) ($topSuspected->disease_code
                ?? $alert->disease_code ?? $alert->alert_code ?? ''));
            $intel = DiseaseIntel::get($diseaseCode);

            // ════════════════════════════════════════════════════════════════
            //  Build the $vars payload
            // ════════════════════════════════════════════════════════════════
            $vars = [
                // ── Alert identity ─────────────────────────────────────────
                'alert_code'          => (string) ($alert->alert_code ?? ''),
                'alert_title'         => (string) ($alert->alert_title ?? ''),
                'alert_details'       => (string) ($alert->alert_details ?? ''),
                'alert_id'            => $alertId,
                'risk_level'          => (string) ($alert->risk_level ?? ''),
                'risk_level_label'    => self::riskLabel((string) ($alert->risk_level ?? '')),
                'routed_to_level'     => (string) ($alert->routed_to_level ?? ''),
                'ihr_tier'            => (string) ($alert->ihr_tier ?? 'none'),
                'alert_status'        => (string) ($alert->status ?? 'OPEN'),
                'alert_generated_from'=> (string) ($alert->generated_from ?? 'RULE_BASED'),
                'alert_created_at'    => self::fmtDateTime($alert->created_at ?? null),
                'alert_created_ago'   => self::ago($alert->created_at ?? null),
                'alert_acknowledged_at'=> self::fmtDateTime($alert->acknowledged_at ?? null),
                'alert_closed_at'     => self::fmtDateTime($alert->closed_at ?? null),
                'acknowledged_by_name'=> $ackBy ?: '—',
                'ack_sla_hours'       => self::ackSlaHours((string) ($alert->risk_level ?? '')),
                'ack_hours'           => self::ackSlaHours((string) ($alert->risk_level ?? '')),
                'ack_deadline'        => self::ackDeadline($alert),

                // ── Geography ──────────────────────────────────────────────
                'country_code'        => (string) ($alert->country_code ?? ''),
                'country_name'        => self::countryName((string) ($alert->country_code ?? '')),
                'province_code'       => (string) ($alert->province_code ?? ''),
                'pheoc_code'          => (string) ($alert->pheoc_code ?? ''),
                'district_code'       => (string) ($alert->district_code ?? ''),
                'poe_code'            => (string) ($alert->poe_code ?? ''),

                // ── Disease intel (from DiseaseIntel::REGISTRY) ────────────
                'disease_code'        => $diseaseCode,
                'disease_name'        => (string) ($intel['name'] ?? $diseaseCode),
                'disease_ihr_tier'    => (string) ($intel['ihr_tier'] ?? 'SYNDROMIC'),
                'disease_who_category'=> (string) ($intel['who_category'] ?? ''),
                'disease_cfr_pct'     => $intel['cfr_pct'] !== null
                                          ? number_format((float) $intel['cfr_pct'], 1) . '%'
                                          : 'unknown',
                'disease_incubation'  => (string) ($intel['incubation'] ?? 'unknown'),
                'disease_transmission'=> (string) ($intel['transmission'] ?? ''),
                'disease_ppe'         => (string) ($intel['ppe'] ?? ''),
                'disease_isolation'   => (string) ($intel['isolation'] ?? ''),
                'disease_ihr_notification' => (string) ($intel['ihr_notification'] ?? ''),
                'disease_case_definition'  => (string) ($intel['case_definition'] ?? ''),
                'disease_differential'     => (string) ($intel['differential'] ?? ''),
                'disease_specimens'        => (string) ($intel['specimens'] ?? ''),
                'immediate_actions_html'  => self::listHtml($intel['immediate_actions'] ?? []),
                'recommended_tests_html'  => self::listHtml($intel['recommended_tests'] ?? []),
                'key_distinguishers_html' => self::listHtml($intel['key_distinguishers'] ?? []),

                // ── Officer identity + timing ──────────────────────────────
                'captured_by_name'    => $capturedBy ?: '—',
                'opened_by_name'      => $openedBy ?: '—',
                'captured_at'         => self::fmtDateTime($primary->captured_at ?? null),
                'opened_at'           => self::fmtDateTime($sec->opened_at ?? null),
                'dispositioned_at'    => self::fmtDateTime($sec->dispositioned_at ?? null),
                'case_closed_at'      => self::fmtDateTime($sec->closed_at ?? null),

                // ── Escalation ─────────────────────────────────────────────
                'escalation_ladder'   => self::ladderDescription((string) ($alert->routed_to_level ?? 'DISTRICT')),

                // ── Admin-panel deep links (swap .env APP_URL → all CTAs move) ──
                //   action_url   — primary "Open in Command Centre" button (War Room if alert id present, else Hub)
                //   warroom_url  — alias for clarity inside templates
                //   hub_url      — kanban view
                //   dashboard_url— landing page
                //   app_url      — base URL (kept for backward-compat)
                //
                //   Legacy `console_url` (#/active-alerts hash route) is
                //   retained as an alias of hub_url so any old template
                //   reference continues to resolve — but new templates must
                //   use action_url / warroom_url.
                // For alert-tied templates the "console" button should land
                // on the per-alert War Room. Existing templates using the
                // legacy {{console_url}} variable get the War Room deep link
                // automatically; new templates should prefer {{warroom_url}}.
                'app_url'             => \App\Services\AdminLinks::base(),
                'action_url'          => \App\Services\AdminLinks::alertWarRoom((int) ($alert->id ?? 0)),
                'warroom_url'         => \App\Services\AdminLinks::alertWarRoom((int) ($alert->id ?? 0)),
                'hub_url'             => \App\Services\AdminLinks::alertsHub(),
                'dashboard_url'       => \App\Services\AdminLinks::dashboard(),
                'console_url'         => \App\Services\AdminLinks::alertWarRoom((int) ($alert->id ?? 0)),

                // ── Timestamps (humans) ────────────────────────────────────
                'now'                 => now()->format('Y-m-d H:i'),
                'now_date'            => now()->format('Y-m-d'),
            ];

            // ── Traveller demographics + travel ────────────────────────────
            if ($sec) {
                $vars['traveler_name']      = self::maskName((string) ($sec->traveler_full_name ?? ''))
                    ?: (string) ($sec->traveler_initials ?? $sec->traveler_anonymous_code ?? 'Anonymous');
                $vars['traveler_gender']    = (string) ($sec->traveler_gender ?? '—');
                $vars['traveler_age']       = $sec->traveler_age_years ? (int) $sec->traveler_age_years . ' y' : '—';
                $vars['traveler_nationality'] = self::countryName((string) ($sec->traveler_nationality_country_code ?? ''));
                $vars['traveler_occupation'] = (string) ($sec->traveler_occupation ?? '—');
                $vars['traveler_residence'] = self::countryName((string) ($sec->residence_country_code ?? ''));
                $vars['traveler_phone']     = self::maskPhone((string) ($sec->phone_number ?? ''));
                $vars['traveler_email']     = self::maskEmail((string) ($sec->email ?? ''));
                $vars['emergency_contact']  = trim((string) ($sec->emergency_contact_name ?? '') . ' / ' . ($sec->emergency_contact_phone ?? ''), ' /');

                $vars['travel_direction']   = (string) ($primary->traveler_direction ?? '—');
                $vars['journey_start']      = self::countryName((string) ($sec->journey_start_country_code ?? ''));
                $vars['embarkation_port']   = (string) ($sec->embarkation_port_city ?? '—');
                $vars['conveyance_type']    = (string) ($sec->conveyance_type ?? '—');
                $vars['conveyance_id']      = (string) ($sec->conveyance_identifier ?? '—');
                $vars['seat_number']        = (string) ($sec->seat_number ?? '—');
                $vars['arrival_datetime']   = self::fmtDateTime($sec->arrival_datetime ?? null);
                $vars['departure_datetime'] = self::fmtDateTime($sec->departure_datetime ?? null);
                $vars['purpose_of_travel']  = (string) ($sec->purpose_of_travel ?? '—');
                $vars['length_of_stay']     = $sec->planned_length_of_stay_days
                    ? (int) $sec->planned_length_of_stay_days . ' days'
                    : '—';
                $vars['destination_address']= (string) ($sec->destination_address_text ?? '—');
                $vars['destination_district']= (string) ($sec->destination_district_code ?? '—');

                // ── Clinical picture ───────────────────────────────────────
                $vars['triage_category']    = (string) ($sec->triage_category ?? '—');
                $vars['general_appearance'] = (string) ($sec->general_appearance ?? '—');
                $vars['emergency_signs']    = ((int) ($sec->emergency_signs_present ?? 0)) === 1 ? 'PRESENT' : 'absent';
                $vars['syndrome_classification'] = (string) ($sec->syndrome_classification ?? '—');
                $vars['vitals_html']        = self::vitalsHtml($sec);
                $vars['case_risk_level']    = (string) ($sec->risk_level ?? $alert->risk_level ?? '');

                // ── Officer notes + disposition ────────────────────────────
                $vars['officer_notes']      = (string) ($sec->officer_notes ?? '—');
                $vars['final_disposition']  = (string) ($sec->final_disposition ?? '—');
                $vars['disposition_details']= (string) ($sec->disposition_details ?? '');
                $vars['screening_outcome']  = (string) ($sec->screening_outcome ?? '');
                $vars['followup_required']  = ((int) ($sec->followup_required ?? 0)) === 1 ? 'YES' : 'NO';
                $vars['followup_level']     = (string) ($sec->followup_assigned_level ?? '');
            } else {
                // No case file attached — minimal traveller block
                $vars['traveler_name']      = '— (no case file)';
                $vars['traveler_gender']    = '—';
                $vars['traveler_age']       = '—';
                $vars['traveler_nationality']= '—';
                $vars['vitals_html']        = '<p style="margin:0;color:#64748B;">No case file attached to this alert.</p>';
                $vars['syndrome_classification'] = '—';
                $vars['triage_category']    = '—';
                $vars['officer_notes']      = '—';
            }

            // ── Symptoms / exposures / samples / travel history / followups ─
            $vars['symptoms_html']   = self::symptomsHtml($symptoms);
            $vars['symptoms_count']  = (string) $symptoms->count();
            $vars['exposures_html']  = self::exposuresHtml($exposures);
            $vars['samples_html']    = self::samplesHtml($samples);
            $vars['samples_count']   = (string) $samples->count();
            $vars['travel_html']     = self::travelHtml($travel);
            $vars['suspected_html']  = self::suspectedHtml($suspected);
            $vars['suspected_count'] = (string) $suspected->count();
            $vars['followups_html']  = self::followupsHtml($followups);
            $vars['followups_count'] = (string) $followups->count();
            $vars['followups_overdue_count'] = (string) $followups->filter(function ($f) {
                return $f->due_at && strtotime((string) $f->due_at) < time()
                    && ! in_array(strtoupper((string) $f->status), ['COMPLETED', 'NOT_APPLICABLE'], true);
            })->count();
            $vars['sec_actions_html']= self::actionsHtml($secActions);

            // ── Prior-alert history at POE ─────────────────────────────────
            $vars['prior_alerts_html']  = self::priorAlertsHtml($priorAlerts);
            $vars['prior_alerts_count'] = (string) $priorAlerts->count();

            // ── Annex 2 criteria assessment ────────────────────────────────
            // IHR 2005 Annex 2 has four criteria; a disease event triggers
            // PHEIC assessment when criteria 1 OR 2 are met, AND criteria
            // 3 OR 4 are met.  We derive which criteria apply from the
            // disease intel profile and alert context.
            $ihrTier     = strtoupper((string) ($alert->ihr_tier ?? ''));
            $riskLevel   = strtoupper((string) ($alert->risk_level ?? ''));
            $a2criteria  = [];
            $cfr         = (float) ($intel['cfr_pct'] ?? 0);
            if ($ihrTier === 'TIER_1_ALWAYS_NOTIFIABLE') {
                $a2criteria[] = '<strong>Criterion 1 (Serious public health impact)</strong> — disease is on the WHO always-notifiable list; meets criterion by disease identity alone';
                $a2criteria[] = '<strong>Criterion 3 (Significant international spread risk)</strong> — Tier-1 status implies this by WHO regulation';
            } else {
                if ($riskLevel === 'CRITICAL' || $cfr > 5) {
                    $a2criteria[] = '<strong>Criterion 1 (Serious impact)</strong> — CRITICAL risk level and/or case fatality rate ' . ($cfr > 0 ? number_format($cfr, 1) . '%' : 'elevated');
                }
                if ($riskLevel === 'HIGH' || $riskLevel === 'CRITICAL') {
                    $a2criteria[] = '<strong>Criterion 2 (Unusual or unexpected event)</strong> — HIGH/CRITICAL risk classification indicates unusual severity';
                }
                $journeyStart = (string) ($sec->journey_start_country_code ?? '');
                if ($journeyStart && $journeyStart !== ($alert->country_code ?? '')) {
                    $a2criteria[] = '<strong>Criterion 3 (International spread risk)</strong> — traveller originated from ' . self::countryName($journeyStart) . '; cross-border exposure pathway confirmed';
                }
                if (in_array($riskLevel, ['CRITICAL','HIGH'], true)) {
                    $a2criteria[] = '<strong>Criterion 4 (Travel/trade restriction risk)</strong> — risk level warrants precautionary international travel advisory';
                }
            }
            $vars['annex2_score']          = (string) count($a2criteria);
            $vars['annex2_criteria_fired'] = empty($a2criteria)
                ? 'Assessment pending — insufficient data to determine specific criteria'
                : implode('<br>• ', array_merge([''], $a2criteria));
            $vars['annex2_criteria_text']  = empty($a2criteria)
                ? 'Assessment pending'
                : implode("\n• ", array_merge(['Criteria fired:'], array_map(fn($c) => strip_tags($c), $a2criteria)));

            // ── 7-1-7 clock (computed, not just timestamps) ────────────────
            $capturedTs   = strtotime((string) ($primary->captured_at ?? '')) ?: null;
            $createdTs    = strtotime((string) ($alert->created_at ?? '')) ?: null;
            $ackTs        = strtotime((string) ($alert->acknowledged_at ?? '')) ?: null;
            $nowTs        = time();
            // Detect phase: from first symptom capture to alert creation
            $detectH      = $capturedTs && $createdTs ? round(($createdTs - $capturedTs) / 3600, 1) : null;
            // Notify phase: from alert creation to acknowledgement
            $notifyH      = $createdTs && $ackTs ? round(($ackTs - $createdTs) / 3600, 1) : ($createdTs ? round(($nowTs - $createdTs) / 3600, 1) : null);
            // Respond phase: from acknowledgement to now (or closure)
            $respondH     = $ackTs ? round(($nowTs - $ackTs) / 3600, 1) : null;
            $vars['detect_hours_elapsed']  = $detectH  !== null ? (string) $detectH  . 'h' : '—';
            $vars['notify_hours_elapsed']  = $notifyH  !== null ? (string) $notifyH  . 'h' : '—';
            $vars['respond_hours_elapsed'] = $respondH !== null ? (string) $respondH . 'h' : '—';
            $vars['detect_status']  = $detectH  !== null ? ($detectH  <= 168 ? '✓ within 7 days' : '⚠ BREACHED (' . round($detectH /24,1)  . 'd)') : '—';
            $vars['notify_status']  = $notifyH  !== null ? ($notifyH  <= 24  ? '✓ within 24 hours' : '⚠ BREACHED (' . round($notifyH /24,1)  . 'd)') : 'not yet acknowledged';
            $vars['respond_status'] = $respondH !== null ? ($respondH <= 168 ? '✓ within 7 days' : '⚠ BREACHED (' . round($respondH/24,1) . 'd)') : '—';

            // ── Response deadline absolute timestamps ──────────────────────
            $vars['detect_deadline']  = $capturedTs ? date('Y-m-d H:i', $capturedTs + 7 * 86400)  : '—';
            $vars['notify_deadline']  = $createdTs  ? date('Y-m-d H:i', $createdTs  + 86400)        : '—';
            $vars['respond_deadline'] = $ackTs       ? date('Y-m-d H:i', $ackTs      + 7 * 86400)  : '—';

            // ── Template name for footer (NOT the alert_code) ─────────────
            // The footer bug: old templates showed "template {{alert_code}}"
            // which showed the case code (RWA-2026-00147) not the template name.
            // This token allows templates to identify themselves correctly.
            $vars['template_name'] = '(see notification_log.template_code)';

            // ── Disease-intel hero card (optional convenience) ─────────────
            $vars['disease_intel_html'] = self::diseaseIntelHtml($intel);

            // ── Top-line narrative summary ─────────────────────────────────
            $vars['summary'] = self::synthesiseSummary($vars);

            // ── Allow caller-supplied overrides (closed_by_name etc.) ──────
            foreach ($overrides as $k => $v) $vars[$k] = $v;

            return $vars;
        } catch (Throwable $e) {
            // Degraded payload — dispatcher will still render + send.
            return array_merge([
                'alert_code'    => (string) ($alert->alert_code ?? ''),
                'alert_title'   => (string) ($alert->alert_title ?? ''),
                'alert_details' => (string) ($alert->alert_details ?? ''),
                'risk_level'    => (string) ($alert->risk_level ?? ''),
                'poe_code'      => (string) ($alert->poe_code ?? ''),
                'country_code'  => (string) ($alert->country_code ?? ''),
                'country_name'  => self::countryName((string) ($alert->country_code ?? '')),
                'ack_hours'     => self::ackSlaHours((string) ($alert->risk_level ?? '')),
                'summary'       => 'Alert generated — context hydration failed, see console.',
            ], $overrides);
        }
    }

    /**
     * Assemble vars for a screening-referral notification where we don't yet
     * have a persisted alerts row (primary screening -> secondary referral).
     */
    /**
     * Lean variable payload for a SCREENING_REFERRAL email.
     *
     * Contains ONLY data available from primary screening — no secondary
     * screening fields, no disease differential, no exposures, no vitals
     * from secondary.  Empty placeholders are never rendered.
     */
    public static function forScreening(object $screening, ?object $notification = null, array $overrides = []): array
    {
        try {
            $cc       = (string) ($screening->country_code ?? 'RW');
            $priority = strtoupper((string) ($notification->priority ?? 'HIGH'));

            // Screener identity
            $screenerName = '—';
            if (($screening->captured_by_user_id ?? 0) > 0) {
                $u = DB::table('users')->where('id', (int) $screening->captured_by_user_id)->first(['full_name','username']);
                $screenerName = $u ? ((string) ($u->full_name ?: $u->username)) : '—';
            }

            // Temperature — only include when measured
            $tempStr = ($screening->temperature_value !== null && $screening->temperature_value !== '')
                ? number_format((float) $screening->temperature_value, 1) . ' °' . ($screening->temperature_unit ?? 'C')
                : null;

            // Quick symptoms chip list — stored in IDB only, not in server DB schema.
            // Read defensively; absent on server-side screening objects.
            $quickChips = [];
            try {
                $raw = property_exists($screening, 'quick_symptoms_json')
                    ? ($screening->quick_symptoms_json ?? null)
                    : null;
                if ($raw) {
                    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
                    if (is_array($decoded)) $quickChips = $decoded;
                }
            } catch (\Throwable) { /* swallow — field absent on server */ }

            // Direction label
            $directionLabel = match (strtoupper((string) ($screening->traveler_direction ?? ''))) {
                'ENTRY', 'ARRIVING'   => 'Arriving (Entry)',
                'EXIT',  'DEPARTING'  => 'Departing (Exit)',
                'TRANSIT'             => 'Transit',
                default               => (string) ($screening->traveler_direction ?? '—'),
            };

            // Priority colour + label for email styling
            $priorityColor = match ($priority) {
                'CRITICAL' => '#DC2626',
                'HIGH'     => '#D97706',
                default    => '#6366F1',
            };
            $priorityLabel = match ($priority) {
                'CRITICAL' => '🔴 CRITICAL',
                'HIGH'     => '🟡 HIGH',
                default    => '🔵 ' . $priority,
            };

            $vars = array_merge(\App\Services\AdminLinks::generalVars(), [
                // ── Identity ────────────────────────────────────────────
                'country_code'        => $cc,
                'country_name'        => self::countryName($cc),
                'poe_code'            => (string) ($screening->poe_code    ?? '—'),
                'district_code'       => (string) ($screening->district_code ?? '—'),
                'province_code'       => (string) ($screening->province_code ?? ''),

                // ── Referral metadata ───────────────────────────────────
                'referral_id'         => (string) ($notification->client_uuid ?? ('REFERRAL-' . ($screening->id ?? '?'))),
                'notification_priority' => $priority,
                'priority_label'        => $priorityLabel,
                'priority_color'        => $priorityColor,
                'notification_reason'   => (string) ($notification->reason_code ?? 'PRIMARY_SYMPTOMS_DETECTED'),

                // ── What the screener captured ──────────────────────────
                'traveler_direction'  => $directionLabel,
                'traveler_gender'     => ucfirst(strtolower((string) ($screening->gender ?? '—'))),
                'traveler_name'       => (string) ($screening->traveler_full_name ?? ''),
                // Pre-rendered suffix so template never shows empty " · " or Mustache tags
                'traveler_name_suffix' => ($screening->traveler_full_name ?? '')
                    ? ' · ' . (string) $screening->traveler_full_name
                    : '',
                'temperature'         => $tempStr,         // null when not measured
                'temperature_display' => $tempStr ?? 'Not measured',
                'symptoms_present'    => ((int) ($screening->symptoms_present ?? 0)) === 1 ? 'YES' : 'No',
                'quick_symptoms'      => empty($quickChips) ? '—' : implode(', ', array_map('ucfirst', $quickChips)),
                'quick_symptoms_count'=> (string) count($quickChips),

                // ── Screener + timing ───────────────────────────────────
                'screener_name'       => $screenerName,
                'captured_at'         => self::fmtDateTime($screening->captured_at ?? null),
                'captured_at_ago'     => self::ago($screening->captured_at ?? null),

                // ── Action guidance ─────────────────────────────────────
                'secondary_queue_url' => \App\Services\AdminLinks::alertsHub(),
                'console_url'         => \App\Services\AdminLinks::alertsHub(),
                'action_url'          => \App\Services\AdminLinks::alertsHub(),

                // ── Timestamps ──────────────────────────────────────────
                'now'                 => now()->format('Y-m-d H:i'),
                'now_date'            => now()->format('Y-m-d'),
            ], $overrides);

            return $vars;
        } catch (\Throwable $e) {
            return array_merge([
                'referral_id'         => 'REFERRAL-' . ($screening->id ?? '?'),
                'priority_label'      => '🟡 HIGH',
                'priority_color'      => '#D97706',
                'poe_code'            => (string) ($screening->poe_code ?? '—'),
                'country_name'        => self::countryName((string) ($screening->country_code ?? '')),
                'symptoms_present'    => 'YES',
                'quick_symptoms'      => '—',
                'temperature_display' => '—',
                'traveler_direction'  => '—',
                'traveler_gender'     => '—',
                'screener_name'       => '—',
                'captured_at'         => '—',
                'console_url'         => \App\Services\AdminLinks::alertsHub(),
                'action_url'          => \App\Services\AdminLinks::alertsHub(),
                'now'                 => now()->format('Y-m-d H:i'),
            ], $overrides);
        }
    }

    /** Vars for followup due / overdue reminders. */
    public static function forFollowup(object $followup, ?object $alert = null, array $overrides = []): array
    {
        try {
            if (! $alert && ($followup->alert_id ?? 0) > 0) {
                $alert = DB::table('alerts')->where('id', (int) $followup->alert_id)->first();
            }
            $vars = $alert ? self::forAlert($alert) : [];

            $due = $followup->due_at ? Carbon::parse((string) $followup->due_at) : null;
            $overdueMin = $due ? max(0, now()->diffInMinutes($due, false) * -1) : 0;

            $assignee = null;
            if (($followup->assigned_to_user_id ?? 0) > 0) {
                $assignee = DB::table('users')->where('id', (int) $followup->assigned_to_user_id)->value('full_name');
            }

            $vars = array_merge($vars, [
                'followup_action_code'  => (string) ($followup->action_code ?? ''),
                'followup_action_label' => (string) ($followup->action_label ?? ''),
                'followup_status'       => (string) ($followup->status ?? 'PENDING'),
                'followup_due_at'       => self::fmtDateTime($followup->due_at ?? null),
                'followup_overdue_minutes' => (string) $overdueMin,
                'followup_overdue_hours'   => (string) (int) round($overdueMin / 60),
                'followup_notes'        => (string) ($followup->notes ?? ''),
                'followup_assignee'     => $assignee ?: ((string) ($followup->assigned_to_role ?? '—')),
                'followup_blocks_closure' => ((int) ($followup->blocks_closure ?? 0)) === 1 ? 'YES — blocks alert closure' : 'no',
            ], $overrides);
            return $vars;
        } catch (Throwable $e) {
            return array_merge([
                'followup_action_code' => (string) ($followup->action_code ?? ''),
                'followup_action_label'=> (string) ($followup->action_label ?? ''),
                'followup_due_at'      => self::fmtDateTime($followup->due_at ?? null),
            ], $overrides);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  HTML fragment builders
    // ═════════════════════════════════════════════════════════════════════

    private static function vitalsHtml(object $sec): string
    {
        $rows = [];
        $temp = $sec->temperature_value ?? null;
        if ($temp !== null) {
            $hot = (float) $temp >= 38.0;
            $rows[] = self::vitalRow('Temperature', number_format((float) $temp, 1) . ' °' . ($sec->temperature_unit ?? 'C'), $hot ? '#B91C1C' : null);
        }
        if ($sec->pulse_rate) {
            $abn = (int) $sec->pulse_rate < 50 || (int) $sec->pulse_rate > 120;
            $rows[] = self::vitalRow('Pulse', $sec->pulse_rate . ' bpm', $abn ? '#B91C1C' : null);
        }
        if ($sec->respiratory_rate) {
            $abn = (int) $sec->respiratory_rate > 22 || (int) $sec->respiratory_rate < 10;
            $rows[] = self::vitalRow('Respiratory rate', $sec->respiratory_rate . ' /min', $abn ? '#B91C1C' : null);
        }
        if ($sec->bp_systolic || $sec->bp_diastolic) {
            $bp = ($sec->bp_systolic ?? '?') . '/' . ($sec->bp_diastolic ?? '?') . ' mmHg';
            $abn = ((int) ($sec->bp_systolic ?? 120) < 90);
            $rows[] = self::vitalRow('Blood pressure', $bp, $abn ? '#B91C1C' : null);
        }
        if ($sec->oxygen_saturation) {
            $abn = (float) $sec->oxygen_saturation < 94;
            $rows[] = self::vitalRow('SpO₂', number_format((float) $sec->oxygen_saturation, 0) . ' %', $abn ? '#B91C1C' : null);
        }
        if (empty($rows)) return '<tr><td style="padding:8px 12px;color:#64748B;">No vitals recorded</td></tr>';
        return implode('', $rows);
    }

    private static function vitalRow(string $label, string $value, ?string $abnormalColor = null): string
    {
        $v = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $l = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $style = $abnormalColor
            ? "color:{$abnormalColor};font-weight:700;"
            : 'color:#0F172A;font-weight:600;';
        return "<tr><td style=\"padding:6px 12px;color:#475569;font-size:13px;\">{$l}</td>"
             . "<td style=\"padding:6px 12px;font-size:13px;text-align:right;{$style}\">{$v}</td></tr>";
    }

    private static function symptomsHtml($symptoms): string
    {
        if ($symptoms->isEmpty()) {
            return '<p style="margin:0;color:#64748B;font-size:13px;">No symptoms recorded.</p>';
        }
        $present = $symptoms->where('is_present', 1)->where('explicit_absent', 0);
        $absent  = $symptoms->where('explicit_absent', 1);
        $out = '';
        if ($present->isNotEmpty()) {
            $items = $present->map(function ($s) {
                $onset = $s->onset_date ? ' <span style="color:#64748B;">(onset ' . htmlspecialchars((string) $s->onset_date, ENT_QUOTES, 'UTF-8') . ')</span>' : '';
                $note = $s->details ? ' — ' . htmlspecialchars((string) $s->details, ENT_QUOTES, 'UTF-8') : '';
                return '<li style="margin:4px 0;color:#0F172A;"><strong>' . htmlspecialchars(self::humanise((string) $s->symptom_code), ENT_QUOTES, 'UTF-8') . '</strong>' . $onset . $note . '</li>';
            })->implode('');
            $out .= '<p style="margin:0 0 4px;color:#B91C1C;font-weight:700;font-size:13px;">Symptoms PRESENT (' . $present->count() . ')</p>';
            $out .= '<ul style="margin:0 0 12px 18px;padding:0;font-size:13px;">' . $items . '</ul>';
        }
        if ($absent->isNotEmpty()) {
            $items = $absent->map(function ($s) {
                return '<li style="margin:2px 0;color:#475569;">' . htmlspecialchars(self::humanise((string) $s->symptom_code), ENT_QUOTES, 'UTF-8') . '</li>';
            })->implode('');
            $out .= '<p style="margin:0 0 4px;color:#047857;font-weight:600;font-size:13px;">Explicitly ABSENT</p>';
            $out .= '<ul style="margin:0 0 4px 18px;padding:0;font-size:12px;">' . $items . '</ul>';
        }
        return $out;
    }

    private static function exposuresHtml($exposures): string
    {
        if ($exposures->isEmpty()) return '<p style="margin:0;color:#64748B;font-size:13px;">No exposures recorded.</p>';
        $items = $exposures->map(function ($e) {
            $resp = strtoupper((string) ($e->response ?? 'UNKNOWN'));
            $color = $resp === 'YES' ? '#B91C1C' : ($resp === 'NO' ? '#047857' : '#64748B');
            $note = $e->details ? ' — ' . htmlspecialchars((string) $e->details, ENT_QUOTES, 'UTF-8') : '';
            return '<li style="margin:4px 0;font-size:13px;"><strong style="color:' . $color . ';">[' . $resp . ']</strong> ' . htmlspecialchars(self::humanise((string) $e->exposure_code), ENT_QUOTES, 'UTF-8') . $note . '</li>';
        })->implode('');
        return '<ul style="margin:0;padding-left:18px;">' . $items . '</ul>';
    }

    private static function samplesHtml($samples): string
    {
        if ($samples->isEmpty()) {
            return '<p style="margin:0;color:#B91C1C;font-size:13px;font-weight:600;">⚠ No specimens collected.</p>';
        }
        $items = $samples->map(function ($s) {
            $parts = [];
            $parts[] = htmlspecialchars((string) ($s->sample_type ?? 'sample'), ENT_QUOTES, 'UTF-8');
            if ($s->sample_identifier) $parts[] = 'ID: ' . htmlspecialchars((string) $s->sample_identifier, ENT_QUOTES, 'UTF-8');
            if ($s->lab_destination) $parts[] = '→ ' . htmlspecialchars((string) $s->lab_destination, ENT_QUOTES, 'UTF-8');
            if ($s->collected_at) $parts[] = htmlspecialchars((string) $s->collected_at, ENT_QUOTES, 'UTF-8');
            return '<li style="margin:4px 0;font-size:13px;color:#0F172A;">' . implode(' · ', $parts) . '</li>';
        })->implode('');
        return '<ul style="margin:0;padding-left:18px;">' . $items . '</ul>';
    }

    private static function travelHtml($travel): string
    {
        if ($travel->isEmpty()) return '<p style="margin:0;color:#64748B;font-size:13px;">No travel history recorded.</p>';
        $items = $travel->map(function ($t) {
            $role = strtoupper((string) ($t->travel_role ?? 'VISITED'));
            $dates = '';
            if ($t->arrival_date || $t->departure_date) {
                $dates = ' <span style="color:#64748B;">(' . htmlspecialchars((string) ($t->arrival_date ?? ''), ENT_QUOTES, 'UTF-8')
                    . ' → ' . htmlspecialchars((string) ($t->departure_date ?? ''), ENT_QUOTES, 'UTF-8') . ')</span>';
            }
            return '<li style="margin:4px 0;font-size:13px;"><strong>' . $role . '</strong> · ' . htmlspecialchars(self::countryName((string) $t->country_code), ENT_QUOTES, 'UTF-8') . $dates . '</li>';
        })->implode('');
        return '<ul style="margin:0;padding-left:18px;">' . $items . '</ul>';
    }

    private static function suspectedHtml($suspected): string
    {
        if ($suspected->isEmpty()) return '<p style="margin:0;color:#64748B;font-size:13px;">No differential recorded.</p>';
        $items = $suspected->map(function ($d) {
            $intel = DiseaseIntel::get((string) $d->disease_code);
            $confidence = $d->confidence !== null ? number_format((float) $d->confidence, 0) . '%' : '—';
            $cfr = $intel['cfr_pct'] !== null ? number_format((float) $intel['cfr_pct'], 1) . '% CFR' : '';
            $tier = htmlspecialchars((string) ($intel['who_category'] ?? ''), ENT_QUOTES, 'UTF-8');
            $reasoning = $d->reasoning ? '<div style="color:#475569;font-size:12px;margin-top:2px;">' . htmlspecialchars((string) $d->reasoning, ENT_QUOTES, 'UTF-8') . '</div>' : '';
            return '<li style="margin:6px 0;font-size:13px;">'
                . '<strong>#' . (int) $d->rank_order . ' · ' . htmlspecialchars((string) $intel['name'], ENT_QUOTES, 'UTF-8') . '</strong> '
                . '<span style="color:#475569;">· confidence ' . $confidence . ' · ' . $cfr . '</span>'
                . '<div style="color:#64748B;font-size:11px;">' . $tier . '</div>'
                . $reasoning
                . '</li>';
        })->implode('');
        return '<ul style="margin:0;padding-left:18px;">' . $items . '</ul>';
    }

    private static function followupsHtml($followups): string
    {
        if ($followups->isEmpty()) return '<p style="margin:0;color:#64748B;font-size:13px;">No follow-ups seeded.</p>';
        $items = $followups->map(function ($f) {
            $status = strtoupper((string) ($f->status ?? 'PENDING'));
            $overdue = $f->due_at && strtotime((string) $f->due_at) < time()
                && ! in_array($status, ['COMPLETED', 'NOT_APPLICABLE'], true);
            $color = match ($status) {
                'COMPLETED' => '#047857',
                'IN_PROGRESS' => '#0369A1',
                'BLOCKED' => '#B91C1C',
                'NOT_APPLICABLE' => '#64748B',
                default => $overdue ? '#B91C1C' : '#B45309',
            };
            $due = $f->due_at
                ? ' <span style="color:#64748B;">(due ' . htmlspecialchars((string) $f->due_at, ENT_QUOTES, 'UTF-8') . ')</span>'
                : '';
            return '<li style="margin:4px 0;font-size:13px;"><strong style="color:' . $color . ';">[' . $status . ']</strong> '
                . htmlspecialchars((string) $f->action_label, ENT_QUOTES, 'UTF-8') . $due . '</li>';
        })->implode('');
        return '<ul style="margin:0;padding-left:18px;">' . $items . '</ul>';
    }

    private static function actionsHtml($actions): string
    {
        if ($actions->isEmpty()) return '<p style="margin:0;color:#64748B;font-size:13px;">No officer actions recorded.</p>';
        $items = $actions->map(function ($a) {
            $done = ((int) $a->is_done) === 1;
            $color = $done ? '#047857' : '#B45309';
            $mark = $done ? '✓' : '○';
            $note = $a->details ? ' — ' . htmlspecialchars((string) $a->details, ENT_QUOTES, 'UTF-8') : '';
            return '<li style="margin:4px 0;font-size:13px;color:' . $color . ';">' . $mark . ' ' . htmlspecialchars(self::humanise((string) $a->action_code), ENT_QUOTES, 'UTF-8') . $note . '</li>';
        })->implode('');
        return '<ul style="margin:0;padding-left:18px;list-style:none;">' . $items . '</ul>';
    }

    private static function priorAlertsHtml($prior): string
    {
        if ($prior->isEmpty()) return '<p style="margin:0;color:#64748B;font-size:13px;">No other alerts at this POE in the past 30 days.</p>';
        $items = $prior->map(function ($a) {
            return '<li style="margin:4px 0;font-size:12px;">'
                . '<strong>' . htmlspecialchars((string) $a->alert_code, ENT_QUOTES, 'UTF-8') . '</strong> · '
                . htmlspecialchars((string) $a->risk_level, ENT_QUOTES, 'UTF-8') . ' · '
                . htmlspecialchars((string) $a->status, ENT_QUOTES, 'UTF-8') . ' · '
                . htmlspecialchars((string) $a->created_at, ENT_QUOTES, 'UTF-8') . '<br>'
                . '<span style="color:#64748B;">' . htmlspecialchars((string) $a->alert_title, ENT_QUOTES, 'UTF-8') . '</span>'
                . '</li>';
        })->implode('');
        return '<ul style="margin:0;padding-left:18px;">' . $items . '</ul>';
    }

    private static function listHtml(array $items): string
    {
        if (empty($items)) return '<p style="margin:0;color:#64748B;font-size:13px;">—</p>';
        $lis = array_map(function ($x) {
            return '<li style="margin:4px 0;color:#0F172A;">' . htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8') . '</li>';
        }, $items);
        return '<ul style="margin:0;padding-left:18px;font-size:13px;">' . implode('', $lis) . '</ul>';
    }

    private static function diseaseIntelHtml(array $intel): string
    {
        $name = htmlspecialchars((string) ($intel['name'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $tier = htmlspecialchars((string) ($intel['who_category'] ?? ''), ENT_QUOTES, 'UTF-8');
        $cfr  = $intel['cfr_pct'] !== null ? number_format((float) $intel['cfr_pct'], 1) . '%' : 'unknown';
        $inc  = htmlspecialchars((string) ($intel['incubation'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tx   = htmlspecialchars((string) ($intel['transmission'] ?? ''), ENT_QUOTES, 'UTF-8');
        $ppe  = htmlspecialchars((string) ($intel['ppe'] ?? ''), ENT_QUOTES, 'UTF-8');
        $iso  = htmlspecialchars((string) ($intel['isolation'] ?? ''), ENT_QUOTES, 'UTF-8');
        $ihr  = htmlspecialchars((string) ($intel['ihr_notification'] ?? ''), ENT_QUOTES, 'UTF-8');
        $def  = htmlspecialchars((string) ($intel['case_definition'] ?? ''), ENT_QUOTES, 'UTF-8');
        $diff = htmlspecialchars((string) ($intel['differential'] ?? ''), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FEF3C7;border-left:4px solid #B45309;border-radius:6px;margin:12px 0;">
  <tr><td style="padding:14px 16px;">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:#92400E;font-weight:700;">Disease intelligence</div>
    <div style="font-size:17px;font-weight:700;color:#0F172A;margin:4px 0 2px;">{$name}</div>
    <div style="font-size:12px;color:#475569;margin-bottom:10px;">{$tier}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:12px;color:#0F172A;">
      <tr><td style="padding:3px 0;color:#64748B;">Case fatality rate</td><td style="padding:3px 0;text-align:right;font-weight:700;">{$cfr}</td></tr>
      <tr><td style="padding:3px 0;color:#64748B;">Incubation</td><td style="padding:3px 0;text-align:right;">{$inc}</td></tr>
      <tr><td style="padding:3px 0;color:#64748B;vertical-align:top;">Transmission</td><td style="padding:3px 0;text-align:right;">{$tx}</td></tr>
      <tr><td style="padding:3px 0;color:#64748B;vertical-align:top;">PPE</td><td style="padding:3px 0;text-align:right;">{$ppe}</td></tr>
      <tr><td style="padding:3px 0;color:#64748B;vertical-align:top;">Isolation</td><td style="padding:3px 0;text-align:right;">{$iso}</td></tr>
      <tr><td style="padding:3px 0;color:#64748B;vertical-align:top;">IHR notification</td><td style="padding:3px 0;text-align:right;">{$ihr}</td></tr>
    </table>
    <div style="margin-top:10px;font-size:12px;color:#0F172A;"><strong>WHO case definition:</strong> {$def}</div>
    <div style="margin-top:6px;font-size:12px;color:#0F172A;"><strong>Differential:</strong> {$diff}</div>
  </td></tr>
</table>
HTML;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Primitives
    // ═════════════════════════════════════════════════════════════════════

    private static function synthesiseSummary(array $v): string
    {
        $parts = [];
        $parts[] = '[' . ($v['risk_level'] ?? 'UNK') . ']';
        if (! empty($v['disease_name']) && $v['disease_name'] !== $v['disease_code']) $parts[] = $v['disease_name'];
        if (! empty($v['poe_code'])) $parts[] = 'POE ' . $v['poe_code'];
        if (! empty($v['country_name'])) $parts[] = $v['country_name'];
        if (! empty($v['traveler_gender']) && $v['traveler_gender'] !== '—') $parts[] = $v['traveler_gender'];
        if (! empty($v['traveler_age']) && $v['traveler_age'] !== '—') $parts[] = $v['traveler_age'];
        if (! empty($v['syndrome_classification']) && $v['syndrome_classification'] !== '—') $parts[] = 'syndrome: ' . $v['syndrome_classification'];
        if (! empty($v['disease_cfr_pct']) && $v['disease_cfr_pct'] !== 'unknown') $parts[] = 'CFR ' . $v['disease_cfr_pct'];
        return implode(' · ', $parts);
    }

    private static function ackSlaHours(string $risk): int
    {
        return match (strtoupper($risk)) {
            'CRITICAL' => 4,
            'HIGH'     => 24,
            'MEDIUM'   => 48,
            'LOW'      => 72,
            default    => 24,
        };
    }

    private static function ackDeadline(object $alert): string
    {
        try {
            $base = $alert->created_at ? Carbon::parse((string) $alert->created_at) : now();
            return $base->addHours(self::ackSlaHours((string) ($alert->risk_level ?? '')))->format('Y-m-d H:i');
        } catch (Throwable $e) {
            return now()->addHours(24)->format('Y-m-d H:i');
        }
    }

    private static function riskLabel(string $risk): string
    {
        return match (strtoupper($risk)) {
            'CRITICAL' => 'CRITICAL — life-threatening',
            'HIGH'     => 'HIGH — public-health priority',
            'MEDIUM'   => 'MEDIUM — active surveillance',
            'LOW'      => 'LOW — routine follow-up',
            default    => $risk ?: 'UNSPECIFIED',
        };
    }

    private static function ladderDescription(string $level): string
    {
        return match (strtoupper($level)) {
            'POE'      => 'POE → DISTRICT → PHEOC → NATIONAL',
            'DISTRICT' => 'DISTRICT → PHEOC → NATIONAL',
            'PHEOC'    => 'PHEOC → NATIONAL',
            'NATIONAL' => 'NATIONAL → WHO IHR NFP',
            'WHO'      => 'WHO IHR NFP',
            default    => $level,
        };
    }

    // PRESERVED: multi-country name lookup (Category B reference, not system scope).
    private static function countryName(string $code): string
    {
        return match (strtoupper($code)) {
            'UG' => 'Uganda',
            'RW' => 'Rwanda',
            'ZM' => 'Zambia',
            'MW' => 'Malawi',
            'ST' => 'São Tomé and Príncipe',
            'STP'=> 'São Tomé and Príncipe',
            'KE' => 'Kenya',
            'TZ' => 'Tanzania',
            'CD' => 'DR Congo',
            'SS' => 'South Sudan',
            'SD' => 'Sudan',
            'BI' => 'Burundi',
            ''   => '—',
            default => $code,
        };
    }

    private static function fmtDateTime($x): string
    {
        if (! $x) return '—';
        try { return Carbon::parse((string) $x)->format('Y-m-d H:i'); }
        catch (Throwable $e) { return (string) $x; }
    }

    private static function ago($x): string
    {
        if (! $x) return '—';
        try { return Carbon::parse((string) $x)->diffForHumans(); }
        catch (Throwable $e) { return '—'; }
    }

    /** Replace any string starting with 3+ non-space chars with first-letter + last-initial. */
    private static function maskName(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        $parts = preg_split('/\s+/', $s) ?: [$s];
        if (count($parts) < 2) return substr($parts[0], 0, 1) . '***';
        $first = substr($parts[0], 0, 1);
        $last  = substr(end($parts), 0, 1);
        return strtoupper($first . '. ' . $last . '.');
    }

    private static function maskPhone(string $s): string
    {
        $s = preg_replace('/\s+/', '', $s) ?? $s;
        $n = strlen($s);
        if ($n < 4) return $s ?: '—';
        return substr($s, 0, 3) . str_repeat('•', max(0, $n - 5)) . substr($s, -2);
    }

    private static function maskEmail(string $s): string
    {
        if (! str_contains($s, '@')) return $s ?: '—';
        [$local, $domain] = explode('@', $s, 2);
        $first = substr($local, 0, 1);
        return $first . '***@' . $domain;
    }

    private static function humanise(string $code): string
    {
        return ucfirst(str_replace('_', ' ', $code));
    }
}
