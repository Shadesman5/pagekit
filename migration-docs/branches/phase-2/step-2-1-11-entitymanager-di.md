# Step 2.1.11 — EntityManager DI — remove singleton (Active-Record → Data-Mapper)

**Branch:** `feature/entitymanager-di`
**ROADMAP Step:** 2.1.11 (EntityManager DI — remove singleton)
**GitHub Issue:** [#205](https://github.com/Shadesman5/pagekit/issues/205)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-07-14 01:13
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

**Full closure of Phase 1 audit Step 1.11 (ORM Modernization) — planned at Finalize.**
This step removes the last `EntityManager` singleton / static Active-Record
finding behind row 1.11. At **Finalize**, ROADMAP row 1.11 Audit flips
⚠️ → 🛡️ as the full closure combining Steps 2.0.8 + 2.1.6 + 2.1.10 with this
step (2.1.11). Step 2.1.10 already removed `ModelServiceLocator` as a partial
closure; row 1.11 stayed ⚠️ pending this step.

---

## 📚 Deferred / Out-of-Scope

_TBD_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_1_11_EntityManager-DI_plan.md` (→ move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_11_EntityManager-DI.md`
- Predecessor: Step 2.1.10 — Entity Presentation Layer (`step-2-1-10-entity-presentation-layer.md`)
- Successor: Step 2.1.12 ([#217](https://github.com/Shadesman5/pagekit/issues/217)) — _TBD_

---

## 📊 <Step-specific appendix>

_TBD — remove this section if not applicable._
