# Step 2.0.4b: PackageManager Simplification — Remove Auto-Detection

**Also read:** `.cursor/ROADMAP.md` (5 aggressive rules), `migration-docs/branches/PACKAGE_MIGRATION_SYSTEM_REDESIGN.md`.

---

## 1. CONTEXT

### 1.1 Problem — What Step 2.0.4 Got Wrong

Step 2.0.4 unified the core update pipeline correctly (login check, wizard, CLI all run
Doctrine Migrations before scripts). **That part is fine and must NOT be touched.**

However, Step 2.0.4 also introduced **automatic** migration execution and rollback in
`PackageManager`, which violates the original Pagekit design philosophy and creates bugs:

1. **Auto-Detection in `enable()`** — `PackageManager::enable()` detects `src/Migrations/`
   directories and auto-runs `migrateExtension()`. This conflicts with extensions that
   ALSO call `migrateExtension()` in their `scripts.php` hooks (e.g., Blog), creating
   redundant double-execution paths.

2. **Auto-Rollback in `uninstall()`** — `PackageManager::uninstall()` auto-rolls back
   extension migrations before file removal. The original Pagekit documentation explicitly
   states: *"Pagekit will not modify the tables you have created, even when your extension
   is disabled or uninstalled. You will have to take care of needed database changes yourself."*
   No major framework (Laravel, Symfony, Drupal) auto-rolls back on uninstall.

3. **Namespace Guessing** — A 4-level fallback (`resolveExtensionMigrationNamespace`)
   tries to guess the extension's migration namespace via module manager, `index.php`
   parsing (`token_get_all` + regex), `composer.json`, or StudlyCaps fallback. This adds
   ~160 lines of fragile code that is only needed because of auto-detection.

4. **God Method** — `enable()` now handles events, install, script updates, namespace
   resolution, migration execution, migration rollback on failure, script enable, version
   setting, and config registration — too many responsibilities in one try/catch.

### 1.2 Original Pagekit Design (from official docs)

The original Pagekit extension lifecycle is **explicit** — extensions control their own
database through `scripts.php` hooks:

```php
return [
    'install'   => function ($app) {},  // After installation
    'uninstall' => function ($app) {},  // Before uninstallation
    'enable'    => function ($app) {},  // After activation
    'disable'   => function ($app) {},  // Before deactivation
    'updates'   => [
        '0.5.0' => function ($app) {},  // Version-gated updates
        '0.9.0' => function ($app) {},
    ],
];
```

This is the correct pattern. The system provides tools (`MigrationService`), but extensions
decide when and how to use them.

### 1.3 Goal

Remove auto-detection, auto-migration, and auto-rollback from `PackageManager`. Extensions
call `MigrationService::migrateExtension()` explicitly in their `scripts.php` hooks.
The core update pipeline (login check, wizard, CLI) remains unchanged.

### 1.4 Why This Is Not Repetitive

Doctrine Migrations are **idempotent** — calling `migrateExtension()` only executes
migrations that haven't run yet. An extension needs exactly TWO calls in `scripts.php`:

```php
return [
    'install' => function ($app) {
        // Creates all tables on fresh install
        $app->get('migration')->migrateExtension(
            'Vendor\\Ext\\Migrations', __DIR__ . '/src/Migrations'
        );
    },
    'enable' => function ($app) {
        // Runs only NEW migrations after extension update (idempotent)
        $app->get('migration')->migrateExtension(
            'Vendor\\Ext\\Migrations', __DIR__ . '/src/Migrations'
        );
    },
];
```

No per-version `updates` entries needed for schema changes. Doctrine tracks execution
state. The `updates` array is only needed for DATA migrations (transforming existing
rows, config changes) that can't be expressed as Doctrine Migrations.

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

## 3. DO NOT TOUCH (Preserve These)

These parts of Step 2.0.4 are **correct** and must remain as-is:

- **`MigrationService`** — All public methods (`migrate()`, `rollback()`, `migrateExtension()`,
  `rollbackExtension()`, `getExtensionCurrentVersion()`, `status()`, `generate()`).
  These are the tools extensions use explicitly.
- **Login check** (`app/system/index.php` ~line 123–136) — Checks pending Doctrine Migrations
  + scripts before allowing silent version bump.
- **Update wizard** (`MigrationController`) — Runs `MigrationService::migrate()` before
  `scripts->update()`.
- **CLI** (`MigrationCommand`) — Runs Doctrine Migrations + scripts in correct order.
- **`MigrationServiceTest`** — 12 real tests covering core and extension migration/rollback.
- **`DatabaseHandler::createTable()` removal** — Correct; schema comes from migrations.
- **Blog migration timestamp rename** — `Version20251023070000_CreateBlogTables` is correct.

---

## 4. REMOVE AUTO-DETECTION FROM `PackageManager::enable()`

**File:** `app/installer/src/Package/PackageManager.php`

### 4.1 Remove migration auto-execution from `enable()`

In `enable()` (~line 193–226), remove the entire block that:
1. Checks `is_dir($packagePath . '/src/Migrations')`
2. Calls `resolveExtensionMigrationNamespace()`
3. Calls `getExtensionCurrentVersion()`
4. Calls `migrateExtension()`
5. Stores `$appliedMigration` for rollback

**Delete this block** (approximately lines 193–226):
```php
// DELETE: Auto-migration detection block
$packagePath = $package->get('path');
if ($packagePath !== null
    && is_dir($packagePath . '/src/Migrations')
    && $this->app->has('migration')
) {
    // ... all the namespace resolution, migration execution, etc.
}
```

### 4.2 Remove migration rollback from `enable()` catch block

In the `catch (\Throwable $e)` block (~line 245–270), remove the section that
rolls back `$appliedMigration`. Keep the `rollbackEnable()` call for config state
restoration — that is correct.

**Delete this block:**
```php
// DELETE: Migration rollback in catch
if ($appliedMigration !== null && $this->app->has('migration')) {
    try {
        // ... rollback logic
    } catch (\Throwable $rollbackError) {
        // ...
    }
}
```

Also remove the `$appliedMigration` variable declaration at the top of the foreach.

### 4.3 Result

After this change, `enable()` should do exactly:
1. Find previous package config
2. Fire `package.enable` event
3. `doInstall()` if no current version
4. Run `scripts->update()` if version-gated updates exist
5. Run `scripts->enable()`
6. Set version in config
7. Register as extension or theme
8. On failure: `rollbackEnable()` for config state only

This matches the original Pagekit design but with typed parameters.

---

## 5. REMOVE AUTO-ROLLBACK FROM `PackageManager::uninstall()`

**File:** `app/installer/src/Package/PackageManager.php`

In `uninstall()` (~line 94–129), remove the entire block that:
1. Checks `is_dir($packagePath . '/src/Migrations')`
2. Calls `resolveExtensionMigrationNamespace()`
3. Calls `rollbackExtension('0')`

**Delete this block** (approximately lines 94–129):
```php
// DELETE: Auto-rollback block
$packagePath = $package->get('path');
if ($packagePath !== null
    && is_dir($packagePath . '/src/Migrations')
    && $this->app->has('migration')
) {
    try {
        $migrationNamespace = $this->resolveExtensionMigrationNamespace($package);
        // ... rollback logic
    } catch (\Throwable $e) {
        // ... logging
    }
}
```

Extensions that want to clean up their tables do so in their `scripts.php` `uninstall`
hook — this is the original Pagekit pattern and the extension developer's explicit choice.

---

## 6. DELETE NAMESPACE RESOLUTION CODE

**File:** `app/installer/src/Package/PackageManager.php`

Delete these methods entirely — they only existed to support auto-detection:

1. `resolveExtensionMigrationNamespace()` (~line 379–425) — 4-level namespace guesser
2. `parseAutoloadFromIndexFile()` (~line 436–462) — token_get_all + regex parser
3. `extractBracketBody()` (~line 470–506) — bracket matching helper
4. `unescapePhpString()` (~line 514–517) — PHP string unescaping
5. `stripPhpComments()` (~line 525–537) — comment stripping helper

This removes approximately **160 lines** of fragile code.

---

## 7. DELETE `PackageManagerNamespaceTest`

**File:** `tests/Unit/Installer/PackageManagerNamespaceTest.php`

Delete this entire test file. It tests `resolveExtensionMigrationNamespace()` and its
helper methods which no longer exist. All 21 tests in this file are for deleted code.

---

## 8. UPDATE BLOG `scripts.php`

**File:** `packages/pagekit/blog/scripts.php`

The Blog extension is the reference implementation. Update it to use the explicit pattern:

```php
<?php

/**
 * Blog Extension Installation & Update Scripts
 *
 * Architecture:
 * - Database schema: Handled by Doctrine Migrations (src/Migrations/)
 * - Config initialization: Handled here (configurable preferences)
 * - The extension controls its own migration lifecycle explicitly.
 *   PackageManager does NOT auto-detect or auto-run migrations.
 */

return [

    'install' => function ($app) {
        $app->get('migration')->migrateExtension(
            'Pagekit\\Blog\\Migrations',
            __DIR__ . '/src/Migrations'
        );
    },

    'uninstall' => function ($app) {
        // Extension developer's explicit choice to clean up tables.
        // Original Pagekit philosophy: "You will have to take care of
        // needed database changes yourself."
        // $app->get('migration')->rollbackExtension(
        //     'Pagekit\\Blog\\Migrations',
        //     __DIR__ . '/src/Migrations',
        //     '0'
        // );

        // if ($app->has('cache')) {
        //     $app->get('cache')->clear();
        // }
    },

    'enable' => function ($app) {
        // Idempotent: only runs NEW migrations that haven't been executed yet.
        // Handles both first-enable and update scenarios.
        $app->get('migration')->migrateExtension(
            'Pagekit\\Blog\\Migrations',
            __DIR__ . '/src/Migrations'
        );
    },

    'updates' => [
        // Version-gated hooks for DATA migrations only (not schema).
        // Schema changes go in src/Migrations/ as Doctrine Migration classes.
        // Example:
        // '2.1.0' => function ($app) {
        //     $app->get('db')->executeStatement("UPDATE ...");
        // },
    ],

];
```

Key changes from current:
- `install` hook: Simplified, no error wrapping (let it throw naturally)
- `enable` hook: **NEW** — runs migrations on every enable/update (idempotent)
- `uninstall` hook: Kept as explicit developer choice, simplified
- Removed verbose error handling — PHP exceptions propagate naturally

---

## 9. UPDATE `BUGBOT.md`

**File:** `.cursor/BUGBOT.md`

### 9.1 Update "Already resolved" table

Add entry for this step:

```markdown
| Auto-migration/rollback removed from `PackageManager` | PR #XXX (Step 2.0.4b) | Extensions use explicit `scripts.php` hooks per original Pagekit design |
```

### 9.2 Update "Known deferred patterns" table

Remove the `PackageManager::enable()`/`uninstall()` migration integration tests entry
(Issue #156) — these tests are no longer needed since auto-migration was removed.
The `MigrationServiceTest` tests remain relevant for the service itself.

---

## 10. UPDATE BRANCH DOCUMENTATION

After all changes, update or create `migration-docs/branches/PACKAGE_MANAGER_SIMPLIFICATION.md`
following the format of existing branch docs. Document:

- What was removed and why
- Reference to original Pagekit documentation philosophy
- The correct extension lifecycle pattern
- Test results summary

---

## 11. SUCCESS CRITERIA

- [ ] `PackageManager::enable()` does NOT auto-detect `src/Migrations/` directories
- [ ] `PackageManager::enable()` does NOT call `migrateExtension()` automatically
- [ ] `PackageManager::uninstall()` does NOT auto-rollback extension migrations
- [ ] `resolveExtensionMigrationNamespace()` and all helper methods deleted
- [ ] `PackageManagerNamespaceTest.php` deleted
- [ ] Blog `scripts.php` has explicit `migrateExtension()` in `install` AND `enable` hooks
- [ ] Blog `scripts.php` `uninstall` is an explicit developer choice (not auto)
- [ ] Core update pipeline UNCHANGED (login check, wizard, CLI)
- [ ] `MigrationService` public API UNCHANGED
- [ ] `MigrationServiceTest` passes (12 tests)
- [ ] `./app/vendor/bin/phpunit` green
- [ ] `./app/vendor/bin/phpstan analyse` green (no new baseline errors)
- [ ] `php pagekit list` shows all commands
- [ ] BUGBOT.md updated

---

## 12. WHAT THIS STEP DOES NOT DO

- Does NOT change `MigrationService` — it remains the tool extensions call
- Does NOT change the core update pipeline (login, wizard, CLI)
- Does NOT change how Doctrine Migrations work internally
- Does NOT add a marketplace — that remains in Step 5.6
- Does NOT add data export on uninstall — that's a future feature

---

**End of prompt.**
