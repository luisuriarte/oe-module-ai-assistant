-- ============================================================================
-- lang_custom.sql - Spanish (Latin American) translations for oe-module-ai-assistant
-- lang_code = 'el'  (Spanish Latin American in OpenEMR)
-- lang_description = 'Spanish (Latin American)'
--
-- Self-contained: run this script directly after module installation.
-- It populates lang_custom AND synchronizes into lang_languages /
-- lang_constants / lang_definitions.
-- No manual Administration > Language sync step is required.
--
-- Source UI strings are in English (xl/xlt/xla calls in PHP templates).
-- This script provides Spanish (Latin American) translations.
-- Existing translations are NOT overwritten (safe to re-run).
-- ============================================================================

START TRANSACTION;

-- ============================================================================
-- 1. Ensure the Spanish (Latin American) language record exists
-- ============================================================================
INSERT IGNORE INTO lang_languages (lang_code, lang_description)
VALUES ('el', 'Spanish (Latin American)');

-- ============================================================================
-- 2. Insert translations into lang_custom
--    Existing entries are skipped (INSERT IGNORE).
-- ============================================================================
INSERT IGNORE INTO lang_custom (lang_description, lang_code, constant_name, definition) VALUES

-- General / shared
('Spanish (Latin American)', 'el', 'Save', 'Guardar'),
('Spanish (Latin American)', 'el', 'Cancel', 'Cancelar'),
('Spanish (Latin American)', 'el', 'Close', 'Cerrar'),
('Spanish (Latin American)', 'el', 'Error', 'Error'),
('Spanish (Latin American)', 'el', 'Success', 'Éxito'),
('Spanish (Latin American)', 'el', 'Loading...', 'Cargando...'),
('Spanish (Latin American)', 'el', 'Yes', 'Sí'),
('Spanish (Latin American)', 'el', 'No', 'No'),

-- Settings page — title and navigation
('Spanish (Latin American)', 'el', 'AI Assistant Settings', 'Configuración del Asistente IA'),
('Spanish (Latin American)', 'el', 'AI Assistant - Settings', 'Asistente IA — Configuración'),
('Spanish (Latin American)', 'el', 'Save Settings', 'Guardar Configuración'),
('Spanish (Latin American)', 'el', 'Settings saved.', 'Configuración guardada.'),
('Spanish (Latin American)', 'el', 'Some settings could not be saved. Check server logs.', 'Algunas configuraciones no pudieron guardarse. Consulte los registros del servidor.'),

-- Legal consent section
('Spanish (Latin American)', 'el', 'Legal Consent', 'Consentimiento Legal'),
('Spanish (Latin American)', 'el', 'Required: Data Consent Acknowledgement', 'Requerido: Confirmación de Consentimiento de Datos'),
('Spanish (Latin American)', 'el', 'By enabling the AI Assistant, you confirm that your clinic has:', 'Al habilitar el Asistente IA, confirma que su clínica cuenta con:'),
('Spanish (Latin American)', 'el', 'A legal basis for processing patient clinical data with a third-party AI provider.', 'Una base legal para procesar datos clínicos de pacientes con un proveedor de IA externo.'),
('Spanish (Latin American)', 'el', 'An appropriate patient consent process in place.', 'Un proceso de consentimiento de pacientes adecuado establecido.'),
('Spanish (Latin American)', 'el', 'Data categories sent to the provider: problem list, medications, allergies, SOAP note summaries, vitals summary, lab results (if enabled). No names, addresses, national IDs or insurance numbers are sent.', 'Categorías de datos enviados al proveedor: lista de problemas, medicamentos, alergias, resúmenes de notas SOAP, resumen de signos vitales, resultados de laboratorio (si se habilita). No se envían nombres, direcciones, números de identificación nacional ni de seguro.'),
('Spanish (Latin American)', 'el', 'Tick the checkbox in the form below to acknowledge.', 'Marque la casilla en el formulario a continuación para confirmar.'),
('Spanish (Latin American)', 'el', 'I confirm the clinic has a legal basis and patient consent process for AI-assisted processing.', 'Confirmo que la clínica tiene una base legal y un proceso de consentimiento de pacientes para el procesamiento asistido por IA.'),

-- Whisper server section
('Spanish (Latin American)', 'el', 'Whisper Transcription Server', 'Servidor de Transcripción Whisper'),
('Spanish (Latin American)', 'el', 'Server URL', 'URL del Servidor'),
('Spanish (Latin American)', 'el', 'Timeout (s)', 'Tiempo de espera (s)'),
('Spanish (Latin American)', 'el', 'Max audio length (s)', 'Duración máxima de audio (s)'),
('Spanish (Latin American)', 'el', 'Test Whisper Connection', 'Probar Conexión con Whisper'),
('Spanish (Latin American)', 'el', '(available in M2)', '(disponible en M2)'),

-- AI Provider section
('Spanish (Latin American)', 'el', 'AI Provider', 'Proveedor de IA'),
('Spanish (Latin American)', 'el', 'Active Provider', 'Proveedor Activo'),
('Spanish (Latin American)', 'el', 'Base URL', 'URL Base'),
('Spanish (Latin American)', 'el', 'Model', 'Modelo'),
('Spanish (Latin American)', 'el', 'Temperature', 'Temperatura'),
('Spanish (Latin American)', 'el', 'Max tokens', 'Tokens máximos'),
('Spanish (Latin American)', 'el', 'API Key', 'Clave API'),
('Spanish (Latin American)', 'el', 'Key saved', 'Clave guardada'),
('Spanish (Latin American)', 'el', 'Enter API key', 'Ingrese la clave API'),
('Spanish (Latin American)', 'el', 'Leave blank to keep existing key', 'Dejar en blanco para conservar la clave existente'),
('Spanish (Latin American)', 'el', 'Never stored in logs or sent to the browser.', 'Nunca se almacena en registros ni se envía al navegador.'),
('Spanish (Latin American)', 'el', 'Test OpenAI Connection', 'Probar Conexión OpenAI'),
('Spanish (Latin American)', 'el', 'Test Anthropic Connection', 'Probar Conexión Anthropic'),
('Spanish (Latin American)', 'el', 'Test Gemini Connection', 'Probar Conexión Gemini'),
('Spanish (Latin American)', 'el', 'Test Grok Connection', 'Probar Conexión Grok'),
('Spanish (Latin American)', 'el', '(available in M3)', '(disponible en M3)'),
('Spanish (Latin American)', 'el', 'OpenAI-compatible', 'Compatible con OpenAI'),
('Spanish (Latin American)', 'el', 'Anthropic', 'Anthropic'),
('Spanish (Latin American)', 'el', 'Google Gemini', 'Google Gemini'),
('Spanish (Latin American)', 'el', 'Grok (xAI)', 'Grok (xAI)'),

-- Consent gate messages (ConsentGate::denialMessage and module_status)
('Spanish (Latin American)', 'el', 'The AI Assistant administrator has not acknowledged the patient data disclosure. No data was sent. Ask an administrator to enable AI features in Administration -> Modules -> AI Assistant -> Configure.', 'El administrador del Asistente de IA no ha reconocido la divulgación de datos del paciente. No se envió ningún dato. Pedí a un administrador que habilite las funciones de IA en Administración -> Módulos -> Asistente de IA -> Configurar.'),
('Spanish (Latin American)', 'el', 'No API key is saved for the active AI provider. No data was sent. An administrator must configure the provider key.', 'No hay ninguna clave API guardada para el proveedor de IA activo. No se envió ningún dato. Un administrador debe configurar la clave del proveedor.'),
('Spanish (Latin American)', 'el', 'AI transmission is currently blocked by the module safety gate.', 'La transmisión por IA está bloqueada actualmente por la puerta de seguridad del módulo.'),
('Spanish (Latin American)', 'el', 'Access denied.', 'Acceso denegado.'),

-- Patient context section
('Spanish (Latin American)', 'el', 'Patient Context Options', 'Opciones de Contexto del Paciente'),
('Spanish (Latin American)', 'el', 'Past encounters to include', 'Consultas anteriores a incluir'),
('Spanish (Latin American)', 'el', 'Context token budget', 'Presupuesto de tokens de contexto'),
('Spanish (Latin American)', 'el', 'Output language', 'Idioma de salida'),
('Spanish (Latin American)', 'el', 'Include recent lab results', 'Incluir resultados de laboratorio recientes'),

-- Features section
('Spanish (Latin American)', 'el', 'Features', 'Funcionalidades'),
('Spanish (Latin American)', 'el', 'Enable patient chat panel (Layer 2)', 'Habilitar panel de chat del paciente (Capa 2)'),

-- Audit & debug section
('Spanish (Latin American)', 'el', 'Audit & Debug', 'Auditoría y Depuración'),
('Spanish (Latin American)', 'el', 'Audit log retention (days)', 'Retención del registro de auditoría (días)'),
('Spanish (Latin American)', 'el', 'Debug: log prompts and responses (SENSITIVE — disable in production)', 'Depuración: registrar prompts y respuestas (SENSIBLE — deshabilitar en producción)'),

-- Security / error messages
('Spanish (Latin American)', 'el', 'Access denied.', 'Acceso denegado.'),
('Spanish (Latin American)', 'el', 'Invalid CSRF token.', 'Token CSRF inválido.'),
('Spanish (Latin American)', 'el', 'Unauthorized', 'No autorizado'),
('Spanish (Latin American)', 'el', 'Not found', 'No encontrado'),
('Spanish (Latin American)', 'el', 'Session expired. Please log in again.', 'La sesión expiró. Por favor inicie sesión nuevamente.'),

-- Dictation UI (M5 — strings declared now for translation continuity)
('Spanish (Latin American)', 'el', 'AI Dictation', 'Dictado IA'),
('Spanish (Latin American)', 'el', 'Record', 'Grabar'),
('Spanish (Latin American)', 'el', 'Stop', 'Detener'),
('Spanish (Latin American)', 'el', 'Discard', 'Descartar'),
('Spanish (Latin American)', 'el', 'Transcribing...', 'Transcribiendo...'),
('Spanish (Latin American)', 'el', 'Edit transcript before generating draft', 'Edite la transcripción antes de generar el borrador'),
('Spanish (Latin American)', 'el', 'Generate Draft', 'Generar Borrador'),
('Spanish (Latin American)', 'el', 'Generating draft...', 'Generando borrador...'),
('Spanish (Latin American)', 'el', 'AI-generated draft — review before saving', 'Borrador generado por IA — revise antes de guardar'),
('Spanish (Latin American)', 'el', 'Whisper server unavailable. Please try again later.', 'El servidor Whisper no está disponible. Por favor intente más tarde.'),
('Spanish (Latin American)', 'el', 'Server busy — another transcription is in progress. Please wait.', 'Servidor ocupado — hay otra transcripción en curso. Por favor espere.'),
('Spanish (Latin American)', 'el', 'Audio too long. Maximum length is %d minutes.', 'Audio demasiado largo. La duración máxima es %d minutos.'),
('Spanish (Latin American)', 'el', 'Provider connection failed. Check API key and settings.', 'Error de conexión con el proveedor. Verifique la clave API y la configuración.'),
('Spanish (Latin American)', 'el', 'Provider timeout. Please try again.', 'Tiempo de espera del proveedor agotado. Por favor intente nuevamente.'),
('Spanish (Latin American)', 'el', 'Invalid response from AI provider. Please try again.', 'Respuesta inválida del proveedor de IA. Por favor intente nuevamente.'),

-- Chat panel (M6)
('Spanish (Latin American)', 'el', 'Patient Chat', 'Chat del Paciente'),
('Spanish (Latin American)', 'el', 'Ask a question about this patient...', 'Haga una pregunta sobre este paciente...'),
('Spanish (Latin American)', 'el', 'Send', 'Enviar'),
('Spanish (Latin American)', 'el', 'Chat history cleared.', 'Historial de chat eliminado.'),
('Spanish (Latin American)', 'el', 'Clear chat', 'Limpiar chat'),
('Spanish (Latin American)', 'el', 'AI Assistant is thinking...', 'El Asistente IA está pensando...'),
('Spanish (Latin American)', 'el', 'Chat is disabled. Enable it in module settings.', 'El chat está deshabilitado. Habilítelo en la configuración del módulo.');

-- ============================================================================
-- 3. Synchronize lang_custom into lang_constants (add missing source strings)
-- ============================================================================
INSERT IGNORE INTO lang_constants (constant_name)
SELECT DISTINCT CONVERT(lc.constant_name USING utf8mb4)
FROM lang_custom lc
WHERE lc.constant_name <> ''
  AND NOT EXISTS (
    SELECT 1 FROM lang_constants c
    WHERE c.constant_name = CONVERT(lc.constant_name USING utf8mb4)
  );

-- ============================================================================
-- 4. Insert new definitions (skip if already exists)
-- ============================================================================
INSERT IGNORE INTO lang_definitions (cons_id, lang_id, definition)
SELECT c.cons_id, l.lang_id, lc.definition
FROM lang_custom lc
INNER JOIN lang_constants c
    ON c.constant_name = CONVERT(lc.constant_name USING utf8mb4)
INNER JOIN lang_languages l
    ON l.lang_code = lc.lang_code
WHERE NOT EXISTS (
    SELECT 1 FROM lang_definitions d
    WHERE d.cons_id = c.cons_id AND d.lang_id = l.lang_id
);

-- ============================================================================
-- 5. Update definitions that changed since last run
-- ============================================================================
UPDATE lang_definitions d
INNER JOIN lang_constants c ON c.cons_id = d.cons_id
INNER JOIN lang_languages l ON l.lang_id = d.lang_id
INNER JOIN lang_custom lc
    ON l.lang_code = lc.lang_code
    AND c.constant_name = CONVERT(lc.constant_name USING utf8mb4)
SET d.definition = lc.definition
WHERE IFNULL(d.definition, '') <> IFNULL(lc.definition, '');

COMMIT;
