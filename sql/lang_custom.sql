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
-- INSERT IGNORE INTO lang_languages (lang_code, lang_description)
-- VALUES ('el', 'Spanish (Latin American)');

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
('Spanish (Latin American)', 'el', 'AI Assistant — Settings', 'Asistente IA — Configuración'),
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
('Spanish (Latin American)', 'el', 'Test Z.ai Connection', 'Probar Conexión Z.ai'),
('Spanish (Latin American)', 'el', '(available in M3)', '(disponible en M3)'),
('Spanish (Latin American)', 'el', 'OpenAI-compatible', 'Compatible con OpenAI'),
('Spanish (Latin American)', 'el', 'Anthropic', 'Anthropic'),
('Spanish (Latin American)', 'el', 'Google Gemini', 'Google Gemini'),
('Spanish (Latin American)', 'el', 'Grok (xAI)', 'Grok (xAI)'),
('Spanish (Latin American)', 'el', 'Z.ai (GLM)', 'Z.ai (GLM)'),

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
('Spanish (Latin American)', 'el', 'Chat is disabled. Enable it in module settings.', 'El chat está deshabilitado. Habilítelo en la configuración del módulo.'),

-- Settings page — privacy notice and lab toggle
('Spanish (Latin American)', 'el', 'Important privacy notice:', 'Aviso de privacidad importante:'),
('Spanish (Latin American)', 'el', 'Free-tier API keys (such as Google AI Studio free tier) may allow the provider to review or use submitted content for model training. Free-tier keys must NEVER be used with real patient data. Use only paid/HIPAA/BAA enterprise tiers in production.', 'Las claves API de nivel gratuito (como el nivel gratuito de Google AI Studio) pueden permitir que el proveedor revise o utilice el contenido enviado para entrenar modelos. Las claves de nivel gratuito NUNCA deben usarse con datos reales de pacientes. En producción, use únicamente planes empresariales de pago, HIPAA o BAA.'),
('Spanish (Latin American)', 'el', 'Include recent lab results (off by default)', 'Incluir resultados de laboratorio recientes (desactivado por defecto)'),

-- Settings page — Admin Test Bench: Whisper audio transcription
('Spanish (Latin American)', 'el', 'Admin Test Bench — Whisper Audio Transcription', 'Banco de Pruebas del Administrador - Transcripción de Audio Whisper'),
('Spanish (Latin American)', 'el', 'Upload-only (Test mode)', 'Solo carga de archivos (modo de prueba)'),
('Spanish (Latin American)', 'el', 'Transcript Output:', 'Salida de la Transcripción:'),
('Spanish (Latin American)', 'el', 'Transcript will appear here once processing completes...', 'La transcripción aparecerá aquí una vez que el procesamiento se complete...'),

-- Settings page — Admin Test Bench: Synthetic AI prompt
('Spanish (Latin American)', 'el', 'Admin Test Bench — Synthetic AI Prompt Test (M3 Provider Layer)', 'Banco de Pruebas del Administrador - Prueba de Prompt Sintético de IA (Capa de Proveedor M3)'),
('Spanish (Latin American)', 'el', 'Synthetic prompt (No patient data)', 'Prompt sintético (sin datos de paciente)'),
('Spanish (Latin American)', 'el', 'Send a synthetic test prompt through the real provider layer (Gemini, OpenAI, Anthropic, Grok, or Z.ai). Verifies authentication, HTTPS payload formatting, token counting, and audit recording. Audited with patient_id = 0.', 'Envía un prompt de prueba sintético a través de la capa real de proveedores (Gemini, OpenAI, Anthropic, Grok o Z.ai). Verifica la autenticación, el formato de la carga útil HTTPS, el conteo de tokens y el registro de auditoría. Se audita con patient_id = 0.'),
('Spanish (Latin American)', 'el', 'System prompt (optional):', 'Prompt del sistema (opcional):'),
('Spanish (Latin American)', 'el', 'User prompt (synthetic only):', 'Prompt del usuario (solo sintético):'),
('Spanish (Latin American)', 'el', 'Run Test Prompt', 'Ejecutar Prompt de Prueba'),
('Spanish (Latin American)', 'el', 'Contacting provider...', 'Contactando al proveedor...'),
('Spanish (Latin American)', 'el', 'AI Provider Response:', 'Respuesta del Proveedor de IA:'),
('Spanish (Latin American)', 'el', 'Provider response will appear here...', 'La respuesta del proveedor aparecerá aquí...'),

-- Settings page — Admin Test Bench: Patient context preview and leak check
('Spanish (Latin American)', 'el', 'Admin Test Bench — Patient Context Preview & Leak Check', 'Banco de Pruebas del Administrador - Vista Previa del Contexto del Paciente y Detección de Fugas'),
('Spanish (Latin American)', 'el', 'M4 Privacy Verification', 'Verificación de Privacidad M4'),
('Spanish (Latin American)', 'el', 'Generates the exact anonymized/redacted prompt context that would be sent to the AI model for a selected patient. Runs an automated leak check verifying that the patient''s real name, surname, DOB, phone, email, and national/SSN identifiers do not appear anywhere in the assembled text. Audited with patient ID but without clinical text.', 'Genera el contexto exacto anonimizado/ redactado del prompt que se enviaría al modelo de IA para un paciente seleccionado. Ejecuta una verificación automatizada de fugas que confirma que el nombre, apellido, fecha de nacimiento, teléfono, correo electrónico e identificadores nacionales/SSN reales del paciente no aparecen en ningún lugar del texto ensamblado. Se audita con el ID del paciente pero sin texto clínico.'),
('Spanish (Latin American)', 'el', 'Patient ID (PID)', 'ID del Paciente (PID)'),
('Spanish (Latin American)', 'el', 'Generate Context & Check Leaks', 'Generar Contexto y Verificar Fugas'),
('Spanish (Latin American)', 'el', 'Patient context preview will appear here...', 'La vista previa del contexto del paciente aparecerá aquí...'),

-- Settings page — provider rejection message (M3 provider layer)
('Spanish (Latin American)', 'el', 'The AI provider rejected the request.', 'El proveedor de IA rechazó la solicitud.'),

-- Settings page — Synthetic prompt test: success feedback and token/latency stats
('Spanish (Latin American)', 'el', 'Response received!', '¡Respuesta recibida!'),
('Spanish (Latin American)', 'el', 'Tokens:', 'Tokens:'),
('Spanish (Latin American)', 'el', 'in', 'de entrada'),
('Spanish (Latin American)', 'el', 'out', 'de salida'),
('Spanish (Latin American)', 'el', 'total', 'total'),
('Spanish (Latin American)', 'el', 'Latency:', 'Latencia:'),
('Spanish (Latin American)', 'el', 'Logged in audit table', 'Registrado en la tabla de auditoría'),

('Spanish (Latin American)', 'el', 'Patient ID (PID):', 'ID del Paciente (PID):'),
('Spanish (Latin American)', 'el', 'Compiled Patient Context:', 'Contexto Compilado del Paciente:'),

-- Spanish translations added in batch
('Spanish (Latin American)', 'el', 'Absolute (YYYY-MM-DD)', 'Absoluto (AAAA-MM-DD)'),
('Spanish (Latin American)', 'el', 'Allow private/local network hosts (SSRF bypass — enable ONLY for local services like Ollama/LocalAI)', 'Permitir hosts de red privados/locales (excepción SSRF — habilite SOLO para servicios locales como Ollama/LocalAI)'),
('Spanish (Latin American)', 'el', 'Audit &amp; Debug', 'Auditoría y Depuración'),
('Spanish (Latin American)', 'el', 'Audited (no clinical text logged)', 'Auditado (sin texto clínico registrado)'),
('Spanish (Latin American)', 'el', 'Blocked by provider safety filter', 'Bloqueado por el filtro de seguridad del proveedor'),
('Spanish (Latin American)', 'el', 'Connection failed:', 'Conexión fallida:'),
('Spanish (Latin American)', 'el', 'Connection successful', 'Conexión exitosa'),
('Spanish (Latin American)', 'el', 'Date formatting', 'Formato de fecha'),
('Spanish (Latin American)', 'el', 'e.g. 1', 'ej. 1'),
('Spanish (Latin American)', 'el', 'Error:', 'Error:'),
('Spanish (Latin American)', 'el', 'FAIL: Potential PII Leak Detected!', 'FALLO: ¡Se detectó una posible filtración de PII!'),
('Spanish (Latin American)', 'el', 'Invalid response: missing job_id', 'Respuesta inválida: falta job_id'),
('Spanish (Latin American)', 'el', 'Job expired or not found.', 'El trabajo expiró o no se encontró.'),
('Spanish (Latin American)', 'el', 'Leaked fields:', 'Campos filtrados:'),
('Spanish (Latin American)', 'el', 'models discovered', 'modelos encontrados'),
('Spanish (Latin American)', 'el', 'PASS: No PII Leaks Detected', 'APROBADO: No se detectaron fugas de PII'),
('Spanish (Latin American)', 'el', 'Patient name, DOB, phone, email, and ID numbers were searched in the output text and none were found.', 'Se buscaron en el texto de salida el nombre, fecha de nacimiento, teléfono, correo electrónico y números de identificación del paciente, y no se encontró ninguno.'),
('Spanish (Latin American)', 'el', 'Please enter a synthetic test prompt.', 'Ingrese un prompt de prueba sintético.'),
('Spanish (Latin American)', 'el', 'Please enter a valid Patient ID.', 'Ingrese un ID de paciente válido.'),
('Spanish (Latin American)', 'el', 'Please select an audio file first.', 'Seleccione primero un archivo de audio.'),
('Spanish (Latin American)', 'el', 'Polling cancelled by user.', 'Sondeo cancelado por el usuario.'),
('Spanish (Latin American)', 'el', 'Polling timed out.', 'Tiempo de espera del sondeo agotado.'),
('Spanish (Latin American)', 'el', 'Processing audio in background...', 'Procesando audio en segundo plano...'),
('Spanish (Latin American)', 'el', 'Processing...', 'Procesando...'),
('Spanish (Latin American)', 'el', 'Provider', 'Proveedor'),
('Spanish (Latin American)', 'el', 'Relative (e.g. hoy, hace 3 días)', 'Relativo (ej. hoy, hace 3 días)'),
('Spanish (Latin American)', 'el', 'Request error:', 'Error de solicitud:'),
('Spanish (Latin American)', 'el', 'Server busy (HTTP 429): transcription lock currently held by another worker.', 'Servidor ocupado (HTTP 429): el bloqueo de transcripción lo tiene otro trabajador.'),
('Spanish (Latin American)', 'el', 'Submission error:', 'Error de envío:'),
('Spanish (Latin American)', 'el', 'Submitting test audio...', 'Enviando audio de prueba...'),
('Spanish (Latin American)', 'el', 'Testing connection...', 'Probando la conexión...'),
('Spanish (Latin American)', 'el', 'Transcribe Test Audio', 'Transcribir Audio de Prueba'),
('Spanish (Latin American)', 'el', 'Transcription completed!', 'Transcripción completada!'),
('Spanish (Latin American)', 'el', 'Transcription failed:', 'Transcripción fallida:'),
('Spanish (Latin American)', 'el', 'Unknown', 'Desconocido'),
('Spanish (Latin American)', 'el', 'Unknown error occurred', 'Ocurrió un error desconocido'),
('Spanish (Latin American)', 'el', 'Upload an audio file to test end-to-end Whisper transcription through the production validation pipeline, host-shared flock lock, and polling worker. Audited with patient_id = 0, encounter_id = 0 and flagged as test.', 'Cargue un archivo de audio para probar la transcripción Whisper de extremo a extremo a través del pipeline de validación de producción, el bloqueo flock compartido del host y el trabajador de sondeo. Se audita con patient_id = 0, encounter_id = 0 y se marca como prueba.'),

('Spanish (Latin American)', 'el', 'AI provider authentication failed. Please verify your API key in AI Assistant Settings.', 'Falló la autenticación del proveedor de IA. Verifique su clave API en la Configuración del Asistente IA.'),
('Spanish (Latin American)', 'el', 'AI provider communication failed. Please retry.', 'Falló la comunicación con el proveedor de IA. Intente de nuevo.'),
('Spanish (Latin American)', 'el', 'AI provider rate limit reached. Please wait a few seconds before retrying.', 'Se alcanzó el límite de solicitudes del proveedor de IA. Espere unos segundos antes de reintentar.'),
('Spanish (Latin American)', 'el', 'Access denied: You do not have permission to access this sensitive encounter.', 'Acceso denegado: no tiene permiso para acceder a este encuentro sensible.'),
('Spanish (Latin American)', 'el', 'Access denied: module use permission required.', 'Acceso denegado: se requiere el permiso de uso del módulo.'),
('Spanish (Latin American)', 'el', 'An unexpected error occurred while generating the SOAP draft.', 'Ocurrió un error inesperado al generar el borrador SOAP.'),
('Spanish (Latin American)', 'el', 'Encounter not found.', 'Encuentro no encontrado.'),
('Spanish (Latin American)', 'el', 'Invalid patient ID.', 'ID de paciente inválido.'),
('Spanish (Latin American)', 'el', 'Not authenticated.', 'No autenticado.'),
('Spanish (Latin American)', 'el', 'Patient access denied.', 'Acceso al paciente denegado.'),
('Spanish (Latin American)', 'el', 'Security violation: Encounter does not belong to the selected patient.', 'Violación de seguridad: el encuentro no pertenece al paciente seleccionado.'),
('Spanish (Latin American)', 'el', 'The AI provider request timed out. Please try again or check your network connectivity.', 'La solicitud al proveedor de IA expiró. Intente de nuevo o verifique su conexión de red.'),
('Spanish (Latin American)', 'el', 'The consultation content was flagged by the AI provider safety filter.', 'El contenido de la consulta fue marcado por el filtro de seguridad del proveedor de IA.'),
('Spanish (Latin American)', 'el', 'The transcription engine is currently processing another audio. Please try again shortly.', 'El motor de transcripción está procesando otro audio en este momento. Intente de nuevo en unos instantes.'),
('Spanish (Latin American)', 'el', 'Transcript cannot be empty.', 'La transcripción no puede estar vacía.'),
('Spanish (Latin American)', 'el', 'Transcript exceeds maximum allowable length (50,000 characters).', 'La transcripción supera la longitud máxima permitida (50.000 caracteres).'),
('Spanish (Latin American)', 'el', 'AI chat is not enabled on this server. Contact your administrator.', 'El chat por IA no está habilitado en este servidor. Comuníquese con su administrador.'),
('Spanish (Latin American)', 'el', 'Active problems', 'Problemas activos'),
('Spanish (Latin American)', 'el', 'Allergies', 'Alergias'),
('Spanish (Latin American)', 'el', 'An unexpected error occurred while answering the question.', 'Ocurrió un error inesperado al responder la pregunta.'),
('Spanish (Latin American)', 'el', 'Answers come only from the chart context and cite the section they came from.', 'Las respuestas salen solo del contexto de la ficha y citan la sección de origen.'),
('Spanish (Latin American)', 'el', 'Clear conversation', 'Borrar conversación'),
('Spanish (Latin American)', 'el', 'Clinical AI Chat', 'Chat Clínico IA'),
('Spanish (Latin American)', 'el', 'Connection error. Please try again.', 'Error de conexión. Intente de nuevo.'),
('Spanish (Latin American)', 'el', 'Conversation cleared.', 'Conversación borrada.'),
('Spanish (Latin American)', 'el', 'Could not verify whether AI chat is enabled.', 'No se pudo verificar si el chat por IA está habilitado.'),
('Spanish (Latin American)', 'el', 'Current medication', 'Medicación actual'),
('Spanish (Latin American)', 'el', 'History and habits', 'Antecedentes y hábitos'),
('Spanish (Latin American)', 'el', 'Open patient chat', 'Abrir chat del paciente'),
('Spanish (Latin American)', 'el', 'Patient chat', 'Chat del paciente'),
('Spanish (Latin American)', 'el', 'Patient profile', 'Perfil del paciente'),
('Spanish (Latin American)', 'el', 'Previous SOAP encounters', 'Consultas SOAP previas'),
('Spanish (Latin American)', 'el', 'Recent laboratory results', 'Resultados de laboratorio recientes'),
('Spanish (Latin American)', 'el', 'Recent vital signs', 'Signos vitales recientes'),
('Spanish (Latin American)', 'el', 'Reviewing the chart...', 'Revisando la ficha...'),
('Spanish (Latin American)', 'el', 'Sources', 'Fuentes'),
('Spanish (Latin American)', 'el', 'The patient chat panel is disabled. An administrator must enable it in AI Assistant Settings.', 'El panel de chat del paciente está desactivado. Un administrador debe habilitarlo en la Configuración del Asistente IA.'),
('Spanish (Latin American)', 'el', 'The question cannot be empty.', 'La pregunta no puede estar vacía.'),
('Spanish (Latin American)', 'el', 'The question could not be answered. Please try again.', 'No se pudo responder la pregunta. Intente de nuevo.'),
('Spanish (Latin American)', 'el', 'The question exceeds the maximum allowed length.', 'La pregunta supera la longitud máxima permitida.'),
('Spanish (Latin American)', 'el', 'Type a question first.', 'Escribí una pregunta primero.'),
-- Dictation toolbar / audit notice translations

('Spanish (Latin American)', 'el', 'AI dictation is not enabled on this server. Contact your administrator.', 'El dictado por IA no está habilitado en este servidor. Contactá al administrador.'),
('Spanish (Latin American)', 'el', 'AI dictation is not enabled on this server.', 'El dictado por IA no está habilitado en este servidor.'),
('Spanish (Latin American)', 'el', 'AI draft', 'Borrador IA'),
('Spanish (Latin American)', 'el', 'AI-generated draft: review and edit the content before saving the encounter.', 'Borrador generado por IA: revisá y editá el contenido antes de guardar la consulta.'),
('Spanish (Latin American)', 'el', 'Append at the end', 'Anexar al final'),
('Spanish (Latin American)', 'el', 'Audio limit reached (%d min). Processing...', 'Límite de audio alcanzado (%d min). Procesando...'),
('Spanish (Latin American)', 'el', 'Audio recorded. Uploading...', 'Audio grabado. Subiendo...'),
('Spanish (Latin American)', 'el', 'Audio recording is not supported in this browser.', 'La grabación de audio no está soportada en este navegador.'),
('Spanish (Latin American)', 'el', 'Audit log write failures: %s. Records for those operations were not saved. Schema upgrade may be pending.', 'Fallos de escritura en el registro de auditoría: %s. Los registros de esas operaciones no se guardaron. Puede haber una actualización de esquema pendiente.'),
('Spanish (Latin American)', 'el', 'Authorization error checking the transcription: ', 'Error de autorización al consultar la transcripción: '),
('Spanish (Latin American)', 'el', 'Clinical AI Dictation', 'Dictado Clínico IA'),
('Spanish (Latin American)', 'el', 'Connection error: ', 'Error de conexión: '),
('Spanish (Latin American)', 'el', 'Contains items to verify [VERIFY]', 'Contiene elementos a verificar [VERIFY]'),
('Spanish (Latin American)', 'el', 'Could not connect to the server: ', 'Error al conectar con el servidor: '),
('Spanish (Latin American)', 'el', 'Could not generate the draft: ', 'No se pudo generar el borrador: '),
('Spanish (Latin American)', 'el', 'Could not verify whether AI dictation is enabled.', 'No se pudo verificar si el dictado por IA está habilitado.'),
('Spanish (Latin American)', 'el', 'Dictation transcript (you can edit it before generating the draft):', 'Transcripción del dictado (podés editarla antes de generar el borrador):'),
('Spanish (Latin American)', 'el', 'Draft generated successfully (%s - %s tokens).', 'Borrador generado con éxito (%s - %s tokens).'),
('Spanish (Latin American)', 'el', 'Duration:', 'Duración:'),
('Spanish (Latin American)', 'el', 'Error accessing the microphone: ', 'Error al acceder al micrófono: '),
('Spanish (Latin American)', 'el', 'Error checking status: ', 'Error al consultar estado: '),
('Spanish (Latin American)', 'el', 'Error generating draft: ', 'Error al generar borrador: '),
('Spanish (Latin American)', 'el', 'Error in the transcription process.', 'Error en el proceso de transcripción.'),
('Spanish (Latin American)', 'el', 'Generate SOAP Note', 'Generar Nota SOAP'),
('Spanish (Latin American)', 'el', 'Generating SOAP draft with AI...', 'Generando borrador SOAP con IA...'),
('Spanish (Latin American)', 'el', 'Got it', 'Entendido'),
('Spanish (Latin American)', 'el', 'Internal server error during transcription.', 'Error interno del servidor durante la transcripción.'),
('Spanish (Latin American)', 'el', 'Invalid CSRF token. Reload the page.', 'Token CSRF inválido. Recargá la página.'),
('Spanish (Latin American)', 'el', 'Invalid audio file.', 'Archivo de audio inválido.'),
('Spanish (Latin American)', 'el', 'Invalid response', 'Respuesta inválida'),
('Spanish (Latin American)', 'el', 'Microphone permission denied. Please allow microphone access in the browser to dictate.', 'Permiso de micrófono denegado. Por favor permití el acceso al micrófono en el navegador para dictar.'),
('Spanish (Latin American)', 'el', 'Network error uploading audio: ', 'Error de red al subir audio: '),
('Spanish (Latin American)', 'el', 'No audio file was received.', 'No se recibió el archivo de audio.'),
('Spanish (Latin American)', 'el', 'No microphone found connected to this device.', 'No se encontró ningún micrófono conectado en este dispositivo.'),
('Spanish (Latin American)', 'el', 'No transcription available to generate the draft.', 'No hay transcripción disponible para generar el borrador.'),
('Spanish (Latin American)', 'el', 'Previous content detected', 'Contenido previo detectado'),
('Spanish (Latin American)', 'el', 'Processing transcription...', 'Procesando transcripción...'),
('Spanish (Latin American)', 'el', 'Provider error', 'Error del proveedor'),
('Spanish (Latin American)', 'el', 'Ready to dictate', 'Listo para dictar'),
('Spanish (Latin American)', 'el', 'Recording consultation...', 'Grabando consulta...'),
('Spanish (Latin American)', 'el', 'Recording discarded.', 'Grabación descartada.'),
('Spanish (Latin American)', 'el', 'Replace everything', 'Reemplazar todo'),
('Spanish (Latin American)', 'el', 'Server busy. Retrying upload in 3 s...', 'Servidor ocupado. Reintentando subida en 3 s...'),
('Spanish (Latin American)', 'el', 'Server storage error.', 'Error de almacenamiento en el servidor.'),
('Spanish (Latin American)', 'el', 'The SOAP note fields already contain text. How would you like to incorporate the new AI-generated draft?', 'Los campos de la nota SOAP ya contienen texto. ¿Cómo deseás incorporar el nuevo borrador generado por la IA?'),
('Spanish (Latin American)', 'el', 'The Whisper URL is invalid or unreachable.', 'La URL de Whisper no es válida o no es alcanzable.'),
('Spanish (Latin American)', 'el', 'The audio exceeds the maximum allowed size.', 'El audio supera el tamaño máximo permitido.'),
('Spanish (Latin American)', 'el', 'The consultation transcript will appear here...', 'La transcripción de la consulta aparecerá acá...'),
('Spanish (Latin American)', 'el', 'The job does not belong to this patient or encounter.', 'El trabajo no corresponde a este paciente o encuentro.'),
('Spanish (Latin American)', 'el', 'The patient or encounter could not be validated.', 'No se pudo validar el paciente o el encuentro.'),
('Spanish (Latin American)', 'el', 'The transcription engine is busy. Try again in a few seconds.', 'El motor de transcripción está ocupado. Reintentá en unos segundos.'),
('Spanish (Latin American)', 'el', 'The transcription exceeded the maximum allowed time.', 'La transcripción excedió el tiempo máximo permitido.'),
('Spanish (Latin American)', 'el', 'The transcription expired or does not exist. Record again.', 'La transcripción expiró o no existe. Volvé a grabar.'),
('Spanish (Latin American)', 'el', 'The transcription job expired or does not exist.', 'El trabajo de transcripción expiró o no existe.'),
('Spanish (Latin American)', 'el', 'Transcribing audio (Whisper)...', 'Transcribiendo audio (Whisper)...'),
('Spanish (Latin American)', 'el', 'Transcription complete.', 'Transcripción completada.'),
('Spanish (Latin American)', 'el', 'Transcription error: ', 'Error en transcripción: '),
('Spanish (Latin American)', 'el', 'Transcription failed.', 'La transcripción falló.'),
('Spanish (Latin American)', 'el', 'Transcription server busy. Waiting in queue...', 'Servidor de transcripción ocupado. Esperando turno...'),
('Spanish (Latin American)', 'el', 'Unauthenticated or expired session.', 'Sesión no autenticada o vencida.'),
('Spanish (Latin American)', 'el', 'Unknown failure', 'Fallo desconocido'),
('Spanish (Latin American)', 'el', 'Unsupported audio format.', 'Formato de audio no soportado.'),
('Spanish (Latin American)', 'el', 'Upload error: ', 'Error al subir audio: '),
('Spanish (Latin American)', 'el', 'Uploading audio to the Whisper server...', 'Enviando audio al servidor Whisper...'),
('Spanish (Latin American)', 'el', 'You do not have permission to use AI dictation.', 'No tenés permiso para usar el dictado por IA.'),
-- SOAP-AI editor (M8): new UI/config strings
('Spanish (Latin American)', 'el', 'SOAP with AI', 'SOAP con IA'),
('Spanish (Latin American)', 'el', 'Back to native SOAP', 'Volver al SOAP nativo'),
('Spanish (Latin American)', 'el', 'New note', 'Nota nueva'),
('Spanish (Latin American)', 'el', 'Saving...', 'Guardando...'),
('Spanish (Latin American)', 'el', 'Saved.', 'Guardada.'),
('Spanish (Latin American)', 'el', 'Could not save the SOAP note. Please try again.', 'No se pudo guardar la nota SOAP. Intente de nuevo.'),
('Spanish (Latin American)', 'el', 'This note still contains [VERIFY: ...] markers. Save anyway?', 'Esta nota aún contiene marcadores [VERIFY: ...]. ¿Guardar de todos modos?'),
('Spanish (Latin American)', 'el', 'You have unsaved changes. Leave without saving?', 'Tiene cambios sin guardar. ¿Salir sin guardar?'),
('Spanish (Latin American)', 'el', 'Clear', 'Borrar'),
('Spanish (Latin American)', 'el', 'Answers come only from this patient''s chart and cite their source section.', 'Las respuestas provienen solo de la ficha de este paciente y citan su sección de origen.'),
('Spanish (Latin American)', 'el', 'Subjective findings...', 'Hallazgos subjetivos...'),
('Spanish (Latin American)', 'el', 'Objective findings...', 'Hallazgos objetivos...'),
('Spanish (Latin American)', 'el', 'Assessment / diagnosis...', 'Evaluación / diagnóstico...'),
('Spanish (Latin American)', 'el', 'Plan / follow-up...', 'Plan / seguimiento...'),
('Spanish (Latin American)', 'el', 'The SOAP note could not be found.', 'No se pudo encontrar la nota SOAP.'),
-- M7 hardening: rate limits & unified error contract
('Spanish (Latin American)', 'el', 'Rate Limits', 'Límites de Solicitudes'),
('Spanish (Latin American)', 'el', 'Draft generation per minute (0 = unlimited)', 'Borradores por minuto (0 = ilimitado)'),
('Spanish (Latin American)', 'el', 'Chat questions per minute (0 = unlimited)', 'Preguntas de chat por minuto (0 = ilimitado)'),
('Spanish (Latin American)', 'el', 'Audio transcriptions per minute (0 = unlimited)', 'Transcripciones de audio por minuto (0 = ilimitado)'),
('Spanish (Latin American)', 'el', 'Per-user limits over a rolling 60-second window. Exceeding a limit returns HTTP 429 (metadata-only audit). Set 0 to disable a limit.', 'Límites por usuario en una ventana móvil de 60 segundos. Al superar un límite se devuelve HTTP 429 (auditoría solo con metadatos). Use 0 para deshabilitar un límite.'),
('Spanish (Latin American)', 'el', 'Too many requests. Please wait a few seconds and try again.', 'Demasiadas solicitudes. Esperá unos segundos e intentá de nuevo.'),
('Spanish (Latin American)', 'el', 'An unexpected server error occurred.', 'Ocurrió un error inesperado del servidor.'),
('Spanish (Latin American)', 'el', 'Method not allowed.', 'Método no permitido.'),
-- Pause/resume dictation: new toolbar/status strings
('Spanish (Latin American)', 'el', 'Pause', 'Pausar'),
('Spanish (Latin American)', 'el', 'Resume', 'Continuar'),
('Spanish (Latin American)', 'el', 'Recording paused. Press Continue to resume.', 'Grabación en pausa. Presioná Continuar para reanudar.'),
('Spanish (Latin American)', 'el', 'Pause is not supported in this browser. The recording continues without pausing.', 'Pausar no está disponible en este navegador. La grabación continúa sin pausa.'),
-- Patient context builder: English source strings rendered through xl() (USA Hospital base).
-- These are the section headers, labels and statuses embedded in the AI prompt context;
-- OpenEMR translates them to Spanish for Spanish-locale sessions via this script.
('Spanish (Latin American)', 'el', 'PATIENT CLINICAL CONTEXT', 'CONTEXTO CLÍNICO DEL PACIENTE'),
('Spanish (Latin American)', 'el', 'PATIENT PROFILE', 'PERFIL DEL PACIENTE'),
('Spanish (Latin American)', 'el', 'Age', 'Edad'),
('Spanish (Latin American)', 'el', 'Sex', 'Sexo'),
('Spanish (Latin American)', 'el', 'years', 'años'),
('Spanish (Latin American)', 'el', 'Age not recorded', 'Edad no registrada'),
('Spanish (Latin American)', 'el', 'Unknown age', 'Edad desconocida'),
('Spanish (Latin American)', 'el', 'Female', 'Femenino'),
('Spanish (Latin American)', 'el', 'Male', 'Masculino'),
('Spanish (Latin American)', 'el', 'Not specified', 'No especificado'),
('Spanish (Latin American)', 'el', 'KNOWN ALLERGIES', 'ALERGIAS CONOCIDAS'),
('Spanish (Latin American)', 'el', 'No active allergies recorded.', 'Sin alergias registradas activas.'),
('Spanish (Latin American)', 'el', 'Severity', 'Severidad'),
('Spanish (Latin American)', 'el', 'ACTIVE PROBLEMS', 'PROBLEMAS ACTIVOS'),
('Spanish (Latin American)', 'el', 'No active medical problems recorded.', 'Sin problemas médicos activos registrados.'),
('Spanish (Latin American)', 'el', 'Onset', 'Inicio'),
('Spanish (Latin American)', 'el', 'CURRENT MEDICATIONS', 'MEDICACIÓN ACTUAL'),
('Spanish (Latin American)', 'el', 'No active medications recorded.', 'Sin medicación activa registrada.'),
('Spanish (Latin American)', 'el', 'Prescribed', 'Recetado'),
('Spanish (Latin American)', 'el', 'Since', 'Desde'),
('Spanish (Latin American)', 'el', 'HISTORY AND HABITS', 'ANTECEDENTES Y HÁBITOS'),
('Spanish (Latin American)', 'el', 'Tobacco', 'Tabaco'),
('Spanish (Latin American)', 'el', 'Alcohol', 'Alcohol'),
('Spanish (Latin American)', 'el', 'Coffee', 'Café'),
('Spanish (Latin American)', 'el', 'Physical activity', 'Actividad física'),
('Spanish (Latin American)', 'el', 'Sleep', 'Sueño'),
('Spanish (Latin American)', 'el', 'Recreational drugs', 'Drogas recreativas'),
('Spanish (Latin American)', 'el', 'Hazardous activities', 'Actividades peligrosas'),
('Spanish (Latin American)', 'el', 'Counseling received', 'Consejo recibido'),
('Spanish (Latin American)', 'el', 'Additional history', 'Antecedentes adicionales'),
('Spanish (Latin American)', 'el', 'Mother history', 'Antecedentes madre'),
('Spanish (Latin American)', 'el', 'Father history', 'Antecedentes padre'),
('Spanish (Latin American)', 'el', 'Siblings history', 'Antecedentes hermanos'),
('Spanish (Latin American)', 'el', 'Offspring history', 'Antecedentes hijos'),
('Spanish (Latin American)', 'el', 'Spouse history', 'Antecedentes cónyuge'),
('Spanish (Latin American)', 'el', 'Surgical history', 'Antecedentes quirúrgicos'),
('Spanish (Latin American)', 'el', 'current', 'actual'),
('Spanish (Latin American)', 'el', 'quit', 'dejó'),
('Spanish (Latin American)', 'el', 'never', 'nunca'),
('Spanish (Latin American)', 'el', 'not applicable', 'no aplica'),
('Spanish (Latin American)', 'el', 'current smoker', 'fumador actual'),
('Spanish (Latin American)', 'el', 'former smoker', 'ex fumador'),
('Spanish (Latin American)', 'el', 'never smoker', 'nunca fumó'),
('Spanish (Latin American)', 'el', 'packs/day', 'paquetes/día'),
('Spanish (Latin American)', 'el', 'Appendectomy', 'Apendicectomía'),
('Spanish (Latin American)', 'el', 'Cholecystectomy', 'Colecistectomía'),
('Spanish (Latin American)', 'el', 'Hernia repair', 'Reparación de hernia'),
('Spanish (Latin American)', 'el', 'Hysterectomy', 'Histerectomía'),
('Spanish (Latin American)', 'el', 'Heart surgery', 'Cirugía cardíaca'),
('Spanish (Latin American)', 'el', 'Cataract surgery', 'Cirugía de cataratas'),
('Spanish (Latin American)', 'el', 'Tonsillectomy', 'Tonsilectomía'),
('Spanish (Latin American)', 'el', 'RECENT VITAL SIGNS', 'SIGNOS VITALES RECIENTES'),
('Spanish (Latin American)', 'el', 'BP', 'PA'),
('Spanish (Latin American)', 'el', 'Pulse', 'Pulso'),
('Spanish (Latin American)', 'el', 'bpm', 'lpm'),
('Spanish (Latin American)', 'el', 'Temp', 'Temp'),
('Spanish (Latin American)', 'el', 'RR', 'FR'),
('Spanish (Latin American)', 'el', 'rpm', 'rpm'),
('Spanish (Latin American)', 'el', 'SpO2', 'SatO2'),
('Spanish (Latin American)', 'el', 'BMI', 'IMC'),
('Spanish (Latin American)', 'el', 'PREVIOUS ENCOUNTERS (SOAP)', 'CONSULTAS Y EVOLUCIONES PREVIAS (SOAP)'),
('Spanish (Latin American)', 'el', 'PREVIOUS ENCOUNTERS', 'CONSULTAS Y EVOLUCIONES PREVIAS'),
('Spanish (Latin American)', 'el', 'Encounter of', 'Consulta del'),
('Spanish (Latin American)', 'el', 'Encounter', 'Consulta'),
('Spanish (Latin American)', 'el', 'Subjective', 'Subjetivo'),
('Spanish (Latin American)', 'el', 'Objective', 'Objetivo'),
('Spanish (Latin American)', 'el', 'Assessment', 'Evaluación'),
('Spanish (Latin American)', 'el', 'Plan', 'Plan'),
('Spanish (Latin American)', 'el', 'RECENT LABORATORY RESULTS', 'RESULTADOS DE LABORATORIO RECIENTES'),
('Spanish (Latin American)', 'el', 'Study', 'Estudio'),
('Spanish (Latin American)', 'el', 'Ref', 'Ref'),
('Spanish (Latin American)', 'el', 'ABNORMAL', 'ALTERADO'),
('Spanish (Latin American)', 'el', 'CLINICAL NOTES', 'NOTAS CLÍNICAS'),
('Spanish (Latin American)', 'el', 'Clinical note', 'Nota clínica'),
('Spanish (Latin American)', 'el', 'today', 'hoy'),
('Spanish (Latin American)', 'el', 'yesterday', 'ayer'),
('Spanish (Latin American)', 'el', 'days ago', 'días atrás'),
('Spanish (Latin American)', 'el', 'month ago', 'mes atrás'),
('Spanish (Latin American)', 'el', 'months ago', 'meses atrás'),
('Spanish (Latin American)', 'el', 'year ago', 'año atrás'),
('Spanish (Latin American)', 'el', 'years ago', 'años atrás'),
-- Settings page: patient preview lookup now accepts the external ID (pubpid).
('Spanish (Latin American)', 'el', 'Patient ID (PID) / External ID:', 'ID del Paciente (PID) / ID Externo:'),

-- Settings page — Microphone test (live browser capture) and microphone-to-Whisper recording
('Spanish (Latin American)', 'el', 'Microphone Test', 'Prueba de Micrófono'),
('Spanish (Latin American)', 'el', 'Verifies that this browser captures your voice before you dictate. The audio is analyzed locally in the browser and is never uploaded from this panel.', 'Verifica que este navegador capte su voz antes de dictar. El audio se analiza localmente en el navegador y nunca se sube desde este panel.'),
('Spanish (Latin American)', 'el', 'Input device', 'Dispositivo de entrada'),
('Spanish (Latin American)', 'el', 'Refresh', 'Actualizar'),
('Spanish (Latin American)', 'el', 'The selected device is remembered for AI dictation in this browser.', 'El dispositivo seleccionado se recuerda para el dictado con IA en este navegador.'),
('Spanish (Latin American)', 'el', 'Start Microphone Test', 'Iniciar Prueba de Micrófono'),
('Spanish (Latin American)', 'el', 'Stop Test', 'Detener Prueba'),
('Spanish (Latin American)', 'el', 'Live level', 'Nivel en vivo'),
('Spanish (Latin American)', 'el', 'Active device:', 'Dispositivo activo:'),
('Spanish (Latin American)', 'el', 'Requesting microphone access...', 'Solicitando acceso al micrófono...'),
('Spanish (Latin American)', 'el', 'Listening... speak now.', 'Escuchando... hable ahora.'),
('Spanish (Latin American)', 'el', 'Listening, signal detected:', 'Escuchando, señal detectada:'),
('Spanish (Latin American)', 'el', 'Microphone test stopped.', 'Prueba de micrófono detenida.'),
('Spanish (Latin American)', 'el', 'Saved. AI dictation will use this microphone in this browser.', 'Guardado. El dictado con IA usará este micrófono en este navegador.'),
('Spanish (Latin American)', 'el', 'Microphone permission denied. Please allow access and try again.', 'Permiso de micrófono denegado. Por favor permita el acceso e intente nuevamente.'),
('Spanish (Latin American)', 'el', 'No microphone was found on this device.', 'No se encontró ningún micrófono en este dispositivo.'),
('Spanish (Latin American)', 'el', 'Could not access the microphone.', 'No se pudo acceder al micrófono.'),
('Spanish (Latin American)', 'el', 'Audio capture is not supported in this browser.', 'La captura de audio no es compatible con este navegador.'),
('Spanish (Latin American)', 'el', 'Microphone', 'Micrófono'),
('Spanish (Latin American)', 'el', 'Record from Microphone', 'Grabar desde el Micrófono'),
('Spanish (Latin American)', 'el', 'Stop Recording', 'Detener Grabación'),
('Spanish (Latin American)', 'el', 'System default', 'Predeterminado del sistema')
);
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
