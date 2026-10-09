# Tabla de Compatibilidad — oe-module-ai-assistant

Versión mínima de OpenEMR: **8.2.0**  
PHP mínimo: **8.2.0**

Última actualización: M7

## Compatibilidad de Clases / Métodos / Eventos

| Elemento | Namespace | 8.2.0 | 8.4.1 | Notas |
|---|---|---|---|---|
| `ScriptFilterEvent` | `OpenEMR\Events\Core` | ✅ | ✅ | Idéntico. `EVENT_NAME = "html.head.script.filter"` |
| `TemplatePageEvent` | `OpenEMR\Events\Core` | ✅ | ✅ | Idéntico. Constante `CONTEXT_ARGUMENT_SCRIPT_NAME` presente |
| `StyleFilterEvent` | `OpenEMR\Events\Core` | ✅ | ✅ | Idéntico |
| `EncounterMenuEvent` | `OpenEMR\Events\Encounter` | ✅ | ✅ | Idéntico |
| `MenuEvent` | `OpenEMR\Menu` | ✅ | ✅ | Idéntico |
| `PatientMenuEvent` | `OpenEMR\Menu` | ✅ | ✅ | Idéntico |
| `Header::setupHeader()` | `OpenEMR\Core` | ✅ | ✅ | Dispara `ScriptFilterEvent`; `pageName = basename($_SERVER['SCRIPT_NAME'])` del entry script HTTP |
| Entry script para nota nueva SOAP | n/a | ✅ | ✅ | `encounter/load_form.php?formname=soap` → `pageName="load_form.php"` (**no** `new.php`) |
| Entry script para ver/editar SOAP | n/a | ✅ | ✅ | `encounter/view_form.php?formname=soap&id=N` → `pageName="view_form.php"` (**no** `view.php`) |
| Ruta del template SOAP | n/a | ✅ | ✅ | `interface/forms/soap/templates/soap_form.twig` — cargado vía FormLocator, no directamente |
| Parámetro GET `formname` en SOAP | n/a | ✅ | ✅ | Debe ser exactamente `"soap"`; validado con `/^[a-zA-Z0-9_-]{1,64}$/` antes de enrutar |
| Campo SOAP `subjective` | n/a | ✅ | ✅ | `<textarea name="subjective">` idéntico |
| Campo SOAP `objective` | n/a | ✅ | ✅ | `<textarea name="objective">` idéntico |
| Campo SOAP `assessment` | n/a | ✅ | ✅ | `<textarea name="assessment">` idéntico |
| Campo SOAP `plan` | n/a | ✅ | ✅ | `<textarea name="plan">` idéntico |
| `CryptoGen::encryptStandard()` | `OpenEMR\Common\Crypto` | ✅ | ✅ | Firma idéntica |
| `CryptoGen::decryptStandard()` | `OpenEMR\Common\Crypto` | ✅ | ✅ | Firma idéntica |
| `SessionWrapperFactory::getInstance()->getActiveSession()` | `OpenEMR\Common\Session` | ✅ | ✅ | Devuelve `SessionInterface`. Garantiza sesión activa |
| `SessionWrapperFactory::getInstance()->isSessionActive()` | `OpenEMR\Common\Session` | ✅ | ✅ | Idéntico |
| `OEGlobalsBag::get()` | `OpenEMR\Core` | ✅ | ✅ | Idéntico |
| `OEGlobalsBag::getWebRoot()` | `OpenEMR\Core` | ✅ | ✅ | Idéntico |
| `OEGlobalsBag::getKernel()` | `OpenEMR\Core` | ✅ | ✅ | Idéntico |
| `OEGlobalsBag::filter()` | `OpenEMR\Core` | ❌ | ✅ | **Solo en 8.4.1** — no se usa en este módulo |
| `VitalsService::getVitalsForPatientEncounter()` | `OpenEMR\Services` | ✅ | ✅ | Firma idéntica: `($encounter_id)` |
| `VitalsService::getVitalsHistoryForPatient()` | `OpenEMR\Services` | ✅ | ✅ | Firma idéntica: `($pid)` |
| `VitalsService::search()` | `OpenEMR\Services` | ✅ | ✅ | Firma idéntica |
| `PatientService::getAll()` | `OpenEMR\Services` | ✅ | ✅ | Firma idéntica: `(array $search = [], ...)` |
| `PatientService::getFreshPid()` | `OpenEMR\Services` | ✅ | ✅ | Idéntico |
| `EncounterService::getOneByPidEid()` | `OpenEMR\Services` | ✅ | ✅ | Firma idéntica: `($pid, $encounter_id)` |
| `EncounterService::getMostRecentEncounterForPatient()` | `OpenEMR\Services` | ✅ | ✅ | Firma idéntica: `($pid): ?array` |
| `PatientIssuesService::getActiveIssues()` | `OpenEMR\Services` | ✅ | ✅ | Firma idéntica: `(int $pid): ProcessingResult` |
| `PatientIssuesService::getOneById()` | `OpenEMR\Services` | ✅ | ✅ | Firma idéntica: `($issueId)` |
| `AclMain::aclCheckCore()` | `OpenEMR\Common\Acl` | ✅ | ✅ | Idéntico. Verifica `(section, value, user, return_val)` |
| `AclExtended::addObjectSectionAcl()` | `OpenEMR\Common\Acl` | ✅ | ✅ | Idéntico |
| `AclExtended::addObjectAcl()` | `OpenEMR\Common\Acl` | ✅ | ✅ | Idéntico |
| `CsrfUtils::verifyCsrfToken()` | `OpenEMR\Common\Csrf` | ✅ | ✅ | Firma idéntica: `($token, SessionInterface $session, string $subject = 'default'): bool` |
| `CsrfUtils::collectCsrfToken()` | `OpenEMR\Common\Csrf` | ✅ | ✅ | Firma idéntica: `(SessionInterface $session, string $subject = 'default'): string` |
| `QueryUtils::fetchRecords()` | `OpenEMR\Common\Database` | ✅ | ✅ | Idéntico |
| `QueryUtils::sqlStatementThrowException()` | `OpenEMR\Common\Database` | ✅ | ✅ | Idéntico |
| `QueryUtils::sqlInsert()` | `OpenEMR\Common\Database` | ✅ | ✅ | Idéntico |
| `QueryUtils::existsTable()` | `OpenEMR\Common\Database` | ✅ | ✅ | Tipo de retorno difiere (sin tipo vs `bool`) — compatible |
| `QueryUtils::clearSchemaCache()` | `OpenEMR\Common\Database` | ❌ | ✅ | **Solo en 8.4.1** — usar `method_exists()` si se necesita |
| `QueryUtils::escapeLimit()` | `OpenEMR\Common\Database` | ✅ | ❌ | **Solo en 8.2.0** — no se usa en este módulo |
| `SystemLogger` | `OpenEMR\Common\Logging` | ✅ | ✅ | Idéntico. Compatible con PSR-3 |
| `ModulesClassLoader::registerNamespaceIfNotExists()` | `OpenEMR\Core` | ✅ | ✅ | 8.2 devuelve void, 8.4.1 devuelve bool — sin impacto |
| `AbstractModuleActionListener` | `OpenEMR\Core` | ✅ | ✅ | Interfaz idéntica |
| `AclMain::aclCheckCore('sensitivities', $val)` | `OpenEMR\Common\Acl` | ✅ | ✅ | Idéntico. Verificación nativa de sensibilidad de encuentro (high, sensitive, etc.) |
| `AclMain::aclCheckCore('patients', 'med')` | `OpenEMR\Common\Acl` | ✅ | ✅ | Idéntico. Verificación nativa de acceso clínico al paciente |
| Esquema `lists` (`type='allergy'`, `activity=1`) | OpenEMR DB | ✅ | ✅ | Fuente universal de alergias activas tanto en 8.2.0 como en 8.4.1 |
| Chequeo `forms.deleted = 0` | OpenEMR DB | ✅ | ✅ | Convención de soft-delete en todos los formularios clínicos |
| `SoapFormScriptListener` | `OpenEMR\Modules\AiAssistant\EventListener` | ✅ | ✅ | Escucha `ScriptFilterEvent` y `StyleFilterEvent` en `load_form.php` y `view_form.php` y agrega el botón "IA" |
| DOM nativo SOAP (`textarea[name="subjective"]`, `.btn-group`, `input[name="pid"|"id"]`) | DOM | ✅ | ✅ | Misma plantilla en ambas versiones; el launcher solo la lee para armar la URL del editor |
| `FormService::addForm()` | `OpenEMR\Services` | ✅ | ✅ | Crea la fila `forms` de la nota nueva con `formdir='soap'` (igual que el guardado SOAP nativo) |
| `QueryUtils::sqlInsert()` | `OpenEMR\Common\Database` | ✅ | ✅ | Devuelve el id insertado en `form_soap` en ambas versiones |
| Columnas `form_soap` (`subjective`, `objective`, `assessment`, `plan`, `activity`) | OpenEMR DB | ✅ | ✅ | Sin cambios entre versiones; el editor escribe las mismas columnas que `C_FormSOAP` |
| Editor SOAP-AI (`public/form.php`, `soap-ai.js`) | `OpenEMR\Modules\AiAssistant` | ✅ | ✅ | Editor moderno que lee/escribe el `form_soap` nativo; solo se llega desde el botón "IA" del SOAP nativo |
| `SoapDraftGenerator` | `OpenEMR\Modules\AiAssistant\Draft` | ✅ | ✅ | Validación de esquema, reintento ante JSON inválido, anti-inyección y verosimilitud |
| `DraftController` (`action=soap_draft`) | `OpenEMR\Modules\AiAssistant\Controller` | ✅ | ✅ | Sesión, CSRF, ACL use, acceso a paciente, cruce paciente/encuentro, timeout 60s, auditoría sin texto clínico |
| Grabación con `MediaRecorder` | Web API | ✅ | ✅ | Soporte prioritario Safari `audio/mp4;codecs=mp4a.40.2` y fallback a `audio/webm;codecs=opus` |
| `RateLimiter` (`Security\RateLimiter`) | `OpenEMR\Modules\AiAssistant\Security` | ✅ | ✅ | M7: ventanas fijas de 60 s por usuario para draft / chat / transcribe; contador atómico `INSERT … ON DUPLICATE KEY UPDATE`; nuevos buckets no requieren cambios de esquema |
| `oe_ai_assistant_rate_limits` (tabla del módulo) | BD OpenEMR (propia del módulo) | ✅ | ✅ | Creada por `install.sql` / `upgrade.sql`; purgada oportunistamente por `RateLimiter` en cada chequeo (sin cron) |
| Contrato JSON de errores M7 (`error` + `error_type`/`error_code`) | `OpenEMR\Modules\AiAssistant\Controller` + `public/index.php` | ✅ | ✅ | Los endpoints del router y de transcripción devuelven un `error` traducible más un código fijo; 500 JSON global sin filtrar la excepción ni el payload del proveedor |

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

## Requisitos de Versión de PHP (según composer.json de OpenEMR)

| Versión | Requisito PHP en `composer.json` | Plataforma PHP | Notas |
|---|---|---|---|
| OpenEMR 8.2.0 | `"php": ">=8.2.0"` | `8.2` | Verificado en `composer.json` y `Checker::$minimumPhpVersion = "8.2.0"` |
| OpenEMR 8.4.1 | `"php": ">=8.3.0"` | `8.3` | Verificado en `composer.json` y `Checker::$minimumPhpVersion = "8.3.0"` |
| **Este Módulo** | **`"php": ">=8.2.0"`** | **`8.2`** | **Debe compilar y operar en 8.2.0 y 8.4.1** |

### Características de PHP 8.3+ prohibidas en este módulo
Para garantizar total compatibilidad con PHP 8.2.0:
- Función `json_validate()` (PHP 8.3+)
- Constantes de clase tipadas (`const string FOO = 'bar'`) (PHP 8.3+)
- Acceso dinámico a constantes de clase (`ClassName::{$var}`) (PHP 8.3+)
- Atributo `#[Override]` (PHP 8.3+)
- Clases anónimas `readonly` (PHP 8.3+)

### Características de PHP 8.2 permitidas y soportadas
- Propiedades `readonly` y clases `readonly` (PHP 8.2+)
- Enums (PHP 8.1+)
- Tipos en Forma Normal Disyuntiva (DNF) (PHP 8.2+)
- Tipos autónomos `true`, `false` y `null` (PHP 8.2+)
- Sintaxis callable de primera clase (PHP 8.1+)
