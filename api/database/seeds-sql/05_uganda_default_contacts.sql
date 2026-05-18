-- ═══════════════════════════════════════════════════════════════════════════
-- UGANDA NATIONAL NOTIFICATION ROSTER — 2026-04-21 v4
-- ═══════════════════════════════════════════════════════════════════════════
-- Seeds the 19 default recipients for every IHR alert / 7-1-7 breach / PHEIC
-- advisory / daily digest in Uganda. Per the Tesla-grade design:
--
--   • All 19 are NATIONAL level — any POE in Uganda, any district, any PHEOC.
--   • priority_order=1 is the primary TO: (Ayebare Timothy). All others are
--     CCs (priority_order=2+).
--   • Full subscription flags — these people receive EVERYTHING: critical,
--     high, Tier 1/2, 7-1-7 breaches, follow-up reminders, daily + weekly.
--   • Created via user_id=1 (system). Soft-delete preserves audit history.
--
-- Idempotent: ON DUPLICATE KEY does not apply (no unique index on email),
-- so we guard with NOT EXISTS to prevent duplicate seeds on re-run.
-- ═══════════════════════════════════════════════════════════════════════════

SET @country := 'UG';
SET @district := 'Lamwo District';
SET @poe := 'Ngoromoro';
SET @pheoc := 'Gulu RPHEOC';
SET @creator := (SELECT id FROM users WHERE role_key = 'NATIONAL_ADMIN' ORDER BY id LIMIT 1);
SET @creator := COALESCE(@creator, 1);

-- Primary (priority 1) — Ayebare Timothy Kamukama
INSERT INTO poe_notification_contacts
  (country_code, district_code, poe_code, level, full_name, position, organisation,
   email, phone, priority_order, preferred_channel, is_active,
   receives_critical, receives_high, receives_medium, receives_low,
   receives_tier1, receives_tier2, receives_breach_alerts,
   receives_followup_reminders, receives_daily_report, receives_weekly_report,
   notes, created_by_user_id, created_at, updated_at)
SELECT @country, @district, @poe, 'NATIONAL',
  'Ayebare K. Timothy', 'National Surveillance Lead', 'Uganda MoH · POE Sentinel',
  'ayebare.k.timothy@gmail.com', NULL, 1, 'EMAIL', 1,
  1, 1, 1, 0, 1, 1, 1, 1, 1, 1,
  'Primary TO: for all automated alerts (Uganda national roster).', @creator, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM poe_notification_contacts WHERE email='ayebare.k.timothy@gmail.com' AND deleted_at IS NULL);

-- CC recipients (priority 2..19)
-- Each gets the SAME receives_* profile — full national roster.
INSERT INTO poe_notification_contacts
  (country_code, district_code, poe_code, level, full_name, position, organisation,
   email, priority_order, preferred_channel, is_active,
   receives_critical, receives_high, receives_medium, receives_low,
   receives_tier1, receives_tier2, receives_breach_alerts,
   receives_followup_reminders, receives_daily_report, receives_weekly_report,
   notes, created_by_user_id, created_at, updated_at)
SELECT @country, @district, @poe, 'NATIONAL', s.full_name, s.position, s.organisation,
  s.email, s.priority_order, 'EMAIL', 1,
  1, 1, 0, 0, 1, 1, 1, 1, 1, 1,
  'Uganda national roster (CC).', @creator, NOW(), NOW()
FROM (
  SELECT 'Ayebare Timothy Kamukama'  AS full_name, 'Developer / Technical Lead'       AS position, 'Uganda MoH · POE Sentinel' AS organisation, 'ayebaretimothykamukama@gmail.com' AS email,  2 AS priority_order UNION ALL
  SELECT 'B. Kisunzu',                'Surveillance Officer',                          'Uganda MoH',                'bkisunzu@gmail.com',                 3 UNION ALL
  SELECT 'G. Silverbert',             'Regional Coordinator',                          'Uganda MoH',                'gsilverbert@yahoo.com',              4 UNION ALL
  SELECT 'Judith Namugga',            'District Health Officer',                       'Uganda MoH',                'judithnamugga6@gmail.com',           5 UNION ALL
  SELECT 'Edsell Muhindo',            'POE Coordinator',                               'Uganda MoH',                'edsellmuhindo@gmail.com',            6 UNION ALL
  SELECT 'Job (Uganda)',              'Surveillance Officer',                          'Uganda MoH',                'jobexug@gmail.com',                  7 UNION ALL
  SELECT 'Rutayisire Wenyine',        'Cross-Border Surveillance',                     'Uganda MoH',                'rutayisirewenyine@gmail.com',        8 UNION ALL
  SELECT 'M. Clave',                  'PHEOC Officer',                                 'Uganda MoH · PHEOC',        'mclave09@gmail.com',                 9 UNION ALL
  SELECT 'Asimi Rem',                 'Epidemiologist',                                'Uganda MoH',                'asimirem@gmail.com',                10 UNION ALL
  SELECT 'H. Mayinja',                'Data Officer',                                  'Uganda MoH',                'hmayinjamjk@gmail.com',             11 UNION ALL
  SELECT 'Evarist Ayebazibwe',        'Regional Health Officer',                       'Uganda MoH',                'evaristayebazibwe@gmail.com',       12 UNION ALL
  SELECT 'Othniel Olak',              'Surveillance Analyst',                          'Uganda MoH',                'othnielolak@gmail.com',             13 UNION ALL
  SELECT 'Nalabuka Hellen',           'Field Officer',                                 'Uganda MoH',                'nalabukahellen@gmail.com',          14 UNION ALL
  SELECT 'Michael Turyasingura',      'Program Officer',                               'Uganda MoH',                'michael.turyasingura@gmail.com',    15 UNION ALL
  SELECT 'M. Atwire',                 'Clinical Officer',                              'Uganda MoH',                'matwire@gmail.com',                 16 UNION ALL
  SELECT 'JB Kibanga',                'Regional Coordinator',                          'Uganda MoH',                'jbkibanga@gmail.com',               17 UNION ALL
  SELECT 'Moses Ebong',               'Health Officer',                                'Uganda MoH',                'mosesebong@gmail.com',              18 UNION ALL
  SELECT 'S. Sulaiman',               'HISP Uganda Technical Lead',                    'HISP Uganda',               'ssulaiman@hispuganda.org',          19
) s
WHERE NOT EXISTS (SELECT 1 FROM poe_notification_contacts WHERE email = s.email AND deleted_at IS NULL);

SELECT priority_order, full_name, email, level FROM poe_notification_contacts
WHERE country_code = 'UG' AND deleted_at IS NULL
ORDER BY priority_order, id;
