# Log File-Handler Update

## Problem

Der Log-Service in Pagekit schrieb ursprünglich **nur in die Debug-Bar** (Browser), nicht in Dateien. Daher waren keine Error-Logs in `tmp/logs/debug.log` sichtbar.

## Lösung

File-Handler zu `app/modules/log/index.php` hinzugefügt.

### Änderung

**Datei**: `app/modules/log/index.php`

**Was wurde hinzugefügt**:
1. Monolog `StreamHandler` importiert
2. File-Handler konfiguriert der in `tmp/logs/debug.log` schreibt
3. Log-Directory wird automatisch erstellt falls nicht vorhanden
4. Log-Level: DEBUG (alle Nachrichten werden geloggt)

### Code

```php
// Add file handler for persistent logging
if (isset($app['path.logs'])) {
    $logFile = $app['path.logs'] . '/debug.log';
    
    // Ensure log directory exists
    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }
    
    // Add stream handler with DEBUG level
    $streamHandler = new StreamHandler($logFile, Level::Debug);
    $logger->pushHandler($streamHandler);
}
```

## Verwendung

### Logs anzeigen

Nach der Änderung werden alle Logs in `tmp/logs/debug.log` geschrieben:

```bash
# Echtzeit-Log-Anzeige
tail -f tmp/logs/debug.log

# Nur Fehler anzeigen
grep "ERROR" tmp/logs/debug.log

# Nur Extension-Fehler anzeigen
grep "Failed to enable" tmp/logs/debug.log

# Letzte 50 Zeilen
tail -50 tmp/logs/debug.log
```

### Test-Extensions aktivieren

Um Error-Logs zu erzeugen:

1. Gehe zu `/admin/system/package/extensions`
2. Versuche eine der Test-Extensions zu aktivieren:
   - Faulty Enable Test
   - Faulty Bootstrap Test  
   - Faulty Install Test
3. Prüfe `tmp/logs/debug.log`

**Erwartete Log-Einträge**:
```
[2025-10-06 XX:XX:XX] log.ERROR: Failed to enable package "test/faulty-enable": Unable to enable "Faulty Enable Test": TEST ERROR: Enable hook deliberately failed to test error handling
```

## Permissions

Falls Logs nicht geschrieben werden:

```bash
# Log-Directory beschreibbar machen
chmod 777 tmp/logs/

# Log-Datei erstellen und beschreibbar machen
touch tmp/logs/debug.log
chmod 666 tmp/logs/debug.log
```

## Log-Format

Monolog verwendet folgendes Format:
```
[TIMESTAMP] CHANNEL.LEVEL: MESSAGE CONTEXT
```

Beispiel:
```
[2025-10-06 13:45:12] log.ERROR: Failed to enable package "test/faulty-enable": TEST ERROR: Enable hook deliberately failed to test error handling {"exception":"[object] (RuntimeException..."}
```

## Alternative: Debug-Bar

Logs sind auch in der Debug-Bar sichtbar (wenn aktiviert):
- Im Browser: Debug-Bar am unteren Bildschirmrand
- Tab: "Logs"
- Zeigt alle Log-Nachrichten

## Status

✅ File-Handler aktiviert  
✅ Syntax geprüft  
✅ Bereit zum Testen
