# Step 2.1.8 — Infection Mutation Testing

**Branch:** `feature/infection-mutation-testing`
**ROADMAP Step:** 2.1.8 (Static Analysis & Code Quality — Infection Mutation Testing)
**GitHub Issue:** [#155](https://github.com/Shadesman5/pagekit/issues/155)
**Pull Request:** [#216](https://github.com/Shadesman5/pagekit/pull/216)
**Status:** ✅ Complete — Ready for Review
**Date:** 2026-07-09

---

## 🎯 Overview

Step 2.1.8 introduces [Infection](https://infection.github.io/) mutation testing for the auth + user
security-critical core, adds the PHPUnit test base required to reach the **≥80% MSI / ≥80% Covered MSI**
gate, and documents DB/kernel-bound exclusions deferred to Step 2.1.9.

Infection CI wiring (scheduled + manual-dispatch job) remains deferred to Step 2.2 per ticket plan.

---

## ✅ What Changed

### Tooling (Step 1)

| File | Change |
|---|---|
| `composer.json` | Added `infection/infection` dev dependency (`>=0.29 <0.33` for PHP 8.2 + PHPUnit 11); `config.platform.php: 8.2.0` so lock resolves on CI matrix |
| `composer.lock` | Pins Infection 0.32.6 and PHP-8.2-compatible transitive deps |
| `infection.json.dist` | Scoped to auth + user security core; `minMsi` / `minCoveredMsi`: 80%; logs + `tmpDir` under `tmp/infection` |

### New / extended tests (Steps 2–7)

| File | Change |
|---|---|
| `app/modules/auth/src/Tests/NativePasswordEncoderTest.php` | NEW — hash/verify + salt-rejection branch |
| `app/modules/auth/src/Tests/DatabaseHandlerTest.php` | EXTENDED — `read()` + `destroy()` boundary branches |
| `app/system/modules/user/src/Tests/UserProviderTest.php` | NEW — `validateCredentials()` + `findByCredentials()` guards |
| `app/system/modules/user/src/Tests/RoleTest.php` | NEW — pure role methods + system-role flags |
| `app/system/modules/user/src/Tests/UserTest.php` | NEW — status flags, `getStatusText()`, cached `hasPermission()` |
| `app/system/modules/user/src/Tests/LoginAttemptListenerTest.php` | NEW — brute-force throttle boundaries |
| `app/system/modules/user/src/Tests/AuthorizationListenerTest.php` | NEW — auth lifecycle + blocked-user enforcement |
| `app/system/modules/user/src/Tests/AccessListenerTest.php` | NEW — request-time access enforcement |

### Infection gate (Step 8)

- Local run with PCOV/Xdebug coverage driver reaches **≥80% MSI** and **≥80% Covered MSI** on configured classes.
- Equivalent / DB-bound mutants documented via `infection.json.dist` `ignore` entries with Step 2.1.9 references where applicable.

### Post-finalize CI fix

- **Infection 0.34 PHP 8.3-only lock** broke `composer install` on the `phpunit (8.2)` matrix leg; constraint capped at `<0.33` with platform PHP 8.2.0.

---

## ⚠️ Environment Precondition

Infection requires a coverage driver (**PCOV or Xdebug**). Neither is installed by default in the
Cloud Agent VM — enable one before running `./app/vendor/bin/infection` locally:

```bash
# PCOV (preferred — faster)
pecl install pcov && echo "extension=pcov.so" > /etc/php/8.3/mods-available/pcov.ini

# Or Xdebug
XDEBUG_MODE=coverage ./app/vendor/bin/infection --threads=4
```

---

## 🧪 Test Results

| Gate | Result |
|---|---|
| PHPUnit (local) | 445 tests, 0 failures |
| PHPStan (local) | PASS |
| Infection (local, PCOV) | MSI ≥ 80%, Covered MSI ≥ 80% |
| CI — phpunit (8.2) | ✅ success |
| CI — phpunit (8.3) | ✅ success |
| CI — phpstan | ✅ success |
| CI — cs-fixer | ✅ success |
| CI — security-audit | ✅ success |
| Cursor Bugbot | ✅ pass |
| E2E installation.spec.js | 1/1 passed |
| E2E authentication.spec.js | 14/14 passed |
| E2E dashboard.spec.js | 10/10 passed |

**CI run:** https://github.com/Shadesman5/pagekit/actions/runs/28990733935

---

## 📋 Deferred to Step 2.1.9

- `UserProvider::find()` / `findByUsername()` / `findByCredentials()` happy paths (static `User::` DB lookups)
- `User::hasPermission()` uncached `findRoles()` path
- Static DB trait methods in `UserModelTrait` / `RoleModelTrait` / `AccessModelTrait`
- `UserListener` (static `User::` delegations; only `subscribe()` unit-testable)
- `AccessListener::onConfigureRoute` / `processAccessAttribute` (Reflection-heavy route attribute path)
- Time-boundary tests needing an injectable clock (e.g. `Psr\Clock\ClockInterface`) — two `LessThan` mutants only flip on exact equality and are Infection ignores today: `DatabaseHandler::read:53` (`strtotime($access) + timeout < time()`) and `LoginAttemptListener::onPreAuthenticate:43` (`(time() - $last) < DELAY`). One clock abstraction resolves both.
- PHPUnit `failOnWarning` / `failOnPhpunitWarning` / `failOnRisky` are `"false"` in `phpunit.xml.dist` (flagged → Step 2.1.9). Flipping `failOnWarning` to `"true"` also lets the `UserAccessTest` boolean-parser ignore groups (`infection.json.dist` → `LessThan`/`LessThanNegotiation`/`GreaterThanOrEqualTo`/`LogicalOr`/`LogicalAnd` on `User::parse*`) be dropped.
