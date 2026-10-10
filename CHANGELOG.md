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
  `https://api.z.ai/api/paas/v4`, model `glm-4-flash`, temperature `0.2`, max tokens `4096`;
  API key stored encrypted as `zai_api_key`.
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

### Fixed
- **PatientContextBuilder allergy fallback query referenced a nonexistent `lists.severity`
  column** (caught by `tests/SchemaAudit.php` against `sql/database.sql`). The column is
  `lists.severity_al`; the value is now resolved to a readable `severity_ccda` title via the
  native `ListService` when available.

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