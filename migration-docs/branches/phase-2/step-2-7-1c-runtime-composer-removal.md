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

A package zip can be refused, read, and extracted without Composer, and that is how a package is installed. The panel upload and `php pagekit install <archive>` replace the package tree through the seam. Uninstall still goes through the Composer helper. `ext-zip` is a runtime requirement, and the shipped One theme declares an empty autoload map so the seam accepts it.

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
- **`update()` still posts `packagist`.** `app/package/app/lib/update.vue` still requests `admin/system/package/install` with that flag. The endpoint does not read it, and with nothing staged the stream ends `status=error`. That surface goes in Step 6.

---

## 💥 Breaking Changes (Extensions)

`php pagekit install <archive>` installs one zip and prints `Installed <name> <version>.` The command previously took a package list and `--prefer-source` and always failed. `PackageManager::install()` takes a `PackageArchive`. A package's own manifest is unchanged.

---

## ⚠️ Risks & Rollout Notes

- `composer install` now requires the zip extension, including on a host that previously needed it only for dev.
- `replaceTree()` deletes the staged sibling when extraction or the swap fails. A delete that fails is an error-log line and the sibling stays. A retired tree that will not delete is one streamed line plus that log, and the install still succeeds.
- The package update UI still posts `packagist` to the install endpoint. With nothing staged the stream ends `status=error`. That surface goes in Step 6.

---

## 🔐 Security & Data Impact

`open()` is the trust boundary for an untrusted zip and writes nothing. Refused before a byte is written: a path that leaves the package, a `\`, a NUL, an absolute or drive path, a symlink entry, an uncompressed total above 512 MiB, a name that collides after case-folding, and a file that is also a folder. The PHP manifest is parsed, not executed, and must be one static top-level return. `extractTo()` will not overwrite a path, will not follow a link planted at the target, and does not trust the zip stream for size or CRC.

Install calls that boundary. The target is `<path.packages>/<validated name>`. Upload opens the PHP temp file and stages only after the archive, the page type, and `PackageFactory::load()` pass. Install reads `name` and `version` only, refuses a value that fails the patterns without echoing it, re-opens the staged file, and refuses it when that archive's own name or version differs. The staged path is unlinked afterwards and never walked. A retired tree that `Filesystem::delete()` cannot remove stays as a hidden sibling and the install still succeeds. That delete follows a symlinked directory inside the retired tree: an archive cannot plant one, and a link placed by hand in an installed tree is followed, the same as `removeFiles()`.

---

## 🛡️ No-Mercy Compliance

The install path is `PackageArchive` only. `removeFiles()` still calls the Composer helper; that is the uninstall sequence, and it is not a second install path. No stub body, no `packagist` branch, no `class_alias`. The theme's `'autoload' => []` is the declaration the seam requires.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: Step 1 — none. Step 2 — tester PASS after one test-defect retry. Production verifier PASS; production tester PASS; frontend, fresh install, and install-over PASS; test-file verifier PASS.

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

- Ticket: `migration-docs/tickets/active/PROMPT_2_7_1c_Runtime-Composer-Removal_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_1c_Runtime-Composer-Removal.md`
- Predecessor: Step 2.7.1b — Package Module Boundary
- Successor: Step 2.7.2 — Module Dependency Integrity

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
