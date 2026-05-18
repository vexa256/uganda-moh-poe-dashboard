-- ═══════════════════════════════════════════════════════════════════════════
-- PREMIUM EMAIL TEMPLATES — 2026-04-21 v4
-- ═══════════════════════════════════════════════════════════════════════════
-- Replaces the 12 placeholder notification templates with production-grade
-- HTML emails tuned for Gmail / Outlook / Apple Mail / mobile clients.
--
-- Design system (identical across every event type):
--   • Table-based layout (required for Outlook)
--   • 600px max-width, centered
--   • Inline CSS only (Gmail strips <style>)
--   • Dark-navy → cobalt header with IHR gold accent bar
--   • Data-rich "facts" grid — two-column label/value rows
--   • Prominent action card with gradient button
--   • Always-visible footer with WHO/IHR citations
--   • System font stack (no webfont deps — offline-friendly)
--   • Per-event accent colour applied to hero + action card
--
-- Variables rendered via Mustache-style {{token}} substitution by
-- NotificationsController::render().
--
-- Idempotent: uses INSERT ... ON DUPLICATE KEY UPDATE keyed by
-- (template_code, channel), so re-running this file only refreshes content.
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── 1. ALERT_CRITICAL ────────────────────────────────────────────────────
INSERT INTO notification_templates (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('ALERT_CRITICAL', 'EMAIL',
'🚨 CRITICAL · {{alert_title}} · {{poe_code}} · IHR Response Required',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Critical Alert</title></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:5px;background:linear-gradient(90deg,#DC2626 0%,#F59E0B 100%);"></td></tr>
<tr><td style="padding:28px 32px 18px;background:linear-gradient(135deg,#7F1D1D 0%,#991B1B 55%,#001D3D 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.75);text-transform:uppercase;">IHR · POE Sentinel · {{poe_code}}</div>
<div style="font-size:26px;font-weight:900;letter-spacing:-.5px;color:#FFFFFF;margin-top:6px;line-height:1.18;">🚨 CRITICAL ALERT</div>
<div style="font-size:14px;color:#FEE2E2;font-weight:600;margin-top:4px;">{{alert_title}}</div>
</td></tr>
<tr><td style="padding:24px 32px 8px;">
<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#1F2937;"><em>This event matches CRITICAL-risk criteria under IHR 2005. Immediate clinical response, contact tracing, and POE emergency-protocol activation are required. Acknowledge within <strong>{{ack_hours}} hours</strong>.</em></p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:16px;margin-bottom:18px;">
<tr><td style="font-size:10px;font-weight:800;letter-spacing:1.2px;color:#991B1B;text-transform:uppercase;">Alert Summary</td></tr>
<tr><td style="padding-top:8px;font-size:13.5px;color:#7F1D1D;line-height:1.5;">{{alert_details}}</td></tr></table>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #E8EDF5;border-radius:10px;overflow:hidden;margin-bottom:20px;">
<tr><td style="padding:10px 14px;background:#F8FAFC;font-size:11px;font-weight:700;color:#475569;">Alert code</td><td style="padding:10px 14px;background:#F8FAFC;font-size:13px;font-weight:700;color:#0F172A;font-family:ui-monospace,Menlo,Consolas,monospace;text-align:right;">{{alert_code}}</td></tr>
<tr><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Risk level</td><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:13px;font-weight:800;color:#991B1B;text-align:right;">{{risk_level}}</td></tr>
<tr><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Routed to</td><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:13px;font-weight:700;color:#0F172A;text-align:right;">{{routed_to_level}}</td></tr>
<tr><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">IHR tier</td><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:13px;font-weight:700;color:#0F172A;text-align:right;">{{ihr_tier}}</td></tr>
<tr><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">District</td><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:13px;font-weight:700;color:#0F172A;text-align:right;">{{district_code}}</td></tr></table>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:linear-gradient(135deg,#0F172A 0%,#1E40AF 100%);border-radius:10px;padding:18px;margin-bottom:20px;">
<tr><td align="center"><div style="font-size:10px;font-weight:800;letter-spacing:1.6px;color:rgba(255,255,255,.72);text-transform:uppercase;margin-bottom:8px;">Acknowledge within {{ack_hours}}h</div>
<a href="https://poe.health.go.ug/alerts" style="display:inline-block;padding:13px 30px;background:#FFFFFF;color:#991B1B;text-decoration:none;border-radius:8px;font-size:14px;font-weight:800;letter-spacing:.3px;box-shadow:0 4px 14px rgba(0,0,0,.25);">Open POE Sentinel →</a></td></tr></table>
<p style="margin:0 0 8px;font-size:11px;color:#64748B;line-height:1.5;"><strong style="color:#0F172A;">Why you''re getting this:</strong> you are on the notification roster for {{poe_code}} with the "Critical alerts" flag enabled. Manage your subscriptions in <em>Admin → POE Contacts</em>.</p></td></tr>
<tr><td style="padding:16px 32px;background:#0F172A;border-top:3px solid #DC2626;">
<div style="font-size:10px;color:rgba(255,255,255,.55);line-height:1.6;">Uganda National POE Screening Tool · WHO IHR 2005 aligned · This is an automated alert from the national surveillance platform. Do not reply to this email — operational coordination happens inside POE Sentinel.</div></td></tr></table>
</td></tr></table></body></html>',
'CRITICAL ALERT at {{poe_code}}. {{alert_title}}. Details: {{alert_details}}. Code: {{alert_code}} · Risk: {{risk_level}} · Routed: {{routed_to_level}} · IHR: {{ihr_tier}}. Acknowledge within {{ack_hours}}h via POE Sentinel.',
'["POE","DISTRICT","PHEOC","NATIONAL"]', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), is_ai_enhanced=1, updated_at=NOW();

-- ─── 2. ALERT_HIGH ────────────────────────────────────────────────────────
INSERT INTO notification_templates (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('ALERT_HIGH', 'EMAIL',
'⚠ HIGH · {{alert_title}} · {{poe_code}}',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:5px;background:linear-gradient(90deg,#EA580C 0%,#F59E0B 100%);"></td></tr>
<tr><td style="padding:28px 32px 18px;background:linear-gradient(135deg,#9A3412 0%,#C2410C 55%,#001D3D 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.75);text-transform:uppercase;">IHR · POE Sentinel · {{poe_code}}</div>
<div style="font-size:24px;font-weight:900;letter-spacing:-.4px;color:#FFFFFF;margin-top:6px;">⚠ HIGH-risk alert</div>
<div style="font-size:14px;color:#FED7AA;font-weight:600;margin-top:4px;">{{alert_title}}</div></td></tr>
<tr><td style="padding:24px 32px 8px;">
<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#1F2937;">Acknowledge within <strong>4 hours</strong>. Coordinate with {{routed_to_level}} health authorities. Review the linked case disposition before closing.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:10px;padding:14px;margin-bottom:18px;">
<tr><td style="font-size:13px;color:#7C2D12;line-height:1.5;">{{alert_details}}</td></tr></table>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #E8EDF5;border-radius:10px;overflow:hidden;margin-bottom:20px;">
<tr><td style="padding:10px 14px;background:#F8FAFC;font-size:11px;font-weight:700;color:#475569;">Alert code</td><td style="padding:10px 14px;background:#F8FAFC;font-size:13px;font-weight:700;color:#0F172A;font-family:ui-monospace,Menlo,Consolas,monospace;text-align:right;">{{alert_code}}</td></tr>
<tr><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Risk</td><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:13px;font-weight:800;color:#9A3412;text-align:right;">{{risk_level}}</td></tr>
<tr><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Routed to</td><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:13px;font-weight:700;color:#0F172A;text-align:right;">{{routed_to_level}}</td></tr></table>
<div align="center" style="margin:0 0 16px;"><a href="https://poe.health.go.ug/alerts" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#EA580C,#C2410C);color:#FFFFFF;text-decoration:none;border-radius:8px;font-size:13px;font-weight:800;box-shadow:0 4px 14px rgba(234,88,12,.35);">Acknowledge in POE Sentinel →</a></div></td></tr>
<tr><td style="padding:14px 32px;background:#F8FAFC;border-top:1px solid #E8EDF5;"><div style="font-size:10px;color:#64748B;line-height:1.5;">POE Sentinel · Uganda National Surveillance · WHO IHR 2005.</div></td></tr></table>
</td></tr></table></body></html>',
'HIGH alert at {{poe_code}}: {{alert_title}}. {{alert_details}} Acknowledge within 4h.',
'["POE","DISTRICT","PHEOC","NATIONAL"]', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), is_ai_enhanced=1, updated_at=NOW();

-- ─── 3. TIER1_ADVISORY ────────────────────────────────────────────────────
INSERT INTO notification_templates (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('TIER1_ADVISORY', 'EMAIL',
'🌍 IHR TIER 1 · {{alert_title}} · Single case = WHO notification within 24h',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:5px;background:linear-gradient(90deg,#9333EA 0%,#EC4899 100%);"></td></tr>
<tr><td style="padding:28px 32px 18px;background:linear-gradient(135deg,#581C87 0%,#6B21A8 55%,#001D3D 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.75);text-transform:uppercase;">IHR 2005 · Annex 2 · Always Notifiable</div>
<div style="font-size:26px;font-weight:900;letter-spacing:-.5px;color:#FFFFFF;margin-top:6px;">🌍 TIER 1 Event Detected</div>
<div style="font-size:14px;color:#E9D5FF;font-weight:600;margin-top:4px;">{{alert_title}}</div></td></tr>
<tr><td style="padding:24px 32px 8px;">
<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#1F2937;">A Tier 1 always-notifiable event has been detected at <strong>{{poe_code}}</strong>. Under IHR 2005 Annex 2, a single confirmed or probable case is sufficient trigger for mandatory WHO notification — the 4-criteria Annex 2 assessment is bypassed.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F3E8FF;border:1px solid #D8B4FE;border-radius:10px;padding:16px;margin-bottom:18px;">
<tr><td><div style="font-size:10px;font-weight:800;letter-spacing:1.5px;color:#6B21A8;text-transform:uppercase;margin-bottom:6px;">⏱ 24-Hour Obligation</div><div style="font-size:13.5px;color:#4C1D95;line-height:1.6;">Confirm the National IHR Focal Point has been briefed. Escalate to the Minister of Health in parallel. WHO must be notified via the NFP within 24 hours (IHR Article 6).</div></td></tr></table>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #E8EDF5;border-radius:10px;overflow:hidden;margin-bottom:18px;">
<tr><td style="padding:12px 14px;font-size:11px;font-weight:700;color:#475569;background:#F8FAFC;">Alert code</td><td style="padding:12px 14px;font-size:13px;font-weight:700;color:#0F172A;font-family:ui-monospace,Menlo,Consolas,monospace;background:#F8FAFC;text-align:right;">{{alert_code}}</td></tr>
<tr><td style="padding:12px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">POE</td><td style="padding:12px 14px;border-top:1px solid #E8EDF5;font-size:13px;font-weight:700;color:#0F172A;text-align:right;">{{poe_code}}</td></tr>
<tr><td style="padding:12px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">IHR tier</td><td style="padding:12px 14px;border-top:1px solid #E8EDF5;font-size:13px;font-weight:800;color:#6B21A8;text-align:right;">{{ihr_tier}}</td></tr></table>
<div style="background:#FAFAF9;border-left:3px solid #6B21A8;padding:12px 14px;margin:0 0 18px;font-size:12px;color:#44403C;line-height:1.5;"><strong>Tier 1 diseases</strong> (IHR 2005 Annex 2, always notifiable): Smallpox, Poliomyelitis (wild poliovirus), Human influenza caused by a new subtype, SARS.</div>
<div align="center"><a href="https://poe.health.go.ug/alerts/intelligence" style="display:inline-block;padding:13px 28px;background:linear-gradient(135deg,#6B21A8,#9333EA);color:#FFFFFF;text-decoration:none;border-radius:8px;font-size:13px;font-weight:800;box-shadow:0 4px 14px rgba(107,33,168,.4);">Open Alert Intelligence →</a></div></td></tr>
<tr><td style="padding:14px 32px;background:#0F172A;border-top:3px solid #9333EA;"><div style="font-size:10px;color:rgba(255,255,255,.55);line-height:1.5;">IHR 2005 Third Edition Annex 2 · Events that shall always lead to utilisation of the algorithm.</div></td></tr></table>
</td></tr></table></body></html>',
'IHR TIER 1 EVENT at {{poe_code}}. {{alert_title}}. Single case requires WHO notification within 24h (IHR Art. 6). Alert code: {{alert_code}}.',
'["NATIONAL","PHEOC"]', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), is_ai_enhanced=1, updated_at=NOW();

-- ─── 4. ANNEX2_HIT ────────────────────────────────────────────────────────
INSERT INTO notification_templates (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('ANNEX2_HIT', 'EMAIL',
'📋 Annex 2 · {{alert_title}} · {{annex2_yes}}/4 criteria met',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:5px;background:linear-gradient(90deg,#1E40AF 0%,#3B82F6 100%);"></td></tr>
<tr><td style="padding:28px 32px 18px;background:linear-gradient(135deg,#1E3A8A 0%,#1E40AF 55%,#001D3D 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.75);text-transform:uppercase;">IHR 2005 · Annex 2 Decision Instrument</div>
<div style="font-size:26px;font-weight:900;letter-spacing:-.5px;color:#FFFFFF;margin-top:6px;">📋 Threshold Met: {{annex2_yes}}/4</div>
<div style="font-size:14px;color:#BFDBFE;font-weight:600;margin-top:4px;">{{alert_code}}</div></td></tr>
<tr><td style="padding:24px 32px 8px;">
<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#1F2937;">The Annex 2 4-criteria instrument has returned <strong>{{annex2_yes}} of 4 YES</strong> for this event. The State Party is obligated to notify WHO within 24 hours via the National IHR Focal Point.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:16px;margin-bottom:20px;">
<tr><td><div style="font-size:10px;font-weight:800;letter-spacing:1.5px;color:#1E40AF;text-transform:uppercase;margin-bottom:10px;">The 4 Criteria</div>
<div style="font-size:12.5px;color:#1E3A8A;line-height:1.9;">1. Is the public health impact serious?<br>2. Is the event unusual or unexpected?<br>3. Is there significant risk of international spread?<br>4. Is there significant risk of international travel/trade restrictions?</div>
<div style="margin-top:10px;padding-top:10px;border-top:1px dashed #BFDBFE;font-size:11.5px;color:#1E40AF;font-weight:700;">RULE: ≥ 2 YES → notify WHO within 24h (IHR Art. 6).</div></td></tr></table>
<div align="center"><a href="https://poe.health.go.ug/alerts/intelligence" style="display:inline-block;padding:12px 26px;background:linear-gradient(135deg,#1E40AF,#3B82F6);color:#FFFFFF;text-decoration:none;border-radius:8px;font-size:13px;font-weight:800;box-shadow:0 4px 14px rgba(30,64,175,.4);">Review Annex 2 Scorecard →</a></div></td></tr>
<tr><td style="padding:14px 32px;background:#0F172A;"><div style="font-size:10px;color:rgba(255,255,255,.55);line-height:1.5;">IHR 2005 Third Edition · Annex 2 decision tree, pp. 43-46.</div></td></tr></table>
</td></tr></table></body></html>',
'Annex 2 threshold met: {{annex2_yes}}/4. Alert {{alert_code}}. Notify WHO within 24h (IHR Art. 6).',
'["NATIONAL","PHEOC"]', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), is_ai_enhanced=1, updated_at=NOW();

-- ─── 5. BREACH_717 ────────────────────────────────────────────────────────
INSERT INTO notification_templates (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('BREACH_717', 'EMAIL',
'⏱ 7-1-7 BREACH · {{alert_code}} · {{bottleneck_phase}} stage',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:5px;background:linear-gradient(90deg,#DC2626 0%,#F59E0B 100%);"></td></tr>
<tr><td style="padding:28px 32px 18px;background:linear-gradient(135deg,#7F1D1D 0%,#991B1B 55%,#CA8A04 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.75);text-transform:uppercase;">7-1-7 Performance Target · Resolve to Save Lives × WHO</div>
<div style="font-size:24px;font-weight:900;letter-spacing:-.4px;color:#FFFFFF;margin-top:6px;">⏱ 7-1-7 Breach</div>
<div style="font-size:14px;color:#FEE2E2;font-weight:600;margin-top:4px;">{{bottleneck_phase}} stage · {{alert_code}}</div></td></tr>
<tr><td style="padding:24px 32px 8px;">
<p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#1F2937;">Alert <strong style="font-family:ui-monospace,Menlo,monospace;">{{alert_code}}</strong> has breached the <strong>{{bottleneck_phase}}</strong> performance target. The Resolve to Save Lives / WHO 7-1-7 framework requires root-cause analysis for every missed target.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 18px;"><tr>
<td width="33%" style="background:#F3E8FF;border-radius:10px;padding:14px;text-align:center;border:1px solid #D8B4FE;"><div style="font-size:28px;font-weight:900;color:#6B21A8;line-height:1;">7</div><div style="font-size:10px;font-weight:800;color:#581C87;margin-top:4px;letter-spacing:.3px;">DETECT</div><div style="font-size:9px;color:#7E22CE;">days</div></td>
<td width="33%" style="background:#FEF3C7;border-radius:10px;padding:14px;text-align:center;border:1px solid #FDE68A;"><div style="font-size:28px;font-weight:900;color:#B45309;line-height:1;">1</div><div style="font-size:10px;font-weight:800;color:#854D0E;margin-top:4px;letter-spacing:.3px;">NOTIFY</div><div style="font-size:9px;color:#CA8A04;">day</div></td>
<td width="33%" style="background:#D1FAE5;border-radius:10px;padding:14px;text-align:center;border:1px solid #A7F3D0;"><div style="font-size:28px;font-weight:900;color:#047857;line-height:1;">7</div><div style="font-size:10px;font-weight:800;color:#14532D;margin-top:4px;letter-spacing:.3px;">RESPOND</div><div style="font-size:9px;color:#059669;">days</div></td>
</tr></table>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:14px;margin-bottom:18px;">
<tr><td style="font-size:11px;font-weight:700;color:#7F1D1D;">Elapsed</td><td style="font-size:16px;font-weight:900;color:#991B1B;text-align:right;">{{elapsed_hours}}h</td></tr>
<tr><td style="padding-top:4px;font-size:11px;font-weight:700;color:#7F1D1D;">Target</td><td style="padding-top:4px;font-size:13px;font-weight:800;color:#991B1B;text-align:right;">{{target_hours}}h</td></tr></table>
<div style="background:#F8FAFC;border-left:3px solid #991B1B;padding:12px 14px;font-size:12px;color:#1E293B;line-height:1.6;margin-bottom:18px;"><strong>Root-cause categories</strong> per RTSL: capacity · training · communication · laboratory · coordination · legal · leadership. Document the dominant cause in the Intelligence view.</div>
<div align="center"><a href="https://poe.health.go.ug/alerts/intelligence" style="display:inline-block;padding:12px 26px;background:linear-gradient(135deg,#991B1B,#DC2626);color:#FFFFFF;text-decoration:none;border-radius:8px;font-size:13px;font-weight:800;box-shadow:0 4px 14px rgba(153,27,27,.4);">Open 7-1-7 Ledger →</a></div></td></tr>
<tr><td style="padding:14px 32px;background:#0F172A;"><div style="font-size:10px;color:rgba(255,255,255,.55);line-height:1.5;">Frieden TR et al., Lancet 2021; 398:638-640 · Resolve to Save Lives / WHO.</div></td></tr></table>
</td></tr></table></body></html>',
'7-1-7 BREACH at {{bottleneck_phase}} stage. Alert {{alert_code}}. Elapsed {{elapsed_hours}}h / target {{target_hours}}h. Root-cause analysis required.',
'["DISTRICT","PHEOC","NATIONAL"]', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), is_ai_enhanced=1, updated_at=NOW();

-- ─── 6. FOLLOWUP_DUE ──────────────────────────────────────────────────────
INSERT INTO notification_templates (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('FOLLOWUP_DUE', 'EMAIL',
'🔔 Follow-up due in {{due_in_hours}}h · {{action_label}}',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:4px;background:#3B82F6;"></td></tr>
<tr><td style="padding:26px 32px 14px;background:linear-gradient(135deg,#1E40AF 0%,#3B82F6 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.75);text-transform:uppercase;">RTSL Early Response · Follow-up</div>
<div style="font-size:22px;font-weight:900;letter-spacing:-.4px;color:#FFFFFF;margin-top:4px;">🔔 Action due soon</div></td></tr>
<tr><td style="padding:22px 32px 8px;">
<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#1F2937;">The follow-up action <strong>"{{action_label}}"</strong> on alert <em style="font-family:ui-monospace,Menlo,monospace;">{{alert_code}}</em> is due in <strong>{{due_in_hours}} hour(s)</strong>.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:14px;margin-bottom:18px;">
<tr><td style="font-size:11px;font-weight:700;color:#1E40AF;">Action</td><td style="font-size:12.5px;font-weight:700;color:#1E3A8A;text-align:right;">{{action_label}}</td></tr>
<tr><td style="padding-top:6px;font-size:11px;font-weight:700;color:#1E40AF;">Due</td><td style="padding-top:6px;font-size:12.5px;font-weight:700;color:#1E3A8A;text-align:right;">{{due_at}}</td></tr></table>
<div align="center"><a href="https://poe.health.go.ug/alerts/intelligence" style="display:inline-block;padding:11px 24px;background:linear-gradient(135deg,#1E40AF,#3B82F6);color:#FFFFFF;text-decoration:none;border-radius:8px;font-size:12.5px;font-weight:800;">Update status in POE Sentinel →</a></div></td></tr>
<tr><td style="padding:12px 32px;background:#F8FAFC;border-top:1px solid #E8EDF5;"><div style="font-size:10px;color:#64748B;">POE Sentinel · 7-1-7 follow-up engine</div></td></tr></table>
</td></tr></table></body></html>',
'Follow-up due: {{action_label}} ({{alert_code}}) in {{due_in_hours}}h. Due {{due_at}}.',
'["POE","DISTRICT","PHEOC"]', 0, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), is_ai_enhanced=0, updated_at=NOW();

-- ─── 7. FOLLOWUP_OVERDUE ──────────────────────────────────────────────────
INSERT INTO notification_templates (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('FOLLOWUP_OVERDUE', 'EMAIL',
'⏰ OVERDUE · {{action_label}} · {{alert_code}}',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:5px;background:linear-gradient(90deg,#DC2626 0%,#EA580C 100%);"></td></tr>
<tr><td style="padding:26px 32px 14px;background:linear-gradient(135deg,#7F1D1D 0%,#991B1B 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.75);text-transform:uppercase;">Overdue · RTSL Early Response</div>
<div style="font-size:24px;font-weight:900;letter-spacing:-.4px;color:#FFFFFF;margin-top:4px;">⏰ Past due</div>
<div style="font-size:14px;color:#FEE2E2;font-weight:600;margin-top:4px;">{{action_label}}</div></td></tr>
<tr><td style="padding:22px 32px 8px;">
<p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#1F2937;"><em>Outstanding follow-up actions prevent closure of the linked alert and jeopardise the 7-day response target. Update the status now or escalate to a colleague.</em></p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#FEF2F2;border:2px solid #FECACA;border-radius:10px;padding:14px;margin-bottom:16px;">
<tr><td style="font-size:11px;font-weight:700;color:#991B1B;">Action</td><td style="font-size:13px;font-weight:800;color:#7F1D1D;text-align:right;">{{action_label}}</td></tr>
<tr><td style="padding-top:6px;font-size:11px;font-weight:700;color:#991B1B;">Alert</td><td style="padding-top:6px;font-size:12px;font-family:ui-monospace,Menlo,monospace;color:#7F1D1D;text-align:right;">{{alert_code}}</td></tr>
<tr><td style="padding-top:6px;font-size:11px;font-weight:700;color:#991B1B;">Due</td><td style="padding-top:6px;font-size:12.5px;font-weight:700;color:#7F1D1D;text-align:right;">{{due_at}}</td></tr></table>
<div align="center" style="margin-bottom:10px;"><a href="https://poe.health.go.ug/alerts/intelligence" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#991B1B,#DC2626);color:#FFFFFF;text-decoration:none;border-radius:8px;font-size:13px;font-weight:800;box-shadow:0 4px 14px rgba(153,27,27,.4);">Resolve in Intelligence Hub →</a></div></td></tr>
<tr><td style="padding:12px 32px;background:#0F172A;"><div style="font-size:10px;color:rgba(255,255,255,.55);">7-1-7 response target · RTSL 14 early response actions.</div></td></tr></table>
</td></tr></table></body></html>',
'OVERDUE: {{action_label}} on alert {{alert_code}}. Due {{due_at}}. Update status in POE Sentinel.',
'["POE","DISTRICT","PHEOC"]', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), is_ai_enhanced=1, updated_at=NOW();

-- ─── 8. DAILY_REPORT ──────────────────────────────────────────────────────
INSERT INTO notification_templates (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('DAILY_REPORT', 'EMAIL',
'📊 Daily Digest · {{poe_code}} · {{report_date}}',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:5px;background:linear-gradient(90deg,#1E40AF 0%,#3B82F6 50%,#8B5CF6 100%);"></td></tr>
<tr><td style="padding:28px 32px 18px;background:linear-gradient(135deg,#001D3D 0%,#003566 55%,#003F88 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.75);text-transform:uppercase;">Surveillance Digest · {{poe_code}}</div>
<div style="font-size:26px;font-weight:900;letter-spacing:-.5px;color:#FFFFFF;margin-top:6px;">📊 Daily briefing</div>
<div style="font-size:14px;color:#BFDBFE;font-weight:600;margin-top:4px;">{{report_date}}</div></td></tr>
<tr><td style="padding:24px 32px 8px;">
<p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#1F2937;">Automated daily briefing of surveillance activity at <strong>{{poe_code}}</strong> for the 24-hour window ending {{report_date}}. Full dashboards and historical trend are in the POE Sentinel app.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;"><tr>
<td width="33%" style="padding:4px;" align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#EFF6FF;border-radius:10px;padding:14px;border:1px solid #BFDBFE;"><tr><td align="center"><div style="font-size:24px;font-weight:900;color:#1E40AF;line-height:1;">{{open_alerts}}</div><div style="font-size:9.5px;font-weight:800;color:#1E3A8A;margin-top:6px;letter-spacing:.6px;text-transform:uppercase;">Open Alerts</div></td></tr></table></td>
<td width="33%" style="padding:4px;" align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#FEF2F2;border-radius:10px;padding:14px;border:1px solid #FECACA;"><tr><td align="center"><div style="font-size:24px;font-weight:900;color:#991B1B;line-height:1;">{{critical_alerts}}</div><div style="font-size:9.5px;font-weight:800;color:#7F1D1D;margin-top:6px;letter-spacing:.6px;text-transform:uppercase;">Critical</div></td></tr></table></td>
<td width="33%" style="padding:4px;" align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#FEF3C7;border-radius:10px;padding:14px;border:1px solid #FDE68A;"><tr><td align="center"><div style="font-size:24px;font-weight:900;color:#854D0E;line-height:1;">{{breach_alerts}}</div><div style="font-size:9.5px;font-weight:800;color:#7C2D12;margin-top:6px;letter-spacing:.6px;text-transform:uppercase;">7-1-7 Breach</div></td></tr></table></td>
</tr></table>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #E8EDF5;border-radius:10px;overflow:hidden;margin-bottom:20px;">
<tr><td style="padding:14px;background:#F8FAFC;"><div style="font-size:10px;font-weight:800;letter-spacing:1.5px;color:#475569;text-transform:uppercase;">Screening 24h</div></td></tr>
<tr><td style="padding:14px 16px;border-top:1px solid #E8EDF5;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>
<td><div style="font-size:22px;font-weight:900;color:#0F172A;line-height:1;">{{screened_today}}</div><div style="font-size:10px;color:#64748B;margin-top:2px;">total screened</div></td>
<td align="right"><div style="font-size:22px;font-weight:900;color:#B45309;line-height:1;">{{symptomatic_today}}</div><div style="font-size:10px;color:#CA8A04;margin-top:2px;">symptomatic</div></td></tr></table></td></tr></table>
<div style="background:#F8FAFC;border-left:3px solid #1E40AF;padding:12px 14px;margin-bottom:18px;font-size:11.5px;color:#475569;line-height:1.5;"><em>This digest is generated automatically from the national POE surveillance platform. Any numbers above 0 in the Critical or Breach columns warrant immediate operator review.</em></div>
<div align="center"><a href="https://poe.health.go.ug" style="display:inline-block;padding:12px 26px;background:linear-gradient(135deg,#1E40AF,#3B82F6);color:#FFFFFF;text-decoration:none;border-radius:8px;font-size:13px;font-weight:800;">Open POE Sentinel →</a></div></td></tr>
<tr><td style="padding:14px 32px;background:#0F172A;"><div style="font-size:10px;color:rgba(255,255,255,.55);line-height:1.5;">Uganda National POE Screening Tool · Daily digest generated at 07:00 local time.</div></td></tr></table>
</td></tr></table></body></html>',
'Daily digest {{poe_code}} {{report_date}} · Open {{open_alerts}} · Critical {{critical_alerts}} · Breach {{breach_alerts}} · Screened {{screened_today}} · Symptomatic {{symptomatic_today}}.',
'["POE","DISTRICT","PHEOC","NATIONAL"]', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), is_ai_enhanced=1, updated_at=NOW();

-- ─── 9. WEEKLY_REPORT ─────────────────────────────────────────────────────
INSERT INTO notification_templates (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('WEEKLY_REPORT', 'EMAIL',
'📈 Weekly Report · Week {{week_number}} · {{poe_code}}',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:5px;background:linear-gradient(90deg,#059669 0%,#1E40AF 100%);"></td></tr>
<tr><td style="padding:28px 32px 18px;background:linear-gradient(135deg,#064E3B 0%,#047857 55%,#001D3D 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.75);text-transform:uppercase;">Surveillance Summary · Week {{week_number}}</div>
<div style="font-size:26px;font-weight:900;letter-spacing:-.5px;color:#FFFFFF;margin-top:6px;">📈 Weekly report</div>
<div style="font-size:14px;color:#A7F3D0;font-weight:600;margin-top:4px;">{{poe_code}}</div></td></tr>
<tr><td style="padding:24px 32px 8px;">
<p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#1F2937;">ISO week <strong>{{week_number}}</strong> summary for <strong>{{poe_code}}</strong>. Trend analysis, full alert ledger and 7-1-7 compliance are available in-app.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:18px;margin-bottom:20px;text-align:center;">
<tr><td><div style="font-size:36px;font-weight:900;color:#047857;line-height:1;">{{total_alerts}}</div><div style="font-size:11px;font-weight:800;color:#064E3B;margin-top:6px;letter-spacing:.6px;text-transform:uppercase;">Total alerts this week</div></td></tr></table>
<div style="background:#F8FAFC;border-left:3px solid #047857;padding:12px 14px;margin-bottom:18px;font-size:12px;color:#1E293B;line-height:1.6;"><strong>Operational note</strong> — open the in-app Weekly Dashboard for per-alert breakdown, 7-1-7 compliance rates, and district-level disease clusters.</div>
<div align="center"><a href="https://poe.health.go.ug/alerts/intelligence" style="display:inline-block;padding:12px 26px;background:linear-gradient(135deg,#047857,#059669);color:#FFFFFF;text-decoration:none;border-radius:8px;font-size:13px;font-weight:800;">Open Intelligence Hub →</a></div></td></tr>
<tr><td style="padding:14px 32px;background:#0F172A;"><div style="font-size:10px;color:rgba(255,255,255,.55);">WHO IHR-aligned weekly summary · POE Sentinel</div></td></tr></table>
</td></tr></table></body></html>',
'Weekly digest {{poe_code}} week {{week_number}}: {{total_alerts}} alerts.',
'["DISTRICT","PHEOC","NATIONAL"]', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), is_ai_enhanced=1, updated_at=NOW();

-- ─── 10. ESCALATION ───────────────────────────────────────────────────────
INSERT INTO notification_templates (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('ESCALATION', 'EMAIL',
'⬆ ESCALATION · {{alert_code}} → {{escalate_to_level}}',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:4px;background:#CA8A04;"></td></tr>
<tr><td style="padding:28px 32px 18px;background:linear-gradient(135deg,#854D0E 0%,#B45309 55%,#001D3D 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.75);text-transform:uppercase;">Escalation Ladder · IHR 2005 Art. 6</div>
<div style="font-size:24px;font-weight:900;letter-spacing:-.4px;color:#FFFFFF;margin-top:6px;">⬆ Escalated to {{escalate_to_level}}</div>
<div style="font-size:14px;color:#FDE68A;font-family:ui-monospace,Menlo,monospace;font-weight:600;margin-top:4px;">{{alert_code}}</div></td></tr>
<tr><td style="padding:24px 32px 8px;">
<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#1F2937;">Alert <strong style="font-family:ui-monospace,Menlo,monospace;">{{alert_code}}</strong> has been escalated from <strong>{{routed_to_level}}</strong> to <strong>{{escalate_to_level}}</strong>.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 18px;"><tr>
<td style="background:#F8FAFC;border:1px solid #E8EDF5;border-radius:8px;padding:14px;text-align:center;width:40%;"><div style="font-size:9px;font-weight:800;color:#64748B;letter-spacing:.5px;text-transform:uppercase;">From</div><div style="font-size:14px;font-weight:800;color:#0F172A;margin-top:4px;">{{routed_to_level}}</div></td>
<td style="font-size:24px;color:#CA8A04;font-weight:900;text-align:center;">→</td>
<td style="background:linear-gradient(135deg,#CA8A04,#B45309);border-radius:8px;padding:14px;text-align:center;width:40%;"><div style="font-size:9px;font-weight:800;color:rgba(255,255,255,.8);letter-spacing:.5px;text-transform:uppercase;">To</div><div style="font-size:14px;font-weight:800;color:#FFFFFF;margin-top:4px;">{{escalate_to_level}}</div></td>
</tr></table>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:14px;margin-bottom:16px;">
<tr><td style="font-size:10px;font-weight:800;color:#854D0E;letter-spacing:.5px;text-transform:uppercase;margin-bottom:6px;">Reason</td></tr>
<tr><td style="padding-top:6px;font-size:13px;color:#78350F;line-height:1.5;">{{escalation_reason}}</td></tr></table>
<div align="center"><a href="https://poe.health.go.ug/alerts" style="display:inline-block;padding:12px 26px;background:linear-gradient(135deg,#B45309,#CA8A04);color:#FFFFFF;text-decoration:none;border-radius:8px;font-size:13px;font-weight:800;">Review alert →</a></div></td></tr>
<tr><td style="padding:12px 32px;background:#0F172A;"><div style="font-size:10px;color:rgba(255,255,255,.55);">IDSR 3rd Ed. + IHR Art. 6 · Uganda national escalation ladder</div></td></tr></table>
</td></tr></table></body></html>',
'Escalation: {{alert_code}} from {{routed_to_level}} → {{escalate_to_level}}. Reason: {{escalation_reason}}.',
'["PHEOC","NATIONAL","WHO"]', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), is_ai_enhanced=1, updated_at=NOW();

-- ─── 11. PHEIC_ADVISORY ───────────────────────────────────────────────────
INSERT INTO notification_templates (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('PHEIC_ADVISORY', 'EMAIL',
'🌐 PHEIC Advisory · Review required · {{alert_code}}',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:5px;background:linear-gradient(90deg,#0F172A 0%,#9333EA 50%,#DC2626 100%);"></td></tr>
<tr><td style="padding:30px 32px 18px;background:linear-gradient(135deg,#0F172A 0%,#1E1B4B 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2.4px;color:rgba(255,255,255,.75);text-transform:uppercase;">WHO IHR Art. 12 · PHEIC Advisory</div>
<div style="font-size:28px;font-weight:900;letter-spacing:-.5px;color:#FFFFFF;margin-top:8px;line-height:1.1;">🌐 PHEIC indicators met</div>
<div style="font-size:13px;color:rgba(255,255,255,.7);margin-top:6px;font-family:ui-monospace,Menlo,monospace;">{{alert_code}}</div></td></tr>
<tr><td style="padding:24px 32px 8px;">
<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#1F2937;">Preliminary indicators for a <strong>Public Health Emergency of International Concern</strong> have been identified in alert <strong style="font-family:ui-monospace,Menlo,monospace;">{{alert_code}}</strong>. PHEIC declarations are made by the WHO Director-General on advice of an Emergency Committee, per IHR 2005 Article 12.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#FAF5FF;border:1px solid #E9D5FF;border-radius:10px;padding:18px;margin-bottom:20px;">
<tr><td><div style="font-size:10px;font-weight:800;letter-spacing:1.5px;color:#6B21A8;text-transform:uppercase;margin-bottom:10px;">Article 12 Criteria</div>
<div style="font-size:12.5px;color:#4C1D95;line-height:1.9;">1. The event is <strong>extraordinary</strong><br>2. Risk to other States through <strong>international spread</strong><br>3. Potentially requires <strong>coordinated international response</strong></div></td></tr></table>
<div style="background:#FEF2F2;border-left:3px solid #DC2626;padding:12px 14px;margin-bottom:18px;font-size:12px;color:#7F1D1D;line-height:1.6;"><strong>Ensure the National IHR Focal Point has all current information.</strong> Historical PHEICs include H1N1 (2009), Polio (2014), Ebola (2014, 2019), Zika (2016), COVID-19 (2020), Mpox (2022, 2024).</div>
<div align="center"><a href="https://poe.health.go.ug/alerts/intelligence" style="display:inline-block;padding:13px 30px;background:linear-gradient(135deg,#1E1B4B,#6B21A8);color:#FFFFFF;text-decoration:none;border-radius:8px;font-size:13px;font-weight:800;box-shadow:0 4px 14px rgba(30,27,75,.45);">Review alert intelligence →</a></div></td></tr>
<tr><td style="padding:14px 32px;background:#0F172A;border-top:3px solid #9333EA;"><div style="font-size:10px;color:rgba(255,255,255,.55);line-height:1.5;">WHO IHR (2005) Article 12 · Emergency Committee procedures</div></td></tr></table>
</td></tr></table></body></html>',
'PHEIC ADVISORY for alert {{alert_code}}. IHR Art. 12 criteria met. Escalate to WHO IHR Focal Point immediately.',
'["NATIONAL","WHO"]', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), is_ai_enhanced=1, updated_at=NOW();

-- ─── 12. ALERT_CLOSED ─────────────────────────────────────────────────────
INSERT INTO notification_templates (template_code, channel, subject_template, body_html_template, body_text_template, applicable_levels, is_ai_enhanced, is_active, created_at, updated_at)
VALUES ('ALERT_CLOSED', 'EMAIL',
'✅ Closed · {{alert_code}} · {{close_reason_short}}',
'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F0F4FA;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F0F4FA;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px -12px rgba(15,23,42,.18);">
<tr><td style="height:4px;background:#10B981;"></td></tr>
<tr><td style="padding:26px 32px 14px;background:linear-gradient(135deg,#064E3B 0%,#047857 100%);">
<div style="font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.75);text-transform:uppercase;">Resolved</div>
<div style="font-size:22px;font-weight:900;letter-spacing:-.4px;color:#FFFFFF;margin-top:4px;">✅ Alert closed</div>
<div style="font-size:13px;color:#A7F3D0;font-family:ui-monospace,Menlo,monospace;margin-top:4px;">{{alert_code}}</div></td></tr>
<tr><td style="padding:22px 32px 8px;">
<p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#1F2937;">Alert <strong style="font-family:ui-monospace,Menlo,monospace;">{{alert_code}}</strong> has been marked as closed.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #E8EDF5;border-radius:10px;overflow:hidden;margin-bottom:18px;">
<tr><td style="padding:10px 14px;background:#F8FAFC;font-size:11px;font-weight:700;color:#475569;">Closed by</td><td style="padding:10px 14px;background:#F8FAFC;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{closed_by_name}}</td></tr>
<tr><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Closed at</td><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:12.5px;font-weight:700;color:#0F172A;text-align:right;">{{closed_at}}</td></tr>
<tr><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:11px;font-weight:700;color:#475569;">Reason</td><td style="padding:10px 14px;border-top:1px solid #E8EDF5;font-size:12.5px;color:#0F172A;text-align:right;">{{close_reason}}</td></tr></table>
<div style="background:#F0FDF4;border-left:3px solid #10B981;padding:12px 14px;font-size:11.5px;color:#14532D;line-height:1.5;">Record archived for audit. No further action required.</div></td></tr>
<tr><td style="padding:12px 32px;background:#F8FAFC;border-top:1px solid #E8EDF5;"><div style="font-size:10px;color:#64748B;">POE Sentinel · Audit trail retained</div></td></tr></table>
</td></tr></table></body></html>',
'Closed: {{alert_code}} by {{closed_by_name}} at {{closed_at}}. Reason: {{close_reason}}.',
'["POE","DISTRICT","PHEOC","NATIONAL"]', 0, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE subject_template=VALUES(subject_template), body_html_template=VALUES(body_html_template), body_text_template=VALUES(body_text_template), is_ai_enhanced=0, updated_at=NOW();

SELECT template_code, channel, LENGTH(body_html_template) AS html_bytes, is_active FROM notification_templates ORDER BY template_code;
