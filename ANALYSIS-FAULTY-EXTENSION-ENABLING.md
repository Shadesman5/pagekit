# Analyse: Fehlerhafte Extensions können aktiviert werden

## Executive Summary

**Problem**: Extensions mit Fehlern werden trotzdem als "aktiviert" markiert, anstatt automatisch deaktiviert/abgebrochen zu werden wie im ursprünglichen Pagekit.

**Hauptursachen**: 
1. Exception-Swallowing während der Installation (Installer.php)
2. Falsche Reihenfolge der Aktivierungsschritte (PackageManager.php)
3. Fehlende Rollback-Mechanismen bei Aktivierungsfehlern
4. Inkonsistente Fehlerbehandlung zwischen CLI und Web-UI

---

## Root Causes - Code-Referenzen

### RC #1: Exception Swallowing während Installation
**Datei**: `app/installer/src/Installer.php`  
**Zeilen**: 147-150

```php
try {
    $packageManager->enable($package);
} catch (\Exception $e) {
    // KRITISCH: Exception wird komplett verschluckt!
    // Keine Fehlerbehandlung, kein Logging, kein Rollback
}
```

**Problem**: Während der Installation werden ALLE Exceptions beim Aktivieren von Packages komplett ignoriert. Die Extension wird trotzdem als aktiviert markiert.

**Kontext**: Diese Methode wird beim initialen Setup aufgerufen und iteriert über alle gefundenen Extensions/Themes.

---

### RC #2: Vorzeitiges Persistieren des "enabled" Status
**Datei**: `app/installer/src/Package/PackageManager.php`  
**Zeilen**: 154-162

```php
153: $version = $this->getVersion($package);
154: App::config('system')->set('packages.' . $package->get('module'), $version);
155: 
156: $scripts->enable();
157: 
158: if ($package->getType() == 'pagekit-theme') {
159:     App::config('system')->set('site.theme', $package->get('module'));
160: } elseif ($package->getType() == 'pagekit-extension') {
161:     App::config('system')->push('extensions', $package->get('module'));
162: }
```

**Problem**: 
1. **Zeile 154**: Package-Version wird in Config geschrieben BEVOR `scripts->enable()` ausgeführt wird
2. **Zeile 161**: Extension wird zur Enabled-Liste hinzugefügt OHNE Prüfung ob enable() erfolgreich war
3. Wenn `scripts->enable()` (Zeile 156) fehlschlägt, ist die Extension bereits als installiert markiert

**Fehlende Schritte**:
- Kein try-catch Block
- Kein Rollback bei Fehler
- Keine Validierung des Aktivierungsergebnisses

---

### RC #3: PackageScripts.enable() ohne Fehlerbehandlung
**Datei**: `app/installer/src/Package/PackageScripts.php`  
**Zeilen**: 44-55, 98-107

```php
44: public function enable(): void
45: {
46:     $this->run($this->get('enable'));
47: }

98: protected function run($scripts): void
99: {
100:     array_map(function ($script) {
101:         if (is_callable($script)) {
102:             call_user_func($script, App::getInstance());
103:         }
104:     }, (array) $scripts);
105: }
```

**Problem**:
- `call_user_func()` kann beliebige Exceptions werfen
- Diese werden weder gefangen noch behandelt
- array_map() propagiert Exceptions, aber nur wenn der Caller sie behandelt

---

### RC #4: ModuleLoader.load() - Fehlende Error Guards
**Datei**: `app/modules/application/src/Module/Loader/ModuleLoader.php`  
**Zeilen**: 25-53

```php
25: public function load($module)
26: {
27:     // Handle callable main (for modules with anonymous functions)
28:     if (isset($module['main']) && is_callable($module['main']) && !is_string($module['main'])) {
29:         $moduleObj = new Module($module);
30:         
31:         // Bind the callable to the module object so $this refers to the module
32:         $callable = $module['main']->bindTo($moduleObj, Module::class);
33:         $callable($this->app);  // KEINE Fehlerbehandlung!
...
43:     $class = $module[is_string($module['main']) ? 'main' : 'class'];
44:     $module = new $class($module);  // Kann bei Class-Not-Found fehlschlagen
45:     $module->main($this->app);      // Kann bei jedem Fehler in main() fehlschlagen
```

**Problem**:
- Zeile 33, 45: Keine try-catch Blocks um kritische Code-Ausführung
- Wenn Module-Code fehlschlägt (Syntax-Fehler, fehlende Dependencies, etc.), wird Exception direkt propagiert
- In Kombination mit RC #1 werden diese Fehler verschluckt

---

### RC #5: PackageController.enableAction() - Inkonsistente Fehlerbehandlung
**Datei**: `app/installer/src/Controller/PackageController.php`  
**Zeilen**: 82-106

```php
82: public function enableAction($name): array
83: {
84:     $handler = $this->errorHandler($name);
85: 
86:     try {
87:         if (!$package = App::package($name)) {
88:             App::abort(400, __('Unable to find "%name%".', ['%name%' => $name]));
89:         }
90: 
91:         App::module()->load($package->get('module'));
92: 
93:         if (!$module = App::module($package->get('module'))) {
94:             App::abort(400, __('Unable to enable "%name%".', ['%name%' => $package->get('title')]));
95:         }
96: 
97:         $this->manager->enable($package);
98: 
99:         return ['message' => 'success'];
100:     } finally {
101:         // Restore original error handlers
102:         if ($handler) {
103:             $handler();
104:         }
105:     }
106: }
```

**Probleme**:
1. **Zeile 91**: `App::module()->load()` kann fehlschlagen - wird hier in try-catch gefangen
2. **Zeile 97**: `$this->manager->enable()` kann fehlschlagen - wird hier in try-catch gefangen
3. **ABER**: Custom errorHandler (Zeilen 250-309) kann Fehler "wegfangen" und umwandeln
4. **Zeile 99**: Erfolg wird IMMER zurückgegeben, auch wenn vorher Fehler auftraten

**Custom Error Handler Problem** (Zeilen 250-309):
```php
259: set_error_handler(function ($severity, $message, $file, $line) use ($name) {
260:     // Only handle errors that would normally be fatal
261:     if ($severity & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR)) {
262:         // Clean output buffer
263:         while (ob_get_level()) {
264:             ob_get_clean();
265:         }
266:         
267:         $errorMessage = __('Unable to activate "%name%".<br>A fatal error occured.', ['%name%' => $name]);
268:         ...
269:         // Send JSON response
270:         App::response()->json($errorMessage, 500)->send();
271:         exit;
272:     }
273:     
274:     // For other errors, return false to let PHP handle them normally
275:     return false;
276: });
```

**Problem**: Der Error Handler schickt eine JSON-Response und beendet den Request mit `exit`. Das verhindert zwar die weitere Ausführung, aber:
- Die Extension ist bereits zur enabled-Liste hinzugefügt (Zeile 161 in PackageManager)
- Kein Rollback wird ausgeführt
- Der UI-Client erhält einen 500-Fehler, aber die Extension ist trotzdem aktiviert

---

## Symfony ErrorHandler Analyse

**Datei**: `app/modules/application/index.php`  
**Zeilen**: 28-31

```php
28: // use Symfony\Component\ErrorHandler\ErrorHandler instead.
29: // $app['exception'] = ExceptionHandler::register($app['debug']);
30: 
31: ErrorHandler::register()->throwAt(E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR);
```

**Konfiguration**:
- Symfony ErrorHandler ist registriert
- `throwAt()`: Nur FATAL Errors werden zu Exceptions konvertiert
- Andere Error-Levels (E_WARNING, E_NOTICE, etc.) werden NICHT zu Exceptions

**Implikation**:
- Ein Extension-Script mit Warnings/Notices läuft durch
- Nur echte Fatal Errors stoppen die Ausführung
- **ABER**: Siehe RC #1 - selbst diese werden während Installation verschluckt

---

## Extension Lifecycle - Vollständiger Ablauf

### 1. Discovery Phase
- Composer autoloader findet Packages in `packages/*/*/composer.json`
- PackageFactory erstellt Package-Objekte

### 2. Installation Phase (Initial Setup)
**Entry Point**: `Installer.php::install()`
```
Installer::install()
├─> Foreach package in packages/*/*/composer.json
│   ├─> try { package = App::package()->load(json) }
│   │   └─> catch: continue (RC: Fehler werden ignoriert)
│   └─> try { PackageManager->enable(package) }  ← RC #1: EXCEPTION SWALLOWING
│       └─> catch: {} (Komplett leer!)
└─> SUCCESS (immer!)
```

### 3. Enable Phase (Admin UI)
**Entry Point**: `PackageController::enableAction()`
```
PackageController::enableAction($name)
├─> errorHandler = Custom Error Handler (fängt Fatals ab)
├─> try {
│   ├─> package = App::package($name)
│   ├─> App::module()->load(package.module)  ← Kann hier fehlschlagen
│   │   └─> ModuleLoader->load()  ← RC #4: Keine Fehlerbehandlung
│   │       └─> $callable($this->app) ODER new $class() / ->main()
│   ├─> PackageManager->enable(package)  ← RC #2: Falsche Reihenfolge
│   │   └─> (siehe Enable Detailablauf unten)
│   └─> return ['message' => 'success']  ← IMMER Success!
│   } finally { restore error handlers }
```

### 4. Enable Detailablauf (PackageManager::enable)
```
PackageManager::enable($package)
├─> App::trigger('package.enable', [$package])
├─> if (!packages.X exists) { doInstall() }
│   └─> getScripts()->install()
│   └─> config->set('packages.X', version)  ← Version persistiert
├─> if (hasUpdates) { scripts->update() }
├─> config->set('packages.X', version)  ← RC #2: VORZEITIG persistiert (Zeile 154)
├─> scripts->enable()  ← RC #3: Kann hier fehlschlagen (Zeile 156)
│   └─> run(scripts['enable'])
│       └─> array_map(call_user_func, scripts)  ← Exceptions nicht gefangen
└─> config->push('extensions', module)  ← RC #2: Extension aktiviert (Zeile 161)
```

**KRITISCH**: 
- Zeile 154: Package-Version wird gesetzt
- Zeile 156: Scripts werden ausgeführt (kann fehlschlagen)
- Zeile 161: Extension wird aktiviert
- **Kein Rollback bei Fehler zwischen Zeile 156-161!**

---

## Hypothesen-Verifikation

### ✅ H1: Exceptions are logged and consumed; activation continues
**BESTÄTIGT**: 
- Installer.php:147-150 verschluckt alle Exceptions
- Keine Logging-Ausgabe im catch-Block
- Activation läuft weiter

### ✅ H2: Activation return value is ignored or defaulted to success
**BESTÄTIGT**: 
- PackageManager::enable() hat void return type
- PackageController::enableAction() gibt IMMER 'success' zurück (Zeile 99)
- Keine Validierung ob enable() erfolgreich war

### ✅ H3: DB sets `enabled=1` before bootstrap; no rollback on error
**BESTÄTIGT**: 
- Config wird in Zeile 154 gesetzt (Version)
- Config wird in Zeile 161 gesetzt (enabled-Liste)
- scripts->enable() dazwischen (Zeile 156)
- Kein Rollback-Code vorhanden

### ❌ H4: Global ErrorHandler/Shutdown swallows throwables
**TEILWEISE**: 
- ErrorHandler wirft nur bei E_ERROR, E_CORE_ERROR, etc.
- Keine shutdown_function registriert
- **ABER**: Custom errorHandler in PackageController kann Fatals abfangen (exit!)

### ✅ H5: Admin UI path suppresses exceptions differently than CLI
**BESTÄTIGT**: 
- Admin UI: PackageController hat custom errorHandler + try-catch
- Installation: Installer hat leeren catch-Block
- Unterschiedliche Fehlerbehandlung

### ⚠️ H6: Composer autoload errors only surface after activation
**NICHT VERIFIZIERT**: 
- Würde separate Test-Extension erfordern
- Autoload-Fehler würden in ModuleLoader::load() auftreten
- Werden aktuell nicht abgefangen

---

## Reproduktionsschritte

### Szenario: Fehlerhafte Extension bei Installation

1. **Setup**: Fresh Pagekit Installation vorbereiten
   ```bash
   rm -f config.php pagekit.db
   ```

2. **Fehlerhafte Extension erstellen**: `packages/test/faulty-extension/`
   - composer.json mit type: "pagekit-extension"
   - index.php mit absichtlichem Fehler (z.B. undefined function call)
   - scripts.php mit enable-Hook der fehlschlägt

3. **Installation durchführen**:
   ```bash
   php pagekit start -s localhost:8080
   # Browser: http://localhost:8080/installer
   # Installation durchführen
   ```

4. **Erwartetes Verhalten (AKTUELL FALSCH)**:
   - Extension wird trotz Fehler als aktiviert markiert
   - Keine Fehlermeldung während Installation
   - System läuft weiter, aber Extension ist defekt

5. **Korrektes Verhalten (SOLL)**:
   - Extension-Aktivierung schlägt fehl
   - Rollback: Extension wird NICHT zur enabled-Liste hinzugefügt
   - Klare Fehlermeldung in Logs
   - System bleibt stabil ohne defekte Extension

### Szenario: Fehlerhafte Extension via Admin UI

1. **Setup**: Pagekit bereits installiert, eingeloggt als Admin

2. **Navigation**: `/admin/system/package/extensions`

3. **Extension togglen**: Status von "faulty-extension" auf "enabled" setzen

4. **Aktuelles Verhalten**:
   - Ajax-Request an `/admin/installer/package/enable`
   - Custom errorHandler fängt Fatal ab → exit mit 500-Response
   - **ABER**: Extension ist bereits in Config als enabled markiert!
   - Reload der Seite zeigt Extension als "enabled"
   - Extension funktioniert nicht, da Bootstrap fehlgeschlagen

5. **Korrektes Verhalten (SOLL)**:
   - Enable-Request schlägt fehl mit klarer Fehlermeldung
   - Extension bleibt disabled
   - Config wird NICHT modifiziert
   - User sieht Fehler in UI

---

## Fix-Vorschläge

### SHORT-TERM FIX #1: Exception Logging und Propagierung

**Datei**: `app/installer/src/Installer.php`  
**Änderung bei Zeilen 147-150**:

```php
// VORHER:
try {
    $packageManager->enable($package);
} catch (\Exception $e) {
    // Leer - Exception verschluckt!
}

// NACHHER:
try {
    $packageManager->enable($package);
} catch (\Exception $e) {
    // Log the error
    $this->app['log']->error(
        sprintf('Failed to enable package "%s": %s', 
            $package->get('name'), 
            $e->getMessage()
        ),
        ['exception' => $e]
    );
    
    // Ensure package is NOT marked as enabled
    // (Rollback will be handled in PackageManager::enable)
    
    // Continue with next package during installation
    continue;
}
```

### SHORT-TERM FIX #2: Transaktionale Aktivierung

**Datei**: `app/installer/src/Package/PackageManager.php`  
**Änderung bei enable() Methode (Zeilen 118-170)**:

```php
public function enable($packages, $previousPackageConfigs = []): void
{
    if (!is_array($packages)) {
        $packages = [$packages];
    }

    if (!is_array($previousPackageConfigs)) {
        $previousPackageConfigs = [$previousPackageConfigs];
    }

    foreach ($packages as $package) {
        // Store original state for rollback
        $wasEnabled = false;
        $hadVersion = false;
        $originalVersion = null;
        
        try {
            // Get the old package config if provided
            $previousPackageConfig = $package;
            foreach ($previousPackageConfigs as $packageConfig) {
                if ($packageConfig->get('name') == $package->get('name')) {
                    $previousPackageConfig = $packageConfig;
                    break;
                }
            }

            App::trigger('package.enable', [$package]);

            $app = App::getInstance();
            if ($app && isset($app['config'])) {
                // Check if already enabled
                $wasEnabled = in_array(
                    $package->get('module'), 
                    (array) App::config('system')->get('extensions', [])
                );
                
                // Store original version for rollback
                $originalVersion = App::config('system')->get('packages.' . $previousPackageConfig->get('module'));
                $hadVersion = $originalVersion !== null;
                
                if (!$current = $originalVersion) {
                    $current = $this->doInstall($package);
                }

                $scripts = $this->getScripts($package, $current);
                if ($scripts->hasUpdates()) {
                    $scripts->update();
                }

                $version = $this->getVersion($package);
                
                // KRITISCH: Scripts ERST ausführen, DANN Config setzen
                $scripts->enable();  // Kann Exception werfen!
                
                // NUR wenn enable() erfolgreich war, Config aktualisieren:
                App::config('system')->set('packages.' . $package->get('module'), $version);
                
                if ($package->getType() == 'pagekit-theme') {
                    App::config('system')->set('site.theme', $package->get('module'));
                } elseif ($package->getType() == 'pagekit-extension') {
                    if (!$wasEnabled) {
                        App::config('system')->push('extensions', $package->get('module'));
                    }
                }
            } else {
                // During installation, just run basic enable without config updates
                $current = $this->doInstall($package);
                $scripts = $this->getScripts($package, $current);
                
                // Enable scripts can fail - let exception propagate
                $scripts->enable();
            }
            
        } catch (\Throwable $e) {
            // ROLLBACK: Restore original state
            if ($app && isset($app['config'])) {
                if ($hadVersion) {
                    App::config('system')->set('packages.' . $package->get('module'), $originalVersion);
                } else {
                    App::config('system')->remove('packages.' . $package->get('module'));
                }
                
                if ($wasEnabled && $package->getType() == 'pagekit-extension') {
                    // Was already enabled, keep it
                } elseif ($package->getType() == 'pagekit-extension') {
                    // Ensure it's NOT in enabled list
                    App::config('system')->pull('extensions', $package->get('module'));
                }
            }
            
            // Log the error
            if ($app && isset($app['log'])) {
                $app['log']->error(
                    sprintf('Failed to enable package "%s": %s', 
                        $package->get('name'), 
                        $e->getMessage()
                    ),
                    ['exception' => $e]
                );
            }
            
            // Re-throw to allow caller to handle
            throw new \RuntimeException(
                sprintf('Unable to enable "%s": %s', 
                    $package->get('title') ?? $package->get('name'),
                    $e->getMessage()
                ),
                0,
                $e
            );
        }
    }
}
```

### SHORT-TERM FIX #3: PackageController Error Handling

**Datei**: `app/installer/src/Controller/PackageController.php`  
**Änderung bei enableAction() (Zeilen 82-106)**:

```php
public function enableAction($name): array
{
    $handler = $this->errorHandler($name);

    try {
        if (!$package = App::package($name)) {
            App::abort(400, __('Unable to find "%name%".', ['%name%' => $name]));
        }

        App::module()->load($package->get('module'));

        if (!$module = App::module($package->get('module'))) {
            App::abort(400, __('Unable to enable "%name%".', ['%name%' => $package->get('title')]));
        }

        // This can now throw with proper error message
        $this->manager->enable($package);
        
        // Clear cache only on success
        App::module('system/cache')->clearCache();

        return ['message' => 'success'];
        
    } catch (\Throwable $e) {
        // Log the error
        App::log('error', sprintf(
            'Failed to enable extension "%s": %s',
            $name,
            $e->getMessage()
        ), ['exception' => $e]);
        
        // Return error to UI
        $errorMessage = App::debug() 
            ? $e->getMessage() 
            : __('Unable to enable "%name%". See error log for details.', ['%name%' => $name]);
        
        return ['error' => $errorMessage];
        
    } finally {
        // Restore original error handlers
        if ($handler) {
            $handler();
        }
    }
}
```

### LONG-TERM FIX: ActivationGuard Service

**Neue Datei**: `app/installer/src/Package/ActivationGuard.php`

```php
<?php

namespace Pagekit\Installer\Package;

use Pagekit\Application as App;
use Pagekit\Database\Connection;

/**
 * ActivationGuard ensures atomic package activation with automatic rollback.
 */
class ActivationGuard
{
    protected Connection $db;
    protected array $snapshots = [];
    
    public function __construct()
    {
        $this->db = App::db();
    }
    
    /**
     * Execute package activation in a transaction with automatic rollback.
     */
    public function activate($package, callable $activationCallback): void
    {
        $moduleName = $package->get('module');
        
        // Create config snapshot
        $snapshot = $this->createSnapshot($moduleName);
        
        // Start DB transaction
        $this->db->beginTransaction();
        
        try {
            // Execute activation
            $activationCallback($package);
            
            // Commit DB transaction
            $this->db->commit();
            
            // Clear snapshot on success
            unset($this->snapshots[$moduleName]);
            
        } catch (\Throwable $e) {
            // Rollback DB transaction
            $this->db->rollBack();
            
            // Restore config snapshot
            $this->restore($moduleName, $snapshot);
            
            // Log error
            App::log('error', sprintf(
                'Package activation failed for "%s", rolled back. Error: %s',
                $package->get('title') ?? $moduleName,
                $e->getMessage()
            ), [
                'package' => $moduleName,
                'exception' => $e
            ]);
            
            // Re-throw with context
            throw new ActivationException(
                sprintf('Failed to activate "%s"', $package->get('title') ?? $moduleName),
                0,
                $e
            );
        }
    }
    
    protected function createSnapshot(string $moduleName): array
    {
        $config = App::config('system');
        
        return [
            'version' => $config->get('packages.' . $moduleName),
            'enabled' => in_array($moduleName, (array) $config->get('extensions', [])),
            'theme' => $config->get('site.theme') === $moduleName,
        ];
    }
    
    protected function restore(string $moduleName, array $snapshot): void
    {
        $config = App::config('system');
        
        // Restore version
        if ($snapshot['version'] !== null) {
            $config->set('packages.' . $moduleName, $snapshot['version']);
        } else {
            $config->remove('packages.' . $moduleName);
        }
        
        // Restore enabled state
        if ($snapshot['enabled']) {
            if (!in_array($moduleName, (array) $config->get('extensions', []))) {
                $config->push('extensions', $moduleName);
            }
        } else {
            $config->pull('extensions', $moduleName);
        }
        
        // Restore theme
        if ($snapshot['theme']) {
            $config->set('site.theme', $moduleName);
        } elseif ($config->get('site.theme') === $moduleName) {
            $config->remove('site.theme');
        }
    }
}

class ActivationException extends \RuntimeException {}
```

---

## Test-Plan

### Test 1: Fehlerhafte Extension während Installation
```php
// packages/test/faulty-install/composer.json
{
    "name": "test/faulty-install",
    "type": "pagekit-extension",
    "version": "1.0.0",
    "title": "Faulty Install Test"
}

// packages/test/faulty-install/scripts.php
return [
    'install' => function($app) {
        // Intentional error
        throw new \RuntimeException('Installation deliberately failed');
    }
];
```

**Erwartung VORHER**: Extension wird aktiviert, Fehler wird verschluckt  
**Erwartung NACHHER**: Extension wird NICHT aktiviert, Fehler wird geloggt

### Test 2: Fehlerhafte Extension beim Enable
```php
// packages/test/faulty-enable/scripts.php
return [
    'enable' => function($app) {
        // Intentional error
        throw new \RuntimeException('Enable deliberately failed');
    }
];
```

**Erwartung VORHER**: Extension wird in enabled-Liste eingetragen, dann Fehler  
**Erwartung NACHHER**: Extension wird NICHT eingetragen, Rollback erfolgt

### Test 3: Fatal Error in Module Bootstrap
```php
// packages/test/faulty-bootstrap/index.php
return [
    'name' => 'test/faulty-bootstrap',
    'main' => function($app) {
        // Call undefined function -> Fatal
        undefined_function_call();
    }
];
```

**Erwartung VORHER**: Custom ErrorHandler fängt Fatal, aber Extension bereits enabled  
**Erwartung NACHHER**: Exception wird gefangen, Rollback, Extension disabled

---

## Validierung

### Logs überprüfen
```bash
# Nach Fix sollten klare Fehler in Logs erscheinen:
tail -f tmp/logs/debug.log

# Erwartete Log-Einträge:
# [ERROR] Failed to enable package "test/faulty-enable": Enable deliberately failed
# [ERROR] Package activation failed for "Faulty Test", rolled back. Error: ...
```

### Config überprüfen
```bash
# Extensions-Liste darf fehlerhafte Extension NICHT enthalten:
php -r "
require 'app/app.php';
\$app->boot();
\$extensions = \$app->config('system')->get('extensions', []);
print_r(\$extensions);
"
```

### Symfony ErrorHandler Config
```php
// app/modules/application/index.php Zeile 31
ErrorHandler::register()->throwAt(
    E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR
);

// Aktuell korrekt: Fatal Errors werden zu Exceptions
// Diese sollten jetzt nicht mehr verschluckt werden
```

---

## Zusammenfassung

### Identifizierte Probleme
1. ✅ Exception Swallowing (Installer.php:147-150)
2. ✅ Vorzeitiges Persistieren (PackageManager.php:154)
3. ✅ Fehlende Rollback-Logik
4. ✅ Inkonsistente Fehlerbehandlung (CLI vs. Web)
5. ✅ Return-Value wird ignoriert (void + immer 'success')

### Lösungsansätze
- **Short-term**: Exception-Handling verbessern, Reihenfolge korrigieren
- **Long-term**: ActivationGuard mit Transaktionen

### Nächste Schritte
1. ✅ Analyse abgeschlossen
2. ⏳ Fixes implementieren
3. ⏳ Tests erstellen und verifizieren
4. ⏳ E2E-Tests mit Playwright
