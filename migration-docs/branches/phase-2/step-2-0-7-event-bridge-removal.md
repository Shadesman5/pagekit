# Step 2.0.7 — Symfony Event Dispatcher Bridge Removal

**Branch:** `cursor/step-2-0-7-event-bridge-removal-6844`
**ROADMAP Step:** 2.0.7 (Foundation Consolidation — Event Bridge Removal)
**GitHub Issue:** [#184](https://github.com/Shadesman5/pagekit/issues/184)
**Status:** ✅ Complete — Ready for Review
**Date:** 2026-04-27

---

## 🎯 Overview

Step 2.0.7 finalizes the **Foundation Consolidation** phase by removing the now-unused
`SymfonyEventDispatcherBridge` and its dedicated compatibility test. The bridge was
introduced as a thin "Symfony 6.4 EventDispatcherInterface" adapter on top of Pagekit's
own event system. After the audits in Step 2.0.5 / 2.0.6 (and an exhaustive ripgrep
sweep in Step 1 of this ticket) it is **proven to have zero production consumers**.

Per the No-Mercy / Aggressive Modernization rules (`.cursor/ROADMAP.md`), an unused
compatibility layer must be **deleted, not maintained** (Rule 1, Rule 4 — DELETE OVER
WRAP). This step performs that deletion in one atomic ticket and cleans up the now-stale
`phpstan-baseline.neon` entries.

Pagekit's own `EventDispatcher`, `PrefixEventDispatcher`, `Event`, and `EventInterface`
classes are **kept by design** — they are the stable platform extension API
(~147 call sites across modules), not a legacy compat layer (Aggressive Rule 3).

---

## ✅ What Changed

### 🗑️ Deleted

- `app/modules/application/src/Event/SymfonyEventDispatcherBridge.php` — unused adapter
  implementing `Symfony\Component\EventDispatcher\EventDispatcherInterface` on top of
  Pagekit's `events` service.
- `app/modules/application/src/Tests/EventDispatcherCompatibilityTest.php` — the only
  consumer of the bridge; covered exclusively the deleted class.

### ✏️ Edited

- `app/modules/application/index.php` — removed the
  `$app->set('symfony.event_dispatcher', …)` service registration (lines 21–23).
  No `use` import had to be removed (the registration used the inline FQCN).
- `phpstan-baseline.neon` — removed two now-stale ignore blocks pointing at the two
  deleted files (`function.alreadyNarrowedType` and `method.impossibleType`).

### ➖ Net diff

- **0** lines added
- **340** lines removed
- **4** paths touched (2 deleted, 2 edited)
- **0** shims, **0** adapters, **0** `@deprecated` markers, **0** new TODOs

---

## 🧱 Commits (Conventional Commits)

| SHA       | Subject                                                                  | Checklist |
|-----------|--------------------------------------------------------------------------|-----------|
| `88be8821` | `refactor(events): remove SymfonyEventDispatcherBridge service registration` | #2 |
| `cfd21a77` | `refactor(events): delete SymfonyEventDispatcherBridge and its compat test`  | #3 |
| `89fda467` | `chore(phpstan): drop baseline entries for removed event-bridge files`       | #4 |

Each commit references `Refs #184 — Step 2.0.7` in its body for ROADMAP traceability.

---

## 🛡️ No-Mercy Compliance

| Rule | Outcome |
|------|---------|
| **Rule 1 — No Compatibility Layers** | ✅ Bridge deleted, no replacement adapter introduced |
| **Rule 2 — No Adapters** | ✅ No new wrapper class, trait, interface, or static helper |
| **Rule 3 — Breaking Changes Allowed Internally** | ✅ Zero production consumers; system remains functional after every commit |
| **Rule 4 — Delete Over Wrap** | ✅ `git rm` used; no `@deprecated`, no `*.legacy`, no commented-out code |
| **Rule 5 — Mandatory Flagging** | ✅ No new in-code TODOs (this is a pure deletion; nothing to flag) |
| **PHP 8.2+ hygiene** | ✅ No regressions to typed properties / return types in remaining code |
| **No WP/Laravel artifacts** | ✅ |
| **Diff scope** | ✅ Exactly the four paths from the plan |

---

## 🧪 Test Results

### Static analysis
- ✅ `php -l app/modules/application/index.php` — no syntax errors
- ✅ `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M` — `[OK] No errors`

### PHPUnit
- ✅ `./app/vendor/bin/phpunit` — **289 tests, 0 failures, 0 errors**
  - `EventDispatcherCompatibilityTest` correctly no longer discovered
  - Exit code 1 is solely due to **pre-existing** deprecation warnings (unrelated to this step)

### Playwright (chromium only, per `AGENTS.md`)
- ✅ `tests/e2e/specs/01-setup/installation.spec.js` — 1 passed (11.9 s)
- ✅ `tests/e2e/specs/02-core/authentication.spec.js` — 14 passed (59.4 s)
- ✅ `tests/e2e/specs/02-core/dashboard.spec.js` — 10 passed (35.1 s)

### Final ripgrep audit (live codebase only)
- ✅ `rg "SymfonyEventDispatcherBridge"` (PHP/JSON/YAML/JS/Vue/Twig/Razr) → **0** hits
- ✅ `rg "symfony.event_dispatcher"` → **0** hits
- ✅ `rg "Symfony\Component\EventDispatcher\EventDispatcherInterface"` → **0** hits
- ✅ `rg "EventDispatcherCompatibilityTest"` → **0** hits

---

## 📚 Out-of-Scope (Deferred — flagged with ROADMAP IDs)

| Concern | Tracked in |
|---------|------------|
| Rename `GetResponseEvent` in `app/modules/auth/` | Step 2.1.6 (PHPStan Level 7→8 — Strict Typing) |
| `ExceptionListenerWrapper` adapter pattern in kernel | Step 2.1.6 |
| Console `execute()` return types (`: int`) | Step 2.1.4 (PHPStan Level 5→6) |
| Pagekit's stable `EventDispatcher` / `PrefixEventDispatcher` / `Event` API | Phase 2.1 / Phase 5 — kept by design (Aggressive Rule 3) |
| Existing PHPStan baseline noise on the kept event classes | Steps 2.1.4 / 2.1.5 / 2.1.6 |

---

## 📎 Related Documents

- Plan / TODO-Spec: `.cursor/tickets/step-2-0-7-event-bridge-removal_plan.md`
- Task Prompt: `migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/PROMPT_2_0_7_Event-Bridge-Removal.md`
- Original migration doc (historical): `migration-docs/branches/phase-1/step-1-7-symfony-event-migration.md`
- Phase plan: `.cursor/ROADMAP.md` → Phase 2.0 → Step 2.0.7
