-- ═══════════════════════════════════════════════════════════════════════════
--  08_collaboration_tables.sql
--  Alert collaboration / war-room schema — lets POE → DISTRICT → PHEOC →
--  NATIONAL → WHO stakeholders coordinate response on a single alert.
--
--  Tables created:
--    alert_collaborators   — who is assigned to work on an alert + what role
--    alert_comments        — threaded discussion (parent_id for replies, @mentions)
--    alert_evidence        — file/photo/lab-result attachments (stores ref only)
--    alert_timeline_events — machine + human events in chronological order
--    alert_handoffs        — formal level-to-level transitions + acceptance
--    alert_breach_reports  — 7-1-7 breach root-cause analysis + mitigation
--
--  Idempotent — all CREATE TABLE IF NOT EXISTS. Safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS alert_collaborators (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    alert_id        BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    role            VARCHAR(50) NOT NULL DEFAULT 'OBSERVER',
        -- ROLES: INCIDENT_COMMANDER, CASE_OWNER, CLINICAL_LEAD, LAB_LIAISON,
        --        DISTRICT_LIAISON, PHEOC_LIAISON, NATIONAL_LIAISON, WHO_LIAISON,
        --        CONTACT_TRACER, RISK_COMMS, LOGISTICS, OBSERVER
    level           VARCHAR(30) NULL,                 -- POE / DISTRICT / PHEOC / NATIONAL / WHO
    added_by_user_id BIGINT UNSIGNED NOT NULL,
    notes           VARCHAR(500) NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    removed_at      DATETIME NULL,
    removed_by_user_id BIGINT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_alert_user_active (alert_id, user_id, is_active),
    KEY idx_alert (alert_id),
    KEY idx_user  (user_id),
    CONSTRAINT fk_collab_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
    CONSTRAINT fk_collab_user  FOREIGN KEY (user_id)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_comments (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    alert_id        BIGINT UNSIGNED NOT NULL,
    parent_id       BIGINT UNSIGNED NULL,        -- NULL = root-level thread
    author_user_id  BIGINT UNSIGNED NOT NULL,
    author_role     VARCHAR(60) NULL,
    author_level    VARCHAR(30) NULL,
    body            TEXT NOT NULL,
    body_format     ENUM('MARKDOWN','PLAIN','HTML') NOT NULL DEFAULT 'MARKDOWN',
    mentions_json   JSON NULL,                    -- array of {user_id, name, role}
    visibility      ENUM('ALL','INTERNAL','EXTERNAL') NOT NULL DEFAULT 'ALL',
    is_system       TINYINT(1) NOT NULL DEFAULT 0, -- machine-generated system notes
    is_pinned       TINYINT(1) NOT NULL DEFAULT 0,
    reactions_json  JSON NULL,                    -- {"ack":5,"thumbs_up":2}
    edited_at       DATETIME NULL,
    edited_by_user_id BIGINT UNSIGNED NULL,
    deleted_at      DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_alert (alert_id),
    KEY idx_parent (parent_id),
    KEY idx_author (author_user_id),
    CONSTRAINT fk_comm_alert   FOREIGN KEY (alert_id)  REFERENCES alerts(id) ON DELETE CASCADE,
    CONSTRAINT fk_comm_parent  FOREIGN KEY (parent_id) REFERENCES alert_comments(id) ON DELETE CASCADE,
    CONSTRAINT fk_comm_author  FOREIGN KEY (author_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_evidence (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    alert_id        BIGINT UNSIGNED NOT NULL,
    followup_id     BIGINT UNSIGNED NULL,        -- optional link to a followup
    category        VARCHAR(40) NOT NULL DEFAULT 'DOCUMENT',
        -- CATEGORIES: DOCUMENT, PHOTO, LAB_RESULT, CONSENT, WHO_FORM,
        --             CONTACT_LIST, SOP_SIGN_OFF, PPE_CHECKLIST, OTHER
    title           VARCHAR(200) NOT NULL,
    description     VARCHAR(1000) NULL,
    file_ref        VARCHAR(500) NULL,           -- storage path / object key / URL
    file_mime       VARCHAR(80)  NULL,
    file_size_bytes BIGINT UNSIGNED NULL,
    external_url    VARCHAR(500) NULL,           -- lab portal, dropbox, share link
    uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
    uploader_name   VARCHAR(150) NULL,           -- denormalised for fast listing
    visibility      ENUM('ALL','INTERNAL','EXTERNAL') NOT NULL DEFAULT 'ALL',
    deleted_at      DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_alert (alert_id),
    KEY idx_followup (followup_id),
    CONSTRAINT fk_evd_alert    FOREIGN KEY (alert_id)    REFERENCES alerts(id) ON DELETE CASCADE,
    CONSTRAINT fk_evd_followup FOREIGN KEY (followup_id) REFERENCES alert_followups(id) ON DELETE SET NULL,
    CONSTRAINT fk_evd_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_timeline_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    alert_id        BIGINT UNSIGNED NOT NULL,
    event_code      VARCHAR(60) NOT NULL,
        -- CODES: ALERT_CREATED, ACKNOWLEDGED, COMMENT_POSTED, COLLABORATOR_ADDED,
        --        COLLABORATOR_REMOVED, EVIDENCE_UPLOADED, HANDOFF_SENT, HANDOFF_ACCEPTED,
        --        HANDOFF_REJECTED, ESCALATED, FOLLOWUP_COMPLETED, FOLLOWUP_OVERDUE,
        --        BREACH_717_DETECTED, BREACH_ROOT_CAUSE_LOGGED, EMAIL_SENT,
        --        EMAIL_FAILED, EXTERNAL_INFO_REQUESTED, EXTERNAL_INFO_RESPONDED,
        --        PHEIC_DECLARED, ALERT_REOPENED, ALERT_CLOSED
    event_category  ENUM('SYSTEM','HUMAN','EMAIL','WORKFLOW','BREACH','CLINICAL') NOT NULL DEFAULT 'SYSTEM',
    actor_user_id   BIGINT UNSIGNED NULL,
    actor_name      VARCHAR(160) NULL,
    actor_role      VARCHAR(60) NULL,
    payload_json    JSON NULL,
    summary         VARCHAR(500) NOT NULL,
    severity        ENUM('INFO','WARN','ERROR','CRITICAL') NOT NULL DEFAULT 'INFO',
    related_entity_type VARCHAR(40) NULL,   -- COMMENT, EVIDENCE, FOLLOWUP, HANDOFF, EMAIL_LOG
    related_entity_id   BIGINT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_alert_time (alert_id, created_at),
    KEY idx_event_code (event_code),
    CONSTRAINT fk_tl_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_handoffs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    alert_id        BIGINT UNSIGNED NOT NULL,
    from_level      VARCHAR(30) NOT NULL,
    to_level        VARCHAR(30) NOT NULL,
    from_user_id    BIGINT UNSIGNED NOT NULL,
    to_user_id      BIGINT UNSIGNED NULL,         -- optional direct assignee
    reason          VARCHAR(500) NOT NULL,
    handoff_notes   TEXT NULL,
    status          ENUM('SENT','ACKNOWLEDGED','ACCEPTED','REJECTED','RECALLED') NOT NULL DEFAULT 'SENT',
    decided_at      DATETIME NULL,
    decided_by_user_id BIGINT UNSIGNED NULL,
    decision_notes  VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_alert (alert_id),
    KEY idx_status (status),
    CONSTRAINT fk_ho_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
    CONSTRAINT fk_ho_from  FOREIGN KEY (from_user_id) REFERENCES users(id),
    CONSTRAINT fk_ho_to    FOREIGN KEY (to_user_id)   REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_breach_reports (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    alert_id        BIGINT UNSIGNED NOT NULL,
    phase           ENUM('DETECT','NOTIFY','RESPOND') NOT NULL,
    target_hours    INT UNSIGNED NOT NULL,
    elapsed_hours   DECIMAL(10,2) NOT NULL,
    breach_minutes  INT UNSIGNED NOT NULL,
    root_cause_category VARCHAR(60) NOT NULL,
        -- CATEGORIES: STAFFING, SUPPLIES_PPE, LAB_TURNAROUND, TRANSPORT,
        --             COMMUNICATION, TOOLING, PROCESS_GAP, SURGE_VOLUME,
        --             POLICY_AMBIGUITY, EXTERNAL_PARTNER, OTHER
    root_cause_text     TEXT NOT NULL,
    contributing_factors JSON NULL,
    mitigation_plan     TEXT NOT NULL,
    owner_user_id       BIGINT UNSIGNED NOT NULL,
    owner_level         VARCHAR(30) NOT NULL,
    target_resolve_at   DATETIME NULL,
    resolved_at         DATETIME NULL,
    resolution_notes    TEXT NULL,
    status              ENUM('OPEN','IN_PROGRESS','RESOLVED','CANCELLED') NOT NULL DEFAULT 'OPEN',
    reported_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_alert_phase (alert_id, phase),
    KEY idx_status (status),
    CONSTRAINT fk_br_alert    FOREIGN KEY (alert_id)           REFERENCES alerts(id) ON DELETE CASCADE,
    CONSTRAINT fk_br_owner    FOREIGN KEY (owner_user_id)      REFERENCES users(id),
    CONSTRAINT fk_br_reporter FOREIGN KEY (reported_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Convenience columns on alerts (for current owner + PHEIC flag).
-- MySQL 8.0 has no "ADD COLUMN IF NOT EXISTS" so we use a guarded proc.
DROP PROCEDURE IF EXISTS _poe_add_col;
DELIMITER //
CREATE PROCEDURE _poe_add_col(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_def TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name   = p_table
          AND column_name  = p_col
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_def);
        PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END //
DELIMITER ;

CALL _poe_add_col('alerts', 'current_owner_user_id',    '`current_owner_user_id` BIGINT UNSIGNED NULL AFTER `acknowledged_by_user_id`');
CALL _poe_add_col('alerts', 'current_owner_level',      '`current_owner_level` VARCHAR(30) NULL AFTER `current_owner_user_id`');
CALL _poe_add_col('alerts', 'pheic_declared_at',        '`pheic_declared_at` DATETIME NULL AFTER `closed_at`');
CALL _poe_add_col('alerts', 'pheic_declared_by_user_id','`pheic_declared_by_user_id` BIGINT UNSIGNED NULL AFTER `pheic_declared_at`');

DROP PROCEDURE IF EXISTS _poe_add_col;
