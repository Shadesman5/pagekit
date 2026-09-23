# Step 2.7.1c: Runtime Composer Removal

<!-- conductor-mode: full -->

**ROADMAP:** 2.7.1c. GitHub Issue: [#297](https://github.com/Shadesman5/pagekit/issues/297). Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.7.1c.

---

## CONTEXT

- **Land after:** Step 2.7.1b (#287) — it moves the Composer helper, `PackageManager` and the snapshot engine into the package module. This step deletes and changes them in their final namespace, once. If 2.7.1b has not merged, STOP and sequence correctly.
- **Land before:** Step 2.7.2 (#268) — the dependency graph should read one record of what is installed, not Composer's and the manifest's — and Step 2.7.4 (#271), which then carries no overlay exception.
- **Provides:** an installation that registers a ready-to-run package from an archive without resolving, downloading or dumping anything; one autoload source (the module manifest); no `composer/composer` in the production runtime; a snapshot restore that brings a package back whole because there is no second bookkeeping to reconcile.
- **Risk:** Medium — the upload becomes the only install path and has to validate what Composer's extraction used to. Deleting is the easy half; the archive boundary is the design.
- **Goal:** No Composer at runtime. Packages arrive built — PHP, views, static assets, a manifest with an `autoload` map — and the CMS installs them by validating, extracting and registering. The project's own dependencies stay Composer-managed at build time.
- **Why:** Every package installed through the panel goes through Composer today, uploads included: the archive lands in `tmp/packages` and is installed through an `artifact` repository with `packages/` as `vendor-dir`. That keeps a dependency resolver, an autoload dump and `composer/composer`'s dependency tree on the customer's server, needs write access to `packages/composer/*`, and leaves `installed.json` + `autoload_*.php` as a second source of truth beside the module manifest — a restored snapshot puts back a package Composer no longer knows. The remote index the marketplace path was built for is gone. Signed, offline-verifiable packages need an install that executes no resolver.

### Current state (verified 2026-09-14 — paths are pre-2.7.1b; confirm the new locations in Discovery, then build)

**Uploads are Composer installs.**

- `app/installer/src/Controller/PackageController.php:193` `uploadAction()` validates `composer.json` (name, title, version, `type` = `pagekit-<type>`) and moves the archive to `tmp/packages/<vendor>-<name>-<version>.zip`. `:222` `installAction(array $package, bool $packagist)` → `PackageManager::install([name => version], $packagist)`.
- `app/installer/src/Helper/Composer.php:52-55` — the helper's `composer.json` lives under `path.packages`; repositories: `['type' => 'artifact', 'url' => $config['path.artifact']]`. `:177-184` `getComposer()` sets `vendor-dir` = `path.packages` and adds `['packagist' => false]` unless `$packagist`. `:140-169` `composerUpdate()` runs `Composer\Installer` with `setOptimizeAutoloader(true)` and treats `path.vendor/composer/installed.json` (the app's own vendor) as an already-installed repository. `:113-131` `isInstalled()` reads `packages/composer/installed.json`.
- `public/index.php:67` defines `path.artifact` = `tmp/packages`; `:53` `path.packages`.
- `app/installer/src/Package/PackageManager.php:96-101` constructs the helper with `path.temp` / `path.cache` / `path.vendor` / `path.artifact` / `path.packages` and a `system.api` default of `https://pagekit.com` (`:65`, `:72`, `:80`). `:107-125` `install()`: snapshot of package configs → `composer->install()` → for each name, `enable($package, $previousConfig)` when the module already existed (this **is** the update-by-upload path: migrations run from the recorded version) else `doInstall()`. `:529-530` `removeFiles()`: `if ($this->composer->isInstalled(...)) $this->composer->uninstall(...)`. The version fallback near `:1050-1068` reads `packages/composer/installed.json` when `composer.json` carries no `version`.

**The Composer path is dead on Composer 2 (verified 2026-09-20 against `composer/composer` 2.10.3).**

- `Helper/Composer.php:199` builds `new Locker($io, $jsonFile, $repositoryManager, $installationManager, json_encode($config))`; Composer 2's signature is `(IOInterface, JsonFile, InstallationManager, string $composerFileContents, ?ProcessExecutor)`, so the call throws `TypeError` at argument #3. It is an `\Error`, not an `\Exception`: `PackageController::removalFailure()` logs it and streams only the generic "The removal could not be completed" line.
- Behind it, `:147` `DownloadManager::setOutputProgress()`, `:155` `Installer::setAdditionalInstalledRepository()` and `:166` `setUpdateWhitelist()` / `setWhitelistDependencies()` do not exist in Composer 2 either (`setUpdateAllowList()` / `setUpdateAllowTransitiveDependencies()` / `setAdditionalFixedRepository()` are the renamed survivors). `phpstan-baseline.neon` carries all of it as `method.notFound` / `argument.type` entries for `app/installer/src/Helper/Composer.php`.
- Observable effect: uninstalling `pagekit/blog` (named in `packages/composer/installed.json`) takes the snapshot, runs `disable()`, removes `packages.blog` from the system config, then fails in `removeFiles()` before Composer touches the tree — the folder stays, `packages/packages.php` and `packages.lock` are untouched, the stream ends `status=error`. A second attempt takes a second snapshot and fails the same way; Restore from the snapshot is the way back.
- No test executes `Composer::uninstall()` or `composerUpdate()`; fixtures either omit `installed.json` or only assert that the snapshotter captured it.
- Consequence for this step: deleting the branch is a defect fix, not only a cleanup. Do not port the helper to the Composer 2 API on the way — the baseline entries are deleted with the file.

**The overlay is the second autoload source.**

- `autoload.php:7-28` — if `packages/autoload.php` exists, merges `packages/composer/autoload_namespaces.php`, `autoload_psr4.php`, `autoload_classmap.php`, `autoload_files.php` into the root loader.
- The module loader already registers manifest autoload: `app/modules/application/src/Module/Loader/AutoLoader.php:23-24` reads `$module['autoload']` and calls `setPsr4` on the loader for every registered module.
- First-party packages declare it there: `packages/pagekit/blog/index.php:17-19` `'autoload' => ['Pagekit\\Blog\\' => 'src']`; no `packages/**/composer.json` carries an `autoload` key. A convention package needs nothing from the overlay.

**The snapshot engine carries Composer bookkeeping.**

- `app/installer/src/Package/Snapshot/PackageSnapshotter.php:61` `BOOKKEEPING = 'composer/installed.json'`; `:99-116` `composerInstalled()` decides the `composer` metadata flag and `bookkeeping()` copies the file into the snapshot; `restore()` deliberately does not write it back. `app/installer/src/Package/Snapshot/SnapshotStore.php:86` `INSTALLED_FILE`. Tests under `tests/Unit/Snapshot/` cover capture-iff-installed and the not-restored decision.

**The marketplace surface only the Composer path could serve.**

- `app/installer/index.php:95-97` route `/system/marketplace` → `MarketplaceController`; `:133-150` menu `system: marketplace` (+ `extensions`, `themes`). `app/installer/src/Controller/MarketplaceController.php`, `app/installer/views/marketplace.php`, `app/installer/app/components/marketplace.vue`, `app/installer/app/views/marketplace.js`, `app/installer/assets/images/icon-marketplace.svg`.
- `packagist` travels through `app/installer/app/lib/install.vue:48-56`, `update.vue:46-56`, `package.js:63-72` into `installAction`.
- `app/installer/src/SelfUpdater.php`, `UpdateController.php`, `app/console/src/Commands/SelfupdateCommand.php` are the **core** self-update — a different path with its own forward-debt tag. Not this step.

**Console.**

- `app/console/src/Commands/InstallCommand.php:40-45` is a disabled stub (`The 'install' command is disabled: the pagekit.com marketplace backend was discontinued.`) with a `TODO: Step 5.6` note and the old Composer call commented out.
- `BuildCommand.php:8, 62-63` and `UpdateCommand.php:8, 67-68` import `Helper\Composer` and reference it only in commented-out code.

**Dependencies.**

- `composer.json:19` `"composer/composer": "^2.10.3"` in `require`. `Composer\Semver\VersionParser` is used only in `Helper\Composer.php:13`. Version comparison elsewhere is `version_compare` (`app/installer/src/Package/Lifecycle/LifecycleRunner.php:98, 102`).

---

## PRINCIPLES (hold across every checklist step)

- **Delete, do not disable.** No feature flag, no "Composer if present", no stub that says the path is gone (Rule 1 / Rule 4). The disabled marketplace `install` stub is the counter-example to leave behind.
- **One autoload source.** The module manifest. An archive without an `autoload` map is refused at upload with a message naming it — it is not accepted and left to fail at boot.
- **The archive boundary is the security boundary now.** Everything Composer's extraction implicitly guaranteed — no path escapes the target, the target is the declared `vendor/name`, an existing target is an update and nothing else — is this step's explicit validation, with tests that prove the refusal.
- **Update-by-upload keeps today's semantics.** Replace the tree, then `enable()` with the previous package config so migrations run from the recorded version. The replacement stays one method: the update step later puts a snapshot in front of it without reopening the install path.
- **Nothing is resolved at install time.** No dependency resolution, no download, no constraint solving. What a package needs beyond the core, it bundles under its own namespace — the contract text for that is 2.8, not code here.
- **Core self-update is not touched.** `SelfUpdater`, `UpdateController`, `SelfupdateCommand` and their `system.api` reads stay exactly as they are.
- **Rule 3** — tree stays green after every checklist step; required CI job names stay stable.

---

## 0. SAFETY CHECKS

Before starting:

1. Branch up-to-date with `develop`.
2. Confirm **Step 2.7.1b has landed** (the package module exists; the helper, manager and snapshot engine live there).
3. Baseline green:

```bash
php -v
composer install --no-interaction --prefer-dist --no-progress
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
pnpm install --frozen-lockfile && pnpm build
```

**IF ANY FAILS → STOP AND FIX** (or report the pre-existing failure) before deleting anything.

---

## 1. DISCOVERY

```bash
rg -n 'Helper\\\\Composer|->composer\b|composerInstalled|BOOKKEEPING|INSTALLED_FILE' app/ --glob '!app/vendor/**'
rg -n 'path\.artifact|tmp/packages|packages/composer|packages/autoload' app/ public/ autoload.php scripts/ .gitignore --glob '!app/vendor/**'
rg -n 'packagist|marketplace|Marketplace' app/ --glob '!app/vendor/**' --glob '!**/bundle/**'
rg -n 'installed\.json' app/ tests/ --glob '!app/vendor/**'
rg -n "'autoload'" packages/*/*/index.php app/modules/application/src/Module/Loader/
rg -n 'composer/composer|composer/semver' composer.json composer.lock
rg -n 'Composer\\\\Semver|version_compare' app/ --glob '!app/vendor/**'
rg -n 'Helper/Composer.php' phpstan-baseline.neon
```

Resolve before writing code:

- **Where the upload is staged.** The archive has to be validated somewhere before extraction. Keep `tmp/packages` under a key that says staging, or validate from the uploaded temp file directly and delete `path.artifact` — state the choice; do not keep an `artifact` name for something that is no longer a Composer repository.
- **What the archive must contain.** Manifest file(s) the installation reads today (`composer.json` with `name`, `title`, `version`, `type`; module `index.php` with `autoload`), and how a missing or malformed one is reported. Do not invent the 2.7.3 static manifest here — read what exists, and make the check a single function 2.7.3 can retarget.
- **Path safety.** Extraction target is `packages/<vendor>/<name>/` derived from the **manifest name**, validated against the same allowlist the snapshot store uses for ids; entries that escape (`..`, absolute, symlinks) refuse the whole archive before a byte is written.
- **Update-by-upload.** Confirm today's `install()` branch (`enable($package, $previousConfig)` when the module existed) and reproduce it after extraction. Decide what happens to the replaced tree (delete in place — a snapshot before it is the update step's contract, not this one's).
- **`installed.json` readers.** The version fallback in `PackageManager`, `isInstalled()` callers, the snapshotter's capture and its tests, the `composer` metadata flag in snapshot `metadata.json` (drop the field; existing snapshots with the field still read — the store sanitizes unknown/typed fields).
- **`InstallCommand`.** `php pagekit install <archive>`: one local archive path, the same service the upload calls. A container or headless installation has no panel, so the command stays. The marketplace signature (`name:constraint`, `--prefer-source`) and the `Step 5.6` note go with the stub. A later marketplace may add a name form; it does not keep a disabled command. This is not `php pagekit setup`.
- **What `system.api` still serves.** Only the core self-update path reads it afterwards; leave those reads and their default alone.
- **Bundle entries.** `scripts/bundle-entries.mjs` lists the marketplace Vue entry in the installer (or package module) group; removing it changes `pnpm build` output.

---

## 2. WORK

Architect: decompose into ordered, individually-green checklist steps. Suggested order:

1. **Archive install service.** Validate → extract → register → lifecycle `install()`; update branch via `enable($package, $previousConfig)`. `installAction` (without `packagist`) and `php pagekit install <archive>` call it. The command's argument is that one path; `name:constraint` and `--prefer-source` are deleted with the stub. The Composer helper still exists but the install path no longer touches it. Tests for the refusals (no autoload, path escape, `type` mismatch, malformed manifest) and the round-trip.
2. **Delete the Composer runtime.** Helper, `composer/composer` in `require` (+ `composer.lock`), `autoload.php` overlay, `path.artifact` (or its rename), the `removeFiles()` Composer branch, the version fallback, the dead console imports. `packages/composer/` in `.gitignore` and anywhere else it is named.
3. **Snapshot engine.** Delete bookkeeping capture, `INSTALLED_FILE`, the `composer` metadata flag and their tests; the restore round-trip test now covers an uploaded package end to end (upload → enable → uninstall → restore → the package boots and its manifest routes are registered again).
4. **Marketplace surface.** Controller, route, menu, view, Vue component and entry, icon, `packagist` in `installAction` / `install.vue` / `update.vue` / `package.js`; components left without a caller. `pnpm build` / `pnpm lint` / `prettier --check` green.
5. **Guards and docs.** A test or CI grep that `app/vendor` after `composer install --no-dev` holds no `composer/composer`, that `autoload.php` has no overlay branch and that `packages/composer/` is never created; README / AGENTS where they describe upload or `packages/composer`.
6. **Mandatory final `(XL)` step** — Review (Bugbot + Security) + E2E.

Sizing hints: (1) carries the design and the security surface — `L`; (2) and (3) are deletions with wide test fallout — `M`/`L`; (4) is frontend deletion — keep it separate so a red CI after it points at the bundle, not at PHP.

### Notes per group

- **(1)** The refusal tests are the feature. An install path that accepts an archive it cannot place safely is worse than the Composer one it replaces.
- **(2)** `composer.lock` changes; run `composer install` afterwards and check the tree, not the diff.
- **(3)** Existing snapshots in the field carry `composer: bool` in `metadata.json`. `SnapshotStore` sanitizes typed fields — confirm an old snapshot still lists and restores. Uninstall clears the route cache, because a removed package's routes must not stay cached; the restore assertion fails if that cache still describes a tree without the package. The oracle is the fixture's manifest routes.
- **(4)** Vue is deleted, not edited. Do not "clean up" `install.vue` beyond removing the flag.

---

## 3. OUT OF SCOPE

- **Core self-update** (`SelfUpdater`, `UpdateController`, `SelfupdateCommand`, their `system.api` default) → Step 2.9 owns it.
- **A snapshot before update-by-upload** → Step 2.9 (the update-rollback contract).
- **The static manifest and its format** → Step 2.7.3 (this step reads the module `index.php` `autoload` that exists today).
- **Packaging contract text, constraint syntax, author tooling for bundling and scoping libraries** → Step 2.8.
- **Marketplace, signing, any package index** → Step 5.6. No replacement, no client, no stub.
- **Dependency graph / pre-flight** → Step 2.7.2.
- **`vendor/` at the repo root** → Step 2.7.4.
- **Rewriting comments in files this step only partially touches.** Inherited docblocks that predate the comment-prose rule are not a finding against this step; the sweep is owned elsewhere.

---

## 4. TESTING

- **Per checklist step (Conductor Tester):** PHPUnit + PHPStan PASS/FAIL.
- **`test-writer` applies** — the archive service is new code; the deletions change what existing tests may assume (fixtures that wrote `installed.json`, snapshot tests that plant it).
- **Minimum coverage:** upload → install → enable → uninstall (snapshot) → restore round-trip for a fixture package with manifest autoload; after install and after restore the fixture's manifest routes are registered (the package boots, and a route cache cleared on uninstall does not come back describing a tree without it); upload of a newer version runs `enable()` with the previous config; an archive without manifest autoload, with a path escape, with a mismatched `type` or a malformed manifest is refused before anything is written; `packages/composer/` is never created; `autoload.php` has no overlay branch; `composer install --no-dev` yields no `composer/composer`.
- **Fresh install:** `php pagekit setup … -d sqlite` from nothing, then upload the Blog package as an archive and enable it — the first-party package is the proof that manifest autoload carries a real extension. Its permalink aliases are not the oracle for route registration.
- **Frontend:** `pnpm build` without the marketplace entry; `pnpm lint` and `pnpm exec prettier --check .` exit 0.
- **E2E (final `(XL)`):** 3 `@ci` specs; extensions and themes pages load and act without the marketplace menu entry.

---

## SUCCESS CRITERIA

- `composer/composer` is not in the runtime `require`; `app/vendor` after `composer install --no-dev` does not contain it; no class under `app/` imports `Composer\`.
- `autoload.php` merges nothing from `packages/`; `packages/composer/` does not exist after an install and nothing writes it.
- Upload validates, extracts to `packages/<vendor>/<name>/` from the manifest name, registers, runs `install()`; a newer version of an installed package runs `enable()` with the previous config.
- Refusals are tested: missing manifest autoload, path escape, `type` mismatch, malformed manifest — none writes a byte.
- The snapshot engine has no `installed.json` capture, no `composer` flag, no `INSTALLED_FILE`; a snapshot of an uploaded package restores, the package boots, and its manifest routes are registered again.
- The marketplace package surface is gone (controller, route, menu, view, Vue, `packagist`); core self-update is byte-for-byte untouched.
- `php pagekit install <archive>` installs a local archive from the console over the same service. The marketplace stub, its `name:constraint` signature, `--prefer-source` and the `Step 5.6` note are gone.
- No `Step 2.7.1c` references in code or tests; PHPUnit + PHPStan green with no new baseline entries.

---

## NOTES FOR THE ARCHITECT

- **This is a deletion with one new seam.** The seam is the archive service; everything else is removal. If the plan grows a second new component, re-scope.
- **The refusal list is the design decision.** Decide it in the ticket — which manifest fields are mandatory, what a path escape is, what an existing target means — so the Refactorer does not decide the security boundary alone.
- **Keep the tree replacement one method.** The update step will put a snapshot in front of it; a replacement spread over the controller and the manager cannot be wrapped later without an adapter.
- **Do not leave a stub.** The disabled marketplace `install` command is what a half-deletion looks like; this step removes that shape, it does not add another.
- **The Verifier will meet essays it did not write.** The snapshot and package docblocks predate the comment-prose rule; the parts this step does not touch arrive unchanged and are not a finding against it — state that in the ticket.
- **Paths in this prompt are pre-2.7.1b.** Discovery resolves them in the package module; do not build against `Pagekit\Installer\Package\`.
- One ticket / one PR; Conventional Commits; version bump once at Finalize — never inside checklist steps.
