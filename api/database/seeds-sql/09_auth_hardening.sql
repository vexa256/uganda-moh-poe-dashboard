-- ═══════════════════════════════════════════════════════════════════════════
--  09_auth_hardening.sql  —  OWASP-aligned auth schema for the master dashboard
--
--  Applies to the `users` table (adds columns) + creates 6 support tables:
--    auth_events             one row per auth-relevant action (login, 2FA, lock)
--    email_verifications     signed-token table for email verify / reset / invite
--    trusted_devices         device-bound tokens for "passkey-like" step-up
--    user_audit_log          before/after JSON diff of admin mutations
--    user_anomaly_flags      live flags raised by UserAnomalyService
--    webauthn_credentials    enrolled security keys / platform authenticators
--
--  Idempotent — safe to re-run (uses CREATE TABLE IF NOT EXISTS + guarded ALTER).
-- ═══════════════════════════════════════════════════════════════════════════

DROP PROCEDURE IF EXISTS _poe_add_col;
DELIMITER //
CREATE PROCEDURE _poe_add_col(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_def TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_col
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_def);
        PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END //
DELIMITER ;

-- ── users · hardening columns ──────────────────────────────────────────────
CALL _poe_add_col('users','two_factor_secret',                 '`two_factor_secret` VARCHAR(128) NULL');
CALL _poe_add_col('users','two_factor_recovery_codes_hash',    '`two_factor_recovery_codes_hash` TEXT NULL');
CALL _poe_add_col('users','two_factor_confirmed_at',           '`two_factor_confirmed_at` DATETIME NULL');
CALL _poe_add_col('users','failed_login_count',                '`failed_login_count` INT UNSIGNED NOT NULL DEFAULT 0');
CALL _poe_add_col('users','last_failed_login_at',              '`last_failed_login_at` DATETIME NULL');
CALL _poe_add_col('users','locked_until',                      '`locked_until` DATETIME NULL');
CALL _poe_add_col('users','last_login_ip',                     '`last_login_ip` VARCHAR(45) NULL');
CALL _poe_add_col('users','last_login_ua',                     '`last_login_ua` VARCHAR(300) NULL');
CALL _poe_add_col('users','last_activity_at',                  '`last_activity_at` DATETIME NULL');
CALL _poe_add_col('users','password_changed_at',               '`password_changed_at` DATETIME NULL');
CALL _poe_add_col('users','must_change_password',              '`must_change_password` TINYINT(1) NOT NULL DEFAULT 0');
CALL _poe_add_col('users','risk_score',                        '`risk_score` INT NOT NULL DEFAULT 0');
CALL _poe_add_col('users','risk_score_updated_at',             '`risk_score_updated_at` DATETIME NULL');
CALL _poe_add_col('users','risk_flags_json',                   '`risk_flags_json` JSON NULL');
CALL _poe_add_col('users','locale',                            '`locale` VARCHAR(10) NOT NULL DEFAULT \'en\'');
CALL _poe_add_col('users','timezone',                          '`timezone` VARCHAR(64) NULL');
CALL _poe_add_col('users','invitation_token_hash',             '`invitation_token_hash` CHAR(64) NULL');
CALL _poe_add_col('users','invitation_expires_at',             '`invitation_expires_at` DATETIME NULL');
CALL _poe_add_col('users','invitation_accepted_at',            '`invitation_accepted_at` DATETIME NULL');
CALL _poe_add_col('users','suspended_at',                      '`suspended_at` DATETIME NULL');
CALL _poe_add_col('users','suspension_reason',                 '`suspension_reason` VARCHAR(500) NULL');
CALL _poe_add_col('users','created_by_user_id',                '`created_by_user_id` BIGINT UNSIGNED NULL');
CALL _poe_add_col('users','phone_verified_at',                 '`phone_verified_at` DATETIME NULL');
CALL _poe_add_col('users','avatar_url',                        '`avatar_url` VARCHAR(500) NULL');
CALL _poe_add_col('users','account_type',                      "`account_type` ENUM('NATIONAL_ADMIN','PHEOC_ADMIN','DISTRICT_ADMIN','POE_ADMIN','POE_OFFICER','OBSERVER','SERVICE') NOT NULL DEFAULT 'POE_OFFICER'");

DROP PROCEDURE IF EXISTS _poe_add_col;

-- ── auth_events — append-only audit trail for anything auth-related ───────
CREATE TABLE IF NOT EXISTS auth_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NULL,
    email_attempted VARCHAR(190) NULL,              -- for failed logins where user is unknown
    event_type      VARCHAR(60)  NOT NULL,
      -- LOGIN_OK · LOGIN_FAIL · LOGOUT · LOCKED · UNLOCKED
      -- PASSWORD_CHANGED · PASSWORD_RESET_REQUESTED · PASSWORD_RESET_USED
      -- EMAIL_VERIFY_SENT · EMAIL_VERIFIED · EMAIL_CHANGE_REQUESTED · EMAIL_CHANGED
      -- TWOFA_ENABLED · TWOFA_DISABLED · TWOFA_CHALLENGED · TWOFA_OK · TWOFA_FAIL
      -- TRUSTED_DEVICE_ADDED · TRUSTED_DEVICE_REMOVED · TRUSTED_DEVICE_USED
      -- WEBAUTHN_REGISTERED · WEBAUTHN_REMOVED · WEBAUTHN_USED
      -- ADMIN_CREATED · ADMIN_UPDATED · ADMIN_SUSPENDED · ADMIN_REACTIVATED
      -- ROLE_CHANGED · ASSIGNMENT_CHANGED
      -- INVITATION_SENT · INVITATION_ACCEPTED · INVITATION_EXPIRED
      -- SESSION_REVOKED · TOKEN_REVOKED
      -- LOGIN_RISK_HIGH · ANOMALY_FLAGGED
    severity        ENUM('INFO','WARN','ERROR','CRITICAL') NOT NULL DEFAULT 'INFO',
    ip              VARCHAR(45)  NULL,
    user_agent      VARCHAR(300) NULL,
    city            VARCHAR(100) NULL,
    country         VARCHAR(100) NULL,
    payload_json    JSON NULL,
    risk_delta      INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_time (user_id, created_at),
    KEY idx_event     (event_type),
    KEY idx_email_time(email_attempted, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── email_verifications — signed-token table for verify / reset / invite ──
CREATE TABLE IF NOT EXISTS email_verifications (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    purpose       ENUM('VERIFY_EMAIL','RESET_PASSWORD','INVITATION','CHANGE_EMAIL') NOT NULL,
    token_hash    CHAR(64)    NOT NULL,            -- sha256(token); raw token only exists in the email
    email         VARCHAR(190) NOT NULL,
    payload_json  JSON NULL,                       -- e.g. new email for CHANGE_EMAIL
    ip            VARCHAR(45)  NULL,
    user_agent    VARCHAR(300) NULL,
    expires_at    DATETIME    NOT NULL,
    used_at       DATETIME    NULL,
    created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_token_hash (token_hash),
    KEY idx_user_purpose (user_id, purpose),
    CONSTRAINT fk_ev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── trusted_devices — "passkey-like" device-bound second factor ───────────
CREATE TABLE IF NOT EXISTS trusted_devices (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    device_token_hash   CHAR(64)     NOT NULL,     -- sha256 of the opaque device token
    device_fingerprint  VARCHAR(128) NULL,         -- client-supplied stable hash
    label               VARCHAR(120) NULL,         -- "Timothy's MacBook"
    user_agent          VARCHAR(300) NULL,
    ip_first            VARCHAR(45)  NULL,
    ip_last             VARCHAR(45)  NULL,
    last_used_at        DATETIME     NULL,
    expires_at          DATETIME     NOT NULL,
    revoked_at          DATETIME     NULL,
    revoked_reason      VARCHAR(200) NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_token_hash (device_token_hash),
    KEY idx_user (user_id),
    CONSTRAINT fk_td_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── user_audit_log — admin-initiated mutations with before/after diff ─────
CREATE TABLE IF NOT EXISTS user_audit_log (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id  BIGINT UNSIGNED NOT NULL,
    target_user_id BIGINT UNSIGNED NULL,
    action         VARCHAR(60) NOT NULL,
      -- CREATE · UPDATE · SUSPEND · REACTIVATE · DELETE · RESET_PASSWORD
      -- CHANGE_ROLE · ASSIGN · UNASSIGN · INVITE · CLEAR_FLAG · IMPERSONATE_START
    before_json    JSON NULL,
    after_json     JSON NULL,
    ip             VARCHAR(45)  NULL,
    user_agent     VARCHAR(300) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_actor (actor_user_id, created_at),
    KEY idx_target(target_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── user_anomaly_flags — live flags raised by UserAnomalyService ──────────
CREATE TABLE IF NOT EXISTS user_anomaly_flags (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    flag_code     VARCHAR(60) NOT NULL,
      -- DORMANT · PASSWORD_STALE · FREQUENT_FAILED_LOGINS · IMPOSSIBLE_TRAVEL
      -- MULTIPLE_IPS_24H · MULTIPLE_DEVICES_24H · ROLE_ACTIVITY_MISMATCH
      -- UNUSUAL_HOURS · GEO_DEVIATION · NO_MFA_FOR_ADMIN · WEAK_PASSWORD
      -- INVITATION_OLD · ACCOUNT_NEVER_USED · SHARED_CREDENTIALS_LIKELY
    severity      ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'LOW',
    evidence_json JSON NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cleared_at    DATETIME NULL,
    cleared_by_user_id BIGINT UNSIGNED NULL,
    clearance_note VARCHAR(300) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_user_flag (user_id, flag_code),
    KEY idx_severity (severity, cleared_at),
    CONSTRAINT fk_af_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── webauthn_credentials — W3C WebAuthn / passkey registry ────────────────
CREATE TABLE IF NOT EXISTS webauthn_credentials (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    credential_id   VARCHAR(512) NOT NULL,        -- base64url
    public_key      TEXT NULL,                    -- COSE public key (base64)
    sign_count      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    transports      VARCHAR(200) NULL,
    attestation_fmt VARCHAR(50)  NULL,
    aaguid          CHAR(36)     NULL,
    device_type     VARCHAR(40)  NULL,            -- platform / cross-platform
    label           VARCHAR(120) NULL,            -- "Timothy's YubiKey"
    last_used_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at      DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cred_id (credential_id(191)),
    KEY idx_user (user_id),
    CONSTRAINT fk_wa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── role_registry — canonical role_key dictionary (used by the PWA) ───────
CREATE TABLE IF NOT EXISTS role_registry (
    role_key      VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
    display_name  VARCHAR(100) NOT NULL,
    scope_level   ENUM('NATIONAL','PHEOC','DISTRICT','POE','SELF') NOT NULL,
    description   VARCHAR(500) NULL,
    permissions_json JSON NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_registry (role_key, display_name, scope_level, description) VALUES
 ('NATIONAL_ADMIN', 'National Administrator', 'NATIONAL', 'Country-wide read/write, user management, template admin.'),
 ('PHEOC_OFFICER',  'PHEOC Officer',          'PHEOC',    'Province-wide operational coordination + PHEOC digests.'),
 ('DISTRICT_SUPERVISOR','District Supervisor','DISTRICT', 'District-scoped alert closure + follow-up oversight.'),
 ('POE_ADMIN',      'POE Administrator',      'POE',      'Per-POE user + roster management.'),
 ('POE_DATA_OFFICER','POE Data Officer',      'POE',      'Submit aggregated + primary screenings at a POE.'),
 ('POE_OFFICER',    'POE Officer',            'POE',      'Standard field officer — primary + secondary screening.'),
 ('OBSERVER',       'Observer',               'SELF',     'Read-only, all data scoped to user country.'),
 ('SERVICE',        'Service Account',        'NATIONAL', 'Automated scripts + integrations.');
