# Step X.Y.Z — <Kurztitel>

<!-- Branch-doc schema for doc-writer ONLY (not CHANGELOG/README — see doc-writer.md Finalize).
     Kopieren nach: migration-docs/branches/phase-<X>/step-<X>-<Y>-<Z>-<kebab-title>.md
     Plan: copy + header metadata. Execute: fill sections per <!-- … --> comments.
     Finalize: close remaining _TBD_. Nie von Grund auf neu schreiben. -->

**Branch:** `feature/<slug>`
**ROADMAP Step:** X.Y.Z (<Roadmap-Titel aus .cursor/ROADMAP.md>)
**GitHub Issue:** [#NNN](https://github.com/Shadesman5/pagekit/issues/NNN)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** YYYY-MM-DD HH:MM
**Completed:** _TBD_

---

## 🎯 Overview

<!-- 1–3 Absätze. Pflichtinhalt:
     - Was wurde in diesem Roadmap Step erreicht?
     - Scope-Grenze: was bewusst nicht Teil dieses PRs ist
     - Phase-1-Audit-Closure: ja / nein / teilweise (mit Verweis auf ROADMAP-Zelle)
     Finalize: glätten, aber keine Ticket-Wiederholung. -->

_TBD — Plan-Phase legt nur Skeleton an; Finalize füllt die Zusammenfassung._

---

## ✅ What Changed

<!-- Pflicht. Wachstum während Execute: ein ###-Block pro Checklist Step (oder thematische Gruppe).
     Format: Tabelle | File | Change | — faktenbasiert, keine Plan-Prosa.
     Delta-Prosa nur bei Abweichung vom Ticket, Deferral, temporärer Brücke oder ESCALATE. -->

### <Thema> (Checklist Steps N–M)

| File | Change |
|---|---|
| `path/to/file.php` | _TBD_ |

<!-- Weitere ###-Blöcke pro Checklist Step hier einfügen (Execute). -->

---

## ⚠️ Breaking Changes (Extensions)

<!-- Pflicht-Sektion. Wenn nichts: explizit "None" schreiben. -->

_TBD_

---

## 🛡️ No-Mercy Compliance

<!-- Pflicht. Kurze Checkliste — Beispielpunkte (anpassen/löschen je nach Step):
     - [ ] Keine Kompatibilitätsschichten / Adapter hinzugefügt
     - [ ] Alle Call-Sites direkt aktualisiert
     - [ ] phpstan-baseline nur chirurgisch (keine Regeneration)
     - [ ] Temporäre Brücken mit ROADMAP-Tag markiert
     - [ ] Tests grün nach jedem Checklist Step -->

_TBD_

---

## 🧪 Quality Gates & Test Results

<!-- Pflicht. Execute: nach jedem Checklist Step eine Zeile in "Local gates" ergänzen.
     Finalize: CI-Tabelle, Coverage-Pinning, Bugbot, E2E füllen.
     N/A ist erlaubt für Infection / Line coverage, wenn der Step sie nicht nutzt.
     Metrik-Namen sind vorgegeben — nur Werte eintragen, Zeilen nicht löschen.

     PHPStan baseline (lokal ausführbar):
       grep -c "message:" phpstan-baseline.neon
       grep -oP 'count:\s*\K[0-9]+' phpstan-baseline.neon | awk '{s+=$1} END{print s+0}'
     Infection (wenn Step es nutzt):
       ./app/vendor/bin/infection --threads=4  → MSI / Covered MSI aus Summary
     Line coverage lokal (optional):
       ./app/vendor/bin/phpunit --coverage-text → Zeile "Lines:" -->

### Local gates (per checklist step)

<!-- Eine Zeile pro Checklist Step. PHPUnit-Format: "tests / assertions / failures".
     PHPStan-Format: "L&lt;n&gt; / &lt;errors&gt; errors". Infection: "MSI% / Covered MSI%" oder N/A. -->

| Step | PHPUnit | PHPStan level | PHPStan errors | Baseline blocks | Suppressed errors | Infection MSI | Infection covered MSI | Line coverage % | Notes |
|---|---|---|---|---|---|---|---|---|---|
| _TBD_ | _TBD_ | _TBD_ | _TBD_ | _TBD_ | _TBD_ | _N/A_ | _N/A_ | _N/A_ | _optional_ |

### CI gates (finalize)

<!-- Link: https://github.com/Shadesman5/pagekit/actions/runs/<run-id> -->

| Check | Result | Details |
|---|---|---|
| CI run | _TBD_ | _run-id + link_ |
| PHPUnit (8.2) | _TBD_ | _tests / failures_ |
| PHPUnit (8.3) | _TBD_ | _tests / failures_ |
| PHPStan | _TBD_ | _level / errors_ |
| PHPStan baseline | _TBD_ | _blocks / suppressed (delta vs. Step 1)_ |
| CS-Fixer | _TBD_ | _pass / fail_ |
| Security audit | _TBD_ | _pass / fail_ |
| Line coverage floor | _TBD_ | _pinned % / actual %_ |
| Codecov upload | _TBD_ | _pass / skip / non-blocking_ |
| Bugbot | _TBD_ | _clean / N findings_ |
| E2E | _TBD_ | _3 specs / N tests passed_ |

### Coverage gate (pinned baseline)

<!-- Ausfüllen wenn dieser Step die CI-Coverage-Schwelle setzt oder anhebt (z. B. Step 2.1.9).
     Sonst: "Unchanged — see pinned floor in php-quality.yml (<X.X %>)." -->

| Field | Value |
|---|---|
| Pinned floor % | _TBD_ |
| Actual % at finalize | _TBD_ |
| Ratchet rule | _only up / unchanged this PR_ |
| Workflow reference | `.github/workflows/php-quality.yml` |

---

## 📋 Phase 1 Audit Closure

<!-- Pflicht-Sektion. Wenn nichts: "None in this PR." -->

_TBD_

---

## 📚 Deferred / Out-of-Scope

<!-- Pflicht. Jeder Punkt mit ROADMAP Step ID, z. B. "Step 2.1.11 — …". -->

_TBD_

---

## 🧱 Commits (Conventional Commits)

<!-- Empfohlen. Finalize oder manuell aus git log. Gruppiert nach Checklist Step. -->

_TBD_

---

## 📎 Related Documents

<!-- Pflicht. -->

- Ticket: `migration-docs/tickets/active/<task-slug>_plan.md` (_TBD_ → `done/` nach Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/.../PROMPT_X_Y_Z_....md`
- Predecessor: Step X.Y.(Z−1) — _TBD_
- Successor: Step X.Y.(Z+1) — _TBD_

---

<!-- Optionale Anhänge — nur für Step-spezifisches AUSSERHALB der Quality Gates (z. B. PCOV setup,
     behavioural contract, manual GitHub settings). Coverage Gate und Test-Metriken gehören OBEN. -->

## 📊 <Step-spezifischer Anhang>

_TBD — Abschnitt entfernen, wenn nicht zutreffend._
