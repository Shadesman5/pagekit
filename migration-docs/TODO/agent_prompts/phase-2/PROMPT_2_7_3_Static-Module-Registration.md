# Step 2.7.3: Static Module Registration

<!-- conductor-mode: full -->

**ROADMAP:** 2.7.3. GitHub Issue: #266. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.7.3.

---

## Task

Boot learns that a module exists from static metadata. It does not execute that module's PHP to find out. The entry point runs only for a module that is actually being loaded.

The barrier around `include` catches a throwable. It does not catch a fatal compile error, `exit` or `die` at the top of the file, or resource exhaustion. Those still end the request, and today every on-disk package is included before anything is enabled. A package that is not being loaded must not be able to take the boot down.

One discovery path. The static source carries what registration reads before load: the module name, `require`, `include`, and the autoload map. The map is applied before the entry point of a module that is being loaded, including when a package is installed and not yet enabled. No second path still discovers a module by including its entry point.

The entry point stays PHP. `main`, closures and the other load-time behavior cannot move into a data file. Load includes that file and merges it with the static record. Discovery does not.

Registered still means the module is known. Enabled still means it is loaded. This step changes how a module becomes registered. It does not reopen the rules for an unsatisfied `require`.

Load, `main()` and lifecycle keep the existing fault barrier. Static discovery removes the registration hazard. It does not replace load isolation.

The shape of the static source is what a later packaging contract has to require. That contract is not written here.

## Findings

**What boot includes.** `app/system/app.php` and `app/console/app.php` register `packages/*/*/index.php`, `app/modules/*/index.php`, `app/package/index.php`, `app/installer/index.php` and `app/system/index.php`. The console boot also registers `app/console/index.php`. `app/installer/app.php` does not glob `packages/`. `ModuleManager::register()` includes every matched file. A file that does not return an array with `name` is skipped. The `try` around that `include` carries `TODO: Must be refactored in Step 2.7.3 (Static Module Registration)`.

**Children.** `app/system/index.php` sets `'include' => 'modules/*/index.php'`. `register()` resolves that glob and includes every system submodule while it is still registering `system`. Those submodules are not in the boot glob.

**Two catalogs.** The module registry is the included `index.php` arrays. The admin package list is a different scan: `PackageModule` builds `PackageFactory` from `packages/*/*/composer.json` (`name`, `type`, `version`, `title`). `packages/pagekit/blog/composer.json` has no module name, no `require` and no `autoload`. Those live in `packages/pagekit/blog/index.php`. A new file beside both is a third catalog.

**When classes load.** `AutoLoader` reads `autoload` inside `load()`, after `register()` has already included the file. On the blog, `events.boot` is a closure: `new PostPresenter` and `Comment::class` run when that event runs, not when the file is included. A class reference evaluated while the file is included needs the map already. `register()` does not apply it.

**Graph.** `require` is on the registered module because the include already happened. `resolveModules()` reads it from that array at load. A package that is on disk but not enabled is registered today, and its `require` is visible without anyone loading it.

## Out of scope

- Moving `vendor/` to the repository root (2.7.4).
- Prebuilt JavaScript and CSS, the author tooling, and the shape of an upload archive (2.8).
- Marketplace signing, a catalogue, or remote distribution (5.6).
- Process-level sandboxing of enabled extension PHP.
- The admin package list's `composer.json` fields (`title`, `version`, `type`). This step does not rebuild that list.

## Done when

- Boot can list every on-disk module, including a package that is not enabled, without executing its entry point.
- A broken or parse-invalid entry point in a module that is not being loaded does not take the CMS down. A test covers that, including a parse-error fixture.
- `name`, `require`, `include` and the autoload map are known from the static source. The map is applied before the entry point of a module that is being loaded.
- First-party modules use that source: `packages/pagekit/*`, `app/modules/*`, `app/system`, `app/installer`, `app/package` and `app/console`. Nothing discovers a module by including every entry point.
- An enabled package still loads through the existing fault barrier. Its entry point still carries the load-time PHP.
- The forward-debt tag on `ModuleManager::register()` is gone.
