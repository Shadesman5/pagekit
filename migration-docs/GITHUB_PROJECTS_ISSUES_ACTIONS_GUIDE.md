# GitHub Project Setup – Pagekit CMS Modernization

> **Projektname:** `Pagekit CMS Modernization`  
> **Aktueller Stand:** Project erstellt, 117 PRs importiert (94 Done, 23 offen/Dependabot), ein Board-View mit Standard-Spalten (Todo, In Progress, Done), 6 Auto-Workflows aktiv.

---

## 1. Begriffe – kurz und klar

| Begriff               | Was ist das?                                                                                      | Beispiel                                                             |
| --------------------- | ------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| **Repository**        | Ein Git-Repo mit Code.                                                                            | `Shadesman5/pagekit`                                                 |
| **Issue**             | Eine Aufgabe/Bug/Idee, gehört zu **einem** Repo.                                                  | „PSR-11 Container Modernisierung" im pagekit-Repo.                   |
| **Pull Request (PR)** | Code-Änderung, gehört zu **einem** Repo. Kann Issues schließen (`Closes #42`).                    | PR #111 „PHP 8 Attributes Migration".                                |
| **Project**           | Ein **Board/Tabelle** das Items (Issues + PRs) aus **mehreren** Repos bündelt.                    | „Pagekit CMS Modernization" – zeigt Items aus pagekit, pk-docs, etc. |
| **View**              | Eine **Ansicht** im Project. Mehrere Views zeigen die **gleichen** Items, nur anders dargestellt. | Board-View, Table-View, Roadmap-View.                                |
| **Spalte** (Board)    | Die Kategorien in einer **Board-View**. Standard: Todo, In Progress, Done.                        | Das ist der **Status** eines Items.                                  |
| **Custom Field**      | Ein selbst definiertes Feld, das du jedem Item zuweisen kannst.                                   | Feld „Phase" mit Werten 1, 2, 3, 4, 5.                               |
| **Label**             | Ein Tag an einem Issue/PR im Repository.                                                          | `phase-2`, `backend`, `frontend`.                                    |
| **Milestone**         | Ein Versions-Ziel im Repository, dem Issues zugeordnet werden.                                    | `Pagekit 2.0.0`.                                                     |
| **Draft Issue**       | Existiert **nur** im Project (kein Repo, keine Nummer). Gut für Ideen.                            | Phase-5-Wünsche.                                                     |
| **Gist**              | Eine einzelne versionierte Datei auf gist.github.com (bis ~1 MB).                                 | Backup eines langen Dokuments.                                       |
| **GitHub Action**     | Ein automatisierter Workflow (YAML), der bei Events läuft (Push, PR, Timer).                      | Tests bei PR, Docs-Sync nach pk-docs.                                |

---

## 2. Wie Spalten, Views und Phasen zusammenhängen

Das ist der Kern der Verwirrung, darum hier extra erklärt:

### Spalten = Status (NICHT Phase)

Dein Board hat aktuell drei Spalten: **Todo | In Progress | Done**.  
Das bedeutet: „In welchem **Zustand** ist dieses Item?"

Spalten sagen **nichts** darüber, zu welcher **Phase** ein Item gehört.

### Phase = Custom Field ODER Label

Um zu sehen „welche Aufgaben gehören zu Phase 2", brauchst du **eines** von beiden:

**Option A – Custom Field „Phase" (empfohlen):**

1. Im Project: „+ New field" → Name „Phase", Typ „Single Select", Werte: `1`, `2`, `3`, `4`, `5`.
2. Jedem Item (PR/Issue) den Phase-Wert zuweisen (z. B. alle 117 PRs = Phase 1).
3. Jetzt kannst du:
   - In einer **Table-View** nach Phase sortieren oder gruppieren.
   - In einer **Board-View** die Gruppierung auf „Phase" stellen → Spalten werden „Phase 1", „Phase 2", …
   - Einen **Filter** setzen: „Phase = 2" → nur Phase-2-Items.

**Option B – Labels im Repo:**

1. Im pagekit-Repo: Labels `phase-1`, `phase-2`, … anlegen.
2. Jedem PR/Issue ein Label zuweisen.
3. In einer View filtern: „Label = phase-2".

**Empfehlung:** Option A (Custom Field), weil Labels nur in einem Repo gelten und du 5 Repos hast. Custom Fields gelten im **gesamten Project** über alle Repos hinweg.

### Views = verschiedene Brillen auf dieselben Daten

Stell dir vor: Du hast 117 Items. Jedes Item hat einen **Status** (Todo/In Progress/Done) und eine **Phase** (1–5).

- **Board-View „Workflow":** Spalten = Status. Zeigt dir was gerade „Todo", „In Progress" und „Done" ist.
- **Table-View „Übersicht":** Zeilen = alle Items, Spalten = Status, Phase, Repo. Sortierbar und filterbar.
- **Table-View „Phase 2":** Wie oben, aber Filter: Phase = 2. Zeigt nur Phase-2-Items.

**Jede View zeigt die gleichen Items – nur anders dargestellt.**

---

## 3. Empfohlene Views

Starte mit **3 Views** (du kannst jederzeit welche hinzufügen oder ändern):

| #   | View-Name                     | Typ     | Was zeigt sie?                             | Wie einrichten?                                                                                     |
| --- | ----------------------------- | ------- | ------------------------------------------ | --------------------------------------------------------------------------------------------------- |
| 1   | **Board** (existiert bereits) | Board   | Status-Workflow: Todo → In Progress → Done | Behalten wie es ist.                                                                                |
| 2   | **Übersicht**                 | Table   | Alle Items mit Phase, Status, Repo-Name    | Neues Table-View; Spalten: Title, Status, Phase (Custom Field), Repository. Nach Phase sortieren.   |
| 3   | **Roadmap**                   | Roadmap | Zeitliche Darstellung mit Milestones       | Neues Roadmap-View; gruppiert nach Milestone. Braucht Start-/End-Datum an Items (optional, später). |

**Später optional:**

- **Board „By Phase":** Board-View mit Gruppierung nach Phase statt Status → Spalten werden „Phase 1", „Phase 2", …
- **Table „Nur Phase 2":** Table-View mit Filter „Phase = 2".

---

## 4. Was du als Nächstes tun solltest – Schritt für Schritt

Du hast bereits: Project erstellt, 117 PRs importiert, Board-View mit Standard-Spalten, Auto-Workflows aktiv.

### Schritt 1: Custom Field „Phase" anlegen

1. Im Project oben rechts: **„+"** (oder Feld-Menu in Table-View) → **New field**.
2. Name: `Phase`, Typ: **Single Select**.
3. Werte anlegen: `Phase 1`, `Phase 2`, `Phase 3`, `Phase 4`, `Phase 5`.
4. Speichern.

### Schritt 2: Allen 117 PRs die Phase zuweisen

- Alle 117 PRs gehören zu **Phase 1** → in der Table-View (oder Board) bei jedem Item „Phase = 1" setzen.
- **Tipp:** In der Table-View geht das schneller – du kannst pro Zeile klicken und den Wert setzen.

### Schritt 3: Table-View „Übersicht" anlegen

1. Im Project: **„+ New view"** → Typ **Table**.
2. Name: „Übersicht".
3. Spalten hinzufügen: Phase (das Custom Field), Status, Repository.
4. Nach **Phase** sortieren (aufsteigend).

### Schritt 4: Labels in pagekit anlegen

Im Repo `Shadesman5/pagekit` → **Issues → Labels → New label**:

| Label      | Farbe (Vorschlag) | Zweck                |
| ---------- | ----------------- | -------------------- |
| `phase-1`  | grün              | Abgeschlossene Phase |
| `phase-2`  | blau              | Aktueller Fokus      |
| `phase-3`  | lila              | Frontend             |
| `phase-4`  | orange            | Production           |
| `phase-5`  | grau              | Ideen/Wünsche        |

### Schritt 5: Milestones in pagekit anlegen

Im Repo → **Issues → Milestones → New milestone**:

| Milestone       | Beschreibung                      |
| --------------- | --------------------------------- |
| `Pagekit 1.1.x` | Phase 2 (Developer Experience)    |
| `Pagekit 1.2.0` | Phase 3 (Frontend-Modernisierung) |
| `Pagekit 2.0.0` | Phase 4 (Production Ready)        |

### Schritt 6: Erste Issues für offene Schritte anlegen

**Noch nicht alle auf einmal!** Starte mit den nächsten 5–8 offenen Schritten (Phase 2):

| Issue-Titel                                      | Label                 | Milestone     | Phase (Custom Field) |
| ------------------------------------------------ | --------------------- | ------------- | -------------------- |
| PSR-11 Container Vollmodernisierung (Step 2.0.5) | `phase-2`, `backend`  | Pagekit 1.1.x | 2                    |
| Static Analysis & Code Quality (Step 2.1)        | `phase-2`, `backend`  | Pagekit 1.1.x | 2                    |
| QueryBuilder API Standardization (Step 2.1.7)    | `phase-2`, `backend`  | Pagekit 1.1.x | 2                    |
| CI/CD Pipeline (Step 2.2)                        | `phase-2`, `backend`  | Pagekit 1.1.x | 2                    |
| Docker Production Setup (Step 2.3)               | `phase-2`, `backend`  | Pagekit 1.1.x | 2                    |
| Build Tools Modernization (Step 2.4)             | `phase-2`, `frontend` | Pagekit 1.1.x | 2                    |
| Extension Safety & Fault Isolation (Step 2.5)    | `phase-2`, `backend`  | Pagekit 1.1.x | 2                    |

**Issue-Beschreibung:** Kurze Zielbeschreibung + Verweis auf die Datei in migration-docs (z. B. „Details: siehe `migration-docs/TODO/PHASE#2_MODERNISING.md`, Schritt 2.0.5").

**Phase 5:** Als **Draft Issues** im Project anlegen (kein Repo nötig – sind nur Ideen).

### Schritt 7 (optional, später): Weitere Repos verknüpfen

- Project Settings → Repos hinzufügen (pk-docs, Extensions, etc.).
- Issues aus diesen Repos dem Project hinzufügen, wenn sie Modernisierungs-relevant sind.

---

## 5. Was bleibt wo?

| Inhalt                            | Wo?                                            | Warum?                                                             |
| --------------------------------- | ---------------------------------------------- | ------------------------------------------------------------------ |
| **ROADMAP.md**                    | `.cursor/ROADMAP.md`                           | Zentral für Agents und Cursor-Regeln.                              |
| **Cursor Rules**                  | `.cursor/rules/`                               | IDE-spezifisch.                                                    |
| **Agent-Prompts** (lange Dateien) | `migration-docs/TODO/agent_prompts/`           | Unbegrenzt lang; Issues haben 65K-Zeichen-Limit.                   |
| **Große Phase-Details, Audits**   | `migration-docs/` (schlank)                    | Für Remote-Agenten und Nachverfolgung.                             |
| **Aufgaben-Tracking**             | **GitHub Project + Issues**                    | Single Source of Truth für Status und nächste Schritte.            |
| **PR-Dokumentation**              | **GitHub PRs** (die 117 PRs)                   | Bereits dort; später mit migration-docs/pull-requests/ abgleichen. |
| **Phase-5-Ideen**                 | **Draft Issues** im Project                    | Nur Wünsche; werden ggf. später zu echten Issues.                  |
| **Archiv (nach Migration)**       | Evtl. eigenes Repo `pagekit-migration-history` | Idee für später; vorerst migration-docs behalten.                  |

---

## 6. GitHub Actions (später, optional)

**Nicht jetzt starten.** Erst Project + Issues sauber einrichten, dann Automatisierung.

### Wofür Actions?

- **Tests bei PR:** PHPUnit + Playwright automatisch laufen lassen.
- **Docs-Sync:** Bei PR-Merge in pagekit → pk-docs informieren oder aktualisieren.

### Cross-Repo (pagekit → pk-docs)

- Ein Workflow läuft in **einem** Repo.
- Um in **pk-docs** zu schreiben, braucht die Action ein **Token** (PAT als Secret).
- Einfachster Einstieg: Bei PR-Merge in pagekit nur ein **Issue in pk-docs** erstellen („Docs für PR #XY prüfen").

### Beispiel-Workflow (Konzept)

```yaml
# .github/workflows/docs-sync.yml (in pagekit)
name: Sync Docs to pk-docs
on:
  pull_request:
    types: [closed]
    branches: [develop]
jobs:
  sync:
    if: github.event.pull_request.merged == true
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Analyze PR
        run: |
          echo "PR: ${{ github.event.pull_request.title }}"
          # Skript das pk-docs informiert
      - name: Push to pk-docs
        env:
          TOKEN: ${{ secrets.DOCS_REPO_TOKEN }}
        run: |
          git clone https://x-access-token:$TOKEN@github.com/Shadesman5/pk-docs.git pk-docs
          cd pk-docs
          git config user.name "github-actions"
          git config user.email "actions@github.com"
          # Dateien anpassen
          git add -A && git commit -m "Docs: sync from pagekit PR #${{ github.event.pull_request.number }}" || true
          git push
```

**Voraussetzung:** PAT mit Schreibrechten für pk-docs, gespeichert als Secret `DOCS_REPO_TOKEN` in pagekit (Settings → Secrets and variables → Actions). **Nie** Tokens in Code oder Logs.

---

## 7. Häufige Fehler und Tipps

| Fehler                                      | Vermeidung                                                                |
| ------------------------------------------- | ------------------------------------------------------------------------- |
| Spalten mit Phasen verwechseln              | Spalten = Status (Todo/Done). Phase = Custom Field oder Label.            |
| Alles auf einmal machen wollen              | Erst Schritt 1–3 (Custom Field + Table-View), dann Labels, dann Issues.   |
| Agent-Prompts in Issues packen              | Zu lang → als Dateien in migration-docs lassen.                           |
| Alle Schritte (Phase 0–5) sofort als Issues | Starte mit Phase 2 (die nächsten 5–8 Schritte). Phase 5 als Draft Issues. |
| Token in Code oder Logs                     | Nur als Secret speichern.                                                 |
| migration-docs sofort löschen               | Erst alles abgleichen; schlank halten, nicht löschen.                     |

---

## 8. Zusammenfassung: Dein nächster Schritt

```
JETZT:
  [x] Project erstellt ✅
  [x] 117 PRs importiert ✅
  [x] Board-View (Todo/In Progress/Done) ✅
  [x] Auto-Workflows aktiv ✅

ALS NÄCHSTES (in dieser Reihenfolge):
  [ ] Custom Field "Phase" anlegen (Schritt 1)
  [ ] Allen 117 PRs Phase = 1 zuweisen (Schritt 2)
  [ ] Table-View "Übersicht" anlegen (Schritt 3)
  [ ] Labels in pagekit anlegen (Schritt 4)
  [ ] Milestones anlegen (Schritt 5)
  [ ] 5-8 Issues für Phase 2 anlegen (Schritt 6)

SPÄTER:
  [ ] Weitere Repos verknüpfen (Schritt 7)
  [ ] migration-docs/pull-requests mit GitHub PRs abgleichen
  [ ] GitHub Actions für Tests / Docs-Sync einrichten
  [ ] Phase-5-Schritte als Draft Issues anlegen
```

---

## 9. Referenzen

- **GitHub Projects:** [docs.github.com/en/issues/planning-and-tracking-with-projects](https://docs.github.com/en/issues/planning-and-tracking-with-projects)
- **Custom Fields:** [docs.github.com/en/issues/planning-and-tracking-with-projects/understanding-fields](https://docs.github.com/en/issues/planning-and-tracking-with-projects/understanding-fields)
- **Issues:** [docs.github.com/en/issues](https://docs.github.com/en/issues)
- **Actions:** [docs.github.com/en/actions](https://docs.github.com/en/actions)
- **Gists:** [docs.github.com/en/get-started/writing-on-github/editing-and-sharing-content-with-gists](https://docs.github.com/en/get-started/writing-on-github/editing-and-sharing-content-with-gists)

---

_Zuletzt aktualisiert: 2026-02-14_
