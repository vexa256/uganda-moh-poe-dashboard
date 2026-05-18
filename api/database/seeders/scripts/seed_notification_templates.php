<?php
/**
 * seed_notification_templates.php
 *
 * Idempotent seeder for the `notification_templates` table. Populates every
 * template_code that the NotificationDispatcher references so real-time alert
 * emails (ALERT_CRITICAL/HIGH, TIER1/2_ADVISORY, PHEIC_ADVISORY, ANNEX2_HIT,
 * ESCALATION, SECONDARY_SCREENING_OPENED, SCREENING_REFERRAL, ALERT_CASE_FILE,
 * ALERT_CLOSED, BREACH_717, FOLLOWUP_DUE/OVERDUE, RESPONDER_INFO_REQUEST,
 * WEEKLY_REPORT, DAILY_REPORT, NATIONAL_INTELLIGENCE) actually render and
 * dispatch instead of silently returning "Template '<code>' not found".
 *
 * USAGE
 *   php seed_notification_templates.php <host> <db> <user> <pass>
 *   php seed_notification_templates.php --verify-only <host> <db> <user> <pass>
 *
 * SAFETY
 *   - Uses INSERT ... ON DUPLICATE KEY UPDATE on (template_code, channel) so
 *     re-runs overwrite cleanly. Manual edits made via DB are LOST on re-run —
 *     this is the source of truth.
 *   - Every template is rendered against a comprehensive mock var bag and any
 *     unresolved {{placeholder}} aborts the run with a clear error message.
 *   - No network calls, no Mail::send — purely DB upsert.
 *
 * DESIGN DISCIPLINE (every template adheres to these)
 *   - 640px max-width centered table; mobile-responsive to 320px
 *   - Inline CSS only; no <style> blocks; no JS; no external images
 *   - Dark navy header bar #0F172A; outer #F8FAFC; white card with 12px radius
 *   - Geist-equivalent system stack; 20px h1; 13.5px body; 11px metadata
 *   - Risk pill colours: red #B91C1C / amber #B45309 / green #047857
 *   - One primary CTA per email pointing at {{action_url}}
 *   - Plain-text alternative populated for every template
 *   - Triple-brace {{{var}}} used for pre-rendered HTML fragments only
 */

declare(strict_types=1);

// ─── Argument parsing ────────────────────────────────────────────────────────
$verifyOnly = false;
$argvCopy = $argv;
array_shift($argvCopy);
if (($argvCopy[0] ?? '') === '--verify-only') {
    $verifyOnly = true;
    array_shift($argvCopy);
}

if (count($argvCopy) < 4) {
    fwrite(STDERR, "usage: php seed_notification_templates.php [--verify-only] <host> <db> <user> <pass>\n");
    exit(2);
}
[$host, $db, $user, $pass] = $argvCopy;

// ─── Connect ────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] DB connect failed: " . $e->getMessage() . "\n");
    exit(3);
}

// Confirm table exists before doing anything
$tblExists = $pdo->query("SHOW TABLES LIKE 'notification_templates'")->fetchColumn();
if ($tblExists === false) {
    fwrite(STDERR, "[FATAL] table notification_templates does not exist in DB '{$db}'\n");
    exit(4);
}

// ─── Layout primitives ───────────────────────────────────────────────────────

/**
 * Build a full HTML email wrapper around inner body markup.
 * Header tone: 'critical' (red), 'high' (amber), 'advisory' (navy), 'success'
 * (green), 'info' (slate), 'breach' (deep red).
 */
function shell(string $eyebrow, string $title, string $deck, string $inner, string $tone = 'advisory'): string
{
    $bar = match ($tone) {
        'critical' => '#B91C1C',
        'high'     => '#B45309',
        'success'  => '#047857',
        'breach'   => '#7F1D1D',
        'info'     => '#1D4ED8',
        default    => '#0F172A',
    };
    return ''
        . '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title></head>'
        . '<body style="margin:0;background:#F8FAFC;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#0F172A;line-height:1.45;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F8FAFC;padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="max-width:640px;width:100%;background:#FFFFFF;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,0.08);">'
        . '<tr><td style="padding:16px 24px;background:' . $bar . ';color:#FFFFFF;font-weight:600;font-size:13px;letter-spacing:.08em;text-transform:uppercase;">'
        .   '🇺🇬 UGANDA POE SENTINEL · ' . htmlspecialchars($eyebrow, ENT_QUOTES)
        . '</td></tr>'
        . '<tr><td style="padding:24px 24px 6px 24px;">'
        .   '<div style="font-size:11px;color:#64748B;letter-spacing:.06em;text-transform:uppercase;font-weight:600;">{{now}} · Africa/Kampala</div>'
        .   '<h1 style="margin:8px 0 6px 0;font-size:20px;line-height:1.3;color:#0F172A;font-weight:700;">' . htmlspecialchars($title, ENT_QUOTES) . '</h1>'
        .   '<p style="margin:0;color:#475569;font-size:13.5px;">' . htmlspecialchars($deck, ENT_QUOTES) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:14px 24px 20px 24px;">' . $inner . '</td></tr>'
        . '<tr><td style="padding:14px 24px;background:#F8FAFC;border-top:1px solid #E2E8F0;font-size:11px;color:#64748B;line-height:1.5;">'
        .   'Sent by the Uganda Points-of-Entry Surveillance System on behalf of the Ministry of Health. '
        .   'This is an automated transactional message — replies are not monitored. '
        .   'To change recipient routing, contact the National POE focal point.'
        . '</td></tr>'
        . '</table></td></tr></table>'
        . '</body></html>';
}

/** Two-column key/value grid; each $rows is [label, value]. */
function factGrid(array $rows): string
{
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 14px 0;border-collapse:collapse;">';
    foreach ($rows as [$label, $value]) {
        $h .= '<tr>'
            . '<td style="padding:7px 10px 7px 0;width:42%;font-size:12px;color:#64748B;border-bottom:1px solid #F1F5F9;letter-spacing:.02em;text-transform:uppercase;font-weight:600;">'
            .   $label
            . '</td>'
            . '<td style="padding:7px 0;font-size:13.5px;color:#0F172A;border-bottom:1px solid #F1F5F9;">'
            .   $value
            . '</td></tr>';
    }
    return $h . '</table>';
}

/** Section heading inside the card. */
function sectionH(string $label, string $accent = '#0F172A'): string
{
    return '<div style="font-size:11.5px;color:' . $accent . ';font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin:16px 0 8px 0;">' . htmlspecialchars($label, ENT_QUOTES) . '</div>';
}

/** Coloured tone strip — used for "what to do now" callouts. */
function calloutBox(string $title, string $body, string $tone = 'info'): string
{
    $bg  = match ($tone) { 'red' => '#FEF2F2', 'amber' => '#FFFBEB', 'green' => '#ECFDF5', default => '#F1F5F9' };
    $bd  = match ($tone) { 'red' => '#FCA5A5', 'amber' => '#FCD34D', 'green' => '#86EFAC', default => '#CBD5E1' };
    $col = match ($tone) { 'red' => '#B91C1C', 'amber' => '#B45309', 'green' => '#047857', default => '#0F172A' };
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 14px 0;">'
        . '<tr><td style="padding:12px 14px;background:' . $bg . ';border:1px solid ' . $bd . ';border-radius:8px;">'
        . '<div style="font-size:11px;color:' . $col . ';font-weight:700;letter-spacing:.06em;text-transform:uppercase;margin-bottom:4px;">' . htmlspecialchars($title, ENT_QUOTES) . '</div>'
        . '<div style="font-size:13px;color:#0F172A;line-height:1.5;">' . $body . '</div>'
        . '</td></tr></table>';
}

/** Primary CTA button — kept inline so ensureCtaAppended() detects it. */
function primaryCta(string $label = 'Open in Command Centre'): string
{
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0 6px 0;">'
        . '<tr><td align="center">'
        . '<a href="{{action_url}}" style="display:inline-block;background:#0F172A;color:#FFFFFF;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;letter-spacing:.02em;">'
        . htmlspecialchars($label, ENT_QUOTES) . ' &rarr;'
        . '</a>'
        . '<div style="margin-top:8px;font-size:11px;color:#64748B;">'
        . 'Or paste this into your browser: <span style="color:#0F172A;word-break:break-all;">{{action_url}}</span>'
        . '</div>'
        . '</td></tr></table>';
}

/**
 * Risk pill — uses the polished {{risk_level_label}} which CaseContextBuilder
 * provides. Colour is hard-coded HIGH amber by default because mustache cannot
 * branch — callers pick the right tone with their template choice.
 */
function riskPill(string $bg, string $col, string $token = '{{risk_level_label}}'): string
{
    return '<span style="display:inline-block;padding:3px 10px;background:' . $bg . ';color:' . $col . ';border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">' . $token . '</span>';
}

// ─── Template specifications ────────────────────────────────────────────────
// Every entry maps a template_code → [subject, html_inner, plaintext, levels, ai].

$T = [];

// ============================================================================
//  ALERT_CRITICAL  — new CRITICAL-risk alert, full case context
// ============================================================================
$alertInner = ''
    . '<div style="margin-bottom:14px;">' . riskPill('#FEF2F2', '#B91C1C') . '</div>'
    . factGrid([
        ['Alert reference', '<strong>{{alert_code}}</strong>'],
        ['Disease / syndrome', '{{disease_name}}'],
        ['IHR classification', '{{ihr_tier}}'],
        ['Point of entry', '{{poe_code}}'],
        ['District', '{{district_code}} · {{country_name}}'],
        ['Routed to', '{{routed_to_level}}'],
        ['Raised at', '{{alert_created_at}} ({{alert_created_ago}})'],
        ['Acknowledgement deadline', '{{ack_deadline}} (within {{ack_hours}} h)'],
    ])
    . sectionH('Executive summary')
    . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.55;">{{summary}}</p>'
    . sectionH('Traveller (de-identified)')
    . factGrid([
        ['Identifier', '{{traveler_name}}'],
        ['Demographics', '{{traveler_gender}} · {{traveler_age}} · {{traveler_nationality}}'],
        ['Travel direction', '{{travel_direction}}'],
        ['Journey', '{{journey_start}} &rarr; {{poe_code}}'],
        ['Conveyance', '{{conveyance_type}} · {{conveyance_id}} · seat {{seat_number}}'],
        ['Captured by', '{{captured_by_name}} at {{captured_at}}'],
    ])
    . sectionH('Clinical picture')
    . '<div style="margin-bottom:10px;font-size:13px;color:#0F172A;">Triage: <strong>{{triage_category}}</strong> · Syndrome: <strong>{{syndrome_classification}}</strong> · Emergency signs: <strong>{{emergency_signs}}</strong></div>'
    . '{{{vitals_html}}}'
    . '{{{symptoms_html}}}'
    . sectionH('Suspected diseases & differential')
    . '{{{suspected_html}}}'
    . sectionH('Disease intelligence brief')
    . '{{{disease_intel_html}}}'
    . sectionH('Exposures (last 21 days)')
    . '{{{exposures_html}}}'
    . sectionH('7-1-7 response clock')
    . factGrid([
        ['Detect (target ≤ 7 days)',  '{{detect_hours_elapsed}} — {{detect_status}}'],
        ['Notify (target ≤ 24 hours)', '{{notify_hours_elapsed}} — {{notify_status}}'],
        ['Respond (target ≤ 7 days)', '{{respond_hours_elapsed}} — {{respond_status}}'],
    ])
    . sectionH('Escalation ladder')
    . '<p style="margin:0 0 12px 0;font-size:13px;color:#0F172A;">{{escalation_ladder}}</p>'
    . sectionH('Prior alerts at this POE (last 30 days)')
    . '{{{prior_alerts_html}}}'
    . calloutBox('Immediate clinical actions', '{{{immediate_actions_html}}}', 'red')
    . calloutBox('Recommended laboratory tests', '{{{recommended_tests_html}}}', 'amber')
    . primaryCta('Open War Room');

$alertText = "UGANDA POE SENTINEL — CRITICAL ALERT\n"
    . "Reference: {{alert_code}}\n"
    . "Disease: {{disease_name}} ({{ihr_tier}})\n"
    . "POE: {{poe_code}} · District: {{district_code}} · {{country_name}}\n"
    . "Raised: {{alert_created_at}} ({{alert_created_ago}})\n"
    . "Acknowledgement deadline: {{ack_deadline}} (within {{ack_hours}} h)\n"
    . "Routed to: {{routed_to_level}}\n\n"
    . "Summary:\n{{summary}}\n\n"
    . "Traveller: {{traveler_name}} · {{traveler_gender}} · {{traveler_age}}\n"
    . "Nationality: {{traveler_nationality}} · Travel: {{travel_direction}} from {{journey_start}}\n"
    . "Triage: {{triage_category}} · Syndrome: {{syndrome_classification}}\n\n"
    . "Open War Room: {{action_url}}\n";

$T['ALERT_CRITICAL'] = [
    'subject' => '🔴 CRITICAL · {{alert_code}} · {{disease_name}} at {{poe_code}}',
    'html'    => shell('CRITICAL ALERT', 'Critical alert raised at {{poe_code}}', 'A traveller meeting case-definition for {{disease_name}} has been flagged for the highest response tier.', $alertInner, 'critical'),
    'text'    => $alertText,
    'levels'  => ['POE','DISTRICT','PHEOC','NATIONAL'],
];

// ============================================================================
//  ALERT_HIGH — same skeleton, amber chrome
// ============================================================================
$T['ALERT_HIGH'] = [
    'subject' => '🟠 HIGH · {{alert_code}} · {{disease_name}} at {{poe_code}}',
    'html'    => shell('HIGH-RISK ALERT', 'High-risk alert raised at {{poe_code}}', 'A traveller has been flagged for {{disease_name}} at a level requiring same-day response.',
                       str_replace("riskPillTone='critical'", '', $alertInner), 'high'),
    'text'    => str_replace('CRITICAL ALERT', 'HIGH ALERT', $alertText),
    'levels'  => ['POE','DISTRICT','PHEOC','NATIONAL'],
];

// ============================================================================
//  TIER1_ADVISORY — always-notifiable disease, full case context + IHR framing
// ============================================================================
$tier1Inner = ''
    . '<div style="margin-bottom:14px;">' . riskPill('#FEF2F2', '#B91C1C', 'IHR TIER 1 — ALWAYS NOTIFIABLE') . '</div>'
    . factGrid([
        ['Alert reference', '<strong>{{alert_code}}</strong>'],
        ['Disease', '{{disease_name}}'],
        ['IHR tier', '{{ihr_tier}}'],
        ['Risk level', '{{risk_level_label}}'],
        ['POE / District', '{{poe_code}} · {{district_code}}'],
        ['Routed to', '{{routed_to_level}}'],
        ['Raised at', '{{alert_created_at}}'],
        ['Acknowledgement deadline', '{{ack_deadline}}'],
    ])
    . calloutBox('IHR obligation', 'Tier 1 diseases require notification to WHO via the IHR National Focal Point within 24 hours under Article 6 of the IHR (2005). PHEOC and the National IHR focal point must be paged immediately.', 'red')
    . sectionH('Executive summary')
    . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.55;">{{summary}}</p>'
    . sectionH('Disease profile')
    . '{{{disease_intel_html}}}'
    . sectionH('Traveller & clinical')
    . factGrid([
        ['Traveller', '{{traveler_name}} · {{traveler_gender}} · {{traveler_age}}'],
        ['Nationality', '{{traveler_nationality}} · Residence: {{traveler_residence}}'],
        ['Journey', '{{journey_start}} &rarr; {{poe_code}} via {{conveyance_type}} {{conveyance_id}}'],
        ['Triage', '{{triage_category}} · Syndrome: {{syndrome_classification}}'],
        ['Captured by', '{{captured_by_name}} at {{captured_at}}'],
    ])
    . '{{{vitals_html}}}'
    . '{{{symptoms_html}}}'
    . sectionH('Suspected diseases')
    . '{{{suspected_html}}}'
    . sectionH('Recent exposures')
    . '{{{exposures_html}}}'
    . sectionH('Annex 2 PHEIC criteria assessment')
    . '<p style="margin:0 0 8px 0;font-size:13px;color:#0F172A;"><strong>Score: {{annex2_score}}/4 criteria met</strong></p>'
    . '<div style="font-size:13px;color:#0F172A;line-height:1.6;">{{{annex2_criteria_fired}}}</div>'
    . calloutBox('Required immediate actions', '{{{immediate_actions_html}}}', 'red')
    . calloutBox('Specimens to collect', '{{{recommended_tests_html}}}', 'amber')
    . primaryCta('Open War Room');

$T['TIER1_ADVISORY'] = [
    'subject' => '🚨 IHR TIER 1 · {{disease_name}} · {{alert_code}} at {{poe_code}}',
    'html'    => shell('IHR TIER 1 ADVISORY', 'IHR Tier 1 disease detected at {{poe_code}}', 'A traveller meeting criteria for an always-notifiable disease has been identified.', $tier1Inner, 'critical'),
    'text'    => "UGANDA POE SENTINEL — IHR TIER 1 ADVISORY\n"
        . "Reference: {{alert_code}}\nDisease: {{disease_name}}\n"
        . "POE: {{poe_code}} · District: {{district_code}}\nRaised: {{alert_created_at}}\n\n"
        . "IHR obligation: notify WHO within 24 hours under IHR Article 6.\n\n"
        . "Summary: {{summary}}\n\nAnnex 2 score: {{annex2_score}}/4\n\nOpen War Room: {{action_url}}\n",
    'levels'  => ['NATIONAL','PHEOC','DISTRICT','POE'],
];

// ============================================================================
//  TIER2_ADVISORY — Annex-2 assessable disease
// ============================================================================
$T['TIER2_ADVISORY'] = [
    'subject' => '🟠 IHR TIER 2 · {{disease_name}} · {{alert_code}} at {{poe_code}}',
    'html'    => shell('IHR TIER 2 ADVISORY', 'IHR Tier 2 event flagged for Annex 2 review', 'Disease event requires the four-criteria Annex 2 assessment to determine WHO notification.',
        str_replace('IHR TIER 1 — ALWAYS NOTIFIABLE', 'IHR TIER 2 — ANNEX 2 ASSESSMENT',
            str_replace('within 24 hours under Article 6 of the IHR (2005)', 'when 2 or more Annex 2 criteria are met (see assessment below)', $tier1Inner)
        ), 'high'),
    'text'    => "UGANDA POE SENTINEL — IHR TIER 2 ADVISORY\n"
        . "Reference: {{alert_code}}\nDisease: {{disease_name}}\nPOE: {{poe_code}}\n"
        . "Annex 2 score: {{annex2_score}}/4\nNotify WHO if ≥ 2/4 criteria met.\n\nOpen War Room: {{action_url}}\n",
    'levels'  => ['NATIONAL','PHEOC','DISTRICT'],
];

// ============================================================================
//  PHEIC_ADVISORY — PHEIC-eligible event, national + WHO
// ============================================================================
$pheicInner = ''
    . '<div style="margin-bottom:14px;">' . riskPill('#FEF2F2', '#B91C1C', 'PHEIC-ELIGIBLE EVENT') . '</div>'
    . factGrid([
        ['Alert reference', '<strong>{{alert_code}}</strong>'],
        ['Disease', '{{disease_name}}'],
        ['IHR tier', '{{ihr_tier}}'],
        ['POE', '{{poe_code}} · {{district_code}}'],
        ['Country', '{{country_name}}'],
        ['Risk', '{{risk_level_label}}'],
        ['Annex 2 score', '{{annex2_score}}/4'],
        ['Raised at', '{{alert_created_at}}'],
    ])
    . calloutBox('Why this is a PHEIC candidate', '<p style="margin:0 0 8px 0;">An IHR Tier 1 / always-notifiable disease has been detected. Tier 1 events meet Annex 2 criterion 1 (serious public health impact) and criterion 3 (significant international spread risk) by WHO regulation.</p><div>{{{annex2_criteria_fired}}}</div>', 'red')
    . sectionH('Disease profile')
    . '{{{disease_intel_html}}}'
    . sectionH('Index case')
    . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.55;">{{summary}}</p>'
    . factGrid([
        ['Traveller', '{{traveler_name}} · {{traveler_gender}} · {{traveler_age}}'],
        ['Nationality', '{{traveler_nationality}}'],
        ['Journey origin', '{{journey_start}}'],
        ['Conveyance', '{{conveyance_type}} · {{conveyance_id}}'],
        ['Captured by', '{{captured_by_name}} at {{captured_at}}'],
    ])
    . sectionH('Required IHR actions')
    . '<ol style="margin:0 0 12px 18px;padding:0;font-size:13.5px;color:#0F172A;line-height:1.7;">'
    . '<li>Notify the National IHR Focal Point — already paged via this alert.</li>'
    . '<li>The National IHR Focal Point must notify WHO within 24 hours under Article 6.</li>'
    . '<li>Activate response plan, isolation, contact tracing, and cross-border coordination.</li>'
    . '<li>Document acknowledgement and all subsequent actions in the War Room.</li>'
    . '</ol>'
    . primaryCta('Open War Room');

$T['PHEIC_ADVISORY'] = [
    'subject' => '🚨 PHEIC ADVISORY · {{disease_name}} · {{alert_code}}',
    'html'    => shell('PHEIC ADVISORY', 'PHEIC-eligible event detected', 'Public Health Emergency of International Concern assessment is required.', $pheicInner, 'critical'),
    'text'    => "UGANDA POE SENTINEL — PHEIC ADVISORY\n"
        . "Reference: {{alert_code}}\nDisease: {{disease_name}}\nPOE: {{poe_code}}\n"
        . "Annex 2 score: {{annex2_score}}/4\n\n"
        . "Required: National IHR Focal Point to notify WHO within 24 hours per Article 6.\n\n"
        . "Open War Room: {{action_url}}\n",
    'levels'  => ['NATIONAL','PHEOC','WHO'],
];

// ============================================================================
//  ANNEX2_HIT — explicit Annex-2 criteria trigger
// ============================================================================
$T['ANNEX2_HIT'] = [
    'subject' => '📋 ANNEX 2 ASSESSMENT · {{alert_code}} · {{disease_name}}',
    'html'    => shell('ANNEX 2 TRIGGER', 'Annex 2 criteria fired for {{alert_code}}', 'Disease event has triggered one or more of the four IHR Annex 2 PHEIC assessment criteria.',
        ''
        . '<div style="margin-bottom:14px;">' . riskPill('#FFFBEB', '#B45309', 'ANNEX 2 ASSESSMENT FIRED') . '</div>'
        . factGrid([
            ['Alert reference', '<strong>{{alert_code}}</strong>'],
            ['Disease', '{{disease_name}}'],
            ['POE', '{{poe_code}} · {{district_code}}'],
            ['Country', '{{country_name}}'],
            ['Annex 2 score', '<strong style="color:#B45309;">{{annex2_score}}/4 criteria</strong>'],
            ['Risk level', '{{risk_level_label}}'],
        ])
        . sectionH('Criteria fired')
        . '<div style="font-size:13.5px;color:#0F172A;line-height:1.7;background:#FFFBEB;border:1px solid #FCD34D;border-radius:8px;padding:14px;">{{{annex2_criteria_fired}}}</div>'
        . sectionH('Recommended assessment pathway')
        . '<ol style="margin:0 0 12px 18px;padding:0;font-size:13.5px;color:#0F172A;line-height:1.7;">'
        . '<li>Convene the IHR assessment team within 12 hours.</li>'
        . '<li>If ≥ 2/4 criteria are confirmed, notify WHO via the National IHR Focal Point within 24 hours.</li>'
        . '<li>Document the decision rationale in the case War Room.</li>'
        . '</ol>'
        . sectionH('Disease profile')
        . '{{{disease_intel_html}}}'
        . primaryCta('Open War Room'),
        'high'),
    'text'    => "UGANDA POE SENTINEL — ANNEX 2 TRIGGER\n"
        . "Reference: {{alert_code}}\nDisease: {{disease_name}}\nPOE: {{poe_code}}\n"
        . "Annex 2 score: {{annex2_score}}/4\n\n"
        . "{{annex2_criteria_text}}\n\n"
        . "Open War Room: {{action_url}}\n",
    'levels'  => ['NATIONAL','PHEOC','DISTRICT'],
];

// ============================================================================
//  ESCALATION — alert moved up the ladder
// ============================================================================
$T['ESCALATION'] = [
    'subject' => '⬆️ ESCALATED · {{alert_code}} now routed to {{routed_to_level}}',
    'html'    => shell('ESCALATION', 'Alert escalated up the response ladder', 'This alert now requires attention from a higher level of the response structure.',
        ''
        . '<div style="margin-bottom:14px;">' . riskPill('#FEF2F2', '#B91C1C', 'ESCALATED · {{risk_level_label}}') . '</div>'
        . factGrid([
            ['Alert reference', '<strong>{{alert_code}}</strong>'],
            ['Now routed to', '<strong>{{routed_to_level}}</strong>'],
            ['POE / District', '{{poe_code}} · {{district_code}}'],
            ['Disease', '{{disease_name}}'],
            ['Original raised at', '{{alert_created_at}} ({{alert_created_ago}})'],
            ['Acknowledged by', '{{acknowledged_by_name}}'],
            ['Acknowledgement deadline', '{{ack_deadline}}'],
        ])
        . sectionH('Why this was escalated')
        . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.55;">{{summary}}</p>'
        . sectionH('Current 7-1-7 status')
        . factGrid([
            ['Detect',  '{{detect_hours_elapsed}} — {{detect_status}}'],
            ['Notify',  '{{notify_hours_elapsed}} — {{notify_status}}'],
            ['Respond', '{{respond_hours_elapsed}} — {{respond_status}}'],
        ])
        . sectionH('Escalation ladder')
        . '<p style="margin:0 0 12px 0;font-size:13px;color:#0F172A;">{{escalation_ladder}}</p>'
        . calloutBox('Required at the {{routed_to_level}} level', '<ul style="margin:0 0 0 18px;padding:0;line-height:1.7;"><li>Acknowledge receipt within {{ack_hours}} hours of this email.</li><li>Assign a case owner and confirm via the War Room.</li><li>Coordinate with the level below to avoid duplicate effort.</li></ul>', 'red')
        . primaryCta('Open War Room'),
        'critical'),
    'text'    => "UGANDA POE SENTINEL — ESCALATION\n"
        . "Reference: {{alert_code}} now routed to {{routed_to_level}}\n"
        . "Disease: {{disease_name}} · POE: {{poe_code}}\n"
        . "Originally raised: {{alert_created_at}} ({{alert_created_ago}})\n"
        . "Acknowledged by: {{acknowledged_by_name}}\n"
        . "Acknowledgement deadline: {{ack_deadline}}\n\n"
        . "Summary: {{summary}}\n\nOpen War Room: {{action_url}}\n",
    'levels'  => ['PHEOC','NATIONAL'],
];

// ============================================================================
//  ALERT_CLOSED — informational close summary
// ============================================================================
$T['ALERT_CLOSED'] = [
    'subject' => '✅ CLOSED · {{alert_code}} · {{disease_name}}',
    'html'    => shell('ALERT CLOSED', 'Alert {{alert_code}} has been closed', 'The case has reached final disposition. This is an informational closure summary for the response team.',
        ''
        . '<div style="margin-bottom:14px;">' . riskPill('#ECFDF5', '#047857', 'CLOSED') . '</div>'
        . factGrid([
            ['Alert reference', '<strong>{{alert_code}}</strong>'],
            ['Disease', '{{disease_name}}'],
            ['POE / District', '{{poe_code}} · {{district_code}}'],
            ['Originally raised', '{{alert_created_at}}'],
            ['Acknowledged at', '{{alert_acknowledged_at}} by {{acknowledged_by_name}}'],
            ['Closed at', '{{alert_closed_at}}'],
            ['Closed by', '{{closed_by_name}}'],
            ['Risk level at close', '{{risk_level_label}}'],
        ])
        . sectionH('Closure reason')
        . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.55;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px;">{{close_reason}}</p>'
        . sectionH('Case outcome')
        . factGrid([
            ['Classification', '{{outcome_classification_label}}'],
            ['Laboratory result', '{{outcome_lab_label}}'],
            ['Clinical outcome', '{{outcome_clinical_label}}'],
            ['Public-health action', '{{outcome_ph_action_label}}'],
            ['Outbreak determination', '{{outcome_outbreak_label}}'],
            ['Confirmed agent', '{{outcome_lab_disease_name}}'],
            ['IHR notification', '{{outcome_ihr_text}}'],
        ])
        . sectionH('Final 7-1-7 performance')
        . factGrid([
            ['Detect',  '{{detect_hours_elapsed}} — {{detect_status}}'],
            ['Notify',  '{{notify_hours_elapsed}} — {{notify_status}}'],
            ['Respond', '{{respond_hours_elapsed}} — {{respond_status}}'],
        ])
        . sectionH('Final case summary')
        . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.55;">{{outcome_summary_text}}</p>'
        . primaryCta('View final report'),
        'success'),
    'text'    => "UGANDA POE SENTINEL — ALERT CLOSED\n"
        . "Reference: {{alert_code}}\nDisease: {{disease_name}}\nPOE: {{poe_code}}\n"
        . "Closed at: {{alert_closed_at}} by {{closed_by_name}}\n"
        . "Reason: {{close_reason}}\n\n"
        . "Classification: {{outcome_classification_label}}\n"
        . "Lab: {{outcome_lab_label}}\nClinical: {{outcome_clinical_label}}\n"
        . "PH action: {{outcome_ph_action_label}}\nOutbreak: {{outcome_outbreak_label}}\n"
        . "IHR: {{outcome_ihr_text}}\n\nSummary: {{outcome_summary_text}}\n\n"
        . "Open: {{action_url}}\n",
    'levels'  => ['POE','DISTRICT','PHEOC','NATIONAL'],
];

// ============================================================================
//  ALERT_CASE_FILE — secondary case reached actionable disposition
// ============================================================================
$T['ALERT_CASE_FILE'] = [
    'subject' => '📁 CASE FILE · {{alert_code}} · {{final_disposition}}',
    'html'    => shell('CASE FILE DISPATCHED', '{{alert_title}}', 'Secondary screening has reached a disposition that requires structured follow-up.',
        ''
        . '<div style="margin-bottom:14px;">' . riskPill('#FFFBEB', '#B45309', 'CASE · {{case_status}} · {{final_disposition}}') . '</div>'
        . factGrid([
            ['Alert reference', '<strong>{{alert_code}}</strong>'],
            ['Secondary case ID', '#{{secondary_case_id}}'],
            ['Disposition', '<strong>{{final_disposition}}</strong>'],
            ['Risk level', '{{risk_level_label}}'],
            ['IHR tier', '{{ihr_tier}}'],
            ['POE / District', '{{poe_code}} · {{district_code}}'],
            ['Traveller', '{{traveler_label}}'],
            ['Opened by', '{{opened_by_name}} at {{opened_at}}'],
            ['Alert raised', '{{alert_created_at}}'],
            ['Owner', '{{owner_role}}'],
            ['Deadline', '{{next_action_deadline}}'],
        ])
        . sectionH('What happened')
        . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.55;">{{summary_what}}</p>'
        . sectionH('Why it matters')
        . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.55;">{{why_it_matters}}</p>'
        . sectionH('Actions already taken')
        . '<pre style="margin:0 0 12px 0;font-family:inherit;white-space:pre-wrap;font-size:13px;color:#0F172A;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px;">{{actions_taken}}</pre>'
        . calloutBox('Next required action — {{next_action_owner}}', '<p style="margin:0 0 6px 0;"><strong>{{next_action_label}}</strong></p><p style="margin:0;">{{next_action_body}}</p><p style="margin:8px 0 0 0;font-size:12px;color:#B45309;">Due by {{next_action_deadline}}</p>', 'amber')
        . primaryCta('Open case file'),
        'high'),
    'text'    => "UGANDA POE SENTINEL — CASE FILE\n"
        . "Reference: {{alert_code}} · case #{{secondary_case_id}}\n"
        . "Disposition: {{final_disposition}} · Risk: {{risk_level_label}}\n"
        . "POE: {{poe_code}} · Traveller: {{traveler_label}}\n"
        . "Opened by: {{opened_by_name}} at {{opened_at}}\n\n"
        . "What: {{summary_what}}\nWhy: {{why_it_matters}}\n\n"
        . "Actions taken:\n{{actions_taken}}\n\n"
        . "Next: {{next_action_label}}\n{{next_action_body}}\nOwner: {{next_action_owner}} · Due: {{next_action_deadline}}\n\n"
        . "Open: {{action_url}}\n",
    'levels'  => ['POE','DISTRICT','PHEOC','NATIONAL'],
];

// ============================================================================
//  SECONDARY_SCREENING_OPENED — new secondary case, real-time page
// ============================================================================
$T['SECONDARY_SCREENING_OPENED'] = [
    'subject' => '🩺 NEW SECONDARY · {{poe_code}} · {{opened_at_date}} {{opened_at_time}}',
    'html'    => shell('NEW SECONDARY SCREENING', 'Secondary screening opened at {{poe_code}}', 'A primary screening has been escalated to secondary assessment — real-time page.',
        ''
        . '<div style="margin-bottom:14px;">' . riskPill('#FFFBEB', '#B45309', 'SECONDARY · LIVE CASE') . '</div>'
        . factGrid([
            ['Case identifier', '<strong>{{client_uuid}}</strong>'],
            ['Point of entry', '{{poe_code}}'],
            ['District', '{{district_code}} · {{country_code}}'],
            ['Opened on', '{{opened_at_date}} at {{opened_at_time}}'],
            ['Traveller (de-identified)', '{{traveler_name}}'],
            ['Email dispatched', '{{sent_at}}'],
        ])
        . calloutBox('Recommended action', '<ul style="margin:0 0 0 18px;padding:0;line-height:1.7;"><li>Open the secondary screening record in the POE Sentinel console.</li><li>Review vitals, exposures, and symptom progression in real time.</li><li>If criteria are met, raise the alert from inside the record.</li></ul>', 'amber')
        . primaryCta('Open secondary record'),
        'high'),
    'text'    => "UGANDA POE SENTINEL — NEW SECONDARY SCREENING\n"
        . "Case: {{client_uuid}}\nPOE: {{poe_code}} · District: {{district_code}}\n"
        . "Opened: {{opened_at_date}} at {{opened_at_time}}\n"
        . "Traveller: {{traveler_name}}\n\n"
        . "Open: {{records_url}}\n",
    'levels'  => ['POE','DISTRICT','PHEOC','NATIONAL'],
];

// ============================================================================
//  SCREENING_REFERRAL — primary detected symptoms requiring secondary
// ============================================================================
$T['SCREENING_REFERRAL'] = [
    'subject' => '🔵 REFERRAL · {{priority_label}} · {{poe_code}} · {{referral_id}}',
    'html'    => shell('PRIMARY → SECONDARY REFERRAL', 'Symptomatic primary screening at {{poe_code}}', 'A traveller has been flagged at primary screening and requires immediate secondary assessment.',
        ''
        . '<div style="margin-bottom:14px;"><span style="display:inline-block;padding:3px 10px;background:{{priority_color}}20;color:{{priority_color}};border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">{{priority_label}}</span></div>'
        . factGrid([
            ['Referral ID', '<strong>{{referral_id}}</strong>'],
            ['Reason', '{{notification_reason}}'],
            ['Point of entry', '{{poe_code}}'],
            ['District', '{{district_code}} · {{country_name}}'],
            ['Travel direction', '{{traveler_direction}}'],
            ['Traveller', '{{traveler_gender}}{{traveler_name_suffix}}'],
            ['Temperature', '{{temperature_display}}'],
            ['Symptoms present', '{{symptoms_present}}'],
            ['Quick-screen findings', '{{quick_symptoms}} ({{quick_symptoms_count}} flag(s))'],
            ['Captured by', '{{screener_name}}'],
            ['Captured at', '{{captured_at}} ({{captured_at_ago}})'],
        ])
        . calloutBox('Required at the receiving point', '<ul style="margin:0 0 0 18px;padding:0;line-height:1.7;"><li>Acknowledge the referral in the secondary queue.</li><li>Begin clinical assessment and document vitals + exposures.</li><li>If criteria are met, raise an alert; otherwise close with the appropriate disposition.</li></ul>', 'amber')
        . primaryCta('Open secondary queue'),
        'high'),
    'text'    => "UGANDA POE SENTINEL — REFERRAL\n"
        . "ID: {{referral_id}} · Priority: {{priority_label}}\nReason: {{notification_reason}}\n"
        . "POE: {{poe_code}} · District: {{district_code}}\n"
        . "Direction: {{traveler_direction}} · {{traveler_gender}}{{traveler_name_suffix}}\n"
        . "Temperature: {{temperature_display}}\nSymptoms: {{symptoms_present}} ({{quick_symptoms}})\n"
        . "Screener: {{screener_name}} at {{captured_at}}\n\n"
        . "Open: {{action_url}}\n",
    'levels'  => ['POE','DISTRICT','PHEOC','NATIONAL'],
];

// ============================================================================
//  BREACH_717 — 7-1-7 SLA breach on an alert
// ============================================================================
$T['BREACH_717'] = [
    'subject' => '⚠️ 7-1-7 BREACH · {{alert_code}} · {{bottleneck_phase}} phase',
    'html'    => shell('7-1-7 BREACH', '7-1-7 breach on alert {{alert_code}}', 'The {{bottleneck_phase}} phase has missed its target. A root-cause assessment is required.',
        ''
        . '<div style="margin-bottom:14px;">' . riskPill('#FEF2F2', '#7F1D1D', 'BREACHED · {{bottleneck_phase}}') . '</div>'
        . factGrid([
            ['Alert reference', '<strong>{{alert_code}}</strong>'],
            ['Disease', '{{disease_name}}'],
            ['POE / District', '{{poe_code}} · {{district_code}}'],
            ['Risk level', '{{risk_level_label}}'],
            ['Routed to', '{{routed_to_level}}'],
            ['Breached phase', '<strong>{{bottleneck_phase}}</strong>'],
            ['Target', '{{target_hours}} hours'],
            ['Elapsed', '<strong style="color:#B91C1C;">{{elapsed_hours}} hours</strong>'],
            ['Originally raised', '{{alert_created_at}} ({{alert_created_ago}})'],
            ['Acknowledged by', '{{acknowledged_by_name}}'],
        ])
        . sectionH('Full 7-1-7 status')
        . factGrid([
            ['Detect (≤ 7 days)',   '{{detect_hours_elapsed}} — {{detect_status}}'],
            ['Notify (≤ 24 hours)', '{{notify_hours_elapsed}} — {{notify_status}}'],
            ['Respond (≤ 7 days)',  '{{respond_hours_elapsed}} — {{respond_status}}'],
        ])
        . calloutBox('Required within 24 hours of this email', '<ul style="margin:0 0 0 18px;padding:0;line-height:1.7;"><li>File a root-cause assessment (RCA) on the alert War Room.</li><li>Commit to a mitigation plan, with named owners and deadlines.</li><li>Escalate to the next level if the breach cannot be remediated within 48 hours.</li></ul>', 'red')
        . sectionH('Outstanding follow-ups')
        . '{{{followups_html}}}'
        . primaryCta('Open War Room'),
        'breach'),
    'text'    => "UGANDA POE SENTINEL — 7-1-7 BREACH\n"
        . "Reference: {{alert_code}}\nDisease: {{disease_name}}\n"
        . "POE: {{poe_code}} · District: {{district_code}}\n"
        . "Breached phase: {{bottleneck_phase}}\n"
        . "Target: {{target_hours}} h · Elapsed: {{elapsed_hours}} h\n"
        . "Detect: {{detect_status}} · Notify: {{notify_status}} · Respond: {{respond_status}}\n\n"
        . "RCA + mitigation plan required within 24 h.\n\nOpen War Room: {{action_url}}\n",
    'levels'  => ['DISTRICT','PHEOC','NATIONAL'],
];

// ============================================================================
//  FOLLOWUP_DUE — follow-up approaching due time
// ============================================================================
$T['FOLLOWUP_DUE'] = [
    'subject' => '⏰ FOLLOW-UP DUE · {{alert_code}} · {{followup_action_label}}',
    'html'    => shell('FOLLOW-UP DUE', '{{followup_action_label}}', 'A response action is approaching its target deadline. Please complete or document progress.',
        ''
        . '<div style="margin-bottom:14px;">' . riskPill('#FFFBEB', '#B45309', 'DUE · {{followup_status}}') . '</div>'
        . factGrid([
            ['Alert reference', '<strong>{{alert_code}}</strong>'],
            ['Action', '{{followup_action_label}}'],
            ['Action code', '{{followup_action_code}}'],
            ['Status', '{{followup_status}}'],
            ['Due', '<strong>{{followup_due_at}}</strong>'],
            ['Assignee', '{{followup_assignee}}'],
            ['Blocks alert closure', '{{followup_blocks_closure}}'],
            ['POE / District', '{{poe_code}} · {{district_code}}'],
            ['Risk level', '{{risk_level_label}}'],
        ])
        . sectionH('Action notes')
        . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.55;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px;">{{followup_notes}}</p>'
        . calloutBox('What to do', '<ul style="margin:0 0 0 18px;padding:0;line-height:1.7;"><li>Open the follow-up in the War Room.</li><li>Mark as <em>Completed</em> with evidence, or update the status to <em>In progress</em> with notes.</li><li>If this action is not applicable, mark <em>Not applicable</em> with a justification.</li></ul>', 'amber')
        . primaryCta('Open War Room'),
        'high'),
    'text'    => "UGANDA POE SENTINEL — FOLLOW-UP DUE\n"
        . "Alert: {{alert_code}}\nAction: {{followup_action_label}}\n"
        . "Due: {{followup_due_at}} · Assignee: {{followup_assignee}}\n"
        . "Blocks closure: {{followup_blocks_closure}}\n"
        . "Notes: {{followup_notes}}\n\nOpen: {{action_url}}\n",
    'levels'  => ['DISTRICT','PHEOC','NATIONAL'],
];

// ============================================================================
//  FOLLOWUP_OVERDUE — past due
// ============================================================================
$T['FOLLOWUP_OVERDUE'] = [
    'subject' => '🔴 OVERDUE · {{alert_code}} · {{followup_action_label}} ({{followup_overdue_hours}}h late)',
    'html'    => shell('FOLLOW-UP OVERDUE', '{{followup_action_label}} is overdue', 'A response action has passed its target deadline. Immediate attention required.',
        ''
        . '<div style="margin-bottom:14px;">' . riskPill('#FEF2F2', '#B91C1C', 'OVERDUE · {{followup_overdue_hours}}H LATE') . '</div>'
        . factGrid([
            ['Alert reference', '<strong>{{alert_code}}</strong>'],
            ['Action', '{{followup_action_label}}'],
            ['Action code', '{{followup_action_code}}'],
            ['Status', '{{followup_status}}'],
            ['Was due', '<strong style="color:#B91C1C;">{{followup_due_at}}</strong>'],
            ['Overdue by', '<strong style="color:#B91C1C;">{{followup_overdue_hours}} hours</strong>'],
            ['Assignee', '{{followup_assignee}}'],
            ['Blocks alert closure', '{{followup_blocks_closure}}'],
            ['POE / District', '{{poe_code}} · {{district_code}}'],
            ['Risk level', '{{risk_level_label}}'],
        ])
        . sectionH('Action notes')
        . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.55;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px;">{{followup_notes}}</p>'
        . calloutBox('Required now', '<ul style="margin:0 0 0 18px;padding:0;line-height:1.7;"><li>If this action is complete, mark it complete with evidence — it should not be sitting open.</li><li>If it is in progress, document the blocker.</li><li>If it is no longer relevant, mark as <em>Not applicable</em> with justification.</li><li>Escalate if you cannot complete this action within the next response cycle.</li></ul>', 'red')
        . primaryCta('Open War Room'),
        'critical'),
    'text'    => "UGANDA POE SENTINEL — FOLLOW-UP OVERDUE\n"
        . "Alert: {{alert_code}}\nAction: {{followup_action_label}}\n"
        . "Due: {{followup_due_at}} · Overdue by: {{followup_overdue_hours}}h\n"
        . "Assignee: {{followup_assignee}} · Blocks closure: {{followup_blocks_closure}}\n"
        . "Notes: {{followup_notes}}\n\nOpen: {{action_url}}\n",
    'levels'  => ['DISTRICT','PHEOC','NATIONAL'],
];

// ============================================================================
//  RESPONDER_INFO_REQUEST — external responder asked for case info
// ============================================================================
$T['RESPONDER_INFO_REQUEST'] = [
    'subject' => '📨 Information request · POE Sentinel · {{alert_code}}',
    'html'    => shell('INFORMATION REQUEST', 'Information request for case {{alert_code}}', 'Hello {{responder_name}} — the Uganda POE Sentinel team is requesting information about a case under your jurisdiction.',
        ''
        . factGrid([
            ['Sent to', '<strong>{{responder_name}}</strong>'],
            ['Case reference', '<strong>{{alert_code}}</strong>'],
            ['Disease', '{{disease_name}}'],
            ['POE / District', '{{poe_code}} · {{district_code}}'],
            ['Risk level', '{{risk_level_label}}'],
        ])
        . sectionH('Request body')
        . '<div style="margin:0 0 14px 0;font-size:13.5px;color:#0F172A;line-height:1.6;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:14px;white-space:pre-wrap;">{{request_body}}</div>'
        . sectionH('How to respond')
        . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.55;">Reply directly to this email — the response token below allows our system to match your reply back to the case automatically. You may also call the National POE focal point at the number on file.</p>'
        . '<div style="margin:0 0 14px 0;padding:10px 12px;background:#F1F5F9;border-radius:6px;font-family:Menlo,Consolas,monospace;font-size:11.5px;color:#475569;word-break:break-all;">Token: {{request_token}}</div>'
        . sectionH('Case at-a-glance')
        . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.55;">{{summary}}</p>'
        . primaryCta('Open case in Command Centre'),
        'info'),
    'text'    => "UGANDA POE SENTINEL — INFORMATION REQUEST\n"
        . "To: {{responder_name}}\nCase: {{alert_code}} · {{disease_name}}\n"
        . "POE: {{poe_code}} · District: {{district_code}}\n\n"
        . "Request:\n{{request_body}}\n\n"
        . "Token: {{request_token}}\n\n"
        . "Reply to this email — the token will match your response to the case.\n\n"
        . "Case URL: {{action_url}}\n",
    'levels'  => ['EXTERNAL'],
];

// ============================================================================
//  WEEKLY_REPORT — Monday 07:30 country scorecard
// ============================================================================
$weeklyInner = ''
    . '<div style="margin-bottom:14px;font-size:11px;color:#64748B;letter-spacing:.06em;text-transform:uppercase;font-weight:600;">Week {{week_start}} → {{week_end}} · {{country_name}}</div>'
    . sectionH('Executive narrative')
    . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.6;">{{executive_summary}}</p>'
    . sectionH('Primary screening volume')
    . factGrid([
        ['Screened (this week)', '<strong>{{screenings_7d}}</strong>'],
        ['Previous 7 days', '{{screenings_prev_7d}}'],
        ['Week-over-week change', '<strong>{{screenings_delta}}</strong>'],
        ['Symptomatic', '{{symptomatic_7d}} ({{symptomatic_rate}})'],
        ['Fever ≥ 37.5 °C', '{{fever_7d}} ({{fever_rate}})'],
        ['Direction · Entry / Exit / Transit', '{{direction_entry}} / {{direction_exit}} / {{direction_transit}}'],
    ])
    . sectionH('Alert activity & IHR compliance')
    . factGrid([
        ['Alerts raised (7 d)', '<strong>{{alerts_7d}}</strong>'],
        ['Acknowledged within 24 h', '{{alerts_acked_24h}}'],
        ['Closed within 7 d', '{{alerts_closed_7d}}'],
        ['IHR 1-day acknowledgement compliance', '<strong>{{ihr_1day_compliance}}</strong>'],
        ['Stuck open past 24 h', '<strong>{{stuck_alerts}}</strong>'],
        ['Secondary cases opened (7 d)', '{{secondary_cases_7d}}'],
    ])
    . sectionH('Alert breakdown')
    . '{{{alert_table_html}}}'
    . sectionH('Disposition mix')
    . '{{{disposition_html}}}'
    . sectionH('Top suspected diseases')
    . '{{{disease_html}}}'
    . sectionH('Top traveller nationalities')
    . '{{{nationality_html}}}'
    . sectionH('Conveyance')
    . '{{{conveyance_html}}}'
    . sectionH('POE activity')
    . '{{{poe_activity_html}}}'
    . sectionH('Silent POEs this week')
    . '{{{silent_poes_html}}}'
    . sectionH('Officer engagement')
    . factGrid([
        ['Active this week', '{{officers_active_7d}}'],
        ['Dormant (14+ d)', '<strong style="color:#B91C1C;">{{officers_dormant}}</strong>'],
        ['Total active officers', '{{officers_total}}'],
        ['POEs that submitted aggregated reports', '{{agg_poes_submitted}}'],
    ])
    . sectionH('Blocking follow-ups overdue')
    . '{{{blocking_fu_html}}}'
    . primaryCta('Open Command Centre');

$T['WEEKLY_REPORT'] = [
    'subject' => '📊 Weekly Scorecard · {{country_name}} · {{week_end}}',
    'html'    => shell('WEEKLY SCORECARD', 'Weekly POE surveillance scorecard', 'A consolidated weekly view of screening volume, alert activity, IHR compliance, and operational readiness.', $weeklyInner, 'advisory'),
    'text'    => "UGANDA POE SENTINEL — WEEKLY SCORECARD\n"
        . "{{week_start}} → {{week_end}} · {{country_name}}\n"
        . str_repeat('=', 50) . "\n\n"
        . "{{executive_summary}}\n\n"
        . "Screened (7 d):           {{screenings_7d}} ({{screenings_delta}} vs prev 7 d)\n"
        . "Symptomatic:              {{symptomatic_7d}} ({{symptomatic_rate}})\n"
        . "Fever ≥ 37.5 °C:          {{fever_7d}} ({{fever_rate}})\n"
        . "Direction (E/X/T):        {{direction_entry}} / {{direction_exit}} / {{direction_transit}}\n\n"
        . "Alerts raised (7 d):      {{alerts_7d}}\n"
        . "Ack'd within 24 h:        {{alerts_acked_24h}}\n"
        . "Closed within 7 d:        {{alerts_closed_7d}}\n"
        . "IHR 1-day compliance:     {{ihr_1day_compliance}}\n"
        . "Stuck open past 24 h:     {{stuck_alerts}}\n"
        . "Secondary cases (7 d):    {{secondary_cases_7d}}\n\n"
        . "Officers active:          {{officers_active_7d}} of {{officers_total}}\n"
        . "Officers dormant (14d+):  {{officers_dormant}}\n"
        . "POEs submitted aggregated reports: {{agg_poes_submitted}}\n"
        . "Silent POEs:              {{silent_poes_count}}\n\n"
        . "Open: {{action_url}}\n",
    'levels'  => ['NATIONAL','PHEOC'],
];

// ============================================================================
//  DAILY_REPORT — retired but defensively seeded (lightweight)
// ============================================================================
$T['DAILY_REPORT'] = [
    'subject' => '📅 Daily Report · {{country_name}} · {{now_date}}',
    'html'    => shell('DAILY REPORT', 'Daily POE surveillance summary', 'A 24-hour snapshot of screening, alerts, and case activity.',
        ''
        . sectionH('24-hour snapshot')
        . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.55;">{{executive_summary}}</p>'
        . sectionH('Key counts')
        . factGrid([
            ['Date', '{{now_date}}'],
            ['Country', '{{country_name}}'],
            ['Screenings (24 h)', '{{screenings_7d}}'],
            ['Symptomatic', '{{symptomatic_7d}} ({{symptomatic_rate}})'],
            ['Alerts raised', '{{alerts_7d}}'],
            ['Alerts closed', '{{alerts_closed_7d}}'],
        ])
        . primaryCta('Open Command Centre'),
        'advisory'),
    'text'    => "UGANDA POE SENTINEL — DAILY REPORT\n{{now_date}} · {{country_name}}\n\n"
        . "{{executive_summary}}\n\nOpen: {{action_url}}\n",
    'levels'  => ['NATIONAL'],
];

// ============================================================================
//  NATIONAL_INTELLIGENCE — retired but defensively seeded
// ============================================================================
$T['NATIONAL_INTELLIGENCE'] = [
    'subject' => '🧠 National Intelligence Digest · {{country_name}} · {{now_date}}',
    'html'    => shell('NATIONAL INTELLIGENCE', 'National intelligence digest', 'A multi-day rollup of surveillance signals, response performance, and emerging risks.',
        ''
        . sectionH('Headline narrative')
        . '<p style="margin:0 0 12px 0;font-size:13.5px;color:#0F172A;line-height:1.6;">{{executive_summary}}</p>'
        . sectionH('Operational snapshot')
        . factGrid([
            ['Reporting window', '{{week_start}} → {{week_end}}'],
            ['Country', '{{country_name}}'],
            ['Screenings', '{{screenings_7d}}'],
            ['Alerts raised', '{{alerts_7d}}'],
            ['IHR compliance', '{{ihr_1day_compliance}}'],
            ['Stuck open past 24 h', '{{stuck_alerts}}'],
        ])
        . primaryCta('Open Command Centre'),
        'advisory'),
    'text'    => "UGANDA POE SENTINEL — NATIONAL INTELLIGENCE\n{{week_start}} → {{week_end}} · {{country_name}}\n\n"
        . "{{executive_summary}}\n\nOpen: {{action_url}}\n",
    'levels'  => ['NATIONAL'],
];

// ─── Mock var bag for verification ──────────────────────────────────────────
// Every key the dispatcher could supply is present so we can detect any
// {{token}} that escapes substitution. Keys are kept in sync with
// CaseContextBuilder::forAlert / forScreening / forFollowup + the override
// bags that dispatchAlertClosed, dispatchBreach717, dispatchSecondaryScreening,
// buildCaseFileVars, buildOutcomeOverrides and the weekly digest builder pass.

$mockVars = [
    // AdminLinks::generalVars()
    'action_url' => 'https://ug-poe.ecsahc.com/admin',
    'dashboard_url' => 'https://ug-poe.ecsahc.com/admin',
    'hub_url' => 'https://ug-poe.ecsahc.com/admin/alerts',
    'app_url' => 'https://ug-poe.ecsahc.com/admin',
    'warroom_url' => 'https://ug-poe.ecsahc.com/admin/alerts/123/war-room',
    'console_url' => 'https://ug-poe.ecsahc.com/admin/alerts/123/war-room',

    // Alert identity
    'alert_code' => 'UGA-2026-00147',
    'alert_title' => 'Viral haemorrhagic fever — suspected',
    'alert_details' => 'Index case meets case-definition. Awaiting laboratory confirmation.',
    'alert_id' => '147',
    'risk_level' => 'CRITICAL',
    'risk_level_label' => 'Critical',
    'routed_to_level' => 'NATIONAL',
    'ihr_tier' => 'TIER_1_ALWAYS_NOTIFIABLE',
    'alert_status' => 'OPEN',
    'alert_generated_from' => 'RULE_BASED',
    'alert_created_at' => '2026-05-17 14:32',
    'alert_created_ago' => '3 hours ago',
    'alert_acknowledged_at' => '2026-05-17 15:01',
    'alert_closed_at' => '—',
    'acknowledged_by_name' => 'Moses Ebong',
    'ack_sla_hours' => '4',
    'ack_hours' => '4',
    'ack_deadline' => '2026-05-17 18:32 (within 4 h)',

    // Geography
    'country_code' => 'UG',
    'country_name' => 'Uganda',
    'province_code' => '',
    'pheoc_code' => '',
    'district_code' => 'WAKISO',
    'poe_code' => 'ENTEBBE_INTL',

    // Disease intel
    'disease_code' => 'vhf',
    'disease_name' => 'Viral haemorrhagic fever',
    'disease_ihr_tier' => 'TIER_1_ALWAYS_NOTIFIABLE',
    'disease_who_category' => 'WHO R&D Blueprint priority',
    'disease_cfr_pct' => '50.0%',
    'disease_incubation' => '2-21 days',
    'disease_transmission' => 'Direct contact with body fluids',
    'disease_ppe' => 'Full PPE; double gloves',
    'disease_isolation' => 'Negative-pressure isolation',
    'disease_ihr_notification' => 'Tier 1 — notify WHO within 24 h',
    'disease_case_definition' => 'Acute febrile illness with haemorrhagic signs.',
    'disease_differential' => 'Severe malaria, leptospirosis, typhoid.',
    'disease_specimens' => 'EDTA whole blood + serum',
    'immediate_actions_html' => '<ul style="margin:0 0 0 18px;padding:0;line-height:1.7;"><li>Isolate immediately</li><li>Don full PPE</li><li>Notify National IHR focal point</li></ul>',
    'recommended_tests_html' => '<ul style="margin:0 0 0 18px;padding:0;line-height:1.7;"><li>RT-PCR VHF panel</li><li>Malaria RDT + microscopy</li></ul>',
    'key_distinguishers_html' => '<ul style="margin:0 0 0 18px;padding:0;line-height:1.7;"><li>Bleeding gums</li><li>Conjunctival injection</li></ul>',
    'disease_intel_html' => '<p style="margin:0;color:#0F172A;font-size:13px;">VHF profile loaded.</p>',

    // Officer identity + timing
    'captured_by_name' => 'Joyce Akello',
    'opened_by_name' => 'Dr Sarah Nakimuli',
    'captured_at' => '2026-05-17 14:25',
    'opened_at' => '2026-05-17 14:30',
    'dispositioned_at' => '—',
    'case_closed_at' => '—',
    'escalation_ladder' => 'POE → DISTRICT → PHEOC → NATIONAL · all four levels notified.',

    // Now
    'now' => '2026-05-17 17:32',
    'now_date' => '2026-05-17',

    // Traveller
    'traveler_name' => 'M. K.',
    'traveler_gender' => 'Male',
    'traveler_age' => '34 y',
    'traveler_nationality' => 'Democratic Republic of the Congo',
    'traveler_occupation' => 'Trader',
    'traveler_residence' => 'Uganda',
    'traveler_phone' => '+256 7•• ••• 412',
    'traveler_email' => 'm••@example.com',
    'emergency_contact' => 'Mary K / +256 700 000 000',
    'travel_direction' => 'Arrival',
    'journey_start' => 'Democratic Republic of the Congo',
    'embarkation_port' => 'Kinshasa',
    'conveyance_type' => 'Air',
    'conveyance_id' => 'KQ412',
    'seat_number' => '14B',
    'arrival_datetime' => '2026-05-17 13:55',
    'departure_datetime' => '2026-05-17 09:40',
    'purpose_of_travel' => 'Business',
    'length_of_stay' => '7 days',
    'destination_address' => 'Kampala Serena Hotel',
    'destination_district' => 'KAMPALA',

    // Clinical
    'triage_category' => 'Red — emergency',
    'general_appearance' => 'Diaphoretic, weak',
    'emergency_signs' => 'PRESENT',
    'syndrome_classification' => 'Viral haemorrhagic fever syndrome',
    'vitals_html' => '<table style="width:100%;border-collapse:collapse;font-size:12.5px;"><tr><td style="padding:4px 8px;border-bottom:1px solid #F1F5F9;">Temperature</td><td style="padding:4px 8px;border-bottom:1px solid #F1F5F9;text-align:right;font-weight:600;color:#B91C1C;">39.4 °C</td></tr></table>',
    'case_risk_level' => 'CRITICAL',
    'officer_notes' => 'Patient cooperative; family member also symptomatic — to be screened.',
    'final_disposition' => 'PROBABLE_CASE',
    'disposition_details' => '',
    'screening_outcome' => 'REFERRED',
    'followup_required' => 'YES',
    'followup_level' => 'NATIONAL',

    // HTML fragments
    'symptoms_html' => '<ul style="margin:6px 0 12px 18px;padding:0;font-size:13px;line-height:1.6;"><li>Fever (39.4 °C)</li><li>Headache</li><li>Bleeding gums</li></ul>',
    'symptoms_count' => '3',
    'exposures_html' => '<ul style="margin:6px 0 12px 18px;padding:0;font-size:13px;line-height:1.6;"><li>Attended funeral in Equateur, DRC — 12 days ago</li></ul>',
    'samples_html' => '<ul style="margin:6px 0 12px 18px;padding:0;font-size:13px;line-height:1.6;"><li>EDTA whole blood · pending</li></ul>',
    'samples_count' => '1',
    'travel_html' => '<ul style="margin:6px 0 12px 18px;padding:0;font-size:13px;line-height:1.6;"><li>Democratic Republic of the Congo (12 d ago)</li></ul>',
    'suspected_html' => '<ol style="margin:6px 0 12px 18px;padding:0;font-size:13px;line-height:1.6;"><li>Ebola virus disease (82% confidence)</li><li>Marburg virus disease (61%)</li></ol>',
    'suspected_count' => '2',
    'followups_html' => '<ul style="margin:6px 0 12px 18px;padding:0;font-size:13px;line-height:1.6;"><li>Case investigation started — due in 4 h</li></ul>',
    'followups_count' => '14',
    'followups_overdue_count' => '0',
    'sec_actions_html' => '<ul style="margin:6px 0 12px 18px;padding:0;font-size:13px;line-height:1.6;"><li>Patient isolated</li></ul>',
    'prior_alerts_html' => '<p style="margin:0;color:#64748B;font-size:12.5px;font-style:italic;">No prior alerts at this POE in the last 30 days.</p>',
    'prior_alerts_count' => '0',

    // Annex 2
    'annex2_score' => '3',
    'annex2_criteria_fired' => '<br>• <strong>Criterion 1</strong> — Tier 1 disease<br>• <strong>Criterion 2</strong> — unusual event<br>• <strong>Criterion 3</strong> — international spread',
    'annex2_criteria_text' => "Criteria fired:\n• Criterion 1\n• Criterion 2\n• Criterion 3",

    // 7-1-7
    'detect_hours_elapsed' => '0.1h',
    'notify_hours_elapsed' => '0.5h',
    'respond_hours_elapsed' => '2.5h',
    'detect_status' => '✓ within 7 days',
    'notify_status' => '✓ within 24 hours',
    'respond_status' => '✓ within 7 days',
    'detect_deadline' => '2026-05-24 14:25',
    'notify_deadline' => '2026-05-18 14:32',
    'respond_deadline' => '2026-05-24 15:01',

    'template_name' => '(see notification_log.template_code)',
    'summary' => 'A 34-year-old male arriving from DRC was flagged at Entebbe with fever, headache, and bleeding gums. Disposition: probable case. Specimens collected.',

    // Close overrides
    'closed_by_name' => 'Dr Sarah Nakimuli',
    'close_reason' => 'Lab returned negative for VHF panel; clinical course consistent with severe malaria.',
    'close_reason_short' => 'Lab negative for VHF; severe malaria.',
    'outcome_classification_label' => 'Discarded — alternative diagnosis confirmed',
    'outcome_lab_label' => 'Negative for VHF panel',
    'outcome_clinical_label' => 'Recovered',
    'outcome_ph_action_label' => 'Contact tracing closed at 21 d',
    'outcome_outbreak_label' => 'No outbreak',
    'outcome_ihr_text' => 'Tier 1 — WHO notified, closed under non-event update',
    'outcome_lab_disease_name' => 'Plasmodium falciparum',
    'outcome_summary_text' => 'Case discarded after laboratory confirmation of severe falciparum malaria.',

    // Breach overrides
    'bottleneck_phase' => 'NOTIFY',
    'elapsed_hours' => '32.1',
    'target_hours' => '24',

    // ALERT_CASE_FILE
    'case_status' => 'OPEN',
    'secondary_case_id' => '512',
    'secondary_case_uuid' => 'abcd-1234',
    'traveler_label' => 'M. K. · Male',
    'owner_role' => 'NATIONAL',
    'summary_what' => 'Secondary screening #512 has reached disposition PROBABLE_CASE at ENTEBBE_INTL.',
    'why_it_matters' => 'IHR Tier 1 always-notifiable disease — single case requires WHO notification within 24 h.',
    'actions_taken' => "• Patient isolated\n• EDTA blood collected\n• Contact list initiated",
    'next_action_label' => 'Collect lab samples + isolate + start contact tracing.',
    'next_action_body' => 'Coordinate with NATIONAL-level health authorities. Document acknowledgement + activate the relevant RTSL early-response actions in the POE Sentinel intelligence hub.',
    'next_action_owner' => 'NATIONAL',
    'next_action_deadline' => '2026-05-17 18:32 UTC',

    // SECONDARY_SCREENING_OPENED
    'client_uuid' => 'sec-abcd-0001',
    'opened_at_date' => '17 May 2026',
    'opened_at_time' => '14:30',
    'sent_at' => '17 May 2026 14:32 EAT',
    'records_url' => 'https://ug-poe.ecsahc.com/secondary-screening/records',

    // SCREENING_REFERRAL
    'referral_id' => 'REFERRAL-9012',
    'notification_priority' => 'HIGH',
    'priority_label' => '🟡 HIGH',
    'priority_color' => '#D97706',
    'notification_reason' => 'PRIMARY_SYMPTOMS_DETECTED',
    'traveler_direction' => 'Arriving (Entry)',
    'traveler_name_suffix' => '',
    'temperature' => '38.6 °C',
    'temperature_display' => '38.6 °C',
    'symptoms_present' => 'YES',
    'quick_symptoms' => 'Fever, Cough',
    'quick_symptoms_count' => '2',
    'screener_name' => 'Joyce Akello',
    'captured_at_ago' => '20 minutes ago',

    // RESPONDER_INFO_REQUEST
    'responder_name' => 'Mulago Hospital — Lab Reception',
    'request_body' => 'Please confirm receipt of sample S-512 and provide ETA on RT-PCR results.',
    'request_token' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',

    // Followup
    'followup_action_code' => 'CASE_INVESTIGATION',
    'followup_action_label' => 'Case investigation started',
    'followup_status' => 'PENDING',
    'followup_due_at' => '2026-05-17 18:32',
    'followup_overdue_minutes' => '0',
    'followup_overdue_hours' => '0',
    'followup_notes' => 'Awaiting field team mobilisation.',
    'followup_assignee' => 'Field Investigation Team — Kampala',
    'followup_blocks_closure' => 'YES — blocks alert closure',

    // Weekly / National Intelligence vars
    'week_start' => '2026-05-10 00:00',
    'week_end' => '2026-05-17 00:00',
    'executive_summary' => 'This weekly scorecard covers 7 days of POE surveillance in Uganda. Volume up 12% vs prior week; 1 Tier-1 candidate alert under investigation.',
    'screenings_7d' => '4,217',
    'screenings_prev_7d' => '3,766',
    'screenings_delta' => '+12.0%',
    'symptomatic_7d' => '38',
    'symptomatic_rate' => '0.9%',
    'fever_7d' => '11',
    'fever_rate' => '0.3%',
    'direction_entry' => '3,012',
    'direction_exit' => '1,011',
    'direction_transit' => '194',
    'alerts_7d' => '7',
    'alerts_acked_24h' => '7',
    'alerts_closed_7d' => '5',
    'ihr_1day_compliance' => '100%',
    'stuck_alerts' => '0',
    'secondary_cases_7d' => '14',
    'officers_active_7d' => '47',
    'officers_dormant' => '3',
    'officers_total' => '59',
    'agg_poes_submitted' => '35',
    'silent_poes_count' => '4',
    'alert_table_html' => '<p style="font-size:12px;color:#64748B;">No new alerts this week.</p>',
    'disposition_html' => '<p style="font-size:12px;color:#64748B;">No data.</p>',
    'disease_html' => '<p style="font-size:12px;color:#64748B;">No data.</p>',
    'nationality_html' => '<p style="font-size:12px;color:#64748B;">No data.</p>',
    'conveyance_html' => '<p style="font-size:12px;color:#64748B;">No data.</p>',
    'poe_activity_html' => '<p style="font-size:12px;color:#64748B;">No data.</p>',
    'silent_poes_html' => '<p style="font-size:12px;color:#64748B;">All POEs reported.</p>',
    'blocking_fu_html' => '<p style="font-size:12px;color:#64748B;">None blocking.</p>',
];

// ─── Render-and-verify helper ───────────────────────────────────────────────
/**
 * Same logic as NotificationDispatcher::render() — triple-brace first,
 * double-brace second. We intentionally do NOT call polishVarsForDisplay()
 * here; we want to catch ANY unresolved {{token}} the live render would
 * also leave behind.
 */
function renderTemplate(string $tpl, array $vars): string
{
    $out = preg_replace_callback('/\{\{\{\s*([a-z0-9_]+)\s*\}\}\}/i', function ($m) use ($vars) {
        return (string) ($vars[$m[1]] ?? '');
    }, $tpl) ?? $tpl;
    return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($vars) {
        return htmlspecialchars((string) ($vars[$m[1]] ?? ''), ENT_QUOTES, 'UTF-8');
    }, $out) ?? $out;
}

/** Detect any {{token}} or {{{token}}} that survived rendering. */
function findUnresolved(string $rendered): array
{
    preg_match_all('/\{\{\{?\s*([a-z0-9_]+)\s*\}?\}\}/i', $rendered, $m);
    return array_values(array_unique($m[1] ?? []));
}

// ─── Verify pass ────────────────────────────────────────────────────────────
$errors = [];
foreach ($T as $code => $tpl) {
    foreach (['subject' => $tpl['subject'], 'html' => $tpl['html'], 'text' => $tpl['text']] as $kind => $body) {
        $rendered = renderTemplate($body, $mockVars);
        $missing  = findUnresolved($rendered);
        if (!empty($missing)) {
            $errors[] = "[{$code}/{$kind}] unresolved tokens: " . implode(', ', $missing);
        }
    }
}

if (!empty($errors)) {
    fwrite(STDERR, "\n[VERIFY FAILED] Unresolved template tokens detected:\n");
    foreach ($errors as $e) fwrite(STDERR, "  - {$e}\n");
    fwrite(STDERR, "\nFix the templates or extend the mock var bag before seeding.\n");
    exit(5);
}
echo "[VERIFY OK] " . count($T) . " templates rendered against mock bag with zero unresolved tokens.\n";

if ($verifyOnly) {
    echo "[--verify-only] skipping DB writes; exiting cleanly.\n";
    exit(0);
}

// ─── Upsert ─────────────────────────────────────────────────────────────────
$sql = "INSERT INTO notification_templates
        (template_code, channel, subject_template, body_html_template, body_text_template,
         applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
        VALUES
        (:code, 'EMAIL', :subject, :html, :text, :levels, 0, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            subject_template   = VALUES(subject_template),
            body_html_template = VALUES(body_html_template),
            body_text_template = VALUES(body_text_template),
            applicable_levels  = VALUES(applicable_levels),
            is_active          = 1,
            updated_at         = NOW()";
$stmt = $pdo->prepare($sql);

$inserted = 0; $updated = 0;
foreach ($T as $code => $tpl) {
    $existing = $pdo->prepare("SELECT id FROM notification_templates WHERE template_code = ? AND channel = 'EMAIL'");
    $existing->execute([$code]);
    $existed = (bool) $existing->fetchColumn();

    $stmt->execute([
        ':code'    => $code,
        ':subject' => $tpl['subject'],
        ':html'    => $tpl['html'],
        ':text'    => $tpl['text'],
        ':levels'  => json_encode($tpl['levels'], JSON_UNESCAPED_SLASHES),
    ]);
    if ($existed) $updated++; else $inserted++;
    echo "  · " . str_pad($code, 28) . ($existed ? "UPDATED" : "INSERTED") . "\n";
}

echo "\n[DONE] inserted={$inserted}  updated={$updated}  total=" . count($T) . "\n";
