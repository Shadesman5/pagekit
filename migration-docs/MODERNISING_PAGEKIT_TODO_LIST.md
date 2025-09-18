# 🚀 Pagekit CMS Modernisierung - Projekt-Fahrplan

> **Version**: 1.0.27 → 2.0.0  
> **Status**: Phase 1, Schritt 1.2 in Arbeit  
> **Ziel**: Vollständige Modernisierung vor dem ersten Produktions-Release 2.0

## 📋 Übersicht

Dieser strategische Plan führt Pagekit CMS von der aktuellen Legacy-Basis zu einem modernen, sicheren und wartbaren System. Jeder Schritt wird in einem eigenen Git-Branch durchgeführt und über Pull Requests geprüft.

## 🤖 Wichtige Anweisungen für Background-Agenten

**ALLE AGENTEN MÜSSEN**:

1. **Branch-Strategie**:

    - Immer von `develop` branchen
    - Branch-Name wie angegeben verwenden
    - Niemals direkt auf `develop` committen

2. **Test-Driven Development**:

    - Tests MÜSSEN grün sein vor PR-Erstellung
    - Fehlende Tests MÜSSEN geschrieben werden
    - Coverage sollte nicht sinken

3. **Pull Request Workflow**:

    - Nach erfolgreichem Abschluss IMMER einen PR erstellen
    - PR von feature branch → `develop`
    - Ausführliche PR-Beschreibung mit Testergebnissen
    - Entsprechende Labels setzen

4. **Dokumentation**:

    - Alle Änderungen dokumentieren
    - README.md bei Bedarf aktualisieren
    - Changelog-würdige Änderungen notieren

5. **Isolation**:
    - Nur die angegebenen Aufgaben bearbeiten
    - Keine "Nebenbei-Fixes" außerhalb des Scopes
    - Bei Blockern: Issue erstellen, nicht selbst lösen

---

## ✅ Phase 0: Vorbereitung – Das Spielfeld abstecken

**Status**: ABGESCHLOSSEN ✅  
**Ziel**: Eine stabile und nachvollziehbare Ausgangsbasis schaffen.

### Schritt 0.1: Git-Repository verbinden & Baseline erstellen

-   **Status**: ✅ Erledigt
-   **Aktion**: Repository mit GitHub verbunden, Test-Suite ausgeführt
-   **Ergebnis**: `dependency-analysis.md` erstellt mit allen veralteten Paketen

---

## 🔧 Phase 1: Backend-Stabilisierung – Das Fundament gießen

**Status**: IN ARBEIT 🚧  
**Priorität**: HÖCHSTE  
**Ziel**: Kritische Sicherheitsrisiken beseitigen und die Grundlage für alle weiteren Upgrades legen.

### Schritt 1.1: Mailer-Migration abschließen

-   **Status**: ✅ ABGESCHLOSSEN
-   **Branch**: `feature/symfony-mailer-migration`
-   **Ergebnis**:
    -   Swift Mailer → Symfony Mailer 5.4 Migration komplett
    -   42 Tests implementiert
    -   Dokumentation in `MAIL_MIGRATION.md`

### Schritt 1.2: Test-Suite modernisieren

-   **Status**: ✅ ABGESCHLOSSEN
-   **Branch**: `feature/phpunit-11-upgrade`
-   **Ziel**: PHPUnit 9.6 → 11.x
-   **Voraussetzung**: PHP 8.2+ (siehe Hinweis)

**✅ WICHTIG**: PHPUnit 11 benötigt PHP 8.2+. Daher muss zuerst:

1. PHP Minimum Version auf 8.2 anheben
2. Dann PHPUnit aktualisieren

**Detaillierter Agenten-Auftrag**:

```
TASK: Modernize PHPUnit Test Suite from 9.x to 11.x

1. PREPARATION:
   - Create new branch: `feature/phpunit-11-upgrade` from `develop`
   - Ensure you have PHP 8.2+ locally installed

2. PHP VERSION UPDATE:
   - Update composer.json: Change "php": "^7.4" to "php": "^8.2"
   - Update index.php: Change version check from 7.3 to 8.2
   - Search for any other PHP version checks in the codebase

3. PHPUNIT UPGRADE:
   - Run: composer require --dev phpunit/phpunit:^11.0
   - Update phpunit.xml.dist if needed for PHPUnit 11 compatibility

4. FIX DEPRECATED METHODS:
   Common changes needed:
   - setUp(): void (add return type)
   - tearDown(): void (add return type)
   - assertRegExp() → assertMatchesRegularExpression()
   - assertNotRegExp() → assertDoesNotMatchRegularExpression()
   - expectExceptionMessageRegExp() → expectExceptionMessageMatches()
   - @expectedException annotations → expectException() method
   - getMockBuilder()->setMethods() → getMockBuilder()->onlyMethods()

5. WRITE MISSING TESTS:
   If any modules lack tests, create basic test coverage:
   - Minimum one test per public method
   - Focus on critical paths
   - Document why tests were added

6. VALIDATION:
   - Run: composer test (or phpunit)
   - ALL tests must pass
   - No deprecation warnings allowed
   - Generate coverage report if possible

7. DOCUMENTATION:
   - Update README.md with new PHP requirement
   - Document any new test conventions
   - List all deprecated methods that were updated

8. CREATE PULL REQUEST:
   - Title: "feat: Upgrade PHPUnit to 11.x and PHP minimum to 8.2"
   - Base: develop
   - Description: Include test results, coverage stats, and list of changes
   - Add labels: enhancement, testing, dependencies

SUCCESS CRITERIA:
- All existing tests pass
- No PHPUnit deprecation warnings
- PHP 8.2 compatibility verified
- PR created and ready for review
```

### Schritt 1.3: Kritische Abhängigkeiten patchen

-   **Status**: 🚧 In Arbeit
-   **Branch**: `feature/security-patches`
-   **Priorität**: Nach PHPUnit-Update

**Detaillierter Agenten-Auftrag**:

```
TASK: Patch Critical Security Vulnerabilities

1. PREPARATION:
   - Create new branch: `feature/security-patches` from `develop`
   - Review dependency-analysis.md for priority updates
   - Ensure PHPUnit 11 is working (from step 1.2)

2. UPDATE STRATEGY:
   Update packages ONE AT A TIME in this order:
   a) doctrine/dbal: ~2.13 → ^3.8 (HIGH PRIORITY)
   b) monolog/monolog: ~2.1.1 → ^3.7
   c) doctrine/cache: ~1.13 → ^2.2
   d) Other security-critical packages

3. FOR EACH PACKAGE UPDATE:
   - Create a separate commit
   - Run: composer update [package-name]
   - Run full test suite after EACH update
   - If tests fail:
     * Check for breaking changes in package changelog
     * Update code to fix compatibility
     * Write new tests if needed
   - Document changes in SECURITY_PATCHES.md

4. SPECIAL ATTENTION - Doctrine DBAL:
   Major version jump requires:
   - Check all database queries for deprecated methods
   - Update query builder usage if needed
   - Test all database operations thoroughly

5. CREATE/UPDATE TESTS:
   For any code changes made:
   - Write unit tests for new code paths
   - Update existing tests if APIs changed
   - Ensure 100% pass rate

6. VALIDATION:
   - Run: composer audit (should show fewer vulnerabilities)
   - Run: composer test
   - Check that system still works in browser
   - No regression in functionality

7. CREATE PULL REQUEST:
   - Title: "fix: Patch critical security vulnerabilities"
   - Base: develop
   - Description:
     * List all updated packages with version changes
     * Include composer audit before/after comparison
     * Note any code changes required
   - Add labels: security, dependencies, high-priority

SUCCESS CRITERIA:
- All high/critical vulnerabilities resolved
- All tests pass
- No functional regressions
- Each package update in separate commit
- PR ready with complete documentation
```

### Schritt 1.4: Der "Königszug" – Das Symfony-Upgrade

-   **Status**: ⏳ Ausstehend
-   **Branch**: `feature/symfony-6.4-upgrade`
-   **Ziel**: Symfony 5.4 → 6.4 LTS
-   **Voraussetzung**: PHP 8.2+, PHPUnit 11

**Detaillierter Agenten-Auftrag**:

```
TASK: Upgrade Symfony from 5.4 to 6.4 LTS

1. PREPARATION:
   - Create new branch: `feature/symfony-6.4-upgrade` from `develop`
   - Ensure PHP 8.2+ and PHPUnit 11 are working
   - Create SYMFONY_64_CHANGES.md to document all changes

2. ANALYSIS PHASE:
   - List all Symfony components in composer.json
   - Review Symfony 5.4→6.0→6.4 upgrade guides
   - Identify all breaking changes that affect Pagekit

3. UPDATE COMPOSER.JSON:
   Update ALL Symfony packages to ^6.4:
   - "symfony/console": "^6.4"
   - "symfony/finder": "^6.4"
   - "symfony/framework-bundle": "^6.4"
   - "symfony/http-foundation": "^6.4"
   - "symfony/http-kernel": "^6.4"
   - "symfony/mailer": "^6.4"
   - "symfony/routing": "^6.4"
   - "symfony/stopwatch": "^6.4"
   - "symfony/translation": "^6.4"
   - "symfony/twig-bridge": "^6.4"
   - "symfony/yaml": "^6.4"

4. RUN COMPOSER UPDATE:
   - Run: composer update symfony/*
   - Document any dependency conflicts
   - Resolve conflicts by updating related packages

5. FIX BREAKING CHANGES:
   Common issues to check:
   - Service configuration changes (services.yaml)
   - Deprecated service aliases
   - Changed method signatures
   - Removed deprecated features
   - New required configurations
   - Changes in event system
   - Router configuration updates

6. MODULE-BY-MODULE TESTING:
   Test each module separately:
   - app/system/modules/*
   - app/modules/*
   - packages/*
   For each module:
   - Run module-specific tests
   - Check for deprecation warnings
   - Update code as needed
   - Write new tests for changed behavior

7. SPECIFIC ATTENTION AREAS:
   - HTTP Kernel changes
   - Router configuration
   - Service container
   - Event dispatcher
   - Console commands
   - Mailer (already on 5.4, but verify 6.4)

8. CREATE/UPDATE TESTS:
   - Write tests for any compatibility layers
   - Update existing tests for new APIs
   - Add integration tests for critical paths
   - Ensure no Symfony deprecation warnings

9. PERFORMANCE CHECK:
   - Compare response times before/after
   - Check memory usage
   - Profile any slow areas
   - Document performance changes

10. VALIDATION:
    - Run: composer test (full suite)
    - Run: bin/console about (check Symfony version)
    - Test all major features in browser:
      * User registration/login
      * Admin panel
      * Content creation
      * Email sending
      * File uploads
    - No deprecation warnings in logs

11. DOCUMENTATION:
    Update in SYMFONY_64_CHANGES.md:
    - All changed files
    - All code modifications
    - Any new patterns introduced
    - Performance impact
    - Breaking changes for extensions

12. CREATE PULL REQUEST:
    - Title: "feat: Upgrade Symfony to 6.4 LTS"
    - Base: develop
    - Description:
      * Summary of all Symfony components updated
      * List of breaking changes addressed
      * Test results summary
      * Performance comparison
      * Link to SYMFONY_64_CHANGES.md
    - Add labels: enhancement, symfony, major-update

SUCCESS CRITERIA:
- All Symfony components on 6.4.x
- Zero failing tests
- No deprecation warnings
- All features working as before
- Performance maintained or improved
- Complete documentation
- PR ready with thorough testing evidence
```

---

## 🛠️ Phase 2: Build-System – Die Werkstatt aufrüsten

**Status**: ⏳ WARTEND  
**Ziel**: Die Werkzeuge für das Frontend-Development modernisieren.

### Schritt 2.1: Webpack-Migration

-   **Status**: ⏳ Ausstehend
-   **Branch**: `feature/webpack-5-upgrade`
-   **Ziel**: Webpack 4 → Webpack 5

**Agenten-Auftrag**:

```
"Migrate from Webpack 4 to Webpack 5. Update webpack.config.js for
better tree-shaking, faster builds, and improved source-maps. Update
all webpack plugins and loaders to compatible versions."
```

### Schritt 2.2: Yarn-Upgrade (Optional)

-   **Status**: ⏳ Zu evaluieren
-   **Alternative**: Bei Yarn 1.22.22 bleiben (stabil und funktional)
-   **Hinweis**: Yarn Berry hat Breaking Changes

---

## 🎨 Phase 3: Frontend-Erneuerung – Die Fassade streichen

**Status**: ⏳ WARTEND  
**Ziel**: Sichtbare Verbesserungen und zukunftssichere Frontend-Architektur.

### Schritt 3.1: UIkit-Update

-   **Status**: ⏳ Ausstehend
-   **Branch**: `feature/uikit-update`
-   **Ziel**: UIkit 3.5 → 3.21.x (latest)

**Agenten-Auftrag**:

```
"Update UIkit from 3.5 to latest 3.x. Analyze breaking changes and
update all HTML templates and Vue components accordingly. Test all
UI components for visual regressions."
```

### Schritt 3.2: Vue.js-Migration Vorbereitung

-   **Status**: ⏳ Strategieplanung
-   **Optionen**:
    1. Vue 2.6 → 2.7 (Compatibility Build)
    2. Direkt Vue 3 mit Migration Build
    3. Schrittweise Komponenten-Migration

**Empfehlung**: Erst Vue 2.7 als Zwischenschritt

---

## 📊 Fortschritts-Tracking

| Phase | Schritt              | Status | Branch             | PR  |
| ----- | -------------------- | ------ | ------------------ | --- |
| 0     | 0.1 Repository Setup | ✅     | -                  | -   |
| 1     | 1.1 Mailer Migration | ✅     | merged             | #17 |
| 1     | 1.2 PHPUnit Update   | 🚧     | feature/phpunit-11 | -   |
| 1     | 1.3 Security Patches | ⏳     | -                  | -   |
| 1     | 1.4 Symfony 6.4      | ⏳     | -                  | -   |
| 2     | 2.1 Webpack 5        | ⏳     | -                  | -   |
| 2     | 2.2 Yarn Upgrade     | ⏳     | -                  | -   |
| 3     | 3.1 UIkit Update     | ⏳     | -                  | -   |
| 3     | 3.2 Vue Migration    | ⏳     | -                  | -   |

---

## 🎯 Versions-Meilensteine

### Pagekit 1.0.28 (Intern)

-   ✅ Mailer Migration
-   ⬜ PHPUnit 11
-   ⬜ PHP 8.2 Minimum

### Pagekit 1.1.0 (Intern)

-   ⬜ Symfony 6.4 LTS
-   ⬜ Alle Sicherheitslücken behoben
-   ⬜ Doctrine DBAL 3.x

### Pagekit 2.0.0 (Erster Production Release)

-   ⬜ Alle Phasen abgeschlossen
-   ⬜ Frontend modernisiert
-   ⬜ Vollständige Test-Abdeckung
-   ⬜ Dokumentation aktualisiert
-   ⬜ Performance optimiert

---

## 📝 Wichtige Hinweise

1. **PHP Version**: Muss auf 8.2 angehoben werden für PHPUnit 11 und Symfony 6.4
2. **Test First**: Jede Änderung muss durch Tests abgesichert sein
3. **Incremental**: Kleine, testbare Schritte statt Big-Bang-Updates
4. **Documentation**: Jede Phase produziert Dokumentation für zukünftige Wartung

---

**Erstellt**: September 2025  
**Letzte Aktualisierung**: September 16, 2025  
**Nächster Schritt**: PHPUnit 11 Upgrade (PHP 8.2 requirement first)
