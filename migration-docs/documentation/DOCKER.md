# Docker Setup für Pagekit

Diese Docker-Konfiguration stellt eine vollständige Entwicklungsumgebung für Pagekit bereit.

## Voraussetzungen

-   Docker
-   Docker Compose

## Schnellstart

1. Repository klonen:

```bash
git clone https://github.com/cssailing/pagekit.git
cd pagekit
```

2. Docker-Container starten:

```bash
# Mit MySQL (Standard):
docker-compose up -d

# Nur SQLite (ohne MySQL/phpMyAdmin):
docker-compose --profile sqlite up -d web node
```

3. Frontend-Assets bauen:

```bash
# Development (with file watching):
docker-compose up -d node

# Production build:
docker-compose exec node yarn compile-js --mode=production
docker-compose exec node yarn compile-less
```

## Services

| Service    | URL                   | Beschreibung            |
| ---------- | --------------------- | ----------------------- |
| Pagekit    | http://localhost:8080 | Hauptanwendung          |
| phpMyAdmin | http://localhost:8081 | Datenbankadministration |

## Database-Zugangsdaten

-   **Host:** mysql (intern) / localhost:3306 (extern)
-   **Database:** pagekit
-   **Username:** pagekit
-   **Password:** pagekit
-   **Root Password:** pagekit

## Nützliche Befehle

### Container-Status prüfen:

```bash
docker-compose ps
```

### Logs anzeigen:

```bash
docker-compose logs -f web
```

### In PHP-Container einsteigen:

```bash
docker-compose exec web bash
```

### Composer-Befehle ausführen:

```bash
docker-compose exec web composer install
```

### Yarn-Befehle ausführen:

```bash
docker-compose exec node yarn watch-js
```

### Container stoppen:

```bash
docker-compose down
```

### Container mit Volumes löschen:

```bash
docker-compose down -v
```

## Entwicklung

-   Alle Dateien werden live synchronisiert
-   Node.js Container überwacht automatisch Änderungen
-   PHP-Dateien werden sofort übernommen
-   MySQL-Daten bleiben in Volumes erhalten

## Technische Details

-   **PHP:** 8.4 mit Apache (Mindestanforderung: 8.2)
-   **Database Options:**
    -   **MySQL:** 8.4 (Standard, mit phpMyAdmin)
    -   **SQLite:** 3.x (Built-in PHP extension)
-   **Node.js:** 18 (Alpine)
-   **Composer:** 2.0+ (neueste Version)
-   **Yarn:** 1.22.22 (explizit installiert für Konsistenz)
-   **phpMyAdmin:** Latest (nur bei MySQL)

## Troubleshooting

### Port bereits belegt:

Ändere die Ports in `docker-compose.yml`:

```yaml
ports:
    - '8090:80' # Statt 8080:80
```

### Permissions-Probleme:

```bash
docker-compose exec web chown -R www-data:www-data /var/www/html
```
