<?php

declare(strict_types=1);

namespace Database\Seeders\AggressiveTemplates;

/**
 * Three follow-up-family templates:
 *   FOLLOWUP_DUE     — action owner, due in 12h or less
 *   FOLLOWUP_OVERDUE — action owner, past its due time
 *   RESPONDER_INFO_REQUEST — external responder asked for clarifying info
 */
final class FollowupTemplates
{
    public function all(): array
    {
        return [
            $this->followupDue(),
            $this->followupOverdue(),
            $this->responderInfoRequest(),
        ];
    }

    private const WRAP_OPEN = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#EEF2F7;font-family:Arial,Helvetica,sans-serif;color:#0F172A;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF2F7;padding:24px 12px;"><tr><td align="center"><table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;background:#FFFFFF;border-radius:10px;overflow:hidden;box-shadow:0 4px 16px rgba(15,23,42,0.08);">';
    private const WRAP_CLOSE = '</table></td></tr></table></body></html>';

    private function footer(string $tone): string
    {
        return <<<HTML
<tr><td style="padding:18px 28px 12px;background:#F1F5F9;border-top:1px solid #E2E8F0;">
  <p style="margin:0;font-size:11px;color:#475569;line-height:1.5;">
    <strong>{{country_name}} POE Sentinel</strong> · {$tone} · RTSL 14 early-response framework + WHO AFRO IDSR 2021.<br>
    Alert <strong>{{alert_code}}</strong> · fired {{alert_created_ago}} at {{alert_created_at}}.<br>
    Open in console → <a href="{{console_url}}" style="color:#1D4ED8;text-decoration:none;font-weight:600;">{{console_url}}</a>
  </p>
</td></tr>
HTML;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  FOLLOWUP_DUE
    // ═════════════════════════════════════════════════════════════════════
    private function followupDue(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#0C4A6E 0%,#075985 50%,#0EA5E9 100%);padding:24px 28px;">
  <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#BAE6FD;font-weight:700;">RTSL 14 · EARLY RESPONSE ACTION DUE</div>
  <div style="margin-top:6px;font-size:12px;color:#E0F2FE;">Scheduled follow-up for alert {{alert_code}} — action window open now.</div>
  <div style="margin-top:12px;font-size:22px;font-weight:800;color:#FFFFFF;line-height:1.2;">◷ {{followup_action_label}}</div>
  <div style="margin-top:8px;font-size:13px;color:#BAE6FD;">Owner: {{followup_assignee}} · Due: {{followup_due_at}}</div>
</td></tr>

<tr><td style="padding:22px 28px 0;">
  <p style="margin:0;font-size:13px;color:#0F172A;">This is a scheduled Resolve-to-Save-Lives 14 early-response action tied to alert <strong>{{alert_code}}</strong>. You have been assigned as the action owner. Close the loop in the console — mark completed, blocked, or not-applicable with evidence.</p>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#075985;font-weight:800;">Action brief</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;background:#F0F9FF;border:1px solid #BAE6FD;border-radius:6px;">
    <tr><td style="padding:8px 12px;font-size:12px;color:#0C4A6E;">Action code</td><td style="padding:8px 12px;font-size:12px;text-align:right;font-weight:700;">{{followup_action_code}}</td></tr>
    <tr><td style="padding:8px 12px;font-size:12px;color:#0C4A6E;vertical-align:top;">Action</td><td style="padding:8px 12px;font-size:12px;text-align:right;">{{followup_action_label}}</td></tr>
    <tr><td style="padding:8px 12px;font-size:12px;color:#0C4A6E;">Status</td><td style="padding:8px 12px;font-size:12px;text-align:right;font-weight:700;">{{followup_status}}</td></tr>
    <tr><td style="padding:8px 12px;font-size:12px;color:#0C4A6E;">Due</td><td style="padding:8px 12px;font-size:12px;text-align:right;font-weight:700;color:#0369A1;">{{followup_due_at}}</td></tr>
    <tr><td style="padding:8px 12px;font-size:12px;color:#0C4A6E;">Blocks alert closure?</td><td style="padding:8px 12px;font-size:12px;text-align:right;">{{followup_blocks_closure}}</td></tr>
    <tr><td style="padding:8px 12px;font-size:12px;color:#0C4A6E;">Current notes</td><td style="padding:8px 12px;font-size:12px;text-align:right;"><em>{{followup_notes}}</em></td></tr>
  </table>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#075985;font-weight:800;">Why this action matters</div>
  <p style="margin:6px 0 0;font-size:13px;color:#0F172A;">This is one of {{followups_count}} RTSL-14 actions seeded at alert creation. Completing on time is how the country meets the global <strong>7-1-7 response target</strong>. {{followups_overdue_count}} action(s) on this alert are already overdue.</p>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#075985;font-weight:800;">Alert context</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;border:1px solid #E2E8F0;border-radius:6px;background:#F8FAFC;">
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Alert · risk</td><td style="padding:6px 12px;font-size:12px;text-align:right;font-weight:700;">{{alert_code}} · {{risk_level_label}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Disease</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{disease_name}} · {{disease_who_category}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Country · POE · district</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{country_name}} · {{poe_code}} · {{district_code}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Traveller</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{traveler_name}} · {{traveler_age}} · {{traveler_nationality}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Disposition</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{final_disposition}}</td></tr>
  </table>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#075985;font-weight:800;">Evidence to attach when you complete</div>
  <ul style="margin:8px 0 0 20px;padding:0;font-size:13px;color:#0F172A;">
    <li style="margin:4px 0;">Document / photo showing the action was performed</li>
    <li style="margin:4px 0;">Name of team member who carried it out</li>
    <li style="margin:4px 0;">Completion time (UTC or local with TZ)</li>
    <li style="margin:4px 0;">Any deviation from SOP and the reason</li>
  </ul>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#075985;font-weight:800;">All follow-ups on this alert</div>
  <div style="margin-top:8px;padding:12px 14px;background:#F0F9FF;border:1px solid #BAE6FD;border-radius:6px;">{{{followups_html}}}</div>
</td></tr>

<tr><td style="padding:20px 28px 14px;">
  <div style="background:#075985;border-radius:8px;padding:14px 16px;text-align:center;">
    <a href="{{console_url}}" style="color:#FFFFFF;font-size:14px;font-weight:700;text-decoration:none;">Mark {{followup_action_code}} complete →</a>
  </div>
</td></tr>

HTML . $this->footer('FOLLOW-UP DUE') . self::WRAP_CLOSE;

        $text = <<<TEXT
RTSL 14 · FOLLOW-UP DUE
Alert {{alert_code}} · action {{followup_action_code}}
{{followup_action_label}}

Owner: {{followup_assignee}}
Due: {{followup_due_at}}
Status: {{followup_status}}
Blocks closure: {{followup_blocks_closure}}
Notes: {{followup_notes}}

Context:
 · Disease {{disease_name}} · risk {{risk_level_label}}
 · {{country_name}} · POE {{poe_code}} · {{district_code}}
 · Traveller {{traveler_name}} · {{traveler_age}} · {{traveler_nationality}}
 · Disposition {{final_disposition}}

{{followups_overdue_count}} of {{followups_count}} follow-ups on this alert are already overdue.
Complete in console with evidence → {{console_url}}
TEXT;

        return [
            'template_code' => 'FOLLOWUP_DUE',
            'levels' => ['POE', 'DISTRICT', 'PHEOC'],
            'subject' => '◷ Due · {{followup_action_code}} · {{alert_code}} · {{poe_code}} · by {{followup_due_at}}',
            'html' => $html,
            'text' => $text,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  FOLLOWUP_OVERDUE
    // ═════════════════════════════════════════════════════════════════════
    private function followupOverdue(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#7F1D1D 0%,#B91C1C 50%,#DB2777 100%);padding:24px 28px;">
  <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#FBCFE8;font-weight:700;">OVERDUE · RTSL 14 action past its window</div>
  <div style="margin-top:6px;font-size:12px;color:#FCE7F3;">This is the escalation notice — supervisor copy appended.</div>
  <div style="margin-top:12px;font-size:22px;font-weight:800;color:#FFFFFF;line-height:1.2;">⊘ OVERDUE · {{followup_action_label}}</div>
  <div style="margin-top:8px;font-size:13px;color:#FBCFE8;">Overdue by {{followup_overdue_hours}} h ({{followup_overdue_minutes}} min) · was due {{followup_due_at}}</div>
</td></tr>

<tr><td style="padding:22px 28px 0;">
  <p style="margin:0;font-size:13px;color:#0F172A;">An RTSL-14 early response action tied to <strong>{{alert_code}}</strong> is now <strong>overdue</strong>. The alert is exposed to the 7-1-7 breach clock; the supervisor copy is visible on this thread. Please take direct action now.</p>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#B91C1C;font-weight:800;">Overdue action</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;background:#FFF1F2;border:1px solid #FECDD3;border-radius:6px;">
    <tr><td style="padding:8px 12px;font-size:12px;color:#7F1D1D;">Code</td><td style="padding:8px 12px;font-size:12px;text-align:right;font-weight:700;">{{followup_action_code}}</td></tr>
    <tr><td style="padding:8px 12px;font-size:12px;color:#7F1D1D;vertical-align:top;">Label</td><td style="padding:8px 12px;font-size:12px;text-align:right;">{{followup_action_label}}</td></tr>
    <tr><td style="padding:8px 12px;font-size:12px;color:#7F1D1D;">Current status</td><td style="padding:8px 12px;font-size:12px;text-align:right;font-weight:700;">{{followup_status}}</td></tr>
    <tr><td style="padding:8px 12px;font-size:12px;color:#7F1D1D;">Was due</td><td style="padding:8px 12px;font-size:12px;text-align:right;">{{followup_due_at}}</td></tr>
    <tr><td style="padding:8px 12px;font-size:12px;color:#7F1D1D;">Overdue by</td><td style="padding:8px 12px;font-size:12px;text-align:right;font-weight:700;color:#B91C1C;">{{followup_overdue_hours}} h</td></tr>
    <tr><td style="padding:8px 12px;font-size:12px;color:#7F1D1D;">Assigned to</td><td style="padding:8px 12px;font-size:12px;text-align:right;">{{followup_assignee}}</td></tr>
    <tr><td style="padding:8px 12px;font-size:12px;color:#7F1D1D;">Blocks alert closure?</td><td style="padding:8px 12px;font-size:12px;text-align:right;font-weight:700;">{{followup_blocks_closure}}</td></tr>
  </table>
</td></tr>

<tr><td style="padding:16px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#B91C1C;font-weight:800;">What happens if this stays overdue</div>
  <ul style="margin:8px 0 0 20px;padding:0;font-size:13px;color:#0F172A;">
    <li style="margin:4px 0;">7-1-7 response window is being eaten — this <em>will</em> cause a BREACH_717 notice.</li>
    <li style="margin:4px 0;">If <strong>blocks_closure = YES</strong> the parent alert cannot be closed until this is resolved.</li>
    <li style="margin:4px 0;">On next digest the alert is flagged on the national intelligence brief.</li>
    <li style="margin:4px 0;">Supervisor level ({{routed_to_level}}) is now receiving these notices until resolution.</li>
  </ul>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#B91C1C;font-weight:800;">Case in which this action sits</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;border:1px solid #E2E8F0;border-radius:6px;background:#F8FAFC;">
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Alert · risk</td><td style="padding:6px 12px;font-size:12px;text-align:right;font-weight:700;">{{alert_code}} · {{risk_level_label}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Disease</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{disease_name}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Country · POE · district</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{country_name}} · {{poe_code}} · {{district_code}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Disposition</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{final_disposition}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Officer notes</td><td style="padding:6px 12px;font-size:12px;text-align:right;"><em>{{officer_notes}}</em></td></tr>
  </table>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#B91C1C;font-weight:800;">Other follow-ups ({{followups_count}} · {{followups_overdue_count}} overdue)</div>
  <div style="margin-top:8px;padding:12px 14px;background:#FFF1F2;border:1px solid #FECDD3;border-radius:6px;">{{{followups_html}}}</div>
</td></tr>

<tr><td style="padding:20px 28px 14px;">
  <div style="background:#B91C1C;border-radius:8px;padding:14px 16px;text-align:center;">
    <a href="{{console_url}}" style="color:#FFFFFF;font-size:14px;font-weight:700;text-decoration:none;">Resolve {{followup_action_code}} now →</a>
  </div>
</td></tr>

HTML . $this->footer('OVERDUE · ESCALATED') . self::WRAP_CLOSE;

        $text = <<<TEXT
OVERDUE — RTSL 14 early response action
Alert {{alert_code}} · action {{followup_action_code}}
{{followup_action_label}}

Was due: {{followup_due_at}}
Overdue by: {{followup_overdue_hours}} h ({{followup_overdue_minutes}} min)
Owner: {{followup_assignee}}
Status: {{followup_status}}
Blocks closure: {{followup_blocks_closure}}

IMPACT
 · 7-1-7 breach clock is ticking on this alert.
 · If blocks_closure = YES the parent alert cannot close.
 · Supervisor level {{routed_to_level}} is now receiving copies.

Context: {{disease_name}} · {{country_name}} · POE {{poe_code}} · disposition {{final_disposition}}
All follow-ups: {{followups_count}} seeded · {{followups_overdue_count}} overdue.

Resolve now → {{console_url}}
TEXT;

        return [
            'template_code' => 'FOLLOWUP_OVERDUE',
            'levels' => ['POE', 'DISTRICT', 'PHEOC', 'NATIONAL'],
            'subject' => '⊘ OVERDUE {{followup_overdue_hours}}h · {{followup_action_code}} · {{alert_code}}',
            'html' => $html,
            'text' => $text,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  RESPONDER_INFO_REQUEST
    // ═════════════════════════════════════════════════════════════════════
    private function responderInfoRequest(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#B45309 0%,#D97706 60%,#FBBF24 100%);padding:24px 28px;">
  <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#FEF3C7;font-weight:700;">EXTERNAL RESPONDER · INFO REQUEST</div>
  <div style="margin-top:6px;font-size:12px;color:#FEF9C3;">The POE needs a direct answer from you before proceeding.</div>
  <div style="margin-top:12px;font-size:22px;font-weight:800;color:#FFFFFF;line-height:1.2;">◈ Information requested · {{alert_code}}</div>
  <div style="margin-top:8px;font-size:13px;color:#FEF3C7;">You have been routed as a responder on this case.</div>
</td></tr>

<tr><td style="padding:22px 28px 0;">
  <p style="margin:0;font-size:13px;color:#0F172A;">You are receiving this because a POE Sentinel case has routed a query to your inbox. The case summary and the specific question(s) follow. Reply to this email with the information requested — your response is logged into the case file automatically.</p>
</td></tr>

<tr><td style="padding:16px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#B45309;font-weight:800;">What we need to know</div>
  <div style="margin-top:8px;padding:12px 14px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:6px;">
    <ul style="margin:0;padding-left:18px;font-size:13px;color:#0F172A;">
      <li style="margin:4px 0;">Has this traveller been seen in your facility / district / network before?</li>
      <li style="margin:4px 0;">Is the case consistent with anything you are currently investigating?</li>
      <li style="margin:4px 0;">Any outbreak / cluster context you can share?</li>
      <li style="margin:4px 0;">Can you confirm that contacts listed below can be reached?</li>
      <li style="margin:4px 0;">Any specific clinical observation, lab result, or epi detail to add?</li>
    </ul>
  </div>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#B45309;font-weight:800;">Case at a glance</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;border:1px solid #E2E8F0;border-radius:6px;background:#F8FAFC;">
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Alert</td><td style="padding:6px 12px;font-size:12px;text-align:right;font-weight:700;">{{alert_code}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Disease</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{disease_name}} · {{disease_who_category}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Risk</td><td style="padding:6px 12px;font-size:12px;text-align:right;font-weight:700;">{{risk_level_label}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Country · POE · district</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{country_name}} · {{poe_code}} · {{district_code}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Traveller</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{traveler_name}} · {{traveler_age}} · {{traveler_gender}} · {{traveler_nationality}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Destination in country</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{destination_district}} · {{destination_address}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Syndrome · disposition</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{syndrome_classification}} · {{final_disposition}}</td></tr>
  </table>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#B45309;font-weight:800;">Disease context (for your situational awareness)</div>
  <div style="margin-top:8px;padding:12px 14px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:6px;font-size:12px;color:#0F172A;">
    <p style="margin:0 0 4px;"><strong>CFR:</strong> {{disease_cfr_pct}} · <strong>Incubation:</strong> {{disease_incubation}}.</p>
    <p style="margin:0 0 4px;"><strong>Transmission:</strong> {{disease_transmission}}.</p>
    <p style="margin:0 0 4px;"><strong>Isolation posture:</strong> {{disease_isolation}}.</p>
    <p style="margin:0;"><strong>Differential:</strong> {{disease_differential}}.</p>
  </div>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#B45309;font-weight:800;">Travel context</div>
  <div style="margin-top:8px;padding:12px 14px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:6px;">
    <p style="margin:0 0 4px;font-size:12px;">{{travel_direction}} · {{conveyance_type}} {{conveyance_id}} seat {{seat_number}} · arrival {{arrival_datetime}}</p>
    {{{travel_html}}}
  </div>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#B45309;font-weight:800;">How to reply</div>
  <ul style="margin:8px 0 0 20px;padding:0;font-size:13px;color:#0F172A;">
    <li style="margin:4px 0;">Reply directly to this email — the response is appended to the case file.</li>
    <li style="margin:4px 0;">Or open {{console_url}} and record a note on {{alert_code}}.</li>
    <li style="margin:4px 0;">Please respond within <strong>24 hours</strong>. Silence delays the national response.</li>
  </ul>
</td></tr>

<tr><td style="padding:20px 28px 14px;">
  <div style="background:#D97706;border-radius:8px;padding:14px 16px;text-align:center;">
    <a href="{{console_url}}" style="color:#FFFFFF;font-size:14px;font-weight:700;text-decoration:none;">Respond on {{alert_code}} →</a>
  </div>
</td></tr>

HTML . $this->footer('RESPONDER INFO REQUEST') . self::WRAP_CLOSE;

        $text = <<<TEXT
EXTERNAL RESPONDER — INFO REQUEST
{{alert_code}} · {{country_name}} · POE {{poe_code}}

We need a direct answer from you on this case.

QUESTIONS
 · Has this traveller been seen in your facility before?
 · Any current investigation / outbreak context?
 · Contacts below reachable?
 · Any extra clinical / lab / epi detail?

CASE
 · Disease: {{disease_name}} ({{disease_who_category}}) · CFR {{disease_cfr_pct}}
 · Risk: {{risk_level_label}}
 · Traveller: {{traveler_name}} · {{traveler_age}} · {{traveler_gender}} · {{traveler_nationality}}
 · Destination: {{destination_district}} · {{destination_address}}
 · Syndrome: {{syndrome_classification}} · disposition {{final_disposition}}

TRAVEL: {{travel_direction}} · {{conveyance_type}} {{conveyance_id}} seat {{seat_number}} · arrival {{arrival_datetime}}
Incubation: {{disease_incubation}} · transmission: {{disease_transmission}}

Respond to this email OR {{console_url}} within 24h.
TEXT;

        return [
            'template_code' => 'RESPONDER_INFO_REQUEST',
            'levels' => ['DISTRICT', 'PHEOC', 'NATIONAL', 'WHO'],
            'subject' => '◈ Info request · {{alert_code}} · {{disease_name}} · please respond 24h',
            'html' => $html,
            'text' => $text,
        ];
    }
}
