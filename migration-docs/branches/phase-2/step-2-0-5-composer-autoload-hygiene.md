# Composer & Autoload Hygiene (Step 2.0.5)

## Overview

This branch executes **ROADMAP Step 2.0.5 — Composer & Autoload Hygiene**, the
last cleanup step in the Foundation Consolidation block before the harder
Phase 2 work (event dispatcher bridge removal, ORM tightening, public API). The
goal is a healthy, minimal, audit-clean `composer.json` / `composer.lock` so
later steps can move fast without dragging legacy dependency baggage.

- **Branch:** `cursor/step-2-0-5-composer-autoload-hygiene-7733`
- **Status:** Completed
- **Version:** 1.2.10
- **ROADMAP step:** 2.0.5 (Foundation Consolidation)
- **GitHub Issue:** #182
- **PR:** #192

## Scope

- `composer.json` (root) — schema, `autoload.psr-4`, `require`, `require-dev`
- `composer.lock` — regenerated after each substep
- `phpstan-baseline.neon` — refreshed (preflight cleanup)
- `app/modules/auth/index.php` — `auth.random` service removal
- `app/modules/auth/src/Handler/DatabaseHandler.php` — `RandomLib` removal
- `app/installer/src/Installer.php` — `RandomLib` removal

## Out of Scope (deferred)

- **Step 2.0.6** (Test Infrastructure Cleanup) — module-level
  `phpunit.xml.dist` cleanup, PHPUnit attribute migration, leftover
  `Doctrine\Common\Cache\ArrayCache` import.
- **Step 2.0.7** (Event Dispatcher Bridge Removal).
- **Step 2.0.8** (Hotfix `create_function()` in User module).
- **Step 2.1.x** (PHPStan tightening) — only the existing baseline is run; no
  level changes.
- Real public API / CI/CD pipeline (Step 2.2).

## Changes

### 1. Preflight composer schema cleanup

**Files:** `composer.json`, `composer.lock`, `phpstan-baseline.neon`

- Removed invalid `title` property and discouraged `version` field from
  `composer.json` so `composer validate --strict` passes.
- Regenerated `phpstan-baseline.neon` — stale `class.nameCase` suppressions
  for `MySqlPlatform` were replaced with the current `class.notFound` errors;
  one stale `requireOnce.fileNotFound` entry (cache module bootstrap) was
  removed.

### 2. Dead PSR-4 autoload mappings removed

**File:** `composer.json`

Removed mappings whose target directories do not exist:

- `Pagekit\Theme\` → `app/system/modules/theme/src` (deleted)
- `Pagekit\Package\` → `app/system/modules/package/src` (deleted)

`Pagekit\Installer\Package\*` (mapped via `Pagekit\Installer\` →
`app/installer/src`) is unaffected and not to be confused with the removed
`Pagekit\Package\` namespace.

### 3. Unused direct dependencies removed

**Files:** `composer.json`, `composer.lock`

All candidates verified as **zero direct PHP usage** before removal:

| Package | Removed from |
|---|---|
| `symfony/framework-bundle` | `require` |
| `symfony/twig-bridge` | `require` |
| `symfony/yaml` | `require` |
| `symfony/process` | `require` |
| `paragonie/sodium_compat` | `require` |
| `doctrine/data-fixtures` | `require-dev` |

No adapters, no shims — pure deletion.

### 4. `symfony/validator` aligned to Symfony 6.4 LTS

**Files:** `composer.json`, `composer.lock`

`"symfony/validator": "^7.4"` → `"^6.4"`. All 13 PHP files using the Validator
were inspected; no 7.x-only constraints/attributes are in use, so the LTS line
applies cleanly. `composer.lock` resolves to `v6.4.36`.

### 5. `paragonie/random-lib` replaced with native PHP

**Files:** `composer.json`, `composer.lock`,
`app/modules/auth/index.php`,
`app/modules/auth/src/Handler/DatabaseHandler.php`,
`app/installer/src/Installer.php`

- `paragonie/random-lib` (and transitive `ircmaxell/security-lib`) removed
  from `composer.json` / `composer.lock`.
- All `RandomLib\Generator->generateString(64)` call sites replaced with
  native `bin2hex(random_bytes(32))` (same 64-character hex output).
- `auth.random` container service removed from `app/modules/auth/index.php`.
- `DatabaseHandler::__construct()` lost its `RandomLib\Generator $random`
  parameter; `$config` parameter tightened to `?array`.

DELETE OVER WRAP — no adapter, no wrapper class.

### 6. `psr/log` widened

**Files:** `composer.json`, `composer.lock`

`"psr/log": "^2.0"` → `"^2.0|^3.0"` (matches the existing `psr/cache` pattern
and lets Monolog 3.x's PSR Log 3.x interfaces resolve cleanly). Lock now
resolves `psr/log` `3.0.2`.

### 7. Final consistency pass

- All `symfony/*` requires on `^6.4`, no `^7.x` mix.
- All declared PSR-4 autoload paths exist on disk.
- `composer dump-autoload --optimize` regenerated final classmap.

## Breaking Changes

### For Core System

None — all external behavior is preserved (token output length and entropy
match the previous `RandomLib\Generator->generateString(64)` calls; native
`random_bytes()` is the OS CSPRNG).

### For Extensions

If a third-party extension relies on the `auth.random` container service or
constructs `Pagekit\Auth\Handler\DatabaseHandler` with the previous
`(string $key, RandomLib\Generator $random, array $config = [])` signature, it
must update to the new `(string $key, ?array $config = null)` signature and
generate its own random tokens (e.g. via `bin2hex(random_bytes(32))`).

The `Pagekit\Theme\` and `Pagekit\Package\` PSR-4 prefixes never resolved
(target dirs absent), so removing them cannot break any working extension.

## Test Results

### Per-step gate (every checklist step)

- ✅ `composer validate --strict`
- ✅ `./app/vendor/bin/phpunit` — 289 tests, 0 failures
- ✅ `php pagekit list`
- ✅ `./app/vendor/bin/phpstan analyse` — clean against baseline

### Final acceptance gate

- ✅ `./app/vendor/bin/phpunit` — 289 tests, 710 assertions, 0 failures
  (1 pre-existing SMTP warning, 5 pre-existing skips)
- ✅ `./app/vendor/bin/phpstan analyse` — no errors beyond baseline
- ✅ `php pagekit setup` — completes successfully
- ✅ `php pagekit list` — Pagekit 1.2.10 console boots, all commands listed
- ✅ `composer install --dry-run` — "Nothing to install, update or remove"
- ✅ Playwright E2E (chromium):
  - `installation.spec.js` — 1/1 passed (full installer flow)
  - `authentication.spec.js` — 14/14 passed (login, logout, CSRF, rate
    limiting, session management)
  - `dashboard.spec.js` — 10/10 passed (load, widgets, navigation,
    responsive layout)

Firefox/WebKit Playwright browsers were not pre-installed in the cloud agent
VM (no sudo); the chromium project run is the canonical run for this branch.

## References

- ROADMAP step: `2.0.5` (Foundation Consolidation block)
- Task prompt:
  `migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/PROMPT_2_0_5_Composer-Autoload-Hygiene.md`
- Architect ticket: `.cursor/tickets/PROMPT_2_0_5_Composer-Autoload-Hygiene_plan.md`
- GitHub Issue: #182
- PR: #192
