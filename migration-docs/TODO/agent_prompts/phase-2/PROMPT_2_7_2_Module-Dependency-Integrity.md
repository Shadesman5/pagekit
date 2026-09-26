# Step 2.7.2: Module Dependency Integrity

<!-- conductor-mode: full -->

**ROADMAP:** 2.7.2. GitHub Issue: #268. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.7.2.

---

## Task

The module dependency graph is honest in both directions, and disable, uninstall and restore do not run without a pre-flight.

`ModuleManager::resolveModules()` skips a `require` that is not registered, and the depender still loads. `load()` throws only for a name it was asked for directly. `PackageManager::disable()` never consults the graph. Activation must not leave a half-wired application because a requirement was skipped.

An unsatisfied `require` refuses, and the message names the missing module. Logging it and continuing is the same defect. A module that is already enabled and whose requirement is unsatisfied does not take the request down: the existing auto-disable and admin notice carry that failure. A registered-but-disabled module and a module that is not registered are different failures, and each message says which.

A manifest `require` that names a module this installation does not have is refused before any package file is written, and the message names the missing module. The panel upload and `php pagekit install <archive>` share that check.

`enable()` and `disable()` are reached from the panel alone. `php pagekit install <archive>` places the tree and runs the install lifecycle, and no console command switches a package on or off, so a host without the panel can install a package and cannot activate it. Either the console gets enable and disable through the same pre-flight, or the panel stays the only activation surface and the install command's help says so.

`requiredBy` is derived from the registered manifests. Destructive operations ask it who still needs the target. The active theme (`site.theme`) counts as enabled, the same as an entry in `extensions`. A blocking dependent stops disable and uninstall. There is no override.

The admin UI and the API read one pre-flight: blockers, modules that would keep a requirement nobody satisfies, and a data-risk hint. The hint comes from what already exists (migrations, config rows, node types). No new metadata field on the manifest.

A restore puts the whole database back. A core or extension whose code has moved on since the snapshot gets its schema and its `packages.*` version key reverted while the code stays new. Core migrates again on the next admin request; an extension migrates on `enable()`. The pre-flight compares the snapshot's application version and the dump's `packages.*` keys with the running installation and refuses by name. Snapshot metadata records the application version. The dump format stays as it is.

That answer also includes what a MySQL restore would refuse — no table prefix, a copy name past 64 characters, a reserved `_r_` / `_b_` name that is not this installation's, an inbound foreign key from a table outside the dump, another restore holding the lock — and it is given before any package file is put back. A refusal leaves `packages/` as it was. A refusal an operator can act on is not reported as an internal failure.

`DatabaseDumper::schema()` and `DatabaseRestorer::name()` compare the prefix byte for byte. The collision checks fold names through `comparable()` and `lower_case_table_names`. The selection and the ownership check fold the same way. A dump that selected no table is refused when it is taken.

Which tables a module owns is part of the data-risk answer. A package-scoped restore, and dropping those tables on purge, are not built here.

The integrity API talks about modules that are registered and modules that are enabled, so a later change to how a module becomes registered does not reopen these rules.

`application` is the composition root. It does not own the types other modules import. `kernel` owns `Event`, `Module`, `Container`, `Application`, `Util`. `UrlProvider` and `Response` live in `routing`. `#[Access]` lives in `routing`. `view/twig` is not a module. A `Pagekit\` autoload prefix belongs to one directory. An import is a `require` toward that module. A cycle is broken by moving the type. No alias, no forwarding subclass, no test exception for a parent import.

## Findings

**Resolver.** `ModuleManager::resolveModules()` in `app/modules/application/src/Module/ModuleManager.php`: a name already on the unresolved stack throws `Circular requirement "%s > %s"`. A name present in `$this->registered` recurses. Any other name is ignored, and the depender is still stored in `$resolved`. `load()` throws `Undefined module: $name` only for the name it was asked to load.

**Who is enabled.** System config `extensions` (a list) and `site.theme` (one module). `SystemModule` loads both. `PackageManager::enable()` and `disable()` push and pull `extensions`, and set or remove `site.theme`. `disable()` runs the package script, fires `package.disable`, and pulls the extension. The extensions and themes screens show name, toggle, version, folder, settings, permissions and uninstall.

**Imports and manifests.** Core modules import types their `require` lists do not reach. `#[Access]` sits in `system/user` while admin controllers, including package, import it. `system/user` imports captcha, mail and cache. `application` is both the composition root and the owner of `Event`, `Module` and `Application`, and `UrlProvider` imports routing. `view` requires `view/twig`, and `view/twig` imports `view`. Two modules claim `Pagekit\View\`. `system/settings` claims `Pagekit\System\`. The edge test maps a class to the module directory that contains its file and fails unless `require` reaches that module. `packages/pagekit/*` are outside the test; their call sites of a moved class still change.

**Notice.** A boot-time refusal uses the durable failure record and the admin notice that already exist.

**Archive.** `PackageArchive::open()` refuses an archive before anything is written: manifest shape, entry names, autoload directories. `PackageManager::install()` checks the target. A `require` naming a module the installation does not have is on neither list, so the tree lands and the failure shows up at activation or on the next boot. The module `index.php` `require` is readable without running the file, the same way `autoload` already is.

**Callers.** `enable()` and `disable()` are reached from the panel alone. The install command places the tree and does not enable the package.

**Uninstall.** `PackageController::uninstallAction` takes the snapshot first. It carries `TODO: Must be refactored in Step 2.7.2 (Module Dependency Integrity)`. The pre-flight belongs in front of that snapshot.

**Restore.** `PackageSnapshotter::restore()` puts the package files back (`reinstate()`), then restores the database. `details()` records the package's identity, its version, the reason and a database description. It does not record the running application version. `SnapshotController::failed()` returns one fixed line for every failure.

**Prefix.** `DatabaseDumper::schema()` keeps a table when the name starts with the prefix. `DatabaseRestorer::name()` rejects a dumped name that does not. `TablePrefix` accepts an uppercase prefix (`PK_`). On a server that folds table names, that installation's dump selects nothing, and the snapshot is still marked whole.

**Tests.** Nothing covers the silent skip or a disable pre-flight. `PackageLifecycleWiringTest` covers node lifecycle. Fixture modules live in the test, not in the first-party packages.

## Out of scope

- Static discovery and a new manifest format (2.7.3).
- Which characters an install prefix may contain.
- Removing orphaned modules, and install-reason bookkeeping (5.0).
- The package author contract (2.8).
- The marketplace (5.6).

## Done when

- An unregistered or inactive `require` does not load the depender. The failure names the module and the missing requirement. A cycle is still detected, including when a package is enabled or validated.
- An archive whose `require` names a module the installation does not have is refused before anything is written. The missing module is named. The panel upload and `php pagekit install <archive>` both hit that refusal.
- Enable and disable from the console are settled: the same pre-flight as the panel, or the panel stays the only activation surface and the install command's help says so.
- `requiredBy` answers who depends on a module, from the registered manifests, including the active theme.
- A test reads every core module's `use Pagekit\…` imports, attribute classes included, against its manifest `require`, and fails when no declared edge reaches the module that contains the class file. A cycle is fixed by moving the type. `#[Access]` is a routing type. `kernel` owns the foundation. `application` is only the composition root.
- Disable and uninstall pre-flight reports blockers, orphans and a data-risk hint. A blocking dependent prevents the change. The extensions and themes UI shows that result.
- Restore pre-flight refuses, by name, when the recorded application version or a `packages.*` version differs. Metadata written from now on carries the application version. The MySQL refusals above are part of that answer, and they are known before a package file is written back. A refused restore leaves `packages/` unchanged.
- Table selection and the check that a dumped table belongs to this installation fold the prefix the way `comparable()` does. A dump that selected no table is refused when it is taken.
- A boot-time failure reuses the existing auto-disable and admin notice.
