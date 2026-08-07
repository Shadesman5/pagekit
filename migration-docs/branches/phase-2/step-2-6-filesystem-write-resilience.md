# Step 2.6 — Filesystem Write Resilience

<!-- Branch doc for Roadmap Step 2.6.
     Path: migration-docs/branches/phase-2/step-2-6-filesystem-write-resilience.md -->

**Branch:** `feature/filesystem-write-resilience`
**ROADMAP Step:** 2.6 (Filesystem Write Resilience — Atomic Writes & Error-Handling Hygiene)
**GitHub Issue:** [#257](https://github.com/Shadesman5/pagekit/issues/257)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-08-07 20:54
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### Atomic-write primitive: `Filesystem::dumpAtomic()` (Checklist Step 1)

| File | Change |
|---|---|
| `app/modules/filesystem/src/Filesystem.php` | New `dumpAtomic(string $file, string $content, ?int $mode = null): void` on the existing `file` service — no new class, no new service id. Resolves the target via `getPathInfo()` and requires protocol `file` with a non-empty `pathname`, else throws `\InvalidArgumentException` (refuses a stream wrapper or an adapter-backed path rather than degrading it to a non-atomic write). When the target is itself a symlink, the write target is resolved to its `realpath()` first (see Key Decisions). Writes content to a `tempnam()` temp file in the target's own directory, `chmod`s it to the target's existing permission bits when the target already exists or `($mode ?? 0666) & ~umask()` when it doesn't, then `rename()`s over the target; a blocked rename (a reader holding the target open — Windows only) falls back to a direct `file_put_contents($target, …, LOCK_EX)`; the temp file is removed on every failure path; total failure throws `\RuntimeException`. Either path ends in the new private `invalidateOpcache()` (`opcache_invalidate($target, true)` when the extension is loaded). No caller wired to it yet (Checklist Steps 2–4). |

### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `app/modules/filesystem/src/Tests/DumpAtomicTest.php` (new) | Real-filesystem coverage (`FileUtil` trait, no vfsStream) of `dumpAtomic()`: fresh write reads back correctly via `require`; replacing an existing file leaves no temp-file residue; a reader holding the target open never observes a half-written replacement (skipped on a platform that allows the rename regardless); a write through a symlink lands on the file the link points at and leaves the link itself in place; created-file permissions across a `null` mode, a mode the umask narrows, and a mode stricter than the umask; replacing a `0600`-hardened file keeps `0600`; a missing target directory, a target occupied by a directory, and an unwritable target directory each throw `\RuntimeException` with no temp-file residue (root-aware skip on the permission-based cases); a remote URL, a stream wrapper and an empty path each throw `\InvalidArgumentException`; a path routed through a registered adapter is refused rather than silently written as a local file. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Router `writeCache()` delegates to the primitive (Checklist Step 2)

| File | Change |
|---|---|
| `app/modules/routing/src/Router.php` | Constructor gains a trailing `Filesystem $files = new Filesystem()` collaborator (new-in-initializer default; no `index.php`/manifest change, no `filesystem` module dependency added). `writeCache()`'s own `tempnam()`/`chmod()`/`rename()`/`file_put_contents()` fallback body is deleted and replaced with a single delegation to `$this->files->dumpAtomic($file, $content)`; the method's `@throws \RuntimeException` contract is unchanged. `getCache()` freshness logic, the matcher/generator dumpers, and the corrupt-cache fallback in `getMatcher()`/`getGenerator()` are untouched. |

### Tests (Checklist Step 2)

| File | Change |
|---|---|
| `app/modules/routing/src/Tests/RouterTest.php` | Three new tests: `testDumpedCacheIsWrittenThroughTheFilesystem` (a stub `Filesystem` records each call; asserts `dumpAtomic()` receives the cache file path and the dumped matcher content, and that the real file lands on disk); `testUnwritableCacheDegradesToTheUncachedRouter` (a stub `Filesystem` throws `\RuntimeException` from every `dumpAtomic()` call — matching and generation still succeed from the non-cached path, both dumps were attempted, and the cache directory is left empty); `testCacheIsWrittenWithoutAnInjectedFilesystem` (a router built with the constructor's default `Filesystem` still writes a real cache file). Existing `testCorruptCacheFileFallsBackInsteadOfFatal` and `testCacheKeyReflectsRouteAffectingOptions` are unmodified. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done (delegation test + unwritable-cache degrade test); Verifier (test files) PASS; Tester FAIL once (an assertion on the post-`match()` generated URL required an exact path, too strict once the request context's base URL is prefixed onto it) → test-writer retry; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Config writers onto the primitive: Installer + SettingsController (Checklist Step 3)

| File | Change |
|---|---|
| `app/installer/src/Installer.php` | The install-time `config.php` write — previously `if (!file_put_contents($this->configFile, $configuration->dump()))` — is replaced by `try { $this->app->get('file')->dumpAtomic($this->configFile, $configuration->dump()); } catch (\RuntimeException $e) { $status = 'write-failed'; throw new BadRequestHttpException(__('Can\'t write config.'), $e); }`. The `write-failed` status and the user-facing message are unchanged; the caught primitive exception is now chained onto the `BadRequestHttpException` instead of being discarded. |
| `app/system/modules/settings/src/Controller/SettingsController.php` | Constructor gains a trailing `private readonly Filesystem $file`, resolved by the container against the `file` service id (parameter-name matching — no `index.php` change). `saveAction()`'s `file_put_contents($file, $fileConfig->dump())` — whose return value was previously ignored — is replaced by `$this->file->dumpAtomic($file, $fileConfig->dump())`, which throws instead of silently returning `['message' => 'success']` on a failed write; the method's own trailing `opcache_invalidate($file)` call is deleted (the primitive now does it centrally). Docblock documents the new `@throws \RuntimeException` and that the failure is left to propagate. |

### Tests (Checklist Step 3)

| File | Change |
|---|---|
| `tests/Unit/Installer/InstallerConfigWriteTest.php` (new) | Runs `Installer::install()` end-to-end against a real temp application root and a real `Filesystem` service (DB, password hashing, module cache and content script are stubs — only the config write is real): a successful install's `config.php` reads back the posted database connection, a `false` debug flag and a 64-char secret, with nothing else left in the root; a root `chmod`'d `0555` (skipped when the test user can still write it) makes the install report `status: write-failed` with the `Can't write config.` message and leaves no partial file behind. |
| `tests/Unit/Settings/SettingsControllerTest.php` (new) | Constructs the controller directly — with a `require_once` of the settings-module file, since `Pagekit\System\` autoloads to `app/system/src`, not the module — against a real temp `config.php` and a real `Filesystem`: a save rewrites the edited key, carries the untouched connection and secret over, and leaves only `config.php` in the root; the database-backed options are recorded separately from the file; and hardening both the file and its directory to read-only (skipped when still writable) makes `saveAction()` throw instead of returning `['message' => 'success']`, leaves the original `config.php` content and the options store untouched, and leaves no stray file behind — the regression this step fixes. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done (Installer write-failed regression + SettingsController ignored-write-failure regression); Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Package registry onto the primitive: Composer helper + PackageManager wiring (Checklist Step 4)

| File | Change |
|---|---|
| `app/installer/src/Helper/Composer.php` | Constructor gains a trailing `?Filesystem $files = null, ?LoggerInterface $logger = null` (`$files ??= new Filesystem()`, `$logger ??= new NullLogger()` — `psr/log` was already a direct dependency). `writeConfig()`'s `file_put_contents($this->file, '<?php return ' . var_export($this->packages, true) . ';')` — whose return value was never checked — is replaced by `$this->files->dumpAtomic($this->file, ...)`; the docblock now documents `@throws \RuntimeException`. `readConfig()`'s `require` path, the `packages.php` layout and the `blueprint`/install/uninstall flow are unchanged. |
| `app/installer/src/Package/PackageManager.php` | Constructor resolves the container's `file`/`log` services (each read only after its own `has()` check) and passes them into `new Composer(...)`, but only when each is actually `instanceof Filesystem` / `instanceof LoggerInterface` — `ContainerInterface::get()` returns `mixed`, so a container that registers either id with a non-conforming value now leaves the helper on its own `Filesystem`/`NullLogger` default instead of a `TypeError` at construction. |

### Tests (Checklist Step 4)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageRegistryWriteTest.php` (new) | Builds the `Composer` helper directly (a `RegistryComposer` subclass exposing `writeRegistry()`/`readRegistry()`) against a real temp `path.packages` directory: a written registry `require`s back to the exact package list and is readable through a second helper instance; a helper given a recording `Filesystem` writes through that instance, not a default one; replacing an existing registry leaves only `packages.php` in the vendor directory (no temp-file residue); a registry directory hardened read-only (skipped when still writable) makes the write throw `\RuntimeException` naming the target file, leaving the previous registry content and directory listing untouched; a helper built with no `Filesystem` argument still writes the registry itself via its own default. |
| `tests/Unit/Package/PackageManagerMigrationTest.php` | One new test, `testConstructorGivesTheRegistryHelperTheContainersFilesystemAndLogger`: builds the manager against a container exposing a recording `Filesystem` and a mocked `LoggerInterface`, drives `addPackages()`/`writeConfig()` on the `Composer` helper the manager built (via a new `registryHelperOf()` reflection helper, since `install()`/`uninstall()` reach the registry write only behind a live Composer run), and asserts the write happened through the container's own filesystem service — not a default one the helper made for itself — and that the helper's `logger` property is the container's mock, not a discarding default. |

Gates: Verifier (production) PASS after a comment-hygiene fix-loop; Tester PHPUnit+PHPStan PASS after adding the `instanceof` guards in `PackageManager` so a non-conforming stub `file`/`log` container service can't reach the `Composer` constructor untyped; test-writer done; Verifier (test files) PASS after a fix pinning that the manager passes its *own* filesystem/logger through to the `Composer` helper it builds, not merely that the helper accepts one; Tester PHPUnit+PHPStan PASS.

---

## 🧠 Key Decisions (Rationale)

- **`dumpAtomic()` resolves a symlinked target before the temp+rename dance (Checklist Step 1).** A `rename()` replaces whatever directory entry it is pointed at, so renaming straight over a symlinked `config.php` would replace the link itself with a plain file and orphan whatever it pointed at — a Docker deployment's `$PAGEKIT_DATA_DIR` volume (Step 2.5) is the concrete case this guards against. `dumpAtomic()` resolves the target to `realpath()` first whenever it is a symlink, so the temp file, the permission carry-over and the final `rename()` all act on the file the link points at, and the link itself survives untouched.
- **`PackageManager` guards the container's `file`/`log` services with `instanceof` before handing them to `Composer` (Checklist Step 4).** `ContainerInterface::has()` only proves a service id is registered; `get()` returns `mixed` and guarantees nothing about the value's type. `Composer`'s constructor types its collaborators as `?Filesystem`/`?LoggerInterface`, so without the guard a container that registers `file`/`log` as something else (a test stub built for a different purpose, or any future non-conforming registration) would hit a `TypeError` at `new Composer(...)` instead of falling through to the helper's own `Filesystem`/`NullLogger` defaults.

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

---

## ⚠️ Risks & Rollout Notes

- **The rename-blocked fallback write is not atomic (Checklist Step 1).** Where a reader holding the target open blocks the `rename()` — reachable only on Windows — `dumpAtomic()` falls back to a direct `file_put_contents($target, …, LOCK_EX)`, on which a concurrent reader without its own lock can observe a partially written file. The primitive's only wired-up deployment target so far is the Docker Linux production image (Step 2.5), where this path cannot be reached.

---

## 🔐 Security & Data Impact

- **A symlinked `config.php` keeps pointing at its target after a write (Checklist Step 1).** Resolving the link before the temp+rename dance (see Key Decisions) means the first write through `dumpAtomic()` cannot silently sever the link a Docker deployment relies on to keep `config.php` on `$PAGEKIT_DATA_DIR` (Step 2.5).
- **An existing target's permission bits survive a rewrite (Checklist Step 1).** `dumpAtomic()` carries `fileperms($target) & 0777` onto the replacement when the target already exists, so an operator-hardened `config.php` (e.g. `0600`) is not silently widened back to a fresh-file default by the next write through it.
- **A failed settings save now fails loudly instead of reporting success (Checklist Step 3).** `SettingsController::saveAction()` used to ignore the return value of its `config.php` write, so a hardened or read-only application tree got back `['message' => 'success']` over a file that was never touched. The write now throws and the exception is left to propagate, so a failed save surfaces as an error instead of a false positive that hides unsaved settings from the administrator.
- **A failed package-registry write no longer disappears silently (Checklist Step 4).** `Composer::writeConfig()`'s own `file_put_contents()` call ignored its return value entirely, so a `path.packages` directory an install/uninstall could not write left `packages.php` stale with no indication anything had failed. The write now throws `\RuntimeException` through `dumpAtomic()`, the same fail-loud fix Checklist Step 3 applied to the config writers.

---

## 🛡️ No-Mercy Compliance

- **Rule 4 (Delete over wrap) — Checklist Step 2:** `Router::writeCache()`'s own temp+rename+fallback body is deleted outright in favor of delegating to `Filesystem::dumpAtomic()` — no parallel write path or flag keeps the old logic alive alongside the primitive.
- **Rule 4 (Delete over wrap) — Checklist Step 3:** `SettingsController`'s own trailing `opcache_invalidate()` call is deleted outright now that `dumpAtomic()` invalidates centrally — no double invalidation kept alongside the primitive.
- **Rule 4 (Delete over wrap) — Checklist Step 4:** `Composer::writeConfig()`'s own `file_put_contents()` call is replaced outright by delegation to `Filesystem::dumpAtomic()` — no parallel write path survives for callers without an injected `Filesystem`; the helper's `$files ??= new Filesystem()` default is what stands in for a missing collaborator, not a second write branch.

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

- Ticket: `migration-docs/tickets/active/PROMPT_2_6_Filesystem-Write-Resilience_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_6_Filesystem-Write-Resilience.md`
- Predecessor: Step 2.5 — Docker Production Image & Deploy
- Successor: Step 2.7 — Extension Safety System

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
