# Test-Guide: Verifizierung der Fixes für fehlerhafte Extension-Aktivierung

## Voraussetzungen

### 1. Test-Umgebung vorbereiten
```bash
# Saubere Installation
rm -f config.php pagekit.db

# Test-Config erstellen
cp tests/e2e/config/test-config.example.json tests/e2e/config/test-config.json

# Test-Config anpassen (Admin-Credentials, DB-Settings)
# Beispiel für SQLite:
{
  "adminUser": {
    "username": "admin",
    "password": "admin123",
    "email": "admin@example.com"
  },
  "siteUrl": "http://localhost:8080",
  "database": {
    "driver": "sqlite",
    "prefix": "pk_"
  }
}
```

### 2. Pagekit starten
```bash
php pagekit start -s localhost:8080 --no-ansi
```

### 3. Installation durchführen
```bash
# Option A: Via Playwright E2E Test
npx playwright test --project=chromium-desktop tests/e2e/specs/01-setup/installation.spec.js

# Option B: Manuell im Browser
# http://localhost:8080/installer
# Folge den Installationsschritten
```

Nach erfolgreicher Installation solltest du auf `/admin/dashboard` eingeloggt sein.

---

## Test-Szenario 1: Fehlerhafte Extension während Installation

### Ziel
Verifizieren dass fehlerhafte Extensions während der Installation NICHT aktiviert werden und ordentlich geloggt werden.

### Setup
Die Test-Extensions `test/faulty-install`, `test/faulty-enable`, `test/faulty-bootstrap` sind bereits in `packages/test/` erstellt.

### Durchführung

#### 1.1 Fresh Installation mit fehlerhaften Extensions
```bash
# Sicherstellen dass Test-Extensions vorhanden sind
ls -la packages/test/*/composer.json

# Erwartete Ausgabe:
# packages/test/faulty-bootstrap/composer.json
# packages/test/faulty-enable/composer.json
# packages/test/faulty-install/composer.json

# Fresh Installation
rm -f config.php pagekit.db
npx playwright test --project=chromium-desktop tests/e2e/specs/01-setup/installation.spec.js
```

#### 1.2 Logs überprüfen
```bash
# Während Installation sollten Fehler geloggt werden
tail -f tmp/logs/debug.log

# Erwartete Log-Einträge:
# [ERROR] Failed to enable package "test/faulty-install" during installation: TEST ERROR: Install hook deliberately failed...
# [ERROR] Failed to enable package "test/faulty-enable" during installation: TEST ERROR: Enable hook deliberately failed...
# [ERROR] Failed to enable package "test/faulty-bootstrap" during installation: TEST ERROR: Required function not found...
```

#### 1.3 Config überprüfen
```bash
# Fehlerhafte Extensions sollten NICHT in der enabled-Liste sein
php -r "
require 'app/app.php';
\$app = require './app.php';
\$app->boot();
\$extensions = \$app->config('system')->get('extensions', []);
echo 'Enabled Extensions:' . PHP_EOL;
print_r(\$extensions);
echo PHP_EOL;

// Fehlerhafte Extensions sollten NICHT dabei sein
\$faulty = ['test/faulty-install', 'test/faulty-enable', 'test/faulty-bootstrap'];
\$enabled_faulty = array_intersect(\$extensions, \$faulty);
if (empty(\$enabled_faulty)) {
    echo '✅ SUCCESS: No faulty extensions are enabled' . PHP_EOL;
} else {
    echo '❌ FAIL: Faulty extensions are enabled: ' . implode(', ', \$enabled_faulty) . PHP_EOL;
}
"
```

### Erwartetes Ergebnis
- ✅ Installation läuft durch (bricht nicht ab)
- ✅ Blog und Theme-One sind aktiviert (funktionierende Extensions)
- ✅ Fehlerhafte Extensions sind NICHT aktiviert
- ✅ Klare Fehler-Logs für jede fehlerhafte Extension
- ✅ Dashboard ist erreichbar und funktioniert

---

## Test-Szenario 2: Fehlerhafte Extension via Admin UI

### Ziel
Verifizieren dass der Enable-Button im Admin-Panel korrekt reagiert bei fehlerhaften Extensions.

### Durchführung

#### 2.1 Admin-Panel öffnen
```bash
# Browser: http://localhost:8080/admin/system/package/extensions
# Login: admin / admin123 (oder deine Credentials)
```

#### 2.2 Extension aktivieren via UI

**Test Extension: "Faulty Enable Test"**

1. Gehe zu Extensions-Liste
2. Suche "Faulty Enable Test" (test/faulty-enable)
3. Klicke auf Enable (Status-Toggle)
4. Beobachte Response

**Erwartetes Verhalten:**
- ❌ Extension Toggle bleibt auf "Disabled"
- ⚠️ Error-Message wird angezeigt (je nach Debug-Mode)
  - **Debug ON**: "Unable to enable "Faulty Enable Test": TEST ERROR: Enable hook deliberately failed to test error handling"
  - **Debug OFF**: "Unable to enable "test/faulty-enable". See error log for details."
- 📝 Log-Eintrag wird erstellt

#### 2.3 Network Tab überprüfen (Browser DevTools)
```javascript
// Request:
POST /admin/installer/package/enable
{
    "name": "test/faulty-enable"
}

// Response (VORHER - FALSCH):
{
    "message": "success"  // ❌ Falsch, aber Extension ist kaputt
}

// Response (NACHHER - KORREKT):
{
    "error": "Unable to enable \"Faulty Enable Test\": TEST ERROR: Enable hook deliberately failed..."
}
// HTTP Status: 200 (Error ist im Response-Body)
```

#### 2.4 Logs überprüfen
```bash
tail -f tmp/logs/debug.log | grep "Failed to enable"

# Erwartete Ausgabe:
# [2025-10-06 XX:XX:XX] app.ERROR: Failed to enable package "test/faulty-enable": TEST ERROR: Enable hook deliberately failed to test error handling
```

#### 2.5 Config erneut prüfen
```bash
php -r "
require 'app/app.php';
\$app = require './app.php';
\$app->boot();
\$extensions = \$app->config('system')->get('extensions', []);

if (in_array('test/faulty-enable', \$extensions)) {
    echo '❌ FAIL: Faulty extension is enabled in config' . PHP_EOL;
    exit(1);
} else {
    echo '✅ SUCCESS: Faulty extension is NOT enabled' . PHP_EOL;
}
"
```

### Erwartetes Ergebnis
- ✅ UI zeigt Error-Message
- ✅ Extension bleibt disabled
- ✅ Config enthält Extension NICHT in enabled-Liste
- ✅ Log-Eintrag mit vollständigem Stack-Trace
- ✅ Kein System-Crash, Admin-Panel bleibt funktional

---

## Test-Szenario 3: Bootstrap-Fehler beim Enable

### Ziel
Verifizieren dass Fehler im Module-Bootstrap (main function) korrekt behandelt werden.

### Durchführung

#### 3.1 Enable "Faulty Bootstrap Test"
```bash
# Browser: http://localhost:8080/admin/system/package/extensions
# Enable "Faulty Bootstrap Test"
```

**Erwartetes Verhalten:**
- ❌ Enable schlägt fehl
- ⚠️ Error-Message: "Unable to enable "Faulty Bootstrap Test": TEST ERROR: Required function not found - simulating bootstrap failure"
- 📝 Log-Eintrag mit Stack-Trace

#### 3.2 Verify Module nicht geladen
```bash
php -r "
require 'app/app.php';
\$app = require './app.php';
\$app->boot();

// Module sollte NICHT geladen sein
\$module = \$app->module('test/faulty-bootstrap');
if (\$module === null) {
    echo '✅ SUCCESS: Faulty module is not loaded' . PHP_EOL;
} else {
    echo '❌ FAIL: Faulty module is loaded' . PHP_EOL;
}
"
```

### Erwartetes Ergebnis
- ✅ Module wird nicht geladen
- ✅ Extension ist nicht in enabled-Liste
- ✅ Fehler wird klar kommuniziert
- ✅ System bleibt stabil

---

## Test-Szenario 4: Rollback-Verifikation

### Ziel
Verifizieren dass bei einem fehlgeschlagenen Update/Enable der Original-Zustand wiederhergestellt wird.

### Setup
Wir benötigen eine Extension die:
1. Initial erfolgreich aktiviert werden kann
2. Bei einem Update fehlschlägt

### Durchführung

#### 4.1 Funktionierende Extension erstellen
```bash
mkdir -p packages/test/working-extension

cat > packages/test/working-extension/composer.json << 'EOF'
{
    "name": "test/working-extension",
    "type": "pagekit-extension",
    "version": "1.0.0",
    "title": "Working Extension",
    "extra": {
        "scripts": "scripts.php"
    }
}
EOF

cat > packages/test/working-extension/index.php << 'EOF'
<?php
return [
    'name' => 'test/working-extension',
    'type' => 'extension',
    'main' => function($app) {
        $app['log']->info('Working extension loaded');
    }
];
EOF

cat > packages/test/working-extension/scripts.php << 'EOF'
<?php
return [
    'install' => [
        function($app) {
            $app['log']->info('Working extension installed');
        }
    ],
    'enable' => [
        function($app) {
            $app['log']->info('Working extension enabled');
        }
    ]
];
EOF
```

#### 4.2 Extension aktivieren (sollte funktionieren)
```bash
# Via Admin UI: Enable "Working Extension"
# Sollte erfolgreich sein
```

#### 4.3 Extension "kaputt machen" (Update simulieren)
```bash
# scripts.php modifizieren um Fehler zu werfen
cat > packages/test/working-extension/scripts.php << 'EOF'
<?php
return [
    'enable' => [
        function($app) {
            throw new \RuntimeException('Updated version has a bug!');
        }
    ]
];
EOF
```

#### 4.4 Re-Enable versuchen
```bash
# 1. Extension disablen via UI
# 2. Extension wieder enablen via UI
# 3. Sollte jetzt fehlschlagen mit Error
```

#### 4.5 Verifizieren: Extension ist disabled
```bash
php -r "
require 'app/app.php';
\$app = require './app.php';
\$app->boot();
\$extensions = \$app->config('system')->get('extensions', []);

if (in_array('test/working-extension', \$extensions)) {
    echo '❌ FAIL: Extension is still marked as enabled after failed re-enable' . PHP_EOL;
    exit(1);
} else {
    echo '✅ SUCCESS: Extension was correctly rolled back to disabled state' . PHP_EOL;
}
"
```

### Erwartetes Ergebnis
- ✅ Extension ist nach fehlgeschlagenem Enable DISABLED
- ✅ Config wurde nicht modifiziert (Rollback erfolgte)
- ✅ Klare Fehlermeldung
- ✅ Alte Version/State bleibt erhalten

---

## Test-Szenario 5: Log-Ausgaben validieren

### Ziel
Verifizieren dass alle Fehler ordentlich geloggt werden mit ausreichend Kontext.

### Durchführung

#### 5.1 Mehrere Enable-Versuche
```bash
# Enable jede fehlerhafte Extension einzeln via Admin UI:
# 1. test/faulty-enable
# 2. test/faulty-bootstrap
# 3. test/faulty-install (falls sichtbar)
```

#### 5.2 Logs analysieren
```bash
cat tmp/logs/debug.log | grep -A 5 "Failed to enable"

# Erwartetes Format:
# [TIMESTAMP] app.ERROR: Failed to enable package "test/faulty-enable": TEST ERROR: Enable hook deliberately failed to test error handling
#   {
#     "exception": "RuntimeException Object with stack trace...",
#     "package": "test/faulty-enable"
#   }
```

#### 5.3 Log-Levels prüfen
```bash
# ERROR-Level sollte für alle fehlgeschlagenen Enables verwendet werden
grep "ERROR.*Failed to enable" tmp/logs/debug.log | wc -l

# Sollte mindestens 3 Einträge haben (eine pro Test-Extension)
```

### Erwartetes Ergebnis
- ✅ Alle Fehler haben ERROR-Level
- ✅ Exception-Details sind enthalten (Message + Stack Trace)
- ✅ Package-Name ist im Context
- ✅ Timestamps sind korrekt
- ✅ Logs sind lesbar und enthalten genug Info für Debugging

---

## Debug-Mode Tests

### Ziel
Verifizieren dass Error-Messages im Debug-Mode detaillierter sind.

### Durchführung

#### Debug Mode aktivieren
```php
// config.php - application.debug auf true setzen
[
    'application' => [
        'debug' => true,
        // ...
    ]
]
```

#### Enable fehlerhafter Extension
```bash
# Via Admin UI: Enable "Faulty Enable Test"
```

**Erwartete Error-Message (Debug ON):**
```
Unable to enable "Faulty Enable Test": TEST ERROR: Enable hook deliberately failed to test error handling
```

#### Debug Mode deaktivieren
```php
// config.php
[
    'application' => [
        'debug' => false,
    ]
]
```

#### Enable erneut versuchen
**Erwartete Error-Message (Debug OFF):**
```
Unable to enable "test/faulty-enable". See error log for details.
```

### Erwartetes Ergebnis
- ✅ Debug ON: Detaillierte Fehlermeldung mit Exception-Message
- ✅ Debug OFF: Generische Fehlermeldung, Verweis auf Logs
- ✅ Beide Modi: Extension bleibt disabled
- ✅ Beide Modi: Vollständige Logs werden geschrieben

---

## Performance & Edge-Cases

### Test: Multiple Extensions gleichzeitig
```bash
# Theoretisch könnte man mehrere Extensions auf einmal enablen
# PackageManager::enable() akzeptiert Arrays
```

### Test: Re-Enable einer bereits enabled Extension
```bash
# Blog-Extension ist bereits enabled
# Versuche erneut zu enablen via PackageManager

php -r "
require 'app/app.php';
\$app = require './app.php';
\$app->boot();

\$package = \$app->package('pagekit/blog');
\$manager = new \Pagekit\Installer\Package\PackageManager();

try {
    \$manager->enable(\$package);
    echo '✅ Re-enable successful (should be idempotent)' . PHP_EOL;
} catch (\Exception \$e) {
    echo '❌ Re-enable failed: ' . \$e->getMessage() . PHP_EOL;
}
"
```

**Erwartetes Verhalten:**
- ✅ Kein Fehler (idempotent)
- ✅ Extension bleibt enabled
- ✅ Keine doppelten Einträge in config

---

## Checkliste für finale Validierung

### Installation
- [ ] Fresh Install mit fehlerhaften Extensions läuft durch
- [ ] Fehlerhafte Extensions sind NICHT aktiviert
- [ ] Funktionierende Extensions (Blog, Theme) sind aktiviert
- [ ] Logs enthalten Error-Einträge für fehlerhafte Extensions
- [ ] Dashboard ist erreichbar

### Admin UI
- [ ] Enable fehlerhafter Extension zeigt Error-Message
- [ ] Extension-Status bleibt auf "disabled"
- [ ] Error-Response wird zurückgegeben (nicht "success")
- [ ] Logs enthalten vollständigen Stack-Trace
- [ ] Admin-Panel bleibt funktional (kein Crash)

### Rollback
- [ ] Config wird nicht modifiziert bei fehlgeschlagenem Enable
- [ ] Extension ist nicht in extensions-Array
- [ ] Package-Version bleibt unverändert
- [ ] Original-State wird wiederhergestellt

### Logging
- [ ] Alle Fehler werden mit ERROR-Level geloggt
- [ ] Exception-Details sind im Log
- [ ] Package-Name im Context
- [ ] Logs sind lesbar und hilfreich

### Debug-Mode
- [ ] Debug ON: Detaillierte Error-Messages
- [ ] Debug OFF: Generische Messages mit Log-Verweis
- [ ] Beide Modi: Vollständige Logs

### Edge-Cases
- [ ] Re-Enable funktioniert (idempotent)
- [ ] Keine doppelten Config-Einträge
- [ ] Performance ist akzeptabel
- [ ] Keine Memory-Leaks

---

## Cleanup nach Tests

```bash
# Test-Extensions entfernen (optional)
rm -rf packages/test/

# Oder: Test-Extensions behalten für zukünftige Tests
# Sie stören nicht, da sie disabled sind
```

---

## Troubleshooting

### Problem: Logs werden nicht geschrieben
**Lösung:**
```bash
# Prüfe tmp/logs/ Permissions
ls -la tmp/logs/
chmod 777 tmp/logs/

# Stelle sicher dass Log-Service funktioniert
php -r "
require 'app/app.php';
\$app = require './app.php';
\$app->boot();
\$app['log']->info('Test log entry');
echo 'Log service is working' . PHP_EOL;
"
```

### Problem: Extension wird trotz Fehler aktiviert
**Debug:**
```bash
# 1. Prüfe dass Fixes angewendet wurden
grep -n "CRITICAL FIX" app/installer/src/Package/PackageManager.php
# Sollte Zeile ~164 zeigen

# 2. Prüfe ob rollbackEnable existiert
grep -n "function rollbackEnable" app/installer/src/Package/PackageManager.php
# Sollte Zeile ~223 zeigen

# 3. Prüfe PackageController
grep -n "catch.*Throwable" app/installer/src/Controller/PackageController.php
# Sollte Zeile ~104 zeigen
```

### Problem: Tests schlagen fehl
**Debug:**
```bash
# Detaillierte Logs anschauen
tail -n 100 tmp/logs/debug.log

# PHP-Fehler prüfen
tail -n 50 tmp/logs/php_errors.log

# Cache clearen
rm -rf tmp/cache/*
rm -rf tmp/temp/*
```

---

## Zusammenfassung

Alle Tests sollten die erwarteten Ergebnisse liefern:
- ✅ Fehlerhafte Extensions werden NICHT aktiviert
- ✅ Rollback funktioniert korrekt
- ✅ Fehler werden ordentlich geloggt
- ✅ UI zeigt klare Error-Messages
- ✅ System bleibt stabil

Bei Problemen: Logs prüfen, Fixes verifizieren, Edge-Cases testen.
