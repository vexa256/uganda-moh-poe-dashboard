-- ═══════════════════════════════════════════════════════════════════════════
-- ALERT ENGINE v2 — 2026-04-21 v6
-- Hardcoded WHO/IDSR/7-1-7 alert + intelligence + responder workflow
-- ═══════════════════════════════════════════════════════════════════════════
-- Adds:
--   1. notification_suppressions  — anti-spam dedup keyed by template+entity+contact
--   2. external_responders        — hospitals / labs / responders requested ad-hoc
--   3. responder_info_requests    — outbound info-request tickets (token-tracked)
--   4. ALERT_CASE_FILE template   — rich secondary-case payload
--   5. NATIONAL_INTELLIGENCE      — triennial digest
--   6. RESPONDER_INFO_REQUEST     — outsider info-request email
--
-- Idempotent. Safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── 1. notification_suppressions ─────────────────────────────────────────
-- A row exists for the most-recent send of each (template, entity, contact)
-- triple. Dispatcher checks this BEFORE sending and skips if last_sent_at
-- was within the suppression window (template-specific). Updated after each
-- successful send. This is what stops the "same alert blasting the roster
-- 6 times in 2 minutes" failure mode.
CREATE TABLE IF NOT EXISTS `notification_suppressions` (
  `id`                  bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_code`       varchar(60) NOT NULL,
  `related_entity_type` varchar(40) NOT NULL,
  `related_entity_id`   bigint UNSIGNED NOT NULL,
  `contact_id`          bigint UNSIGNED NOT NULL,
  `last_sent_at`        datetime NOT NULL,
  `send_count`          int UNSIGNED NOT NULL DEFAULT 1,
  `created_at`          datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notif_suppr_unique` (`template_code`, `related_entity_type`, `related_entity_id`, `contact_id`),
  KEY `notif_suppr_recent_idx` (`last_sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ─── 2. external_responders ───────────────────────────────────────────────
-- Hospitals, labs, partner agencies that may be asked to provide structured
-- information about a case. Kept separate from poe_notification_contacts
-- because they DO NOT receive the routine roster broadcasts — they only
-- receive case-specific RESPONDER_INFO_REQUEST emails.
CREATE TABLE IF NOT EXISTS `external_responders` (
  `id`            bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `country_code`  varchar(10) NOT NULL,
  `district_code` varchar(30) DEFAULT NULL,
  `responder_type` enum('HOSPITAL','LAB','EMS','LAW_ENFORCEMENT','PARTNER_AGENCY','OTHER') NOT NULL DEFAULT 'HOSPITAL',
  `name`          varchar(160) NOT NULL,
  `organisation`  varchar(160) DEFAULT NULL,
  `position`      varchar(120) DEFAULT NULL,
  `email`         varchar(160) NOT NULL,
  `phone`         varchar(40) DEFAULT NULL,
  `notes`         varchar(500) DEFAULT NULL,
  `is_active`     tinyint(1) NOT NULL DEFAULT 1,
  `created_by_user_id` bigint UNSIGNED NOT NULL,
  `created_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ext_resp_country_idx` (`country_code`),
  KEY `ext_resp_type_idx`    (`responder_type`),
  KEY `ext_resp_active_idx`  (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ─── 3. responder_info_requests ───────────────────────────────────────────
-- Outbound information-request tickets. Each carries a one-time token that
-- could later be exchanged for a structured submission form (out of scope
-- for this iteration but the table is ready).
CREATE TABLE IF NOT EXISTS `responder_info_requests` (
  `id`              bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `responder_id`    bigint UNSIGNED NOT NULL,
  `alert_id`        bigint UNSIGNED DEFAULT NULL,
  `secondary_screening_id` bigint UNSIGNED DEFAULT NULL,
  `requested_by_user_id`  bigint UNSIGNED NOT NULL,
  `request_token`   char(64) NOT NULL,
  `request_subject` varchar(200) NOT NULL,
  `request_body`    text NOT NULL,
  `status`          enum('SENT','RECEIVED','EXPIRED','CANCELLED') NOT NULL DEFAULT 'SENT',
  `expires_at`      datetime DEFAULT NULL,
  `responded_at`    datetime DEFAULT NULL,
  `response_payload` json DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `resp_req_token_unique` (`request_token`),
  KEY `resp_req_responder_idx` (`responder_id`),
  KEY `resp_req_alert_idx`     (`alert_id`),
  KEY `resp_req_status_idx`    (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- 4. ALERT_CASE_FILE — rich secondary-case alert template
-- ═══════════════════════════════════════════════════════════════════════════
INSERT INTO notification_templates
  (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('ALERT_CASE_FILE', 'EMAIL',
'POE Sentinel · {{poe_code}} · {{risk_level}} · {{alert_title}}',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:5px;background:linear-gradient(90deg,#1E40AF 0%,#8B5CF6 50%,#EC4899 100%);"></td></tr>
<tr><td style="padding:26px 32px 18px;background:linear-gradient(135deg,#001D3D 0%,#003566 55%,#003F88 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.75);text-transform:uppercase;">Case File · {{poe_code}} · IHR 2005</div>
<div style="font-size:24px;font-weight:900;letter-spacing:-.4px;color:#FFFFFF;margin-top:6px;line-height:1.2;">{{alert_title}}</div>
<div style="font-size:12px;color:#BFDBFE;font-family:ui-monospace,Menlo,monospace;margin-top:6px;">{{alert_code}} · case #{{secondary_case_id}}</div>
</td></tr>
<tr><td style="padding:24px 32px 8px;">
<p style="margin:0 0 14px;font-size:13.5px;line-height:1.55;color:#1F2937;">A secondary screening case has progressed to a state requiring stakeholder awareness and structured follow-up. The complete case context is below; the full IDSR record is available in POE Sentinel.</p>

<div style="font-size:10.5px;font-weight:900;letter-spacing:1.5px;color:#475569;text-transform:uppercase;margin:18px 0 6px;">What happened</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F8FAFC;border:1px solid #E8EDF5;border-radius:8px;padding:12px 14px;">
<tr><td style="font-size:12.5px;color:#1F2937;line-height:1.5;">{{summary_what}}</td></tr></table>

<div style="font-size:10.5px;font-weight:900;letter-spacing:1.5px;color:#475569;text-transform:uppercase;margin:18px 0 6px;">Where & when</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #E8EDF5;border-radius:8px;overflow:hidden;">
<tr><td style="padding:9px 14px;background:#F8FAFC;font-size:11px;font-weight:700;color:#475569;width:40%;">POE</td><td style="padding:9px 14px;background:#F8FAFC;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{poe_code}}</td></tr>
<tr><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">District</td><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{district_code}}</td></tr>
<tr><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Country</td><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{country_code}}</td></tr>
<tr><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Case opened</td><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{opened_at}}</td></tr>
<tr><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Alert created</td><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{alert_created_at}}</td></tr>
</table>

<div style="font-size:10.5px;font-weight:900;letter-spacing:1.5px;color:#475569;text-transform:uppercase;margin:18px 0 6px;">Who is involved</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #E8EDF5;border-radius:8px;overflow:hidden;">
<tr><td style="padding:9px 14px;background:#F8FAFC;font-size:11px;font-weight:700;color:#475569;width:40%;">Traveller</td><td style="padding:9px 14px;background:#F8FAFC;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{traveler_label}}</td></tr>
<tr><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Opened by</td><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{opened_by_name}}</td></tr>
<tr><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Owner role</td><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{owner_role}}</td></tr>
</table>

<div style="font-size:10.5px;font-weight:900;letter-spacing:1.5px;color:#475569;text-transform:uppercase;margin:18px 0 6px;">Why it matters</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:12px 14px;">
<tr><td style="font-size:12px;color:#78350F;line-height:1.55;">{{why_it_matters}}</td></tr></table>

<div style="font-size:10.5px;font-weight:900;letter-spacing:1.5px;color:#475569;text-transform:uppercase;margin:18px 0 6px;">Status snapshot</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #E8EDF5;border-radius:8px;overflow:hidden;">
<tr><td style="padding:9px 14px;background:#F8FAFC;font-size:11px;font-weight:700;color:#475569;width:40%;">Risk level</td><td style="padding:9px 14px;background:#F8FAFC;font-size:13px;font-weight:800;color:#991B1B;text-align:right;">{{risk_level}}</td></tr>
<tr><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">IHR tier</td><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{ihr_tier}}</td></tr>
<tr><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Routed to</td><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{routed_to_level}}</td></tr>
<tr><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Case status</td><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{case_status}}</td></tr>
<tr><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Disposition</td><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{final_disposition}}</td></tr>
</table>

<div style="font-size:10.5px;font-weight:900;letter-spacing:1.5px;color:#475569;text-transform:uppercase;margin:18px 0 6px;">Actions already taken</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:12px 14px;">
<tr><td style="font-size:12px;color:#14532D;line-height:1.6;">{{actions_taken}}</td></tr></table>

<div style="font-size:10.5px;font-weight:900;letter-spacing:1.5px;color:#475569;text-transform:uppercase;margin:18px 0 6px;">Next required action</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:14px;">
<tr><td><div style="font-size:13px;font-weight:800;color:#1E3A8A;">{{next_action_label}}</div>
<div style="font-size:11.5px;color:#1E40AF;margin-top:4px;line-height:1.5;">{{next_action_body}}</div>
<div style="font-size:10.5px;color:#475569;font-weight:700;margin-top:6px;">Owner: <strong>{{next_action_owner}}</strong>{{#deadline_block}} · Deadline: <strong>{{next_action_deadline}}</strong>{{/deadline_block}}</div></td></tr></table>

<div align="center" style="margin:24px 0 12px;"><a href="https://poe.health.go.ug/secondary-screening/records?open={{secondary_case_uuid}}" style="display:inline-block;padding:13px 30px;background:linear-gradient(135deg,#1E40AF,#3B82F6);color:#FFFFFF;text-decoration:none;border-radius:8px;font-size:13.5px;font-weight:800;box-shadow:0 4px 14px rgba(30,64,175,.4);">Open case file in POE Sentinel</a></div>

<p style="margin:14px 0 0;font-size:11px;color:#64748B;line-height:1.6;text-align:center;">Case ID: <strong style="color:#0F172A;font-family:ui-monospace,Menlo,monospace;">{{secondary_case_uuid}}</strong></p>
</td></tr>
<tr><td style="padding:14px 32px;background:#0F172A;border-top:1px solid #1E293B;"><div style="font-size:10px;color:rgba(255,255,255,.55);line-height:1.5;">Uganda National POE Screening Tool · WHO IHR 2005 · IDSR 3rd Ed. · Generated automatically. Reply to this email for case-specific questions.</div></td></tr></table>
</td></tr></table></body></html>',
'POE Sentinel case file · {{poe_code}} · {{alert_title}} · Risk {{risk_level}} · IHR {{ihr_tier}}. {{summary_what}} Next: {{next_action_label}} (owner: {{next_action_owner}}). Case ID {{secondary_case_uuid}}.',
'["POE","DISTRICT","PHEOC","NATIONAL"]', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), is_ai_enhanced=1, updated_at=NOW();

-- ═══════════════════════════════════════════════════════════════════════════
-- 5. NATIONAL_INTELLIGENCE digest template (every 3 days)
-- ═══════════════════════════════════════════════════════════════════════════
INSERT INTO notification_templates
  (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('NATIONAL_INTELLIGENCE', 'EMAIL',
'POE Sentinel · National Surveillance Intelligence · {{report_date}}',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:5px;background:linear-gradient(90deg,#0F172A 0%,#1E40AF 50%,#8B5CF6 100%);"></td></tr>
<tr><td style="padding:28px 32px 18px;background:linear-gradient(135deg,#0F172A 0%,#1E1B4B 55%,#001D3D 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2.4px;color:rgba(255,255,255,.75);text-transform:uppercase;">Triennial National Intelligence · {{country_code}}</div>
<div style="font-size:24px;font-weight:900;letter-spacing:-.4px;color:#FFFFFF;margin-top:6px;">National Surveillance Intelligence</div>
<div style="font-size:13px;color:#C7D2FE;margin-top:4px;">{{report_date}} · 3-day rolling window</div></td></tr>
<tr><td style="padding:24px 32px 8px;">
<p style="margin:0 0 14px;font-size:13.5px;line-height:1.55;color:#1F2937;">Automated national surveillance intelligence digest. This briefing surfaces operational signals that require coordinated attention — gaps, spikes, dormancy, and unresolved cases — without spamming the operational roster.</p>

<div style="font-size:10.5px;font-weight:900;letter-spacing:1.5px;color:#475569;text-transform:uppercase;margin:18px 0 6px;">Surveillance health</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #E8EDF5;border-radius:8px;overflow:hidden;">
<tr><td style="padding:11px 14px;background:#F8FAFC;font-size:11.5px;font-weight:700;color:#475569;width:55%;">POEs without primary screening (24h+)</td><td style="padding:11px 14px;background:#F8FAFC;font-size:14px;font-weight:900;color:#991B1B;text-align:right;">{{poe_silent_24h}}</td></tr>
<tr><td style="padding:11px 14px;border-top:1px solid #E8EDF5;font-size:11.5px;font-weight:700;color:#475569;">POEs without aggregated submission (3d+)</td><td style="padding:11px 14px;border-top:1px solid #E8EDF5;font-size:14px;font-weight:900;color:#991B1B;text-align:right;">{{poe_no_submission_3d}}</td></tr>
<tr><td style="padding:11px 14px;border-top:1px solid #E8EDF5;font-size:11.5px;font-weight:700;color:#475569;">Dormant accounts (no login 14d+)</td><td style="padding:11px 14px;border-top:1px solid #E8EDF5;font-size:14px;font-weight:900;color:#9A3412;text-align:right;">{{dormant_accounts}}</td></tr>
</table>

<div style="font-size:10.5px;font-weight:900;letter-spacing:1.5px;color:#475569;text-transform:uppercase;margin:18px 0 6px;">Case load</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #E8EDF5;border-radius:8px;overflow:hidden;">
<tr><td style="padding:11px 14px;background:#F8FAFC;font-size:11.5px;font-weight:700;color:#475569;width:55%;">Alerts open &gt; 24h without acknowledgement</td><td style="padding:11px 14px;background:#F8FAFC;font-size:14px;font-weight:900;color:#991B1B;text-align:right;">{{stuck_alerts}}</td></tr>
<tr><td style="padding:11px 14px;border-top:1px solid #E8EDF5;font-size:11.5px;font-weight:700;color:#475569;">RTSL follow-ups overdue</td><td style="padding:11px 14px;border-top:1px solid #E8EDF5;font-size:14px;font-weight:900;color:#9A3412;text-align:right;">{{overdue_followups}}</td></tr>
<tr><td style="padding:11px 14px;border-top:1px solid #E8EDF5;font-size:11.5px;font-weight:700;color:#475569;">Symptomatic-rate spike (POEs over 7d baseline)</td><td style="padding:11px 14px;border-top:1px solid #E8EDF5;font-size:14px;font-weight:900;color:#7C2D12;text-align:right;">{{spike_count}}</td></tr>
</table>

<div style="font-size:10.5px;font-weight:900;letter-spacing:1.5px;color:#475569;text-transform:uppercase;margin:18px 0 6px;">Activity (3-day window)</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr>
<td width="33%" style="padding:4px;" align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#EFF6FF;border-radius:10px;padding:14px;border:1px solid #BFDBFE;"><tr><td align="center"><div style="font-size:22px;font-weight:900;color:#1E40AF;line-height:1;">{{total_screenings_3d}}</div><div style="font-size:9.5px;font-weight:800;color:#1E3A8A;margin-top:6px;letter-spacing:.4px;text-transform:uppercase;">Screenings</div></td></tr></table></td>
<td width="33%" style="padding:4px;" align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#FAF5FF;border-radius:10px;padding:14px;border:1px solid #E9D5FF;"><tr><td align="center"><div style="font-size:22px;font-weight:900;color:#6B21A8;line-height:1;">{{total_secondary_3d}}</div><div style="font-size:9.5px;font-weight:800;color:#581C87;margin-top:6px;letter-spacing:.4px;text-transform:uppercase;">Secondary cases</div></td></tr></table></td>
<td width="33%" style="padding:4px;" align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#FEF2F2;border-radius:10px;padding:14px;border:1px solid #FECACA;"><tr><td align="center"><div style="font-size:22px;font-weight:900;color:#991B1B;line-height:1;">{{total_alerts_3d}}</div><div style="font-size:9.5px;font-weight:800;color:#7F1D1D;margin-top:6px;letter-spacing:.4px;text-transform:uppercase;">Alerts raised</div></td></tr></table></td>
</tr></table>

<div style="font-size:10.5px;font-weight:900;letter-spacing:1.5px;color:#475569;text-transform:uppercase;margin:20px 0 6px;">Detected anomalies</div>
<div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:14px;font-size:12px;color:#78350F;line-height:1.7;">{{anomaly_summary}}</div>

<div style="background:#F8FAFC;border-left:3px solid #1E40AF;padding:12px 14px;margin-top:18px;font-size:11.5px;color:#475569;line-height:1.55;"><em>This digest is sent to NATIONAL_ADMIN-tier subscribers every 3 days. Operational broadcast roster is not included to avoid noise. Per-POE drill-down is in the in-app Intelligence Hub.</em></div>

<div align="center" style="margin:18px 0 6px;"><a href="https://poe.health.go.ug/alerts/intelligence" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#0F172A,#1E40AF);color:#FFFFFF;text-decoration:none;border-radius:8px;font-size:13px;font-weight:800;">Open Intelligence Hub →</a></div>
</td></tr>
<tr><td style="padding:14px 32px;background:#0F172A;"><div style="font-size:10px;color:rgba(255,255,255,.55);line-height:1.5;">Uganda national surveillance intelligence digest · WHO IDSR 3rd Ed. · Generated by hardcoded detector engine.</div></td></tr></table>
</td></tr></table></body></html>',
'National intelligence digest {{report_date}}: silent POEs {{poe_silent_24h}}, no-submission {{poe_no_submission_3d}}, dormant {{dormant_accounts}}, stuck alerts {{stuck_alerts}}, overdue followups {{overdue_followups}}. Anomalies: {{anomaly_summary}}.',
'["NATIONAL"]', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), is_ai_enhanced=1, updated_at=NOW();

-- ═══════════════════════════════════════════════════════════════════════════
-- 6. RESPONDER_INFO_REQUEST template (outsider info-request)
-- ═══════════════════════════════════════════════════════════════════════════
INSERT INTO notification_templates
  (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('RESPONDER_INFO_REQUEST', 'EMAIL',
'POE Sentinel · Information request · {{alert_code}}',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:5px;background:linear-gradient(90deg,#0369A1 0%,#0284C7 100%);"></td></tr>
<tr><td style="padding:26px 32px 14px;background:linear-gradient(135deg,#075985 0%,#0369A1 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.75);text-transform:uppercase;">Information request · POE Sentinel</div>
<div style="font-size:22px;font-weight:900;color:#FFFFFF;margin-top:6px;">Case-related information requested</div>
<div style="font-size:12px;color:#BAE6FD;font-family:ui-monospace,Menlo,monospace;margin-top:4px;">{{alert_code}}</div></td></tr>
<tr><td style="padding:24px 32px 8px;">
<p style="margin:0 0 14px;font-size:13.5px;line-height:1.6;color:#1F2937;">Dear {{responder_name}},</p>
<p style="margin:0 0 14px;font-size:13.5px;line-height:1.6;color:#1F2937;">The Uganda Point-of-Entry surveillance team is coordinating a public-health response and would value your structured input on the case below. Your reply will be added to the case file in POE Sentinel and will inform the next steps in the IDSR response.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F9FF;border:1px solid #BAE6FD;border-radius:8px;padding:14px;margin-bottom:18px;">
<tr><td style="font-size:13px;color:#075985;line-height:1.6;">{{request_body}}</td></tr></table>
<div style="font-size:10.5px;font-weight:900;letter-spacing:1.5px;color:#475569;text-transform:uppercase;margin:0 0 6px;">Case context</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #E8EDF5;border-radius:8px;overflow:hidden;">
<tr><td style="padding:9px 14px;background:#F8FAFC;font-size:11px;font-weight:700;color:#475569;">POE</td><td style="padding:9px 14px;background:#F8FAFC;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{poe_code}}</td></tr>
<tr><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Risk level</td><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{risk_level}}</td></tr>
<tr><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Reference</td><td style="padding:9px 14px;border-top:1px solid #E8EDF5;font-size:11.5px;font-weight:700;color:#0F172A;font-family:ui-monospace,Menlo,monospace;text-align:right;">{{request_token}}</td></tr></table>
<p style="margin:18px 0 0;font-size:11.5px;color:#64748B;line-height:1.6;">Please reply directly to this email or contact the operational team at <a href="mailto:vexa256@gmail.com" style="color:#0369A1;">vexa256@gmail.com</a>. Thank you for supporting the national surveillance response.</p>
</td></tr>
<tr><td style="padding:14px 32px;background:#F8FAFC;border-top:1px solid #E8EDF5;"><div style="font-size:10px;color:#64748B;line-height:1.5;">Uganda Ministry of Health · POE Sentinel · WHO IHR 2005 · IDSR 3rd Ed.</div></td></tr></table>
</td></tr></table></body></html>',
'POE Sentinel info request {{alert_code}}: {{request_body}} · POE: {{poe_code}} · Risk: {{risk_level}} · Reference: {{request_token}}.',
'["POE","DISTRICT","PHEOC","NATIONAL"]', 0, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), updated_at=NOW();

SELECT COUNT(*) AS total_templates FROM notification_templates WHERE is_active = 1;
SELECT COUNT(*) AS suppression_table_ready FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'notification_suppressions';
SELECT COUNT(*) AS responders_ready          FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'external_responders';
