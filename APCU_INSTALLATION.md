# APCu Installation für Pagekit

## Was ist APCu?

APCu (APC User Cache) ist ein In-Memory Cache für PHP, der Daten direkt im RAM speichert. Dies macht ihn deutlich schneller als dateibasierte Caches.

## Vorteile

- ⚡ **Extrem schnell**: Direkter Speicherzugriff ohne Dateisystem
- 💾 **Persistent**: Überlebt PHP-Requests
- 🔄 **Automatisch**: Keine manuelle Verwaltung nötig
- 📈 **Performance**: Reduziert Datenbankabfragen erheblich

## Installation

### Ubuntu/Debian

```bash
# APCu installieren
sudo apt-get update
sudo apt-get install php-apcu

# Webserver neustarten
sudo systemctl restart apache2
# oder für nginx:
sudo systemctl restart php-fpm
```

### CentOS/RHEL/Fedora

```bash
# APCu installieren
sudo yum install php-pecl-apcu
# oder
sudo dnf install php-pecl-apcu

# Webserver neustarten
sudo systemctl restart httpd
```

### Docker

In deiner `Dockerfile`:

```dockerfile
# APCu Installation
RUN pecl install apcu \
    && docker-php-ext-enable apcu

# Optional: APCu konfigurieren
RUN echo "apc.enabled=1" >> /usr/local/etc/php/conf.d/apcu.ini \
    && echo "apc.shm_size=128M" >> /usr/local/etc/php/conf.d/apcu.ini
```

### macOS (mit Homebrew)

```bash
# APCu über PECL installieren
pecl install apcu

# In php.ini aktivieren
echo "extension=apcu.so" >> /usr/local/etc/php/8.2/php.ini
```

## Konfiguration

Empfohlene Einstellungen in `php.ini`:

```ini
; APCu aktivieren
apc.enabled=1

; Shared Memory Größe (Standard: 32M)
apc.shm_size=128M

; TTL für Cache-Einträge (0 = unbegrenzt)
apc.ttl=0

; TTL für Garbage Collection
apc.gc_ttl=3600

; Aktiviere CLI (für Tests)
apc.enable_cli=1
```

## Überprüfung

Nach der Installation kannst du prüfen, ob APCu funktioniert:

```php
<?php
// test-apcu.php
if (function_exists('apcu_fetch')) {
    echo "APCu ist installiert und aktiv!\n";
    
    // Test speichern
    apcu_store('test', 'Hello APCu!', 60);
    
    // Test abrufen
    $value = apcu_fetch('test');
    echo "Gespeicherter Wert: $value\n";
} else {
    echo "APCu ist nicht verfügbar.\n";
}
```

## In Pagekit verwenden

Nach der Installation wird APCu automatisch in Pagekit erkannt:

1. Gehe zu **System → Einstellungen → Cache**
2. Wähle "APCu Speicher" oder lass "Auto" es automatisch auswählen
3. Speichern

## Performance-Vergleich

| Cache-Typ | Geschwindigkeit | Use Case |
|-----------|----------------|----------|
| APCu | ⚡⚡⚡⚡⚡ | Produktion mit viel Traffic |
| PHP Datei | ⚡⚡⚡⚡ | Standard-Produktion |
| Datei | ⚡⚡⚡ | Entwicklung/kleine Sites |

## Troubleshooting

### APCu wird nicht angezeigt

1. Prüfe PHP-Version: `php -v` (PHP 7.0+ benötigt)
2. Prüfe Installation: `php -m | grep apcu`
3. Prüfe Konfiguration: `php -i | grep apc`

### Cache wird nicht geleert

```bash
# APCu Cache manuell leeren
php -r "apcu_clear_cache();"
```

### Monitoring

Für APCu-Monitoring kannst du [APCu Panel](https://github.com/krakjoe/apcu/blob/master/apc.php) verwenden.

## Empfehlung

- **Entwicklung**: Datei-Cache ist ausreichend
- **Staging**: PHP Datei-Cache empfohlen
- **Produktion**: APCu für beste Performance

APCu lohnt sich besonders bei:
- Websites mit hohem Traffic
- Vielen gleichzeitigen Benutzern
- Komplexen Datenbankabfragen
- API-Endpoints mit hoher Last