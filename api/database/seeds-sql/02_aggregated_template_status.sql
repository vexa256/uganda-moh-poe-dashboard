-- ═══════════════════════════════════════════════════════════════════════════
-- AGGREGATED TEMPLATE — STATUS + REPORTING METADATA (2026-04-21 v2)
-- ═══════════════════════════════════════════════════════════════════════════
-- Promotes aggregated_templates from "one active per country" to "many
-- published per country". Each PUBLISHED template is a separate report type
-- that POE users can select & submit against.
--
-- New columns on aggregated_templates:
--   status                      DRAFT | PUBLISHED | RETIRED | ARCHIVED
--   reporting_frequency         DAILY | WEEKLY | MONTHLY | QUARTERLY | AD_HOC | EVENT
--   published_at                when status moved to PUBLISHED
--   published_by_user_id        who published it
--   retired_at                  when status moved to RETIRED
--   icon                        material-icon name for UI surfacing
--   colour                      hex colour for UI card accent
--
-- Idempotent: uses INFORMATION_SCHEMA guard so it's safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════

SET @db := DATABASE();
SET @tbl := 'aggregated_templates';

-- status enum
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=@db AND table_name=@tbl AND column_name='status');
SET @sql := IF(@col_exists = 0,
  "ALTER TABLE aggregated_templates ADD COLUMN `status` ENUM('DRAFT','PUBLISHED','RETIRED','ARCHIVED') NOT NULL DEFAULT 'DRAFT' AFTER `is_default`",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Index on status
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema=@db AND table_name=@tbl AND index_name='aggregated_templates_status_idx');
SET @sql := IF(@idx_exists = 0,
  "ALTER TABLE aggregated_templates ADD INDEX aggregated_templates_status_idx (status, country_code)",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- reporting_frequency
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=@db AND table_name=@tbl AND column_name='reporting_frequency');
SET @sql := IF(@col_exists = 0,
  "ALTER TABLE aggregated_templates ADD COLUMN `reporting_frequency` ENUM('DAILY','WEEKLY','MONTHLY','QUARTERLY','AD_HOC','EVENT') NOT NULL DEFAULT 'WEEKLY' AFTER `status`",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- published_at / published_by_user_id / retired_at
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=@db AND table_name=@tbl AND column_name='published_at');
SET @sql := IF(@col_exists = 0,
  "ALTER TABLE aggregated_templates ADD COLUMN `published_at` DATETIME NULL AFTER `reporting_frequency`, ADD COLUMN `published_by_user_id` BIGINT UNSIGNED NULL AFTER `published_at`, ADD COLUMN `retired_at` DATETIME NULL AFTER `published_by_user_id`, ADD COLUMN `retired_by_user_id` BIGINT UNSIGNED NULL AFTER `retired_at`",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- icon / colour
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=@db AND table_name=@tbl AND column_name='icon');
SET @sql := IF(@col_exists = 0,
  "ALTER TABLE aggregated_templates ADD COLUMN `icon` VARCHAR(40) NULL AFTER `retired_by_user_id`, ADD COLUMN `colour` VARCHAR(16) NULL AFTER `icon`",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill: existing is_active=1 rows → PUBLISHED
UPDATE aggregated_templates
SET status = 'PUBLISHED',
    published_at = IFNULL(published_at, created_at),
    published_by_user_id = IFNULL(published_by_user_id, created_by_user_id)
WHERE is_active = 1 AND status = 'DRAFT';

-- Seed a second presentation-friendly example template: "Daily POE Screening Tally"
-- Only if default exists and this one doesn't. Demonstrates multi-published.
SET @default_id := (SELECT id FROM aggregated_templates WHERE is_default = 1 LIMIT 1);
SET @daily_exists := (SELECT COUNT(*) FROM aggregated_templates WHERE template_code = 'DAILY_POE_TALLY_V1');

INSERT INTO aggregated_templates
  (country_code, template_name, template_code, description, version,
   is_active, is_default, locked, status, reporting_frequency,
   published_at, published_by_user_id, icon, colour,
   metadata, created_by_user_id, created_at, updated_at)
SELECT 'UG', 'Daily POE Screening Tally', 'DAILY_POE_TALLY_V1',
       'Lightweight end-of-day count from each POE. Gender, symptom, referral totals only.',
       1, 1, 0, 0, 'PUBLISHED', 'DAILY',
       NOW(), 1, 'stats-chart-outline', '#1E40AF',
       JSON_OBJECT('seeded', true, 'preset', 'DAILY_TALLY'), 1, NOW(), NOW()
WHERE @default_id IS NOT NULL AND @daily_exists = 0;

-- Add the 7 core fixed columns to the daily tally template, plus referrals/alerts
SET @daily_id := (SELECT id FROM aggregated_templates WHERE template_code = 'DAILY_POE_TALLY_V1' LIMIT 1);

INSERT INTO aggregated_template_columns
  (template_id, column_key, column_label, category, data_type,
   is_required, is_enabled, is_core, display_order,
   dashboard_visible, report_visible, aggregation_fn,
   created_by_user_id, created_at, updated_at)
SELECT @daily_id, s.k, s.l, s.cat, s.dt, s.req, 1, s.core, s.ord, 1, 1, s.agg, 1, NOW(), NOW()
FROM (
  SELECT 'total_screened' AS k, 'Total screened today' AS l, 'CORE' AS cat, 'INTEGER' AS dt, 1 AS req, 1 AS core, 0 AS ord, 'SUM' AS agg UNION ALL
  SELECT 'total_male',            'Male',                'GENDER',   'INTEGER', 1, 1, 1, 'SUM' UNION ALL
  SELECT 'total_female',          'Female',              'GENDER',   'INTEGER', 1, 1, 2, 'SUM' UNION ALL
  -- 'total_other' + 'total_unknown_gender' retired 2026-04-21.
  SELECT 'total_symptomatic',     'Symptomatic',         'SYMPTOMS', 'INTEGER', 1, 1, 3, 'SUM' UNION ALL
  SELECT 'total_asymptomatic',    'Asymptomatic',        'SYMPTOMS', 'INTEGER', 1, 1, 4, 'SUM' UNION ALL
  SELECT 'referrals_made',        'Referrals to secondary', 'CORE', 'INTEGER', 0, 0, 5, 'SUM' UNION ALL
  SELECT 'alerts_raised',         'Alerts raised',       'CORE',     'INTEGER', 0, 0, 6, 'SUM'
) s
WHERE @daily_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM aggregated_template_columns
    WHERE template_id = @daily_id AND column_key = s.k
  );

-- Final sanity check
SELECT id, template_code, status, reporting_frequency, is_default, country_code
FROM aggregated_templates
WHERE deleted_at IS NULL
ORDER BY id;
