# Pagekit Background Agents für Cursor

Diese Konfiguration ermöglicht es, Background Agents in Cursor zu verwenden, um die Pagekit-Modernisierung automatisiert durchzuführen.

## Workspace-Layout

Cursor mountet das geklonte Repository unter `/workspace`. Das Dockerfile setzt `WORKDIR /workspace`, sodass alle Scripts und Befehle im Projektverzeichnis laufen.

## Setup

1. **GitHub Token erstellen**:

    - Gehe zu GitHub → Settings → Developer settings → Personal access tokens
    - Erstelle einen Token mit `repo` und `workflow` Rechten
    - Kopiere den Token

2. **In Cursor konfigurieren**:

    - Öffne Background Agents Einstellungen
    - Füge den GitHub Token als Secret `PAGEKIT_BACKGROUND_AGENT` hinzu (genau wie du es schon hast!)
    - Optional: Füge `COMPOSER_GITHUB_TOKEN` hinzu (gleicher Token)

3. **Agent starten**:
    - Der Agent wird automatisch:
        - Das Repository klonen
        - Dependencies installieren
        - Auf dem `develop` Branch arbeiten

## Verfügbare Befehle

Im Background Agent Terminal (Workspace: `/workspace`) kannst du folgende Befehle nutzen:

```bash
# Modernisierungs-Tasks (von /workspace aus)
.cursor/modernize-helper.sh phpunit   # PHPUnit 11 Upgrade
.cursor/modernize-helper.sh security  # Security Patches
.cursor/modernize-helper.sh symfony   # Symfony 6.4 Upgrade

# Allgemeine Befehle
composer test                                      # Tests ausführen
yarn compile-js                                    # Frontend builden
yarn watch-all                                     # Frontend watchen

# Git Workflow
git checkout -b feature/my-feature                 # Neuer Branch
git add .                                          # Änderungen stagen
git commit -m "feat: my feature"                   # Commit
gh pr create --base develop                        # PR erstellen
```

## Push-Regel verwenden

Die `push` Regel automatisiert den kompletten Git-Workflow:

1. Erstellt intelligente Commits
2. Updated das Changelog
3. Pushed zu `dev-integration`
4. Erstellt/Updated den PR

Einfach "push" im Chat eingeben!

## Workflow für Modernisierungs-Tasks

1. **Schritt 1.2 - PHPUnit Update** (AKTUELL):

    ```bash
    .cursor/modernize-helper.sh phpunit
    ```

2. **Schritt 1.3 - Security Patches** (NÄCHSTER):

    ```bash
    .cursor/modernize-helper.sh security
    ```

3. **Schritt 1.4 - Symfony Update**:
    ```bash
    .cursor/modernize-helper.sh symfony
    ```

## Troubleshooting

-   **Permission Denied**: Scripts mit `chmod +x` ausführbar machen
-   **GitHub Auth Failed**: Prüfe ob `GH_TOKEN` korrekt gesetzt ist
-   **Tests schlagen fehl**: Logs in `/workspace/` bzw. `tmp/logs/` prüfen

## Wichtige Dateien

-   `.cursor/Dockerfile` - Container-Definition (PHP 8.5, Node, Playwright **chromium-only**, gh CLI)
-   `.cursor/environment.json` - Agent-Konfiguration (im Repo versioniert, hat Vorrang vor Personal/Team-Configs im Cursor Dashboard)
-   `.cursor/install.sh` - Update-Script (composer install + yarn install + Tool-Verification)
-   `.cursor/start.sh` - Start-Script (Token-Mapping + Pagekit-Server auf Port 8080)
-   `.cursor/secrets.example.env` - Secret-Beispiel (echte Secrets ins Cursor Dashboard, nicht ins Repo!)
-   `MODERNISATION_STRATEGY.md` - Vision & Strategie der Modernisierung

## ⚠️ Bekannte Cloud-Agent-Stolperfallen

### Secret-Namen ohne Leerzeichen

Cursor injiziert Secret-Namen als space-separierte Liste in `CLOUD_AGENT_INJECTED_SECRET_NAMES`. **Secret-Namen mit Leerzeichen** (z. B. `PAGEKIT BACKGROUND AGENT`) brechen den plattforminternen Pre-Commit-Secret-Scanner — `git commit` schlägt dann fehl und braucht `--no-verify`.

**Lösung:** Alle Secret-Namen müssen valide Bash-Identifier sein: `[A-Z_][A-Z0-9_]*` (z. B. `PAGEKIT_BACKGROUND_AGENT`, `GH_TOKEN`, `COMPOSER_GITHUB_TOKEN`). Im [Cursor Dashboard → Cloud Agents → Secrets](https://cursor.com/dashboard/cloud-agents) prüfen und ggf. umbenennen.

### Playwright nur mit Chromium

Die Cloud-Agent-VM hat **kein sudo** und kann daher `npx playwright install-deps` für firefox/webkit nicht ausführen. Im Dockerfile ist nur chromium installiert. Tests mit firefox/webkit gehören in CI/CD-Pipelines auf vollwertigen Hosts; dort `PW_BROWSERS=all` setzen, um die zusätzlichen Projekte zu aktivieren.
