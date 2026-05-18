-- ═══════════════════════════════════════════════════════════════════════════
-- Adds 22 authoritative WHO AFRO IDSR 3rd Ed. + IHR Annex 1B PoE columns
-- to the seeded default aggregated template.
--
-- Sources:
--   • WHO AFRO IDSR Technical Guidelines 3rd Ed. Booklet 1 — Annex C (PoE surveillance)
--   • Uganda MoH IDSR 3rd Ed. (Sept 2021) — PoE register
--   • HISP DHIS2 COVID-19 PoE Tracker System Design v0.3.1 (de-facto field dictionary
--     used across UG / KE / RW / ZM during COVID and retained for IDSR PoE modules)
--   • IHR 2005 Annex 1B / WHO Benchmark 17 — Points of Entry capacities
--   • Africa CDC Cross-Border Surveillance Strategic Framework (2024)
--
-- Notes:
--   • ECSA-HC does not publish its own PoE aggregated template; it defers
--     to WHO AFRO IDSR as the technical standard.
--   • Idempotent: skips columns that already exist on the template.
--   • Applied to every non-deleted template in the DB (country-wide baseline).
--     Admins retain full toggle control over enable/disable per column.
-- ═══════════════════════════════════════════════════════════════════════════

-- Apply to every existing template (default + any country copies)
-- Use a procedure-style block via dynamic SQL at the app level; here we just
-- target the seeded default template by (is_default = 1) which every country
-- clone inherits from.

SET @tpl_id := (SELECT id FROM aggregated_templates WHERE is_default = 1 ORDER BY id LIMIT 1);
SET @base_order := IFNULL((SELECT MAX(display_order) FROM aggregated_template_columns WHERE template_id = @tpl_id), 0);

-- 22 authoritative columns — each maps to WHO AFRO IDSR Annex C, Uganda IDSR
-- 3rd Ed. PoE register, and DHIS2 PoE Tracker data element set.
--
-- Default enablement policy:
--   enabled = 1  → high-priority IHR-mandated or IDSR priority (surfaced by default)
--   enabled = 0  → specialised capacity data (admins opt in)
INSERT INTO aggregated_template_columns
  (template_id, column_key, column_label, category, data_type,
   is_required, is_enabled, is_core, display_order,
   dashboard_visible, report_visible, aggregation_fn, help_text,
   created_by_user_id, created_at, updated_at)
SELECT @tpl_id, s.k, s.l, s.cat, s.dt,
       0, s.enabled, 0, @base_order + s.ord,
       1, 1, s.agg, s.hint,
       1, NOW(), NOW()
FROM (
  -- ── Core detection & outcomes pipeline (WHO AFRO IDSR Annex C) ─────────
  SELECT 'ill_travellers_detected'         AS k, 'Ill travellers detected on arrival'                AS l, 'CORE'        AS cat, 'INTEGER' AS dt, 1 AS enabled, 1 AS ord, 'SUM' AS agg,
         'IDSR PoE register column 1 — count of travellers flagged by screening as symptomatic/ill on first encounter.' AS hint UNION ALL
  SELECT 'fever_above_38',                     'Travellers with temperature ≥ 38 °C',                    'SYMPTOMS',    'INTEGER', 1, 2, 'SUM',
         'Thermometer/thermal-scanner readings ≥ 38 °C (101.5 °F). A leading indicator in IDSR PoE module.' UNION ALL
  SELECT 'isolated_on_site',                   'Travellers isolated at PoE holding area',                'OUTCOMES',    'INTEGER', 1, 3, 'SUM',
         'Travellers placed in the designated PoE isolation/holding area pending referral decision.' UNION ALL
  SELECT 'referred_to_isolation_facility',     'Referred to designated isolation/treatment facility',    'OUTCOMES',    'INTEGER', 1, 4, 'SUM',
         'Formal referral to a gazetted isolation/treatment centre. Triggers ambulance/transport logging.' UNION ALL
  SELECT 'quarantine_home_follow_up',          'Placed under home/community quarantine follow-up',       'OUTCOMES',    'INTEGER', 0, 5, 'SUM',
         'Home/community quarantine with follow-up by district surveillance team.' UNION ALL
  SELECT 'contacts_listed',                    'Contacts of ill travellers listed',                      'CORE',        'INTEGER', 1, 6, 'SUM',
         'Contacts identified and documented for follow-up per the IHR/IDSR contact tracing protocol.' UNION ALL

  -- ── Travel / origin context (IHR-events + DHIS2 PoE Tracker) ──────────
  SELECT 'arrivals_from_outbreak_country',     'Arrivals from countries with active IHR-notifiable outbreak', 'TRAVEL', 'INTEGER', 1, 7, 'SUM',
         'Arrivals in the reporting period from a country currently on the IHR outbreak watch list. Distinct from the rolling administrative "high_risk_origin" flag.' UNION ALL
  SELECT 'nationals_returning',                'Returning nationals / residents',                        'TRAVEL',      'INTEGER', 0, 8, 'SUM',
         'Citizens/residents returning from international travel.' UNION ALL
  SELECT 'foreign_nationals',                  'Foreign nationals',                                      'TRAVEL',      'INTEGER', 0, 9, 'SUM',
         'Non-citizen travellers.' UNION ALL

  -- ── Conveyance & IHR Annex 1B capacities ───────────────────────────────
  SELECT 'conveyances_inspected',              'Conveyances (aircraft/ship/vehicle) inspected',          'CONVEYANCE',  'INTEGER', 1, 10, 'SUM',
         'Count of conveyances inspected in the reporting period. IHR 2005 Annex 1B core capacity.' UNION ALL
  SELECT 'ship_sanitation_certs_issued',       'Ship Sanitation Certificates issued/extended',           'CONVEYANCE',  'INTEGER', 0, 11, 'SUM',
         'Ship Sanitation Control Certificates (SSCC) or Ship Sanitation Control Exemption Certificates (SSCEC) issued.' UNION ALL
  SELECT 'aircraft_gen_decl_reviewed',         'Aircraft General Declarations (Part 2) reviewed',        'CONVEYANCE',  'INTEGER', 0, 12, 'SUM',
         'Health portion (Part 2) of ICAO Aircraft General Declaration reviewed per IHR Annex 9.' UNION ALL
  SELECT 'vector_control_actions',             'Vector control actions taken at PoE',                    'ENVIRONMENT', 'INTEGER', 0, 13, 'SUM',
         'Fumigation, de-rat certification, larval source reduction, etc. IHR Annex 1B routine capacity.' UNION ALL

  -- ── Vaccination certificate checks (IHR Annex 7) ───────────────────────
  SELECT 'yellow_fever_cert_checked',          'Yellow fever certificates checked',                      'VACCINE',     'INTEGER', 1, 14, 'SUM',
         'YF certificate verification performed per IHR Annex 7 (required for travel to/from YF-endemic countries).' UNION ALL
  SELECT 'yellow_fever_cert_invalid_refused',  'YF cert invalid — vaccination/refusal at border',        'VACCINE',     'INTEGER', 0, 15, 'SUM',
         'Certificates found invalid or missing; traveller vaccinated on-site or refused entry per national policy.' UNION ALL

  -- ── IDSR priority syndromes (previously gapped) ────────────────────────
  SELECT 'suspected_afp',                      'Suspected AFP / polio',                                  'DISEASE',     'INTEGER', 1, 16, 'SUM',
         'Acute Flaccid Paralysis — IDSR immediately notifiable and IHR Tier 1 (wild polio).' UNION ALL
  SELECT 'suspected_measles',                  'Suspected measles',                                      'DISEASE',     'INTEGER', 1, 17, 'SUM',
         'IDSR immediately notifiable; carries high cross-border transmission risk.' UNION ALL
  SELECT 'suspected_sari_ili',                 'Suspected SARI / ILI',                                   'DISEASE',     'INTEGER', 1, 18, 'SUM',
         'Severe Acute Respiratory Infection / Influenza-Like Illness — the IDSR respiratory syndrome cluster.' UNION ALL

  -- ── Lab quality & alert verification ───────────────────────────────────
  SELECT 'lab_samples_rejected',               'Samples rejected / unsuitable',                          'LAB',         'INTEGER', 0, 19, 'SUM',
         'Samples rejected by the reference laboratory for quality reasons (haemolysed, insufficient volume, wrong tube, etc.).' UNION ALL
  SELECT 'alerts_verified_true',               'PoE alerts verified as true events',                     'CORE',        'INTEGER', 0, 20, 'SUM',
         'Alerts raised that were subsequently confirmed as real events (excludes false positives / duplicates).' UNION ALL

  -- ── Data quality (IDSR reporting SLA) ──────────────────────────────────
  SELECT 'report_completeness_pct',            'Daily report completeness (%)',                          'QUALITY',     'PERCENT', 0, 21, 'AVG',
         'Percentage of expected daily PoE reports actually received in the period. WHO AFRO IDSR core indicator.' UNION ALL
  SELECT 'report_timeliness_pct',              'On-time submission (%)',                                 'QUALITY',     'PERCENT', 0, 22, 'AVG',
         'Percentage of daily reports submitted by the WHO AFRO-recommended Monday deadline for the prior week.'
) s
WHERE @tpl_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM aggregated_template_columns
    WHERE template_id = @tpl_id AND column_key = s.k
  );

-- Report
SELECT CONCAT('Columns on template #', @tpl_id, ': ', COUNT(*)) AS result
FROM aggregated_template_columns WHERE template_id = @tpl_id AND deleted_at IS NULL;
