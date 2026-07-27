# Step 2.4.1 — Webroot Modernization (adopt `public/`)

<!-- Branch doc for Roadmap Step 2.4.1.
     Path: migration-docs/branches/phase-2/step-2-4-1-webroot-modernization.md -->

**Branch:** `feature/webroot-modernization`
**ROADMAP Step:** 2.4.1 (Webroot Modernization (public/))
**GitHub Issue:** [#243](https://github.com/Shadesman5/pagekit/issues/243)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-07-27 17:34
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### Baseline & inventory ground truth (Checklist Step 1)

| File | Change |
|---|---|
| `migration-docs/branches/phase-2/step-2-4-1-webroot-inventory-before.txt` | New — committed inventory (1,682 lines) of today's git-ignored, build-produced served files (Vite bundles, vendor asset copies under `app/assets/`, editor asset copies, LESS-compiled CSS) via `git ls-files --others --ignored --exclude-standard`; the pre-migration ground truth for Step 8's `public/`-prefixed parity check. |

Tests: none (test-writer: skip — no production PHP under `app/`/`packages/`). Gates: Verifier PASS; Tester — PHPUnit PASS, PHPStan PASS.

---

## 🧠 Key Decisions (Rationale)

_TBD / None_

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

---

## ⚠️ Risks & Rollout Notes

_TBD / None_

---

## 🔐 Security & Data Impact

_TBD / None_

---

## 🛡️ No-Mercy Compliance

_TBD_

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: _TBD / None_

---

## 📋 Phase 1 Audit Closure

_TBD / None_

---

## 👤 Maintainer action

_TBD / None_

---

## 📚 Deferred / Out-of-Scope

- **Steps 2.7 / 2.8 / 2.9** — `PHASE_2_MODERNISING.md` §2.7, §2.8, §2.9 amended in this plan with the deferred webroot consequences: DB-less extension-fallback file kept out of the now-public `storage/` tree (2.7), runtime-installed/uploaded package assets need a `public/` publisher on install/enable (2.8), release artifacts must recreate the `public/storage` symlink and prune stale published assets (2.9).

_TBD_

---

## 📌 Follow-on (ROADMAP)

_TBD / None_

---

## 🧊 Parked (unplanned)

_TBD / None_

---

## 🧹 Cleanup

_TBD / None_

---

## 🛡️ Audit

_TBD / None_

---

## 🎁 Bonus

_TBD / None_

---

## 🔍 Research

_TBD / None_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_4_1_Webroot-Modernization_plan.md` (moves to `done/` at Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_4_1_Webroot-Modernization.md`
- Predecessor: Step 2.4 — Build Tools (pnpm + Vite)
- Successor: Step 2.5 — Docker Production Image & Deploy

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
