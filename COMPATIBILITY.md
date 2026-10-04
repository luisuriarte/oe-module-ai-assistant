# Compatibility Table — oe-module-ai-assistant

Minimum supported OpenEMR: **8.2.0**  
PHP minimum: **8.2.0**

Last updated: M1

## Class / Method / Event Compatibility

| Item | Namespace | 8.2.0 | 8.4.1 | Notes |
|---|---|---|---|---|
| `ScriptFilterEvent` | `OpenEMR\Events\Core` | ✅ | ✅ | Identical. `EVENT_NAME = "html.head.script.filter"` |
| `TemplatePageEvent` | `OpenEMR\Events\Core` | ✅ | ✅ | Identical. `CONTEXT_ARGUMENT_SCRIPT_NAME` constant present |
| `StyleFilterEvent` | `OpenEMR\Events\Core` | ✅ | ✅ | Identical |
| `EncounterMenuEvent` | `OpenEMR\Events\Encounter` | ✅ | ✅ | Identical |
| `MenuEvent` | `OpenEMR\Menu` | ✅ | ✅ | Identical |
| `PatientMenuEvent` | `OpenEMR\Menu` | ✅ | ✅ | Identical |
| `Header::setupHeader()` | `OpenEMR\Core` | ✅ | ✅ | Fires `ScriptFilterEvent`; `pageName = basename($_SERVER['SCRIPT_NAME'])` of the HTTP entry script |
| SOAP new-note entry script | n/a | ✅ | ✅ | `encounter/load_form.php?formname=soap` → `pageName="load_form.php"` (**not** `new.php`) |
| SOAP view/edit entry script | n/a | ✅ | ✅ | `encounter/view_form.php?formname=soap&id=N` → `pageName="view_form.php"` (**not** `view.php`) |
| SOAP template path | n/a | ✅ | ✅ | `interface/forms/soap/templates/soap_form.twig` — reached via FormLocator, not directly |
| SOAP `formname` GET param | n/a | ✅ | ✅ | Must equal `"soap"` exactly; validated `/^[a-zA-Z0-9_-]{1,64}$/` before use |
| SOAP field `subjective` | n/a | ✅ | ✅ | `<textarea name="subjective">` identical |
| SOAP field `objective` | n/a | ✅ | ✅ | `<textarea name="objective">` identical |
| SOAP field `assessment` | n/a | ✅ | ✅ | `<textarea name="assessment">` identical |
| SOAP field `plan` | n/a | ✅ | ✅ | `<textarea name="plan">` identical |
| `CryptoGen::encryptStandard()` | `OpenEMR\Common\Crypto` | ✅ | ✅ | Identical signature |
| `CryptoGen::decryptStandard()` | `OpenEMR\Common\Crypto` | ✅ | ✅ | Identical signature |
| `SessionWrapperFactory::getInstance()->getActiveSession()` | `OpenEMR\Common\Session` | ✅ | ✅ | Returns `SessionInterface`. Ensures active session |
| `SessionWrapperFactory::getInstance()->isSessionActive()` | `OpenEMR\Common\Session` | ✅ | ✅ | Identical |
| `OEGlobalsBag::get()` | `OpenEMR\Core` | ✅ | ✅ | Identical |
| `OEGlobalsBag::getWebRoot()` | `OpenEMR\Core` | ✅ | ✅ | Identical |
| `OEGlobalsBag::getKernel()` | `OpenEMR\Core` | ✅ | ✅ | Identical |
| `OEGlobalsBag::filter()` | `OpenEMR\Core` | ❌ | ✅ | **8.4.1 only** — not used in this module |
| `VitalsService::getVitalsForPatientEncounter()` | `OpenEMR\Services` | ✅ | ✅ | Identical signature: `($encounter_id)` |
| `VitalsService::getVitalsHistoryForPatient()` | `OpenEMR\Services` | ✅ | ✅ | Identical signature: `($pid)` |
| `VitalsService::search()` | `OpenEMR\Services` | ✅ | ✅ | Identical signature |
| `PatientService::getAll()` | `OpenEMR\Services` | ✅ | ✅ | Identical signature: `(array $search = [], ...)` |
| `PatientService::getFreshPid()` | `OpenEMR\Services` | ✅ | ✅ | Identical |
| `EncounterService::getOneByPidEid()` | `OpenEMR\Services` | ✅ | ✅ | Identical signature: `($pid, $encounter_id)` |
| `EncounterService::getMostRecentEncounterForPatient()` | `OpenEMR\Services` | ✅ | ✅ | Identical signature: `($pid): ?array` |
| `PatientIssuesService::getActiveIssues()` | `OpenEMR\Services` | ✅ | ✅ | Identical signature: `(int $pid): ProcessingResult` |
| `PatientIssuesService::getOneById()` | `OpenEMR\Services` | ✅ | ✅ | Identical signature: `($issueId)` |
| `AclMain::aclCheckCore()` | `OpenEMR\Common\Acl` | ✅ | ✅ | Identical. Checks `(section, value, user, return_val)` |
| `AclExtended::addObjectSectionAcl()` | `OpenEMR\Common\Acl` | ✅ | ✅ | Identical |
| `AclExtended::addObjectAcl()` | `OpenEMR\Common\Acl` | ✅ | ✅ | Identical |
| `CsrfUtils::verifyCsrfToken()` | `OpenEMR\Common\Csrf` | ✅ | ✅ | Identical signature: `($token, SessionInterface $session, string $subject = 'default'): bool` |
| `CsrfUtils::collectCsrfToken()` | `OpenEMR\Common\Csrf` | ✅ | ✅ | Identical signature: `(SessionInterface $session, string $subject = 'default'): string` |
| `QueryUtils::fetchRecords()` | `OpenEMR\Common\Database` | ✅ | ✅ | Identical |
| `QueryUtils::sqlStatementThrowException()` | `OpenEMR\Common\Database` | ✅ | ✅ | Identical |
| `QueryUtils::sqlInsert()` | `OpenEMR\Common\Database` | ✅ | ✅ | Identical |
| `QueryUtils::existsTable()` | `OpenEMR\Common\Database` | ✅ | ✅ | Return type differs (no type vs `bool`) — safe |
| `QueryUtils::clearSchemaCache()` | `OpenEMR\Common\Database` | ❌ | ✅ | **8.4.1 only** — not used; use `method_exists()` if needed |
| `QueryUtils::escapeLimit()` | `OpenEMR\Common\Database` | ✅ | ❌ | **8.2.0 only** — not used |
| `SystemLogger` | `OpenEMR\Common\Logging` | ✅ | ✅ | Identical. PSR-3 compatible |
| `ModulesClassLoader::registerNamespaceIfNotExists()` | `OpenEMR\Core` | ✅ | ✅ | 8.2 returns void, 8.4.1 returns bool — no impact |
| `AbstractModuleActionListener` | `OpenEMR\Core` | ✅ | ✅ | Identical interface |

## Capability Detection Pattern

```php
// Do NOT compare version numbers. Use class/method existence:
if (method_exists(QueryUtils::class, 'clearSchemaCache')) {
    QueryUtils::clearSchemaCache(); // 8.4.1+ only
}
if (class_exists('\OpenEMR\Common\Forms\EncounterFormAccess')) {
    // 8.4.1 only — not needed by our module
}
```

## PHP Version Requirements (from OpenEMR composer.json)

| Version | PHP Requirement in `composer.json` | Platform PHP | Notes |
|---|---|---|---|
| OpenEMR 8.2.0 | `"php": ">=8.2.0"` | `8.2` | Enforced by `Checker::$minimumPhpVersion = "8.2.0"` |
| OpenEMR 8.4.1 | `"php": ">=8.3.0"` | `8.3` | Enforced by `Checker::$minimumPhpVersion = "8.3.0"` |
| **This Module** | **`"php": ">=8.2.0"`** | **`8.2`** | **Must run on both 8.2.0 and 8.4.1** |

### PHP 8.3+ Features Forbidden in this Module
To ensure full compatibility with PHP 8.2.0:
- `json_validate()` function (PHP 8.3+)
- Typed class constants (`const string FOO = 'bar'`) (PHP 8.3+)
- Dynamic class constant fetch (`ClassName::{$var}`) (PHP 8.3+)
- `#[Override]` attribute (PHP 8.3+)
- Anonymous `readonly` classes (PHP 8.3+)

### PHP 8.2 Features Allowed and Supported
- `readonly` properties and `readonly` classes (PHP 8.2+)
- Enums (PHP 8.1+)
- Disjunctive Normal Form (DNF) types (PHP 8.2+)
- `true`, `false`, and `null` as standalone types (PHP 8.2+)
- First-class callable syntax (PHP 8.1+)
