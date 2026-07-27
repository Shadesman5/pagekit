# Migration Documentation Overview

Diese Sammlung enthält alle wichtigen Dokumentationen zur Modernisierung und Migration des Pagekit CMS.

## 📁 Struktur

### 📋 `/TODO/` — Planung & Phasen (aktiv)

-   **MODERNISATION_STRATEGY.md** — Vision, Philosophie und strategische Entscheidungen
-   **PHASE#1_MODERNISING.md** .. **PHASE#5_FUTURE_VISION.md** — Detaillierte Schritte pro Phase
-   **agent_prompts/** — Agent-Prompts für konkrete Migrationsschritte
-   **GITHUB_PROJECTS_ISSUES_ACTIONS_GUIDE.md** — GitHub Projects, Issues und Actions Leitfaden

### 🔒 `/security/`

-   **completed/SECURITY_PATCHES.md** — Finales Security Patch Dokument
-   **process/SECURITY_PATCHES_IMPLEMENTATION.md** — Detailliertes Implementierungslog

### 📦 `/dependencies/`

-   **completed/DEPENDABOT_UPDATES.md** — Automatisierte Dependabot-Updates
-   **process/dependency-analysis.md** — Analyse aller Projekt-Abhängigkeiten
-   **process/SYMFONY_64_CHANGES.md** — Strategie für Symfony 6.4 Migration

### 🧪 `/testing/`

-   **completed/TEST_IMPROVEMENTS.md** — Verbesserungen am Testsystem

### 📧 `/mail/`

-   **completed/MAIL_MIGRATION.md** — Mail-System Migration

### 📚 `/documentation/`

-   **CURSOR_RULES_OVERVIEW.md** — Übersicht der Cursor-Regeln
-   **DOCUMENTATION_AUTOMATION.md** — Automatisierung der Dokumentation

### 📦 `/branches/` — Historisches Archiv (Phase 1)

> Diese Dateien dokumentieren abgeschlossene Phase-1 Branches.
> Sie werden nicht mehr aktiv gepflegt — der aktuelle Workflow nutzt GitHub PRs direkt.

### 📦 `/pull-requests/` — Historisches Archiv (Phase 1)

> PR-Zusammenfassungen aus dem alten Workflow (vor Cloud-Agenten).
> Aktuelle PRs werden direkt auf GitHub erstellt — siehe `push.mdc`.

## 🔍 Tracking & Navigation

| Was | Wo |
|---|---|
| **Fortschritt & Status** | [ROADMAP.md](../.cursor/ROADMAP.md) (einzige SSOT) |
| **Vision & Strategie** | [MODERNISATION_STRATEGY.md](TODO/MODERNISATION_STRATEGY.md) |
| **Technische Details** | `TODO/PHASE#X_*.md` (pro Phase) |
| **Agent Workflow** | `.cursor/rules/` (push.mdc, feature-branch.mdc, orchestrator) |

## 📅 Timeline

-   **Start**: September 2025
-   **Phase 1**: Abgeschlossen (17/17 Schritte, Version 1.1.0)
-   **Phase 2**: Aktiv (Static Analysis & Code Quality)
-   **Ziel**: Pagekit 2.0.0 (Production-Ready)

---

_Zuletzt aktualisiert: März 2026_
