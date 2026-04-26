# Test Infrastructure Cleanup (Step 2.0.6)

## Overview

This branch executes **ROADMAP Step 2.0.6 — Test Infrastructure Cleanup**, the
last test-tooling cleanup in the Foundation Consolidation block. The goal is a
single canonical PHPUnit configuration, modern PHPUnit 11 attribute syntax in
all test files, no leftover legacy Doctrine cache imports, and no silent
exception swallowing in the routing loader that was originally a test-time
debugging artefact. The result is a healthy, audit-clean test layer that can
support the upcoming Step 2.0.7 (Event Dispatcher Bridge Removal) and Step 2.1
PHPStan level raises without dragging legacy syntax along.

- **Branch:** `cursor/step-2-0-6-test-infrastructure-cleanup-f348`
- **Status:** Completed
- **Version:** 1.2.11
- **ROADMAP step:** 2.0.6 (Foundation Consolidation)
- **GitHub Issue:** #183
- **Architect ticket:** `.cursor/tickets/PROMPT_2_0_6_Test-Infrastructure-Cleanup_plan.md`

## Scope

- `app/modules/filter/phpunit.xml.dist` — deleted
- `app/modules/filesystem/phpunit.xml.dist` — deleted
- `app/modules/cookie/phpunit.xml.dist` — deleted
- `app/modules/auth/phpunit.xml.dist` — deleted
- `app/modules/filter/src/Tests/PregReplaceTest.php` — `@dataProvider` → `#[DataProvider]`
- `app/modules/filter/src/Tests/StripNewlinesTest.php` — `@dataProvider` → `#[DataProvider]`
- `app/modules/filesystem/src/Tests/PathTest.php` — 3× `@dataProvider` → `#[DataProvider]`
- `app/modules/filesystem/src/Tests/LocatorTest.php` — `@dataProvider` → `#[DataProvider]`
- `app/system/modules/mail/src/Tests/MailerTest.php` — `@group` → `#[Group]`
- `app/system/modules/mail/src/Tests/Integration/MailIntegrationTest.php` — `@group` → `#[Group]`
- `app/system/modules/mail/src/Tests/Controller/MailControllerTest.php` — `@group` → `#[Group]`
- `app/modules/config/src/Tests/ConfigManagerTest.php` — modernized
- `phpstan-baseline.neon` — stale entries for `ConfigManagerTest.php` removed
- `app/modules/routing/src/Loader/RoutesLoader.php` — silent catch replaced with debug-aware handler
- `app/modules/routing/index.php` — pass `$app` into `RoutesLoader`

## Out of Scope (deferred)

- **Step 2.0.7** (Event Dispatcher Bridge Removal) — `SymfonyEventDispatcherBridge`
  cleanup is the next ticket; no event-dispatcher code or services touched here.
- **Step 2.0.8** (Hotfix `create_function()` in User module).
- **Step 2.1.3** (`strict_types` Migration) — adding `declare(strict_types=1);`
  to test files that lack it is explicitly tracked there; not done as part of
  this ticket to keep the diff focused.
- **Step 2.1.4–2.1.6** (PHPStan level raises) — only the existing baseline is
  run for verification; no level changes.
- Other audit findings tied to other steps (`assertEquals` → `assertSame`
  sweep, `MenuApiController` validation, `MailerTest::send()` return type) —
  remain in their respective Step 2.1.x / 2.0.4 follow-ups.

## Changes

### 1. Pre-flight baseline

**Files:** `.cursor/tickets/PROMPT_2_0_6_Test-Infrastructure-Cleanup_plan.md`

Architect ticket added to record the agreed scope, exhaustive file list, and
per-step gates. PHPUnit + PHPStan green baseline confirmed before any code
changes.

### 2. Obsolete module-level `phpunit.xml.dist` files removed

**Files (all deleted):**

- `app/modules/filter/phpunit.xml.dist`
- `app/modules/filesystem/phpunit.xml.dist`
- `app/modules/cookie/phpunit.xml.dist`
- `app/modules/auth/phpunit.xml.dist`

These configs used the PHPUnit 9 schema with broken bootstrap paths and were
no longer used by any tooling. Their tests are already discovered by the root
`phpunit.xml.dist` via the `app/modules/*/src/Tests` glob, so the deletion is
behaviour-neutral. DELETE OVER WRAP — no migration to PHPUnit 11 schema since
the configs are unnecessary altogether.

### 3. Root `phpunit.xml.dist` test path casing audit

**Files:** none (audit-only)

Verified that the on-disk directory `tests/Unit` (capital U) matches the
existing `<directory>tests/Unit</directory>` reference in `phpunit.xml.dist`.
The "fix" listed in the prompt was a no-op on the current tree — no XML edit
required.

### 4. `@dataProvider` PHPDoc → `#[DataProvider]` attribute

**Files:**

- `app/modules/filter/src/Tests/PregReplaceTest.php`
- `app/modules/filter/src/Tests/StripNewlinesTest.php`
- `app/modules/filesystem/src/Tests/PathTest.php` (3 occurrences)
- `app/modules/filesystem/src/Tests/LocatorTest.php`

Each file imports `use PHPUnit\Framework\Attributes\DataProvider;` and uses
`#[DataProvider('provideX')]` directly above each method. All
`@dataProvider` PHPDoc references purged from the codebase (`rg "@dataProvider"
app/ packages/ --glob "*Test.php"` is empty).

### 5. `@group` PHPDoc → `#[Group]` attribute

**Files:**

- `app/system/modules/mail/src/Tests/MailerTest.php`
- `app/system/modules/mail/src/Tests/Integration/MailIntegrationTest.php`
- `app/system/modules/mail/src/Tests/Controller/MailControllerTest.php`

Each file imports `use PHPUnit\Framework\Attributes\Group;` and uses
`#[Group('<name>')]` at the same target (class- or method-level) the original
`@group` was attached to. All `@group` PHPDoc references purged from the test
suite.

### 6. Remaining PHPDoc test annotation sweep

**Files:** none (no-op)

`@test`, `@covers`, `@depends`, and `@requires` PHPDoc annotations were
audited across `app/` and `packages/` test files; zero matches found. No edits
needed.

### 7. `ConfigManagerTest` modernization

**Files:**

- `app/modules/config/src/Tests/ConfigManagerTest.php`
- `phpstan-baseline.neon`

- Removed `use Doctrine\Common\Cache\ArrayCache;` (the legacy cache adapter is
  no longer present anywhere in the repository).
- Deleted the `getCache()` helper and all commented-out legacy test methods
  that previously exercised the (now-gone) `ArrayCache` argument. DELETE OVER
  WRAP — no `markTestSkipped` or `markTestIncomplete` placeholders.
- Adapted `getConfig()` so it constructs `ConfigManager` via the modern
  signature `(Connection $connection, array $config)` (previous code passed a
  third cache argument).
- Modernized the remaining `->will($this->returnValue(true))` mock pattern to
  `->willReturn(true)`.
- Pruned three stale `phpstan-baseline.neon` entries that referenced the
  removed `ArrayCache` and 3-arg `ConfigManager` constructor calls. PHPStan's
  `ignore.unmatched` rule otherwise reports stale baseline entries as errors.

### 8. PHPUnit mock patterns audit

**Files:** none (no-op)

After Step 7 the codebase has zero `->will($this->returnValue(...))`,
`returnValueMap()`, `returnCallback()`, `returnSelf()`, or
`onConsecutiveCalls()` calls in any test file. The remaining
`->will($this->throwException(...))` calls in mail tests are explicitly out
of scope per the ticket.

### 9. Silent `InvalidArgumentException` in `RoutesLoader::addController()`

**Files:**

- `app/modules/routing/src/Loader/RoutesLoader.php`
- `app/modules/routing/index.php`

The previous `catch (\InvalidArgumentException $e) {}` swallowed real
controller-loading bugs in production. The new handler:

- Re-throws when `$app->get('debug')` is truthy, so debugging immediately
  surfaces the actual problem.
- Otherwise logs via `$app->get('log')->warning(...)` if the `log` service is
  registered, or falls back to `error_log(...)`.
- Defends against `Application` not being injected (`?Application $app =
  null`) and against the `debug`/`log` services being absent
  (`$app->has(...)`-guarded).

`app/modules/routing/index.php` was updated so the loader receives `$app` at
construction. No new helper classes, no adapter — direct constructor
injection (Rule 2: NO ADAPTERS).

`php pagekit list` continues to boot the console cleanly with the updated
loader.

### 10. Final consolidated audit

After Step 9 the following sweeps are all clean:

- `rg -l "phpunit.xml" app/ packages/ --glob "*.dist"` → empty (only the root
  config remains).
- `rg "@dataProvider|@group|@test\b|@covers|@depends|@requires" app/
  packages/ --glob "*Test.php"` → empty.
- `rg "returnValue\(" app/ packages/ --glob "*Test.php"` → empty.
- `rg "use Doctrine\\\\Common\\\\Cache" app/ packages/ --glob "*Test.php"`
  → empty.
- `rg "backupStaticAttributes|convertErrorsToExceptions|syntaxCheck" app/
  packages/` → empty.

## Breaking Changes

### For Core System

None — all existing tests still run unchanged through the root
`phpunit.xml.dist`, with the same discovered set (294 tests).

### For Extensions

- **`Pagekit\Routing\Loader\RoutesLoader::__construct()` signature** — Now
  accepts an optional `Application` argument as the third parameter (`?Application $app = null`).
  Existing extensions that construct `RoutesLoader` directly (rare; the loader is
  registered as a singleton service) keep working since `$app` is optional and
  defaults to `null`. Passing `$app` enables the new debug-aware exception
  handler (re-throw in debug, log in production); without it, the handler
  falls back to `error_log()`.
- **`InvalidArgumentException` in route loading is no longer silent** —
  Extensions that relied on the previous silent swallowing of
  `InvalidArgumentException` from `addController()` (e.g. by intentionally
  registering invalid controller patterns) will now surface those errors in
  debug mode and log them in production. The fix is to register valid
  controller patterns; this is the intended behaviour.

### For Tests

- **PHPUnit attribute syntax required for migrated tests** — Test files
  edited in this branch now use `#[DataProvider]` and `#[Group]` attributes
  instead of `@dataProvider` / `@group` PHPDoc. Custom test runners that grep
  for the old PHPDoc annotations need updating; PHPUnit 11 itself reads both,
  but the codebase no longer emits the legacy form.

## Test Results

### Per-step gate (every checklist step)

- ✅ `./app/vendor/bin/phpunit` — 294 tests, 736 assertions, 0 failures
- ✅ `./app/vendor/bin/phpstan analyse` — clean against baseline
- ✅ `php pagekit list` — boots cleanly (Step 9 only)

### Final acceptance gate

- ✅ `./app/vendor/bin/phpunit` — 294 tests, 736 assertions, 0 failures
  (1 pre-existing SMTP warning, 5 pre-existing skips)
- ✅ `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M` —
  no errors beyond baseline
- ✅ `php pagekit setup` — completes successfully
- ✅ `php pagekit list` — Pagekit 1.2.11 console boots, all commands listed
- ✅ Playwright E2E (chromium):
  - `tests/e2e/specs/01-setup/installation.spec.js` — 1/1 passed
  - `tests/e2e/specs/02-core/authentication.spec.js` — 14/14 passed
  - `tests/e2e/specs/02-core/dashboard.spec.js` — 10/10 passed

Firefox/WebKit Playwright browsers are not pre-installed in the cloud agent
VM (they need root for `playwright install-deps`); the chromium project run
is the canonical run for this branch (gated by `PW_BROWSERS=all` for full CI
hosts).

## References

- ROADMAP step: `2.0.6` (Foundation Consolidation block)
- Task prompt:
  `migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/PROMPT_2_0_6_Test-Infrastructure-Cleanup.md`
- Architect ticket: `.cursor/tickets/PROMPT_2_0_6_Test-Infrastructure-Cleanup_plan.md`
- GitHub Issue: #183
