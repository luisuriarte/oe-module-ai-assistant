# oe-module-ai-assistant

An OpenEMR custom module that helps clinicians write SOAP notes with AI-assisted dictation and chart-aware conversation.

**Minimum OpenEMR version:** 8.2.0  
**PHP minimum:** 8.2.0  
**Current milestone:** M7 complete (hardening)

---

## Features

### Layer 1 — Dictation to SOAP Draft
1. The clinician opens a patient encounter and opens the native **SOAP** form.
2. The native form's **AI** button opens the **SOAP with AI** form: a modern editor that reads and writes the exact same `form_soap` row (it is not a new table and not a separately registered form; it is reachable only from that button).
3. Inside the editor an **AI Dictation** control appears (record / stop / discard).
4. Audio is transcribed locally by a **whisper.cpp** server — audio never leaves the host.
5. The clinician reviews and edits the transcript.
6. Click **Generate SOAP Note** — the transcript plus a minimised patient chart context are sent to the configured AI provider.
7. The four SOAP fields (*Subjective, Objective, Assessment, Plan*) are filled automatically, with a visible banner: _"AI-generated draft — review before saving"_.
8. Saving persists to `form_soap` + `forms` (with `formdir = 'soap'`, exactly like the native form) and returns to the native SOAP form. Nothing reaches the chart before that point.

### Layer 2 — Patient Chat Panel *(optional, enable in settings)*
- A side panel scoped to the current patient.
- Answers questions using only the patient's de-identified chart data.
- Cites which chart section each fact comes from, shown as chips under the answer. A section that is not present in the chart is never reported as a source.
- Conversation history lives in the browser tab only: nothing is stored in the database, in the PHP session, or in any browser storage. The history is sent with each question and re-bounded server-side; a client-injected "system" turn is rejected so the model instructions cannot be rewritten.

---

## Architecture

```
oe-module-ai-assistant/
├── openemr.bootstrap.php           Event listeners registration
├── ModuleManagerListener.php       Install / enable / disable / unregister lifecycle
├── moduleConfig.php                Settings page entry (rendered by Module Manager)
├── info.txt                        Module display name
├── version.php                     Version + minimum OpenEMR / PHP declarations
├── COMPATIBILITY.md                API compatibility table 8.2.0 vs 8.4.1
│
├── src/
│   ├── Settings/SettingsManager    Key/value settings store (CryptoGen for API keys)
│   ├── Transcription/              HTTP client for whisper.cpp
│   ├── Provider/                   AiProviderInterface + OpenAI / Anthropic / Gemini / Grok adapters
│   ├── Context/PatientContextBuilder  Minimised, de-identified chart context
│   ├── Draft/SoapDraftGenerator    Transcript + context → validated S/O/A/P JSON
│   ├── Service/ChatService         Patient-scoped conversation
│   ├── Security/ConsentGate        Server-side consent gate for all outbound calls
│   ├── Security/RateLimiter        Per-user 60s windows for provider-bound endpoints
│   ├── Session/                    SessionAccessor, CsrfCompat (8.2.0 / 8.4.1)
│   ├── Provider/Exception/         Fixed error codes, never leaks provider bodies
│   ├── Controller/                 Session + CSRF + ACL-protected endpoints
│   │                               (+ SoapAiFormController: SOAP-AI editor and save into form_soap)
│   ├── Audit/AuditLogger           Metadata-only audit trail + schema self-heal
│   └── EventListener/              ScriptFilterEvent → adds the "AI" button to native SOAP
│
├── public/                         Web-accessible; every file enforces session + ACL
│   ├── index.php                   Front controller / router (includes soap_ai_save)
│   ├── form.php                    SOAP-AI editor page (HTML)
│   └── assets/js|css               SOAP-AI editor, "AI" launcher and styles
│
├── templates/
│   ├── settings.php                Admin settings form (PHP template)
│   └── soap_ai.php                 SOAP-AI editor template
└── sql/
    ├── install.sql                 CREATE TABLE oe_ai_assistant_audit / _settings / _rate_limits
    ├── upgrade.sql                 Idempotent ALTERs + CREATE TABLE IF NOT EXISTS (rate limits)
    ├── uninstall.sql               DROP TABLE (clean removal)
    └── lang_custom.sql             Spanish (Latin American) translations
```

---

## Installation

### Requirements

| Requirement | Version |
|---|---|
| OpenEMR | ≥ 8.2.0 |
| PHP | ≥ 8.2.0 (8.3+ on OpenEMR 8.4.x) |
| whisper.cpp server | Running on `http://127.0.0.1:8178` (local only) |
| Shared temp dir | `sys_get_temp_dir()` must be the same for every PHP worker (flock + job files). With `PrivateTmp=yes` (systemd) or per-pool chroots, point `TMPDIR` at a common path. |
| AI provider | OpenAI / Anthropic / Gemini / Grok (xAI) API key |

### Steps

1. **Copy** the module folder to OpenEMR:
   ```
   interface/modules/custom_modules/oe-module-ai-assistant/
   ```

2. In OpenEMR go to **Admin → Modules → Manage Modules** → tab **Available** → find _AI Assistant_ → click **Install + Enable**.

3. The Module Manager will:
   - Create tables `oe_ai_assistant_audit`, `oe_ai_assistant_settings` and `oe_ai_assistant_rate_limits`
   - Run `sql/upgrade.sql` (idempotent) so existing installs get the current schema
   - Register ACL section `ai_assistant` with objects `use` and `admin`

4. **Translations:** run `sql/lang_custom.sql` directly against your OpenEMR database to install Spanish (Latin American) translations:
   ```bash
   mysql -u openemr -p openemr < sql/lang_custom.sql
   ```

5. Go to **Admin → Modules → Manage Modules** → find _AI Assistant_ → click **Configure**.

6. On the settings page:
   - Acknowledge the **legal consent** checkbox.
   - Enter the **whisper server URL** (default: `http://127.0.0.1:8178`).
   - Select your **AI provider** and enter the corresponding **API key**.
   - Click **Save Settings**.

---

## Configuration Reference

| Setting | Default | Description |
|---|---|---|
| `whisper_url` | `http://127.0.0.1:8178` | whisper.cpp server base URL |
| `whisper_timeout` | `60` | Request timeout in seconds |
| `whisper_max_audio_sec` | `180` | Maximum audio length (3 min) |
| `active_provider` | `openai` | `openai` / `anthropic` / `gemini` / `grok` |
| `openai_base_url` | `https://api.openai.com/v1` | Supports self-hosted / compatible servers |
| `openai_model` | `gpt-4o` | Model name (free text) |
| `openai_temperature` | `0.2` | 0.0 – 2.0 |
| `openai_max_tokens` | `2048` | Max output tokens |
| `openai_api_key` | — | Encrypted at rest; never logged |
| `anthropic_model` | `claude-opus-4-5` | |
| `anthropic_api_key` | — | Encrypted at rest |
| `gemini_model` | `gemini-3.8-flash` | `temperature` is omitted for Gemini 3.x |
| `gemini_api_key` | — | Encrypted at rest |
| `grok_base_url` | `https://api.x.ai/v1` | xAI endpoint (OpenAI-compatible) |
| `grok_model` | `grok-4.7` | Any model id served to your key |
| `grok_temperature` | `0.2` | 0.0 – 2.0 |
| `grok_max_tokens` | `2048` | Max output tokens |
| `grok_api_key` | — | Encrypted at rest |
| `context_num_encounters` | `5` | Past SOAP encounters sent as context |
| `context_token_budget` | `4000` | Max tokens for patient context |
| `context_include_labs` | `0` | Send recent lab results |
| `output_language` | `es` | Draft language: `es` = Spanish, `en` = English |
| `chat_enabled` | `0` | Enable Layer 2 chat panel |
| `provider_allow_private_hosts` | `0` | Allow loopback/private API endpoints (self-hosted models) |
| `audit_retention_days` | `90` | Days to keep audit log rows |
| `debug_log_content` | `0` | **Off in production.** Logs prompts/responses |
| `rate_limit_draft_per_min` | `6` | Max draft generations per user per 60 s (`0` = unlimited) |
| `rate_limit_chat_per_min` | `10` | Max chat questions per user per 60 s (`0` = unlimited) |
| `rate_limit_transcribe_per_min` | `4` | Max transcriptions per user per 60 s (`0` = unlimited) |

---

## Security

- Every endpoint requires a valid OpenEMR session, CSRF token, and ACL check.
- **Consent gate:** patient data is never transmitted to an AI provider until an administrator ticks the disclosure acknowledgement in settings. Enforced server-side by `ConsentGate` inside `DraftController::createDraft` and `TranscribeController::submit`, before any context is assembled. Denials are audited with `status = 'blocked'`.
- **Rate limits (M7):** draft generation, chat questions and transcription are capped per authenticated user over a rolling 60-second window (`rate_limit_*_per_min`, `0` = unlimited). Exceeding a limit returns HTTP 429 + `Retry-After` before any provider or Whisper round-trip; the denial is audited (`status = 'blocked'`, `error_code = 'rate_limited'`). The counter table is pruned automatically, no schema change is ever needed to add a bucket, and a store failure fails open (the request proceeds) while being logged.
- Audio is held as a temporary file only for the duration of the transcription request, then deleted.
- API keys are encrypted at rest using OpenEMR's `CryptoGen` (AES-256); never written to logs or returned to the browser.
- The whisper.cpp server is bound to `localhost` and is never exposed to the network.
- No direct patient identifiers (name, DOB, address, national ID, insurance number) are sent to AI providers. The context includes age and sex only.
- Audit table records metadata only: user, patient ID, encounter ID, action, provider, model, status, token counts, duration, timestamp. `action`/`status` are `VARCHAR(32)`, not an ENUM, so new values do not need an ALTER.
- If audit inserts fail, the logger stops sending payload content and increments an in-request counter; the settings page shows a warning with the failure count.
- Transcription uses an exclusive non-blocking `flock()` on a temp file hashed by the whisper URL. The lock file is never unlinked (unlinking races with other workers on the path); workers waiting on it must share the same temp directory.
- The patient chat panel (Layer 2) answers questions only from the de-identified chart context and tags every sourced claim with a `[[cite:<section>]]` marker that the server validates against the sections actually present in the chart. The panel never issues diagnoses or treatment changes, and it disappears from the page when `chat_enabled` is off.
- `debug_log_content` defaults to `0`. Keep it off outside development: turning it on writes prompts and responses to logs.

---

## ACL Permissions

| Section | Object | Purpose |
|---|---|---|
| `ai_assistant` | `use` | Use dictation and chat features |
| `ai_assistant` | `admin` | Access module settings page |

---

## Development Milestones

| Milestone | Description | Status |
|---|---|---|
| M0 | Inspection report, version detection, SOAP hook analysis | Done |
| M1 | Module skeleton, settings page, install/uninstall | Done |
| M2 | TranscriptionClient, upload endpoint, validations | Done |
| M3 | Provider layer (OpenAI / Anthropic / Gemini / Grok), encrypted keys | Done |
| M4 | PatientContextBuilder, de-identification | Done |
| M5 | Layer 1 UI: dictation, transcript editor, SOAP field fill | Done |
| M6 | Layer 2: patient chat panel | Done |
| M7 | Hardening: audit, rate limits, error handling, final docs | Done |

---

## Compatibility

See [COMPATIBILITY.md](COMPATIBILITY.md) for the full API compatibility table between OpenEMR 8.2.0 and 8.4.1.

---

## License

GNU General Public License 3 — see [LICENSE](LICENSE) (mirrors [the official text in the OpenEMR repository](https://github.com/openemr/openemr/blob/master/LICENSE)).
