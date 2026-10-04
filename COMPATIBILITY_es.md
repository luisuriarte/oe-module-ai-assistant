# Tabla de Compatibilidad — oe-module-ai-assistant

Versión mínima de OpenEMR: **8.2.0**  
PHP mínimo: **8.2.0**

Última actualización: M1

## Compatibilidad de Clases / Métodos / Eventos

| Elemento | Namespace | 8.2.0 | 8.4.1 | Notas |
|---|---|---|---|---|
| `ScriptFilterEvent` | `OpenEMR\Events\Core` | ✅ | ✅ | Idéntico. `EVENT_NAME = "html.head.script.filter"` |
| `TemplatePageEvent` | `OpenEMR\Events\Core` | ✅ | ✅ | Idéntico. Constante `CONTEXT_ARGUMENT_SCRIPT_NAME` presente |
| `StyleFilterEvent` | `OpenEMR\Events\Core` | ✅ | ✅ | Idéntico |
| `EncounterMenuEvent` | `OpenEMR\Events\Encounter` | ✅ | ✅ | Idéntico |
| `MenuEvent` | `OpenEMR\Menu` | ✅ | ✅ | Idéntico |
| `PatientMenuEvent` | `OpenEMR\Menu` | ✅ | ✅ | Idéntico |
| `Header::setupHeader()` | `OpenEMR\Core` | ✅ | ✅ | Dispara `ScriptFilterEvent`; `pageName = basename($_SERVER[SCRIPT_NAME])` |
| Ruta del template SOAP | n/a | ✅ | ✅ | `interface/forms/soap/templates/soap_form.twig` idéntico en ambas versiones |
| Campo SOAP `subjective` | n/a | ✅ | ✅ | `<textarea name="subjective">` idéntico |
| Campo SOAP `objective` | n/a | ✅ | ✅ | `<textarea name="objective">` idéntico |
| Campo SOAP `assessment` | n/a | ✅ | ✅ | `<textarea name="assessment">` idéntico |
| Campo SOAP `plan` | n/a | ✅ | ✅ | `<textarea name="plan">` idéntico |
| `CryptoGen::encryptStandard()` | `OpenEMR\Common\Crypto` | ✅ | ✅ | Firma idéntica |
| `CryptoGen::decryptStandard()` | `OpenEMR\Common\Crypto` | ✅ | ✅ | Firma idéntica |
| `SessionWrapperFactory::getInstance()->getActiveSession()` | `OpenEMR\Common\Session` | ✅ | ✅ | Idéntico |
| `SessionUtil` | `OpenEMR\Common\Session` | ✅ | ✅ | Idéntico |
| `OEGlobalsBag::get()` | `OpenEMR\Core` | ✅ | ✅ | Idéntico |
| `OEGlobalsBag::getWebRoot()` | `OpenEMR\Core` | ✅ | ✅ | Idéntico |
| `OEGlobalsBag::getKernel()` | `OpenEMR\Core` | ✅ | ✅ | Idéntico |
| `OEGlobalsBag::filter()` | `OpenEMR\Core` | ❌ | ✅ | **Solo en 8.4.1** — no se usa en este módulo |
| `VitalsService::getVitalsForPatientEncounter()` | `OpenEMR\Services` | ✅ | ✅ | Firma idéntica |
| `VitalsService::getVitalsHistoryForPatient()` | `OpenEMR\Services` | ✅ | ✅ | Firma idéntica |
| `PatientService` | `OpenEMR\Services` | ✅ | ✅ | Usado vía QueryUtils en PatientContextBuilder |
| `EncounterService` | `OpenEMR\Services` | ✅ | ✅ | Para lista de consultas |
| `AclMain::aclCheckCore()` | `OpenEMR\Common\Acl` | ✅ | ✅ | Idéntico |
| `AclExtended::addObjectSectionAcl()` | `OpenEMR\Common\Acl` | ✅ | ✅ | Idéntico |
| `AclExtended::addObjectAcl()` | `OpenEMR\Common\Acl` | ✅ | ✅ | Idéntico |
| `CsrfUtils::verifyCsrfToken()` | `OpenEMR\Common\Csrf` | ✅ | ✅ | Idéntico |
| `CsrfUtils::collectCsrfToken()` | `OpenEMR\Common\Csrf` | ✅ | ✅ | Idéntico |
| `QueryUtils::fetchRecords()` | `OpenEMR\Common\Database` | ✅ | ✅ | Idéntico |
| `QueryUtils::sqlStatementThrowException()` | `OpenEMR\Common\Database` | ✅ | ✅ | Idéntico |
| `QueryUtils::sqlInsert()` | `OpenEMR\Common\Database` | ✅ | ✅ | Idéntico |
| `QueryUtils::existsTable()` | `OpenEMR\Common\Database` | ✅ | ✅ | Tipo de retorno difiere (sin tipo vs `bool`) — compatible |
| `QueryUtils::clearSchemaCache()` | `OpenEMR\Common\Database` | ❌ | ✅ | **Solo en 8.4.1** — usar `method_exists()` si se necesita |
| `QueryUtils::escapeLimit()` | `OpenEMR\Common\Database` | ✅ | ❌ | **Solo en 8.2.0** — no se usa en este módulo |
| `SystemLogger` | `OpenEMR\Common\Logging` | ✅ | ✅ | Idéntico. Compatible con PSR-3 |
| `ModulesClassLoader::registerNamespaceIfNotExists()` | `OpenEMR\Core` | ✅ | ✅ | 8.2 devuelve void, 8.4.1 devuelve bool — sin impacto |
| `AbstractModuleActionListener` | `OpenEMR\Core` | ✅ | ✅ | Interfaz idéntica |

## Estrategia de Detección por Capacidad

No se comparan números de versión. Se usa existencia de clase/método:

```php
// Ejemplo: QueryUtils::clearSchemaCache() solo en 8.4.1+
if (method_exists(QueryUtils::class, 'clearSchemaCache')) {
    QueryUtils::clearSchemaCache();
}

// Ejemplo: EncounterFormAccess solo en 8.4.1 (no lo usamos, solo de referencia)
if (class_exists('\OpenEMR\Common\Forms\EncounterFormAccess')) {
    // ruta 8.4.1
}
```

## Restricciones de Sintaxis PHP

El módulo debe compilar en PHP 8.2.0. Construcciones prohibidas:
- Clases `readonly` (PHP 8.3+) ← Las *propiedades* `readonly` están permitidas (PHP 8.1+)
- `json_validate()` (PHP 8.3+)
- Constantes de clase tipadas con `final const Tipo` (PHP 8.3+)

Construcciones PHP 8.2 que SÍ podemos usar: propiedades `readonly`, enums, fibras, tipos de intersección, tipo de retorno `never`, sintaxis callable de primera clase.
