# 🛠️ Phase 2: Developer Experience – Werkzeuge für Qualität

**Ziel**: Testing, CI/CD und Developer Tools aufbauen.
**Wichtig**: Kann teilweise parallel zu Phase 1 laufen!

## Schritt 2.0: Controller Annotations zu PHP 8 Attributes Migration

- **Branch**: e.g. `feature/controller-attributes`
- **Ziel**: Migration von Doctrine Annotations zu PHP 8 Attributes für alle Controller

**Detaillierter Agenten-Auftrag**:

## Schritt 2.0.1: PSR-11 Container Vollmodernisierung

- **Branch**: e.g. `feature/psr11-container-full-modernization`
- **Ziel**: Container zu einem nativen PSR-11 Container vollmodernisieren
- **Voraussetzung**: Schritt 1.14 (Doctrine Attributes) abgeschlossen

---

## Schritt 2.0.2: Validator-Translator Integration

- **Ziel**: Symfony Validator an Pagekit Translator anbinden, damit Validation-Fehlermeldungen in der aktiven Locale zurückgegeben werden (statt Roh-Keys wie `validation.user.username_required`)
- **Voraussetzung**: Schritt 2.0.1 (PSR-11 Container) abgeschlossen
- **Aufgaben**:
  - `ValidatorServiceProvider`: `$builder->setTranslator()` + `setTranslationDomain('validators')` anbinden
  - `validation.php` → `validators.php` umbenennen (System + Blog, alle Locales)
  - Verify: ValidatesRequestTrait liefert übersetzte Strings in JSON-Responses
  - (Optional) `ExtensionTranslateCommand` erweitern für `#[Assert\...]` Message-Keys
  - Tests für übersetzte Validation-Messages schreiben
- **Ergebnis**: API-Responses enthalten menschenlesbare, lokalisierte Fehlermeldungen

---

## Schritt 2.0.3: Cache API Vollmodernisierung

- **Ziel**: Pagekit's eigenes Cache-System (`CacheInterface`, `Psr6Adapter`, 5 Adapter-Wrapper) komplett durch direkte Nutzung von Symfony Cache / PSR-6 `CacheItemPoolInterface` ersetzen
- **Voraussetzung**: Schritt 2.0.2 (Validator-Translator Integration) abgeschlossen
- **Kontext**: Step 1.10 hat `doctrine/cache` intern durch `symfony/cache` ersetzt, aber eine Kompatibilitätsschicht (`CacheInterface` + `Psr6Adapter`) beibehalten. Diese verstößt gegen Rule 1 (No Compatibility Layers) und Rule 4 (Delete over Wrap). Die Regeln existierten bei Step 1.10 noch nicht.
- **Entscheidung**: `Psr\Cache\CacheItemPoolInterface` (PSR-6) wird das einzige Cache-Interface. Kein PSR-16, keine Symfony Contracts, kein eigenes Pagekit-Interface. `TagAwareCacheInterface` (Step 4.3) basiert auf PSR-6 — nahtloser Upgrade-Pfad.
- **Aufgaben**:
  - **Löschen (7 Dateien):**
    - `app/system/modules/cache/src/CacheInterface.php` (Legacy-Interface)
    - `app/system/modules/cache/src/Adapter/Psr6Adapter.php` (Kompatibilitäts-Layer)
    - `app/system/modules/cache/src/Adapter/ArrayAdapter.php` (Thin Wrapper)
    - `app/system/modules/cache/src/Adapter/FilesystemAdapter.php` (Thin Wrapper)
    - `app/system/modules/cache/src/Adapter/PhpFilesAdapter.php` (Thin Wrapper)
    - `app/system/modules/cache/src/Adapter/ApcuAdapter.php` (Thin Wrapper)
    - `app/system/modules/cache/src/Adapter/NullAdapter.php` (Thin Wrapper)
  - **Cache-Modul:**
    - `CacheModule::createPsr6Cache()`: Return-Type auf `CacheItemPoolInterface`, Symfony-Adapter direkt erstellen
    - `CacheModule::doClearCache()`: `flushAll()` → `clear()`
    - Namespace-Handling: Symfony-Adapter unterstützen Namespaces nativ via Konstruktor-Parameter
  - **ORM:**
    - `MetadataManager`: Property/Getter/Setter auf `?CacheItemPoolInterface`, Legacy-Else-Branch löschen
    - `QueryBuilder`: Gleiche Bereinigung (Union-Type, Legacy-Branch)
    - `EntityManager`: Bereits bereinigt (vorheriger Review-Schritt)
  - **Consumer (je ~3-5 Zeilen Änderung):**
    - `LoginAttemptListener`: `mixed $cache` → `CacheItemPoolInterface`, `fetch/save/delete` → PSR-6 API
    - `UrlResolver`: `mixed $cache` → `?CacheItemPoolInterface`, `fetch/save` → PSR-6 API
    - `RouteListener`: `mixed $cache` → `CacheItemPoolInterface`, `delete` → `deleteItem()`
    - `blog/scripts.php`: `clear()` bleibt (PSR-6 native)
  - **Tests:** `Psr6AdapterTest` → `CachePoolTest`, `QueryBuilderCacheTest` anpassen
- **Ergebnis**: Container liefert direkt `CacheItemPoolInterface`, keine Pagekit-eigenen Cache-Klassen mehr
- **Risiko**: Niedrig-Mittel — ~18 Dateien (7 löschen, ~11 ändern)
- **Kein neues Paket nötig**: `psr/cache` und `symfony/cache` bereits vorhanden

---

## Schritt 2.0.4: Package/Migration System Redesign

- **Branch**: e.g. `feature/package-migration-redesign`
- **Ziel**: Komplettes Redesign des Update- und Extension-Lifecycle-Systems. Doctrine Migrations und scripts.php-Hooks in eine einheitliche Pipeline zusammenfuehren. Grundlage fuer zukuenftigen Marketplace (Step 5.6) legen.
- **Voraussetzung**: Schritt 2.0.3 (Cache API Vollmodernisierung) abgeschlossen
- **Kontext**: Step 1.12 (DB Migration System) wurde ohne die aggressiven Regeln umgesetzt. Die Analyse zeigt, dass der Update-Pfad (Login-Check, Update-Wizard, CLI) Doctrine Migrations nicht automatisch ausfuehrt. Version-Bumps koennen ohne Schema-Pruefung passieren. Extensions haben kein einheitliches Install/Update-Pattern.
- **Aufgaben**:
  - **Update-Pipeline vereinheitlichen:**
    - Login-Check (`app/system/index.php`): Pending Doctrine Migrations pruefen, nicht nur `scripts.php`
    - Update-Wizard (`MigrationController`): `MigrationService::migrate()` VOR `scripts->update()` ausfuehren
    - CLI `pagekit migrate`: Doctrine Migrations + Scripts in richtiger Reihenfolge
    - Kein stilles Version-Bumping ohne Migration-Check
  - **CLI bereinigen:**
    - `pagekit migrate` vereinheitlichen: Doctrine Migrations + Scripts in einem Befehl
    - `migration:*` Sub-Commands bleiben fuer Entwickler (low-level)
  - **Extension Lifecycle standardisieren:**
    - Einheitliches Pattern fuer alle Extensions (wie Blog, aber automatisch)
    - `PackageManager::enable()` ruft automatisch `migrateExtension()` auf
    - `PackageManager::uninstall()` ruft automatisch Rollback auf
    - Extensions brauchen `scripts.php` nur noch fuer Nicht-SQL-Hooks (Config, Cache)
    - Dokumentation/Template fuer Extension-Entwickler
  - **MigrationService haerten:**
    - Fehlerbehandlung in `migrate()` fixen (Array-Rueckgabe korrekt pruefen)
    - Rollback-Isolation bei Extensions sicherstellen (nur eigene Namespaces)
    - Status-API fuer pending Migrations (fuer Login-Check)
  - **Marketplace-Grundlage:**
    - Saubere `PackageManager` API: `install()`, `update()`, `uninstall()`, `enable()`, `disable()`
    - ZIP-Upload modernisieren (bestehender Upload-Button im Backend)
    - Extension-Versioning pro Paket in Config
    - Vorbereitung fuer Marketplace-API-Anbindung (Step 5.6)
- **Ergebnis**: Ein Update-Pfad fuer alles; Extensions folgen einheitlichem Lifecycle; Marketplace-ready
- **Risiko**: Mittel-Hoch — betrifft Installer, PackageManager, MigrationService, CLI, Login-Flow
- **Betroffene Dateien**: `scripts.php`, `system/index.php`, `MigrationService.php`, `MigrationCommand.php`, `MigrationController.php`, `PackageManager.php`, `PackageScripts.php`, `Installer.php`, `blog/scripts.php`

---

## Schritt 2.1: Static Analysis & Code Quality Tools

- **Ziel**: Umfassende Code-Qualitäts-Tools und statische Analyse
- **Voraussetzung**: Schritt 1.14 (Doctrine Attributes) abgeschlossen
- **Zeitschätzung**: aufgeteilt in 9 Unterschritte
- **Pagekit-Prinzip**: Tools für Developer, Core bleibt leicht!

**Codebase-Analyse (Stand Februar 2026)**:

| Metrik                     | Ist-Zustand        | Ziel                          |
| -------------------------- | ------------------ | ----------------------------- |
| PHP-Dateien (ohne Vendor)  | ~750-770           | alle modernisiert             |
| Dateien mit `strict_types` | ~113 (~15%)        | 100%                          |
| Dateien mit Return Types   | ~15%               | ~100% (PHPStan Level 8)       |
| Typed Properties           | ~15%               | ~100%                         |
| Testdateien                | 39 (~200 Methoden) | 80%+ Coverage Core            |
| CI/CD für Tests            | nicht vorhanden    | vollständige Pipeline         |
| PHPStan                    | nicht installiert  | Level 8                       |
| Infection                  | nicht installiert  | 80%+ Score (kritische Module) |

> ⚠️ **Warum aufgeteilt?** Der ursprüngliche Plan (5-7 Tage, ein Block) war unrealistisch.
> `strict_types` zu ~650 Dateien hinzufügen ist kein Style-Fix – es ändert das Laufzeitverhalten
> und kann `TypeError`-Exceptions verursachen. PHPStan Level 5 allein wird auf einer 85% ungetypten
> Codebase hunderte Fehler melden. Jeder Unterschritt ist einzeln testbar und commitbar.

**Unterschritte-Übersicht**:

| Schritt | Beschreibung                      | Zeitschätzung | Risiko      |
| ------- | --------------------------------- | ------------- | ----------- |
| 2.1.1   | Tooling-Setup & Baseline          | 1-2 Tage      | Niedrig     |
| 2.1.2   | CI/CD Integration & Quality Gates | 1-2 Tage      | Niedrig     |
| 2.1.3   | `strict_types` Migration          | 3-5 Tage      | Mittel-Hoch |
| 2.1.4   | PHPStan Level 5→6 (Return Types)  | 1-2 Tage      | Niedrig     |
| 2.1.5   | PHPStan Level 6→7 (Null Safety)   | 1-2 Tage      | Mittel      |
| 2.1.6   | PHPStan Level 7→8 (Strict Typing) | 1-2 Tage      | Mittel      |
| 2.1.7   | QueryBuilder API Standardization  | 1-2 Tage      | Niedrig     |
| 2.1.8   | Infection Mutation Testing        | 2-3 Tage      | Niedrig     |
| 2.1.9   | Test Coverage Ausbau              | Fortlaufend   | Niedrig     |

---

### Schritt 2.1.1: Tooling-Setup & Baseline

- **Ziel**: Quality-Tools installieren, Baseline dokumentieren, PSR-12 Formatierung (ohne `strict_types`)
- **Voraussetzung**: Schritt 1.14 (Doctrine Attributes) abgeschlossen
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_1_Tooling-Setup-Baseline.md`
- **Aufgaben**:
  - PHPStan installieren (`phpstan/phpstan`, `phpstan/phpstan-doctrine`, `phpstan/phpstan-symfony`)
  - `phpstan.neon` konfigurieren mit Level 5
  - Baseline generieren (`phpstan analyse --generate-baseline`) – bestehende Fehler dokumentiert
  - `roave/security-advisories:dev-latest` installieren
  - `.php-cs-fixer.php`: `@PSR2` → `@PSR12` upgraden (**ohne** `declare_strict_types` Rule!)
  - PHP-CS-Fixer ausführen: `vendor/bin/php-cs-fixer fix` (reine Formatierung)
  - ✅ ESLint/Prettier bereits konfiguriert – keine Änderungen nötig
- **Ergebnis**: Tools laufen, Code ist PSR-12 formatiert, Baseline dokumentiert
- **Risiko**: Niedrig – reine Formatierung und Tool-Installation

---

### Schritt 2.1.2: CI/CD Integration & Quality Gates

- **Ziel**: Automatische Qualitätsprüfung für jeden PR
- **Voraussetzung**: Schritt 2.1.1 (Tooling-Setup) abgeschlossen
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_2_CI-CD-Quality-Gates.md`
- **Aufgaben**:
  - GitHub Actions Workflow: PHPStan Check (gegen Baseline, neue Fehler = Fail)
  - GitHub Actions Workflow: PHP-CS-Fixer dry-run (Style-Violations = Fail)
  - GitHub Actions Workflow: Security Audit (`composer audit`)
  - GitHub Actions Workflow: PHPUnit Tests (bestehende 39 Testdateien)
  - Code Coverage Report generieren (Ist-Stand dokumentieren)
  - Quality Gates als Required Checks für PRs aktivieren
- **Ergebnis**: Jeder PR wird automatisch geprüft, keine Regression möglich
- **Risiko**: Niedrig – nur CI-Konfiguration
- **Hinweis**: Schritt 2.2 erweitert dies später um E2E-Tests, Matrix-Builds und Release-Automation

---

### Schritt 2.1.3: `strict_types` Migration

- **Ziel**: `declare(strict_types=1)` in allen PHP-Dateien einführen (~765 Dateien ohne strict_types)
- **Voraussetzung**: Schritt 2.1.2 (CI/CD) abgeschlossen (damit Regressionen sofort erkannt werden)
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_3_Strict-Types-Migration.md`
- **Aufgaben**:
  - **Modul für Modul** migrieren (nicht alles auf einmal!)
  - Reihenfolge: Core-Module → System-Module → Packages
  - Pro Modul: `strict_types` hinzufügen → Tests laufen → `TypeError` fixen
  - Typ-Casts wo nötig hinzufügen (`(int)`, `(string)`, etc.)
  - PHPStan Baseline nach jeder Modul-Migration updaten
  - `.php-cs-fixer.php`: `declare_strict_types` Rule erst NACH vollständiger Migration aktivieren
- **Ergebnis**: Alle PHP-Dateien haben `strict_types`, alle Tests grün
- **Risiko**: Mittel-Hoch – Laufzeitverhalten ändert sich, `TypeError` möglich
- **Orchestrator-Hinweis**: Der Refactorer-Agent arbeitet pro Modul-Gruppe. Nach jeder Gruppe: Tests → Commit → nächste Gruppe. Kein Big-Bang!
- **Empfohlene Reihenfolge**:
  1. `app/modules/filter/` (klein, gut getestet)
  2. `app/modules/filesystem/` (klein, gut getestet)
  3. `app/modules/cookie/` (klein, gut getestet)
  4. `app/modules/auth/` (kritisch, gut getestet)
  5. `app/modules/database/` (Core, vorsichtig)
  6. `app/modules/routing/`, `app/modules/view/`, etc.
  7. `app/system/modules/*`
  8. `app/installer/`
  9. `packages/*` (zuletzt)

---

### Schritt 2.1.4: PHPStan Level 5→6 (Return Types)

- **Ziel**: PHPStan von Level 5 auf Level 6 steigern
- **Voraussetzung**: Schritt 2.1.3 (`strict_types` Migration) abgeschlossen
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_4_PHPStan-Level-6.md`
- **Aufgaben**:
  - Fehlende Return Types auf allen Methoden nachrüsten
  - Union Types bereinigen (z.B. `string|int` → klare Entscheidung)
  - Baseline updaten → Fehler fixen → Tests grün
- **Ergebnis**: PHPStan Level 6 ohne neue Baseline-Einträge
- **Risiko**: Niedrig – mechanische Arbeit, hohes Volumen, aber logisch einfach
- **Agent-Hinweis**: Refactorer kann das gut abarbeiten — hohe Menge, aber repetitive Pattern
- **Aus 2.1.1 Review identifiziert:**
  - `AuthDataCollector`: `Auth::getUser()` gibt `UserInterface` zurück, aber Code ruft `isAuthenticated()` und `User::findRoles()` auf, die nur auf der konkreten `User`-Klasse existieren. Entweder `UserInterface` erweitern oder Rückgabetyp von `getUser()` einengen.

---

### Schritt 2.1.5: PHPStan Level 6→7 (Null Safety)

- **Ziel**: PHPStan von Level 6 auf Level 7 steigern
- **Voraussetzung**: Schritt 2.1.4 (PHPStan Level 6) abgeschlossen
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_5_PHPStan-Level-7.md`
- **Aufgaben**:
  - Property Types auf allen Klassen-Properties erzwingen
  - Strikte Null-Checks einführen (`?string` statt `string|null`, Null-Guards)
  - "Call to member function on null" — Prüfen ob `null` tatsächlich möglich ist oder ob der Typ falsch deklariert war
  - Baseline updaten → Fehler fixen → Tests grün
- **Ergebnis**: PHPStan Level 7 ohne neue Baseline-Einträge
- **Risiko**: Mittel – erfordert Logik-Verständnis ("Kann das hier wirklich null sein?")
- **Agent-Hinweis**: Bei unklarer Null-Logik Verifier einschalten — Refactorer könnte vorschnell `!= null` Guards setzen wo das eigentliche Problem ein falscher Typ ist

---

### Schritt 2.1.6: PHPStan Level 7→8 (Strict Typing)

- **Ziel**: PHPStan von Level 7 auf Level 8 steigern (volle Typ-Sicherheit)
- **Voraussetzung**: Schritt 2.1.5 (PHPStan Level 7) abgeschlossen
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_6_PHPStan-Level-8.md`
- **Aufgaben**:
  - Alle verbleibenden `mixed` Typen eliminieren wo vermeidbar
  - Template-Parameter für generische Collections (wo sinnvoll)
  - Dokumentierte Ausnahmen für Stellen wo `mixed` unvermeidbar ist (z.B. Plugin-API)
  - Baseline updaten → Fehler fixen → Tests grün
  - ❌ Level 9 NICHT verwenden (zu streng für CMS mit dynamischen Extension-APIs)
  - **Interface-Design-Bereinigungen (aus 2.1.1 Review):**
    - `MailerInterface` in `MailerInterface` (send/create) und `MailPluginInterface` (beforeSend/afterSend) aufspalten — aktuell vermischt Mailer und Plugin dieselbe Schnittstelle
    - `EntityManager`: Singleton-Pattern (`static::$instance`) entfernen, alle Call-Sites auf DI umstellen
    - `FileLocatorAsset`: Static Service Locator (`setServices()` mit `mixed`-Properties) durch DI ersetzen, Properties typisieren
    - `ResponseListener`: `mixed $url` Property auf korrekten Typ (callable/Interface) einengen
- **Ergebnis**: PHPStan Level 8 ohne Baseline-Einträge (oder mit dokumentierten, begründeten Ausnahmen)
- **Risiko**: Mittel – kann Architektur-Entscheidungen erfordern (Interfaces ändern, Generics einführen)
- **Agent-Hinweis**: Hier sind ggf. Architect-Entscheidungen nötig bevor der Refactorer loslegt — nicht alle `mixed` können durch einfache Typ-Deklaration ersetzt werden

---

### Schritt 2.1.7: QueryBuilder API Standardization

- **Ziel**: Standardisierung der DB-Layer API an Doctrine-Standards
- **Kontext**: Aktuell nutzt Pagekit einen Wrapper (execute), der Parameter-Binding und Execution vermischt. DBAL 3 trennt dies strikt.
- **Voraussetzung**: Schritt 2.1.2 (CI/CD) abgeschlossen
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_7_QueryBuilder-API.md`
- **Aufgaben**:
  - Die Methoden `executeQuery()` und `executeStatement()` im `Pagekit\Database\Query\QueryBuilder` public machen
  - Alle Core-Aufrufe von `$qb->execute()` auf `$qb->executeQuery()` / `$qb->executeStatement()` umstellen (Rector oder Search-Replace)
  - Die alte Methode `execute()` **entfernen** (DELETE OVER WRAP – keine `@deprecated` Compat-Layer!)
  - **DBAL Type Normalisierung:**
    - `JsonArrayType`: `getName()` von `'json_array'` auf `'json'` umstellen
    - Alle `#[ORM\Column(type: 'json_array')]` in Entity-Attributen auf `type: 'json'` aendern (`DataModelTrait`, `DatabaseHandler`)
    - `ModelTrait::toArray()`: `case 'json_array'` auf `case 'json'` aendern
    - `database/index.php`: Redundante `Type::addType('json_array', ...)` Registrierung entfernen
    - `Connection::registerCustomTypeMappings()`: `json→json_array` Mapping vereinfachen/entfernen
    - `SimpleArrayType`: Kommentare aktualisieren (kein Compat-Layer, sondern JSON-Fallback-Parsing)
  - Alle Tests grün nach Umstellung
- **Ergebnis**: Standard-Doctrine-Dokumentation nutzbar, IDEs erkennen korrekte Return-Types (`Result`)
- **Risiko**: Niedrig – rein interne API-Änderung, alle Call-Sites werden im selben Schritt aktualisiert
- **⚠️ Agent-Hinweis (DBAL 3 Return Types)**: `executeQuery()` gibt ein `Doctrine\DBAL\Result` zurück, NICHT ein PDO-Statement oder Boolean wie das alte `execute()`. Alle Call-Sites müssen auf die DBAL 3 Result-API umgestellt werden:
  - `->fetchAll(PDO::FETCH_ASSOC)` → `->fetchAllAssociative()`
  - `->fetch(PDO::FETCH_ASSOC)` → `->fetchAssociative()`
  - `->fetchColumn()` → `->fetchOne()`
  - `->rowCount()` → bleibt `->rowCount()` (nur für INSERT/UPDATE/DELETE via `executeStatement()`)
  - Der Refactorer-Agent MUSS jede Call-Site individuell prüfen — kein blindes Search-Replace!

---

### Schritt 2.1.8: Infection Mutation Testing

- **Ziel**: Mutation Testing für sicherheitskritische Module einführen
- **Voraussetzung**: Schritt 2.1.6 (PHPStan Level 8) abgeschlossen + ausreichende Test-Coverage (mind. 60%+)
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_8_Infection-Mutation-Testing.md`
- **Aufgaben**:
  - Infection installieren (`infection/infection`)
  - `infection.json.dist` konfigurieren
  - Nur für kritische Module ausführen:
    - ✅ `app/modules/auth` (Authentication)
    - ✅ `app/system/modules/user` (User Management)
    - ❌ NICHT für Views, Templates, Markdown (zu langsam, nicht kritisch)
  - Ziel: 80%+ Mutation Score für kritische Module
- **Ergebnis**: Sicherheitskritischer Code ist durch Mutation Testing abgesichert
- **Risiko**: Niedrig – nur Test-Tooling, keine Code-Änderungen

---

### Schritt 2.1.9: Test Coverage Expansion

- **Ziel**: Test-Coverage systematisch auf Zielwerte steigern
- **Voraussetzung**: Schritt 2.1.2 (CI/CD mit Coverage-Reports) abgeschlossen
- **Zeitschätzung**: Fortlaufend (parallel zu allen weiteren Phase-2-Schritten)
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_9_Test-Coverage-Expansion.md`
- **Aufgaben**:
  - Coverage-Zielwerte:
    - Core Modules (`app/modules/`): 80%+
    - System Modules (`app/system/modules/`): 75%+
    - Packages (`packages/`): 60%+
  - Pro Schritt: Tests für betroffene Module mitschreiben
  - Coverage-Trends in CI tracken (HTML-Reports)
  - Edge-Case Tests für echte Pagekit-Szenarien:
    - Large File Uploads (Storage Module)
    - Concurrent Admin Actions (Session Handling)
    - Database Connection Failures (ORM Error Handling)
    - `AddRelNofollowFilter` XSS Edge Cases: Filter härten + 3 deaktivierte Tests aktivieren (slash statt Leerzeichen, Null-Byte-Obfuscation, `rel="follow"` Ersetzung) — siehe `app/modules/filter/src/Tests/AddRelNofollowTest.php`
- **Ergebnis**: Coverage steigt organisch mit jeder Änderung
- **Risiko**: Niedrig – fortlaufende Verbesserung, kein Big-Bang
- **Hinweis**: Kein eigener Branch – Coverage-Tests werden in jedem Feature-Branch mitgeliefert

---

### Schritt 2.2: CI/CD Pipeline

- **Ziel**: Automatisierte CI/CD mit E2E & Static Analysis Integration
- **Voraussetzung**: Schritte 1.10.5 (E2E Tests) und 2.1 (Static Analysis) abgeschlossen
- **Components**:
  - GitHub Actions Workflows (3 separate Workflows)
  - Automated E2E Testing Pipeline (alle optimierten Playwright Tests)
  - Static Analysis Integration (PHPStan Level 5→8)
  - Security Audit (roave/security-advisories)
  - Code Style Checks (PHP-CS-Fixer, ESLint)
  - Quality Gates für Pull Requests
  - Release Automation
  - ~~Deploy Previews~~ (Optional, später)

**Detaillierter Agenten-Auftrag**:

```
TASK: Implement GitHub Actions CI/CD Pipeline

0. CRITICAL RULES:
   ⚠️ CI should be fast and reliable
   - Keep workflows under 10 minutes
   - Use caching for dependencies
   - Run tests in parallel where possible
   - No flaky tests allowed

1. GITHUB ACTIONS WORKFLOWS:

   Workflow 1: PHP Tests (.github/workflows/php-tests.yml)
   -----------------------------------------------------------
   Trigger: push, pull_request (main, develop branches)

   Jobs:
   - PHPUnit Tests (159 Tests)
     - Matrix: PHP 8.2, 8.3, 8.4
     - Matrix: MySQL 8.4, SQLite 3
     - Cache: Composer dependencies
     - Parallel execution

   - PHPStan Analysis
     - Level 5 initially (strict check)
     - Generate baseline if needed
     - Fail on new errors

   - PHP-CS-Fixer Check (PSR-12)
     - Dry-run mode (no changes)
     - Fail on style violations
     - Ensure PSR-12 compliance

   - Security Audit
     - roave/security-advisories check
     - Composer audit
     - Fail on vulnerabilities

   Workflow 2: E2E Tests (.github/workflows/e2e-tests.yml)
   -----------------------------------------------------------
   Trigger: push, pull_request (main, develop branches)

   ⚠️ DESIGN-PRINZIP: E2E testet UI-Interaktion, NICHT Backend-Varianten.
   PHPUnit (Workflow 1) deckt die PHP/DB-Matrix ab. E2E fixiert das
   Backend auf EINE stabile Kombination und variiert nur Viewport.

   Jobs:
   - Playwright E2E Tests
     - Browser: Chromium (einziger Primary Browser)
     - Backend: PHP 8.3 + MySQL 8.4 (fixiert)
     - Matrix: 3 Viewports (siehe unten)
     - Screenshots on failure
     - Video on failure
     - Artifact upload
     - Parallel execution per Viewport

   Viewport-Matrix (3 Jobs):
     - 📱 Mobile:  375×667  (iPhone SE — kleinstes relevantes Gerät)
     - 📋 Tablet:  768×1024 (iPad Portrait)
     - 🖥️ Desktop: 1920×1080

   Optional (NICHT im Standard-CI, manuell triggerbar):
     - 🖥️ 4K/Retina: 2560×1440 (nur bei UI-Regressions-Verdacht)

   Ergebnis: 3 parallele Jobs statt 36. Ziel < 10 Minuten erreichbar.

   Test Categories:
   ✅ 01-setup/installation.spec.js
   ✅ 02-core/authentication.spec.js
   ✅ 02-core/dashboard.spec.js
   ✅ 02-core/orm-operations.spec.js
   ✅ 02-core/settings.spec.js
   ✅ 03-content/blog.spec.js
   ✅ 03-content/media.spec.js
   ✅ 03-content/pages.spec.js
   ✅ 04-frontend/public-pages.spec.js
   ✅ 05-features/menu-system.spec.js
   ✅ 05-features/user-management.spec.js
   ✅ 05-features/widgets.spec.js

   Workflow 2b: Cross-Browser Tests (.github/workflows/e2e-cross-browser.yml)
   -----------------------------------------------------------
   Trigger: schedule (weekly, z.B. Sonntag 03:00 UTC) + workflow_dispatch (manuell)
   ⚠️ NICHT bei jedem PR — nur wöchentlich oder vor Releases!

   Jobs:
   - Playwright Cross-Browser
     - Browsers: Firefox, WebKit (Safari)
     - Backend: PHP 8.3 + MySQL 8.4 (fixiert)
     - Viewport: Desktop 1920×1080 (fixiert — Rendering-Bugs sind selten viewport-abhängig)
     - Nur kritische Test-Subset:
       ✅ 02-core/authentication.spec.js
       ✅ 03-content/pages.spec.js
       ✅ 04-frontend/public-pages.spec.js

   Ergebnis: 2 Jobs (Firefox + WebKit), nur 3 Test-Suites. Läuft < 5 Minuten.
   Fängt Browser-spezifische Rendering-/JS-Bugs ab, ohne jeden PR zu blockieren.

   Workflow 3: Frontend Tests (.github/workflows/frontend-tests.yml)
   -----------------------------------------------------------
   Trigger: push, pull_request (main, develop branches)

   Jobs:
   - ESLint Check
     - Check all .js and .vue files
     - Airbnb style guide
     - Fail on errors

   - Prettier Check
     - Check formatting
     - Fail on style violations

   - Yarn Build Verification
     - Ensure assets compile
     - Check for webpack errors
     - No broken imports

2. QUALITY GATES:

   Pull Request MUST pass:
   ✅ All PHPUnit Tests (159 Tests)
   ✅ PHPStan Level 5 (no new errors)
   ✅ No Security Vulnerabilities
   ✅ PHP-CS-Fixer PSR-12 compliant
   ✅ All E2E Tests green
   ✅ ESLint/Prettier checks
   ⚠️ Code Coverage not below 75% (Core), 60% (Packages)

   Branch Protection Rules:
   - Require status checks before merge
   - Require branches to be up to date
   - Require approvals: 1 (for external contributors)

3. TEST MATRIX FOR EDGE-CASES:

   Integriert in Workflow 2 (E2E Tests) — KEIN separater Workflow!

   ⚠️ Edge-Cases laufen auf der fixierten E2E-Kombination:
   - Browser: Chromium
   - Backend: PHP 8.3 + MySQL 8.4
   - Viewport: Desktop 1920×1080 (Edge-Cases sind nicht viewport-abhängig)

   Edge-Case Scenarios (als eigene Test-Dateien oder Tags in bestehenden Tests):
   - Large File Upload (>50MB) via Media Manager
   - Session Timeout während Admin-Operation
   - Concurrent Page Edits (Race Conditions)
   - Network Failure während Form Submit
   - Database Connection Loss Simulation

   ❌ KEINE PHP/DB-Varianten in E2E (dafür ist Workflow 1 / PHPUnit da)
   ❌ KEINE Performance Benchmarks in CI (zu instabil)
   ❌ KEINE Load Tests in CI (zu langsam, gehört in Staging)
   ❌ KEINE Visual Regression Tests (zu wartungsintensiv)

4. PERFORMANCE & LOAD TEST STRATEGY:

   ❌ NICHT in GitHub Actions CI!

   ✅ Performance Monitoring in PRODUCTION:
   - Real User Monitoring (RUM)
   - Application Performance Monitoring (APM)
   - Tools: New Relic, DataDog, Blackfire.io, oder Self-hosted
   - Alerts bei Performance-Regression

   ✅ Load Tests VOR Major Releases:
   - Wann: Vor v2.0.0, v2.1.0, etc.
   - Wo: Staging Environment (production-like)
   - Tools: k6, JMeter, Artillery
   - Script: scripts/load-test-staging.sh

   Load Test Workflow (manuell):
   1. Deploy zu Staging
   2. Run Load Test (k6/JMeter)
      - 100 VUs Homepage (60s)
      - 50 VUs Admin Panel (60s)
      - 100 VUs Blog Page (60s)
   3. Analyze Results
   4. Fix Bottlenecks
   5. Repeat until acceptable
   6. → Dann Production Release

5. CACHING STRATEGY:

   - Composer dependencies (~/.composer/cache)
   - NPM dependencies (~/.npm)
   - Playwright browsers (~/.cache/ms-playwright)
   - PHPUnit test cache (.phpunit.cache)

   Speedup: ~5-10 minutes pro Workflow

6. DEPLOYMENT AUTOMATION (OPTIONAL):

   - Auto-deploy to Staging on develop push
   - Manual approval for Production
   - Rollback mechanism
   - Blue-Green Deployment (später)

SUCCESS CRITERIA:
✅ 4 GitHub Actions Workflows implementiert (PHP Tests, E2E, Cross-Browser, Frontend)
✅ E2E Tests: 3 Viewport-Jobs (Mobile/Tablet/Desktop), Chromium only, < 10 Minuten
✅ Cross-Browser Tests: wöchentlich + manuell, Firefox + WebKit
✅ Quality Gates enforced on PRs (nur Workflow 1-3, NICHT Cross-Browser)
✅ Code Coverage tracking aktiv
✅ Security Audit integriert
✅ Edge-Case Tests in E2E Suite integriert
✅ Load Test Strategy dokumentiert (VOR Releases)
✅ Performance Monitoring Strategy dokumentiert (Production)
✅ Caching optimiert (schnelle CI)
❌ KEINE Load Tests in CI (gehört in Staging)
❌ KEINE Performance Benchmarks in CI (zu instabil)
```

---

### Schritt 2.3: Docker Production Setup

- **Branch**: `feature/docker-production`
- **Ziel**: Production-ready Docker
- **Tasks**:

  - Multi-stage Builds
  - Alpine Linux Images
  - Docker Compose Optimierung
  - Kubernetes Ready

---

### Schritt 2.4: Build Tools Modernization

- **Ziel**: Moderne Build-Pipeline
- **Options**:
  - Webpack 5 Migration
  - Yarn Berry Evaluation (oder bei Yarn 1.22 bleiben)
  - Vite als Alternative zu Webpack
  - ESBuild für schnellere Builds
  - pnpm als Package Manager Alternative

---

### Schritt 2.5: Extension Safety & Fault Isolation

- **Ziel**: Verhindern, dass fehlerhafte Extensions das gesamte CMS crashen (White Screen of Death).
- **Kontext**: Aktuell werden Extensions direkt beim Boot geladen. Ein Fehler in einer `index.php` oder `main()` Funktion reißt den Kernel mit.
- **Aufgaben**:
  1.  **Sandboxed Loading**: Den `ModuleManager` so umbauen, dass Module in einem `try/catch(\Throwable)` Block geladen werden.
  2.  **Logging (ZUERST, DB-UNABHÄNGIG!)**: Der genaue Stacktrace muss **sofort** in `pagekit.log` geschrieben werden (Monolog FileHandler). Dieser Schritt darf **keine Datenbank-Verbindung** voraussetzen — wenn die Extension die DB-Connection zerstört hat, muss das Logging trotzdem funktionieren.
  3.  **Auto-Disable (eigener try/catch!)**: Versuch, das Modul in der Datenbank zu deaktivieren. Dieser DB-Write muss in einem **separaten** `try/catch(\Throwable)` stehen. Wenn der DB-Write fehlschlägt (z.B. weil die Extension die DB-Connection korrumpiert hat), wird stattdessen in eine lokale Datei geschrieben (`storage/disabled-extensions.json`). Beim nächsten Boot prüft der Kernel **beide** Quellen (DB + Fallback-Datei).
  4.  **Admin Alert**: Beim nächsten Login muss der Admin eine Flash-Message sehen: _"Extension X wurde wegen eines kritischen Fehlers deaktiviert."_
- **Warum**: Erhöht die Stabilität drastisch. Ein Syntaxfehler in einem Plugin darf nicht dazu führen, dass man nicht mehr ins Admin-Panel kommt, um es zu deinstallieren.
- **Technische Umsetzung**:
  - [ ] Einführung von `Pagekit\System\Extension\ExtensionLifecycleInterface`.
  - [ ] Umstellung der Blog-Extension von `scripts.php` auf eine Lifecycle-Klasse.
  - [ ] Umbau des `ModuleManager`/`ExtensionManager`, um diese Klassen in `try/catch(\Throwable)`-Blöcken auszuführen.
  - [ ] Logging über Monolog FileHandler (`storage/logs/pagekit.log`) — MUSS ohne DB funktionieren.
  - [ ] Auto-Disable: Primär via DB, Fallback via `storage/disabled-extensions.json`. Boot-Sequenz prüft beide Quellen.
  - [ ] Integration mit dem neuen Migrations-System (Schritt 1.12): Bei Throwable während `onInstall` -> Automatischer DB-Rollback.
  - [ ] Implementierung der Admin-Alert Flash-Messages.

---

### Schritt 2.6: Automated Update System - External & Background-Updates

- **Ziel**: Moderne, zukunftssichere Update-Infrastruktur für Pagekit CMS
- **Priorität**: 🔴 Hoch (notwendig für langfristige Wartbarkeit)
- **Kontext**: 'migration-docs\TODO\features\AUTOMATED_UPDATE_SYSTEM.md'
