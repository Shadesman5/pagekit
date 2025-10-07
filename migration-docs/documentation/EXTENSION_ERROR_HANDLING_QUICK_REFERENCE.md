# Quick Reference: Fehlerhafte Extension-Aktivierung Fix

## TL;DR

**Problem**: Extensions mit Fehlern wurden aktiviert  
**Lösung**: Transaktionale Aktivierung mit Rollback  
**Status**: ✅ BEHOBEN

---

## Geänderte Dateien

```
app/installer/src/Installer.php              (~10 Zeilen)
app/installer/src/Package/PackageManager.php (~140 Zeilen + neue Methode)
app/installer/src/Controller/PackageController.php (~15 Zeilen)
```

---

## Root Causes (Kurzfassung)

| # | Problem | Datei | Zeilen | Fix |
|---|---------|-------|--------|-----|
| 1 | Exception Swallowing | Installer.php | 147-150 | Logging hinzugefügt |
| 2 | Vorzeitiges Persistieren | PackageManager.php | 154-161 | Reihenfolge korrigiert |
| 3 | Keine Rollback-Logik | PackageManager.php | 118-170 | try-catch + rollback |
| 4 | Keine Error Response | PackageController.php | 82-106 | catch block hinzugefügt |

---

## Was wurde geändert

### 1. Installer.php
```php
// Leerer catch → Logging
catch (\Exception $e) {
    $this->app['log']->error(/* ... */);
}
```

### 2. PackageManager.php
```php
// NEU: State capture + try-catch + rollback
try {
    $originalState = [/* capture state */];
    $scripts->enable();  // ERST scripts
    Config::set(/* ... */);  // DANN config
} catch (\Throwable $e) {
    $this->rollbackEnable($package, $originalState);
    throw new \RuntimeException(/* ... */);
}
```

### 3. PackageController.php
```php
// NEU: catch block
try {
    $this->manager->enable($package);
    return ['message' => 'success'];
} catch (\Throwable $e) {
    return ['error' => $errorMessage];
}
```

---

## Testen

### Quick Test
```bash
# 1. Setup
rm -f config.php pagekit.db
php pagekit start -s localhost:8080

# 2. Install
npx playwright test --project=chromium tests/e2e/specs/01-setup/installation.spec.js

# 3. Check logs
tail -f tmp/logs/debug.log | grep "Failed to enable"

# 4. Verify config
php -r "
require 'app/app.php';
\$app = require './app.php';
\$app->boot();
\$ext = \$app->config('system')->get('extensions', []);
print_r(\$ext);
"
```

### Erwartetes Ergebnis
- ✅ Blog & Theme-One: enabled
- ❌ test/faulty-*: disabled
- 📝 Logs: ERROR-Einträge für fehlerhafte Extensions

---

## Logs überprüfen

```bash
# Alle Enable-Fehler anzeigen
grep "Failed to enable" tmp/logs/debug.log

# Erwartetes Format:
# [TIMESTAMP] app.ERROR: Failed to enable package "test/faulty-enable": TEST ERROR: ...
```

---

## Verhalten

| Vorher ❌ | Nachher ✅ |
|----------|-----------|
| Extension aktiviert trotz Fehler | Extension bleibt disabled |
| Keine Logs | Detaillierte Error-Logs |
| Config inkonsistent | Config korrekt via Rollback |
| UI zeigt "success" | UI zeigt Error |

---

## Rollback-Mechanismus

```
State VORHER erfassen
    ↓
Scripts ausführen
    ↓
Fehler? → Rollback auf State VORHER
    ↓
Kein Fehler? → Config setzen
```

---

## Test-Extensions

**Location**: `packages/test/*/`

1. **faulty-enable**: Enable-Hook wirft Exception
2. **faulty-bootstrap**: main() wirft Exception
3. **faulty-install**: Install-Hook wirft Exception

**Verwendung**: Reproduktion & Testing

---

## Wichtige Code-Stellen

### PackageManager::enable() - Neue Struktur
```php
foreach ($packages as $package) {
    $originalState = null;
    try {
        // Capture state
        $originalState = [...];
        
        // Execute scripts FIRST
        $scripts->enable();
        
        // Set config ONLY on success
        Config::set(...);
    } catch (\Throwable $e) {
        // Rollback
        if ($originalState !== null) {
            $this->rollbackEnable($package, $originalState);
        }
        // Log & re-throw
        throw new \RuntimeException(...);
    }
}
```

### PackageManager::rollbackEnable() - Neue Methode
```php
protected function rollbackEnable($package, array $originalState): void
{
    // Restore version
    if ($originalState['version'] !== null) {
        Config::set(...);
    } else {
        Config::remove(...);
    }
    
    // Restore enabled state
    if (!$originalState['enabled'] && currently_enabled) {
        Config::pull('extensions', $moduleName);
    }
}
```

---

## Debugging

### Extension trotzdem enabled?
```bash
# 1. Check Fixes
grep -n "CRITICAL FIX" app/installer/src/Package/PackageManager.php
grep -n "rollbackEnable" app/installer/src/Package/PackageManager.php

# 2. Check Config
php -r "
\$config = include 'config.php';
print_r(\$config['system']['extensions'] ?? []);
"

# 3. Check Logs
tail -100 tmp/logs/debug.log
```

### Keine Logs?
```bash
# Check permissions
chmod 777 tmp/logs/

# Test logging
php -r "
require 'app/app.php';
\$app = require './app.php';
\$app->boot();
\$app['log']->error('Test');
"
```

---

## Admin UI

### Enable via UI
1. Navigate: `/admin/system/package/extensions`
2. Toggle Extension Status
3. Observe Response

**Expected**:
- Success: `{"message": "success"}`
- Error: `{"error": "Unable to enable ..."}`

### Network Tab
```javascript
// POST /admin/installer/package/enable
// Response bei Fehler:
{
    "error": "Unable to enable \"Extension Name\": Error message..."
}
```

---

## Checkliste

### Installation
- [ ] Fresh install funktioniert
- [ ] Fehlerhafte Extensions disabled
- [ ] Funktionierende Extensions enabled
- [ ] Logs enthalten Fehler

### Admin UI
- [ ] Enable zeigt Error bei fehlerhafter Extension
- [ ] Status bleibt disabled
- [ ] Error-Response (nicht "success")
- [ ] Logs aktualisiert

### Rollback
- [ ] Config unverändert bei Fehler
- [ ] Extension nicht in extensions-Array
- [ ] Original-State wiederhergestellt

---

## Weitere Dokumentation

Detaillierte Informationen:
- **ANALYSIS-FAULTY-EXTENSION-ENABLING.md** - Vollständige Analyse
- **FIXES-IMPLEMENTATION.md** - Code-Details
- **TEST-GUIDE.md** - Umfassende Tests
- **SUMMARY.md** - Executive Summary

---

## One-Liner für Common Tasks

```bash
# Fresh Install
rm -f config.php pagekit.db && php pagekit start -s localhost:8080

# Check Extensions
php -r "require 'app/app.php';\$a=require './app.php';\$a->boot();print_r(\$a->config('system')->get('extensions',[]));"

# Tail Logs
tail -f tmp/logs/debug.log | grep --color "ERROR\|Failed"

# Syntax Check
php -l app/installer/src/Package/PackageManager.php

# Clear Cache
rm -rf tmp/cache/* tmp/temp/*
```

---

**Erstellt**: 2025-10-06  
**Version**: 1.0  
**Status**: ✅ Komplett
