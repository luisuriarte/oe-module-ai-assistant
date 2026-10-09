-- oe-module-ai-assistant install SQL
-- Prefix: oe_ai_assistant_

-- Audit log: metadata only, no clinical content by default.
--
-- `action` and `status` are VARCHAR(32) rather than ENUM so that adding an action or a
-- status does not require a schema change, and so a write never fails with "Data
-- truncated" (error 1265) — which AuditLogger swallows by design, silently losing the
-- audit record. The previous ENUM declared only ('transcribe','draft','chat') while the
-- controllers also write 'soap_draft', 'test_prompt' and 'preview_context', so those
-- inserts were dropped without any visible failure.
--
-- `status` likewise: 'blocked' is written by the ConsentGate denials in
-- DraftController and TranscribeController, and SettingsController writes
-- 'leak_detected' when the PHI leak check fails.
CREATE TABLE IF NOT EXISTS `oe_ai_assistant_audit` (
    `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`      INT              NOT NULL DEFAULT 0,
    `patient_id`   INT              NOT NULL DEFAULT 0,
    `encounter_id` INT              NOT NULL DEFAULT 0,
    `action`       VARCHAR(32)      NOT NULL DEFAULT '',
    `provider`     VARCHAR(64)      NOT NULL DEFAULT '',
    `model`        VARCHAR(128)     NOT NULL DEFAULT '',
    `status`       VARCHAR(32)      NOT NULL DEFAULT 'ok',
    `error_code`   VARCHAR(64)      NOT NULL DEFAULT '',
    `duration_ms`  INT UNSIGNED     NOT NULL DEFAULT 0,
    `tokens_in`    INT UNSIGNED     NOT NULL DEFAULT 0,
    `tokens_out`   INT UNSIGNED     NOT NULL DEFAULT 0,
    `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_patient`  (`patient_id`),
    KEY `idx_audit_user`     (`user_id`),
    KEY `idx_audit_action`   (`action`),
    KEY `idx_audit_created`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module settings: key/value store for encrypted API keys and module config.
-- OpenEMR globals cannot safely hold per-provider encrypted secrets.
CREATE TABLE IF NOT EXISTS `oe_ai_assistant_settings` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(128)    NOT NULL,
    `setting_value` TEXT            NOT NULL,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limit counters (M7): per-user fixed-window counts for provider-bound
-- endpoints. Same rationale as the audit table: `count` and `bucket` are plain
-- scalar types (never ENUM), so new buckets never require a schema change and an
-- increment can never fail with MySQL error 1265 "Data truncated".
CREATE TABLE IF NOT EXISTS `oe_ai_assistant_rate_limits` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT             NOT NULL DEFAULT 0,
    `bucket`       VARCHAR(16)     NOT NULL DEFAULT '',
    `window_start` INT UNSIGNED    NOT NULL DEFAULT 0,
    `count`        INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_bucket_window` (`user_id`, `bucket`, `window_start`),
    KEY `idx_rl_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
