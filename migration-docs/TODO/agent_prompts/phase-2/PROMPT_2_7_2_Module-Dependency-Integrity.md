# Step 2.7.2: Module Dependency Integrity

<!-- conductor-mode: full -->

**ROADMAP:** 2.7.2. GitHub Issue: #268. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.7.2.

---

## CONTEXT

- **Land after:** Step 2.7 (#160) — fail-closed activation and disable/uninstall pre-flight reuse the auto-disable / admin-notice seam — and Step 2.7.1b (#287), so the declared graph and the code graph agree before anything fails closed on them. Step 2.7.1 (#267) has landed: uninstall is snapshot-first and `PackageController::uninstallAction` carries the forward-debt tag for this step. Step 2.7.1a (#281) has landed: a MySQL restore fills `_r_` copies and swaps them in with one `RENAME TABLE`, refuses before it creates anything, and runs under a `GET_LOCK` taken inside its preflight. Step 2.7.1c (#297) has landed: there is one install path — `Pagekit\Package\Archive\PackageArchive::open()` refuses an archive before anything is written and `PackageManager::install()` places the tree — shared by the panel upload and `php pagekit install <archive>`; a package is what its `composer.json` (`name`, `type`, `version`, `title`) and its module `index.php` (`name`, `autoload`) say, both read out of the archive without running anything — nothing runs Composer and there is no `installed.json` — and `enable()` / `disable()` are reached from the panel alone. If 2.7.1b has not merged, STOP and sequence correctly.
- **Provides:** an honest bidirectional dependency graph and a pre-flight answer for destructive package ops. Sub-Extension operator activation (5.0) must not ship while unsatisfied requirements can still be skipped silently. Prefer landing **before** Static Module Registration (2.7.3) so the graph hardens against today's registration model first.
- **Risk:** Low–Medium. One resolver behaviour change plus a read-only graph. Fail-closed can strand an installation whose manifests were already inconsistent — failures must name the missing module.
- **Goal:** Activation never leaves a half-wired application because a `require` was skipped; disable/uninstall never run blind; "what depends on this?" is answerable without scanning ad hoc at call time.
- **Why:** `ModuleManager::resolveModules()` silently skips an unregistered `require` entry. `load()` throws only for a directly requested unknown name. `PackageManager::disable()` performs no dependency check. That is tolerable while `require` lists are maintained in core code; it becomes a fault source the moment operators activate and deactivate modules themselves (5.0) or third-party packages declare dependencies (2.8 / 5.6).

### Current state (verified 2026-08-10 — confirm in Discovery, then build; do not rediscover blindly)

**Resolver — silent skip.**

- `ModuleManager::resolveModules()` (`app/modules/application/src/Module/ModuleManager.php:197-218`):
  - Circular: if `$required` is in `$unresolved` → `\RuntimeException` with `Circular requirement "%s > %s"` (`:205-207`).
  - Present: if `$required` is in `$this->registered` → recurse (`:209-210`).
  - **Absent: if not registered → no log, no throw, continue.** The depender still enters `$resolved` (`:216`).
- `ModuleManager::load()` (`:85-118`) — unknown **directly requested** name → `\RuntimeException("Undefined module: $name")` (`:95-97`). That is fail-loud for the top-level name only; it does not fix the silent skip inside `resolveModules()`.

**Boot still loads extensions/theme through SystemModule.**

- `SystemModule::main()` merges `extensions` + `site.theme` and loads each (post-2.7: behind `\Throwable` barrier + auto-disable). A missing dependency that was silently skipped surfaces later as a missing service — or, after 2.7, as an opaque load failure that does not name the missing `require`.

**Activation registry is two keys.**

- Enabled extensions: `system` config `extensions` (array). Theme: `site.theme`.
- Written by `PackageManager::enable()` / `disable()` (`push` / `pull` on `extensions`; `set`/`remove` on `site.theme`). Persisted via `ConfigManager` to `@system_config`.
- A disable check that reads **only** `extensions` will happily disable a module the **active theme** requires and break the frontend. Both keys are dependents.

**PackageManager::disable() — no graph check.**

- `app/package/src/PackageManager.php:375` `disable()` — scripts → event → pull from `extensions`. Zero dependency validation.
- `PackageController::disableAction()` / uninstall UI likewise have no pre-flight.

**Declared edges and imports disagree in one place.**

- `app/package/index.php` `require` names `application`, `migration`, `system/intl`, `system/view`. Every other `Pagekit\` namespace the module imports is `application`'s own (`Pagekit\Application`, `Pagekit\Module`, `Pagekit\Util` — the root PSR-4 entry), directly declared (`Pagekit\Migration`) or reached through `application`'s own `require` (`filesystem`, `routing`, `database`, `log`) — except `Pagekit\User\Attribute\Access` on `app/package/src/Controller/{PackageController,SnapshotController}.php`: no edge, direct or transitive, reaches `system/user`. The attribute resolves lazily at dispatch, so load order never fails on it and nothing but a test that reads imports and manifests together can see it.

**Manifest `require` examples.**

- Core: `app/system/index.php` and module manifests under `app/modules/*/index.php` declare `require` arrays (e.g. routing → `kernel`, `filter`).
- First-party packages: `packages/pagekit/blog/index.php` and `packages/pagekit/theme-one/index.php` currently have **no** `require` key — the graph must still be correct for core, and ready for packages that will declare dependencies under 2.8 / 5.6.

**Owned tables / settings — not declared.**

- Table ownership is implicit (ORM `tableClass`, migrations). Config ownership is implicit (`@system_config` keys by module name). Pre-flight "data risk" must derive from what exists (migrations registry, known config keys, node types) without inventing a second metadata system — or declare a minimal optional manifest field and migrate first-party packages deliberately. Do not block the graph on a perfect ownership schema.

**Admin UI.**

- Extensions/themes lists show name, toggle, version, folder, settings, permissions, uninstall — **no** dependency column, **no** "required by" warning before disable/uninstall.
- Step 2.7 admin notice seam (durable disable record → message) is the channel for fail-closed activation failures that auto-disable or refuse enable — reuse it; do not invent a second flash path.

**Tests.**

- No dedicated PHPUnit coverage for `resolveModules()` silent-skip or disable pre-flight today. `PackageLifecycleWiringTest` covers node lifecycle only.

---

## PRINCIPLES (hold across every checklist step)

- **Fail closed, name the missing module.** An unsatisfied `require` must refuse activation or disable the depender with an explicit reason — never a silent skip.
- **Bidirectional.** `require` (forward) and `requiredBy` (reverse) are both first-class; destructive ops query reverse.
- **Theme is a dependent.** `site.theme` counts like an enabled extension when asking "who needs this module?".
- **Reuse 2.7 notice / disable seams.** Do not add a parallel messaging or auto-disable mechanism.
- **Pre-flight is one query.** Admin UI and API consume the same result: blockers, orphans, data-risk hints.
- **Do not implement 5.0 cleanup here.** No install-reason bookkeeping, no automatic orphan removal — only the information and the refuse/block seams those features will need.
- **Do not rewrite discovery.** Static manifests are 2.7.3; this step works with today's registered module arrays.
- **Rule 3** — tree stays green after every checklist step; required CI job names stay stable.

---

## 0. SAFETY CHECKS

Before starting:

1. Branch up-to-date with `develop`.
2. Confirm **Step 2.7 has landed** (barrier + admin notice seam available).
3. Baseline green:

```bash
php -v
composer install --no-interaction --prefer-dist --no-progress
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
pnpm install --frozen-lockfile && pnpm build
```

**IF ANY FAILS → STOP AND FIX** (or report the pre-existing failure) before changing the resolver.

---

## 1. DISCOVERY

```bash
rg -n 'resolveModules|Circular requirement|Undefined module' app/modules/application/src/Module/ --glob '!app/vendor/**'
rg -n "'require'" app/modules/*/index.php app/system/index.php packages/pagekit/*/index.php
rg -n 'function disable|pull\('\''extensions|site\.theme' app/package/src/ app/system/src/ --glob '!app/vendor/**'
rg -n 'extensions\.php|disableAction|uninstallAction|package-manager' app/package/ --glob '!app/vendor/**'
rg -n "'require'" -A 12 app/package/index.php; rg -n '^use Pagekit\\' app/package/src/ app/system/src/ app/installer/src/ --glob '!app/vendor/**'
rg -n 'MessageBag|auto-disable|view\.messages' app/system/ app/modules/session/ --glob '!app/vendor/**'
rg -n 'ArchiveRefusedException|function open|function install|function enable|function disable' app/package/src/Archive/ app/package/src/PackageManager.php app/console/src/Commands/ --glob '!app/vendor/**'
```

Resolve before writing code:

- **Fail-closed policy on boot vs. on enable.** When an already-enabled module has an unsatisfied `require` after upgrade: refuse boot of that module + auto-disable via 2.7, or hard-fail the request? Prefer surviving admin (2.7 principle) — state the choice.
- **Inactive vs. unregistered.** A `require` on a registered-but-disabled module is different from a missing package — both must be explicit in errors.
- **`requiredBy` derivation** — computed from registered manifests at boot or on demand; caching rules; invalidation when packages are enabled/disabled.
- **Declared edges match imports** — a graph that fails closed is only worth having when it is complete, and an edge nobody declared is a dependency the rules cannot see. Decide whether an edge reached through another module's `require` (the package module reaches `filesystem`, `routing`, `database` and `log` through `application`) counts as declared, and how the test reads a module's `use Pagekit\…` imports and its manifest together; attribute classes count, because they resolve lazily and never fail at load time. The `package` module's `Pagekit\User\Attribute\Access` import with no `system/user` edge is the first case it closes — declare the edge or re-home the attribute, but state which.
- **Circular detection at validation time** — keep boot detection; add the same check when enabling / validating a package so operators see it before activation.
- **Requirements at the archive check** — `PackageArchive::open()` is the refusal list an archive meets before anything is written (manifest shape, entry names, autoload folders) and `PackageManager::install()` checks the target; a manifest `require` naming a module the installation does not have is on neither, so the tree lands and the problem surfaces at activation or boot, one request after the upload. Decide where the requirement check sits (the archive's `index.php` `require` is readable without running it, the way `autoload` already is) and that it names the missing module; the panel and the console share the one install path, so one check covers both.
- **Activation has one caller** — `enable()` / `disable()` are reached from the panel alone; `php pagekit install <archive>` places the tree and runs the install lifecycle and no console command switches a package on or off, so a host without panel access can install but not activate. Decide whether the console gets `enable` / `disable` through the same fail-closed validation and pre-flight (one seam, two callers), or the panel stays the one activation surface and the install command's help says so.
- **Data-risk signal** — minimal honest heuristic (has migrations? has config rows? has node types?) without a full ownership schema if that would bloat the step.
- **UI/API shape** — one pre-flight DTO/array for disable and uninstall; where the extensions Vue surfaces blockers.
- **Interaction with 2.7.1 (landed)** — pre-flight runs before the snapshot-first uninstall and before **restore**: a restore is a whole-database revert (in place on SQLite, copies and one rename on MySQL), so compare the snapshot's recorded application version and the dump's `packages.*` version keys against the running installation and warn or refuse by name (core re-migrates on the next admin request, extensions only on `enable()`). `PackageSnapshotter::details()` must record the application version for that — metadata only, no dump-format change. Do not own snapshot creation here.
- **Interaction with 2.7.1a (landed)** — the restore pre-flight also carries what `DatabaseRestorer` would refuse on MySQL (no table prefix, a copy name past 64 characters, a reserved `_r_`/`_b_` name in the way that is not this installation's, inbound foreign keys from tables outside the dump, another restore holding the lock), and it is answered **before** `PackageSnapshotter::restore()` reinstates the package files: today `reinstate()` runs first, so a refusal leaves `packages/<vendor>/<name>` restored with the database untouched, and the `GET_LOCK` covers the database half only. Those refusals are private to `restore()` — decide the query form (`preflight` as an answer, not only an exception) and how a refusal an operator acts on is told from an internal failure; `SnapshotController::failed()` reports both with one fixed line.
- **Prefix comparison on a folding server** — `DatabaseDumper::schema()` and `DatabaseRestorer::name()` compare the prefix byte for byte while the restore's collision and ownership checks fold per `lower_case_table_names`; an installation created with an uppercase-lettered prefix (`TablePrefix` admits `PK_`) dumps no tables on a server that stores names folded, and `PackageSnapshotter::create()` marks that dump whole. Fold both comparisons as `comparable()` does, and refuse a dump that selected no table at dump time. Decide whether the install shape narrows to lower case as well (a wizard rule; state it if taken).
- **Ownership answer is for later consumers** — report which tables a module owns; package-scoped restore and purge-with-tables consume it under 5.0. Do not build either.

---

## 2. WORK

Architect: decompose into ordered, individually-green checklist steps. Suggested order:

1. **Fail closed in `resolveModules()`** — unregistered / unresolvable `require` throws (or returns a structured failure the loader handles) naming the missing module; no silent skip. Boot path stays admin-safe via 2.7 barrier.
2. **Activation-time validation** — enable / validate refuses unsatisfied or circular requirements with the same naming; the archive check refuses an upload whose `require` the installation cannot satisfy before a file is written, panel and `php pagekit install` alike. Settle the console `enable` / `disable` question here (same seam, or panel-only with the install command's help saying so).
3. **Reverse index `requiredBy`** — derived API used by PackageManager and controllers.
   - **Declared edges match imports** — a test reads every core module's `use Pagekit\…` imports and its manifest `require` together and fails on an import no declared edge reaches; attribute classes included. It closes the `package` module's undeclared `system/user` edge (`Pagekit\User\Attribute\Access` on both of its controllers) and then holds for every module the graph is about to fail closed on.
4. **Theme-as-dependent** — disable/uninstall of anything `site.theme` requires is blocked or warned per the chosen policy (block is safer for "must not break frontend").
5. **Pre-flight for disable/uninstall/restore** — single query: active dependents (blockers), would-be orphans, data-risk hints; for restore, the version comparison against the snapshot metadata and what the MySQL restorer would refuse, answered before `PackageSnapshotter::restore()` puts any file back; wire into `PackageManager` / `PackageSnapshotter` + controllers before mutation. The dumper's and restorer's prefix comparison folds like the collision checks, and a dump that selected no table is refused when taken.
6. **Admin UX** — show blockers in the extensions/themes UI; disable/uninstall cannot ignore a blocking dependent without an explicit, tested escape hatch (prefer no escape hatch).
7. **Mandatory final `(XL)` step** — Review (Bugbot + Security) + E2E.

Sizing hints: (1) and (5) carry behaviour risk; (6) is the UX fix-loop; (2)–(4) are focused once the resolver contract exists.

### Notes per group

- **(1) Behaviour change:** existing installs with inconsistent manifests will start failing loudly — that is intended. Error text must be actionable (which module, which missing require).
- **(4) Theme:** treating only `extensions` is a design bug, not an edge case.
- **(6) Vue 2.7** — match installer module patterns; no Vue 3-only syntax.

---

## 3. OUT OF SCOPE

- **Snapshots, three-stage uninstall, retention, restore UX** → Step 2.7.1.
- **Static module registration / discovery without PHP for inactive packages** → Step 2.7.3.
- **Automatic removal of orphaned dependencies and install-reason bookkeeping** → Step 5.0.
- **Fault barrier, dumpers, static bridge removal** → Step 2.7 (consume; do not redo).
- **Package author contract / prebuilt assets** → Step 2.8.
- **Marketplace** → Step 5.6.

---

## 4. TESTING

- **Per checklist step (Conductor Tester):** PHPUnit + PHPStan PASS/FAIL.
- **`test-writer` applies** — this area starts near zero for the resolver/graph; write tests from scratch.
- **Minimum coverage:** silent-skip is gone (unregistered require fails with named module); circular still detected; enable refused on unsatisfied require; `requiredBy` lists a depender; disable blocked when an enabled extension or the active theme requires the target; pre-flight payload shape stable for UI/API.
- **Fixture modules** under test doubles / temp package manifests — do not corrupt first-party packages to test.
- **Frontend:** lint/prettier if Vue touched.
- **E2E (final `(XL)`):** 3 `@ci` specs; admin still boots if a dependency failure auto-disables via 2.7.

---

## SUCCESS CRITERIA

- `resolveModules()` no longer skips unregistered `require` entries; failures name the missing module.
- Enable/validate surfaces circular and unsatisfied requirements before activation completes.
- An archive whose `require` names a module the installation does not have is refused before anything is written, with the missing module named — through the panel upload and `php pagekit install <archive>` alike; whether the console can enable and disable is decided and, if not, said in the install command's help.
- `requiredBy` answers "what depends on this?" from registered manifests.
- Every core module's `require` names the modules whose classes its code imports, attribute classes included, held by a test that reads imports and manifests together; the `package` module's `Pagekit\User\Attribute\Access` import has a declared edge.
- Disable/uninstall pre-flight reports blockers (including `site.theme`), orphans, and a data-risk hint; blocking dependents prevent the mutation.
- Restore pre-flight names every core/extension whose version differs from what the snapshot recorded; snapshot metadata carries the application version from now on.
- Restore pre-flight reports every MySQL refusal (no prefix, over-long copy name, foreign reserved name, inbound foreign key, lock held) before a package file is reinstated; a refused restore leaves `packages/` as it found it. The dumper's table selection folds per `lower_case_table_names`, and a dump holding no tables is refused at dump time rather than marked whole.
- Admin UI shows the pre-flight result; no silent disable of a module the active theme requires.
- Reuses the Step 2.7 admin notice / auto-disable seam for boot-time fail-closed cases — no second messaging system.
- No `Step 2.7.2` references in code or tests; PHPUnit + PHPStan green with no new baseline entries.

---

## NOTES FOR THE ARCHITECT

- **The silent skip is the bug.** Replacing it with a log line and continuing is not fail-closed.
- **Strand risk is real.** An upgraded site with a typo'd `require` will start refusing that module — make the message the feature.
- **Keep the graph read-mostly.** Prefer deriving `requiredBy` over storing a second writable index that can drift.
- **2.7.3 will change how modules become `registered`.** Keep the integrity API against "registered + enabled" abstractions so discovery can move without reopening fail-closed rules.
- **Do not build orphan auto-delete.** Information only; 5.0 owns removal policy and must call 2.7.1 for any delete.
- One ticket / one PR; Conventional Commits; version bump once at Finalize — never inside checklist steps.
