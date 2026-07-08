# Step 2.1.5 — PHPStan Level 6 → 7 (Null Safety)

**Branch:** `cursor/phpstan-level-7-80bb`
**ROADMAP Step:** 2.1.5 (Static Analysis & Code Quality — PHPStan Level 6→7 — Null Safety)
**GitHub Issue:** [#152](https://github.com/Shadesman5/pagekit/issues/152)
**Pull Request:** [#210](https://github.com/Shadesman5/pagekit/pull/210)
**Status:** ✅ Complete — Ready for Review
**Date:** 2026-06-20

---

## 🎯 Overview

Step 2.1.5 raises the project's PHPStan baseline from `level: 6` to `level: 7`. Level 7
enforces **property types** and **null-safe code** — every property must be natively typed
(or explicitly `mixed` where PHP cannot express the type), and nullable values must be
handled at call sites or corrected at the type declaration.

The migration was executed in **14 sequential checklist steps** (12 commits; Steps 1 and 8
shipped empty diffs where the tree was already clean). A Bugbot quick-peek mini-loop and
a MetaHelper E2E regression fix produced one additional commit after Early Push.

No Phase 1 audit closures apply to this step (1.11 closes later via 2.0.8 + 2.1.6 +
2.1.10/2.1.11 per ROADMAP).

---

## ✅ What Changed

### `phpstan.neon` configuration

- `level: 6` → `level: 7` (Checklist Step 14).
- No other config changes.

### Property-type sweep (27 files)

| Scope | Files | Commit |
|---|---|---|
| `app/modules/` core (11 files) | kernel, filesystem, feed, debug, application | `refactor(types): add property types to app/modules core files` |
| `app/console/src/Commands/` (16 files) | all console commands incl. Migration/ | `refactor(types): add property types to console commands` |

### Null-safety sweep (grouped by module risk)

| Group | Modules | Commit |
|---|---|---|
| A | kernel, application, config, log, session, auth, migration | `refactor(phpstan): null safety app/modules Group A` |
| B | database (ORM, DBAL) | `refactor(phpstan): null safety app/modules/database Group B` |
| C | view, twig, markdown, filter, filesystem | `refactor(phpstan): null safety app/modules Group C` |
| D | routing, feed, debug | `refactor(phpstan): null safety app/modules Group D` |
| — | app/modules final sweep | *(no diff — already clean)* |
| E | app/system/modules mail + user | `refactor(phpstan): null safety app/system/modules mail and user` |
| F | app/system/modules site + intl | `refactor(phpstan): null safety app/system/modules site and intl` |
| G | app/system/modules tail (cache, captcha, dashboard, finder, widget, …) | `refactor(phpstan): null safety app/system/modules tail` |
| H | app/system/src + app/installer + app/console | `refactor(phpstan): null safety app/system src installer console` |
| I | packages/pagekit/blog | `refactor(phpstan): null safety packages pagekit blog` |

### Baseline & cleanup

- Surgical baseline removals only across Steps 4, 6, 10, 12, 14 — no wholesale regeneration.
- Deleted stale `app/modules/view/src/PhpEngine.php.backup` artifact.

### Bugbot / E2E mini-loop fixes

- **`TraceableEventDispatcher`** — `SplObjectStorage` tracks exact listener references for subscribe/unsubscribe identity.
- **`EventDispatcher`** — re-subscribe guard calls `unsubscribe()` before re-registering listeners.
- **`SimpleArrayType`** — removed `strval()` coercion; preserves native JSON scalar types.
- **`MetaHelper`** — accepts `null` config values from `site/index.php` meta keys on fresh installs (E2E 500 regression).

---

## 🧪 Test Results

| Gate | Result |
|---|---|
| PHPUnit (local) | ✅ 326 tests, 751 assertions |
| PHPStan L7 (local) | ✅ No errors |
| CI run 27891657950 | ✅ phpunit 8.2, phpunit 8.3, phpstan, cs-fixer, security-audit |
| Playwright E2E | ✅ installation (1), authentication (14), dashboard (10) |

---

## ⚠️ Breaking Changes for Extensions

None at the public extension API level. Internal type narrowings (e.g. `EventDispatcherInterface::trigger()` conditional return, `CacheModule::supports()` split) may affect extensions that relied on loose typing — update call sites to match the narrowed signatures.

---

## 🔗 References

- ROADMAP Step 2.1.5
- GitHub Issue #152
- Pull Request #210
- Predecessor: Step 2.1.4 (PR #203)
- Successor: Step 2.1.6 (PHPStan Level 7→8 — Strict Typing, Issue #153)
