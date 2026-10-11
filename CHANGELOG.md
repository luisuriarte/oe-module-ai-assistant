# Changelog

All notable changes to **oe-module-ai-assistant**.

## [0.1.0] — M7 hardening complete (unreleased)

### Added
- **Rate limiting (M7)** — `Security\RateLimiter`:
  - New module table `oe_ai_assistant_rate_limits` (user × bucket × 60-second window) created by
    `install.sql` and `upgrade.sql`, dropped by `uninstall.sql`.
  - Per-user ceilings enforced on the three provider-bound endpoints:
    `DraftController::createDraft`, `ChatController::chat`, `TranscribeController::submit`.
  - Exceeding a limit returns **HTTP 429 + `Retry-After`** before any provider/Whisper
    round-trip, and is audited (`status = 'blocked'`, `error_code = 'rate_limited'`).
  - `0` on a setting disables that limit (defaults: 6 drafts, 10 chat questions, 4
    transcriptions per minute).
  - Expired windows are pruned opportunistically on every check; counters are never an
    ENUM so new buckets never need a schema change; a store failure fails **open**
    (request proceeds) and is logged.
  - New **Rate Limits** section on the settings page with the three limits and help text.
- **Unified JSON error contract (M7)**: routing-level and transcribe errors now return a
  translatable `error` plus a fixed `error_type`/`error_code`; `public/index.php` converts
  any uncaught `Throwable` into a JSON 500 that never leaks the exception or provider payload.
- **Z.ai (GLM) provider** — new `Provider\Adapter\ZaiAdapter` extending `OpenAiAdapter`
  (OpenAI-compatible). Selectable as `zai` on the settings page with defaults
  `https://api.z.ai/api/paas/v4`, model `glm-4.5-flash`, temperature `0.2`, max tokens `4096`;
  API key stored encrypted as `zai_api_key`.
- **Microphone test (settings)** — new **Microphone Test** card that lists the browser
  input devices (`enumerateDevices`), shows a live level meter (Web Audio `AnalyserNode`)
  and displays the active device label, so an administrator can confirm the correct
  microphone before dictating. The chosen device is stored in `localStorage` and reused by
  the dictation toolbar (`soap-ai.js`), with an automatic fallback to the system default.
  The dictation toolbar also exposes an inline **microphone selector** (hidden while
  recording) so any user can pick the input device without admin access.
  A **Record from Microphone** button in the Whisper test bench records a clip and feeds
  the existing test-mode `transcribe_submit` flow, validating the full browser → Whisper
  chain without needing an external audio file.
- **Chat can see the note open in the editor** — `ChatController` forwards the four SOAP
  fields currently in the editor (which may be unsaved) with each question; the context
  builder renders them as a new, prioritized `CURRENT NOTE` section (citable as
  `[[cite:nota_actual]]`) and excludes that encounter from the previous-encounters list so
  the same note is not duplicated. The text is redacted with the same best-effort rules as
  stored notes and bounded in size per field and overall.
- **SOAP prompt customization** — the draft system prompt now embeds a built-in **section
  content guidance** block (what belongs in each of `subjective`, `objective`, `assessment`,
  `plan`; the assessment line restricts the model to the clinician's own diagnoses). The
  `soap_section_guidance` setting overrides it (empty = built-in default) in the same
  position, and per-provider `<provider>_prompt_extra` (e.g. `openai_prompt_extra`) appends
  optional instructions for the active provider, always subordinate to the clinical safety
  rules. The default is a single English code constant
  (`SoapDraftGenerator::DEFAULT_SECTION_GUIDANCE`), never passed through `xl()` and not stored
  in `lang_custom.sql`; the settings page shows it as a read-only preview. No schema change
  (key/value settings table).
- `LICENSE` (GNU GPL v3, mirrored from the OpenEMR repository) and this `CHANGELOG.md`.

### Changed
- `TranscribeController` (`submit`/`status`) maps every fixed code to a translatable message
  via `respondError()`; the client keeps the machine code and the user gets proper text.
- `soap-ai.js` distinguishes a `rate_limited` 429 from a busy Whisper worker: a rate-limited
  upload is reported to the user instead of entering the automatic retry loop.
- Module tests extended with `tests/RateLimiterTest.php` (fixed windows, rollover, isolation,
  disabled paths, bucket sanitisation, pruning — 517 assertions, standalone, no DB).
- Docs updated to M7: `README.md` / `README_es.md`, `COMPATIBILITY.md` / `COMPATIBILITY_es.md`.
- Removed orphaned assets from the legacy UI (`ai-dictation.js`, `ai-chat.js`) that were no
  longer referenced by any live template or controller.
- `tests/SoapDraftSimulationTest.php` extended with sanitizer coverage, the 2000/2001
  character limit, the reading-path cap, an identical system prompt on the invalid-JSON
  retry, and resilience of the final output-format block against provider-injected
  `OUTPUT FORMAT` / fake JSON keys.
- **Z.ai default model** is now `glm-4.5-flash` (was `glm-4-flash`) in the adapter constant,
  `SettingsManager::getDefaults()`, the settings placeholder and the docs; values already
  stored in settings are untouched. `tests/ProviderSimulationTest.php` extended for the
  thinking parameter, the one-shot rejection fallback and the empty-answer diagnostics.

### Fixed
- **PatientContextBuilder allergy fallback query referenced a nonexistent `lists.severity`
  column** (caught by `tests/SchemaAudit.php` against `sql/database.sql`). The column is
  `lists.severity_al`; the value is now resolved to a readable `severity_ccda` title via the
  native `ListService` when available.
- **Raw SQL error text could corrupt JSON responses** — `ADOConnection::outp()` echoes the
  driver message when a query fails and `ADODB_OUTP` is undefined (the OpenEMR default), so
  a controller could catch the exception while the echoed text still broke the JSON body
  (browser: `Unexpected token ... is not valid JSON`). `public/index.php` now routes that
  output to the system log via `$GLOBALS['ADODB_OUTP']`.
- **Chat reported "no information about the plan" for a note being dictated** — answers were
  grounded only in saved `form_soap` rows, so text typed in the editor but not yet saved was
  invisible to the model. The editor now sends the current S/O/A/P with each question (see
  the `CURRENT NOTE` section above).
- **SOAP prompt customization hardening** — `soap_section_guidance` and every
  `<provider>_prompt_extra` are now sanitized (newlines normalized, control characters
  removed, trimmed) and capped at **2000 characters** on both the save path
  (`SettingsController`) and the prompt reading path (`SoapDraftGenerator`); an oversized
  value is not saved and the settings page reports it while the other settings are kept. The
  default guidance now instructs the model to keep the key and set the value to an empty
  string when a section has no information, the precedence clause explicitly covers the
  output format and forbids restating the instructions, and the output-format block is no
  longer numbered (`7.` removed) so the numbered list stays only the safety rules 1-6.
- **Z.ai (GLM) reasoning could consume the whole output budget** — GLM-4.5-Flash is a hybrid
  reasoning model, so with thinking on it could spend the (small) output budget on
  `reasoning_content` before emitting the JSON SOAP draft, producing an empty/truncated answer
  and a wasted correction retry. `ZaiAdapter` now always sends `thinking = {"type":"disabled"}`,
  retries exactly once without the parameter when the endpoint rejects it with an HTTP 400 that
  blames `thinking` (logging a warning), and on an empty answer logs metadata only (`provider`,
  `model`, `finish_reason`, `reasoning_content_present`) — never the content or the reasoning
  text. A `length` finish reason now raises a clear invalid-response error (the model exhausted
  its output token budget) instead of a generic invalid-JSON failure.

## Milestone history

| Milestone | Scope | Status |
|---|---|---|
| M0 | Inspection report, version detection, SOAP hook analysis | Done |
| M1 | Module skeleton, settings page, install/uninstall | Done |
| M2 | TranscriptionClient, upload endpoint, validations | Done |
| M3 | Provider layer (OpenAI / Anthropic / Gemini / Grok / Z.ai), encrypted keys | Done |
| M4 | PatientContextBuilder, de-identification | Done |
| M5 | Layer 1 UI: dictation, transcript editor, SOAP field fill | Done |
| M6 | Layer 2: patient chat panel | Done |
| M7 | Hardening: audit, rate limits, error handling, final docs | Done |