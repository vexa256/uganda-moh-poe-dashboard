-- ═══════════════════════════════════════════════════════════════════════════
-- AGGREGATED SUBMISSIONS ↔ TEMPLATE LINK (2026-04-21 v3)
-- ═══════════════════════════════════════════════════════════════════════════
-- Adds template_id / template_code / template_version to aggregated_submissions
-- so every submission carries a durable pointer back to the template that
-- produced it. Required for:
--   • the multi-published templates era (submissions must survive template
--     deletion while preserving audit context)
--   • dashboards that roll up per-template counts
--   • the force-delete flow (AggregatedTemplatesController::destroy counts
--     submissions per template_id before allowing cascade delete)
--
-- Idempotent: uses INFORMATION_SCHEMA guard. Safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════

SET @tbl := 'aggregated_submissions';

SET @exists := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name=@tbl AND column_name='template_id');
SET @sql := IF(@exists = 0,
  "ALTER TABLE aggregated_submissions
     ADD COLUMN template_id BIGINT UNSIGNED NULL AFTER notes,
     ADD COLUMN template_code VARCHAR(60) NULL AFTER template_id,
     ADD COLUMN template_version INT UNSIGNED NULL AFTER template_code,
     ADD INDEX agg_sub_template_id_idx (template_id),
     ADD INDEX agg_sub_template_code_idx (template_code)",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- One-time wipe: zero out historical OTHER/UNKNOWN gender columns (retired).
UPDATE aggregated_submissions SET total_other = 0, total_unknown_gender = 0
  WHERE (total_other > 0 OR total_unknown_gender > 0) AND deleted_at IS NULL;

-- Retire OTHER/UNKNOWN gender columns on every template (idempotent).
UPDATE aggregated_template_columns SET deleted_at = COALESCE(deleted_at, NOW())
  WHERE column_key IN ('total_other', 'total_unknown_gender') AND deleted_at IS NULL;

SELECT 'template_id on aggregated_submissions' AS ok,
       IF(COUNT(*)=1, 'yes', 'MISSING') AS status
FROM information_schema.columns
WHERE table_schema=DATABASE() AND table_name='aggregated_submissions' AND column_name='template_id';
