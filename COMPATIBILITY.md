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
| `Header::setupHeader()` | `OpenEMR\Core` | ✅ | ✅ | Fires `ScriptFilterEvent`; `pageName = basename($_SERVER[SCRIPT_NAME])` |
| SOAP template path | n/a | ✅ | ✅ | `interface/forms/soap/templates/soap_form.twig` identical in both |
| SOAP field `subjective` | n/a | ✅ | ✅ | `<textarea name="subjective">` identical |
| SOAP field `objective` | n/a | ✅ | ✅ | `<textarea name="objective">` identical |
| SOAP field `assessment` | n/a | ✅ | ✅ | `<textarea name="assessment">` identical |
| SOAP field `plan` | n/a | ✅ | ✅ | `<textarea name="plan">` identical |
| `CryptoGen::encryptStandard()` | `OpenEMR\Common\Crypto` | ✅ | ✅ | Identical signature |
| `CryptoGen::decryptStandard()` | `OpenEMR\Common\Crypto` | ✅ | ✅ | Identical signature |
| `SessionWrapperFactory::getInstance()->getActiveSession()` | `OpenEMR\Common\Session` | ✅ | ✅ | Identical |
| `SessionUtil` | `OpenEMR\Common\Session` | ✅ | ✅ | Identical |
| `OEGlobalsBag::get()` | `OpenEMR\Core` | ✅ | ✅ | Identical |
| `OEGlobalsBag::getWebRoot()` | `OpenEMR\Core` | ✅ | ✅ | Identical |
| `OEGlobalsBag::getKernel()` | `OpenEMR\Core` | ✅ | ✅ | Identical |
| `OEGlobalsBag::filter()` | `OpenEMR\Core` | ❌ | ✅ | **8.4.1 only** — not used in this module |
| `VitalsService::getVitalsForPatientEncounter()` | `OpenEMR\Services` | ✅ | ✅ | Identical signature |
| `VitalsService::getVitalsHistoryForPatient()` | `OpenEMR\Services` | ✅ | ✅ | Identical signature |
| `PatientService` | `OpenEMR\Services` | ✅ | ✅ | Used via QueryUtils in PatientContextBuilder |
| `EncounterService` | `OpenEMR\Services` | ✅ | ✅ | Used for encounter list |
| `AclMain::aclCheckCore()` | `OpenEMR\Common\Acl` | ✅ | ✅ | Identical |
| `AclExtended::addObjectSectionAcl()` | `OpenEMR\Common\Acl` | ✅ | ✅ | Identical |
| `AclExtended::addObjectAcl()` | `OpenEMR\Common\Acl` | ✅ | ✅ | Identical |
| `CsrfUtils::verifyCsrfToken()` | `OpenEMR\Common\Csrf` | ✅ | ✅ | Identical |
| `CsrfUtils::collectCsrfToken()` | `OpenEMR\Common\Csrf` | ✅ | ✅ | Identical |
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

## PHP Syntax Restrictions

Must compile on PHP 8.2.0. Forbidden constructs:
- `readonly` classes (8.3+)  ← `readonly` *properties* are fine (8.1+)
- `json_validate()` (8.3+)
- Typed class constants with `final const Type` (8.3+)
