# Step 2.2 — CI/CD Pipeline

<!-- Branch doc for Roadmap Step 2.2.
     Path: migration-docs/branches/phase-2/step-2-2-ci-cd-pipeline.md -->

**Branch:** `feature/cicd-pipeline`
**ROADMAP Step:** 2.2 (CI/CD Pipeline)
**GitHub Issue:** [#157](https://github.com/Shadesman5/pagekit/issues/157)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-07-24 11:20
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### <Theme> (Checklist Steps N–M)

| File | Change |
|---|---|
| `path/to/file.php` | _TBD_ |
 
_TBD_

---

## 🧠 Key Decisions (Rationale)

_TBD / None_

---

## ⚠️ Breaking Changes (Extensions)

_TBD_

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

- CI run: _TBD_
- Notable deviations: _TBD / None_

---

## 📋 Phase 1 Audit Closure

_TBD_

---

## 📚 Deferred / Out-of-Scope

**Plan note — PHASE Deferred sync amendments (Architect):** present for §2.9 and §3.6.1; no ROADMAP sub-step added. Land with this ticket commit.

- **Step 2.9 (Phase 2 Closeout)** — Make the PHPUnit suite DB-portable: most DB tests hardcode in-memory-SQLite connections instead of honoring the `$GLOBALS['db_*']` parameters that `DbUtil::getConnection()` already supports — route them through the shared helper so the whole suite genuinely runs against MySQL, then flip the non-blocking `phpunit-mysql` CI leg to a required gate (drop its `continue-on-error`). Why: a MySQL gate that exercises only a handful of tests gives false cross-DB confidence. *(PHASE §2.9 amended)*
- **Step 3.6.1 (E2E Test Suite Rework)** — Lift the CI quarantine as each spec is reworked: remove its `test.describe.fixme` marker and tag it `@ci` so the tag-driven pipelines (PR smoke, merge, nightly) pick it up automatically — spec selection is tags + Playwright projects, never per-pipeline spec copies. Make the `@ci` specs viewport-robust (tablet/mobile Playwright projects), so the nightly viewport legs and the weekly cross-browser sweep can drop their non-blocking `continue-on-error` status. Why: desktop-only specs leave responsive admin/frontend regressions invisible. *(PHASE §3.6.1 amended)*

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_2_CI-CD-Pipeline_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_2_CI-CD-Pipeline.md`
- Predecessor: Step 2.1.14 — PHP Version Upgrade (8.2 → 8.5)
- Successor: Step 2.3 — Docker

---

## 📊 <Step-specific appendix>

_TBD — remove this section if not applicable._
