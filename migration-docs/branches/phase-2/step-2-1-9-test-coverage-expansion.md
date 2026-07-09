# Step 2.1.9 — Test Coverage Expansion

**Branch:** `feature/test-coverage-expansion`
**ROADMAP Step:** 2.1.9 (Test Coverage Expansion)
**GitHub Issue:** [#156](https://github.com/Shadesman5/pagekit/issues/156)
**Pull Request:** [#218](https://github.com/Shadesman5/pagekit/pull/218)
**Status:** ✅ Complete — Ready for Review
**Date:** 2026-07-09

---

## 🎯 Overview

Step 2.1.9 is the **concrete carried-over-debt + CI-infrastructure + edge-case
consolidation** of the ongoing test-coverage effort — not a one-shot raise of
every module to target percentages. It adds targeted production hardening, a
large batch of unit/integration tests, PHPUnit strictness gates, a ratcheting
CI line-coverage floor, and Codecov upload wiring.

**No Phase 1 audit cell closes in this PR.** The E2E suite rework (audit 1.10.5)
is re-homed to **Step 3.6.1**.

---

## ✅ What Changed

### Production hardening (Steps 1–5, 8)

| File | Change |
|---|---|
| `app/modules/filesystem/src/StreamWrapper.php` | Declare `$context` property (kills 4 PHP deprecations) |
| `app/modules/routing/src/Tests/RouterTest.php` | Remove `setAccessible()`; decouple from `blog.permalink` |
| `app/modules/database/src/Tests/ORM/QueryBuilderCacheTest.php` | Remove `setAccessible()` |
| `app/system/modules/mail/src/Tests/MessageTest.php` | Remove `setAccessible()` |
| `app/system/modules/mail/src/Message.php` | Remove `setAccessible()` |
| `app/modules/kernel/src/Event/ExceptionListener.php` | Remove `setAccessible()` |
| `app/modules/filter/src/AddRelNofollowFilter.php` | Harden XSS edge cases (slash/null-byte obfuscation, `rel="follow"` replacement) |
| `app/modules/auth/src/Handler/DatabaseHandler.php` | Injectable `ClockInterface` (last ctor param, default `Clock`) |
| `app/system/modules/user/src/Event/LoginAttemptListener.php` | Injectable `ClockInterface` |
| `app/modules/auth/index.php`, `app/system/modules/user/index.php` | Wire real clock at factory sites |
| `app/system/modules/site/src/Controller/MenuApiController.php` | `#[Assert]` + `ValidatesRequestTrait`; restore `trim()` on id/label |

### New / extended tests (Steps 4–11)

| Area | Files |
|---|---|
| Filter | `AddRelNofollowTest.php` — 3 XSS edge-case tests |
| Auth | `DatabaseHandlerTest.php` — `MockClock` boundary tests |
| User | `LoginAttemptListenerTest.php` — `MockClock` boundary tests; `UserAccessTest.php` — parser mutant coverage |
| Database | `EntityManagerTest.php` — ORM query-cache invalidation regression |
| Site | `MenuApiControllerTest.php` — NEW site module `Tests/` dir (save/validation/delete/whitespace) |
| Container | `tests/Unit/Container/` — module `$app` fallback, factory/finder freshness |
| Package | `tests/Unit/Package/PackageManagerMigrationTest.php` — enable/uninstall migration integration |
| Console | `tests/Unit/Console/MigrationCommandTest.php` — CLI integration (CommandTester) |

### Config / CI (Steps 6, 12, 13)

| File | Change |
|---|---|
| `phpunit.xml.dist` | Flip `failOnWarning`/`failOnPhpunitWarning`/`failOnRisky` → `"true"` |
| `infection.json.dist` | Drop resolved `LessThan` ignores (clock boundaries) + UserAccessTest-masked parser ignores |
| `composer.json` / `composer.lock` | Add `psr/clock` prod + `symfony/clock` dev |
| `.github/workflows/php-quality.yml` | Minimum line-coverage gate (3.8 %) + Codecov upload (non-blocking) |
| `README.md` | Codecov coverage badge |

### Finalize-phase fixes

| Commit | Change |
|---|---|
| `style(test)` | PHP-CS-Fixer violations in `PackageManagerMigrationTest.php` |
| `fix(site)` | Restore menu id/label trimming in `saveAction()` (Bugbot finding) |

---

## 📊 CI Line-Coverage Gate — Pinned Baseline (Step 12, §4.2)

A ratcheting minimum line-coverage gate was added to the `phpunit (8.3)` leg of
`.github/workflows/php-quality.yml`. It parses the Clover `<project><metrics>`
node emitted by `--coverage-clover=coverage.xml` and fails the job when line
coverage drops below the pinned floor.

| Metric | Value |
|---|---|
| **Measured line coverage (after Steps 1-11)** | **3.86 %** (`1833 / 47535` statements) |
| **Pinned CI floor** (`MIN_LINE_COVERAGE`) | **3.8 %** (rounded DOWN from 3.8561 %) |
| Pre-ticket baseline (for reference) | 3.21 % (`1524 / 47527`, planning-time) |
| Coverage driver (measurement) | PCOV 1.0.11 / PHP 8.3.6 |
| Coverage driver (CI enforcement) | Xdebug (`shivammathur/setup-php`) |
| PHPUnit | 11.5.55 |
| Measured on | 2026-07-09 |

**Why 3.8 % and not 3.86 %:** the value is rounded **down** to absorb (a) the
small line-count difference between PCOV (used for the local measurement) and
Xdebug (used by CI), and (b) general floating-point jitter.

**Ratchet policy:** the floor only ever moves **up** — never lower it.

---

## 📈 Codecov Integration — Badge + Per-PR Delta (Step 13, §4.3)

| Change | Location | Notes |
|---|---|---|
| Codecov upload step | `.github/workflows/php-quality.yml` (`phpunit` **8.3** leg) | `codecov/codecov-action` pinned by SHA `0fb7174895f61a3b6b78fc075e0cd60383518dac` |
| Non-blocking upload | same step | `fail_ci_if_error: false` |
| Token wiring | same step | `token: ${{ secrets.CODECOV_TOKEN }}` — best-effort until secret exists |
| Coverage badge | `README.md` | Codecov badge alongside PHP/Symfony/Vue badges |

### ⚠️ External activation required (flagged, NOT blocking)

1. Install the **Codecov GitHub App** on `Shadesman5/pagekit`.
2. Add **`CODECOV_TOKEN`** repository secret in GitHub Actions.

---

## 🧪 Test Results

| Gate | Result |
|---|---|
| PHPUnit (local) | 488 tests, 1111 assertions — 0 failures |
| PHPStan (local) | PASS |
| CI — phpunit (8.2) | ✅ success |
| CI — phpunit (8.3) | ✅ success (coverage gate 3.8 % enforced) |
| CI — phpstan | ✅ success |
| CI — cs-fixer | ✅ success |
| CI — security-audit | ✅ success |
| Cursor Bugbot | ✅ pass (after menu trim fix) |
| E2E installation.spec.js | 1/1 passed |
| E2E authentication.spec.js | 14/14 passed |
| E2E dashboard.spec.js | 10/10 passed |

**CI run:** https://github.com/Shadesman5/pagekit/actions/runs/29056951601

---

## 📋 Deferred

- **Breadth coverage** to §1 target table (core 80 %+, system 75 %+, packages 60 %+) → ongoing / Step 2.9 closeout
- **Full E2E rework** (Phase 1 audit 1.10.5) → **Step 3.6.1**
- **`packages/` coverage** (out of `<source>` scope) → ongoing
- **DB/kernel-bound unit gaps** (`UserProvider` happy paths, `UserListener`, `User::hasPermission` uncached) → Step 2.9
- **Infection MSI ratcheting** beyond auth+user → Step 2.9 closeout
