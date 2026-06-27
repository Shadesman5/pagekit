# Step 2.1.6 — PHPStan Level 7 → 8 (Strict Typing)

**Branch:** `cursor/phpstan-level-8-af04`
**ROADMAP Step:** 2.1.6 (Static Analysis & Code Quality — PHPStan Level 7→8 — Strict Typing)
**GitHub Issue:** [#153](https://github.com/Shadesman5/pagekit/issues/153)
**Pull Request:** [#212](https://github.com/Shadesman5/pagekit/pull/212)
**Status:** ✅ Complete — Ready for Review
**Date:** 2026-06-26

---

## 🎯 Overview

Step 2.1.6 raises the project's PHPStan baseline from `level: 7` to `level: 8`. Level 8
enforces **strict typing** — nullable values must be guarded, `mixed` must be narrowed or
documented, and dynamic property access patterns must be eliminated.

The migration was executed in **16 sequential checklist steps** (18 commits including Bugbot
mini-loop and cs-fixer CI fixes; Step 1 shipped an empty diff per ticket contingency).

Phase 1 audit **1.11 partial** — remaining ORM architectural debt deferred to Steps 2.1.10
and 2.1.11 per ROADMAP.

---

## ✅ What Changed

### `phpstan.neon` configuration

- `level: 7` → `level: 8` (Checklist Step 16).
- Added `phpstan/phpstan-phpunit` extension include.

### Test infrastructure (Step 2)

- Added `phpstan/phpstan-phpunit: ^2.0` to `require-dev`.
- Swept 15+ test files: `protected ?Type $prop = null` → `private Type $prop` (no initializer).
- Removed redundant `assertInstanceOf` narrowing assertions flagged by phpstan-phpunit.

### Dead code removal (Step 3)

- Deleted `DebugStack.php` (zero callers).
- Removed dead query-string parser block in `AliasListener.php`.
- Cleaned PHP 5.x/7.x/APC dead code from `requirements.php`.

### Interface refactors

| Change | Commit |
|---|---|
| `MailPluginInterface` split from `MailerInterface` | `refactor(mail): split MailPluginInterface from MailerInterface` |
| `FileLocatorAsset` constructor injection | `refactor(view): inject FileLocatorAsset dependencies via constructor` |
| `IntlServiceLocator` static container → constructor DI | `refactor(intl): replace IntlServiceLocator static container with DI` |
| Console `Command` setter-DI → constructor DI | `refactor(console): replace setter DI with constructor injection` |
| `PackageController` God-DI → specific services | `refactor(installer): inject specific services into PackageController` |

### Type hardening

| Scope | Commit |
|---|---|
| `ModelServiceLocator` return type narrowing | `refactor(site): narrow ModelServiceLocator return types` |
| `#[AllowDynamicProperties]` removal on Node/Widget | `refactor(site): remove AllowDynamicProperties and add typed props` |
| `UrlGeneratorInterface` → `LinkReferenceType` rename | `refactor: rename UrlGeneratorInterface and GetResponseEvent` |
| `GetResponseEvent` → `AuthResponseEvent` rename | (same commit) |
| EntityManager/ORM typing hardening | `refactor(database): harden ORM typing for phpstan L8` |
| `blog/UrlResolver` static property type-narrow | `refactor(blog): type-narrow UrlResolver static properties for L8` |

### Null-safety sweeps

| Scope | Commit |
|---|---|
| `app/modules/` (29 files) | `fix(modules): null-safety sweep for phpstan level 8` |
| `app/system/`, `app/installer/`, `app/console/` (24 files) | `fix(system): null-safety sweep for phpstan level 8` |

### Mixed usage audit (Step 16)

- Documented all remaining justified `mixed` usages with `@return mixed Genuinely unknown type —` docblocks.
- `#[AllowDynamicProperties]` count = 0 across `app/` and `packages/`.

### Bugbot / CI mini-loop fixes

- **`IntlModule::$app`** — nullable property + `getApp()` guard (Rule 4.2).
- **`UrlResolver` TEMPORARY BRIDGE TODO** — updated to reference Step 2.5.
- **php-cs-fixer** — style fixes across branch-changed files for CI green.

---

## 🧪 Test Results

| Gate | Result |
|---|---|
| PHPUnit (local) | ✅ 326 tests, 708 assertions |
| PHPStan L8 (local) | ✅ No errors |
| CI run 28277728948 | ✅ phpunit 8.2, phpunit 8.3, phpstan, cs-fixer, security-audit |
| Playwright E2E | ✅ installation (1), authentication (14), dashboard (10) |

---

## 🔗 Deferred Work

| Item | Step | Issue |
|---|---|---|
| `ModelServiceLocator` architectural removal | 2.1.10 | #204 |
| `EntityManager` singleton removal | 2.1.11 | #205 |
| `blog/UrlResolver` static locator removal | 2.5 | — |
| QueryBuilder API standardization | 2.1.7 | #154 |
