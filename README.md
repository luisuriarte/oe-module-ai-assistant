# oe-module-ai-assistant

An OpenEMR custom module that helps clinicians write SOAP notes with AI-assisted dictation and chart-aware conversation.

**Minimum OpenEMR version:** 8.2.0  
**PHP minimum:** 8.2.0  
**Current milestone:** M1 — Module Skeleton

---

## Features

### Layer 1 — Dictation to SOAP Draft
1. The clinician opens a patient encounter and selects the **SOAP** form.
2. An **AI Dictation** control appears (record / stop / discard).
3. Audio is transcribed locally by a **whisper.cpp** server — audio never leaves the host.
4. The clinician reviews and edits the transcript.
5. Click **Generate Draft** — the transcript plus a minimised patient chart context are sent to the configured AI provider.
6. The four SOAP fields (*Subjective, Objective, Assessment, Plan*) are filled automatically, with a visible banner: _"AI-generated draft — review before saving"_.
7. Nothing is saved to the chart until the clinician clicks the standard **Save** button.

### Layer 2 — Patient Chat Panel *(optional, enable in settings)*
- A side panel scoped to the current patient.
- Answers questions using only the patient's chart data.
- Cites which section each fact comes from.
- Conversation history is kept in-session only; not persisted by default.

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
│   ├── Service/SoapDraftService    Transcript + context → validated S/O/A/P JSON
│   ├── Service/ChatService         Patient-scoped conversation
│   ├── Controller/                 Session + CSRF + ACL-protected endpoints
│   ├── Audit/AuditLogger           Metadata-only audit trail
│   └── EventListener/              ScriptFilterEvent → injects JS into SOAP form
│
├── public/                         Web-accessible; every file enforces session + ACL
│   ├── index.php                   Front controller / router
│   └── assets/js|css               Dictation control and chat panel widgets
│
├── templates/settings.php          Admin settings form (PHP template)
└── sql/
    ├── install.sql                 CREATE TABLE oe_ai_assistant_audit / _settings
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
| AI provider | OpenAI / Anthropic / Gemini / Grok (xAI) API key |

### Steps

1. **Copy** the module folder to OpenEMR:
   ```
   interface/modules/custom_modules/oe-module-ai-assistant/
   ```

2. In OpenEMR go to **Admin → Modules → Manage Modules** → tab **Available** → find _AI Assistant_ → click **Install + Enable**.

3. The Module Manager will:
   - Create tables `oe_ai_assistant_audit` and `oe_ai_assistant_settings`
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
| `whisper_max_audio_sec` | `300` | Maximum audio length (5 min) |
| `active_provider` | `openai` | `openai` / `anthropic` / `gemini` / `grok` |
| `openai_base_url` | `https://api.openai.com/v1` | Supports self-hosted / compatible servers |
| `openai_model` | `gpt-4o` | Model name (free text) |
| `openai_temperature` | `0.2` | 0.0 – 2.0 |
| `openai_max_tokens` | `2048` | Max output tokens |
| `openai_api_key` | — | Encrypted at rest; never logged |
| `anthropic_model` | `claude-opus-4-5` | |
| `anthropic_api_key` | — | Encrypted at rest |
| `gemini_model` | `gemini-2.0-flash` | |
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
| `audit_retention_days` | `90` | Days to keep audit log rows |
| `debug_log_content` | `0` | **Off in production.** Logs prompts/responses |

---

## Security

- Every endpoint requires a valid OpenEMR session, CSRF token, and ACL check.
- **Consent gate:** patient data is never transmitted to an AI provider until an administrator ticks the disclosure acknowledgement in settings. Enforced server-side by `ConsentGate` inside `DraftController::createDraft` and `TranscribeController::submit`, before any context is assembled. Denials are audited with `status = 'blocked'`.
- Audio is held as a temporary file only for the duration of the transcription request, then deleted.
- API keys are encrypted at rest using OpenEMR's `CryptoGen` (AES-256); never written to logs or returned to the browser.
- The whisper.cpp server is bound to `localhost` and is never exposed to the network.
- No direct patient identifiers (name, DOB, address, national ID, insurance number) are sent to AI providers. The context includes age and sex only.
- Audit table records metadata only: user, patient ID, encounter ID, action, provider, model, status, token counts, duration, timestamp.
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
| M0 | Inspection report, version detection, SOAP hook analysis | ✅ Done |
| M1 | Module skeleton, settings page, install/uninstall | ✅ Done |
| M2 | TranscriptionClient, upload endpoint, validations | ⏳ Next |
| M3 | Provider layer (OpenAI / Anthropic / Gemini / Grok), encrypted keys | Pending |
| M4 | PatientContextBuilder, de-identification | Pending |
| M5 | Layer 1 UI: dictation, transcript editor, SOAP field fill | Pending |
| M6 | Layer 2: patient chat panel | Pending |
| M7 | Hardening: audit, rate limits, error handling, final docs | Pending |

---

## Compatibility

See [COMPATIBILITY.md](COMPATIBILITY.md) for the full API compatibility table between OpenEMR 8.2.0 and 8.4.1.

---

## License

GNU General Public License 3 — see [LICENSE](https://github.com/openemr/openemr/blob/master/LICENSE).
