# Frontend Error-Handling Fix

## Problem

Wenn eine Extension beim Enable fehlschlägt:
- ✅ Rollback funktioniert (Extension wird disabled)
- ✅ Logs werden geschrieben
- ❌ **KEINE Fehlermeldung in der UI**

Stattdessen:
- Kurz grüne Anzeige "Extension aktiviert"
- Seite lädt neu
- Extension ist wieder disabled
- User sieht keinen Fehler

## Root Cause

**Datei**: `app/installer/app/lib/package.js`

### Problem 1: Success-Response wird nicht validiert

```javascript
// VORHER:
enable(pkg) {
    return this.$http.post('admin/system/package/enable', { name: pkg.name }).then(() => {
        this.$notify('Extension enabled');  // ← Wird IMMER ausgeführt
        Vue.set(pkg, 'enabled', true);
        document.location.assign(...);     // ← Seite lädt neu
    }, this.error);
},
```

Das Backend gibt `['error' => 'Fehlermeldung']` zurück, aber das wird als **Success-Response** interpretiert (HTTP 200), daher wird der `.then()` Block ausgeführt statt `.catch()`.

### Problem 2: Error-Handler funktioniert nicht richtig

```javascript
// VORHER:
error(message) {
    this.$notify(message.data, 'danger');  // ← TypeError wenn message.data undefined
}
```

## Lösung

### Fix 1: Success-Response validieren

```javascript
// NACHHER:
enable(pkg) {
    return this.$http.post('admin/system/package/enable', { name: pkg.name }).then((response) => {
        // Check if response contains an error (even with 200 status)
        if (response.data && response.data.error) {
            this.$notify(response.data.error, 'danger');
            return;  // ← WICHTIG: Seite lädt NICHT neu!
        }
        
        this.$notify(this.$trans('"%title%" enabled.', { title: pkg.title }));
        Vue.set(pkg, 'enabled', true);
        document.location.assign(this.$url(`admin/system/package/${pkg.type === 'pagekit-theme' ? 'themes' : 'extensions'}`));
    }, this.error);
},
```

**Änderung**:
- Prüfe ob `response.data.error` existiert
- Wenn ja: Zeige Fehlermeldung, **OHNE** Seite neu zu laden
- Wenn nein: Alles wie vorher

### Fix 2: Robuster Error-Handler

```javascript
// NACHHER:
error(response) {
    // Handle different error response formats
    let message = 'An error occurred';
    
    if (response && response.data) {
        if (typeof response.data === 'string') {
            message = response.data;
        } else if (response.data.error) {
            message = response.data.error;
        } else if (response.data.message) {
            message = response.data.message;
        }
    } else if (typeof response === 'string') {
        message = response;
    }
    
    this.$notify(message, 'danger');
}
```

**Änderung**:
- Unterstützt verschiedene Response-Formate
- Kein TypeError mehr bei undefined
- Fallback auf generische Nachricht

## Nach dem Fix

### Erwartetes Verhalten

**Extension mit Fehler aktivieren:**

1. User klickt auf Status-Toggle
2. Request geht an Backend
3. Backend gibt zurück: `{"error": "Unable to enable \"Blog\": TEST: Blog enable absichtlich fehlgeschlagen"}`
4. Frontend prüft `response.data.error`
5. **Rote Notification wird angezeigt** mit Fehlermeldung
6. Extension bleibt disabled
7. **Seite lädt NICHT neu**
8. User sieht den Fehler!

**Extension ohne Fehler aktivieren:**

1. User klickt auf Status-Toggle
2. Request geht an Backend
3. Backend gibt zurück: `{"message": "success"}`
4. Frontend prüft `response.data.error` → nicht vorhanden
5. Grüne Notification: "Extension aktiviert"
6. Extension wird enabled
7. Seite lädt neu

## Testing

### 1. JavaScript neu kompilieren

```bash
cd app/installer
yarn compile-js
```

### 2. Cache leeren

```bash
rm -rf tmp/cache/*
```

### 3. Blog-Extension testen

Blog ist noch mit Fehler (`scripts.php` Zeile 57):
```php
'enable' => function ($app) {
    throw new \RuntimeException('TEST: Blog enable absichtlich fehlgeschlagen');
},
```

**Test:**
1. Gehe zu Extensions
2. Blog ist disabled
3. Klicke auf Status-Toggle
4. **Erwartung**: Rote Fehlermeldung erscheint
5. Blog bleibt disabled
6. Seite lädt NICHT neu

### 4. Nach Test: Blog reparieren

Entferne die Test-Exception aus `packages/pagekit/blog/scripts.php`:
```php
'enable' => function ($app) {
    // throw new \RuntimeException('TEST: Blog enable absichtlich fehlgeschlagen');
},
```

Oder besser: Ganzen enable-Block löschen wenn leer:
```php
// 'enable' => function ($app) {
//     // Nothing to do
// },
```

## Betroffene Dateien

- ✅ `app/installer/app/lib/package.js` - Source-Datei geändert
- ✅ `app/installer/app/bundle/extensions.js` - Wird automatisch kompiliert

## Status

✅ Code geändert  
✅ JavaScript kompiliert  
⏳ Bereit zum Testen
