# Step 2.7.1b — Package Module Boundary

<!-- Branch doc for Roadmap Step 2.7.1b.
     Path: migration-docs/branches/phase-2/step-2-7-1b-package-module-boundary.md -->

**Branch:** `feature/package-module-boundary`
**ROADMAP Step:** 2.7.1b (Package Module Boundary)
**GitHub Issue:** [#287](https://github.com/Shadesman5/pagekit/issues/287)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-09-22 00:39
**Completed:** _TBD_

---

## 🎯 Overview

The `package` module sits beside `installer`. All three boots register `app/package/index.php`. `system` and `installer` require `package`. `PackageModule::main()` registers `extension.failures` when the container names `path.system`, and registers `package`, `manager`, and `systemApi` on every boot. `snapshotter` is registered when the container names both `path.snapshots` and `db`. The registry, the lifecycle contract, `ExtensionFailureStore`, `PackageManager`, the Composer helper, the snapshot engine, and the extensions, themes, and snapshots admin surface live in `Pagekit\Package`. The package manifest holds `snapshots.retention_days`, routes `/system/package` and `/system/snapshot`, permission `system: manage packages`, and menu `system: extensions`, `system: themes`, `system: snapshots`. The marketplace and the update surface stay in `installer`.

---

## ✅ What Changed

### Module skeleton (Checklist Step 1)

Nothing moved. The directory, the class main, and the tooling that has to see the directory are in place so later steps can `git mv` into a module the boots already load.

| File | Change |
|---|---|
| `app/package/index.php` (new) | Manifest `package`: `'main' => Pagekit\Package\PackageModule`, `require` `application`, `migration`, `system/intl`, `system/view`, resource `package:`. No routes, menu, permissions, or config. |
| `app/package/src/PackageModule.php` (new) | Final `PackageModule`. `main()` returns `null` and registers nothing. |
| `app/system/app.php`, `app/console/app.php`, `app/installer/app.php` | Register `app/package/index.php` ahead of `app/installer/index.php`. |
| `app/system/index.php`, `app/installer/index.php` | `'package'` added to `require`, after `migration`. Installer still owns the package services, routes, and admin menu. |
| `composer.json` | PSR-4 `Pagekit\Package\` → `app/package/src`, beside `Pagekit\Installer\`. |
| `phpstan.neon` | Analyse path `app/package`. |
| `phpunit.xml.dist` | Coverage include `app/package`. No `phpunit.xml` beside the dist file. |
| `app/modules/application/src/Tests/bootstrap.php` | Deleted the stale `Pagekit\Package\` → `/app/modules/package/src` mapping. |

#### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageModuleBoundaryTest.php` (new) | Manifest graph (`package` requires neither `installer` nor `system`; both of those require `package`), each boot file registers the manifest once, a scan of `app/package` and `app/installer/src` reports `Pagekit\System\` only outside the package tree and fails an in-memory fixture that contains such a line, `main()` adds no services, Composer / PHPStan / PHPUnit name `app/package`, and the application test bootstrap no longer maps `/app/modules/package/src`. Asserts membership, not list order. Including a manifest binds `$app` first — the system manifest's events capture it. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done after one retry (first Tester FAIL: including `app/system/index.php` tripped `failOnWarning` on unbound `$app`) → Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Registry and lifecycle contract (Checklist Step 2)

`git mv` of the registry and the lifecycle types. Namespace only. The `package` service stays registered in `app/installer/index.php`. `PackageManager` stays in `app/installer/src/Package/`.

| File | Change |
|---|---|
| `app/package/src/Package.php`, `PackageInterface.php`, `PackageFactory.php` | From `app/installer/src/Package/`. Namespace `Pagekit\Package`. |
| `app/package/src/Lifecycle/{LifecycleRunner,MigrationSet,PackageLifecycle,PackageLifecycleInterface}.php` | From `app/installer/src/Package/Lifecycle/`. Namespace `Pagekit\Package\Lifecycle`. |
| `app/installer/index.php` | Import is `Pagekit\Package\PackageFactory`. The `package` registration stays in this manifest. |
| `app/installer/src/Package/PackageManager.php` | `use` of `PackageInterface`, `Lifecycle\LifecycleRunner`, `Lifecycle\MigrationSet` only. |
| `app/installer/src/Installer.php` | `LifecycleRunner` import. |
| `app/installer/src/Controller/PackageController.php` | `PackageFactory` and `PackageInterface` imports, re-sorted. |
| `app/installer/src/Controller/MarketplaceController.php` | `PackageFactory` import. The action type-hints the factory. |
| `app/installer/src/Package/Snapshot/PackageSnapshotter.php` | `PackageInterface` import. |
| `app/system/index.php`, `app/system/src/Controller/MigrationController.php`, `app/console/src/Commands/MigrationCommand.php` | `LifecycleRunner` import. |
| `app/system/scripts.php` | `PackageLifecycle` import. The system lifecycle file is this script. |
| `app/system/modules/site/src/PackageNodeTypes.php` | `PackageInterface` import. |
| `packages/pagekit/blog/src/BlogLifecycle.php` | `PackageLifecycle` and `MigrationSet` imports. |
| `phpstan-baseline.neon` | `PackageFactory.php` finding moved to `app/package/src/PackageFactory.php`, path-sorted between `app/modules/view/src/View.php` and `app/system/app.php`. Message, identifier, and count unchanged. The installer path is gone. |

#### Existing tests (follow the move)

Imports re-pointed to `Pagekit\Package\…` and re-sorted (`Pagekit\Package\` after `Pagekit\Module\` / `Pagekit\Migration\`, before `Pagekit\Site\` / `Pagekit\System\` / `Pagekit\Tests\`). Generated lifecycle sources inside the tests name the new class. No assertion changed. `tests/Unit/Package/bootstrap.php` still declares its `__()` stub in `Pagekit\Installer\Package`, where `PackageManager` still lives.

| File | Change |
|---|---|
| `tests/Unit/Package/{LifecycleRunnerTest,PackageFactoryTest,PackageFailureRecordTest,PackageHookBarrierTest,PackageHookWarningTest,PackageManagerMigrationTest,PackageSchemaTest,PackageSnapshotGateTest,PackageTreeRemovalTest,ShippedLifecycleTest}.php` | Imports (and inlined `use` lines in generated lifecycles). |
| `tests/Unit/Snapshot/{PackageSnapshotterTest,RemovalPromiseTest,SnapshotControllerTest,SnapshotPurgeTest,SnapshotRestoreTest,SnapshotRetentionTest,SnapshotServiceWiringTest}.php` | Imports. |
| `tests/Unit/Console/{MigrationCommandTest,UninstallCommandTest}.php`, `tests/Unit/System/MigrationControllerTest.php` | Imports (and inlined `use` lines). |
| `app/system/modules/site/src/Tests/{PackageLifecycleWiringTest,PackageNodeTypesTest}.php` | `Package` import. |

#### Tests (Checklist Step 2)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageModuleBoundaryTest.php` | Retired names `Pagekit\Installer\Package\{Package,PackageInterface,PackageFactory}` and `…\Lifecycle\` are absent under `app/`, `packages/`, and `tests/` (vendor and `node_modules` skipped). The pattern stops at those three classes and the lifecycle segment, so `PackageManager` and `TablePrefix` stay legal. A double-quoted fixture proves the detector reports the retired names and ignores `PackageManager`, the new namespace, and `TablePrefix`. The seven files exist under `app/package/` and are gone from `app/installer/`. `PackageManager`'s `Pagekit\Package\` imports are exactly the three above. `MarketplaceController` imports `PackageFactory`. The `__()` stub's namespace is the manager's, and the bootstrap does not declare `namespace Pagekit\Package`. The baseline entry is the one `PackageFactory` finding, neighbors path-sorted around it. |
| `tests/Unit/Package/ShippedLifecycleTest.php` | Blog lifecycle is `Pagekit\Blog\BlogLifecycle` and an instance of `Pagekit\Package\Lifecycle\PackageLifecycle`; the system script's lifecycle is an instance of the same contract. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done after one retry (Verifier FAIL: `PackageModuleBoundaryTest` baseline assertion expected a literal trailing `$`, which in the neon entry is the pattern's end anchor inside `#^…$#`) → Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Failure record (Checklist Step 3)

`git mv` of the store. Namespace only. The `extension.failures` registration, its `path.system` guard, and its comment moved from `SystemModule::main()` into `PackageModule::main()`. The guard is unchanged; its reach is not. `public/index.php` sets `path.system` on every boot and `installer` requires `package`, so the wizard now resolves the id and `PackageManager::$failures` is a real store there. The id exists exactly where `path.system` is set.

| File | Change |
|---|---|
| `app/package/src/Extension/ExtensionFailureStore.php` | From `app/system/src/Extension/`. Namespace `Pagekit\Package\Extension`. |
| `app/package/src/PackageModule.php` | Registers `extension.failures` under `if ($app->has('path.system'))`, comment included. |
| `app/system/src/SystemModule.php` | Registration and store import dropped. The `has()` read, the loader, and the "container that names no place" comment stay. |
| `app/system/src/Extension/ExtensionLoader.php` | `use Pagekit\Package\Extension\ExtensionFailureStore`. The class left this namespace. |
| `app/system/index.php` | Same import. |
| `app/installer/src/Package/PackageManager.php` | Same import. The comment now says the record is kept only where the container names a directory. |

#### Tests (Checklist Step 3)

| File | Change |
|---|---|
| `tests/Unit/Extension/ExtensionAutoDisableTest.php` | Import. `boot()` runs `PackageModule::main()` on every call, including the one that passes no `systemPath`. |
| `tests/Unit/Extension/{ExtensionFailureNoticeTest,ExtensionFailureStoreTest,ExtensionLoaderTest}.php` | Import. |
| `tests/Unit/Package/PackageHookBarrierTest.php` | Import. The `$path` docblock still calls the directory one of the system module. |
| `tests/Unit/Package/PackageFailureRecordTest.php` | Import. `testAFailureTheBarrierRecordedIsWhatTheManagerReportsAndClears`: the barrier writes through the registered service, `getFailedModules()` reports it, `enable()` clears it. The `$path` docblock and `testAnEnvironmentThatKeepsNoRecordReportsNoFailures` still say the installer does not load the system module. |
| `tests/Unit/Package/PackageModuleBoundaryTest.php` | The store file is under `app/package/` and gone from `app/system/`. The `Pagekit\System\` walk asserts both `app/package` and `app/installer/src`, with no filter. The manager's `Pagekit\Package\` imports are the previous three plus `Extension\ExtensionFailureStore` (method renamed). `extension.failures` is registered iff `path.system`, and the store writes under that directory. `SystemModule::main()` does not register the id even when the path is set. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan FAIL once (`PackageModuleBoundaryTest` import allow-list) then PASS after a production retry; test-writer PASS; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Manager, Composer helper and snapshot engine (Checklist Step 4)

`git mv` of the manager, the Composer helper with its two internals, and the snapshot engine. Namespace only, apart from the two `__DIR__` fallbacks in `PackageManager`, which lost one `..` so they still resolve to `app/`. The four registrations and the `snapshots.retention_days` default left the installer manifest. `app/installer/src/Package/` and `app/installer/src/Helper/` are gone. Routes and the admin surface stay where they are.

| File | Change |
|---|---|
| `app/package/src/PackageManager.php` | From `app/installer/src/Package/`. Namespace `Pagekit\Package`. Both no-container fallbacks are `realpath(__DIR__ . '/../..')`. |
| `app/package/src/Helper/{Composer,Factory,InstallerIO}.php` | From `app/installer/src/Helper/`. Namespace `Pagekit\Package\Helper`. |
| `app/package/src/Snapshot/{DatabaseDumper,DatabaseRestorer,DumpFormat,PackageSnapshotter,RestoreTableNames,ShadowSchema,SnapshotStore}.php` | From `app/installer/src/Package/Snapshot/`. Namespace `Pagekit\Package\Snapshot`. The `{@see \Pagekit\Installer\TablePrefix}` citation in `RestoreTableNames` is unchanged. |
| `app/package/src/PackageModule.php` | Registers `package`, `manager`, and `systemApi` on every boot. `snapshotter` only when the container has `path.snapshots` and `db`. The window is `SnapshotStore::retentionDays($this->config('snapshots.retention_days'))`. |
| `app/package/index.php` | `config.snapshots.retention_days` is `SnapshotStore::DEFAULT_RETENTION_DAYS`, comment included. |
| `app/installer/index.php` | The four registrations, the `Package`/`Snapshot` imports, and the `snapshots` config are gone. The `enabled`-gated block, `release_channel`, routes, and menu stay. |
| `app/installer/src/Installer.php` | `PackageManager` import. |
| `app/installer/src/Controller/PackageController.php` | `PackageManager` and `PackageSnapshotter` imports. |
| `app/installer/src/Controller/SnapshotController.php` | `PackageSnapshotter` and `SnapshotStore` imports. `retentionDays()` reads `get('package')`. |
| `app/console/src/Commands/{BuildCommand,UpdateCommand}.php` | `Helper\Composer` import. |
| `app/console/src/Commands/{InstallCommand,UninstallCommand}.php` | `PackageManager` import. |
| `phpstan-baseline.neon` | The six `Composer.php` findings moved to `app/package/src/Helper/Composer.php`, path-sorted between `View.php` and `PackageFactory.php`. Message, identifier, and count unchanged. The `app/installer/index.php` entry stays the `$this` closure at count 1. `app/package/index.php` has none. |

#### Existing tests (follow the move)

Imports re-pointed to `Pagekit\Package\…`. `RestoreTableNamesTest` changes only the `RestoreTableNames` import; `use Pagekit\Installer\TablePrefix;` and the `TablePrefix::refusal()` assertions stay.

| File | Change |
|---|---|
| `tests/Unit/Package/bootstrap.php` | The `__()` stub's namespace is `Pagekit\Package`. |
| `tests/Unit/Package/{PackageFailureRecordTest,PackageHookBarrierTest,PackageHookWarningTest,PackageInstallConstraintTest,PackageManagerMigrationTest,PackageRegistryWriteTest,PackageSchemaTest,PackageSnapshotGateTest,PackageTreeRemovalTest,ShippedLifecycleTest}.php` | Imports. The comments that still place the failure record in the system module were left. |
| `tests/Unit/Snapshot/{ConnectionThatIsRestoredTwiceAtOnce,DatabaseDumperTest,DatabaseRestorerTest,DumpFormatTest,PackageSnapshotterTest,RestoreTableNamesTest,ShadowSchemaTest,SnapshotPurgeTest,SnapshotRestoreTest,SnapshotRetentionTest,SnapshotStoreTest}.php` | Imports. |
| `tests/Unit/Snapshot/SnapshotControllerTest.php` | Imports. The retention fixture's module name is `package`. |
| `tests/Unit/Snapshot/SnapshotServiceWiringTest.php` | Boots `PackageModule` on the case's own config. A window of 7 keeps a snapshot for 7 days. An empty config, a `snapshots` section with no key, a section that is not an array, and a value no number can be read from all leave the store on `SnapshotStore::DEFAULT_RETENTION_DAYS`. |
| `tests/Unit/Snapshot/RemovalPromiseTest.php` | Boots `PackageModule` with `config` `[]`. The manifest `main` is `PackageModule::class`. `installerPath()` reads the page scripts from `app/installer`. |
| `tests/Unit/Console/UninstallCommandTest.php` | Imports. |

#### Tests (Checklist Step 4)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageModuleBoundaryTest.php` | `Pagekit\Installer\Package\` and `Pagekit\Installer\Helper\` are absent under `app/`, `packages/`, and `tests/`. The package pattern is the whole segment, closed by a semicolon or a backslash, so `TablePrefix` and the `{@see}` citation stay legal. Fixtures prove each detector reports a line in the retired segment. `RestoreTableNamesTest` imports both `Pagekit\Package\Snapshot\RestoreTableNames` and `Pagekit\Installer\TablePrefix`, and still calls `TablePrefix::refusal()` three times. The eleven moved files exist under `app/package/` and both installer directories are gone. A bare container gains `package`, `manager`, and `systemApi`. The manifest assertion includes `config`. The per-file import allow-list on the manager was deleted. The six Composer baseline findings sit at the new path; the installer-index entry is unchanged; the package manifest has none. `systemApi` is set in `PackageModule` and in `DashboardModule`. |

Gates: Verifier (production) FAIL once (retention config merge; `RETIRED_REGISTRY` comment) then PASS; Tester PHPUnit+PHPStan PASS; test-writer PASS; Verifier (test files) FAIL once (retired-namespace patterns; `SnapshotServiceWiringTest` docblock) then PASS; Tester PHPUnit+PHPStan PASS.

### Admin surface (Checklist Step 5)

`git mv` of both controllers, the three pages, their scripts, the three `package-*` components, and `app/lib`. Namespace and locator only, apart from the clients that imported the old tree. Routes `/system/package` and `/system/snapshot`, permission `system: manage packages`, and menu `system: extensions|themes|snapshots` left the installer manifest. The marketplace menu still names that permission as access. The `@installer` alias is gone.

| File | Change |
|---|---|
| `app/package/src/Controller/{PackageController,SnapshotController}.php` | From `app/installer/src/Controller/`. Namespace `Pagekit\Package\Controller`. View names are `package:views/extensions.php`, `package:views/themes.php`, and `package:views/snapshots.php`. The `#[Access('system: manage packages', admin: true)]` gate is unchanged. |
| `app/package/views/{extensions,themes,snapshots}.php` | From `app/installer/views/`. Each `$view->script()` names `package:app/bundle/<name>.js`. |
| `app/package/app/views/{extensions,snapshots,themes}.js`, `app/package/app/components/{package-details.vue,package-manager.js,package-upload.vue}`, `app/package/app/lib/{install.vue,output.js,package.js,uninstall.vue,update.vue,version.js}` | From `app/installer/app/`. Content unchanged. |
| `app/package/index.php` | Declares the two routes (controller FQCNs above), the permission, and the three menu entries. |
| `app/installer/index.php` | Those routes, that permission, and those three menu entries are gone. Marketplace and update stay, and the marketplace menu still has `'access' => 'system: manage packages'`. |
| `app/installer/app/components/marketplace.vue` | `import Package from '@package/app/lib/package'`. |
| `app/installer/app/views/update.js`, `app/system/modules/dashboard/app/views/index.js` | `import Version from '@package/app/lib/version'`. The dashboard was the `@installer` importer. |
| `scripts/bundle-entries.mjs` | Alias `@package` → `app/package`. `@installer` deleted. Group `app/package` builds `extensions`, `snapshots`, and `themes`. The installer group keeps `installer`, `marketplace`, and `update`. |
| `app/console/src/Commands/ExtensionTranslateCommand.php` | The `system` extraction walks `app/package` beside `app/installer`. |
| `phpstan-baseline.neon` | The three `$view` findings moved to `app/package/views/{extensions,snapshots,themes}.php`, path-sorted between `PackageFactory.php` and `app/system/app.php`. Message, identifier, and count unchanged. The installer view paths are gone. |

#### Existing tests (follow the move)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageModuleBoundaryTest.php` | The canonical key list gained `routes`, `permissions`, and `menu`. |
| `tests/Unit/Package/PackageFailureRecordTest.php` | `PackageController` import. The two comments that place the record in the system module were left. |
| `tests/Unit/Package/PackageHookWarningTest.php` | `PackageController` import. `package.js` and `output.js` are read through `packagePath()`. |
| `tests/Unit/Snapshot/SnapshotControllerTest.php` | `SnapshotController` import. The listing view is `package:views/snapshots.php`, and the file is `app/package/views/snapshots.php`. |
| `tests/Unit/Snapshot/SnapshotAdminSurfaceTest.php` | `SnapshotController` import. The manifest, the `package:` locator, the views, `uninstall.vue`, `snapshots.js`, and bundle group `app/package` are read from `app/package`. |
| `tests/Unit/Snapshot/RemovalPromiseTest.php` | `PackageController` import. `source()` reads `packagePath()`. `installerPath()` is gone. |

#### Tests (Checklist Step 5)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageModuleBoundaryTest.php` | The package manifest owns both routes, the permission, and the three menu entries, and the installer manifest declares none of them. The marketplace menu still consumes the permission. `system: manage packages` is declared once, as a manifest key, in `app/package/index.php`; a fixture distinguishes that key from an access consumer. Both controllers are `Pagekit\Package\Controller` and their only `#[Access]` is that permission with `admin: true`. Nothing under `app/package/` names `installer:`, `@installer`, or `app/installer`; a fixture reports each of those and ignores `package:`, `TablePrefix`, the `{@see}` citation, and `@package`. The seventeen moved files exist under `app/package/` and are gone from `app/installer/`. The three views register `package:app/bundle/<name>.js`. The bundle manifest aliases `@package`, contains no `@installer`, builds the three pages from `app/package`, and keeps `installer`, `marketplace`, and `update` on the installer group. The marketplace, the update page, and the dashboard import `@package`. |
| `tests/Unit/Console/ExtensionTranslateCommandTest.php` (new) | The `system` catalogue walk includes the three pages (found by the bundle each registers) and the scripts that call `$trans(`, and still includes the wizard. A `pagekit/blog` walk stays inside that package. |

Gates: Verifier (production) PASS; Tester PASS (PHPUnit + PHPStan, `pnpm build`, `pnpm lint`, `pnpm exec prettier --check .`, authenticated smoke of the extensions, themes, and snapshots pages). Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Tooling and installer cleanup (Checklist Step 6)

The no-change list was re-checked and left. `composer.json`, `phpstan.neon`, `phpunit.xml.dist`, and `scripts/bundle-entries.mjs` already name the directory. No test files. test-writer skipped.

| File | Change |
|---|---|
| `scripts/publish.mjs` | `trees()` includes `app/package`, between `app/installer` and `app/system`. |
| `.cursor/BUGBOT.md` | §6 path list includes `app/package/src/`. |
| `app/installer/app/lib/` | Empty directory removed. The removal has no diff. |

Gates: Verifier (production) FAIL once (empty `app/installer/app/lib` left on disk) then PASS after removal; Tester PASS (PHPUnit + PHPStan, fresh SQLite install, `/installer` without `config.php`, `pnpm build`, `pnpm lint`, `pnpm exec prettier --check .`); test-writer skipped.

---

## 🧠 Key Decisions (Rationale)

- **List position is not the contract.** `app/package/index.php` is registered ahead of `app/installer/index.php`, and `'package'` sits after `'migration'` in both `require` arrays. `ModuleManager::register()` only discovers manifests; `resolveModules()` walks requirements by name. `PackageModuleBoundaryTest` asserts those arrays contain the entry, never an index or a relative order.
- **The manager's compile-time tie is five sub-namespace names.** `PackageManager` lives in `Pagekit\Package`, so `PackageInterface` needs no import. What it imports from this module is `Extension\ExtensionFailureStore`, `Helper\Composer`, `Lifecycle\LifecycleRunner`, `Lifecycle\MigrationSet`, and `Snapshot\PackageSnapshotter`. The per-file allow-list that pinned the old four was deleted: inside the module that list only restates the manager's own imports. The tree-wide scan is what still has to hold.
- **The marketplace action type-hints the factory.** `MarketplaceController` imports `Pagekit\Package\PackageFactory`. The boundary test asserts that import.
- **Baseline entries stay path-sorted.** The `PackageFactory` finding kept its message, identifier, and count, and still sits ahead of `app/system/app.php`. The six `Composer.php` findings moved with the file and sit between `View.php` and that entry. The three `$view` findings on the extensions, themes, and snapshots views moved with those files and sit between `PackageFactory.php` and `app/system/app.php`. The installer manifest's `$this` closure stays at count 1. The package manifest added none.
- **The translation stub moved with the manager.** `tests/Unit/Package/bootstrap.php` declares `__()` in `Pagekit\Package`, the namespace an unqualified `__()` resolves in before the global helper.
- **The failure id follows `path.system`.** The guard moved verbatim. It is not "wherever the system module ran": every boot names the path, so the wizard resolves `extension.failures`. Both branches are driven off that path alone. `ExtensionAutoDisableTest::boot()` runs `PackageModule` even when no directory is passed — a conditional boot would make the missing-id assertion pass because the module never ran.
- **Two comments still place the record in the system module.** `PackageFailureRecordTest` (the `$path` docblock and `testAnEnvironmentThatKeepsNoRecordReportsNoFailures`) and `PackageHookBarrierTest` (the `$path` docblock) still say the installer does not load that module. Step 4 re-pointed their imports and left the sentences. Step 5 re-pointed `PackageController` in `PackageFailureRecordTest` and left them. They are false.
- **The system-namespace walk asserts both roots.** It used to keep only `app/package/` hits, because the installer tree imported the store. That import is gone, so an empty result over `app/package` and `app/installer/src` is the assertion.
- **The retention window is read through `Module::config()`.** `PackageModule` passes `$this->config('snapshots.retention_days')` to `SnapshotStore::retentionDays()`. A nested subscript on `Module::$config` is an offset on `mixed` and fails when the section is not an array; the accessor returns the default there, and it is the same lookup `SnapshotController::retentionDays()` does. An absent section, an absent key, a section that is not an array, and a value no number can be read from all resolve to `SnapshotStore::DEFAULT_RETENTION_DAYS`.
- **A boot passes the installation's own config.** `SnapshotServiceWiringTest` and `RemovalPromiseTest` hand `PackageModule` the case's config (`[]` where the case only asks whether `snapshotter` exists). Array union with the shipped manifest is not recursive: the shipped `snapshots` section would win whole and a configured window would never arrive. The framework merge is the same shape — `app/modules/config/index.php` puts stored values over the manifest with a top-level `array_replace`, so a stored section replaces the shipped one. The package module has no `enabled` gate. `RemovalPromiseTest` kept `installerPath()` beside `packagePath()` until Step 5, because the three scripts it reads had not moved.
- **The retired-namespace scan is the whole `Installer\Package\` segment.** The class alternation that spared `PackageManager` went with the manager. The pattern stops at that segment, and at `Installer\Helper\`, so `RestoreTableNamesTest`'s `TablePrefix` import and the `{@see}` citation in `RestoreTableNames` stay legal. Nothing under `app/`, `packages/`, or `tests/` names either segment.
- **The system catalogue walks both trees.** `extension:translate` for `system` reads `app/package` beside `app/installer`. The command was on neither the scope list nor the no-change list. These pages keep their strings in `app/system/languages`, and leaving the walk until the tooling step would have shipped a catalogue that no longer contains them. Whichever tree holds the extensions, themes, and snapshots pages, that walk returns them. A blog extraction does not.
- **The permission is declared once and shown under `package`.** The permissions screen groups by the declaring module's name, so Manage extensions and themes sits under `package` and Apply system updates stays under `installer`. Roles store the permission name, so nothing stored moves. The marketplace menu still names `system: manage packages` as access. Both moved controllers still gate on that name.
- **`RemovalPromiseTest` dropped `installerPath()`.** Step 4 kept the helper because `package-manager.js`, `uninstall.vue`, and `package.js` had not moved. `source()` now reads `packagePath()`. The file was not on this step's list.
- **The manifest-key list is not the surface.** The boundary test's edit with the move only added `routes`, `permissions`, and `menu` to the canonical keys. What those keys hold, and that the installer manifest declares none of them, is the coverage added after.
- **`trees()` copies scripts; Vite serves the three pages.** `'app/package'` sits between `app/installer` and `app/system`. Both consumers `flatMap` the list. The entry copies the `.js` under `app/package/app/{components,lib,views}` into the webroot, and `pnpm watch` watches `app/package/app`. The pages are Vite output at `package:app/bundle/<name>.js`, written straight into `public/`.
- **The no-change list stayed no-change.** `check-version-ssot.php` still reads `app/installer/requirements.php`. `scripts/styles.mjs` and the CSS ignore still name the wizard theme; `app/package` ships no LESS. `.php-cs-fixer.php` names `app/installer/config.php` only in the comment on its anchored `notPath`. `AGENTS.md` still names `app/installer/app.php` as a boot file. `infection.json.dist` still scopes mutation to the auth and user sources. `tests/Unit/Installer/*` still imports only `Installer`, `TablePrefix`, and `StorageLink`.
- **Git does not record an empty directory.** Step 5's `git mv` left `app/installer/app/lib/` on disk. Removing it produces no diff, and checking out an earlier commit leaves it behind again. Only `servedDirs()` in `scripts/publish.mjs` reads it, so a leftover is still a watched directory. The installer inventory is the working tree: `app/installer` carries no empty directory.

---

## 💥 Breaking Changes (Extensions)

The registry and lifecycle contract left `Pagekit\Installer\Package`. An extension that implements the lifecycle, or names `MigrationSet`, `Package`, `PackageInterface`, or `PackageFactory`, uses `Pagekit\Package\…` (`Lifecycle\` for the lifecycle types). The shipped blog lifecycle is that rename. `ExtensionFailureStore` left `Pagekit\System\Extension` for `Pagekit\Package\Extension`. `PackageManager`, `Pagekit\Installer\Helper\{Composer,Factory,InstallerIO}`, and the snapshot types left for `Pagekit\Package\…` (`Helper\` and `Snapshot\`). `PackageController` and `SnapshotController` left `Pagekit\Installer\Controller` for `Pagekit\Package\Controller`. Routes `/system/package` and `/system/snapshot`, permission `system: manage packages`, and the extensions, themes, and snapshots menu entries are declared by `package`. The marketplace and update controllers stay `Pagekit\Installer\…`.

---

## ⚠️ Risks & Rollout Notes

`system` and `installer` fail module resolution if `app/package/index.php` is absent. The wizard resolves `extension.failures`: `path.system` is set on every boot, and `installer` requires `package`, so `PackageManager::$failures` is a real store there. A container that does not set `path.system` still has no id. `snapshotter` is absent unless the container has both `path.snapshots` and `db`. A caller that still names `Pagekit\Installer\Package\`, `Pagekit\Installer\Helper\`, `Pagekit\System\Extension\ExtensionFailureStore`, or `Pagekit\Installer\Controller\{PackageController,SnapshotController}` does not resolve. The permissions screen lists Manage extensions and themes under the package module. A role that already holds `system: manage packages` still holds it. The `@installer` import prefix is gone. A retention window stored on the installer module is not read: stored config is keyed by module name, and the window is the package module's `snapshots.retention_days`. An installation that has not set one keeps `SnapshotStore::DEFAULT_RETENTION_DAYS`. A stored `snapshots` section replaces the shipped one whole.

---

## 🔐 Security & Data Impact

None.

---

## 🛡️ No-Mercy Compliance

The stale `Pagekit\Package\` mapping to a directory that does not exist was deleted, not left beside the new PSR-4 path. The main is the class. No alias. The registry, the lifecycle types, the failure store, the manager, the helper, and the snapshot engine changed namespace at every call site. `PackageManager` does not import `PackageInterface` from its own namespace. `SystemModule` dropped the failure registration; the `has()` read stayed, because a container without `path.system` still has no id. The four service registrations and the snapshot window left the installer manifest; it does not keep a second copy. The dashboard's `systemApi` registration was already there and was left. `__DIR__` was rewritten so the no-container fallback still names `app/`. The admin surface changed namespace and locator at every call site. The installer manifest does not declare the moved routes, the permission, or the three menu entries again. `@installer` was deleted with its last importer. The empty `app/installer/app/lib/` the move left on disk was removed.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: Step 1 — production Verifier PASS; Tester PHPUnit+PHPStan PASS. test-writer: first Tester FAIL (`PackageModuleBoundaryTest` included `app/system/index.php` and tripped `failOnWarning` on unbound `$app`). Retry binds `$app` before the include. Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS. Step 2 — production Verifier PASS; Tester PHPUnit+PHPStan PASS. test-writer: first Verifier FAIL (`PackageModuleBoundaryTest` baseline assertion expected a literal trailing `$`; in the neon entry that `$` is the pattern's end anchor inside `#^…$#`). Retry asserts the stored message. Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS. Step 3 — production Verifier PASS. Tester PHPUnit+PHPStan FAIL once (`PackageModuleBoundaryTest` import allow-list) then PASS after a production retry. test-writer PASS; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS. Step 4 — production Verifier FAIL once (retention config merge; `RETIRED_REGISTRY` comment) then PASS; Tester PHPUnit+PHPStan PASS. test-writer PASS. Verifier (test files) FAIL once (retired-namespace patterns; `SnapshotServiceWiringTest` docblock) then PASS; Tester PHPUnit+PHPStan PASS. Step 5 — production Verifier PASS; Tester PASS (PHPUnit + PHPStan, `pnpm build`, `pnpm lint`, `pnpm exec prettier --check .`, authenticated smoke of the extensions, themes, and snapshots pages). Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS. Step 6 — production Verifier FAIL once (empty `app/installer/app/lib` left on disk) then PASS after removal; Tester PASS (PHPUnit + PHPStan, fresh SQLite install, `/installer` without `config.php`, `pnpm build`, `pnpm lint`, `pnpm exec prettier --check .`); test-writer skipped.

---

## 📋 Phase 1 Audit Closure

_TBD / None_

---

## 👤 Maintainer action

<!-- Human-only follow-ups the maintainer must do (ruleset flips, real Docker/Apache
     verification, secrets, etc.). Not ROADMAP deferrals — those go under Deferred. -->

_TBD / None_

---

## 📚 Deferred / Out-of-Scope

<!-- Future ROADMAP/PHASE work, explicit non-goals, bridges. Do NOT put maintainer
     Manual Work here — that belongs under Maintainer action above. -->

_TBD / None_

---

## 📌 Follow-on (ROADMAP)

_TBD / None_

---

## 🧊 Parked (unplanned)

<!-- Filled by the post-close review after Finalize: what the finished work left unowned,
     one bullet per finding with the ROADMAP step whose area it belongs to. Doc-writer leaves None. -->

_TBD / None_

---

## 🧹 Cleanup

<!-- Removed in passing (deleted files, dropped baseline/ignore entries, dead code). Doc-writer from
     the handover; the post-close review adds what the diff shows and the handover missed. -->

_TBD / None_

---

## 🛡️ Audit

<!-- No-Mercy leftovers of the shipped diff that have no owner (forward-debt tags, added baseline
     entries, ANOMALIES patterns), each with the ROADMAP step that resolves it. Post-close review. -->

_TBD / None_

---

## 🎁 Bonus

<!-- Work delivered beyond the ticket. Doc-writer from the handover; the post-close review adds
     what the diff shows and the handover missed. -->

_TBD / None_

---

## 🔍 Research

<!-- The verified facts behind each DECISION the post-close review raised — symbols, call chain,
     what each exit deletes or adds — so the maintainer can decide without re-reading the tree. -->

_TBD / None_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_7_1b_Package-Module-Boundary_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_1b_Package-Module-Boundary.md`
- Predecessor: Step 2.7.1a — Atomic MySQL Restore (Shadow Cut-over)
- Successor: Step 2.7.1c — Runtime Composer Removal

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
