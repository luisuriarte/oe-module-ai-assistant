-- oe-module-ai-assistant uninstall SQL
-- Called during module unregister. Drops all module tables cleanly.

DROP TABLE IF EXISTS `oe_ai_assistant_audit`;
DROP TABLE IF EXISTS `oe_ai_assistant_settings`;
