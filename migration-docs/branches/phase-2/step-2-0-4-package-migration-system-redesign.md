# Package Migration System Redesign

## Overview

This document describes the unification of the Pagekit package/migration system so that Doctrine Migrations are first-class citizens in every update code path — login check, update wizard, CLI command — and the simplification of `PackageManager` by removing auto-migration detection in favor of explicit `scripts.php` hooks.

**Migration Date**: April 9, 2026
**Pagekit Version**: 1.2.9
**Branch**: `cursor/package-migration-system-redesign-2ba5`
**ROADMAP Step**: 2.0.4 + 2.0.4b
**GitHub Issue**: #180
**PR**: #189
**Status**: Completed + Audit Passed

## Migration Summary

### Step 2.0.4 — Core Migration Unification

- **Hardened MigrationService** — Removed `is_array($result)` guard branches. Doctrine Migrations 3.x always returns `array<string, ExecutionResult>`. Deleted unused `getConfigPath()`. Extracted `createExtensionDependencyFactory()`. Added `ensureInitialized()` and `getExtensionCurrentVersion()`.
- **Unified login check** — `auth.login` checks `MigrationService::status()['has_pending']` before version bump, with fail-safe handling (errors treated as pending).
- **Unified update wizard** — `MigrationController::migrateAction()` runs Doctrine migrations before `PackageScripts::update()`. Defensive `has('migration')` guards.
- **Unified CLI command** — `MigrationCommand::execute()` runs Doctrine migrations first, then scripts. Distinguishes "updated" vs "up to date" output.
- **Removed DatabaseHandler::createTable()** — Schema managed by Doctrine migration.
- **Blog migration renamed** — `Version001_CreateBlogTables` → `Version20251023070000_CreateBlogTables` with `createTableIfNotExists()` guards.
- **PackageManager API typed** — All public methods have PHP 8.2+ type declarations.
- **Real MigrationServiceTest** — 12 tests replacing `markTestSkipped()` stubs.

### Step 2.0.4b — PackageManager Simplification

- **Removed auto-migration from `enable()`** — Deleted `$appliedMigration` tracking, `is_dir` detection, `resolveExtensionMigrationNamespace()`, `migrateExtension()` calls. Also removed migration rollback from catch block.
- **Removed auto-rollback from `uninstall()`** — Deleted `rollbackExtension()` auto-detection block. Extensions handle cleanup in their `scripts.php` `uninstall` hook.
- **Deleted namespace resolution methods** — `resolveExtensionMigrationNamespace()`, `parseAutoloadFromIndexFile()`, `extractBracketBody()`, `unescapePhpString()`, `stripPhpComments()` (~170 lines removed).
- **Deleted `PackageManagerNamespaceTest`** — 21 tests covering deleted methods.
- **Blog `scripts.php` updated** — Explicit `enable` hook with `migrateExtension()` call (idempotent). Simplified `install`/`uninstall` hooks. Documentation: schema changes in `src/Migrations/`, `updates` for data only.
- **BUGBOT updated** — Auto-migration removal added to resolved items. Migration integration test deferred item removed (no longer needed).

### Why This Design

1. **No Compatibility Layers** (ROADMAP Rule 1) — Doctrine Migrations integrated directly in core update pipeline.
2. **No Adapters** (ROADMAP Rule 2) — Call sites updated directly.
3. **Delete Over Wrap** (ROADMAP Rule 4) — `DatabaseHandler::createTable()` deleted; auto-migration detection deleted in favor of explicit hooks.
4. **Explicit over implicit** — Extensions control their own migration lifecycle via `scripts.php` hooks rather than PackageManager auto-detecting `src/Migrations/`.

## Breaking Changes for Extensions

- **`PackageManager` method signatures**: All public methods now have union/object type declarations.
- **`DatabaseHandler::createTable()` removed**: Use Doctrine migration instead.
- **No auto-migration on enable/uninstall**: Extensions must call `migrateExtension()`/`rollbackExtension()` explicitly in their `scripts.php` hooks (see blog extension for reference pattern).

## Files Changed

| File | Change |
|---|---|
| `app/modules/migration/src/MigrationService.php` | Hardened return values, DRY factory helper, `getExtensionCurrentVersion()`, `ensureInitialized()` |
| `app/system/index.php` | Unified login check with fail-safe status handling |
| `app/system/src/Controller/MigrationController.php` | Unified update wizard, `has('migration')` guards |
| `app/console/src/Commands/MigrationCommand.php` | Unified CLI command, distinguish messages |
| `app/installer/src/Package/PackageManager.php` | Typed API, removed auto-migrate/rollback, removed namespace resolution methods |
| `app/modules/auth/src/Handler/DatabaseHandler.php` | Removed deprecated `createTable()` |
| `packages/pagekit/blog/src/Migrations/2025/Version20251023070000_CreateBlogTables.php` | Renamed, `createTableIfNotExists()` |
| `packages/pagekit/blog/scripts.php` | Explicit migration hooks pattern |
| `tests/Unit/Migration/MigrationServiceTest.php` | 12 real test implementations |
| `app/system/scripts.php` | Removed resolved TODO |
| `phpstan-baseline.neon` | Removed stale baseline entries |
| `.cursor/BUGBOT.md` | Updated resolved/deferred items |
| `CHANGELOG-NEW.md` | Full 1.2.9 entry |

## Deferred Items

| Item | Tracked In | Issue |
|---|---|---|
| `MigrationCommand` integration test (Doctrine + scripts flow) | Step 2.1.9 (Test Coverage Expansion) | #156 |
| Blog migration version table update for existing installations | Step 2.0.5 (Composer & Autoload Hygiene) | #182 |

## Test Results Summary

- PHPUnit: 289 tests, 710 assertions, 0 failures
- PHPStan: 7 errors (all pre-existing)
- `php pagekit setup`: Success
- `php pagekit list`: All commands listed
- Playwright E2E (Chromium): installation (1), authentication (14), dashboard (10) — all passed

## Referenced ROADMAP Steps

- **Step 2.0.4** — Package/Migration System Redesign (Foundation Consolidation)
- **Step 2.0.4b** — PackageManager Simplification
- **Step 1.12** — DB Migration System — Audit debt resolved
