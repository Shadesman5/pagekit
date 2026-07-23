# Step 2.0.4: Package/Migration System Redesign

**ROADMAP:** 2.0.4 — Foundation Consolidation.  
**GitHub Issue:** #180.  
**Prerequisite:** Step 2.0.3 (Full Cache API Modernization, Issue #179) merged on your branch.

**Also read:** `.cursor/ROADMAP.md` (5 aggressive rules), `migration-docs/TODO/PHASE_2_MODERNISING.md` (Step 2.0.4 section with audit findings).

---

## 1. CONTEXT

### 1.1 Problem

Step 1.12 introduced Doctrine Migrations but did **not** unify the three update paths. Today, **three separate mechanisms** can bump the system version **without** running Doctrine Migrations:

1. **Login check** (`app/system/index.php` ~line 123–137): Only checks `PackageScripts::hasUpdates()`. If `scripts.php` has no updates, the config version is bumped silently — no Doctrine Migrations executed.
2. **Update wizard** (`app/system/src/Controller/MigrationController.php`): `migrateAction()` runs `scripts->update()` then bumps version. **No call to `MigrationService`** at all.
3. **CLI** (`app/console/src/Commands/MigrationCommand.php`): `php pagekit migrate` runs `PackageScripts::update()` only, not Doctrine Migrations. The `migration:migrate` command exists separately but is not integrated.

Additionally:
- Extensions have no automatic migration-on-enable pattern
- `DatabaseHandler::createTable()` does runtime DDL bypassing migrations
- `MigrationServiceTest` has all tests skipped

### 1.2 Goal

**One update path for everything:** Login check, wizard, CLI, and extension lifecycle all go through the same pipeline: Doctrine Migrations first, then scripts.php hooks (for non-SQL work like config, cache). No silent version bumps without migration checks.

### 1.3 Packages

No new Composer packages needed. `doctrine/migrations` is already required.

---

## 2. SAFETY & VERIFICATION

**Workspace root.** PHPUnit: `./app/vendor/bin/phpunit`. PHPStan: `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M`. Console: `php pagekit list`.

**After each numbered section:**

```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

If anything fails → fix before continuing.

---

## 3. UNIFY UPDATE PIPELINE

The core idea: create a **single method** that both the login check, wizard, CLI, and extension lifecycle call. This method runs Doctrine Migrations **then** scripts.php updates.

### 3.1 Login check — `app/system/index.php`

**Current (~line 123–137):** Checks `PackageScripts::hasUpdates()` only. If no script updates, bumps version.

**Change:** Before bumping version, also check for pending Doctrine Migrations via `MigrationService::status()`. If `pending > 0` OR `scripts->hasUpdates()`, redirect to migration wizard. Never bump version without both checks passing.

```php
$hasPendingMigrations = false;
if ($app->has('migration')) {
    $status = $app->get('migration')->status();
    $hasPendingMigrations = ($status['pending'] ?? 0) > 0;
}

if ($scripts->hasUpdates() || $hasPendingMigrations) {
    return $app->get('response')->redirect('@system/migration' . ($redirect ? '?redirect=' . $redirect : ''));
}
```

### 3.2 Update wizard — `app/system/src/Controller/MigrationController.php`

**Current:** `migrateAction()` only runs `scripts->update()`.

**Change:** Execute `MigrationService::migrate()` **before** `scripts->update()`. Check result. On failure, show error instead of silently bumping version.

```php
public function migrateAction(?string $redirect = null): mixed
{
    if ($this->app->has('migration')) {
        $result = $this->app->get('migration')->migrate();
        if (!($result['success'] ?? false)) {
            throw new \RuntimeException('Migration failed: ' . ($result['error'] ?? 'Unknown error'));
        }
    }

    if ($this->scripts->hasUpdates()) {
        $this->scripts->update();
    }

    $this->app->get('config')('system')->set('version', $this->version);

    return $this->redirect($redirect ?: '@system');
}
```

Type the constructor parameters properly (currently `mixed`).

### 3.3 CLI — `app/console/src/Commands/MigrationCommand.php`

**Current:** `php pagekit migrate` only runs `PackageScripts::update()`.

**Change:** Run Doctrine Migrations first, then scripts. Report results for both.

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    // 1. Doctrine Migrations
    if ($this->container->has('migration')) {
        $result = $this->container->get('migration')->migrate();
        if ($result['success'] ?? false) {
            $this->line(sprintf('Doctrine Migrations: %d executed.', $result['executed'] ?? 0));
        } else {
            $this->error('Doctrine Migration failed: ' . ($result['error'] ?? 'Unknown'));
            return Command::FAILURE;
        }
    }

    // 2. Scripts
    $scripts = new PackageScripts($this->container->path() . '/app/system/scripts.php', $this->container);
    if ($scripts->hasUpdates()) {
        $scripts->update();
        $this->line('Script updates applied.');
    }

    // 3. Bump version
    $this->container->get('config')('system')->set('version', $this->container->get('version'));
    $this->line('System is up to date.');

    return Command::SUCCESS;
}
```

Add `declare(strict_types=1)`.

---

## 4. STANDARDIZE EXTENSION LIFECYCLE

### 4.1 `PackageManager::enable()` — auto-migrate

**File:** `app/installer/src/Package/PackageManager.php`

In `enable()` (~line 116–206), after loading the module but **before** running `scripts->update()`, check if the extension has Doctrine Migrations and run them:

```php
// After module is loaded, before scripts
if ($app->has('migration') && is_dir($modulePath . '/src/Migrations')) {
    $result = $app->get('migration')->migrateExtension(
        $module->get('namespace') . '\\Migrations',
        $modulePath . '/src/Migrations'
    );
    if (!($result['success'] ?? false)) {
        throw new \RuntimeException("Migration failed for {$name}: " . ($result['error'] ?? ''));
    }
}
```

### 4.2 `PackageManager::uninstall()` — auto-rollback

In `uninstall()` (~line 84–110), before removing files, rollback the extension's migrations:

```php
if ($app->has('migration') && is_dir($modulePath . '/src/Migrations')) {
    $app->get('migration')->rollbackExtension(
        $module->get('namespace') . '\\Migrations',
        $modulePath . '/src/Migrations',
        '0'
    );
}
```

### 4.3 Document extension pattern

Extensions only need `scripts.php` for **non-SQL hooks** (config initialization, cache clearing, event registration). All schema work goes in `src/Migrations/`. The Blog extension is already a reference implementation for this pattern.

---

## 5. HARDEN MIGRATIONSERVICE

**File:** `app/modules/migration/src/MigrationService.php`

### 5.1 Fix `migrate()` return value handling

**Current (~line 143–146):** `is_array($result)` is used to determine success, but the actual return type from `$migrator->migrate()` may vary. Same pattern in `rollback()`, `migrateExtension()`, `rollbackExtension()`.

**Fix:** Check the actual Doctrine Migrations API return type. `$migrator->migrate()` returns `array<string, MigrationResult>`. A non-empty array means migrations were executed. An exception means failure. Align the return structure:

```php
$results = $migrator->migrate($plan);
return [
    'success' => true,
    'executed' => count($results),
    'migrations' => array_keys($results),
];
```

### 5.2 Status API for pending migrations

`status()` already exists and returns `pending` count. Verify it works correctly for the login check integration (Section 3.1). The method should reliably return `['pending' => int]` even when the migrations table doesn't exist yet.

### 5.3 Delete dead code

- Remove `getConfigPath()` (~line 80–83) — unused private method.

### 5.4 Rollback isolation

Verify that `rollbackExtension()` only rolls back migrations in the **extension's own namespace**, not system migrations. The current implementation uses `ConfigurationArray` with extension-specific paths — confirm this is correctly scoped.

---

## 6. AUDIT FINDINGS — LEGACY CLEANUP

### 6.1 `DatabaseHandler::createTable()` — remove deprecated runtime DDL

**File:** `app/modules/auth/src/Handler/DatabaseHandler.php` (~line 130–146)

This method is marked `@deprecated to be removed in Pagekit 1.0` but is still called from `write()` (~line 86). The auth table `@system_auth` is already defined in the core migration `Version20251023061532`.

**Fix:**
- Remove `createTable()` method entirely
- Remove the `createTable()` call from `write()`
- Verify the core migration creates the `@system_auth` table with matching schema (column names, types, constraints)
- If schema differs between migration and `createTable()`, align the migration

### 6.2 Blog migration naming

**Current:** `Version001_CreateBlogTables` in `packages/pagekit/blog/src/Migrations/2025/`

**Fix:** Rename to timestamp format matching core: `Version20251023HHMMSS_CreateBlogTables` (use the original commit date). Update the Doctrine Migrations version tracking table if needed (or handle via `MigrationService` which may auto-detect).

### 6.3 `MigrationServiceTest` — write real tests

**File:** `tests/unit/Migration/MigrationServiceTest.php`

All tests are currently **skipped**. Write actual tests:
- `testMigrateRunsPendingMigrations()`
- `testRollbackRevertsLastMigration()`
- `testStatusReturnsPendingCount()`
- `testMigrateExtensionRunsExtensionMigrations()`
- `testRollbackExtensionOnlyAffectsExtensionNamespace()`

Use SQLite in-memory for test isolation.

---

## 7. MARKETPLACE FOUNDATION

### 7.1 Clean `PackageManager` API

Ensure these public methods exist with proper signatures:

```php
public function install(array $packages, bool $packagist = false): void
public function uninstall(string|array $packages): void
public function enable(string|array $packages): void
public function disable(string|array $packages): void
```

`update()` is currently not a public method — evaluate if it should be (for marketplace "update extension" flow). If added:

```php
public function update(string|array $packages): void
```

### 7.2 Type all parameters

Currently several `PackageManager` parameters are untyped (`$uninstall`, `$packages`). Add `string|array` union types and validate input.

---

## 8. SUCCESS CRITERIA

- [ ] Login check (`system/index.php`) checks pending Doctrine Migrations before bumping version
- [ ] Update wizard (`MigrationController`) runs `MigrationService::migrate()` before scripts
- [ ] CLI `php pagekit migrate` runs Doctrine Migrations + scripts in one command
- [ ] No silent version bumping without migration check
- [ ] `PackageManager::enable()` auto-migrates extensions with `src/Migrations/`
- [ ] `PackageManager::uninstall()` auto-rolls back extension migrations
- [ ] `MigrationService`: fixed return value handling, dead code removed
- [ ] `DatabaseHandler::createTable()` deleted; auth table comes from migration only
- [ ] Blog migration renamed to timestamp format
- [ ] `MigrationServiceTest` has real (non-skipped) tests
- [ ] `PackageManager` methods properly typed
- [ ] `./app/vendor/bin/phpunit` green
- [ ] `./app/vendor/bin/phpstan analyse` green (no new baseline errors)

---

**End of prompt.**
