# 🎯 Vollständiger E2E Test-Plan für Pagekit

**Stand:** 12.02.2026 | **Version:** 2.2 | **System:** Pagekit Modernized

**Abgleich:** Dieser Plan ist mit der [ROADMAP](../../.cursor/ROADMAP.md) und `tests/e2e/TEST_PLAN_ANALYSIS_2025.md` abgestimmt. Features, die laut Roadmap erst in Phase 4/5 kommen oder aktuell nicht vorhanden sind, sind entsprechend gekennzeichnet.

---

## ⚠️ Hinweis für Entwicklung (aus TODO – Agent Instructions)

- **Nicht die komplette E2E-Suite bei jedem Schritt laufen lassen.**  
  Pro Modernisierungs-Schritt: **nur** Installationstest + die Specs der betroffenen Feature-Bereiche ausführen.  
  Siehe `AGENTS.md` → „Modernisation Workflow Rules“.
- Vor Arbeit: `npx playwright test tests/e2e/specs/01-setup/installation.spec.js` + `./app/vendor/bin/phpunit`
- Nach jedem Schritt: frische Installation + betroffene Feature-Tests + PHPUnit (alle grün vor PR).

---

## Aktuelle Test-Coverage-Analyse

### ✅ Was wir bereits testen:
1. ✅ **Installation** (5 Steps) – `01-setup/installation.spec.js` – 1 Test, optimiert
2. ✅ **Authentication** – `02-core/authentication.spec.js` – 13 Tests, optimiert (Login, Logout, CSRF, Rate Limiting, Session)
3. ✅ **Dashboard** – `02-core/dashboard.spec.js` – 12 Tests, optimiert
4. ✅ **Settings** – `02-core/settings.spec.js` – 9 Tests, optimiert
5. ✅ **ORM Operations** – `02-core/orm-operations.spec.js` – 6 Tests
6. ✅ **Pages** – `03-content/pages.spec.js` – 5 Tests
7. ✅ **Blog** – `03-content/blog.spec.js` – 9 Tests
8. ✅ **Media/Finder** – `03-content/media.spec.js` – 8 Tests
9. ✅ **Frontend** – `04-frontend/public-pages.spec.js` – 5 Tests (Homepage, 404, Assets, Nav, Footer)
10. ✅ **Menu System** – `05-features/menu-system.spec.js` – 10 Tests
11. ✅ **User Management** – `05-features/user-management.spec.js` – 8 Tests
12. ✅ **Widgets** – `05-features/widgets.spec.js` – 10 Tests

### 📊 Test-Status:
- **Optimiert:** `test-config.js` (Helper), `installation.spec.js`, `authentication.spec.js`, `dashboard.spec.js`, `settings.spec.js`
- **Vorhanden:** **12** Test-Specs in **5** Kategorien (`01-setup`, `02-core`, `03-content`, `04-frontend`, `05-features`)
- **Ca. 96** einzelne `test()`-Fälle insgesamt
- **Helper:** `test-config.js`, `vue-helpers.js` | **Config:** `config/test-config.json` (aus `test-config.example.json` anlegen)

### 📁 Ordnerstruktur:
```
tests/e2e/
├── config/
│   ├── test-config.example.json
│   └── test-config.json          (lokal anlegen, gitignored)
├── helpers/
│   ├── test-config.js
│   └── vue-helpers.js
├── specs/
│   ├── 01-setup/installation.spec.js
│   ├── 02-core/authentication.spec.js, dashboard.spec.js, orm-operations.spec.js, settings.spec.js
│   ├── 03-content/blog.spec.js, media.spec.js, pages.spec.js
│   ├── 04-frontend/public-pages.spec.js
│   └── 05-features/menu-system.spec.js, user-management.spec.js, widgets.spec.js
├── COMPLETE_TEST_PLAN.md
├── TEST_PLAN_ANALYSIS_2025.md
└── README.md
```
Playwright: `playwright.config.js` (Projektwurzel). Smoke-Auswahl über den `@ci`-Tag der 3 optimierten Specs (`npx playwright test --grep @ci`); die übrigen 8 sind via `test.describe.fixme` quarantäniert.

### ❌ Was noch FEHLT und getestet werden MUSS:

**Legende:**  
- Einträge ohne Zusatz = aktuell im System vorhanden, Tests ausbaubar.  
- `(Phase 4.1)` = Feature kommt erst in Phase 4.1 (Basic Security & Modern Auth).  
- `(nicht vorhanden)` = laut `TEST_PLAN_ANALYSIS_2025.md` derzeit nicht implementiert.

## 1. USER MANAGEMENT & PERMISSIONS
- [ ] User erstellen (verschiedene Rollen)
- [ ] User bearbeiten
- [ ] User löschen
- [ ] Bulk Operations (mehrere User)
- [ ] Rollen-Management (Anonymous, Authenticated, Administrator)
- [ ] Permissions testen (was kann welche Rolle?)
- [ ] Profil bearbeiten
- [ ] Avatar hochladen
- [ ] Passwort ändern
- [ ] Passwort zurücksetzen (Reset-Flow) — _(Teil von Phase 4.1)_
- [ ] Email-Verifizierung — _(optional Phase 4.x)_
- [ ] User blockieren/entsperren
- [ ] Login-Versuche und Sicherheit (Rate Limiting bereits in authentication.spec.js)
- [ ] **2FA (Two-Factor Authentication)** — _(Phase 4.1)_
- [ ] **OAuth2 Social Login** — _(Phase 4.1)_

## 2. BLOG MODULE (Wichtiges Package!)
- [ ] Blog Post erstellen
- [ ] Blog Post bearbeiten
- [ ] Blog Post veröffentlichen/unveröffentlichen
- [ ] Blog Kategorien verwalten
- [ ] Blog Kommentare (wenn aktiviert)
- [ ] Blog Settings
- [ ] RSS Feed
- [ ] Blog Frontend-Ansicht
- [ ] Pagination
- [ ] Search in Blog

## 3. MEDIA/FILE MANAGEMENT (Finder)
- [ ] Datei hochladen (verschiedene Formate)
- [ ] Ordner erstellen
- [ ] Dateien umbenennen
- [ ] Dateien löschen
- [ ] Bulk Upload
- [ ] Drag & Drop Upload
- [ ] ~~Bildbearbeitung~~ — _(nicht vorhanden: kein Image Editor im Finder)_
- [ ] Storage Settings
- [ ] File Permissions

## 4. WIDGET SYSTEM
_(Im System aktuell: Text Widget, Menu Widget, Login Widget, User Widget. Kein Feed Widget, Location Widget, Custom HTML Widget.)_
- [ ] Widget hinzufügen
- [ ] Widget konfigurieren
- [ ] Widget positionieren
- [ ] Widget kopieren
- [ ] Widget löschen
- [ ] Widget Visibility Rules
- [ ] Text Widget
- [ ] Menu Widget
- [ ] Login Widget
- [ ] User Widget

## 5. SITE/PAGE MANAGEMENT
- [ ] Seite erstellen (verschiedene Typen)
- [ ] Seite bearbeiten
- [ ] Seite Status (Published/Unpublished/Draft)
- [ ] Seite Menü-Zuweisung
- [ ] Seite Access Level
- [ ] Meta Tags (SEO)
- [ ] Seiten-Hierarchie
- [ ] Seite kopieren
- [ ] Bulk Operations

## 6. MENU SYSTEM
- [ ] Menü erstellen
- [ ] Menü-Items hinzufügen
- [ ] Menü-Items sortieren (Drag & Drop)
- [ ] Menü-Items bearbeiten
- [ ] Verschiedene Link-Typen
- [ ] Menü Positionen
- [ ] Menü Visibility

## 7. SYSTEM SETTINGS
- [ ] General Settings
- [ ] Site Settings (Title, Description, Logo)
- [ ] Maintenance Mode
- [ ] Localization Settings
- [ ] Mail Settings (SMTP Test)
- [ ] Cache Management
- [ ] System Information
- [ ] Update Check

## 8. DASHBOARD
- [ ] Dashboard Widgets
- [ ] Widget Reihenfolge ändern
- [ ] System Info Widget
- [ ] User Statistics
- [ ] Custom Dashboard Layout

## 9. THEME MANAGEMENT
- [ ] Theme aktivieren
- [ ] Theme Settings
- [ ] Theme Customizer (wenn vorhanden)
- [ ] Logo Upload
- [ ] Favicon Upload
- [ ] Custom CSS
- [ ] Widget Positions

## 10. EDITOR TESTING
- [ ] HTML Editor
- [ ] Markdown Editor
- [ ] Code Editor
- [ ] Media einfügen
- [ ] Links einfügen
- [ ] Shortcodes

## 11. SEARCH FUNCTIONALITY
- [ ] Global Search
- [ ] User Search
- [ ] Content Search
- [ ] Filter und Sortierung
- [ ] ~~Advanced Search~~ — _(nicht vorhanden: nur Basic Search)_

## 12. MULTI-LANGUAGE (Intl)
- [ ] Sprache wechseln
- [ ] Content übersetzen
- [ ] Language Detection

## 13. COMMENTS SYSTEM
_(Hinweis: Guest Comments nicht vorhanden – nur eingeloggte User.)_
- [ ] Kommentar schreiben (eingeloggte User)
- [ ] Kommentar moderieren
- [ ] Kommentar löschen
- [ ] Spam Protection

## 14. SECURITY FEATURES
- [ ] CSRF Protection
- [ ] XSS Prevention
- [ ] SQL Injection Prevention
- [ ] File Upload Security
- [ ] Session Management
- [ ] Captcha Integration

## 15. INTERNAL API TESTING
- [ ] Admin AJAX Endpoints
- [ ] Vue.js API Calls (nach Phase 3: axios statt vue-resource)
- [ ] Authentication Checks
- [ ] Error Handling
- [ ] **REST API v2** — _(Phase 4.2, OpenAPI/JWT)_

## 16. PERFORMANCE & EDGE CASES
- [ ] Large File Upload
- [ ] Many Widgets Performance
- [ ] Concurrent User Actions
- [ ] Session Timeout
- [ ] Browser Back/Forward
- [ ] Multiple Tabs

## 17. RESPONSIVE & BROWSER
- [ ] Mobile View
- [ ] Tablet View
- [ ] Different Browsers
- [ ] Touch Interactions
- [ ] Keyboard Navigation

## 18. ERROR HANDLING
- [ ] 404 Pages
- [ ] 403 Forbidden
- [ ] 500 Server Error
- [ ] Validation Errors
- [ ] Network Errors

## 19. ACCESSIBILITY
- [ ] Screen Reader Support
- [ ] Keyboard Only Navigation
- [ ] ARIA Labels
- [ ] Focus Management

## 20. SPECIAL PAGEKIT FEATURES
- [ ] Database Migration
- [ ] Cache Clear
- [ ] System Info Display
- [ ] Debug Mode
- [ ] Maintenance Mode

## Prioritäten für Implementation:

### 🔴 KRITISCH (Muss sofort getestet werden):
1. User Management & Permissions
2. Blog Module
3. Media/File Management
4. Site/Page Management
5. System Settings

### 🟡 WICHTIG (Sollte getestet werden):
6. Widget System
7. Menu System
8. Dashboard
9. Editor Testing
10. Security Features

### 🟢 NICE TO HAVE (Kann später ergänzt werden):
11. Multi-Language
12. Comments
13. API Testing
14. Accessibility
15. Performance Tests

## Test-Anzahl (IST vs. Ziel):
- **Aktuell (IST):** ~96 Tests in 12 Spec-Dateien (Kern-Features abgedeckt)
- **Minimum (Ziel):** ~80-100 Tests (Kritische Features) → **erreicht**
- **Optimal (Ziel):** ~150-200 Tests (Kritisch + Wichtig)
- **Vollständig (Ziel):** ~250-300 Tests (alle Features aus den Abschnitten 1–20 unten)

## Nächste Schritte:
1. ✅ Test-Config und Installation optimiert
2. ✅ Authentication, Dashboard, Settings optimiert
3. ✅ Blog, Media, Pages, Frontend, Menu, User, Widgets-Specs vorhanden (können bei Bedarf verfeinert werden)
4. 🔜 **GitHub Actions CI/CD für E2E** (entspricht TODO Schritt 2.2 CI/CD Pipeline)
5. 🔜 Test-Fixtures (SQL) vorbereiten (optional)
6. ✅ `test:e2e:install` verweist auf `specs/01-setup/installation.spec.js`

## CI/CD Status:
- ✅ Dependabot konfiguriert (`.github/dependabot.yml` – Composer, NPM, Docker)
- ✅ Testserver wird von Playwright selbst gestartet (`webServer: php pagekit start --no-ansi`); Reset auf Frischzustand = `config.php` + `pagekit.db` löschen
- ✅ Travis CI vorhanden (`.travis.yml`) – läuft **PHPUnit** mit PHP 8.2, 8.3, 8.4 (keine E2E)
- ❌ GitHub Actions für E2E noch nicht implementiert

## System-Informationen:
- **PHP:** 8.2-8.4
- **MySQL:** 8.4
- **SQLite:** 3.x
- **Vue.js:** 2.6 _(Phase 3: 2.7 Bridge → Vue 3.x; E2E-Helper/Selectors ggf. anpassen)_
- **UIkit:** 3.5 _(Phase 3: 3.21+)_
- **Node.js:** 18-20 (für Tests)

**Nicht im Scope dieses Plans (laut Roadmap):** Marketplace (Phase 5.6, API deaktiviert), Extension-Install per UI (nur manuell), REST API (Phase 4.2). Details: `TEST_PLAN_ANALYSIS_2025.md`.