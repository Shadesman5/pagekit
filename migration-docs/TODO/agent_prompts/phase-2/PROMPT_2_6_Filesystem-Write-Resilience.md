# Step 2.6: Filesystem Write Resilience — Atomic Writes & Error-Handling Hygiene

<!-- conductor-mode: full -->

**ROADMAP:** 2.6. GitHub Issue: #257. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.6.

---

## CONTEXT

- **Land after:** nothing hard. Technically independent of 2.5 (Docker production image) — the two touch disjoint files. If 2.5 has already landed, its prod `php.ini` sets `opcache.validate_timestamps=0`, which makes the opcache concern below load-bearing rather than theoretical.
- **Provides:** the atomic-write primitive that Extension Safety (2.7) reuses for its DB-less fallback write and that the Automated Update System (2.9) reuses for artefact/registry writes — this step is sequenced before both so neither has to invent its own.
- **Risk:** Low–Medium. Small surface, but two of the three write targets are files the application cannot boot without.
- **Goal:** One shared atomic-write primitive for boot-critical files, used by every writer of such a file; plus two error-handling sites that stop hiding failures.
- **Why:** `config.php` and `packages.php` are written with a plain `file_put_contents()`. A crash, a full disk, or a reader hitting the file mid-write leaves a truncated `<?php return …` file that the next request `require`s — for `config.php` that means the installation stops booting and only a manual file edit brings it back. The routing cache already implements the correct temp+rename pattern, including a Windows fallback, but that logic is private to the router. Extract it once, reuse it, delete the duplicate.

### Current state (verified 2026-07-31 — confirm in Discovery, then build; do not rediscover blindly)

**The filesystem service has no write-content API at all.**

- `app/modules/filesystem/src/Filesystem.php` — public API is `getUrl`, `getPath`, `getPathInfo`, `getPathOption`, `exists`, `copy`, `delete`, `listDir`, `makeDir`, `copyDir`, `getAdapter`, `registerAdapter`. No `dump`/`write`/`put`, no `chmod`, no `rename`. It is a path resolver plus an adapter dispatcher.
- Registered as service id **`file`** in `app/modules/filesystem/index.php:17` (`$app->set('file', fn () => new Filesystem())`); the module manifest has **no `require`** list.
- Paths are resolved through `Path::parse()` and per-protocol adapters (`getPathOption($file, 'pathname')`, `:76-82`). Non-`file` protocols are served by `StreamAdapter`/`StreamWrapper`. A rename-based atomic write is only meaningful for a real local path, so the new method must resolve `pathname`/`localpath` explicitly and decide what happens for adapter-backed protocols — **fail loudly rather than silently degrade to a non-atomic write**.

**The reference implementation lives in the router.**

- `app/modules/routing/src/Router.php:442-468` `writeCache()`: `@tempnam(dirname($file), 'route-cache')` → `@file_put_contents($tmp, $content)` → `@chmod($tmp, 0666 & ~umask())` → `@rename($tmp, $file)`; on any failure `@unlink($tmp)` and then a direct `@file_put_contents($file, $content, LOCK_EX)`, which throws `\RuntimeException` if it also fails. The comments document the intent (concurrent readers during rapid route changes) and the Windows-lock fallback.
- Callers: `getMatcher()` (`:171`) and `getGenerator()` (`:212`). Both are wrapped in `try/catch (\Throwable)` (`:168-189`, `:209-231`) that falls back to the **non-cached** matcher/generator. The router therefore tolerates a corrupt cache by design — `config.php` and `packages.php` do not. Do not carry that tolerance over to the config writers.
- Freshness is `filemtime($file) >= $this->resource->getModified()` in `getCache()` (`:404-433`). **Content-hash freshness is a Step 2.7 item, not this step's** — leave the freshness logic alone.
- `app/modules/routing/src/Tests/RouterTest.php:240-273` (`testCorruptCacheFileFallsBackGracefully`) covers the graceful degradation and must stay green after the refactor.

**Module dependency direction is the central design constraint.**

- `routing` requires `kernel` and `filter` (`app/modules/routing/index.php:91-96`) — **not** `filesystem`. `Router` is constructed at `app/modules/routing/index.php:27` with routes, loader, request stack and a `cache` path only; it has no access to the `file` service.
- Conversely `Filesystem.php` imports `Pagekit\Routing\Generator\UrlGenerator` for its reference-type constants, so a naive "routing depends on filesystem" edge points the wrong way and risks a circular module requirement.
- **What each writer can actually reach today:**
  - `Installer` receives the whole `Application` (`app/installer/src/Installer.php:40` uses `$app->get('path')`) → can reach any service.
  - `SettingsController` receives `Request`, `ConfigManager` and a plain `string $configFile` (`app/system/modules/settings/src/Controller/SettingsController.php:18-23`; wired at `app/system/modules/settings/index.php:10`) → needs one more constructor argument.
  - `Composer` helper receives an array of paths plus an optional `OutputInterface` (`app/installer/src/Helper/Composer.php:36-48`) → has no container and no logger; whatever it needs must be passed in by `PackageManager`, which does hold `$this->app`.
  - `Router` has none of the above.

**The write sites in scope.**

| Site | Code | Atomic? | opcache? |
| --- | --- | --- | --- |
| Install config | `app/installer/src/Installer.php:208` — `file_put_contents($this->configFile, $configuration->dump())`, falsy → status `write-failed` + `BadRequestHttpException` | No | **No** |
| Admin settings config | `app/system/modules/settings/src/Controller/SettingsController.php:59` — return value ignored; `opcache_invalidate($file)` follows at `:65-67` | No | Yes (only site that does) |
| Package registry | `app/installer/src/Helper/Composer.php:226-229` `writeConfig()` — target `$config['path.packages'].'/packages.php'` (`:41`), read back via `require` in `readConfig()` (`:218-221`), called from `install()` (`:70`) and `uninstall()` (`:87`) | No | No |
| Router cache | `app/modules/routing/src/Router.php:442-468` | **Yes** | No (`require`d, not `require_once` — see `:177-179`) |

- All three targets are **PHP files loaded via `require`**: `Config::dump()` emits `'<?php return '.var_export(...).';'` (`app/modules/config/src/Config.php:124-127`) and `Composer::writeConfig()` emits the same shape. Opcache invalidation therefore belongs **in the primitive**, not scattered across callers — otherwise the installer keeps its silent gap and 2.5's `opcache.validate_timestamps=0` makes a successful rename invisible to the running process.

**The two error-handling sites.**

- `app/console/src/Commands/SelfupdateCommand.php`: `execute()` prints an error and returns `Command::FAILURE` (`:42-44`) behind a **live forward-debt marker** `// TODO: Step 5.6 (Marketplace & Extensions)` (`:39-41`). Lines `45-85` are the entire former body commented out, ending in `catch (\Exception $e) { … throw $e; }`. Rule 4 (delete over wrap): delete the commented block, **keep the TODO marker** — it documents work that still must happen. `getVersions()` (`:93-100`) and `download()` (`:105-114`) stay as live code.
- `app/installer/src/Helper/Composer.php:58-65`: `VersionParser::normalize($version)` inside `try`, `catch (\UnexpectedValueException $e) {}` — empty. Consequence: `addPackages()` (`:55`) already merged the package into `$this->packages`, and `composerUpdate()` (`:67`) runs regardless, so the package lands in `packages.php` but is never force-refreshed, with no diagnostic anywhere. The class has no logger; `PackageManager` does and already uses it (`app/installer/src/Package/PackageManager.php:198-207`).

**Infrastructure.**

- Logger: service id `log`, `Pagekit\Log\Logger` extends `Monolog\Logger`, `StreamHandler` on `{path.logs}/debug.log` = `tmp/logs/debug.log` (`app/modules/log/index.php:16-29`). File-based, no DB needed.
- Filesystem tests use the `FileUtil` trait (`app/modules/filesystem/src/Tests/FileUtil.php`) with **real** temp directories from `sys_get_temp_dir()`. There is **no vfsStream anywhere in the repo** — do not add it as a new dependency; provoke failures with real conditions (non-existent directory, a directory occupying the target path, read-only target directory, unwritable temp target).
- PHPStan baseline: no entries for `Filesystem.php`, `Router.php` or `SettingsController.php`. `Composer.php` has 6 (Composer API typing), `filesystem/index.php` 2 (`$this`/`$app` in closures). Do not add new baseline entries.
- **Out of scope write sites** (throwaway caches, dev tooling, temp files — do not touch): `app/modules/debug/src/DataCollector/RoutesDataCollector.php:62`, `app/modules/view/src/Asset/AssetManager.php:301`, `app/console/src/Commands/ExtensionTranslateCommand.php:240,247,259`, `app/installer/src/Controller/UpdateController.php:66`, `app/installer/src/Controller/PackageController.php:207`, `app/system/modules/mail/src/Message.php:104,178`.

---

## PRINCIPLES (hold across every checklist step)

- **One primitive, one owner** (DNA: one glue point per concern). When this step ends, exactly **one** temp+rename implementation exists in the tree. `Router::writeCache()` calls it or is deleted in favour of it — it is never a second copy.
- **No static state.** The primitive must be instantiable/injectable. A static utility class is a bridge, and bridges need a removal step; do not create one here.
- **No new dependency.** Everything needed (`tempnam`, `rename`, `chmod`, `umask`, `opcache_invalidate`) is in PHP core. No Symfony Filesystem component, no vfsStream, no Composer package.
- **Fail loudly.** The primitive throws when it cannot write. Boot-critical writers must react to failure (the installer already does; the settings controller currently ignores the return value — fix that). Never return a bare `false` that a caller can ignore.
- **Honest atomicity.** `rename()` is atomic only within one filesystem, and the Windows-lock fallback is a plain write. Document that limit in the method contract instead of implying a guarantee the fallback does not give.
- **Delete over wrap.** The commented-out `SelfupdateCommand` body goes; git history is the reference. Keep the forward-debt TODO.
- **Forward debt only.** No `Step 2.6` tags for work completed in this step; the only step reference that survives in code is the existing Step 5.6 marker.
- **Rule 3** — tree stays green after every checklist step; required CI job names stay stable.

---

## 0. SAFETY CHECKS

Before starting:

1. Branch up-to-date with `develop`.
2. Baseline green:

```bash
php -v
composer install --no-interaction --prefer-dist --no-progress
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

**IF ANY FAILS → STOP AND FIX** (or report the pre-existing failure) before touching write paths.

---

## 1. DISCOVERY

```bash
rg -n 'file_put_contents|fwrite\(|var_export' app/ packages/ --glob '!app/vendor/**'
rg -n 'writeCache|tempnam|@rename|LOCK_EX|opcache_invalidate' app/ --glob '!app/vendor/**'
rg -n 'configFile|config\.file|path\.packages' app/ --glob '!app/vendor/**'
rg -n "catch \(\\\\?UnexpectedValueException|catch \([A-Za-z\\\\]+ \\\$e\) \{\s*\}" app/ packages/ --glob '!app/vendor/**'
rg -n "'require'" app/modules/filesystem/index.php app/modules/routing/index.php app/modules/log/index.php
```

Resolve before writing code:

- **Where does the primitive live, and how does `Router` reach it?** `routing` does not require `filesystem`, and `Filesystem` references a routing class — decide the dependency direction deliberately. Options include a narrow writer collaborator injected into `Router` from `app/modules/routing/index.php`, adding a module `require`, or hosting the primitive where both can consume it without a cycle. Whatever you pick: no static access, no service locator inside `Composer`/`Router`, and only one implementation.
- **API shape:** method name (the PHASE description says `Filesystem::dumpAtomic()`), signature (target path, content, optional mode), return type (prefer `void` + exception over `bool`), and the exception type. Check whether existing callers can act on it.
- **Permissions:** the router uses `0666 & ~umask()`. Decide whether `config.php` (which may hold DB credentials) should get the same or a tighter mode, and whether the mode is a parameter. State the decision — do not silently loosen the current effective permissions of `config.php`.
- **Opcache:** confirm the invalidation call shape (`opcache_invalidate($file, true)` guarded by `function_exists`) and that centralizing it does not double-invalidate in the settings controller.
- **How the logger reaches `Composer`:** constructor injection from `PackageManager` vs. an existing collaborator; keep it a real dependency, not `$GLOBALS`/container lookup.
- **Installer env:** verify the primitive is available during install (env `installer`, `app/installer/app.php` boot chain) — `config.php` is written *before* a normal system boot, so the writer must be reachable at that point.

---

## 2. WORK

Architect: decompose into ordered, individually-green checklist steps. Suggested order: 2.1 (primitive + unit tests) → 2.2 (router refactor onto it) → 2.3 (config writers) → 2.4 (package registry) → 2.5 (error-handling hygiene) → mandatory `(XL)` review step. Sizing hint: 2.1 is the only step with real design weight; 2.5 is small.

### 2.1 The atomic-write primitive

- Temp file in the **target directory** (same filesystem — a system temp dir would make `rename()` a cross-device copy and lose atomicity), write, set permissions, `rename()` over the target.
- Keep the existing fallback for a blocked rename (Windows locks): direct write with `LOCK_EX`, clearly documented as non-atomic.
- Remove the temp file on **every** failure path.
- Invalidate the opcode cache for the target after a successful write.
- Throw on total failure; never return silently.
- Unit tests: fresh write, replacement of an existing file, resulting permissions, no temp-file residue after a provoked failure, exception on an unwritable target, and content integrity (`require` of the written file returns the expected array).

### 2.2 Router onto the primitive

- `Router::writeCache()` loses its own temp+rename body and delegates. The graceful-degradation behaviour of `getMatcher()`/`getGenerator()` stays exactly as it is; `RouterTest::testCorruptCacheFileFallsBackGracefully` must still pass unchanged.
- Do not touch cache freshness (`filemtime`) or the dumpers — those belong to Step 2.7.

### 2.3 Config writers

- Installer write and admin settings write both go through the primitive.
- The settings controller stops ignoring the outcome and stops carrying its own `opcache_invalidate` (now central).
- The installer's existing `write-failed` status/exception path must keep working — a failed config write still has to reach the user as a real error.

### 2.4 Package registry

- `Composer::writeConfig()` goes through the primitive. Since the file is read back with `require`, opcache invalidation matters here too.

### 2.5 Error-handling hygiene

- Log the rejected version constraint (package name + constraint) instead of an empty `catch`. Decide whether the package should also be skipped rather than silently written into the registry — if behaviour changes, say so in the branch doc and cover it with a test.
- Delete the commented-out former body in `SelfupdateCommand`; keep the Step 5.6 TODO marker and the live `error()`/`FAILURE` behaviour.

---

## 3. OUT OF SCOPE

- **Route cache freshness by content hash, and replacing the deprecated copied `PhpMatcherDumper` / `UrlGeneratorDumper`** → Step 2.7. This step only changes *how* the cache file is written, never *when* it is considered fresh or *what* is dumped.
- **Snapshots / backups before destructive package operations, restore, retention** → Step 2.7.1.
- **Re-enabling `self-update` and the release feed it needs** → the existing Step 5.6 marker plus the update system in Step 2.9. This step deletes dead commented code, it does not revive the command.
- **OpenWeatherMap API key and the env/secrets layer** → Step 2.5.
- **Throwaway cache, dev tooling and temp-file writes** — the sites listed under Current state stay as they are. A `file_put_contents` is not a defect by itself; only boot-critical and registry files need atomicity.
- **A general-purpose filesystem abstraction** (Flysystem-style adapters for writes, locking layer, transactional file API). One primitive, no framework.

---

## 4. TESTING

- **Per checklist step (Conductor Tester):** PHPUnit + PHPStan PASS/FAIL.
- **`test-writer` applies to every step here** — all of them change production PHP under `app/`. No step should be marked `test-writer: skip`.
- **Primitive coverage is the core of this ticket:** failure paths matter more than the happy path. Cover unwritable target directory, target path occupied by a directory, temp-write failure, and rename failure (fallback path) — with real filesystem conditions, since the repo has no vfsStream and none is to be added.
- **Regression coverage for the callers:** router cache still written and still degrades gracefully; a settings save still persists and still invalidates; a package install/uninstall still updates the registry.
- **E2E (final `(XL)` step):** the 3 `@ci` Playwright specs. The installation spec is the meaningful one here — it exercises the config write end to end.

---

## SUCCESS CRITERIA

- Exactly one atomic-write implementation exists; `Router::writeCache()` no longer carries its own copy, and no static state was introduced to share it.
- The primitive is unit-tested including failure paths, leaves no temp files behind, throws instead of failing silently, and invalidates the opcode cache for the written file.
- Install config, admin settings config and the package registry are all written through it; the settings controller no longer discards the write outcome and no longer holds its own opcache call.
- Route caching behaves exactly as before, including graceful degradation on a corrupt cache file; freshness logic and dumpers untouched.
- An invalid package version constraint produces a log entry naming package and constraint; no empty `catch` left at that site.
- The commented-out `SelfupdateCommand` body is gone; the Step 5.6 forward-debt marker and the disabled-command behaviour remain.
- No `Step 2.6` references in code or tests; PHPUnit + PHPStan green with no new baseline entries.

---

## NOTES FOR THE ARCHITECT

- **The dependency direction is the one real decision in this ticket.** `filesystem` has no `require` list and references a routing class; `routing` requires `kernel` + `filter` and constructs `Router` with a cache path only. Pick a shape that leaves both module manifests honest and does not need a static accessor to work. Record the choice in the ticket — the Verifier will check that no second implementation and no static bridge appeared.
- **Do not turn this into a filesystem-abstraction project.** The value is one small primitive plus four call sites. If the design grows a writer interface hierarchy, it is too big.
- **`tempnam()` in the target directory, not the system temp dir** — the current router code already gets this right; keep it. Cross-device `rename()` silently stops being atomic.
- **`config.php` may contain database credentials.** Whatever permission mode the primitive applies must not be looser than what that file effectively has today.
- **The atomicity story is incomplete without opcache.** A correct rename that opcache ignores is a bug the operator sees as "my setting did not save". Centralize the invalidation; do not leave it to callers.
- **Behaviour change flag:** if skipping invalid-constraint packages (rather than only logging) is the right call, treat it as a deliberate, tested change and note it in the branch doc — not as a silent side effect of adding a log line.
- One ticket / one PR; Conventional Commits; version bump once at Finalize — never inside checklist steps.
