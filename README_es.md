# oe-module-ai-assistant

Módulo personalizado de OpenEMR que ayuda a los médicos a redactar notas SOAP mediante dictado asistido por IA y conversación contextualizada con la historia clínica del paciente.

**Versión mínima de OpenEMR:** 8.2.0  
**PHP mínimo:** 8.2.0  
**Hito actual:** M1 — Esqueleto del Módulo

---

## Funcionalidades

### Capa 1 — Dictado a Borrador SOAP
1. El médico abre una consulta del paciente y selecciona el formulario **SOAP**.
2. Aparece un control de **Dictado IA** (grabar / detener / descartar).
3. El audio se transcribe localmente mediante un servidor **whisper.cpp** — el audio nunca sale del servidor.
4. El médico revisa y corrige la transcripción.
5. Al hacer clic en **Generar Borrador**, la transcripción más un contexto minimizado de la historia clínica se envían al proveedor de IA configurado.
6. Los cuatro campos SOAP (*Subjetivo, Objetivo, Evaluación, Plan*) se completan automáticamente, con un banner visible: _"Borrador generado por IA — revise antes de guardar"_.
7. Nada se guarda en la historia clínica hasta que el médico haga clic en el botón estándar **Guardar**.

### Capa 2 — Panel de Chat del Paciente *(opcional, habilitar en configuración)*
- Panel lateral con alcance limitado al paciente actual.
- Responde preguntas usando únicamente los datos de la historia clínica del paciente.
- Indica de qué sección proviene cada dato.
- El historial de conversación se mantiene sólo en la sesión actual; no se persiste por defecto.

---

## Arquitectura

```
oe-module-ai-assistant/
├── openemr.bootstrap.php           Registro de listeners de eventos
├── ModuleManagerListener.php       Ciclo de vida: instalar / habilitar / deshabilitar / desinstalar
├── moduleConfig.php                Entrada a la página de configuración
├── info.txt                        Nombre del módulo
├── version.php                     Versión + declaraciones de OpenEMR/PHP mínimos
├── COMPATIBILITY.md                Tabla de compatibilidad 8.2.0 vs 8.4.1
│
├── src/
│   ├── Settings/SettingsManager    Almacén de configuración (CryptoGen para API keys)
│   ├── Transcription/              Cliente HTTP para whisper.cpp
│   ├── Provider/                   AiProviderInterface + adaptadores OpenAI / Anthropic / Gemini
│   ├── Context/PatientContextBuilder  Contexto clínico minimizado y desidentificado
│   ├── Service/SoapDraftService    Transcripción + contexto → JSON S/O/A/P validado
│   ├── Service/ChatService         Conversación con alcance por paciente
│   ├── Controller/                 Endpoints protegidos: sesión + CSRF + ACL
│   ├── Audit/AuditLogger           Registro de auditoría (solo metadatos)
│   └── EventListener/              ScriptFilterEvent → inyecta JS en el formulario SOAP
│
├── public/                         Accesible por web; todos los archivos verifican sesión + ACL
│   ├── index.php                   Controlador frontal / router
│   └── assets/js|css               Controles de dictado y chat
│
├── templates/settings.php          Formulario de configuración (plantilla PHP)
└── sql/
    ├── install.sql                 CREATE TABLE oe_ai_assistant_audit / _settings
    ├── uninstall.sql               DROP TABLE (eliminación limpia)
    └── lang_custom.sql             Traducciones al español (Latinoamérica)
```

---

## Instalación

### Requisitos

| Requisito | Versión |
|---|---|
| OpenEMR | ≥ 8.2.0 |
| PHP | ≥ 8.2.0 (8.3+ en OpenEMR 8.4.x) |
| Servidor whisper.cpp | Corriendo en `http://127.0.0.1:8178` (solo local) |
| Proveedor de IA | Clave API de OpenAI / Anthropic / Gemini |

### Pasos

1. **Copiar** la carpeta del módulo a OpenEMR:
   ```
   interface/modules/custom_modules/oe-module-ai-assistant/
   ```

2. En OpenEMR ir a **Administración → Módulos → Gestionar Módulos** → pestaña **Disponibles** → buscar _AI Assistant_ → hacer clic en **Instalar + Habilitar**.

3. El Administrador de Módulos creará automáticamente:
   - Las tablas `oe_ai_assistant_audit` y `oe_ai_assistant_settings`
   - La sección ACL `ai_assistant` con los objetos `use` y `admin`

4. **Traducciones:** ejecutar `sql/lang_custom.sql` directamente en la base de datos de OpenEMR:
   ```bash
   mysql -u openemr -p openemr < sql/lang_custom.sql
   ```

5. Ir a **Administración → Módulos → Gestionar Módulos** → _AI Assistant_ → **Configurar**.

6. En la página de configuración:
   - Marcar la casilla de **consentimiento legal**.
   - Ingresar la **URL del servidor whisper** (por defecto: `http://127.0.0.1:8178`).
   - Seleccionar el **proveedor de IA** e ingresar la **clave API** correspondiente.
   - Hacer clic en **Guardar Configuración**.

---

## Referencia de Configuración

| Parámetro | Valor por defecto | Descripción |
|---|---|---|
| `whisper_url` | `http://127.0.0.1:8178` | URL base del servidor whisper.cpp |
| `whisper_timeout` | `60` | Tiempo de espera en segundos |
| `whisper_max_audio_sec` | `300` | Duración máxima del audio (5 min) |
| `active_provider` | `openai` | `openai` / `anthropic` / `gemini` |
| `openai_base_url` | `https://api.openai.com/v1` | Compatible con servidores auto-alojados |
| `openai_model` | `gpt-4o` | Nombre del modelo (texto libre) |
| `openai_temperature` | `0.2` | 0.0 – 2.0 |
| `openai_max_tokens` | `2048` | Tokens máximos de salida |
| `openai_api_key` | — | Cifrada en reposo; nunca se registra |
| `anthropic_model` | `claude-opus-4-5` | |
| `anthropic_api_key` | — | Cifrada en reposo |
| `gemini_model` | `gemini-2.0-flash` | |
| `gemini_api_key` | — | Cifrada en reposo |
| `context_num_encounters` | `5` | Consultas SOAP anteriores incluidas en el contexto |
| `context_token_budget` | `4000` | Tokens máximos para el contexto del paciente |
| `context_include_labs` | `0` | Incluir resultados de laboratorio recientes |
| `output_language` | `es` | Idioma del borrador: `es` = Español, `en` = Inglés |
| `chat_enabled` | `0` | Habilitar panel de chat (Capa 2) |
| `audit_retention_days` | `90` | Días de retención del registro de auditoría |
| `debug_log_content` | `0` | **Apagado en producción.** Registra prompts y respuestas |

---

## Seguridad

- Todo endpoint requiere una sesión válida de OpenEMR, token CSRF y verificación ACL.
- El audio se conserva como archivo temporal únicamente durante la transcripción y se elimina inmediatamente después.
- Las claves API se cifran en reposo con `CryptoGen` de OpenEMR (AES-256); nunca se escriben en registros ni se devuelven al navegador.
- El servidor whisper.cpp está vinculado a `localhost` y nunca se expone a la red.
- No se envían identificadores directos del paciente (nombre, fecha de nacimiento, dirección, DNI, número de seguro) a los proveedores de IA. El contexto incluye solo edad y sexo.
- La tabla de auditoría registra únicamente metadatos: usuario, ID de paciente, ID de consulta, acción, proveedor, modelo, estado, conteo de tokens, duración y marca de tiempo.

---

## Permisos ACL

| Sección | Objeto | Propósito |
|---|---|---|
| `ai_assistant` | `use` | Usar dictado y chat |
| `ai_assistant` | `admin` | Acceder a la página de configuración |

---

## Hitos de Desarrollo

| Hito | Descripción | Estado |
|---|---|---|
| M0 | Informe de inspección, detección de versión, análisis del hook SOAP | ✅ Completado |
| M1 | Esqueleto del módulo, página de configuración, instalar/desinstalar | ✅ Completado |
| M2 | TranscriptionClient, endpoint de carga, validaciones | ⏳ Siguiente |
| M3 | Capa de proveedores (OpenAI / Anthropic / Gemini), claves cifradas | Pendiente |
| M4 | PatientContextBuilder, desidentificación | Pendiente |
| M5 | UI Capa 1: dictado, editor de transcripción, llenado de campos SOAP | Pendiente |
| M6 | Capa 2: panel de chat del paciente | Pendiente |
| M7 | Robustecimiento: auditoría, límites de tasa, manejo de errores, docs finales | Pendiente |

---

## Compatibilidad

Ver [COMPATIBILITY_es.md](COMPATIBILITY_es.md) para la tabla completa de compatibilidad entre OpenEMR 8.2.0 y 8.4.1.

---

## Licencia

GNU General Public License 3 — ver [LICENSE](https://github.com/openemr/openemr/blob/master/LICENSE).
