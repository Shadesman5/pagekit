# Step 2.7.1b: Package Module Boundary

<!-- conductor-mode: full -->

**ROADMAP:** 2.7.1b. GitHub Issue: #287. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.7.1b.

---

## CONTEXT

- **Land after:** Step 2.7.1a (#281) — it rewrites `DatabaseRestorer`, one of the files this step relocates. Moving a file mid-rewrite buys nothing and costs a merge. If 2.7.1a has not merged, STOP and sequence correctly.
- **Land before:** Step 2.7.2 (#268) and Step 2.7.3 (#266). A dependency graph cannot honestly fail closed while `system` and the package code need each other in code and may not say so in the manifest; and static manifests should not be written for a module whose boundary is about to move.
- **Provides:** an extension contract under a namespace that names it, a module dependency graph with one direction, and an `installer` module that is the installer.
- **Risk:** Medium — wide mechanical blast radius (imports across the tree plus tooling config), but no logic change. The only design decision is where the extension failure record lands.
- **Goal:** The package registry, the lifecycle contract, `PackageManager`, the snapshot engine and the Composer helper live in a module of their own. `installer` shrinks to the setup wizard plus the marketplace / self-update clients. No import below the new module points back at `Pagekit\System\`.
- **Why:** `system` declares `installer` as a `require`, so the module loads on every boot; only the wizard sits behind its `enabled` flag while `package`, `manager` and `snapshotter` are registered unconditionally. Roughly 88% of `app/installer/src` is not setup code. That would be cosmetic if it did not have two hard consequences — a published extension contract under the wrong name, and a declared module graph that contradicts the code graph.

### Current state (verified 2026-09-10 — confirm in Discovery, then build; do not rediscover blindly)

**The module is not the installer.**

- `app/system/index.php:22` lists `'installer'` under `require`, so it is loaded on every boot in all three entry points (`app/system/app.php`, `app/installer/app.php`, `app/console/app.php` all register `app/installer/index.php`).
- `app/installer/index.php`: `package` (`:22`), `manager` (`:23`) and `snapshotter` (`:36`) are registered before the `if ($config['enabled'])` gate at `:46`. The config default is `'enabled' => false` (`:188`). The gate covers only the `/installer` route, the asset version, the locale listener and the exception redirect.
- Routes, menu entries and permissions for extensions, themes, marketplace, update and snapshots are declared at module level in the same file — all of them admin surface, none of them setup.
- Size, by source lines under `app/installer/src`: snapshot engine ≈ 2 650, package registry / lifecycle / manager / controller ≈ 2 100, marketplace and self-update ≈ 700, setup wizard ≈ 750.

**The extension contract carries the installer's name.**

- `packages/pagekit/blog/src/BlogLifecycle.php` imports `Pagekit\Installer\Package\Lifecycle\PackageLifecycle` and `…\MigrationSet`. `app/system/scripts.php` imports the same contract. Extension packaging (2.8) and the marketplace (5.6) would publish that name.

**The declared graph and the code graph disagree.**

- `app/installer/src/Package/PackageManager.php:13` imports `Pagekit\System\Extension\ExtensionFailureStore`, and reaches it through `$this->app->has('extension.failures')` at `:93` — an optional lookup that hides the dependency from the manifest.
- The reverse direction is declared and real: `app/system/index.php:5`, `app/system/scripts.php:5`, `app/system/src/Controller/MigrationController.php:10` and `app/system/modules/site/src/PackageNodeTypes.php:8` all import from `Pagekit\Installer\`.
- `installer` therefore cannot declare `system` as a `require` — `system` already requires it.

**Both classes in the cycle are already dependency-clean.**

- `ExtensionFailureStore` (`app/system/src/Extension/ExtensionFailureStore.php`) imports only `Pagekit\Filesystem\Filesystem`; its constructor takes a directory path and that service. It is registered in `SystemModule::main()` (`app/system/src/SystemModule.php:61`), guarded on `path.system`.
- `ExtensionLoader` (`app/system/src/Extension/ExtensionLoader.php`) imports `Pagekit\Module\ModuleManager` (which lives in the `Pagekit\` root namespace, `app/modules/application/src/Module/`) and `Psr\Log\LoggerInterface`. It receives the enabled list, the theme name and a disable closure as arguments (`SystemModule.php:68-75`). It knows nothing about `Pagekit\System\` beyond its own namespace declaration.
- Consumers of the record: `ExtensionLoader`, the `view.messages` admin notice in `app/system/index.php:187-211`, and `PackageManager`.

**The Composer helper would reverse the cycle if it stayed.**

- `Pagekit\Installer\Helper\Composer` has exactly three callers: `PackageManager`, `app/console/src/Commands/BuildCommand.php` and `app/console/src/Commands/UpdateCommand.php`. The setup wizard is not one of them.

**Tooling holds the old path.**

- `phpstan-baseline.neon` names `app/installer` in 24 entries (the baseline is edited by hand — never `--generate-baseline`).
- Further hard-coded paths: `composer.json` (PSR-4), `phpstan.neon`, `.php-cs-fixer.php`, `.gitignore`, the three `app.php` boot files, `scripts/bundle-entries.mjs`, `scripts/styles.mjs`, `scripts/publish.mjs`, `.github/scripts/check-version-ssot.php`, plus `AGENTS.md` and `.cursor/BUGBOT.md`.
- More than 70 PHP files name the `Pagekit\Installer` namespace.

---

## PRINCIPLES (hold across every checklist step)

- **This step relocates code. It does not rewrite it.** Any behaviour difference is a bug in the move, not an improvement.
- **No bridge of any kind.** No `class_alias`, no old namespace kept alive, no second service id, no dual registration (Rule 1 / Rule 2 / Rule 4).
- **One direction.** After the move, nothing below the new module imports `Pagekit\System\`. That is a grep, and it is the acceptance test.
- **One manifest per concern.** Routes, menu, permissions and views for packages and snapshots move together with the code they serve. Do not split a manifest across two modules.
- **The service is registered by the module that owns the class.** `extension.failures` moves with the record.
- **Do not pre-empt neighbouring steps.** No static manifest (2.7.3), no `vendor/` move (2.7.4), no dependency-graph work (2.7.2).
- **Rule 3** — tree stays green after every checklist step; required CI job names stay stable.

---

## 0. SAFETY CHECKS

Before starting:

1. Branch up-to-date with `develop`.
2. Confirm **Step 2.7.1a has landed** (the restorer rewrite is in the tree, so this step moves its final shape).
3. Baseline green:

```bash
php -v
composer install --no-interaction --prefer-dist --no-progress
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
pnpm install --frozen-lockfile && pnpm build
```

**IF ANY FAILS → STOP AND FIX** (or report the pre-existing failure) before moving a file.

---

## 1. DISCOVERY

```bash
rg -l 'Pagekit\\\\Installer' --glob '*.php' --glob '!app/vendor/**'
rg -n 'Pagekit\\\\System' app/installer/src/
rg -n 'Pagekit\\\\Installer' app/system/ packages/ app/console/ --glob '!**/vendor/**'
rg -n "app->set\('|'require'|'routes'|'menu'|'permissions'" app/installer/index.php
rg -n 'extension.failures|ExtensionFailureStore|ExtensionLoader' app/system/src/ app/installer/src/
rg -n 'app/installer' composer.json phpstan.neon phpstan-baseline.neon .php-cs-fixer.php .gitignore scripts/*.mjs .github/scripts/*.php app/*/app.php
```

Resolve before writing code:

- **Where the module lives and what it is called.** A directory beside `app/installer/`, `app/system/` and `app/console/` mirrors the existing layout and sidesteps the tier question: `app/modules/*` holds no views, and `app/system/modules/*` is the tier Step 5.0 wants operator-deactivatable — package management may never be. State the choice and the namespace that follows from it.
- **Where the failure record lands.** It has to be readable by the boot barrier in `system` and by the package manager without an import pointing back at `system`. Moving it into the new module is the obvious answer; if Discovery finds a better one, say why.
- **Whether the setup wizard needs anything from the new module.** If it does, that dependency has to be declared, and it decides the `require` direction between the two.
- **Service registration order.** `system` requires the new module, so its `main()` runs first — confirm that `extension.failures` is available where `SystemModule` and `PackageManager` expect it, and that `path.system` (or its successor) is still the guard.
- **What the module manifest declares.** `require` list, routes, menu, permissions, resources, config defaults (snapshot retention lives there today) — all of it moves; check nothing is left orphaned in `installer`.
- **Bundle entries and asset paths.** `scripts/bundle-entries.mjs` groups Vue entries per module; the group key and its source paths follow the move, and `pnpm build` output names change with it.

---

## 2. WORK

Architect: decompose into ordered, individually-green checklist steps. Suggested order:

1. **New module skeleton.** Directory, `index.php` manifest, PSR-4 entry, module registration in the three boot files. Nothing moved yet; the module registers and boots empty.
2. **Package registry and contract.** `Package`, `PackageInterface`, `PackageFactory`, `Package\Lifecycle\*` and the `package` service. Every call site updated, including `packages/pagekit/blog` and `app/system/scripts.php` — the shipped extension is the proof that the contract name changed.
3. **Failure record.** `ExtensionFailureStore` and its service move; `ExtensionLoader` stays in `system` and imports it. After this step the grep for `Pagekit\System\` below the new module must be empty.
4. **Manager, Composer helper and snapshot engine.** `PackageManager`, `Helper\Composer`, `Package\Snapshot\*` and the `manager` / `snapshotter` services, with the console commands following.
5. **Admin surface.** `PackageController`, `SnapshotController`, their views and Vue entries, plus routes, menu, permissions and config defaults, moved undivided into the new manifest.
6. **Tooling and `installer` cleanup.** Baseline paths, CS-Fixer, `.gitignore`, build scripts, version-SSoT script, docs. `installer` is left holding the wizard plus the marketplace / self-update clients and nothing else.
7. **Mandatory final `(XL)` step** — Review (Bugbot + Security) + E2E.

Sizing hints: (3) is the only design decision; (2), (4) and (5) are mechanical but wide. Keeping (5) separate means a red CI after it points at the admin surface rather than at the whole move.

### Notes per group

- **(2)** The blog extension is a first-party package that implements the contract. If it still compiles against the old name after this step, the move is incomplete.
- **(3)** The record is a leaf: a path plus the filesystem service. If the move turns into a redesign, stop and re-scope.
- **(5)** Views and Vue entries move as they are. The admin rebuild deletes them later; polishing them here is work thrown away.
- **(6)** The PHPStan baseline is edited, never regenerated. A regenerated baseline hides whatever the move broke.

---

## 3. OUT OF SCOPE

- **Dividing `PackageManager`.** Its size is a separate problem and a relocation does not split a file. Do not take the opportunity.
- **Marketplace client, update controller, self-updater.** They stay in `installer` untouched — they are on the removal path.
- **Any behaviour change.** New guards, better errors, tightened validation: all out. This step moves code.
- **Static module manifests / discovery** → Step 2.7.3.
- **Dependency-graph fail-closed rules and pre-flight** → Step 2.7.2.
- **`vendor/` at the repo root** → Step 2.7.4.
- **The namespace rebrand.** The vendor prefix stays what it is; only the module segment changes here.
- **Splitting the admin surface off into `system` or a separate admin tree** → the admin topology decision belongs to the admin rebuild, not here.

---

## 4. TESTING

- **Per checklist step (Conductor Tester):** PHPUnit + PHPStan PASS/FAIL.
- **`test-writer` applies** only where a test's subject genuinely changed — service registration, module wiring, the failure record's new home. A pure relocation needs updated imports, not new tests.
- **Minimum coverage:** the new module registers and its services resolve in all three boot paths; the failure record is written by the boot barrier and cleared by the package manager across the new boundary; the shipped extension's lifecycle still runs; snapshot create / restore / purge behave as before.
- **Guard test:** an assertion (or a CI grep) that no file below the new module imports `Pagekit\System\`. This is the acceptance criterion — make it mechanical so it cannot rot.
- **Fresh install:** `php pagekit setup … -d sqlite` from nothing must complete and boot. A namespace move is the class of change that passes unit tests and fails a real install.
- **Frontend:** `pnpm build` produces the moved bundle entries; `pnpm lint` and `pnpm exec prettier --check .` exit 0.
- **E2E (final `(XL)`):** 3 `@ci` specs; extensions, themes and snapshots pages still load and act.

---

## SUCCESS CRITERIA

- The package registry, contract, lifecycle, manager, snapshot engine and Composer helper live in one module under a namespace that names them.
- No file below that module imports `Pagekit\System\`; the declared `require` graph matches the imports.
- `app/installer/` holds the setup wizard plus the marketplace / self-update clients and nothing else.
- The contract a shipped extension implements no longer names the installer, and `packages/pagekit/blog` proves it.
- No `class_alias`, no old namespace, no duplicated service id anywhere in the tree.
- A fresh SQLite installation completes and boots; enable, disable, uninstall, snapshot restore and snapshot purge behave exactly as before.
- Autoload map, PHPStan baseline paths, CS-Fixer config, `.gitignore`, boot files, build scripts and the version-SSoT script all name the new locations; nothing still resolves through the old one.
- No `Step 2.7.1b` references in code or tests; PHPUnit + PHPStan green with no new baseline entries.

---

## NOTES FOR THE ARCHITECT

- **This is a boundary, not a refactor.** The temptation to fix things while the files are open is the main risk to the step. Everything that is not "same code, new home" is out.
- **The one-way grep is the design.** If the plan cannot state a check that proves the direction, the plan has not decided where the failure record goes.
- **The Composer helper is the trap.** Leaving it in `installer` reverses the cycle instead of removing it.
- **The admin surface travels with its manifest.** Splitting routes, menu and permissions across two modules costs more than it saves, and the Vue half is deleted by the admin rebuild anyway.
- **Do not regenerate the PHPStan baseline.** Edit the 24 paths.
- **A green unit suite is not enough here.** The fresh-install boot is the check a namespace move most often fails.
- One ticket / one PR; Conventional Commits; version bump once at Finalize — never inside checklist steps.
