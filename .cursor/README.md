# Pagekit Background Agents für Cursor

Diese Konfiguration ermöglicht es, Background Agents in Cursor zu verwenden, um die Pagekit-Modernisierung automatisiert durchzuführen.

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

Im Background Agent Terminal kannst du folgende Befehle nutzen:

```bash
# Modernisierungs-Tasks
/home/ubuntu/.cursor/modernize-helper.sh phpunit   # PHPUnit 11 Upgrade
/home/ubuntu/.cursor/modernize-helper.sh security  # Security Patches
/home/ubuntu/.cursor/modernize-helper.sh symfony   # Symfony 6.4 Upgrade

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
    /home/ubuntu/.cursor/modernize-helper.sh phpunit
    ```

2. **Schritt 1.3 - Security Patches** (NÄCHSTER):

    ```bash
    /home/ubuntu/.cursor/modernize-helper.sh security
    ```

3. **Schritt 1.4 - Symfony Update**:
    ```bash
    /home/ubuntu/.cursor/modernize-helper.sh symfony
    ```

## Troubleshooting

-   **Permission Denied**: Scripts mit `chmod +x` ausführbar machen
-   **GitHub Auth Failed**: Prüfe ob `GH_TOKEN` korrekt gesetzt ist
-   **Tests schlagen fehl**: Logs in `/home/ubuntu/pagekit/` prüfen

## Wichtige Dateien

-   `.cursor/Dockerfile` - Container-Definition
-   `.cursor/environment.json` - Agent-Konfiguration
-   `.cursor/*.sh` - Helper Scripts
-   `MODERNISING_PAGEKIT_TODO_LIST.md` - Hauptaufgaben
