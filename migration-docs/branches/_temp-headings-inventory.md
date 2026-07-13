# Temporär — Überschriften-Inventar Phase 2

> **Status:** Arbeitsdatei — nach Abschluss der Standardisierung löschen.
> **Ziel:** Alle bisherigen Überschriften erfassen, bewerten, zusammenführen.
> **Referenz-Skeleton:** [`branch-doc-skeleton.md`](./branch-doc-skeleton.md)

---

## Epochen (Qualitätsbogen)

| Epoche | Steps (ca.) | Charakter | Tendenz |
|---|---|---|---|
| Früh | 2.0.1–2.0.6 | Sehr detailliert, ohne Emojis, uneinheitliche H1 | Struktur schwach, Inhalt stark |
| Goldstandard | 2.0.7–2.1.4 | Vollständiges Template, 240–310 Zeilen | Vollständigkeit ↑ |
| V2-Kompakt | 2.1.5–2.1.10 | Checklist-Step-Tabellen, 94–141 Zeilen | Struktur in What Changed ↑, Vollständigkeit ↓ |

---

## Überschriften-Inventar

| Überschrift | Vorkommen (Phase 2) | Bewertung | Im Skeleton? | Empfehlung / Merge |
|---|---|---|---|---|
| `# Step X.Y.Z — Titel` | 2.0.7 – 2.1.10 | ✅ Sehr gut | Ja (H1) | **Pflicht** — einheitliches H1-Format |
| `# Titel (Step X.Y.Z)` | 2.0.3–2.0.6 | ⚠️ Alt | Nein | Abschaffen |
| Metadaten-Block (`Branch`, `ROADMAP Step`, `GitHub Issue`, `Pull Request`, `Status`, `Started`, `Completed`) | 2.0.7+ (teilweise nur `Date`) | ✅ Sehr gut | Ja | **Pflicht** — `Started`/`Completed` statt nur `Date` |
| `## 🎯 Overview` | 2.0.7 – 2.1.10 | ✅ Sehr gut | Ja | **Pflicht** |
| `## Overview` (ohne Emoji, früh) | 2.0.1–2.0.6 | ⚠️ Alt | — | → `## 🎯 Overview` vereinheitlichen |
| `## Scope` | 2.0.5, 2.0.6 | ⚠️ Redundant | Nein | **Zusammenführen** → in Overview integrieren |
| `## ✅ What Changed` | 2.0.7 – 2.1.10 | ✅ Sehr gut | Ja | **Pflicht** |
| `### <Thema> (Checklist Steps N–M)` | 2.1.7 – 2.1.10 | ✅ Sehr gut | Ja (H3-Muster) | **Pflicht** unter What Changed |
| `### ➕ Added` / `✏️ Edited` / `➖ Net diff` | 2.0.7 – 2.1.3 | ✅ Gut | Nein | **Optional** — nur bei kleinem Diff ohne Checklist-Mapping |
| Tabellen `\| File \| Change \|` | 2.1.7 – 2.1.10 | ✅ Sehr gut | Ja | **Pflicht** in What Changed |
| `## ⚠️ Breaking Changes (Extensions)` | 2.1.4–2.1.7, 2.0.x | ✅ Sehr gut | Ja | **Pflicht** — oder explizit „None" |
| `## Breaking Changes` (ohne Extensions) | 2.0.5, 2.0.6 | ⚠️ Ähnlich | — | **Zusammenführen** → Breaking Changes (Extensions) |
| `## 🛡️ No-Mercy Compliance` | 2.0.7 – 2.1.4, 2.1.3 | ✅ Gut | Ja | **Pflicht** — fehlt in 2.1.5–2.1.10 |
| `## 🧪 Quality Gates & Test Results` | Skeleton (neu) | ✅ Sehr gut | Ja | **Pflicht** — ersetzt separates Test Results + Coverage Gate |
| `## 🧪 Test Results` | Fast überall (alt) | ✅ Gut | — | **Zusammenführen** → Quality Gates & Test Results |
| `## 🧪 Test Results Summary` | 2.1.4 | ⚠️ Synonym | — | **Zusammenführen** → Quality Gates & Test Results |
| `### Local gates (per checklist step)` | Skeleton | ✅ Sehr gut | Ja (H3) | **Pflicht** — eine Zeile pro Checklist Step (Execute) |
| `### CI gates (finalize)` | Skeleton | ✅ Sehr gut | Ja (H3) | **Pflicht** — PHPUnit, PHPStan, baseline delta, Bugbot, E2E |
| `### Coverage gate (pinned baseline)` | 2.1.9, Skeleton | ✅ Sehr gut | Ja (H3) | Unter Quality Gates; „unchanged" wenn Step nicht pinnt |
| `### Per-step gates` / `### Final CI run` / `### Bugbot` / `### E2E` | 2.1.2 – 2.1.4 | ✅ Gut | — | **Zusammenführen** in die zwei Tabellen Local + CI |
| Test Results als Tabelle | 2.1.7 – 2.1.10 | ✅ Gut (kompakt) | — | In Local/CI-Spalten überführen |
| `## 📋 Phase 1 Audit Closure` | 2.1.7 (inline, kein eigenes ##) | ✅ Gut | Ja | **Pflicht** wenn zutreffend |
| `### Phase 1 audit closures` (unter Out-of-Scope) | 2.0.8, 2.0 Foundation | ⚠️ Duplikat | — | **Zusammenführen** → eigene H2 Phase 1 Audit Closure |
| `## 📚 Deferred / Out-of-Scope` | 2.0.7 – 2.1.3 | ✅ Sehr gut | Ja | **Pflicht** — Zielname für alle Varianten |
| `## 📋 Deferred` | 2.1.9, 2.1.10 | ⚠️ Kurz | — | **Zusammenführen** |
| `## 📦 Deferred Work` / `## 📋 Deferred to Step X.Y.Z` | 2.1.4, 2.1.6, 2.1.8 | ⚠️ Synonym | — | **Zusammenführen** |
| `## Out of Scope (deferred)` | 2.0.5, 2.0.6 (früh) | ⚠️ Alt | — | **Zusammenführen** |
| `## 🧱 Commits (Conventional Commits)` | 2.0.7 – 2.1.4, 2.1.3 | ✅ Gut | Ja (empfohlen) | Fehlt in 2.1.5–2.1.10 |
| `## 📎 Related Documents` | 2.0.7 – 2.1.3 | ✅ Gut | Ja | **Pflicht** |
| `## 🔗 References` / `## Referenced ROADMAP Steps` | 2.1.5, 2.0.4 | ⚠️ Duplikat | — | **Zusammenführen** → Related Documents |
| `## 📊 CI Line-Coverage Gate — …` | 2.1.9 | ✅ Wichtig | Ja (H3 unter Quality Gates) | **Nicht** mehr eigener Anhang — nur noch `### Coverage gate` |
| `## ⚠️ Environment Precondition` | 2.1.8 | ✅ Step-spezifisch | Optionaler Anhang | Behalten wenn Setup nötig |
| `## 🧨 Behavioural Contract` | 2.0.8 | ✅ Step-spezifisch | Optionaler Anhang | Behalten bei Verhaltensänderungen |
| `## 🔒 Manual Actions Required` / `## 🔒 Branch Protection` | 2.1.2 | ✅ Step-spezifisch | Optionaler Anhang | **Zusammenführen** → Manual Actions Required |
| `## 📊 Closure Verdict` | Nur 2.0 Foundation | ✅ Spezialfall | Nein | Nur für Audit/Closure-Steps |
| `## 🆕 New Sub-Steps` | Nur 2.0 Foundation | ✅ Spezialfall | Nein | Nur für Audit/Closure-Steps |
| `## 🚦 Routed Gaps` | Nur 2.0 Foundation | ✅ Spezialfall | Nein | Nur für Audit/Closure-Steps |
| `## Objective` | 2.0.1-Block | ⚠️ Guide-artig | Nein | Auslagern (Extension Migration Guide) |
| `## Migration Summary` | 2.0.1, 2.0.3, 2.0.4 | ⚠️ Guide-artig | Nein | **Zusammenführen** → in Overview oder Guide |
| `## Files Changed` (rohe Liste) | 2.0.3, 2.0.4 | ❌ Schwach | Nein | Ersetzen durch Tabellen in What Changed |
| `## Changes` (nummeriert 1–10) | 2.0.5, 2.0.6 | ⚠️ Alt | Nein | Ersetzen durch Checklist-Step-H3 |
| `## Validation Results` | 2.0.1 | ⚠️ Redundant | Nein | **Zusammenführen** → Test Results |
| `## Commit History` | 2.0.1e | ⚠️ Redundant | Nein | **Zusammenführen** → Commits |

---

## Merge-Kandidaten (Zusammenfassung)

| Ziel-Sektion (Skeleton) | Absorbiert diese Varianten |
|---|---|
| `## 🎯 Overview` | Scope, Teile von Migration Summary |
| `## ✅ What Changed` | Changes (nummeriert), Files Changed, ➕/✏️/➖ (optional) |
| `## ⚠️ Breaking Changes (Extensions)` | Breaking Changes, Breaking Changes for Extensions |
| `## 🧪 Quality Gates & Test Results` | Test Results, Test Results Summary, CI Line-Coverage Gate, Validation Results |
| `## 📋 Phase 1 Audit Closure` | Inline Audit-Notes, `### Phase 1 audit closures` unter Out-of-Scope |
| `## 📚 Deferred / Out-of-Scope` | Deferred, Deferred Work, Out-of-Scope (deferred), Deferred to Step … |
| `## 🧱 Commits (Conventional Commits)` | Commit History |
| `## 📎 Related Documents` | References, Referenced ROADMAP Steps |
| `## 📊 <Step-spezifischer Anhang>` | Environment Precondition, Behavioural Contract, Manual Actions, Branch Protection (nicht Coverage/Tests) |

---

## Lücken in 2.1.5–2.1.10 (gegen Skeleton)

| Fehlend / inkonsistent | Betroffene Steps |
|---|---|
| `## 🛡️ No-Mercy Compliance` | 2.1.5 – 2.1.10 |
| `## 🧱 Commits (Conventional Commits)` | 2.1.5 – 2.1.10 |
| `## 📎 Related Documents` | 2.1.6 – 2.1.10 (2.1.5 nur References) |
| `Started` / `Completed` | Alle bisherigen — nur `Date` |
| Einheitlicher Deferred-Name | 2.1.6 – 2.1.10 |
| Detaillierte Test Results (CI-Links, Bugbot) | Besonders 2.1.10 |

---

## Referenz-Docs (Best of)

| Zweck | Beste Referenz |
|---|---|
| Vollständigkeit (alle Pflicht-Sektionen) | `phase-2/step-2-1-2-cicd-quality-gates.md`, `step-2-1-3-strict-types-migration.md` |
| What Changed (Checklist + Tabellen) | `phase-2/step-2-1-9-test-coverage-expansion.md`, `step-2-1-10-entity-presentation-layer.md` |
| Step-spezifischer Anhang | `phase-2/step-2-1-9-test-coverage-expansion.md` (Coverage Gate) |
| Spezialfall Audit/Closure | `phase-2/step-2-0-foundation-closure.md` |

---

## Offene Arbeitspunkte

- [ ] Skeleton in `doc-writer.md` als Pfad referenzieren
- [ ] Alte Phase-2-Docs retroaktiv angleichen? (optional, niedrige Prio)
- [ ] Spezialfall-Sektionen für Closure/Audit-Steps definieren?
- [x] Emojis in H2 — **entschieden: ja** (siehe `pagekit-context.mdc` § Emojis)
- [x] Test Results + Coverage Gate gruppieren — **entschieden: ja** → `## 🧪 Quality Gates & Test Results` mit Local + CI Tabellen + Coverage H3
