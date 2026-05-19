-- ═══════════════════════════════════════════════════════════════════════════
-- UGANDA NATIONAL ROSTER REVISION — 2026-05-19
-- ═══════════════════════════════════════════════════════════════════════════
-- Executive directive: rebalance the NATIONAL CC roster.
--
--   ADD (4 new NATIONAL contacts — CC on every transactional email):
--     • Dr. Allan Muruta         allanmuruta@yahoo.com
--     • Dr. Mwanga Michael       mwangamike@yahoo.com
--     • Dr. Sunday Haggai        sundaykithula@yahoo.com
--     • Joshua Kayiwa            joshuakayiwa@gmail.com
--
--   REMOVE (soft-delete — preserve audit history):
--     • Philip Waiswa            philipwaiswa@gmail.com
--     • Harriet Mayinja          hmayinja@gmail.com
--     • John Lule                jlule@musph.ac.ug
--     • Geofrey Baluku Kisunzu   gbaluku@baylor-uganda.org
--
-- Unchanged: Moses Ebong (TO), Moureen Asimire, Henry Mugumya, Edsell Muhindo,
-- Mackline Victorious, Vexa256 Operations.
--
-- All NATIONAL contacts have every receives_* flag = 1 (full subscription).
-- Idempotent on re-run via NOT EXISTS guards + deleted_at IS NULL checks.
-- The 8 explicit national mailboxes are ALSO hardcoded in
-- App\Mail\SentinelMail::OPS_CC as a safety net so delivery cannot be silently
-- broken by accidental edits to this table.
-- ═══════════════════════════════════════════════════════════════════════════

SET @country := 'UG';
SET @district := 'Lamwo District';
SET @poe := 'Ngoromoro';
SET @creator := (SELECT id FROM users WHERE role_key = 'NATIONAL_ADMIN' ORDER BY id LIMIT 1);
SET @creator := COALESCE(@creator, 1);

-- ── Soft-delete the 4 retired NATIONAL contacts ────────────────────────────
UPDATE poe_notification_contacts
SET deleted_at = NOW(),
    is_active  = 0,
    updated_at = NOW(),
    notes      = CONCAT(
        COALESCE(notes, ''),
        ' [retired 2026-05-19: executive roster revision]'
    )
WHERE country_code = @country
  AND level = 'NATIONAL'
  AND deleted_at IS NULL
  AND email IN (
    'philipwaiswa@gmail.com',
    'hmayinja@gmail.com',
    'jlule@musph.ac.ug',
    'gbaluku@baylor-uganda.org'
  );

-- ── Insert the 4 new NATIONAL contacts (idempotent) ────────────────────────
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
  'Uganda national roster (CC). Added 2026-05-19.', @creator, NOW(), NOW()
FROM (
  SELECT 'Dr. Allan Muruta'    AS full_name, 'National Surveillance' AS position, 'Uganda MoH' AS organisation, 'allanmuruta@yahoo.com'   AS email, 20 AS priority_order UNION ALL
  SELECT 'Dr. Mwanga Michael',                  'National Officer',     'Uganda MoH',           'mwangamike@yahoo.com',            21 UNION ALL
  SELECT 'Dr. Sunday Haggai',                   'National Officer',     'Uganda MoH',           'sundaykithula@yahoo.com',         22 UNION ALL
  SELECT 'Joshua Kayiwa',                       'National Officer',     'Uganda MoH',           'joshuakayiwa@gmail.com',          23
) s
WHERE NOT EXISTS (
  SELECT 1 FROM poe_notification_contacts
  WHERE email = s.email AND deleted_at IS NULL
);

-- ── Verification: list the live NATIONAL roster post-change ───────────────
SELECT priority_order, full_name, email, level, is_active
FROM poe_notification_contacts
WHERE country_code = @country AND level = 'NATIONAL' AND deleted_at IS NULL
ORDER BY priority_order, id;
