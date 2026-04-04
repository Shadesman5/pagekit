# Package Migration System Redesign

## Overview

This document describes the unification of the Pagekit package/migration system so that Doctrine Migrations are first-class citizens in every update code path — login check, update wizard, CLI command, and extension lifecycle.

**Migration Date**: April 2026
**Pagekit Version**: 1.2.9
**Branch**: `cursor/package-migration-system-redesign-2ba5`
**ROADMAP Step**: 2.0.4
**GitHub Issue**: #180
**Status**: ✅ COMPLETED

## Migration Summary

### What Changed

- **Hardened MigrationService** — Removed `is_array($result)` guard branches from `migrate()`, `rollback()`, `migrateExtension()`, `rollbackExtension()`. Doctrine Migrations 3.x always returns `array<string, ExecutionResult>`. Deleted unused `getConfigPath()` method.
- **Unified login check** — `auth.login` event handler now checks `MigrationService::status()['has_pending']` before allowing silent version bump. Redirects to migration wizard if Doctrine migrations OR scripts are pending.
- **Unified update wizard** — `MigrationController::migrateAction()` runs Doctrine migrations before `PackageScripts::update()`. `indexAction()` shows wizard when pending Doctrine migrations exist.
- **Unified CLI migrate command** — `MigrationCommand::execute()` runs Doctrine migrations first, then scripts. Version bump only after both succeed. Uses `Command::SUCCESS`/`FAILURE` constants.
- **Extension auto-migrate on enable** — `PackageManager::enable()` detects `src/Migrations/` directory and runs `MigrationService::migrateExtension()` before setting version.
- **Extension auto-rollback on uninstall** — `PackageManager::uninstall()` rolls back extension Doctrine migrations before file removal. Failure logged but does not block uninstall.
- **Removed DatabaseHandler::createTable()** — Table schema now exclusively managed by Doctrine migration `Version20251023061532`.
- **Blog migration renamed** — `Version001_CreateBlogTables` → `Version20251023070000_CreateBlogTables` (timestamp format consistent with core).
- **PackageManager API typed** — All public methods have proper PHP 8.2+ type declarations.
- **Real MigrationServiceTest** — All `markTestSkipped()` stubs replaced with real SQLite in-memory tests covering migrate, rollback, status, extension operations.
- **Audit cleanup** — Resolved TODO in `scripts.php`, verified zero silent version bumps.

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
| `app/modules/migration/src/MigrationService.php` | Hardened return values, deleted dead code |
| `app/system/index.php` | Unified login check with migration status |
| `app/system/src/Controller/MigrationController.php` | Unified update wizard with Doctrine migrations |
| `app/console/src/Commands/MigrationCommand.php` | Unified CLI command with Doctrine migrations |
| `app/installer/src/Package/PackageManager.php` | Auto-migrate/rollback, typed public API |
| `app/modules/auth/src/Handler/DatabaseHandler.php` | Removed deprecated createTable() |
| `packages/pagekit/blog/src/Migrations/2025/Version20251023070000_CreateBlogTables.php` | Renamed from Version001 |
| `tests/unit/Migration/MigrationServiceTest.php` | Real test implementations |
| `app/system/scripts.php` | Removed resolved TODO |
| `phpstan-baseline.neon` | Removed stale baseline entries |

## Test Results Summary

- ✅ PHPUnit: 280 tests, 674 assertions, 0 failures
- ✅ PHPStan: 7 errors (all pre-existing, unrelated to this task)
- ✅ `php pagekit setup`: Success
- ✅ `php pagekit list`: 17 commands listed
- ✅ Playwright E2E (Chromium): installation (1 passed), authentication (14 passed), dashboard (10 passed)

## Referenced ROADMAP Step

**Step 2.0.4** — Package/Migration System Redesign (Foundation Consolidation)
