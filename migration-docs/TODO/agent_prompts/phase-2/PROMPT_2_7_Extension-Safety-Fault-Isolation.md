# Step 2.7: Extension Safety & Fault Isolation

<!-- conductor-mode: full -->

**ROADMAP:** 2.7. GitHub Issue: #160. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.7.

---

## CONTEXT

- **Land after:** Step 2.6 (#257) — the DB-less fallback write reuses that atomic-write primitive instead of inventing a second one. If 2.6 has not merged, STOP and sequence correctly.
- **Provides:** the fault barrier, auto-disable and admin-notification seam that Snapshot & Three-Stage Uninstall (2.7.1), Module Dependency Integrity (2.7.2), Extension Packaging (2.8) and the Marketplace (5.6) all build on. Step 2.10.2 (Controller FQCN Autowiring) also waits on the static bridges removed here.
- **Risk:** Medium–High. This step edits the boot sequence itself, replaces the route dumpers, and removes three static bridges. Every one of those can break the whole application rather than one feature — which is exactly why it is decomposed into individually-green checklist steps.
- **Goal:** A faulty extension degrades to "this extension is off and the admin knows why", never to a dead site. Plus: remove the static bridges and the deprecated dumper copies that make the boot sequence order-dependent and hard to isolate.
- **Why:** Today a throwable outside `\RuntimeException` during extension load propagates through boot. The admin panel boots through the same sequence, so the one interface that could disable the offender dies with it. Even a caught failure only produces a log line — the extension stays enabled, the next request fails again, and nobody is told which extension is responsible. Before third-party extensions can be distributed at all (2.8/5.6), this has to hold.

### Current state (verified 2026-07-31 — confirm in Discovery, then build; do not rediscover blindly)

**Boot sequence, in order.**

1. `public/index.php` — `$env = 'system'` (`:21`), `$path = dirname(__DIR__)` (`:22`), runtime paths at `:40-52` (`path.temp` → `tmp/temp`, `path.cache` → `tmp/cache`, `path.logs` → `tmp/logs`, `path.artifact` → `tmp/packages`, `path.storage` → `storage`, `path.public` → `public/`). A `set_exception_handler` at `:25-38` appends uncaught exceptions to `tmp/logs/debug.log` — but **only if that file already exists**, so on a fresh installation the last-resort handler is not even registered.
2. `app/system/app.php:9-30` — autoload → `new App($config)` → `$app->get('module')->register([...])` → `addLoader(AutoLoader)` → `addLoader(ConfigLoader(app/system/config.php))` → optional `addLoader(ConfigLoader(config.php))` → `$app->get('module')->load('system')` → `$app->run()`.
3. `ModuleManager::register()` (`app/modules/application/src/Module/ModuleManager.php:125-158`) globs `packages/*/*/index.php`, `app/modules/*/index.php`, `app/installer/index.php`, `app/system/index.php` and does `include $file` at `:136` — **with no try/catch, and for every package on disk regardless of whether it is enabled.** A throwable at the top level of a *disabled* extension's `index.php` therefore still kills boot. This is the first barrier the design has to account for; the currently-known failure mode ("throwable in `main()`") is only the second one.
4. `ModuleManager::load()` (`:85-118`) — unknown name → `\RuntimeException("Undefined module: $name")` (`:96`); `resolveModules()` (`:197-218`) throws on circular requirements and **silently skips** an unregistered `require` entry (that silence is Step 2.7.2, not this step); pre- and post-loaders run per module, and `$this->modules[$name] = $module` happens only **after** every loader returned (`:114`).
5. `ModuleLoader::load()` (`app/modules/application/src/Module/Loader/ModuleLoader.php:24-58`) — closure form: `new Module($module)`, bind, `$callable($this->app)` (`:37`), then `events->subscribe($moduleObj)` (`:39`); class form: `new $class($module)` then `$instance->main($this->app)` (`:50-51`). No try/catch either way.
6. `SystemModule::main()` (`app/system/src/SystemModule.php:53-60`) — the **only** existing barrier:

   ```php
   foreach (array_merge($this->config['extensions'], (array) $theme) as $module) {
       try {
           $app->get('module')->load($module);
       } catch (\RuntimeException $e) {
           $module = ucfirst($module);
           $app->get('log')->error("[$module exception]: {$e->getMessage()}");
       }
   }
   ```

   Catches `\RuntimeException` only; logs the message without a stack trace; leaves the extension enabled. The theme goes through the same loop, and a missing theme module already degrades gracefully to a synthetic `theme-default` (`:62-71`, layout `views:system/blank.php`) — extensions have no equivalent.
7. `$app->run()` → `Application::boot()` fires the `boot` event → `app/system/index.php:84-96` registers the validator provider, calls `UniqueValidator::setDb()` and subscribes the `ExceptionListener` **only when `debug` is off** (`:92-93`).

   **Sequencing consequence:** extensions load in step 6, the `ExceptionListener` is subscribed in step 7. A throwable during extension load can therefore never be rendered by the application's own error page — with `debug` off it is a white screen.

**Partial state is a real hazard.** Because a module's services, listeners and locator entries are registered *inside* `main()` before it may throw, a failing extension can leave half of its wiring behind while never entering `$this->modules`. The barrier design must state what the surviving application looks like — silently continuing with half-registered services is not fault isolation.

**Activation state.**

- `extensions` (array) and `site.theme` live in the `system` config; defaults in `app/system/index.php:76-80`.
- `ConfigManager::set()` (`app/modules/config/src/ConfigManager.php:64-82`) writes to the DB **immediately** when the config is dirty (MySQL upsert, otherwise update-then-insert) against table `@system_config` (`app/modules/config/index.php:45`); the `terminate` event re-persists dirty configs (`app/modules/config/index.php:51-55`). So an auto-disable can take effect within the failing request — but it needs a working `db` service, which is exactly what may be broken.
- The DB-backed module config loader is only added when `config.file` exists (`app/modules/config/index.php:15-27`).

**Logging works without the database.** Service id `log`, `Pagekit\Log\Logger` extends `Monolog\Logger`, `StreamHandler` on `{path.logs}/debug.log` = `tmp/logs/debug.log`, directory created on demand (`app/modules/log/index.php:16-29`). `ErrorHandler::register()` runs at `app/modules/application/index.php:23`.

**Where a fallback file may live.** `CacheModule::doClearCache()` (`app/system/modules/cache/src/CacheModule.php:144-170`) deletes `*.cache` in `path.cache`, and with `['temp' => true]` deletes **everything at depth 0 in `tmp/temp`** (`ignoreDotFiles(true)`). `Installer::install()` triggers a cache clear (`app/installer/src/Installer.php:218`). A disable record dropped into `tmp/temp` would be swept away by a routine admin "clear cache" action, silently re-enabling a broken extension. It must also never live under `storage/`, which is the public media root mounted into `public/` — a fallback file there would be readable over HTTP and disclose which extensions are broken.

**Package lifecycle.**

- `PackageScripts` (`app/installer/src/Package/PackageScripts.php`) — hooks `install`, `uninstall`, `enable`, `disable`, `updates` (version-keyed); `load()` does `require $this->file` (`:85-92`); `run()` is `array_map(… call_user_func($script, $this->app) …)` (`:97-106`) with **no try/catch**; `$app` is a `ContainerInterface`.
- `PackageManager::enable()` (`app/installer/src/Package/PackageManager.php:128-220`) — already wraps everything in `try/catch (\Throwable)`, calls `rollbackEnable()` (`:227-252`), logs with exception context (`:198-207`) and rethrows a `\RuntimeException`. **`rollbackEnable()` restores config only** (version, `extensions` membership, `site.theme`) — the migrations a failed install already applied are **not** rolled back. That gap is this step's install-rollback item.
- `PackageManager::disable()` (`:257-274`) — no try/catch at all: `getScripts($package)->disable()`, `package.disable` event, then pull from `extensions`.
- `MigrationService` (`app/modules/migration/src/MigrationService.php`) — `migrate()` (`:145-203`) returns `['success' => false, 'error' => …]` without rolling back; `rollback()` (`:212-300`) and `rollbackExtension()` (`:579-665`) exist and are usable.
- The tree contains exactly **one** `scripts.php`: `packages/pagekit/blog/scripts.php`. `install` and `enable` both call `$app->get('migration')->migrateExtension('Pagekit\\Blog\\Migrations', __DIR__.'/src/Migrations')` and throw `\RuntimeException` when `success` is falsy; `uninstall` clears the cache; `updates` is empty. This is the reference migration target for the lifecycle interface.

**Routing dumpers.**

- `app/modules/routing/src/Matcher/Dumper/PhpMatcherDumper.php` — copy of Symfony's `PhpMatcherDumper` (deprecated since 4.3), extends `CompiledUrlMatcherDumper`, overrides `dump()` to emit a legacy class.
- `app/modules/routing/src/Generator/UrlGeneratorDumper.php` — copy of the old generator dumper, extends `GeneratorDumper`.
- Consumed at `Router.php:171` (matcher) and `:212` (generator). The dumped classes take only a `RequestContext`, so `Router` instantiates them through reflection (`instantiateMatcher()` `:239-252`, `instantiateGenerator()` `:260-273`) and both call sites degrade to the non-cached path on `\Throwable` (`:168-189`, `:209-231`).
- Freshness: `getCache()` (`:404-433`) — cache key is `sha1(serialize($resource).serialize($options))`, freshness is `filemtime($file) >= $this->resource->getModified()`. **Content-hash freshness is this step's item.**
- The cache **write** is a single primitive after Step 2.6 — do not reintroduce write logic here.

**Static bridges and why they exist.**

| Bridge | Static state | Wiring | DI blocker |
| --- | --- | --- | --- |
| `packages/pagekit/blog/src/UrlResolver.php` | `static ?CacheItemPoolInterface $cache`, `static ?Module $module`, `static ?PostRepository $posts` (`:27-29`), setters `:32-47`, static `getPermalink()` `:159-172`, `\LogicException` guards `:90`/`:119` | `packages/pagekit/blog/index.php:169-171` | `Router::getResolver()` does `new $resolver()` at `Router.php:484` — resolvers get no constructor arguments |
| `packages/pagekit/theme-one/functions.php` | `ThemeOneHelpers::$url` — `private static ?UrlProvider $url` (`:12`), `setUrl()` `:14`, `getUrl()` `:19` throwing at `:22`, consumed by the `image()` helper (`:121`) | `packages/pagekit/theme-one/index.php:16` | The helpers are plain functions in a `require`d file — there is no instance to inject into. Note: this is **not** a static `UrlProvider`; `app/modules/application/src/Application/UrlProvider.php` itself is a normal DI-wired class with no static state |
| `app/system/src/Validator/Constraints/UniqueValidator.php` | `private static mixed $db` (`:22`), `setDb()` (`:24`) | `app/system/index.php:90` (inside the `boot` event) | `ValidatorServiceProvider::register()` (`app/system/src/ValidatorServiceProvider.php:28-41`) builds the validator with `Validation::createValidatorBuilder()`, i.e. Symfony's default `ConstraintValidatorFactory` → `new $class()`. No custom factory exists in the tree |

`RouteListener` (`packages/pagekit/blog/src/Event/RouteListener.php`) calls `UrlResolver::getPermalink()` at `:29` (feeding `$this->router->setOption('blog.permalink', …)`, which is part of the cache key) and at `:42` (permalink alias routes). Both call sites move with the bridge removal.

**Admin messages.**

- `MessageBag` (`app/modules/session/src/MessageBag.php`) extends `AutoExpireFlashBag`; levels `debug`/`info`/`warning`/`error`/`success` (`:14-35`) with helpers (`:43-66`). Service id `message` (`app/modules/session/index.php:26`), registered as a session bag (`:21`).
- Rendered by the `view.messages` handler (`app/system/index.php:144-159`) as `.pk-system-messages` markup, turned into UIkit notifications by `app/system/modules/theme/app/views/theme.js:32` and `app/system/app/lib/notify.js:4`; the login view checks for messages at `app/system/modules/theme/app/views/login.js:11-12`.
- **Design trap:** the flash bag is per-session and auto-expires. A boot failure typically happens on an anonymous frontend request, so writing the flash there notifies a visitor, not the admin — and it is consumed before any admin ever logs in. The notice has to be derived from durable state (the disable record) on the next admin request, not from a flash written at failure time.

**Tests and infrastructure.**

- **No PHPUnit tests exist** for `ModuleManager`, `PackageManager`, `PackageScripts`, `UniqueValidator`, or either dumper. Everything this step touches in those files is currently uncovered.
- `tests/Unit/Blog/UrlResolverTest.php` — 8 tests driven **through the static setters** (`:68`, `:88`, `:106`, `:125`, `:136-143`). They must be rewritten with the DI change, not deleted.
- `app/modules/routing/src/Tests/RouterTest.php` — 11 tests including `testCorruptCacheFileFallsBackGracefully` (`:240-273`). Dumper replacement must keep them green.
- PHPStan baseline entries in the affected area: `packages/pagekit/blog/src/UrlResolver.php` (2), `app/modules/migration/src/MigrationService.php` (4), `app/system/index.php` (2 — `$this`/`$app` in closures). Removing a bridge may make a baseline entry obsolete — drop it, never regenerate the baseline.
- `@ci` Playwright specs: `tests/e2e/specs/01-setup/installation.spec.js`, `tests/e2e/specs/02-core/authentication.spec.js`, `tests/e2e/specs/02-core/dashboard.spec.js`.

---

## PRINCIPLES (hold across every checklist step)

- **The admin panel must survive any extension.** That is the single acceptance test behind everything here. If a design keeps the frontend alive but not `/admin`, it has failed.
- **Recovery must not depend on the thing that broke.** Logging is file-based, the disable path has its own error handling, and the fallback works with no database. Never let the recovery path throw a second exception on top of the first.
- **Fail visibly, not silently.** An auto-disabled extension is an event with a name, a stack trace and an admin-facing message. Continuing quietly with a half-loaded application is the bug being fixed, not an acceptable fallback.
- **Reuse the 2.6 primitive.** The fallback file is written with the shared atomic write. No second temp+rename implementation, no plain `file_put_contents` for state that boot depends on.
- **The fallback file is private state.** Never under `storage/` (HTTP-reachable through the `public/` mount), and never in a location a routine cache/temp clear sweeps.
- **Delete over wrap for the bridges.** When a bridge is removed, its static setters, its boot wiring and its call sites go in the same checklist step — no "new DI path next to the old static path".
- **No behaviour change to blog permalinks.** The dumper swap and the resolver DI change are internal; URLs, permalink aliases and their cache-key participation stay exactly as they are.
- **Rule 3** — the tree stays green after every checklist step; required CI job names stay stable. A half-migrated boot sequence is not a valid intermediate state.

---

## 0. SAFETY CHECKS

Before starting:

1. Branch up-to-date with `develop`.
2. Confirm **Step 2.6 has landed** (or this branch includes the atomic-write primitive) — the fallback write depends on it.
3. Baseline green:

```bash
php -v
composer install --no-interaction --prefer-dist --no-progress
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
pnpm install --frozen-lockfile && pnpm build
```

**IF ANY FAILS → STOP AND FIX** (or report the pre-existing failure) before touching the boot sequence.

---

## 1. DISCOVERY

```bash
rg -n 'catch \(\\?RuntimeException|catch \(\\?Throwable' app/ packages/ --glob '!app/vendor/**'
rg -n 'include \$file|require \$this->file|->main\(' app/modules/application/src/Module/ --glob '!app/vendor/**'
rg -n "extensions|site\.theme" app/system/src/SystemModule.php app/system/index.php app/installer/src/Package/PackageManager.php
rg -n 'getResolver|_resolver|new \$resolver' app/modules/routing/src/ packages/pagekit/blog/src/
rg -n 'PhpMatcherDumper|UrlGeneratorDumper|CompiledUrlMatcher|CompiledUrlGenerator' app/ --glob '!app/vendor/**'
rg -n 'setDb\(|setCache\(|setModule\(|setPostRepository\(|setUrl\(' app/ packages/ --glob '!app/vendor/**'
rg -n "get\('message'\)|MessageBag|view\.messages" app/ --glob '!app/vendor/**'
rg -n 'path\.temp|path\.cache|path\.logs|doClearCache' app/ public/index.php --glob '!app/vendor/**'
```

Resolve before writing code:

- **Which failure windows are in scope.** Registration (`include` of every `index.php` on disk, enabled or not), module load / `main()`, and lifecycle script execution are three distinct windows with different recovery options. State explicitly which ones the barrier covers and what happens in each. A fatal parse error in a `require`d file cannot be caught at all — decide how that case is handled (or accepted and documented).
- **Where the disable record lives**, given that `tmp/temp` is swept at depth 0 by `doClearCache(['temp' => true])` and `tmp/cache` loses `*.cache`. Confirm the chosen location is created when missing, is writable in the Docker dev stack, and survives both clear paths.
- **What the record contains** — extension name plus enough context to render the admin message and to clear it on re-enable. Keep it minimal; this is boot-critical state, not a log.
- **How the admin notice is derived** on the next admin request (durable state → message), given the flash bag's per-session auto-expiry.
- **How the router gets resolvers with dependencies** (`Router.php:484`). Whatever the seam is, it must also carry the `blog.permalink` option flow at `RouteListener.php:29`/`:42` unchanged, and it must not reintroduce static state.
- **Whether the compiled Symfony matcher/generator can be consumed without the copied dumpers**, what that changes about the dumped file shape, and therefore about the reflection-based instantiation at `Router.php:239-273`.
- **How the container reaches the validator factory** so `UniqueValidator` can take its connection through the constructor.
- **Blog lifecycle migration:** what the lifecycle interface has to expose so `packages/pagekit/blog/scripts.php` can be replaced without changing install/enable/uninstall behaviour, and whether `PackageScripts` stays for compatibility with packages that still ship a script file or is replaced outright (Rule 1 says do not keep both paths alive without a reason — state the reason if you keep it).
- **Install rollback:** which migration-rollback entry point (`rollback()` / `rollbackExtension()`) `PackageManager::enable()` should call on throwable, and how to avoid rolling back migrations that were already applied before this install attempt.

---

## 2. WORK

Architect: decompose into ordered, individually-green checklist steps. Suggested order — each group is at least one step, the big ones more:

1. **Fault barrier** — `\Throwable` around extension/theme load and registration; partial-state handling; no behaviour change yet beyond surviving.
2. **Failure logging** — extension name plus stack trace through the file logger, identifiable as an extension failure.
3. **Auto-disable + DB-less fallback** — DB path with its own error handling, fallback write via the 2.6 primitive, boot reads both sources, re-enable clears both.
4. **Admin notification** — durable record surfaced on the next admin request; re-enable path in the extension manager.
5. **Lifecycle interface + blog migration + install rollback** — includes wrapping lifecycle script execution in the barrier.
6. **Routing dumper replacement + content-hash freshness** — permalink behaviour and `RouterTest` unchanged.
7. **Static bridge removal** — resolver DI seam (unblocks `UrlResolver`), theme helper seam, container-aware validator factory. Each bridge is its own step: setters, wiring and call sites in one commit, tests rewritten with it.
8. **Mandatory final `(XL)` step** — Review (Bugbot + Security) + E2E.

Sizing hints: (1) and (3) carry the design weight; (6) and (7) are the most likely to need a fix-loop; (2) and (4) are small once (1) and (3) exist.

### Notes per group

- **(1) Barrier:** the goal is not "wrap everything in `try/catch`" — it is a defined degradation. Decide per window what "degraded but consistent" means, and make the theme fallback (`SystemModule.php:62-71`) and the new extension fallback behave coherently.
- **(3) Auto-disable:** the DB write is immediate (`ConfigManager::set()`), so the disable can land in the failing request when the database is healthy. When it is not, the fallback file is the only record — it must be authoritative on the next boot, which means boot has to merge both sources *before* the extension list is used.
- **(4) Notification:** the message names the extension and points at the log. It must be clearable (re-enable) so it does not haunt the panel forever.
- **(5) Install rollback:** `PackageManager::enable()` already restores config state on throwable; the missing half is the migration rollback. Do not duplicate the existing rollback — extend it.
- **(6) Dumpers:** the two copies are the last vendored Symfony classes in the routing module. Content-hash freshness replaces the `filemtime` comparison; the cache key composition (`Router.php:417-418`) and the graceful-degradation `catch` blocks stay.
- **(7) Bridges:** `tests/Unit/Blog/UrlResolverTest.php` drives the resolver through static setters — rewrite those tests as part of the same step, not afterwards.

---

## 3. OUT OF SCOPE

- **Snapshots / backups before destructive package operations, restore, retention, three-stage uninstall** → Step 2.7.1.
- **Making the module dependency graph fail closed, circular-requirement detection at validation time, `requiredBy` reverse index, pre-flight for destructive operations** → Step 2.7.2. In particular: the silent skip of an unregistered `require` entry in `resolveModules()` (`ModuleManager.php:209-211`) stays as it is here.
- **Extension package shape, prebuilt assets, author-side build preset, publishing package assets into `public/`** → Step 2.8.
- **Marketplace, catalogue, remote distribution, package signing / integrity checks** → Step 5.6.
- **Sandboxing extension PHP execution** (restricted runtime, process isolation) — permanently out of scope; the barrier is about fault isolation, not security isolation against malicious code.
- **Fixing the extension's own bug** — the CMS survives it; the author fixes it.
- **Controller FQCN autowiring** → Step 2.10.2, which waits on the bridges removed here.
- **`User::evaluateBooleanExpression()` extraction** — only if a second caller appears; there is none.
- **Atomic-write primitive design** → Step 2.6. Consume it here; do not extend or fork it.

---

## 4. TESTING

- **Per checklist step (Conductor Tester):** PHPUnit + PHPStan PASS/FAIL.
- **`test-writer` applies to every step** — all of them change production PHP. No `test-writer: skip`.
- **This area starts from zero coverage.** `ModuleManager`, `PackageManager`, `PackageScripts` and the dumpers have no tests at all, so the barrier and auto-disable paths need tests written from scratch — a fixture extension that throws is the natural instrument. Cover at minimum: throwable in `main()`, throwable in a lifecycle script, DB write failure on auto-disable (fallback taken), fallback record honoured on the next boot, and re-enable clearing both sources.
- **Regression coverage for what must not change:** `RouterTest` (all 11, including corrupt-cache degradation) after the dumper swap; the rewritten `UrlResolverTest` after the DI change; permalink and permalink-alias routing.
- **Frontend:** the extension manager gains a visible state (auto-disabled) and the admin notice — run `pnpm lint` and `pnpm exec prettier --check .` for any JS/Vue touched; both are blocking in CI.
- **E2E (final `(XL)` step):** the 3 `@ci` specs. Authentication and dashboard are the meaningful ones — they prove the admin panel still boots.
- **Manual verification is expected for the true white-screen scenario** (drop a deliberately broken extension into `packages/`, hit the frontend, confirm the admin panel is reachable and the extension is disabled). If an agent cannot do this reproducibly, record it under `## Maintainer action` in the branch doc — never report it as automated.

---

## SUCCESS CRITERIA

- A throwable of any type raised while an extension loads leaves both the frontend and `/admin` reachable; no white screen with `debug` off.
- The failure is in `tmp/logs/debug.log` with the extension name and a full stack trace, also when the database is unreachable.
- The offending extension is disabled automatically — in the database when it is healthy, in the private fallback record when it is not — and boot honours both sources.
- The fallback record is not reachable over HTTP and survives a routine cache/temp clear.
- The admin sees a message naming the disabled extension, and re-enabling it after a fix clears both the record and the message.
- A failed install no longer leaves migrations applied: config state *and* schema are rolled back together.
- The blog package runs through the lifecycle class instead of `scripts.php`; install, enable and uninstall behave as before.
- Permalinks, permalink alias routes and route caching behave exactly as before, with cache freshness decided by content hash instead of `filemtime`.
- No copied Symfony dumpers left in the routing module.
- No static setter wiring left for the blog URL resolver, the theme URL helper, or the unique validator; `app/system/index.php:90` and `packages/pagekit/blog/index.php:169-171` are gone, and the rewritten tests do not use static setters.
- No `Step 2.7` references in code or tests; PHPUnit + PHPStan green with no new baseline entries, and obsolete baseline entries removed rather than regenerated.

---

## NOTES FOR THE ARCHITECT

- **This is the biggest step in Phase 2 so far. Decompose aggressively.** Fault barrier, auto-disable, dumper replacement and each static bridge are independent enough to land separately and green — bundling them produces one unreviewable diff over the boot sequence.
- **The registration window is the finding that changes the design.** `ModuleManager::register()` includes *every* `index.php` under `packages/*/*/` before anything is enabled, so "disable the extension" does not protect against a throwable at that level. Decide deliberately whether registration is inside the barrier, whether the disable record is consulted before the glob, or whether that window is accepted and documented. Do not let it be discovered during implementation.
- **The `ExceptionListener` is subscribed after extensions load.** Any error-page-based recovery idea will not work for boot failures. The barrier has to be the recovery mechanism, not the error page.
- **Do not notify through the flash bag at failure time.** It is per-session, auto-expiring, and the failing request is usually an anonymous frontend one. Derive the notice from durable state.
- **`tmp/temp` is not a safe home for the disable record** — `doClearCache(['temp' => true])` empties it at depth 0, and the installer triggers a cache clear. Choose the location so that a routine admin action cannot silently re-enable a broken extension.
- **`PackageManager::enable()` already has the throwable barrier and a config rollback.** Extend it for migrations; do not write a second rollback path next to it.
- **The bridges have different blockers.** The resolver needs a construction seam in the router; the theme helpers need an injectable seam for template-level functions (plain functions, no instance); the validator needs a container-aware factory. Do not assume one mechanism solves all three, and do not remove a bridge before its blocker is actually gone — a bridge replaced by a service locator is still a bridge.
- **The permalink cache key is behavioural.** `RouteListener` feeds `blog.permalink` into the router options, and those options are part of the cache key (`Router.php:417`). Any change in when or how that value is set changes cache identity — cover it with a test rather than reasoning about it.
- One ticket / one PR; Conventional Commits; version bump once at Finalize — never inside checklist steps.
