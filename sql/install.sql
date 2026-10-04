-- oe-module-ai-assistant install SQL
-- Prefix: oe_ai_assistant_

-- Audit log: metadata only, no clinical content by default.
CREATE TABLE IF NOT EXISTS `oe_ai_assistant_audit` (
    `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`      INT              NOT NULL DEFAULT 0,
    `patient_id`   INT              NOT NULL DEFAULT 0,
    `encounter_id` INT              NOT NULL DEFAULT 0,
    `action`       ENUM('transcribe','draft','chat') NOT NULL,
    `provider`     VARCHAR(64)      NOT NULL DEFAULT '',
    `model`        VARCHAR(128)     NOT NULL DEFAULT '',
    `status`       ENUM('ok','error') NOT NULL DEFAULT 'ok',
    `error_code`   VARCHAR(64)      NOT NULL DEFAULT '',
    `duration_ms`  INT UNSIGNED     NOT NULL DEFAULT 0,
    `tokens_in`    INT UNSIGNED     NOT NULL DEFAULT 0,
    `tokens_out`   INT UNSIGNED     NOT NULL DEFAULT 0,
    `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_patient`  (`patient_id`),
    KEY `idx_audit_user`     (`user_id`),
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
