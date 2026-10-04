-- oe-module-ai-assistant uninstall SQL
-- Called during module unregister.
--
-- Settings table is dropped on uninstall:
DROP TABLE IF EXISTS `oe_ai_assistant_settings`;

-- Audit log table: PRESERVED by default for compliance and forensic auditing.
-- If you explicitly wish to purge audit records, uncomment the following line:
-- DROP TABLE IF EXISTS `oe_ai_assistant_audit`;
