-- ============================================================================
-- upgrade.sql — idempotent schema upgrade for existing installations
--
-- Safe to run any number of times. MySQL's MODIFY COLUMN is idempotent: applying
-- it to a column that already has that definition is a no-op.
--
-- Why this exists
-- ---------------
-- The audit table was originally created with ENUM columns for `action` and
-- `status`. Adding a new action or status then required an ALTER, and an action
-- missing from the ENUM made the INSERT fail with error 1265 "Data truncated" —
-- which AuditLogger caught and logged as a message, so the audit record was
-- silently lost while the clinical workflow continued.
--
-- Both columns are now VARCHAR(32). This file converts existing installs.
-- Existing enum values become ordinary strings; no data is lost.
--
-- Applied by ModuleManagerListener::upgrade_sql() and as a lazy self-heal from
-- AuditLogger::ensureSchemaUpgraded() for installs that never re-run the manager.
-- ============================================================================

ALTER TABLE `oe_ai_assistant_audit`
    MODIFY COLUMN `action` VARCHAR(32) NOT NULL DEFAULT '';

ALTER TABLE `oe_ai_assistant_audit`
    MODIFY COLUMN `status` VARCHAR(32) NOT NULL DEFAULT 'ok';

-- ============================================================================
-- Rate limit table (M7): per-user fixed-window counters for provider-bound
-- endpoints. CREATE TABLE IF NOT EXISTS so re-running is a harmless no-op, and
-- pre-existing installs created before M7 pick the table up here.
--
-- `count` is a plain integer (never an ENUM), so new buckets never need an ALTER
-- and an increment can never fail with "Data truncated". Expired windows are
-- pruned opportunistically by RateLimiter on every check.
-- ============================================================================
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
