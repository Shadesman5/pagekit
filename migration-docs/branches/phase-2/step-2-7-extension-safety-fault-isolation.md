# Step 2.7 — Extension Safety System

<!-- Branch doc for Roadmap Step 2.7.
     Path: migration-docs/branches/phase-2/step-2-7-extension-safety-fault-isolation.md -->

**Branch:** `feature/extension-safety-fault-isolation`
**ROADMAP Step:** 2.7 (Extension Safety System — Extension Safety & Fault Isolation)
**GitHub Issue:** [#160](https://github.com/Shadesman5/pagekit/issues/160)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-08-10 20:22
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### Durable failure record: `ExtensionFailureStore` + `path.system` (Checklist Step 1)

| File | Change |
|---|---|
| `app/system/src/Extension/ExtensionFailureStore.php` (new) | New final `Pagekit\System\Extension\ExtensionFailureStore`, constructed from a directory path and the `Filesystem` service (the Step 2.6 `dumpAtomic()` primitive). API: `record(name, type, \Throwable): bool`, `all(): array`, `has(name): bool`, `clear(name): bool`. Every method catches `\Throwable` internally and reports success as a `bool` — nothing escapes to a caller that is itself already handling a fault. Backed by one JSON file (`extension-failures.json`, one entry per module keyed by name — `name`, `type` (`extension`\|`theme`), `class`, `message`, `file`, `line`, `time`; no stack trace) written through `dumpAtomic()` so a concurrent boot never reads a half-written record. A missing, empty, truncated, non-object or foreign-shaped file — and any entry inside it with missing or mistyped fields — reads back as empty/defaulted rather than failing the read. Its directory is created on first write, not eagerly. Not yet called from anywhere; this step lands the primitive only. |
| `public/index.php` | New `path.system` key in `$config` (`$path.'/tmp/system'`), alongside the existing `path.temp`/`path.cache`/`path.logs` entries — the directory the store above uses. No other change: the existing conditional last-resort exception handler (gated on `tmp/logs/debug.log` already existing) is untouched here. |
| `docker/entrypoint.sh` | `tmp/system` added to the `mkdir -p` list of directories the container recreates on every start, alongside `tmp/cache`, `tmp/logs`, `tmp/packages`, `tmp/sessions`, `tmp/temp`. |
| `tmp/system/.gitignore` (new) | Ignores everything written into the directory except its own `.gitignore`/`.htaccess`. |
| `tmp/system/.htaccess` (new) | `Require all denied`. |

### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `tests/Unit/Extension/ExtensionFailureStoreTest.php` (new) | Real-filesystem coverage (per-test temp dir, no vfsStream) of `ExtensionFailureStore`: record/read/has/clear round-trip via a second store instance standing in for the next request; the directory is created by the first write, not upfront; the on-disk file is JSON, never executable PHP, and carries no stack trace; a second failure for the same module replaces the first; multiple modules coexist and clear independently; a missing/empty/whitespace-only/truncated/non-object/foreign-keyed record (data-provider cases) reads as no failures rather than throwing; an entry with missing or wrongly-typed fields still reads back in the full expected shape; a non-UTF-8 exception message is substituted rather than dropping the record; the atomic-write collaborator is asserted to receive the same target file on every write, never a second path; an unwritable directory, a path already occupied by a file, and a filesystem collaborator that throws (`\RuntimeException` and `\Error` alike) each fail only the call in progress and leave any prior record intact. Root-guarded permission tests skip under `posix_geteuid() === 0`, matching the Step 2.6 suite's convention. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Registration-window barrier + last-resort exception handler (Checklist Step 2)

| File | Change |
|---|---|
| `app/modules/application/src/Module/ModuleManager.php` | `register()`'s `include $file` — discovery's only way to read what a module declares, run against every on-disk `index.php` whether the module is enabled or not — is now wrapped in its own `try/catch (\Throwable)`. A throwing file registers nothing; its throwable is collected into a new `protected array $registrationFailures`, keyed by file path rather than module name (a file that failed to `include` never got far enough to declare one). Every other file in the same sweep keeps registering normally, including through the recursive `register($includes)` call a module's own `include` glob triggers one level below the top sweep. New `getRegistrationFailures(): array<string, \Throwable>` accessor returns the collected map as-is. The `try` carries `// TODO: Must be refactored in Step 2.7.3 (Static Module Registration)`; the method's doc block records that `ParseError` is caught like any other throwable, while a genuinely fatal compile error (duplicate class/function declaration), `exit`/`die`, or resource exhaustion inside the same `include` is not a throwable and still ends the request. The pre-existing `!is_array($module) \|\| !isset($module['name'])` skip for a file that runs cleanly but declares no module is untouched — that path was never a failure. |
| `app/system/src/SystemModule.php` | `main()` gains one loop, placed after the module loader is registered and before the extensions/theme load loop: every entry from `$app->get('module')->getRegistrationFailures()` is written to the `log` service at `error` level (`Extension failure [<file>] during registration: <message>`, with `['exception' => $error]` context for the stack trace). Placing it before the load loop means a file's registration failure is logged first, so an enabled extension that file belonged to hitting `Undefined module: <name>` moments later in the same request reads as the second half of one fault. The load loop itself is untouched — it still catches only `\RuntimeException` (Step 3). |
| `public/index.php` | The last-resort `set_exception_handler` — previously registered only `if (file_exists($path.'/tmp/logs/debug.log'))`, so a fresh install had no handler at all until something else had already created that file — is now registered unconditionally. It creates `tmp/logs` on first use (`@mkdir(…, 0755, true)`, re-checked against a concurrent creation) instead of requiring the directory to pre-exist, and both the directory creation and the `error_log()` write are `@`-suppressed with a silent early return on failure — the handler exists to record a throwable that already ended the request, so it must never raise one of its own. The logged message format (`[UNCAUGHT EXCEPTION]` header, type/message/file:line/trace) is unchanged. |

### Tests (Checklist Step 2)

| File | Change |
|---|---|
| `tests/Unit/Module/ModuleRegistrationTest.php` (new) | 9 tests against a bare `ModuleManager`: a throwing package is isolated while healthy neighbours on either side of it in the same sweep still register and load; a missing-dependency `Error` (not just an `Exception`) is isolated the same way; a `ParseError` from an unparsable file — generated at runtime into a temp directory, never committed — is isolated too; a file that failed to register surfaces as `Undefined module: <name>` when the boot later tries to load it under the name the site configuration still lists; a file that runs cleanly but declares no module (or returns nothing) is not counted as a failure; a package that registers correctly and only throws once loaded stays registered (its failure belongs to the load window, not this one); a package-of-packages one level below the sweep — the pattern the system module itself uses to find its own modules — isolates a broken child the same way; a clean install reports no failures; failures from separate `register()` calls accumulate together under one accessor. |
| `tests/Unit/Extension/RegistrationFailureReportingTest.php` (new) | 3 tests booting a real `SystemModule::main()` against a Monolog `TestHandler`: every registration failure is logged once, naming its file and carrying the original throwable in the record's `exception` context; a failed file's log line lands before the `Undefined module` line for an extension the site configuration still enables from that same file, preserving cause-then-consequence order; a clean install logs nothing on boot. |
| `tests/fixtures/modules/{healthy,second,throwing,missing-class,main-throwing,unnamed,no-return}/index.php` (new) | Syntactically valid module descriptors, one per registration scenario above (two intact modules; one throwing at top level; one hitting a missing class; one that only throws from its `main` closure once loaded; one declaring no `name`; one returning nothing). Safe to commit as plain fixtures: neither PHPStan's analysed paths (`app/modules`, `app/system`, `app/installer`, `app/console`, `packages`) nor PHPUnit's `<source>` include set (`app/modules`, `app/system`, `app/console`) reach `tests/`. |
| `tests/fixtures/modules/host/index.php` + `tests/fixtures/modules/host/modules/{healthy,broken}/index.php` (new) | A package declaring its own `include` glob one level below it, with one intact and one throwing child, covering the recursive `register()` call the same barrier reaches through. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Load-window barrier + auto-disable + boot merge (Checklist Step 3)

| File | Change |
|---|---|
| `app/system/src/Extension/ExtensionLoader.php` (new) | New final `Pagekit\System\Extension\ExtensionLoader` — the load-window barrier extracted from `SystemModule::main()`'s loop. Constructed from the `ModuleManager`, a `LoggerInterface`, a nullable `ExtensionFailureStore`, and a `\Closure(string): void` that disables an extension by name. `load(array $extensions, ?string $theme): void` first flushes the Step 2 `getRegistrationFailures()` map to the log (by file path, before anything loads), then computes the effective list as the configured extensions **minus** names already on the failure record — the boot merge — before looping. Each module load is individually `try/catch (\Throwable)`; on catch: log with `['exception' => $e]`, disable (extension only — the theme is never pulled from config), then always record to the store, regardless of whether the disable succeeded. Logging, disabling and recording each carry their own `try/catch` so a failure in one is reported on its own and never replaces or blocks the other two. |
| `app/system/src/SystemModule.php` | `main()` now registers `extension.failures` as a container service — `$app->has('path.system')`-guarded, so a container that never receives that key (console/installer boot) simply has no service to resolve rather than a service that resolves and then fails on first use. Builds an `ExtensionLoader` from `$app->get('module')`, `$app->get('log')`, the failure store (or `null` when the service is absent), and a new private `extensionDisabler()` closure; calls `$loader->load((array) $this->config['extensions'], $theme)` in place of the previous inline `foreach` (which caught only `\RuntimeException` and left everything else fatal). `extensionDisabler()` is `$app->has('config')`-guarded too (the installer boots without a `config` service) and, when present, pulls the name out of the `system` config's `extensions` array and writes it back immediately via `ConfigManager::set()` — not deferred to the terminate event, because the request that just failed is not one to trust with reaching its own end. The synthetic `theme-default` fallback below is unchanged. |

### Tests (Checklist Step 3)

| File | Change |
|---|---|
| `tests/Unit/Extension/ExtensionLoaderTest.php` (new) | 12 tests against `ExtensionLoader` directly (fixture-registered `ModuleManager`, a real `Filesystem`-backed store in a per-test temp dir, a Monolog `TestHandler`): a broken extension is isolated while a healthy extension and the theme behind it still load; a missing-class `\Error` (the `main-erroring` fixture) is caught the same way exceptions are; an extension already on the failure record is excluded from the load list without being re-executed or re-logged; a registration-window (Step 2) casualty is reported first by path, then a moment later by name once the site configuration still hits it as `Undefined module`; the theme is logged and recorded on failure but never disabled, and is retried on the next boot rather than skipped; a failure is still recorded when the disable closure itself throws; a disable that never reached the database still keeps the extension off the *next* boot because a fresh `ModuleManager` in that test is loaded against the record, not the stale config; a record write failure is reported without swallowing the original failure; a logger that cannot write costs only the report, not the boot; a `null` store (no `path.system`) still gets the barrier; a clean install logs, disables and records nothing; a site without a theme loads none rather than failing over the lookup. |
| `tests/Unit/Extension/ExtensionAutoDisableTest.php` (new) | 7 tests booting a real `SystemModule::main()` end-to-end rather than the loader in isolation: the enabled list is written back before the request ends, not left to the terminate event; the on-disk record lands in the directory `path.system` names; an extension whose disable failed (a `ConfigManager::set()` that throws) still stays off the *next* boot because the record — not the config — is what carried it across the request boundary; a failing theme is recorded without touching the enabled-extensions list and the app falls back to the synthetic theme; a site whose config write fails still finishes booting, with both the load failure and the disable failure reported and the healthy extension/theme still resolving; a container with no `config` service (the installer's boot shape) survives the failure too; a container with no `path.system` key gets the barrier but keeps no record and `extension.failures` does not resolve from it. |
| `tests/fixtures/modules/main-erroring/index.php` (new) | Module descriptor whose `main` closure instantiates a class from an uninstalled dependency — PHP raises that as `\Error`, not `\Exception` — proving the load barrier's `\Throwable` catch, not just an exception-only one. |

Gates: Verifier (production) FAIL → PASS after a service-registration guard fix; Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Admin notification + re-enable clearing + manager UI state (Checklist Step 4)

| File | Change |
|---|---|
| `app/system/index.php` | The existing `view.messages` handler's flash-message loop is unchanged; a second block is appended after it, wrapped in its own `try/catch (\Throwable)`. It reads `extension.failures` (`isAdmin` + `$app->has()`-guarded) and, only once the record is non-empty, checks `user->hasAccess('system: manage packages')` before rendering one `.uk-alert.uk-alert-warning` per entry into the same `.pk-system-messages` markup the flash messages use — a theme entry reads "could not be loaded", an extension entry "failed and was disabled". Either way the notice names only the module (HTML-escaped) and points at the log; the store's `message`/`file`/`line` fields (Checklist Step 1) are never rendered. An anonymous request or a non-privileged admin request never resolves the store or the permission service at all. |
| `app/installer/src/Package/PackageManager.php` | New `private readonly ?ExtensionFailureStore $failures`, resolved once in the constructor (`$app->has('extension.failures')`-guarded — `null` in the installer environment, which has no system module). New `getFailedModules(): array` returns the recorded names for the controller below. New private `clearFailure(PackageInterface $package)`, called from `uninstall()`, from the success tail of `enable()` (after the existing `package.enable` event trigger), and from `disable()` (after the existing `extensions` config pull): clears the module's record; a clear that fails is reported to the `log` service (`has('log')`-guarded) rather than surfacing to the caller, and never blocks the enable/disable/uninstall it is attached to. An enable that throws again never reaches this call — the existing `catch (\Throwable)` path is untouched. |
| `app/installer/src/Controller/PackageController.php` | `extensionsAction()` calls `getFailedModules()` once and sets `failure: true` on each package whose module name is on that list, alongside the existing per-package `enabled`/settings resolution — distinguishing a package the failure record switched off from one an administrator disabled by hand, both of which otherwise read as simply not enabled. |
| `app/installer/views/extensions.php` | The status-column toggle gains a third state between "Enabled" and "Disabled": `v-else-if="pkg.failure"` renders a warning icon with a tooltip ("Disabled automatically after a failure. See the error log, then enable again.") in place of the plain disabled dot, re-enabling through the same `@click="enable(pkg)"` the disabled state already used. |

### Tests (Checklist Step 4)

| File | Change |
|---|---|
| `tests/Unit/Extension/ExtensionFailureNoticeTest.php` (new) | 11 tests against the `view.messages` handler via a real `require` of `app/system/index.php` (a module definition, not an autoloaded class) against a bare `Application`: an extension failure renders as "failed and was disabled", a theme failure as "could not be loaded"; the notice names the module and points at the log without repeating the throwable's own message; every recorded failure renders, not just the first; the standing notice appears alongside the current request's own flash messages rather than replacing them; a visitor request and a privileged-but-unauthorized admin request render nothing and never resolve the store/permission services; the permission check runs only when the record is non-empty, and a permission check that throws (DB down) still leaves the rest of the panel rendering; a module name is HTML-escaped before it reaches the markup; a container shaped like the installer's (no `extension.failures` service) renders exactly the messages it always did. |
| `tests/Unit/Package/PackageFailureRecordTest.php` (new) | 13 tests against `PackageManager` and `PackageController` with a real `ExtensionFailureStore` in a per-test temp dir: `getFailedModules()` reports every recorded name, and an empty list where the container keeps no store or something other than the store is registered under that name; `enable()` clears the record for the package being enabled while leaving an unrelated one recorded, for both an extension (`extensions` array) and a theme (`site.theme`); an enable that fails again leaves the existing record in place; `disable()` and `uninstall()` each clear only the acted-on package's record; a record-write failure during clearing is reported to the log while the disable/uninstall itself still goes through; a container shaped like the installer's (no store) disables exactly as it always did; `extensionsAction()` marks a recorded package `failure: true` (with `enabled` unset) while a healthy sibling is marked enabled and not failed, the mark round-trips through the JSON encoding the Vue view consumes, and a container with no store marks nothing. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Lifecycle interface + `LifecycleRunner` replaces `PackageScripts` (Checklist Step 5)

| File | Change |
|---|---|
| `app/installer/src/Package/Lifecycle/PackageLifecycleInterface.php` (new) | New `Pagekit\Installer\Package\Lifecycle\PackageLifecycleInterface`: `install/enable/disable/uninstall(ContainerInterface $app): void`, `updates(): array<string, callable(ContainerInterface): void>` (version-keyed), `migrations(): ?MigrationSet`. A package's lifecycle file now returns an implementation of this instead of an array of closures keyed by hook name — a misspelled hook name is a class that fails to compile rather than a hook that silently never runs. |
| `app/installer/src/Package/Lifecycle/PackageLifecycle.php` (new) | Abstract no-op base implementing the interface (`updates()` → `[]`, `migrations()` → `null`, the four void hooks do nothing) — a package overrides only the hooks it uses. |
| `app/installer/src/Package/Lifecycle/MigrationSet.php` (new) | `final readonly class MigrationSet` (`namespace`, `path`) — the two values `MigrationService::migrateExtension()`/`rollbackExtension()` need; a package declares them here starting this step but does not yet run them through the framework (Step 6). |
| `app/installer/src/Package/Lifecycle/LifecycleRunner.php` (new) | Replaces `PackageScripts` behind the same construction shape (`?string $file, ?string $current, ContainerInterface $app`). Loads the file lazily on the first hook call and caches the resolved lifecycle for the rest of that runner's life — one file read per package operation, however many hooks it calls; the next operation gets a fresh runner and a fresh read. A missing file, or none declared, makes every hook a no-op. A file returning anything other than a `PackageLifecycleInterface` is a reported fault (`\RuntimeException` naming the file, the expected interface, and what it returned instead) from every entry point (`install`/`enable`/`disable`/`uninstall`/`update`/`hasUpdates`), and the same runner keeps reporting it rather than settling into a no-op after the first call. `updates()` keeps `PackageScripts`'s version-keyed filter + `version_compare` ordering unchanged. |
| `app/installer/src/Package/PackageScripts.php` (deleted) | The array-of-closures runner it replaces — no dual path, no fallback kept alongside it. |
| `app/installer/src/Package/PackageManager.php` | `getScripts()` → `getLifecycle(): LifecycleRunner`. New private `reportHookFailure()`: logs (`log`-guarded, its own `try/catch (\Throwable)`) and swallows a throwable from a lifecycle hook. `disable()`'s and `uninstall()`'s hook calls, previously unguarded, are now individually `try/catch (\Throwable) { reportHookFailure(...) }` — a broken package's hook no longer blocks the admin's way out. `enable()`'s hook call is unchanged: it still propagates into the existing rollback catch, because a package that fails to enable is not ready to run. |
| `app/installer/src/Installer.php` | Construction site swapped: the system's own install-time lifecycle read now builds a `LifecycleRunner`. |
| `app/console/src/Commands/MigrationCommand.php` | Construction site swapped. The previous two-step sequence — an untried `hasUpdates()` call followed by a try/caught `update()` — is now both calls inside one `try/catch`, since the file is read lazily on the first question asked of it, which is `hasUpdates()` here. |
| `app/system/src/Controller/MigrationController.php` | Construction site swapped (`$scripts` property renamed `$lifecycle`); both call sites (`hasUpdates()`, `update()`) follow. |
| `app/system/index.php` | Construction site swapped in the `auth.login` listener's pending-update check. |
| `app/system/scripts.php` | The system's own lifecycle file: array return → `return new class extends PackageLifecycle { public function install(...) {...} }`. Same dashboard/menu seeding; no `updates()` override (none declared today). |
| `packages/pagekit/blog/scripts.php` | Array return → anonymous `class extends PackageLifecycle` implementing `install()`, `enable()`, `uninstall()` — an anonymous class, not yet the named `BlogLifecycle` (Step 6). The old `install` and `enable` hooks each inlined an identical `migrateExtension()` call; both now call one shared private `migrate()`. Behaviour unchanged: install/enable still migrate directly, uninstall still only clears the cache. |

Three pre-existing test files construct a package's `scripts.php` fixture inline and still returned the old array shape, which `LifecycleRunner` now rejects; each needed its fixture-writer converted to emit a `PackageLifecycle` anonymous class in the same step rather than in coverage:

| File | Change |
|---|---|
| `tests/Unit/Console/MigrationCommandTest.php` | Its three inline fixtures (`markerUpdateScript()`, `throwingUpdateScript()`, `staleUpdateScript()`) now share one private `updateScript()` helper emitting the lifecycle-class shape; assertions/comments referring to "PackageScripts hooks" reworded to "the lifecycle's updates". Test behaviour and coverage unchanged. |
| `tests/Unit/Package/PackageManagerMigrationTest.php` | Its five inline fixtures (`enableMigrateScript`, `announcingScript`, `throwingEnableScript`, `fullRollbackUninstallScript`, `partialRollbackUninstallScript`) now share one private `lifecycleScript(string $hook, string $body)` helper; assertion messages reworded from "scripts.php hook" to "the lifecycle hook". Test behaviour and coverage unchanged. |
| `tests/Unit/Package/PackageFailureRecordTest.php` | Its one `writeScripts()` fixture writer converted the same way. Test behaviour and coverage unchanged. |

### Tests (Checklist Step 5)

| File | Change |
|---|---|
| `tests/Unit/Package/LifecycleRunnerTest.php` (new) | Runner behaviour directly, against real per-test lifecycle files on disk: no file / a file not on disk / a lifecycle overriding nothing all run every hook as a no-op; a file returning an array (the old contract) or anything else non-lifecycle is reported from whichever hook is called first — naming the file and the expected interface — and the same runner keeps reporting it on a second call rather than settling for no-op; each hook runs the like-named method against the container the *operation* was given; one operation reads the file once however many hooks it calls, the next operation gets its own read; `updates()` ordering/filtering (`version_compare`, bare-integer version keys, nothing left once an installation has passed every update) carried over from the deleted `PackageScripts` suite; `migrations()` returns a declared `MigrationSet` or `null`. |
| `tests/Unit/Package/PackageHookBarrierTest.php` (new) | `PackageManager::disable()`/`uninstall()` against a real lifecycle file on disk: a throwing `disable`/`uninstall` hook (both a plain exception and a missing-class `\Error`) still leaves the extension off / the package removed, still fires the same lifecycle events, still clears the failure record — only a log line (naming the hook, the package, carrying the throwable) is the cost; a lifecycle file that cannot be read is likewise no obstacle to disabling; a logger that itself throws does not stop the disable from completing. `enable()` is the control case: a lifecycle file that cannot be read there is refused (`\RuntimeException` propagates, nothing is enabled). |
| `tests/Unit/Package/ShippedLifecycleTest.php` (new) | Reads `app/system/scripts.php` and `packages/pagekit/blog/scripts.php` from disk through a real `LifecycleRunner` (no mocks of the files themselves): both declare no pending updates; the system's `install()` seeds the dashboard and main-menu config; the blog's `install()` and `enable()` both migrate (same call, idempotent either way) against the namespace/directory the file actually ships, and a failed migration's error message surfaces as the hook's exception; `uninstall()` clears the cache when one is present and completes without one when it is not (console/installer boot shape), and never touches the migration rollback path (data preserved). |

Gates: Verifier (production) FAIL → PASS after rewriting the three pre-existing tests' inline `scripts.php` fixtures from array returns to the new lifecycle contract (the runner rejects the old shape, which the fixtures still used); Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

---

## 🧠 Key Decisions (Rationale)

- **`path.system` is a directory of its own, not `path.cache`/`path.temp`/`storage` (Checklist Step 1).** The extension-failure record has to survive exactly the operations those existing paths do not: `CacheModule::doClearCache()` sweeps `path.cache` and `path.temp`, and `storage/` is reachable over HTTP through the `public/storage` mount. A record that is the only thing telling the next boot which extension to leave off needs a home a routine cache clear does not empty and a browser cannot request.
- **`ExtensionFailureStore` never throws (Checklist Step 1).** Every public method catches `\Throwable` internally and reports success as a `bool`. It is only ever called while another fault is already being handled — a throw here would replace the failure an administrator needs to see with one of its own, on the request that is trying to keep booting.
- **Registration failures are keyed by file path, not module name (Checklist Step 2).** A file that throws during `include` never executes far enough to declare the array discovery would otherwise read a name from — the path is the only handle available, and it is also what the boot's later `Undefined module` error names the same fault by a second time. Flushing the collected failures to the log before the extension/theme load loop keeps the two ends of one story in the order they happened, instead of a bare "undefined module" with nothing saying why.
- **The last-resort handler's own recovery path is not allowed to throw (Checklist Step 2).** It exists only to record a throwable that has already ended the request; `mkdir()` and `error_log()` are both `@`-suppressed and the handler returns silently rather than escalating, so an unwritable `tmp/` cannot turn one crash into a second, unhandled one from inside the handler meant to report the first.
- **The `extension.failures` service registration is `$app->has('path.system')`-guarded, not unconditional (Checklist Step 3).** The Verifier's first pass on this step failed on an unconditional `$app->set(...)`: a container built without a `path.system` key would still resolve the service and only fail once something called a method on it. Guarding the registration itself means the one question a caller can ask the container — whether `extension.failures` is there — is the same question as whether it resolves; `ExtensionLoader` already treats the store as nullable, so the barrier still runs without it and only the next boot's record is what is lost.
- **Disabling and recording are independent outcomes, both attempted on every load failure (Checklist Step 3).** The database is exactly what a failing extension may have broken on, so the config write and the store write cannot be made to depend on each other succeeding. The store write always runs regardless of the disable outcome — it is the one thing that still keeps the extension off the next boot when the config write was lost.
- **The admin notice is derived from the record on every render, not queued as a session flash (Checklist Step 4).** The request that hits a load failure is almost never the administrator's — a `MessageBag` flash queued then would be spent on whichever visitor happened to trigger it. Reading the store on render instead makes the notice stand for as long as the failure does and disappear only when the record is cleared, with no session state to expire or linger.
- **Deriving the notice asks who is looking last, and only when there is something to report (Checklist Step 4).** `hasAccess('system: manage packages')` reads permissions from the database, which is a plausible thing for the failing extension to have taken down; checking it before the record is known to be non-empty would fail every admin render over a question that usually has no consequence. The whole block also carries its own `try/catch (\Throwable)` — the panel it renders in is the one the site is repaired from, and losing the notice for one render costs a failure nobody was told about that time, while a throw escaping here would cost that page.
- **Clearing is centralized on `PackageManager`, not duplicated per action (Checklist Step 4).** `enable()`, `disable()` and `uninstall()` each call the same private `clearFailure()`; an enable that still fails never reaches it, so the record survives to keep the extension out of the next boot and named in the notice. A clear that cannot be written is logged, best-effort, and never blocks the action that triggered it.
- **`PackageScripts` is replaced outright, not extended alongside a new contract (Checklist Step 5).** Only two lifecycle files exist in the tree today (the system's own, the blog's) and no third-party package contract has shipped yet, so there is no installed base to keep working against the old array shape. `LifecycleRunner` requires the interface from the moment it exists; both shipped files convert in the same step rather than getting a transition period.
- **Disabling/uninstalling and enabling diverge on purpose (Checklist Step 5).** A disable or an uninstall is how an administrator gets out from under a package that is already misbehaving, so its hook is given its chance but no veto — a throw costs a log line, never the operation. Enabling is the opposite case: a hook failing there means the package is not ready to run, so `PackageManager::enable()` keeps propagating the throwable into its existing rollback catch unchanged.

---

## 💥 Breaking Changes (Extensions)

- **A package's lifecycle file must return a `PackageLifecycleInterface`, not an array of closures (Checklist Step 5).** `LifecycleRunner` rejects the previous `['install' => fn, ...]` shape with a `\RuntimeException` naming the file, the expected interface and what it returned instead. Nothing in this tree breaks — the only two shipped lifecycle files converted in the same step — but any future extension's `extra.scripts` file has to target the new interface from the start; the composer contract key itself (`extra.scripts`) is unchanged.

---

## ⚠️ Risks & Rollout Notes

- **The registration barrier stops where PHP stops throwing (Checklist Step 2).** `ModuleManager::register()` isolates every exception, `Error`, and `ParseError` a module file's `include` can raise, but a genuinely fatal compile error (a duplicate class/function declaration), `exit`/`die`, or resource exhaustion inside that same `include` is not a throwable and still ends the request — the barrier's own doc block records the residue, and closing it is Step 2.7.3's static-registration redesign (forward-debt tag on the `try` itself).
- **The last-resort handler is now always on, not opt-in via a pre-existing `debug.log` (Checklist Step 2).** It previously registered only when `tmp/logs/debug.log` already existed, so a throwable early in a fresh install's boot had no handler and no record at all. It is now unconditional and creates `tmp/logs` on demand, so every environment logs every uncaught throwable to that file from here on — there is no remaining way to opt out short of denying `tmp/` write access, which the handler's own suppressed `mkdir`/`error_log` calls degrade out of silently rather than escalate.
- **A failing extension's partial registrations survive for the rest of the request that caught them (Checklist Step 3).** Whatever a `main()` registered in the container before it threw — services, listeners, locator entries — has no ownership tracking behind it to unwind, so it stays for the current request; the module itself never enters `ModuleManager`'s loaded set, so nothing can resolve it as a module for the remainder of that same request. Degraded-but-consistent for the request that broke is the contract `ExtensionLoader`'s doc block states; the *next* request does not execute the extension at all, so the partial state does not carry forward.
- **A disable/uninstall hook failure is silent to the admin, by design (Checklist Step 5).** `PackageManager::disable()`/`uninstall()` swallow a broken hook's throwable into a log line only — the action itself still reports success, because it is one the admin has to be able to complete regardless of what the package's own code does on the way out. Nothing in the panel distinguishes a clean hook from a logged-and-swallowed one; `tmp/logs/debug.log` is the only place the failure surfaces.

---

## 🔐 Security & Data Impact

- **The failure record is denied over HTTP and lives outside the webroot (Checklist Step 1).** `tmp/system/.htaccess` adds `Require all denied` on top of the directory already sitting outside `public/`; the record holds a throwable's class, message and `file:line` — no stack trace — for whichever extensions/themes fail, and neither the directory nor its content is reachable by a request.
- **The admin notice names the module only, never the throwable's own message (Checklist Step 4).** The store's `message`/`file`/`line` fields stay in the log; the panel renders just the module name — HTML-escaped, since it originates from the package's own `composer.json` — so a database error, filesystem path, or other detail a failure happened to carry never reaches the page every administrator with package-management rights can see. The notice is also gated on that same permission, resolved only once the record is non-empty.

---

## 🛡️ No-Mercy Compliance

- **Rule 4 (Delete over wrap) — Checklist Step 2:** `public/index.php`'s `if (file_exists(...))`-gated handler registration is deleted outright in favor of unconditional registration — no flag or fallback keeps the old conditional path alive alongside it.
- **Rule 5 (Mandatory Flagging) — Checklist Step 2:** the registration-window `try/catch` in `ModuleManager::register()` carries `// TODO: Must be refactored in Step 2.7.3 (Static Module Registration)` — forward debt for the residual risk noted above, not a marker on anything already finished.
- **Rule 4 (Delete over wrap) — Checklist Step 3:** `SystemModule::main()`'s previous inline `foreach` — catching only `\RuntimeException`, everything else fatal — is deleted outright in favor of `ExtensionLoader::load()`; no dual loop or narrower fallback catch is kept alongside the `\Throwable` barrier.
- **Rule 4 (Delete over wrap) — Checklist Step 5:** `PackageScripts.php` (the array-of-closures runner) is deleted outright in favor of `LifecycleRunner`; all five construction sites swap in the same step, and no shim keeps the old array contract readable alongside the new interface.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: _TBD / None_

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

_TBD / None_

---

## 🧹 Cleanup

_TBD / None_

---

## 🛡️ Audit

_TBD / None_

---

## 🎁 Bonus

_TBD / None_

---

## 🔍 Research

_TBD / None_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_7_Extension-Safety-Fault-Isolation_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_Extension-Safety-Fault-Isolation.md`
- Predecessor: Step 2.6 — Filesystem Write Resilience
- Successor: Step 2.7.1 — Snapshot & Three-Stage Uninstall

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
