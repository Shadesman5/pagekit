# Step 2.7.1c — Runtime Composer Removal

<!-- Branch doc for Roadmap Step 2.7.1c.
     Path: migration-docs/branches/phase-2/step-2-7-1c-runtime-composer-removal.md -->

**Branch:** `feature/runtime-composer-removal`
**ROADMAP Step:** 2.7.1c (Runtime Composer Removal)
**GitHub Issue:** [#297](https://github.com/Shadesman5/pagekit/issues/297)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-09-23 19:01
**Completed:** _TBD_

---

## 🎯 Overview

A package zip can be refused, read, and extracted without Composer, and that is how a package is installed. The panel upload and `php pagekit install <archive>` replace the package tree through the seam. `php pagekit archive` builds that zip with Finder and `\ZipArchive`. `php pagekit update` is gone, and the web self-update log is plain text. Uninstall deletes the package folder through the file service. A removal snapshot is the package tree, a database dump, and a description: it does not copy Composer's installed record, and `read()` omits a `composer` key an earlier release stored. `composer/composer` is not a runtime dependency, and `autoload.php` loads only `app/vendor/autoload.php`. `ext-zip` is a runtime requirement, and the shipped One theme declares an empty autoload map so the seam accepts it. The marketplace route, menu, and package-update client are gone. Extensions and themes install from an archive. `systemApi` is the dashboard's registration; the update page still reads `system.api`.

---

## ✅ What Changed

### Archive seam (Checklist Step 1)

`PackageArchive::open()` is the whole validation. It throws `ArchiveRefusedException` and writes nothing. No caller yet.

| File | Change |
|---|---|
| `app/package/src/Archive/PackageArchive.php` (new) | Final `Pagekit\Package\Archive\PackageArchive`. `open()` validates the zip; accessors expose name, module, version, type, title, autoload, the composer object, and the path. `extractTo()` streams the entries into a directory. |
| `app/package/src/Archive/ArchiveRefusedException.php` (new) | Final `RuntimeException`. The refusal an administrator reads. |
| `packages/pagekit/theme-one/index.php` | `'autoload' => []` after `'name'`. |
| `composer.json` | `"ext-zip": "*"` moved from `require-dev` to `require`. |
| `composer.lock` | Lock refreshed for that platform move. |

#### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageArchiveTest.php` (new) | One temp workspace per case; fixtures built with `\ZipArchive`. A refusal names its reason and leaves only the fixture. Both shipped trees open. `extractTo()` writes the entries at umask modes, refuses an archive that changed after `open()`, and does not replace an existing path. |

Gates: production verifier PASS; production tester PASS; test-file verifier PASS; tester PASS. No deviations.

### Install path (Checklist Step 2)

`PackageManager::install(PackageArchive)` replaces the package tree and then enables or installs it. The panel stages an upload under `packageStaging` and installs that file. `php pagekit install <archive>` does the same from a path and does not enable.

| File | Change |
|---|---|
| `app/package/src/PackageManager.php` | `install(PackageArchive)` refuses another folder, another type, or a link, then `replaceTree()` swaps a hidden sibling into place and `enable()` or `doInstall()` runs. |
| `app/package/src/PackageModule.php` | Registers `packageStaging` as `<path.temp>/packages`. |
| `app/package/src/PackageFactory.php` | `load()` phpdoc is `string\|array<array-key, mixed>`. The signature is unchanged. |
| `app/package/src/Controller/PackageController.php` | Upload opens the archive and builds the payload before staging. Install re-opens the staged file, streams the result, and unlinks that path afterwards. |
| `app/console/src/Commands/InstallCommand.php` | `php pagekit install <archive>` opens the zip and installs it. The package list, `--prefer-source`, and the disabled-command body are gone. |
| `app/package/app/lib/install.vue` | `install()` sends `{ package: pkg }` and no `packagist` flag. |
| `app/package/app/lib/package.js` | `install()` drops its fourth argument. `update()` is unchanged. |
| `app/package/app/components/package-upload.vue` | The upload panel calls `install()` without the trailing `true`. |
| `tests/Unit/Package/PackageModuleBoundaryTest.php` | The bare-container key list includes `packageStaging`. |

#### Tests (Checklist Step 2)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageInstallFromArchiveTest.php` (new) | Fresh install records the version; an update of a loaded module replaces the tree and enables from the recorded version; a disabled package or a non-string `module` installs again; another folder, another type, or a link writes nothing; a failed extraction leaves the packages directory unchanged; a retired tree that cannot be put back is kept and the install fails; one that will not delete does not fail the install. |
| `tests/Unit/Console/InstallCommandTest.php` (new) | A fixture zip installs and is not enabled. A refused archive exits failure and writes nothing. A link target stays a link. A non-string argument is refused before the path is read. |
| `tests/Unit/Package/PackageUploadBoundaryTest.php` (new) | Every upload refusal is a 400 and stages nothing. A request that fails the patterns, a missing file, a refused file, or a staged archive for a different package ends `status=error` and does not install. Success, a streamed exception, and an `Error` all remove the staged file. A staged directory is not walked; a staged link is unlinked. Uninstall failure wording is unchanged. |
| `tests/Unit/Package/PackageZip.php` (new) | Writes a zip the archive checks accept. |
| `tests/Unit/Package/bootstrap.php` | `Pagekit\Package\__()` plus probes for `rename`, `random_bytes`, and `opcache_invalidate`. |
| `tests/bootstrap.php` | Defines `opcache_invalidate` when the extension is absent, then loads the package bootstrap so unqualified calls in that namespace bind. |

Gates: production verifier PASS; production tester PASS; frontend, fresh install, and install-over PASS; test-file verifier PASS; tester PASS after one test-defect retry.

### Console consumers (Checklist Step 3)

`php pagekit archive <name>` writes `<vendor>-<name>.zip` with Finder and `\ZipArchive`. Rules are the package `.gitignore`, then each `archive.exclude` string, one rule per `Gitignore::toRegex()` call; the last match decides. `archive.scripts` is not run. `php pagekit update` is deleted. The release zip no longer reserves `tmp/packages/`. The web self-update log is plain text.

| File | Change |
|---|---|
| `app/console/src/Commands/ArchiveCommand.php` | Validates the name before any path is read, lists files, and writes the zip. `.gitignore` then `archive.exclude`; the last matching rule keeps or drops. |
| `app/console/src/Commands/BuildCommand.php` | The Composer import, the path-config loop, the commented install, and `addEmptyDir('tmp/packages/')` are gone. |
| `app/console/src/Commands/UpdateCommand.php` (deleted) | `php pagekit update` is gone. Registration was a `glob()`. |
| `app/installer/src/SelfUpdater.php` | The `HtmlOutputFormatter` import and `setFormatter()` call are gone. The web update log is plain text. |

#### Tests (Checklist Step 3)

| File | Change |
|---|---|
| `tests/Unit/Console/ArchiveCommandTest.php` (new) | The fixture's sorted entry list pins rule order, the `!` split, and last-match-wins in both directions; an `archive.scripts` marker is never created. A refusal exits failure and writes no zip; a rule that cannot be matched leaves the earlier archive. A link outside the tree is absent. The shipped theme drops `app/assets` and `node_modules` and keeps its sources; the blog keeps its PHP sources. |

Gates: production verifier FAIL once (`archive.exclude` typing), retry then PASS; PHPUnit + PHPStan PASS; test verifier PASS; PHPUnit + PHPStan PASS.

### Snapshot engine (Checklist Step 4)

A snapshot no longer records whether Composer installed the package. `create()` writes the description, the dump, and the archived tree. `read()` ignores a `composer` key already stored in `metadata.json` and does not rewrite the file. Restore does not write `installed.json` back.

| File | Change |
|---|---|
| `app/package/src/Snapshot/PackageSnapshotter.php` | `BOOKKEEPING`, `composerInstalled()`, `bookkeeping()`, `bookkeepingFile()`, the `composer` argument of `details()`, and the capture inside `create()` are gone. The restore docblock no longer describes keeping Composer's record. The `$packages` argument stays: restore still resolves the live tree through it. |
| `app/package/src/Snapshot/SnapshotStore.php` | `INSTALLED_FILE` and `installedFile()` are gone. Both phpstan shapes and `read()` drop `composer`. |

#### Tests (Checklist Step 4)

| File | Change |
|---|---|
| `tests/Unit/Snapshot/PackageSnapshotterTest.php` | The three bookkeeping cases, `provideComposerRecords()`, `composer()`, `writeBookkeeping()`, `BOOKKEEPING`, and `BookkeepingThatCannotBeCopied` are gone. The "beside the tree" list no longer includes `installed.json`. The unfinished-write double refuses `views/extension.php`. |
| `tests/Unit/Snapshot/SnapshotStoreTest.php` | `testASnapshotCountsAsComposerInstalledOnlyWhereItSaysSoOutright` and the `composer` field are gone. `description()` no longer takes that flag. `testASnapshotWhoseDescriptionStillNamesComposerListsAsWholeAndRestoresWithoutThatKey` plants the key, restores, and asserts `list()` and `get()` omit it while the file still holds it. |
| `tests/Unit/Snapshot/SnapshotRestoreTest.php` | `testComposersRecordOfWhatItHadInstalledIsNotWrittenBack`, `writeBookkeeping()`, and `BOOKKEEPING` are gone. |
| `tests/Unit/Snapshot/SnapshotServiceWiringTest.php` | The `packages/composer` directory, the `installed.json` plant, and the assertion that the snapshot holds that file are gone. |
| `tests/Unit/Snapshot/UploadedPackageRoundTripTest.php` (new) | A fixture zip installs and enables; the module's `routes` register and its class autoloads. Uninstall removes the tree and leaves one snapshot. Restore puts the vendor directory back, and a fresh module manager resolves the same routes and class file. The packages directory lists only that vendor, then nothing, then only that vendor. |

Gates: production verifier PASS; PHPUnit + PHPStan PASS; test verifier PASS; PHPUnit + PHPStan PASS.

### Composer runtime (Checklist Step 5)

Uninstall deletes the package folder through the file service. `composer/composer` is not a runtime dependency. `autoload.php` loads only the application vendor autoload. `path.artifact` and the tracked Composer package tree are gone.

| File | Change |
|---|---|
| `app/package/src/PackageManager.php` | The constructor keeps the output setup and the `extension.failures` read. `removeFiles()` deletes through the file service. `getVersion()` returns the `composer.json` version, or `0.0.0` when that file names none. |
| `app/package/src/Helper/Composer.php` (deleted) | The Composer install helper. |
| `app/package/src/Helper/Factory.php` (deleted) | The Composer factory beside it. |
| `app/package/src/Helper/InstallerIO.php` (deleted) | The console IO adapter beside it. |
| `autoload.php` | Returns `require __DIR__ . '/app/vendor/autoload.php'`. Nothing under `packages/` is loaded. |
| `public/index.php` | `path.artifact` is not registered. |
| `composer.json` | `composer/composer` left `require`. `ext-zip` stays in `require`. |
| `composer.lock` | `composer remove composer/composer`. Nine packages left the lock. Ten moved from `packages` to `packages-dev`. No version changed. `packages[*].name` has no `composer/composer`. `platform` keeps `ext-zip`. |
| `phpstan.neon` | `excludePaths` no longer names `packages/autoload.php` or `packages/composer`. |
| `phpstan-baseline.neon` | The six `Helper/Composer.php` entries are gone. |
| `.prettierignore` | The `packages/composer/` entry is gone. |
| `packages/autoload.php`, `packages/packages.php`, `packages/packages.lock`, `packages/composer/` (deleted) | The tracked Composer package tree. |
| `tmp/packages/.gitignore`, `tmp/packages/.htaccess` (deleted) | The tracked artifact directory. |
| `Dockerfile` | The image `mkdir` no longer creates `tmp/packages`. |
| `docker/entrypoint.sh` | The entrypoint `mkdir` no longer creates `tmp/packages`. |
| `.cursor/install.sh` | The install `mkdir` no longer creates `tmp/packages`. |
| `.github/workflows/{php-tests,infection,nightly,e2e-weekly,e2e}.yml` | The nine `mkdir` lines no longer create `tmp/packages`. |
| `AGENTS.md` | The writable-directory line no longer names `tmp/packages`. |

#### Tests (Checklist Step 5)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageInstallConstraintTest.php` (deleted) | Tested the helper. |
| `tests/Unit/Package/PackageRegistryWriteTest.php` (deleted) | Tested the helper. `registryHelperOf()` went with it. |
| `tests/Unit/Package/PackageTreeRemovalTest.php` | The Composer cases, doubles, bookkeeping helpers, and the `$composer` argument of `manager()` are gone. `plant()` no longer says an install leaves `installed.json` behind. |
| `tests/Unit/Package/PackageManagerMigrationTest.php` | `testConstructorResolvesPathsWithAndWithoutContainer` is deleted. Its halves stay as `testEnableWithoutContainerConfigStillRunsMigration` and `testEnableRunsExtensionMigrationAndRecordsVersion`. The class docblock drops the constructor-branch, registry, and Composer-transport sentences. |
| `tests/Unit/Package/PackageModuleBoundaryTest.php` | The four path-and-baseline cases, `composerPaths()`, and the `Helper\Composer` import are gone. `writeComposer()` stays. The installer-tree list no longer names the three helper files; the `app/installer/src/Helper` absence check stays. New cases: the `Composer\` namespace scan allows only `Composer\Autoload\ClassLoader` and fails on a fixture that names anything else; `autoload.php` names no `packages/`; the lock's non-dev set has no `composer/composer` and `require` names `ext-zip`; nothing under `app/`, `public/`, `tests/`, or `scripts/` names the deleted paths, while `public/.htaccess` is the one `installed.json` deny; `packages/` holds no Composer runtime; `main()` resolves `packageStaging` to `<path.temp>/packages`. The walk skips this file. |
| `tests/Unit/Package/PackageHookBarrierTest.php` | The `path.artifact` fixture is gone. The `container()` paths parameter no longer says the removal path reads it. |
| `tests/Unit/Package/PackageHookWarningTest.php` | The `path.artifact` fixture is gone. |
| `tests/Unit/Package/PackageFailureRecordTest.php` | The `path.artifact` fixture is gone. |
| `tests/Unit/Package/PackageSnapshotGateTest.php` | The `path.artifact` fixture is gone. `plant()` no longer says an install leaves `installed.json` behind. |
| `tests/Unit/Package/PackageUploadBoundaryTest.php` | The `path.artifact` fixture is gone. |
| `tests/Unit/Package/PackageInstallFromArchiveTest.php` | The `path.artifact` fixture is gone. |
| `tests/Unit/Console/InstallCommandTest.php` | The `path.artifact` fixture is gone. |
| `tests/Unit/Console/UninstallCommandTest.php` | The `path.artifact` fixture is gone. `plant()` no longer says an install leaves `installed.json` behind. |
| `tests/Unit/Snapshot/UploadedPackageRoundTripTest.php` | The `path.artifact` fixture is gone. |

Gates: production verifier PASS; production tester PASS; test verifier PASS; coverage tester PASS. No deviations.

### Marketplace surface (Checklist Step 6)

The installer marketplace route, menu, page, and bundle are gone. Extensions and themes no longer ask pagekit.com whether an update exists. Upload still installs a zip. The core update page stays.

| File | Change |
|---|---|
| `app/installer/src/Controller/MarketplaceController.php` (deleted) | The marketplace controller. |
| `app/installer/views/marketplace.php` (deleted) | The marketplace page. |
| `app/installer/app/components/marketplace.vue` (deleted) | The marketplace component. |
| `app/installer/app/views/marketplace.js` (deleted) | The marketplace view. |
| `app/installer/assets/images/icon-marketplace.svg` (deleted) | The marketplace menu icon. |
| `app/package/app/lib/update.vue` (deleted) | The package-update client. |
| `app/installer/index.php` | The `/system/marketplace` route and the three marketplace menu entries are gone. `system: update` stays. |
| `scripts/bundle-entries.mjs` | The installer group is `installer` and `update`. |
| `app/package/app/lib/package.js` | `queryUpdates()`, `update()`, and the `UpdateInstance` import are gone. |
| `app/package/app/lib/output.js` | `updatePkg` is gone. `update.vue` was its only reader. |
| `app/package/app/components/package-manager.js` | `updates`, `status`, `load()`, and `mounted()` are gone. |
| `app/package/app/components/package-details.vue` | The `api` prop, both alerts, `queryPackage()`, and the `Version` import are gone. The modal shows the package it was given. |
| `app/package/app/components/package-upload.vue` | The `api` prop and the live `:api="api"` binding are gone. |
| `app/package/views/extensions.php` | Both components are used without `api`. The Update column and its header cell are gone. |
| `app/package/views/themes.php` | Both components are used without `api`. The Update button is gone. |
| `app/package/src/Controller/PackageController.php` | `$systemApi` and both `'api'` payload keys are gone. |
| `app/package/src/PackageModule.php` | `main()` does not register `systemApi`. |
| `app/system/modules/theme/assets/less/theme.less` | The `[class*="system-marketplace-"]` selector is gone. |
| `public/.htaccess` | The CSP comment no longer says marketplace. The `connect-src` line is unchanged. |
| `phpstan-baseline.neon` | The `app/installer/views/marketplace.php` entry is gone. |
| `README.md` | Extensions & Themes describes installing and building an archive. Nothing is resolved or downloaded. |

#### Tests (Checklist Step 6)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageModuleBoundaryTest.php` | Case-sensitive `marketplace` and `packagist` under `app/` are the language catalogues. `systemApi` is the dashboard and `UpdateController`; `set('systemApi'` is `DashboardModule` only. `main()` registers no `systemApi`. `bundle-entries.mjs` has no `marketplace` entry. Both package views hand `package-upload` and `package-details` no `api`. The installer manifest has no `system: marketplace*` menu and no `/system/marketplace` route. The installer bundle group is `installer` and `update`. The import test keeps `update.js` and the dashboard. The permission test no longer expects the installer to name `system: manage packages` as access. `update.vue` left the must-exist list. |
| `tests/Unit/Console/ExtensionTranslateCommandTest.php` | The system walk asserts `app/installer/views/update.php`. |
| `tests/e2e/specs/02-core/dashboard.spec.js` | The Extensions link is `a[href*="/admin/system/package/extensions"]`. |

Gates: production verifier PASS; production tester PASS; test verifier PASS; coverage tester PASS. No deviations.

### Docs and closing gates (Checklist Step 7)

No file changed. `AGENTS.md` and `.cursor/BUGBOT.md` already omit the deleted paths, commands, and symbols, and so do `README.md` outside the rewritten section, `docs-site/`, `.github/`, `.cursor/install.sh`, and `scripts/`.

Gates: Verifier PASS; Tester PASS (PHPUnit + PHPStan); test-writer skipped. Deviation: the `use Composer\` and `Composer\[A-Z]` gates also list `tests/Unit/Snapshot/UploadedPackageRoundTripTest.php:7`, which imports the one allowed name `Composer\Autoload\ClassLoader`.

---

## 🧠 Key Decisions (Rationale)

- **Entry names collide after normalisation.** Two entries that match once a trailing `/` is dropped and the name is ASCII-lowercased are refused ("more than once"), and so is a file that is another entry's folder ("both as a file and as a folder") — the manifest read takes the first match, extraction would write every entry, including on a case-insensitive filesystem. Empty segments (`a//b`) refuse instead of collapsing. A `\` refuses before the drive check, so `C:\win.php` is the backslash refusal and `C:/win.php` is the drive one. A symlink is `attributes >> 16`, independent of host endian. `index.php` twice, `Index.php` beside `index.php`, or `a` beside `a/b` refuses and writes nothing.
- **An empty archive is its own refusal.** A missing, 0-byte, or non-ZIP file is "not a readable ZIP archive"; an archive with no entries is "The archive is empty." `\ZipArchive` will not write a file with no entries, so that fixture is the 22-byte end-of-central-directory record.
- **Each manifest file is capped at 1 MiB** (`MANIFEST_MAX_BYTES`). `composer.json` and `index.php` are read into memory whole, which the 512 MiB uncompressed total does not bound. Above the cap the refusal is "is too large".
- **Extraction streams entry by entry.** `ZipArchive::extractTo()` was rejected: it overwrites existing paths and cannot hold a file to the listing `open()` accepted. `extractTo()` re-opens the zip (no handle is kept) and throws if the entry count or any index's name, size, or CRC changed. Files open with `fopen('xb')`, so an existing path fails, and a write never passes the declared size. Size and CRC are checked in PHP ("is damaged") because the zip stream reports an end-of-entry CRC error as EOF. Modes are `0666` / `0777` under the umask. A failure leaves what was already written — cleanup belongs to `replaceTree()` — so a test asserts the exception, not an empty directory.
- **The manifest is the file's only `return` outside function and class bodies.** A closure, method, or anonymous-class return does not count; a second return outside those bodies refuses, because a condition or a `goto` would pick which one PHP runs. That return must be top-level, including under `namespace X;` (the node may hold the following code, or have null `stmts` with that code as siblings). A return that exists only inside `if`, `declare`, or `try` refuses. Keys resolve last-wins, and a spread or a computed key (not a string or integer literal) clears the literal keys before it. Every `autoload` path is folder-checked, including one a later key overrides. Each such refusal is an `ArchiveRefusedException`, never an `Error` or `TypeError`.
- **Script paths and autoload paths normalise differently.** Both drop empty and `.` segments. `''`, a leading `/`, a drive prefix, or any `..` refuses. Autoload `.` is the package root. Autoload values turn `\` into `/` first, as `AutoLoader` does; script paths do not. Scripts count only when the value is non-empty; a non-string value refuses.
- **Archive text quoted in a refusal is made printable.** Control characters and invalid UTF-8 become `?`.

Safety gates before any edit: the branch contained `origin/develop`, `composer install` was a no-op, and `pnpm install --frozen-lockfile && pnpm build` exited 0. The suite was left to the tester.

- **Reinstalling in the package's own folder is an update.** The same-path check compares `Path::directory()` of both sides, because factory paths are forward-slashed and `path.packages` need not be. The loaded-module check also requires the previous `module` to be a string. Streamed messages quote the validated composer name, never the title: the panel renders them with `v-html`. The manager writes no progress lines of its own.
- **A failed install leaves the packages directory as it was found.** Each hidden sibling has its own name. Failure also `@rmdir`s the vendor folder, so a first install that fails leaves neither a sibling nor an empty vendor directory. When the retired tree cannot be put back, that sibling is kept — it is the only copy — its path is logged, and the message is the distinct "nor the installed ones put back" line.
- **A retired tree that will not delete does not fail the install.** One streamed line and an error log name its path. Opcache invalidation then walks the new tree only. `Filesystem::delete()` follows a symlinked directory inside that retired tree: an archive cannot contain a link, and a link placed by hand in an installed tree is followed, the same hazard as `removeFiles()`.
- **Nothing is staged until the payload can be built.** `PackageFactory::load()` and the `extra.icon` / `extra.image` strip run before `move()`. A composer document the factory cannot load is a 400 and `packageStaging` stays empty. The upload is accepted with `instanceof UploadedFile`. The `load()` phpdoc is `string|array<array-key, mixed>` so integer keys from `PackageArchive::composer()` type-check.
- **The staged file must be the package the request names.** `a-b/c` and `a/b-c`, and versions that contain `-`, share one filename, so `install()` runs only when the opened archive's `name()` and `version()` equal the request. A value that fails the patterns gets a fixed line and is not echoed. A missing file names the validated name and version. `discardStaged()` unlinks a file or a link and does not recurse; a directory, or anything else it cannot remove, is an error-log line only.
- **Install and uninstall share `failure()`.** `removalFailure()` became `failure(string $context, \Throwable $e, string $generic)`. The uninstall call keeps its log context and its generic line. The cache-clear log line reads "after installing or removing a package".
- **The console command catches `\RuntimeException`.** That covers `ArchiveRefusedException`. Success goes through `info()`. A non-string `archive` argument is a `LogicException`: unreachable for the required argument, and present so the type narrows.
- **`update()` posted `packagist` until Step 6.** At Step 2, `update.vue` and `package.js` `update()` still requested `admin/system/package/install` with that flag. The endpoint did not read it, and with nothing staged the stream ended `status=error`. Step 6 deleted that client.
- **Each rule source is one rule per line.** The `.gitignore` text, then each `archive.exclude` string, is split on line breaks; blanks and `#` lines are skipped, so `Gitignore::toRegex()` never receives two rules, including from a multi-line string. A package with no `composer.json` is archived. An unreadable `.gitignore` or `composer.json`, invalid JSON, a `composer.json` that is not an object (a list included), an `archive.exclude` that is present but not a list of strings (`null` included), or a package with no file left exits `FAILURE` and writes no zip. A non-object `archive` adds no exclude rules; `"exclude": {}` is no rules. The name is checked before `path.packages` or the output path is read, and that refusal echoes nothing.
- **A link outside the package is omitted.** Its real path is skipped, so a link cannot carry `config.php` into the zip. A link inside is stored as the file it points to.
- **The zip replaces the previous one only after it is complete.** It is written at `<target>.<hex>` and renamed over `<target>` after every `addFile()` and `close()`. A failure unlinks that file, so an earlier archive survives. `--dir` is created only once there is something to write. Entries are files only, in name order.
- **A rule the matcher cannot apply fails the command.** `preg_match()` that cannot apply names the rule and exits `FAILURE`. Skipping it would archive the files the rule was meant to drop. The library compiles `[]]`, `[!]` and `[z-a]` to invalid regexes. `archive.exclude: ["[]]"]` writes no zip.
- **An unfinished snapshot still stops inside the package tree.** The double that used to refuse copying `installed.json` now refuses `views/extension.php`, so the archive breaks off after `composer.json` and the assertions stay. Refusing the `complete` mark was rejected: that leaves a whole tree. `create()` throws, one unmarked snapshot stays listed, and one `warning` carries `trigger=create`.
- **A `composer` key already on disk is not migrated.** `read()` builds its result from the keys it still has, so the field is ignored and the file is left as stored. `SnapshotControllerTest::place()` and `SnapshotRetentionTest::place()` still plant `'composer' => false`; those tests were left outside this step. The store test writes the key onto a finished snapshot and asserts the file keeps it through a restore.
- **The constructor-path case is deleted.** It asserted the two path branches, which are gone. Keeping it under another name would assert nothing `testEnableWithoutContainerConfigStillRunsMigration` (only `migration`) and `testEnableRunsExtensionMigrationAndRecordsVersion` (no `path.*`) do not already hold. The manager builds and enables when the container names no `path.*` service.
- **The installer-tree list dropped the three helpers.** `testTheManagerHelperAndSnapshotEngineLeftTheInstallerTree` reads every path it lists, so the `Helper/*.php` entries went. The check that `app/installer/src/Helper` is absent stays. The retired-helper names in `testTheDetectorReportsALineInTheRetiredHelperNamespace` are in-memory fixtures and stay.
- **`path.artifact` also left the four tests written after the plan.** `PackageUploadBoundaryTest`, `PackageInstallFromArchiveTest`, `InstallCommandTest`, and `UploadedPackageRoundTripTest`. The sibling `path.temp`, `path.cache`, `path.vendor`, and `system.api` fixture lines stay. The manager no longer reads them. `plant()` in the removal, snapshot-gate, and uninstall tests no longer says an install leaves `installed.json` behind.
- **`public/.htaccess` still denies `installed.json` by name.** The rule sits beside `bower.json`. It is not a reader, and the path scan does not open `.htaccess` (`SOURCE_EXTENSIONS` has no `htaccess`). One assertion allows exactly that line.
- **The lock diff is a section move.** `composer remove composer/composer` listed no update on its dry run. Nine packages left the lock. Ten moved from `packages` to `packages-dev`: `composer/semver`, `composer/xdebug-handler`, `symfony/process`, `justinrainbow/json-schema`, `react/promise`, `marc-mabe/php-enum`, `composer/pcre`, and the `symfony/polyfill-php80`, `php81`, and `php84` packages. No version changed. `packages[*].name` holds no `composer/composer`. `platform` keeps `ext-zip`.
- **Two remnants of the deleted page went with it.** `theme.less` dropped `[class*="system-marketplace-"] .tm-content`: the body class is the request path, so the selector matched that page alone. `output.js` dropped `updatePkg`, which only `update.vue` read. `package-upload.vue` dropped the live `:api="api"` binding as well as the commented one. Case-sensitive `marketplace` and `packagist` have no hit under `app/` outside `app/system/languages`.
- **`systemApi` is the dashboard's registration.** `PackageModule` no longer sets it. `UpdateController` still reads its own `system.api` for `update.js`. A case-insensitive scan also still lists the two `TODO: Step 5.6 (Marketplace & Extensions)` titles (`SelfupdateCommand.php`, `SelfUpdater.php`) — capital M, so the case-sensitive scan is empty — and `tests/e2e/TEST_PLAN_ANALYSIS_2025.md` plus `tests/e2e/COMPLETE_TEST_PLAN.md`. `systemApi` under `app/` is `app/system/modules/dashboard/**` and `UpdateController.php`. `set('systemApi'` is `DashboardModule.php` only.
- **The installer no longer names `system: manage packages`.** The marketplace menu was that permission's only consumer there. The boundary test no longer expects the access line; it still expects the installer not to declare the permission. `update.vue` left the must-exist list. The import test is `testTheUpdateAndDashboardImportThePackageClient`.
- **A `public/` built before this step keeps the marketplace bundle.** `scripts/bundles.mjs` builds with `emptyOutDir: false`, and `publishStatics()` deletes nothing, so `public/app/installer/app/bundle/marketplace.js` and `public/app/installer/assets/images/icon-marketplace.svg` stay on an in-place upgrade as unreferenced files. Both were removed from this environment's gitignored `public/`. A fresh checkout and the production image never have them.
- **README describes the archive install.** Installing over an existing package replaces its folder and keeps an enabled package enabled. `php pagekit archive` writes `<vendor>-<name>.zip` into the installation root when `--dir` is omitted. An archive holds sources only; built bundles stay under `public/` until Step 2.8. `index.php` spells `name` and `autoload` as literals because the seam reads the file without running it. The E2E Testing bullet left the shipped list. The note that a marketplace has to be built from scratch is gone.
- **The 1.2.42 changelog sentence is now false.** It still says the marketplace menu names `system: manage packages` as access, and that marketplace and update stay on `installer`. From this step only update stays, and the installer manifest does not name that permission. The sentence stays until Finalize.
- **The core update path and the autoloader are unchanged.** Against `origin/develop` (`7110583f`) there is no diff in `UpdateController.php`, `SelfupdateCommand.php`, `update.js`, `update.php`, `DashboardModule.php` (still the one `set('systemApi'`), `AutoLoader.php`, `ModuleManager.php`, `.gitignore`, or `phpunit.xml.dist`. `public/index.php` differs by the `path.artifact` line alone; `system.api` is still `https://pagekit.com`. `SnapshotStore` keeps `ID_PATTERN`, `METADATA_FILE`, `DUMP_FILE`, `FILES_DIR`, and `COMPLETE_FILE`. Both `TODO: Step 5.6 (Marketplace & Extensions)` notes remain.
- **`git` stays in the image, and AP-06 still names the old helper.** `Dockerfile` installs `git` for Composer's own `--prefer-source` dependency installs, which is separate from the removed install option. `.cursor/ANOMALIES.md` AP-06 is hand-maintained and still shows the pre-2.7.1b `InstallerIO` path; agents do not write that file.
- **The Composer gates also name the round-trip import.** Both `use Composer\` and `Composer\[A-Z]` list `tests/Unit/Snapshot/UploadedPackageRoundTripTest.php:7` (`use Composer\Autoload\ClassLoader;`). The boundary scan already allows that one name. Rewriting the import as an inline class name was rejected: it would change nothing the criterion checks. The allow-list stays exactly `Composer\Autoload\ClassLoader`.

---

## 💥 Breaking Changes (Extensions)

`php pagekit install <archive>` installs one zip and prints `Installed <name> <version>.` The command previously took a package list and `--prefer-source` and always failed. `PackageManager::install()` takes a `PackageArchive`. A package's own manifest is unchanged. `php pagekit update` is gone. The web self-update log is plain text: the default formatter strips the console tags. A snapshot listing no longer includes `composer`. `read()` omits the key on a file an earlier release wrote; that file is not rewritten, and restore does not write `installed.json` back. `composer/composer` is not a runtime dependency. `autoload.php` loads only `app/vendor/autoload.php`. Uninstall deletes the package folder through the file service. `path.artifact` is not registered. `getVersion()` reads the package `composer.json` and returns `0.0.0` when that file names no version. The marketplace route, its three menu entries, and the package-update client are gone. Extensions and themes receive no API URL and show no Update button. `PackageModule` does not register `systemApi`. The dashboard still does. The core update page still reads `system.api`. `connect-src` still allows `https://pagekit.com`.

---

## ⚠️ Risks & Rollout Notes

- `composer install` now requires the zip extension, including on a host that previously needed it only for dev.
- `replaceTree()` deletes the staged sibling when extraction or the swap fails. A delete that fails is an error-log line and the sibling stays. A retired tree that will not delete is one streamed line plus that log, and the install still succeeds.
- An installation whose `public/` was built before this step can still hold `app/installer/app/bundle/marketplace.js` and `app/installer/assets/images/icon-marketplace.svg`. Nothing references them. A fresh checkout and the production image do not.
- A failed `php pagekit archive` leaves the previous zip. The new file is renamed into place only after `close()`.
- An `archive.exclude` rule the matcher cannot apply fails the command and writes no zip.
- A symlink whose target lies outside the package is omitted from the zip. The web self-update log no longer wraps console tags in markup.
- A snapshot taken earlier may still contain `installed.json` and a `composer` key in `metadata.json`. Restore leaves both files alone. `read()` does not return the key.
- A production install no longer contains `composer/composer`. Ten of its former dependencies sit in `packages-dev`, with no version change.
- Images, the entrypoint, the install script, and CI no longer create `tmp/packages`. An upload is staged under `path.temp/packages`.
- `public/.htaccess` still denies a file named `installed.json`. Nothing in the application reads that file.

---

## 🔐 Security & Data Impact

`open()` is the trust boundary for an untrusted zip and writes nothing. Refused before a byte is written: a path that leaves the package, a `\`, a NUL, an absolute or drive path, a symlink entry, an uncompressed total above 512 MiB, a name that collides after case-folding, and a file that is also a folder. The PHP manifest is parsed, not executed, and must be one static top-level return. `extractTo()` will not overwrite a path, will not follow a link planted at the target, and does not trust the zip stream for size or CRC.

Install calls that boundary. The target is `<path.packages>/<validated name>`. Upload opens the PHP temp file and stages only after the archive, the page type, and `PackageFactory::load()` pass. Install reads `name` and `version` only, refuses a value that fails the patterns without echoing it, re-opens the staged file, and refuses it when that archive's own name or version differs. The staged path is unlinked afterwards and never walked. A retired tree that `Filesystem::delete()` cannot remove stays as a hidden sibling and the install still succeeds. That delete follows a symlinked directory inside the retired tree: an archive cannot plant one, and a link placed by hand in an installed tree is followed, the same as `removeFiles()`.

`php pagekit archive` runs no package-supplied command. The name is checked before a path is read, and a traversing name is refused without being echoed. `Gitignore::toRegex()` receives one rule at a time. A link whose real path leaves the package is omitted.

A snapshot no longer copies `packages/composer/installed.json`. Restore does not write that file back. `read()` omits `composer` and does not rewrite `metadata.json`.

Uninstall does not ask Composer whether the package is installed. `removeFiles()` deletes the tree through the file service. `getVersion()` reads the package `composer.json` only. `autoload.php` does not load `packages/`. `public/.htaccess` still denies `installed.json` by name; that rule is not a reader.

The marketplace client is gone. Extensions and themes receive no API URL and do not call pagekit.com. `connect-src` still allows `https://pagekit.com`: the dashboard update check and the core update page use it. The comment no longer says marketplace.

---

## 🛡️ No-Mercy Compliance

The install path is `PackageArchive` only. `removeFiles()` deletes the tree through the file service. The Composer helper, its factory, and its IO adapter are deleted. `composer/composer` is not in `require`. No stub body, no `packagist` branch, no `class_alias`. The theme's `'autoload' => []` is the declaration the seam requires. `ArchiveCommand` no longer imports Composer. `UpdateCommand` is deleted. `SelfUpdater` no longer sets `HtmlOutputFormatter`. `BuildCommand`'s commented Composer install is deleted. The snapshot path no longer reads or writes Composer's record, and the `composer` key is not kept for old snapshots. The marketplace controller, page, bundle, and `update.vue` are deleted. `PackageModule` does not register `systemApi`. No stub client is kept for Step 5.6.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: Step 1 — none. Step 2 — tester PASS after one test-defect retry. Production verifier PASS; production tester PASS; frontend, fresh install, and install-over PASS; test-file verifier PASS. Step 3 — production verifier FAIL once (`archive.exclude` typing), retry then PASS; PHPUnit + PHPStan PASS; test verifier PASS; PHPUnit + PHPStan PASS. Step 4 — production verifier PASS; PHPUnit + PHPStan PASS; test verifier PASS; PHPUnit + PHPStan PASS. `rg` for `INSTALLED_FILE|composerInstalled|BOOKKEEPING|bookkeeping` under `app` and `tests` still lists `PackageTreeRemovalTest` (Step 5's inventory) and the word in `PackageManagerMigrationTest` and `PackageSchemaTest`. `app/package/src/Snapshot` and `tests/Unit/Snapshot` have no hit. Step 5 — production verifier PASS; production tester PASS; test verifier PASS; coverage tester PASS. No deviations. Step 6 — production verifier PASS; production tester PASS; test verifier PASS; coverage tester PASS. No deviations. Step 7 — Verifier PASS; Tester PASS (PHPUnit + PHPStan); test-writer skipped. The `use Composer\` and `Composer\[A-Z]` gates also list `tests/Unit/Snapshot/UploadedPackageRoundTripTest.php:7`, which imports the one allowed name `Composer\Autoload\ClassLoader`.

---

## 📋 Phase 1 Audit Closure

_TBD / None_

---

## 👤 Maintainer action

<!-- Human-only follow-ups the maintainer must do (ruleset flips, real Docker/Apache
     verification, secrets, etc.). Not ROADMAP deferrals — those go under Deferred. -->

`Dockerfile` and `docker/entrypoint.sh` no longer create `tmp/packages`. Those files were linted in this environment. A boot of the production image — the `Docker Image` workflow, or `docker build .` on a Docker host — is what shows the image still starts.

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

- Ticket: `migration-docs/tickets/active/PROMPT_2_7_1c_Runtime-Composer-Removal_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_1c_Runtime-Composer-Removal.md`
- Predecessor: Step 2.7.1b — Package Module Boundary
- Successor: Step 2.7.2 — Module Dependency Integrity

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
