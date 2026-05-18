<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds five plain-English email templates the wizard fires when an officer
 * uses "ask someone" or closes a case:
 *
 *   WIZARD_ASK_LAB           — request to a laboratory contact
 *   WIZARD_ASK_FIELD_TEAM    — request to a district / POE field team
 *   WIZARD_ASK_PHEOC         — escalation to a provincial response centre
 *   WIZARD_REMIND_RESPONDER  — reminder after no response within 4h
 *   WIZARD_CLOSURE_NOTICE    — closure summary fanned out to original recipients
 *
 * Templates use the same Mustache variables CaseContextBuilder already
 * hydrates so the dispatcher does not need to learn new placeholders.
 *
 * This migration is idempotent — re-running it skips codes that already
 * exist (so reviewers can replay it safely).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notification_templates')) return;

        $templates = [
            [
                'template_code'      => 'WIZARD_ASK_LAB',
                'channel'            => 'EMAIL',
                'subject_template'   => 'Help needed — sample for {{disease_name}} from {{poe_code}}',
                'body_html_template' => self::askLabHtml(),
                'body_text_template' => self::askLabText(),
                'applicable_levels'  => json_encode(['DISTRICT', 'PHEOC', 'NATIONAL']),
            ],
            [
                'template_code'      => 'WIZARD_ASK_FIELD_TEAM',
                'channel'            => 'EMAIL',
                'subject_template'   => 'Help needed on the ground — case at {{poe_code}}',
                'body_html_template' => self::askFieldHtml(),
                'body_text_template' => self::askFieldText(),
                'applicable_levels'  => json_encode(['POE', 'DISTRICT']),
            ],
            [
                'template_code'      => 'WIZARD_ASK_PHEOC',
                'channel'            => 'EMAIL',
                'subject_template'   => 'Provincial support requested — {{disease_name}} at {{poe_code}}',
                'body_html_template' => self::askPheocHtml(),
                'body_text_template' => self::askPheocText(),
                'applicable_levels'  => json_encode(['DISTRICT', 'PHEOC']),
            ],
            [
                'template_code'      => 'WIZARD_REMIND_RESPONDER',
                'channel'            => 'EMAIL',
                'subject_template'   => 'Reminder — please reply on alert {{alert_code}}',
                'body_html_template' => self::remindHtml(),
                'body_text_template' => self::remindText(),
                'applicable_levels'  => json_encode(['POE', 'DISTRICT', 'PHEOC', 'NATIONAL']),
            ],
            [
                'template_code'      => 'WIZARD_CLOSURE_NOTICE',
                'channel'            => 'EMAIL',
                'subject_template'   => 'Closed — {{alert_code}} · {{disease_name}} at {{poe_code}}',
                'body_html_template' => self::closureHtml(),
                'body_text_template' => self::closureText(),
                'applicable_levels'  => json_encode(['POE', 'DISTRICT', 'PHEOC', 'NATIONAL', 'WHO']),
            ],
        ];

        $now = now();
        foreach ($templates as $tpl) {
            DB::table('notification_templates')->updateOrInsert(
                ['template_code' => $tpl['template_code']],
                array_merge($tpl, [
                    'is_ai_enhanced' => 0,
                    'is_active'      => 1,
                    'updated_at'     => $now,
                    'created_at'     => $now,
                ])
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('notification_templates')) return;

        DB::table('notification_templates')
            ->whereIn('template_code', [
                'WIZARD_ASK_LAB',
                'WIZARD_ASK_FIELD_TEAM',
                'WIZARD_ASK_PHEOC',
                'WIZARD_REMIND_RESPONDER',
                'WIZARD_CLOSURE_NOTICE',
            ])
            ->delete();
    }

    // ------------------------------------------------------------------
    //  Templates — plain English. No technical jargon.
    // ------------------------------------------------------------------

    private static function shell(string $title, string $body): string
    {
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#F1F5F9;font-family:Arial,Helvetica,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F1F5F9;padding:24px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:10px;box-shadow:0 4px 16px rgba(15,23,42,0.08);overflow:hidden;">
  <tr><td style="background:#0F172A;color:#F8FAFC;padding:20px 24px;">
    <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#94A3B8;font-weight:700;">{{country_name}} · Public Health Alerts</div>
    <div style="margin-top:6px;font-size:18px;font-weight:700;line-height:1.3;">{$title}</div>
  </td></tr>
  <tr><td style="padding:22px 24px;font-size:14px;line-height:1.55;">
    {$body}
  </td></tr>
  <tr><td style="padding:18px 24px;background:#F8FAFC;border-top:1px solid #E2E8F0;">
    <p style="margin:0;font-size:12px;color:#475569;">Open the case in the console: <a href="{{console_url}}" style="color:#1D4ED8;text-decoration:none;font-weight:600;">{{alert_code}}</a></p>
    <p style="margin:6px 0 0;font-size:11px;color:#64748B;">Sent by {{actor_name}} on {{now}}.</p>
  </td></tr>
</table>
</td></tr></table></body></html>
HTML;
    }

    private static function askLabHtml(): string
    {
        return self::shell('A sample is on the way — can you receive it?', <<<HTML
<p>Hello {{recipient_name}},</p>
<p>We have a case at <strong>{{poe_code}}</strong> that may be <strong>{{disease_name}}</strong>. The on-the-ground team is preparing a sample now.</p>
<p>Please confirm:</p>
<ul>
  <li>You can receive the sample within {{turnaround_hint}}.</li>
  <li>The collection kit and transport conditions you need.</li>
  <li>Who will run the test and when we should expect a result.</li>
</ul>
<p>{{officer_message}}</p>
<p>Thank you — every hour matters.</p>
HTML);
    }

    private static function askLabText(): string
    {
        return "Hello {{recipient_name}},\n\nWe have a case at {{poe_code}} that may be {{disease_name}}. Sample is being prepared.\n\nCan you confirm:\n- You can receive within {{turnaround_hint}}\n- Kit and transport needs\n- Who will run the test and when we should expect a result\n\n{{officer_message}}\n\nThank you.\n\nOpen the case: {{console_url}}";
    }

    private static function askFieldHtml(): string
    {
        return self::shell('We need help on the ground', <<<HTML
<p>Hello {{recipient_name}},</p>
<p>There is a case at <strong>{{poe_code}}</strong> in <strong>{{district_code}}</strong>. We are asking for your team's help.</p>
<p>Please confirm:</p>
<ul>
  <li>Someone can be at the site today.</li>
  <li>What equipment or transport you have ready.</li>
  <li>Who will be the contact person for us.</li>
</ul>
<p>{{officer_message}}</p>
<p>Thank you for stepping in quickly.</p>
HTML);
    }

    private static function askFieldText(): string
    {
        return "Hello {{recipient_name}},\n\nA case at {{poe_code}} in {{district_code}} needs help on the ground.\n\nCan you confirm:\n- Someone can be at the site today\n- Equipment or transport ready\n- Contact person\n\n{{officer_message}}\n\nThank you.\n\nOpen the case: {{console_url}}";
    }

    private static function askPheocHtml(): string
    {
        return self::shell('We are asking the province to step in', <<<HTML
<p>Hello {{recipient_name}},</p>
<p>The case at <strong>{{poe_code}}</strong> may be <strong>{{disease_name}}</strong> and is moving faster than the district can handle alone.</p>
<p>We are asking the province to:</p>
<ul>
  <li>Activate the response coordination structure.</li>
  <li>Confirm laboratory and transport capacity.</li>
  <li>Decide whether national partners need to be told.</li>
</ul>
<p>{{officer_message}}</p>
HTML);
    }

    private static function askPheocText(): string
    {
        return "Hello {{recipient_name}},\n\nThe case at {{poe_code}} (possible {{disease_name}}) is moving faster than the district can handle alone.\n\nWe are asking the province to:\n- Activate the response coordination structure\n- Confirm laboratory and transport capacity\n- Decide whether national partners need to be told\n\n{{officer_message}}\n\nOpen the case: {{console_url}}";
    }

    private static function remindHtml(): string
    {
        return self::shell('Reminder — we are still waiting to hear back', <<<HTML
<p>Hello {{recipient_name}},</p>
<p>We sent you a request about case <strong>{{alert_code}}</strong> {{request_age}} ago and have not heard back.</p>
<p>Please reply when you can — even a one-line acknowledgement helps us plan.</p>
<p>If you cannot help on this one, tell us so we can ask someone else.</p>
HTML);
    }

    private static function remindText(): string
    {
        return "Hello {{recipient_name}},\n\nWe sent you a request about case {{alert_code}} {{request_age}} ago and have not heard back.\n\nPlease reply when you can. If you cannot help, tell us so we can ask someone else.\n\nOpen the case: {{console_url}}";
    }

    private static function closureHtml(): string
    {
        return self::shell('This case has been closed', <<<HTML
<p>Hello team,</p>
<p>Case <strong>{{alert_code}}</strong> ({{disease_name}} at {{poe_code}}) has been closed.</p>
<p><strong>Reason:</strong> {{close_category_label}}</p>
<p><strong>Note:</strong> {{close_note}}</p>
<p><strong>Closed by:</strong> {{actor_name}} on {{closed_at}}.</p>
<p>Thank you to everyone who helped.</p>
HTML);
    }

    private static function closureText(): string
    {
        return "Hello team,\n\nCase {{alert_code}} ({{disease_name}} at {{poe_code}}) has been closed.\n\nReason: {{close_category_label}}\nNote: {{close_note}}\nClosed by: {{actor_name}} on {{closed_at}}.\n\nThank you to everyone who helped.\n\nOpen the case: {{console_url}}";
    }
};
