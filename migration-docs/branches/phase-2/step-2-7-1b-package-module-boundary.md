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

The `package` module sits beside `installer`. All three boots register `app/package/index.php`. `system` and `installer` require `package`. `PackageModule::main()` registers `extension.failures` when the container names `path.system`; the `package` factory registration stays on the installer manifest. The registry (`Package`, `PackageInterface`, `PackageFactory`), the lifecycle contract, and `ExtensionFailureStore` live in `Pagekit\Package`. `PackageManager`, the snapshot engine, routes, and the admin surface still live in `installer`.

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

---

## 🧠 Key Decisions (Rationale)

- **List position is not the contract.** `app/package/index.php` is registered ahead of `app/installer/index.php`, and `'package'` sits after `'migration'` in both `require` arrays. `ModuleManager::register()` only discovers manifests; `resolveModules()` walks requirements by name. `PackageModuleBoundaryTest` asserts those arrays contain the entry, never an index or a relative order.
- **The manager's compile-time tie is four names.** `PackageManager` imports `PackageInterface`, `Lifecycle\LifecycleRunner`, `Lifecycle\MigrationSet`, and `Extension\ExtensionFailureStore`. `Package`, `PackageFactory`, and `PackageLifecycleInterface` never appear in the body — the factory is the container id `package`, parameters are typed on the interface, and the store is the type the optional record is narrowed to. `no_unused_imports` would strip anything else. The boundary test pins those four.
- **The marketplace action type-hints the factory.** `MarketplaceController` imports `Pagekit\Package\PackageFactory`. The boundary test asserts that import.
- **The baseline entry moved in path order.** Same message, identifier, and count. It sits between `View.php` and `app/system/app.php`, not inside the installer block.
- **The translation stub stays with the manager.** `tests/Unit/Package/bootstrap.php` still declares `__()` in `Pagekit\Installer\Package`. Existing tests re-sorted imports and did not change assertions.
- **The failure id follows `path.system`.** The guard moved verbatim. It is not "wherever the system module ran": every boot names the path, so the wizard resolves `extension.failures`. Both branches are driven off that path alone. `ExtensionAutoDisableTest::boot()` runs `PackageModule` even when no directory is passed — a conditional boot would make the missing-id assertion pass because the module never ran.
- **Two comments still place the record in the system module.** `PackageFailureRecordTest` (the `$path` docblock and `testAnEnvironmentThatKeepsNoRecordReportsNoFailures`) and `PackageHookBarrierTest` (the `$path` docblock) still say the installer does not load that module. Only the `PackageManager` comment was in scope to rewrite, so only the imports changed. Those sentences are false as of this step.
- **The system-namespace walk asserts both roots.** It used to keep only `app/package/` hits, because the installer tree imported the store. That import is gone, so an empty result over `app/package` and `app/installer/src` is the assertion.

---

## 💥 Breaking Changes (Extensions)

The registry and lifecycle contract left `Pagekit\Installer\Package`. An extension that implements the lifecycle, or names `MigrationSet`, `Package`, `PackageInterface`, or `PackageFactory`, uses `Pagekit\Package\…` (`Lifecycle\` for the lifecycle types). The shipped blog lifecycle is that rename. `ExtensionFailureStore` left `Pagekit\System\Extension` for `Pagekit\Package\Extension`. `PackageManager`, the snapshot types, routes, and the admin surface are still `Pagekit\Installer\…`.

---

## ⚠️ Risks & Rollout Notes

`system` and `installer` fail module resolution if `app/package/index.php` is absent. The wizard resolves `extension.failures`: `path.system` is set on every boot, and `installer` requires `package`, so `PackageManager::$failures` is a real store there. A container that does not set `path.system` still has no id. A caller that still names `Pagekit\Installer\Package\{Package,PackageInterface,PackageFactory}`, `Pagekit\Installer\Package\Lifecycle\`, or `Pagekit\System\Extension\ExtensionFailureStore` does not resolve.

---

## 🔐 Security & Data Impact

None.

---

## 🛡️ No-Mercy Compliance

The stale `Pagekit\Package\` mapping to a directory that does not exist was deleted, not left beside the new PSR-4 path. The main is the class. No alias. The registry, the lifecycle types, and the failure store changed namespace at every call site; `PackageManager` did not keep imports for classes its body never names. `SystemModule` dropped the registration; the `has()` read stayed, because a container without `path.system` still has no id.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: Step 1 — production Verifier PASS; Tester PHPUnit+PHPStan PASS. test-writer: first Tester FAIL (`PackageModuleBoundaryTest` included `app/system/index.php` and tripped `failOnWarning` on unbound `$app`). Retry binds `$app` before the include. Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS. Step 2 — production Verifier PASS; Tester PHPUnit+PHPStan PASS. test-writer: first Verifier FAIL (`PackageModuleBoundaryTest` baseline assertion expected a literal trailing `$`; in the neon entry that `$` is the pattern's end anchor inside `#^…$#`). Retry asserts the stored message. Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS. Step 3 — production Verifier PASS. Tester PHPUnit+PHPStan FAIL once (`PackageModuleBoundaryTest` import allow-list) then PASS after a production retry. test-writer PASS; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

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
