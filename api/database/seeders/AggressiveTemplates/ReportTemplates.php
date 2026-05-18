<?php

declare(strict_types=1);

namespace Database\Seeders\AggressiveTemplates;

/**
 * Three reporting-family templates:
 *   DAILY_REPORT          — 24h national POE activity digest
 *   WEEKLY_REPORT         — 7-day KPIs + 7-1-7 scorecard
 *   NATIONAL_INTELLIGENCE — every-3-days system intelligence brief
 */
final class ReportTemplates
{
    public function all(): array
    {
        return [
            $this->dailyReport(),
            $this->weeklyReport(),
            $this->nationalIntelligence(),
        ];
    }

    private const WRAP_OPEN = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#EEF2F7;font-family:Arial,Helvetica,sans-serif;color:#0F172A;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF2F7;padding:24px 12px;"><tr><td align="center"><table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;background:#FFFFFF;border-radius:10px;overflow:hidden;box-shadow:0 4px 16px rgba(15,23,42,0.08);">';
    private const WRAP_CLOSE = '</table></td></tr></table></body></html>';

    private function footer(string $tone): string
    {
        return <<<HTML
<tr><td style="padding:18px 28px 14px;background:#F1F5F9;border-top:1px solid #E2E8F0;">
  <p style="margin:0;font-size:11px;color:#475569;line-height:1.5;">
    <strong>{{country_name}} POE Sentinel</strong> · {$tone} · WHO IHR 2005 + AFRO IDSR 2021 + 7-1-7 framework.<br>
    Digest generated {{now}} for window ending {{now_date}}.<br>
    Open dashboard → <a href="{{console_url}}" style="color:#1D4ED8;text-decoration:none;font-weight:600;">{{console_url}}</a>
  </p>
</td></tr>
HTML;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DAILY_REPORT
    // ═════════════════════════════════════════════════════════════════════
    private function dailyReport(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#334155 0%,#475569 50%,#64748B 100%);padding:24px 28px;">
  <div style="font-size:11px;letter-spacing:0.22em;text-transform:uppercase;color:#CBD5E1;font-weight:700;">{{country_name}} POE SENTINEL · DAILY DIGEST</div>
  <div style="margin-top:6px;font-size:12px;color:#E2E8F0;">Window: last 24 hours · issued {{now}}</div>
  <div style="margin-top:12px;font-size:22px;font-weight:800;color:#FFFFFF;line-height:1.2;">▤ National POE activity · 24h</div>
</td></tr>

<tr><td style="padding:22px 28px 0;">
  <p style="margin:0;font-size:13px;color:#0F172A;">This digest gives you a decision-grade briefing on national POE surveillance activity over the past 24 hours. Every section links back to the console for deep dive.</p>
</td></tr>

<tr><td style="padding:16px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#334155;font-weight:800;">Top-line KPIs</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;">
    <tr>
      <td style="padding:4px;width:33%;">
        <div style="padding:14px;background:#F1F5F9;border-radius:8px;border-left:4px solid #0EA5E9;">
          <div style="font-size:10px;text-transform:uppercase;color:#475569;letter-spacing:0.06em;">Primary screenings</div>
          <div style="font-size:22px;font-weight:800;color:#0F172A;margin-top:4px;">{{primary_screenings_24h}}</div>
          <div style="font-size:11px;color:#64748B;margin-top:2px;">{{primary_symptomatic_24h}} symptomatic</div>
        </div>
      </td>
      <td style="padding:4px;width:33%;">
        <div style="padding:14px;background:#F1F5F9;border-radius:8px;border-left:4px solid #B45309;">
          <div style="font-size:10px;text-transform:uppercase;color:#475569;letter-spacing:0.06em;">Alerts raised</div>
          <div style="font-size:22px;font-weight:800;color:#0F172A;margin-top:4px;">{{alerts_24h}}</div>
          <div style="font-size:11px;color:#64748B;margin-top:2px;">{{alerts_critical_24h}} CRITICAL · {{alerts_high_24h}} HIGH</div>
        </div>
      </td>
      <td style="padding:4px;width:33%;">
        <div style="padding:14px;background:#F1F5F9;border-radius:8px;border-left:4px solid #B91C1C;">
          <div style="font-size:10px;text-transform:uppercase;color:#475569;letter-spacing:0.06em;">Overdue follow-ups</div>
          <div style="font-size:22px;font-weight:800;color:#B91C1C;margin-top:4px;">{{followups_overdue_total}}</div>
          <div style="font-size:11px;color:#64748B;margin-top:2px;">{{followups_due_today}} due today</div>
        </div>
      </td>
    </tr>
  </table>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#334155;font-weight:800;">Alerts by risk</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;border:1px solid #E2E8F0;border-radius:6px;background:#F8FAFC;">
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">CRITICAL</td><td style="padding:6px 12px;font-size:12px;text-align:right;font-weight:700;color:#B91C1C;">{{alerts_critical_24h}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">HIGH</td><td style="padding:6px 12px;font-size:12px;text-align:right;font-weight:700;color:#B45309;">{{alerts_high_24h}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">MEDIUM</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{alerts_medium_24h}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">LOW</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{alerts_low_24h}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Open &gt; 24h (not yet acknowledged)</td><td style="padding:6px 12px;font-size:12px;text-align:right;font-weight:700;color:#B91C1C;">{{alerts_stuck_open}}</td></tr>
  </table>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#334155;font-weight:800;">POE activity (top + silent)</div>
  <div style="margin-top:8px;padding:12px 14px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;">
    <p style="margin:0 0 6px;font-size:12px;color:#475569;"><strong>Top 5 POEs by activity</strong></p>
    {{{top_poes_html}}}
    <hr style="border:none;border-top:1px dashed #E2E8F0;margin:10px 0;">
    <p style="margin:0 0 6px;font-size:12px;color:#B91C1C;font-weight:700;">Silent POEs (&gt;24h without a submission)</p>
    {{{silent_poes_html}}}
  </div>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#334155;font-weight:800;">Syndromes seen in the window</div>
  <div style="margin-top:8px;padding:12px 14px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;">{{{syndromes_html}}}</div>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#334155;font-weight:800;">Dispositions</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;border:1px solid #E2E8F0;border-radius:6px;background:#F8FAFC;">
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Released</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{disposition_released}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Referred</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{disposition_referred}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Isolated / Quarantined</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{disposition_isolated}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Denied boarding / Delayed</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{disposition_delayed}}</td></tr>
  </table>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#334155;font-weight:800;">What to do today</div>
  <ul style="margin:8px 0 0 20px;padding:0;font-size:13px;color:#0F172A;">
    <li style="margin:4px 0;">Acknowledge any CRITICAL / HIGH alert still open after 24h.</li>
    <li style="margin:4px 0;">Clear {{followups_overdue_total}} overdue RTSL-14 follow-ups — each one ticks the 7-1-7 clock.</li>
    <li style="margin:4px 0;">Reach out to silent POEs listed above to confirm duty roster + network.</li>
    <li style="margin:4px 0;">Review top-ranked suspected diseases for cluster patterns.</li>
  </ul>
</td></tr>

<tr><td style="padding:20px 28px 14px;">
  <div style="background:#334155;border-radius:8px;padding:14px 16px;text-align:center;">
    <a href="{{console_url}}" style="color:#FFFFFF;font-size:14px;font-weight:700;text-decoration:none;">Open national dashboard →</a>
  </div>
</td></tr>

HTML . $this->footer('DAILY DIGEST · 24h') . self::WRAP_CLOSE;

        $text = <<<TEXT
{{country_name}} POE SENTINEL — DAILY DIGEST (last 24h)
Issued {{now}}

TOP-LINE
 · Primary screenings: {{primary_screenings_24h}} ({{primary_symptomatic_24h}} symptomatic)
 · Alerts raised: {{alerts_24h}} ({{alerts_critical_24h}} CRITICAL · {{alerts_high_24h}} HIGH · {{alerts_medium_24h}} MEDIUM · {{alerts_low_24h}} LOW)
 · Alerts open >24h: {{alerts_stuck_open}}
 · Follow-ups overdue: {{followups_overdue_total}}
 · Follow-ups due today: {{followups_due_today}}

Dispositions:
 · Released {{disposition_released}} · Referred {{disposition_referred}}
 · Isolated/Quarantined {{disposition_isolated}} · Delayed/Denied {{disposition_delayed}}

TODAY
 · Acknowledge CRITICAL/HIGH alerts still open after 24h
 · Clear {{followups_overdue_total}} overdue follow-ups
 · Contact silent POEs
 · Review suspected disease ranking for clusters

Open dashboard → {{console_url}}
TEXT;

        return [
            'template_code' => 'DAILY_REPORT',
            'levels' => ['PHEOC', 'NATIONAL'],
            'subject' => '▤ Daily digest · {{country_name}} · {{alerts_24h}} alerts · {{followups_overdue_total}} overdue',
            'html' => $html,
            'text' => $text,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  WEEKLY_REPORT
    // ═════════════════════════════════════════════════════════════════════
    private function weeklyReport(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#064E3B 0%,#047857 55%,#059669 100%);padding:26px 28px;">
  <div style="font-size:11px;letter-spacing:0.22em;text-transform:uppercase;color:#A7F3D0;font-weight:700;">{{country_name}} POE SENTINEL · WEEKLY SCORECARD</div>
  <div style="margin-top:6px;font-size:12px;color:#D1FAE5;">7-day window · 7-1-7 performance + trend arrows vs. previous week.</div>
  <div style="margin-top:12px;font-size:22px;font-weight:800;color:#FFFFFF;line-height:1.2;">▥ Weekly executive briefing</div>
</td></tr>

<tr><td style="padding:22px 28px 0;">
  <p style="margin:0;font-size:13px;color:#0F172A;">This is the executive summary of the past 7 days at all {{country_name}} Points of Entry — screening volume, alerts fired, 7-1-7 compliance, and outstanding actions that could cause breaches.</p>
</td></tr>

<tr><td style="padding:16px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#047857;font-weight:800;">7-1-7 scorecard</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;background:#ECFDF5;border:1px solid #A7F3D0;border-radius:8px;">
    <tr><td style="padding:10px 14px;border-bottom:1px solid #A7F3D0;">
      <div style="font-size:12px;color:#047857;font-weight:700;">Detect · within 7 days of first signal</div>
      <div style="font-size:14px;color:#0F172A;margin-top:2px;"><strong>{{detect_compliance}}%</strong> compliance · {{detect_breach_count}} breaches</div>
    </td></tr>
    <tr><td style="padding:10px 14px;border-bottom:1px solid #A7F3D0;">
      <div style="font-size:12px;color:#047857;font-weight:700;">Notify · within 1 day of detection</div>
      <div style="font-size:14px;color:#0F172A;margin-top:2px;"><strong>{{notify_compliance}}%</strong> compliance · {{notify_breach_count}} breaches</div>
    </td></tr>
    <tr><td style="padding:10px 14px;">
      <div style="font-size:12px;color:#047857;font-weight:700;">Respond · effective response within 7 days</div>
      <div style="font-size:14px;color:#0F172A;margin-top:2px;"><strong>{{respond_compliance}}%</strong> compliance · {{respond_breach_count}} breaches</div>
    </td></tr>
  </table>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#047857;font-weight:800;">Volume &amp; trend</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;border:1px solid #E2E8F0;border-radius:6px;background:#F8FAFC;">
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Primary screenings this week</td><td style="padding:6px 12px;font-size:12px;text-align:right;font-weight:700;">{{primary_screenings_7d}} ({{primary_trend}})</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Secondary screenings</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{secondary_screenings_7d}} ({{secondary_trend}})</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Alerts fired · CRITICAL + HIGH</td><td style="padding:6px 12px;font-size:12px;text-align:right;font-weight:700;">{{alerts_high_plus_7d}} ({{alerts_trend}})</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Follow-ups completed on time</td><td style="padding:6px 12px;font-size:12px;text-align:right;">{{followups_ontime_pct}}%</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Follow-ups overdue at report time</td><td style="padding:6px 12px;font-size:12px;text-align:right;font-weight:700;color:#B91C1C;">{{followups_overdue_total}}</td></tr>
  </table>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#047857;font-weight:800;">Disease spotlight — top syndromes + suspected diseases this week</div>
  <div style="margin-top:8px;padding:12px 14px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;">{{{top_diseases_html}}}</div>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#047857;font-weight:800;">POE leaderboard / lag list</div>
  <div style="margin-top:8px;padding:12px 14px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;">
    <p style="margin:0 0 6px;font-size:12px;color:#047857;"><strong>Top 5 POEs by on-time response</strong></p>
    {{{top_poes_html}}}
    <hr style="border:none;border-top:1px dashed #E2E8F0;margin:10px 0;">
    <p style="margin:0 0 6px;font-size:12px;color:#B91C1C;font-weight:700;">Lag list — POEs with breaches or silence &gt;72h</p>
    {{{lag_poes_html}}}
  </div>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#047857;font-weight:800;">Executive actions for the coming week</div>
  <ol style="margin:8px 0 0 20px;padding:0;font-size:13px;color:#0F172A;">
    <li style="margin:5px 0;">Resolve all {{followups_overdue_total}} overdue follow-ups before next Monday.</li>
    <li style="margin:5px 0;">Intervene at lag-list POEs — deploy a supervisor visit or phone check-in.</li>
    <li style="margin:5px 0;">Review suspected-disease cluster patterns with epi team.</li>
    <li style="margin:5px 0;">Update national risk matrix if any Tier 1 advisories fired this week.</li>
    <li style="margin:5px 0;">Pre-brief the WHO country office on any Annex 2-positive events.</li>
  </ol>
</td></tr>

<tr><td style="padding:20px 28px 14px;">
  <div style="background:#047857;border-radius:8px;padding:14px 16px;text-align:center;">
    <a href="{{console_url}}" style="color:#FFFFFF;font-size:14px;font-weight:700;text-decoration:none;">Open full weekly dashboard →</a>
  </div>
</td></tr>

HTML . $this->footer('WEEKLY SCORECARD · 7d') . self::WRAP_CLOSE;

        $text = <<<TEXT
{{country_name}} POE SENTINEL — WEEKLY EXECUTIVE BRIEF
Window: last 7 days · issued {{now}}

7-1-7 SCORECARD
 · Detect ≤7d — {{detect_compliance}}% compliance · {{detect_breach_count}} breaches
 · Notify ≤1d — {{notify_compliance}}% compliance · {{notify_breach_count}} breaches
 · Respond ≤7d — {{respond_compliance}}% compliance · {{respond_breach_count}} breaches

VOLUME
 · Primary screenings: {{primary_screenings_7d}} ({{primary_trend}})
 · Secondary screenings: {{secondary_screenings_7d}} ({{secondary_trend}})
 · CRITICAL+HIGH alerts: {{alerts_high_plus_7d}} ({{alerts_trend}})
 · Follow-ups on-time: {{followups_ontime_pct}}%
 · Follow-ups overdue: {{followups_overdue_total}}

ACTIONS THIS WEEK
 1. Resolve {{followups_overdue_total}} overdue follow-ups
 2. Intervene at lag-list POEs
 3. Review suspected-disease clusters with epi team
 4. Update national risk matrix if any Tier 1 fired
 5. Brief WHO country office on Annex-2 positive events

Open weekly dashboard → {{console_url}}
TEXT;

        return [
            'template_code' => 'WEEKLY_REPORT',
            'levels' => ['PHEOC', 'NATIONAL'],
            'subject' => '▥ Weekly scorecard · {{country_name}} · {{alerts_high_plus_7d}} alerts · {{respond_compliance}}% 7-1-7 respond',
            'html' => $html,
            'text' => $text,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  NATIONAL_INTELLIGENCE
    // ═════════════════════════════════════════════════════════════════════
    private function nationalIntelligence(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#0C0A36 0%,#1E1B4B 50%,#1E40AF 100%);padding:26px 28px;">
  <div style="font-size:11px;letter-spacing:0.22em;text-transform:uppercase;color:#BFDBFE;font-weight:700;">{{country_name}} · NATIONAL POE INTELLIGENCE BRIEF</div>
  <div style="margin-top:6px;font-size:12px;color:#DBEAFE;">Issued every 72 hours · IntelligenceEngine v1 · all POEs, all districts, all alerts.</div>
  <div style="margin-top:12px;font-size:22px;font-weight:800;color:#FFFFFF;line-height:1.2;">◉ National intelligence digest</div>
</td></tr>

<tr><td style="padding:22px 28px 0;">
  <p style="margin:0;font-size:13px;color:#0F172A;">{{narrative}}</p>
</td></tr>

<tr><td style="padding:16px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#1E40AF;font-weight:800;">1 · Silent POEs (no submission in the last 24h)</div>
  <div style="margin-top:8px;padding:12px 14px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;">
    <p style="margin:0 0 6px;font-size:12px;color:#1E3A8A;"><strong>{{silent_poes_count}} POE(s) silent.</strong> A silent POE either has no border traffic — or the duty officer is not logging.</p>
    {{{silent_poes_html}}}
  </div>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#1E40AF;font-weight:800;">2 · Unsubmitted pipelines (&gt;3 days unsynced)</div>
  <div style="margin-top:8px;padding:12px 14px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;">
    <p style="margin:0 0 6px;font-size:12px;color:#1E3A8A;"><strong>{{unsubmitted_poes_count}} POE(s)</strong> have offline data that has not reached the server. This breaks country-wide visibility.</p>
    {{{unsubmitted_poes_html}}}
  </div>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#1E40AF;font-weight:800;">3 · Dormant accounts (no login in the last 14 days)</div>
  <div style="margin-top:8px;padding:12px 14px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;">
    <p style="margin:0 0 6px;font-size:12px;color:#1E3A8A;"><strong>{{dormant_accounts_count}} officer account(s)</strong> are dormant. Credential hygiene + roster refresh recommended.</p>
    {{{dormant_accounts_html}}}
  </div>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#1E40AF;font-weight:800;">4 · Stuck alerts (open &gt;SLA · not acknowledged)</div>
  <div style="margin-top:8px;padding:12px 14px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;">
    <p style="margin:0 0 6px;font-size:12px;color:#B91C1C;font-weight:700;">{{stuck_alerts_count}} alert(s) stuck.</p>
    {{{stuck_alerts_html}}}
  </div>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#1E40AF;font-weight:800;">5 · Overdue follow-ups (blocking closure)</div>
  <div style="margin-top:8px;padding:12px 14px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;">
    <p style="margin:0 0 6px;font-size:12px;color:#B91C1C;font-weight:700;">{{overdue_followups_count}} follow-up action(s) past their window.</p>
    {{{overdue_followups_html}}}
  </div>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#1E40AF;font-weight:800;">6 · Case spikes &amp; cluster signals</div>
  <div style="margin-top:8px;padding:12px 14px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;">
    <p style="margin:0 0 6px;font-size:12px;color:#1E3A8A;"><strong>{{case_spikes_count}}</strong> anomalies detected vs. rolling 14-day baseline.</p>
    {{{case_spikes_html}}}
  </div>
</td></tr>

<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#1E40AF;font-weight:800;">7 · Executive read-out &amp; recommendations</div>
  <ul style="margin:8px 0 0 20px;padding:0;font-size:13px;color:#0F172A;">
    <li style="margin:5px 0;">Reach out to every silent POE listed above within 24h.</li>
    <li style="margin:5px 0;">Contact officers from dormant accounts; confirm roster is correct.</li>
    <li style="margin:5px 0;">Re-assign ownership for stuck alerts and overdue follow-ups.</li>
    <li style="margin:5px 0;">Investigate any cluster signal — contact DHO of the affected district.</li>
    <li style="margin:5px 0;">Verify Annex 2 pipeline works end-to-end — any suspected Tier 1 event must be notified to WHO within 24h.</li>
  </ul>
</td></tr>

<tr><td style="padding:20px 28px 14px;">
  <div style="background:#1E40AF;border-radius:8px;padding:14px 16px;text-align:center;">
    <a href="{{console_url}}" style="color:#FFFFFF;font-size:14px;font-weight:700;text-decoration:none;">Open national intelligence console →</a>
  </div>
</td></tr>

HTML . $this->footer('INTELLIGENCE · EVERY 72h') . self::WRAP_CLOSE;

        $text = <<<TEXT
{{country_name}} POE SENTINEL — NATIONAL INTELLIGENCE BRIEF
Issued {{now}} (every 72h)

{{narrative}}

1. Silent POEs ({{silent_poes_count}})
2. Unsubmitted pipelines ({{unsubmitted_poes_count}})
3. Dormant officer accounts ({{dormant_accounts_count}})
4. Stuck alerts ({{stuck_alerts_count}})
5. Overdue follow-ups ({{overdue_followups_count}})
6. Case spikes vs 14-day baseline ({{case_spikes_count}})

EXECUTIVE ACTIONS
 · Reach out to every silent POE within 24h
 · Contact dormant officers; verify roster
 · Re-assign owners for stuck alerts + overdue follow-ups
 · Investigate cluster signals with affected DHOs
 · Verify Annex 2 pipeline — Tier 1 must notify WHO in 24h

Open national intelligence console → {{console_url}}
TEXT;

        return [
            'template_code' => 'NATIONAL_INTELLIGENCE',
            'levels' => ['NATIONAL', 'WHO'],
            'subject' => '◉ {{country_name}} intel · silent {{silent_poes_count}} · stuck {{stuck_alerts_count}} · overdue {{overdue_followups_count}}',
            'html' => $html,
            'text' => $text,
        ];
    }
}
