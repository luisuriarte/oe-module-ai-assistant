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
