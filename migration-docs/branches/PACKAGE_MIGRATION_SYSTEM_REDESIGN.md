# Package Migration System Redesign

## Overview

This document describes the unification of the Pagekit package/migration system so that Doctrine Migrations are first-class citizens in every update code path — login check, update wizard, CLI command, and extension lifecycle.

**Migration Date**: April 9, 2026
**Pagekit Version**: 1.2.9
**Branch**: `cursor/package-migration-system-redesign-2ba5`
**ROADMAP Step**: 2.0.4
**GitHub Issue**: #180
**PR**: #189
**Status**: Completed + Audit Passed
**Commits**: 32 (21 files changed, +1414 / -364 lines)
**Test Count**: 280 → 310

## Migration Summary

### What Changed

- **Hardened MigrationService** — Removed `is_array($result)` guard branches from `migrate()`, `rollback()`, `migrateExtension()`, `rollbackExtension()`. Doctrine Migrations 3.x always returns `array<string, ExecutionResult>`. Deleted unused `getConfigPath()` method. Extracted `createExtensionDependencyFactory()` to DRY up duplicated factory creation. Added `ensureInitialized()` for metadata storage on all DependencyFactory instances.
- **New `getExtensionCurrentVersion()` method** — Returns the current migration version for an extension (`'0'` if none executed). Used for precise rollback targeting in `enable()`.
- **Unified login check** — `auth.login` event handler now checks `MigrationService::status()['has_pending']` before allowing silent version bump. Redirects to migration wizard if Doctrine migrations OR scripts are pending.
- **Unified update wizard** — `MigrationController::migrateAction()` runs Doctrine migrations before `PackageScripts::update()`. `indexAction()` shows wizard when pending Doctrine migrations exist. Both methods defensively check `$this->app->has('migration')` before service access. Script update wrapped in try/catch with JSON error response.
- **Unified CLI migrate command** — `MigrationCommand::execute()` runs Doctrine migrations first, then scripts. Version bump only after both succeed. Uses `Command::SUCCESS`/`FAILURE` constants. Script update wrapped in try/catch.
- **Extension auto-migrate on enable** — `PackageManager::enable()` detects `src/Migrations/` directory and runs `MigrationService::migrateExtension()` before setting version. Captures pre-migration version for precise rollback on failure (only reverts migrations from this `enable()` call, not pre-existing ones).
- **Extension auto-rollback on uninstall** — `PackageManager::uninstall()` rolls back extension Doctrine migrations before file removal. Failure logged but does not block uninstall.
- **Namespace resolution** — `resolveExtensionMigrationNamespace()` derives PSR-4 migration namespace from: 1) module manager autoload, 2) index.php autoload (parsed via `token_get_all()` comment stripping), 3) composer.json PSR-4, 4) StudlyCaps fallback.
- **Removed DatabaseHandler::createTable()** — Table schema now exclusively managed by Doctrine migration `Version20251023061532`.
- **Blog migration renamed** — `Version001_CreateBlogTables` → `Version20251023070000_CreateBlogTables` (timestamp format consistent with core).
- **PackageManager API typed** — All public methods have proper PHP 8.2+ type declarations.
- **Real MigrationServiceTest** — All `markTestSkipped()` stubs replaced with real SQLite in-memory tests: migrate, rollback, status, extension migrate/rollback, no-op migration, `getExtensionCurrentVersion`, and partial rollback (12 tests).
- **PackageManagerNamespaceTest** — 21 tests covering `unescapePhpString`, `extractBracketBody`, `parseAutoloadFromIndexFile`, and `resolveExtensionMigrationNamespace`.
- **Test directory case fix** — `tests/unit/` renamed to `tests/Unit/` for case-sensitive systems (Linux/macOS CI). Previously hidden tests now discovered by PHPUnit.
- **Audit cleanup** — Resolved TODO in `scripts.php`, verified zero silent version bumps. BUGBOT rules updated with PHP 8.2+ error model (Rule 2.2) and resolved/deferred tracking.

### Bug Fixes (post-review session)

- **Enable rollback precision** — `PackageManager::enable()` now captures pre-migration version via `getExtensionCurrentVersion()` and only rolls back migrations applied during the current `enable()` call. Previously rolled back to version `'0'`, which would destroy pre-existing extension tables on re-enable failure.
- **MigrationController defensive guards** — `indexAction()` and `migrateAction()` check `$this->app->has('migration')` before accessing the service, preventing `NotFoundExceptionInterface` when redirected due to pending scripts while migration service isn't registered.
- **CLI/wizard script update guard** — Both `MigrationCommand::execute()` and `MigrationController::migrateAction()` wrap `$scripts->update()` in try/catch to prevent version bump on failure and provide clean error output.
- **Metadata storage auto-initialization** — `ensureInitialized()` called on both core and extension `DependencyFactory`, fixing metadata storage errors on fresh databases (root cause of previously "hidden" test failures).
- **`getExtensionCurrentVersion()` catch widened** — `catch (\Exception)` → `catch (\Throwable)` to handle `\TypeError` from malformed data, matching the method's graceful-fallback intent.
- **Autoload parser comment immunity** — `parseAutoloadFromIndexFile()` now strips PHP comments via `token_get_all()` before regex search, preventing false matches from commented-out autoload config.
- **Test helpers** — `invokePrivate`/`invokeProtected` merged to `invokeMethod`, `createPackageStub` now stores `$type` parameter, `@var` annotation corrected for `$appliedMigration` shape.

### Why This Migration

1. **No Compatibility Layers** (ROADMAP Rule 1) — Doctrine Migrations integrated directly, no dual code paths.
2. **No Adapters** (ROADMAP Rule 2) — Call sites updated directly instead of wrapping old behavior.
3. **Delete Over Wrap** (ROADMAP Rule 4) — `DatabaseHandler::createTable()` deleted; migration handles schema.
4. **Internal Breaking Changes Allowed** (ROADMAP Rule 3) — `PackageManager::enable()` now requires `migration` service for auto-migration.

## Breaking Changes for Extensions

- **`PackageManager::enable()` auto-migration**: Extensions with a `src/Migrations/` directory will have migrations auto-executed on enable and auto-rolled-back on uninstall.
- **`PackageManager` method signatures**: All public methods now have union/object type declarations — callers passing incorrect types will get `TypeError`.
- **`DatabaseHandler::createTable()` removed**: The `@system_auth` table is now exclusively created by Doctrine migration. Extensions that called this method must use their own migration.

## Files Changed

| File | Change |
|---|---|
| `app/modules/migration/src/MigrationService.php` | Hardened return values, DRY factory helper, `getExtensionCurrentVersion()`, `ensureInitialized()`, deleted dead code |
| `app/system/index.php` | Unified login check with migration status |
| `app/system/src/Controller/MigrationController.php` | Unified update wizard, `has('migration')` guards, script try/catch |
| `app/console/src/Commands/MigrationCommand.php` | Unified CLI command, script try/catch, `declare(strict_types=1)` |
| `app/installer/src/Package/PackageManager.php` | Auto-migrate/rollback, typed API, namespace resolution, comment-safe autoload parser, `stripPhpComments()` |
| `app/modules/auth/src/Handler/DatabaseHandler.php` | Removed deprecated `createTable()` |
| `packages/pagekit/blog/src/Migrations/2025/Version20251023070000_CreateBlogTables.php` | Renamed from Version001 |
| `tests/Unit/Migration/MigrationServiceTest.php` | 12 real test implementations (was `tests/unit/`) |
| `tests/Unit/Installer/PackageManagerNamespaceTest.php` | 21 tests for namespace resolution and parsing |
| `app/system/scripts.php` | Removed resolved TODO |
| `phpstan-baseline.neon` | Removed stale baseline entries |
| `.cursor/ROADMAP.md` | Step 1.12 + 2.0.4 audit-passed |
| `.cursor/BUGBOT.md` | PHP 8.2+ error model rule, resolved/deferred items |
| `CHANGELOG-NEW.md` | Full 1.2.9 entry with bug fixes section |
| `migration-docs/TODO/PHASE_2_MODERNISING.md` | Deferred integration test items for Step 2.1.9 |

## Deferred Items

| Item | Tracked In | Issue |
|---|---|---|
| `PackageManager::enable()`/`uninstall()` migration integration tests | Step 2.1.9 (Test Coverage Expansion) | #156 |
| `MigrationCommand` integration test (Doctrine + scripts flow) | Step 2.1.9 (Test Coverage Expansion) | #156 |
| Blog migration version table update for existing installations | Step 2.0.5 (Composer & Autoload Hygiene) | #182 |

## Test Results Summary

- PHPUnit: 310 tests, 731 assertions, 0 failures
- PHPStan: 0 errors
- `php pagekit setup`: Success
- `php pagekit list`: 17 commands listed
- Playwright E2E (Chromium): installation (1 passed), authentication (14 passed), dashboard (10 passed)

## Referenced ROADMAP Steps

- **Step 2.0.4** — Package/Migration System Redesign (Foundation Consolidation) — Audit Passed
- **Step 1.12** — DB Migration System — Audit debt resolved by this step, promoted to Audit Passed
