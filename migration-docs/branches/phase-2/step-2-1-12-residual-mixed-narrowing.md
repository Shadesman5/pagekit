# Step 2.1.12 — Residual `mixed` narrowing

**Branch:** `feature/residual-mixed-narrowing`
**ROADMAP Step:** 2.1.12 (Residual `mixed` narrowing)
**GitHub Issue:** [#217](https://github.com/Shadesman5/pagekit/issues/217)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-07-16 01:13
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

- **Controller DI is by parameter name, not type-hint.** `ControllerResolver::instantiateController()` resolves constructor args via `$container->has($paramName)`. The task prompt's claim that the container resolves module classes by type-hint is wrong — `MigrationController`'s `SystemModule $system` works only because `SystemModule::main()` runs `$app->set('system', $this)`. Checklist Step 2 therefore registers `$app->set('site', $this)` in `SiteModule::main()` (mirror of `system`; no `site` service exists today; no container-id collision with menu/event `'site'` strings).

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

None. Deferred/Bridges are empty. Task-prompt §3 remaining `mixed` (docblock array shapes, `PropertyTrait::__get/__set`, filter/loader/PSR-11 `get()` contracts, `PregReplaceFilter::filter()` return, `ExceptionListener::$controller` / `WrappedListener::$listener` callables) is legitimate permanent non-goal — not future work. No `PHASE_*` amendment required.

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_1_12_Residual-Mixed-Narrowing_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_12_Residual-Mixed-Narrowing.md`
- Predecessor: Step 2.1.11 — EntityManager DI (remove singleton)
- Successor: Step 2.1.13 — TinyMCE Security Patch (~5.10.9)

---

## 📊 <Step-specific appendix>

_TBD — remove this section if not applicable._
