# Step 2.6 — Filesystem Write Resilience

<!-- Branch doc for Roadmap Step 2.6.
     Path: migration-docs/branches/phase-2/step-2-6-filesystem-write-resilience.md -->

**Branch:** `feature/filesystem-write-resilience`
**ROADMAP Step:** 2.6 (Filesystem Write Resilience — Atomic Writes & Error-Handling Hygiene)
**GitHub Issue:** [#257](https://github.com/Shadesman5/pagekit/issues/257)
**Pull Request:** [#265](https://github.com/Shadesman5/pagekit/pull/265)
**Status:** ✅ Complete
**Started:** 2026-08-07 20:54
**Completed:** 2026-08-10 12:08

---

## 🎯 Overview

Ships the one shared atomic-write primitive `Filesystem::dumpAtomic()` and routes every boot-critical write in the tree through it. Extracted 1:1 from `Router::writeCache()`'s existing temp+chmod+rename pattern (plus a new centralized `opcache_invalidate()`), the primitive resolves a symlinked target before writing, carries an existing target's permission bits onto its replacement, refuses anything that isn't a plain local path, and throws instead of ever returning `false`. Five call sites move onto it in turn — the router cache (which loses its own duplicate temp+rename body), the installer's and settings screen's `config.php` writes (both now fail loudly instead of silently reporting success on an unwritable target), and the Composer helper's package registry — plus one error-handling hygiene pass: an empty `catch` around a range version constraint now logs instead of silently skipping the package, and `SelfupdateCommand`'s dead commented-out body is deleted outright. A mandatory Bugbot + Security review closed Checklist Step 6 with no corrective work, and the end-of-ticket E2E ran clean both then and again after a short Finalize fix-loop (a cs-fixer formatting fix plus a Codecov coverage-gap test that, in passing, proved the primitive's non-atomic fallback path reaches further than Checklist Step 1 had documented — see Risks & Rollout Notes).

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

### Error-handling hygiene: constraint log line + `SelfupdateCommand` cleanup (Checklist Step 5)

| File | Change |
|---|---|
| `app/installer/src/Helper/Composer.php` | `install()`'s `catch (\UnexpectedValueException $e)` block — previously empty, silently dropping a range-constrained package out of the forced-refresh list with no trace — now calls `$this->logger->info()` naming the package and its constraint (e.g. `^1.0`) before the loop continues; the package still installs and lands in the registry, only its forced re-download from `$refresh` is skipped. No other branch of `install()` changed. |
| `app/console/src/Commands/SelfupdateCommand.php` | The commented-out former `execute()` body (the pre-discontinuation download/update/migrate flow) and the now-unused `use Pagekit\Installer\SelfUpdater;` import are deleted. The `// TODO: Step 5.6` marker, the `error()` + `Command::FAILURE` refusal, `getVersions()` and `download()` are untouched. |

### Tests (Checklist Step 5)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageInstallConstraintTest.php` (new) | Drives `Composer::install()` through a `RecordingComposer` subclass (overrides `composerUpdate()` so no real Composer run happens) and a recording PSR-3 logger: a range constraint (`^1.0`) logs exactly one info record naming the package and the constraint while the package still lands in `$updated` and in the on-disk registry read back via `require`; an exact version (`1.2.3`) is dropped into the refresh list with no log line; a mixed install of both kinds logs once per range constraint and accounts for every package in both the update list and the registry; the log record's `context` is empty and its message never contains the marketplace URL's credentials. |
| `tests/Unit/Console/SelfupdateCommandTest.php` (new) | Drives `SelfupdateCommand` through a `CommandTester` against a `RecordingApplication` (records every container service id resolved): `execute()` returns `Command::FAILURE` and prints the discontinuation message both with and without `--url`, resolving no container service either time; `download()` (untouched by this step) still throws `\RuntimeException` for a missing URL and leaves no file behind — pinned as a regression guard alongside the cleanup. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Review (Bugbot + Security) + E2E — clean, no fix-loop (Checklist Step 6)

No production or test files changed — Bugbot and the Security Review found nothing to correct on the Checklist Steps 1–5 diff, so this step's mandatory review pass closed with no corrective work.

Gates: Bugbot clean (no bugs); Security Review clean (no medium/high/critical findings); Tester final E2E PASS (3 Playwright `@ci` specs, chromium-desktop). No fix-loop.

### Finalize fix-loop — cs-fixer formatting + coverage gap pass (post-Step-6)

| File | Change |
|---|---|
| `app/modules/routing/src/Tests/RouterTest.php` | cs-fixer FAIL on the PR's CI run: the two stub `Filesystem` subclasses Checklist Step 2 added (`testDumpedCacheIsWrittenThroughTheFilesystem`, `testUnwritableCacheDegradesToTheUncachedRouter`) were written as `new class extends Filesystem`; the project's cs-fixer ruleset requires the explicit constructor parens (`new class () extends Filesystem`). Both fixed; no behavioral change. |
| `app/modules/filesystem/src/Tests/DumpAtomicTest.php` | Coverage gap pass closing a Codecov patch-diff gap: new `testAnUnwritableTargetDirectoryStillRewritesAFileThatIsAlreadyThere` covers a target file that already exists in a directory that itself refuses new entries — `dumpAtomic()` cannot stage a temp file there, so it degrades straight to a direct rewrite of the file already in place, the same non-atomic fallback the rename-blocked path uses (see Risks & Rollout Notes); a new private `stagedFiles()` helper lists the platform's own temp-directory entries so the test can assert none is left behind by the degraded write. |

Gates: cs-fixer FAIL → fixed → PASS; coverage gap pass → PHPUnit + PHPStan PASS; PR #265 CI (final) — `phpunit (8.5)`, `phpunit-mysql`, `phpstan`, `cs-fixer`, `security-audit`, `version-ssot`, `frontend`, `infection-diff` all green (`e2e-smoke`/`e2e-merge` skip — draft PR); Cursor Bugbot — clean, 0 review comments; end-of-ticket E2E — PASS.

### Finalize fix-loop — atomic-or-throw hardening (post-Step-6)

| File | Change |
|---|---|
| `app/modules/filesystem/src/Filesystem.php` | `dumpAtomic()`'s remaining failure fallback — a direct `file_put_contents($target, …, LOCK_EX)` when the staged file could not be renamed onto the target — is deleted outright; every staging or `rename()` failure now discards the temp file and throws `\RuntimeException`, so the method's “atomic or throw” contract has no exception left. `discard()` now `chmod`s the temp file to `0600` before `unlink()`, so a temp file that had already picked up a read-only target's permission bits can still be removed instead of surviving cleanup as leftover `dum*.tmp` residue. A new check after `tempnam()` requires the temp file's own directory to resolve to the same directory as the target; without it, a target directory that refuses new entries can send `tempnam()` to the system temp directory instead of failing outright, and a later `rename()` across that filesystem boundary would silently degrade into a copy-and-unlink — no longer a single atomic step. A dead symlink — pointing at a file that does not exist yet, or looping back on itself — is now followed hop-by-hop instead of through one `realpath()` call, so the write still lands on the path the link chain leads to and never ends up replacing the link's own directory entry. |
| `app/modules/filesystem/src/Tests/DumpAtomicTest.php` | Realigned with the removed fallback: no test names or expects `LOCK_EX` behaviour any more. The unwritable-directory-with-an-existing-file case — previously the coverage for the degrade path — now asserts `dumpAtomic()` throws and the original file is left exactly as it was, since a write that cannot stage next to it can no longer rewrite it in place either. New platform-conditional skips guard the POSIX-permission and staging-probe assertions on a host that does not enforce them the same way. |
| `tests/Unit/Installer/InstallerConfigWriteTest.php` | The write-failed case no longer relies on a chmod'd-read-only directory to provoke a failure from the primitive; it now injects a `Filesystem` double (`UnwritableFilesystem`, throwing `\RuntimeException` from `dumpAtomic()`) so `Installer::install()`'s `write-failed` status and message are asserted the same way regardless of how a given host enforces directory permission bits. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS (one flaky unrelated `CachePoolTest` TTL hit on first run, green on re-run); Verifier (test files) PASS; Tester PASS after test-writer.

### Finalize fix-loop — coverage gap: `createStagingFile()` seam (post-Step-6)

| File | Change |
|---|---|
| `app/modules/filesystem/src/Filesystem.php` | Coverage gap pass closing a Codecov patch-diff gap (1 missing patch line): `dumpAtomic()`'s `tempnam()` call is extracted into a new `protected createStagingFile(string $directory): string\|false`, wrapping `@tempnam($directory, 'dump')` — a directory that accepts no staging file at all has no portable way to arrange on a real filesystem, so the `false`-return throw it leads to was otherwise unreachable from a test. `dumpAtomic()` now calls `$this->createStagingFile($dir)` in place of the direct `tempnam()` call; the throw itself (`\RuntimeException` on `false`) and the rest of the write are unchanged. |
| `app/modules/filesystem/src/Tests/DumpAtomicTest.php` | New coverage for the seam's throw branch via a `filesystemThatStagesNothing()` helper (a `Filesystem` subclass overriding `createStagingFile()` to return `false`): `testADirectoryThatAcceptsNoStagingFileFailsTheWrite` asserts `dumpAtomic()` throws `\RuntimeException` naming the target and leaves the workspace directory empty; `testADirectoryThatAcceptsNoStagingFileLeavesAnExistingTargetAsItIs` runs the same failure against a target that already exists and asserts its content and the directory listing are untouched. |

Gates: Verifier (production) PASS; Tester PASS; test-writer done; Verifier (test files) PASS; Tester PASS.

---

## 🧠 Key Decisions (Rationale)

- **`dumpAtomic()` resolves a symlinked target before the temp+rename dance (Checklist Step 1; hardened to hop-by-hop resolution in the Finalize atomic-or-throw fix-loop).** A `rename()` replaces whatever directory entry it is pointed at, so renaming straight over a symlinked `config.php` would replace the link itself with a plain file and orphan whatever it pointed at — a Docker deployment's `$PAGEKIT_DATA_DIR` volume (Step 2.5) is the concrete case this guards against. `dumpAtomic()` follows the link chain hop-by-hop rather than through a single `realpath()` call — the only way to also resolve a link whose target does not exist yet (an installation's first write to its data volume) without mistaking a self-referencing link for a valid target — so the temp file, the permission carry-over and the final `rename()` all act on the file the link points at, and the link itself survives untouched.
- **`PackageManager` guards the container's `file`/`log` services with `instanceof` before handing them to `Composer` (Checklist Step 4).** `ContainerInterface::has()` only proves a service id is registered; `get()` returns `mixed` and guarantees nothing about the value's type. `Composer`'s constructor types its collaborators as `?Filesystem`/`?LoggerInterface`, so without the guard a container that registers `file`/`log` as something else (a test stub built for a different purpose, or any future non-conforming registration) would hit a `TypeError` at `new Composer(...)` instead of falling through to the helper's own `Filesystem`/`NullLogger` defaults.
- **A range version constraint is logged, not skipped (Checklist Step 5).** `VersionParser::normalize()` only accepts exact versions, so a legitimate range constraint (`^1.0`, `~2.3`) throws `\UnexpectedValueException` inside `Composer::install()`'s per-package normalize loop; dropping the package itself there, instead of just its forced refresh, would break every install pinned to a range. The catch now logs at `info` level, naming the package and its constraint, and the install proceeds exactly as before — only the forced local-repository refresh (meaningful only for exact versions) is skipped.

---

## 💥 Breaking Changes (Extensions)

None. Every constructor touched in this step gains a new, defaulted trailing parameter (`Router`'s `Filesystem $files`, the Composer helper's `?Filesystem $files`/`?LoggerInterface $logger`) — no existing call site anywhere needed to change. `SettingsController::saveAction()` and the config/registry writers now throw on a failed write instead of reporting success; that is a fix to an already-broken failure path, not a change to any documented extension-facing API shape.

---

## ⚠️ Risks & Rollout Notes

- **A write that cannot be staged or moved always throws — no non-atomic fallback (Checklist Step 1; hardened in the Finalize atomic-or-throw fix-loop).** `dumpAtomic()` originally fell back to a direct `file_put_contents($target, …, LOCK_EX)` — non-atomic, and observable half-written by a concurrent reader without its own lock — whenever the staged temp file could not be renamed onto the target. A Finalize coverage-gap test first proved that fallback reachable on Linux, not just on Windows (a reader holding the target open), whenever the target's directory refuses new entries but the target file itself stays owner-writable. That fallback is now deleted outright: every staging or `rename()` failure discards the temp file and throws `\RuntimeException`, so the target is always either fully replaced or left exactly as it was. The primitive's only wired-up deployment target so far, the Docker Linux production image (Step 2.5), keeps `$PAGEKIT_DATA_DIR` (holding `config.php`) writable by `www-data`, so a host in that shape sees no behavioural change from this hardening — a host whose staging directory is not writable now gets a loud failure instead of a best-effort in-place rewrite.

---

## 🔐 Security & Data Impact

- **A symlinked `config.php` keeps pointing at its target after a write (Checklist Step 1).** Resolving the link before the temp+rename dance (see Key Decisions) means the first write through `dumpAtomic()` cannot silently sever the link a Docker deployment relies on to keep `config.php` on `$PAGEKIT_DATA_DIR` (Step 2.5).
- **An existing target's permission bits survive a rewrite (Checklist Step 1).** `dumpAtomic()` carries `fileperms($target) & 0777` onto the replacement when the target already exists, so an operator-hardened `config.php` (e.g. `0600`) is not silently widened back to a fresh-file default by the next write through it.
- **A failed settings save now fails loudly instead of reporting success (Checklist Step 3).** `SettingsController::saveAction()` used to ignore the return value of its `config.php` write, so a hardened or read-only application tree got back `['message' => 'success']` over a file that was never touched. The write now throws and the exception is left to propagate, so a failed save surfaces as an error instead of a false positive that hides unsaved settings from the administrator.
- **A failed package-registry write no longer disappears silently (Checklist Step 4).** `Composer::writeConfig()`'s own `file_put_contents()` call ignored its return value entirely, so a `path.packages` directory an install/uninstall could not write left `packages.php` stale with no indication anything had failed. The write now throws `\RuntimeException` through `dumpAtomic()`, the same fail-loud fix Checklist Step 3 applied to the config writers.
- **The new constraint-skip log line carries no marketplace credentials (Checklist Step 5).** `Composer::install()`'s info-level log names only the package and its version constraint; the `system.api` marketplace URL — which can embed a token — never reaches the message, so a range-constrained install's log trail cannot leak it.

---

## 🛡️ No-Mercy Compliance

- **Rule 4 (Delete over wrap) — Checklist Step 2:** `Router::writeCache()`'s own temp+rename+fallback body is deleted outright in favor of delegating to `Filesystem::dumpAtomic()` — no parallel write path or flag keeps the old logic alive alongside the primitive.
- **Rule 4 (Delete over wrap) — Checklist Step 3:** `SettingsController`'s own trailing `opcache_invalidate()` call is deleted outright now that `dumpAtomic()` invalidates centrally — no double invalidation kept alongside the primitive.
- **Rule 4 (Delete over wrap) — Checklist Step 4:** `Composer::writeConfig()`'s own `file_put_contents()` call is replaced outright by delegation to `Filesystem::dumpAtomic()` — no parallel write path survives for callers without an injected `Filesystem`; the helper's `$files ??= new Filesystem()` default is what stands in for a missing collaborator, not a second write branch.
- **Rule 4 (Delete over wrap) — Checklist Step 5:** `SelfupdateCommand`'s commented-out former `execute()` body and its now-unused `SelfUpdater` import are deleted outright — git history is the record of the discontinued implementation, not a comment block left beside the code that disabled it.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

| Gate | Result |
|---|---|
| CI — PR checks | ✅ green — [PR #265](https://github.com/Shadesman5/pagekit/pull/265) (`phpunit (8.5)`, `phpunit-mysql`, `phpstan`, `cs-fixer`, `security-audit`, `version-ssot`, `frontend`, `infection-diff` all pass; `e2e-smoke`/`e2e-merge` skip — draft PR) |
| Coverage gap pass | ran — `test(filesystem): close codecov patch gaps` (`DumpAtomicTest.php`) |
| Cursor Bugbot (PR) | ✅ clean — 0 review comments |
| E2E | ✅ PASS — local final E2E in Checklist Step 6 (XL), re-run clean after the Finalize fix-loop |
| Finalize fix-loop | cs-fixer FAIL (anonymous-class CS in `RouterTest`) → fixed, then the coverage gap pass — 2 commits, both green |

**CI run:** https://github.com/Shadesman5/pagekit/pull/265

**Metrics (CI-owned):** [PR #265](https://github.com/Shadesman5/pagekit/pull/265) sticky Codecov comment ([app.codecov.io](https://app.codecov.io/gh/Shadesman5/pagekit/pull/265)) · [Quality Dashboard](https://Shadesman5.github.io/pagekit/quality/)

**Notable deviations:**
1. cs-fixer FAIL on the PR's CI run — anonymous-class constructor parens missing in the two `RouterTest` stubs (Checklist Step 2) — fixed, no behavioral change (see Finalize fix-loop above).
2. Coverage gap pass — Codecov's patch diff flagged an uncovered branch in `DumpAtomicTest.php`; closed with a new test that also widened the documented scope of the rename-blocked fallback (see Risks & Rollout Notes).

---

## 📋 Phase 1 Audit Closure

None (no `Closes Phase 1 audit:` line in the ticket header; Filesystem Write Resilience scope does not touch a Phase 1 audit item).

---

## 👤 Maintainer action

<!-- Human-only follow-ups the maintainer must do (ruleset flips, real Docker/Apache
     verification, secrets, etc.). Not ROADMAP deferrals — those go under Deferred. -->

None — no human-only follow-ups in this ticket.

---

## 📚 Deferred / Out-of-Scope

<!-- Future ROADMAP/PHASE work, explicit non-goals, bridges. Do NOT put maintainer
     Manual Work here — that belongs under Maintainer action above. -->

- **Step 2.7 (Extension Safety & Fault Isolation)** — route-cache freshness by content hash instead of `filemtime`; replacing the deprecated copied `PhpMatcherDumper`/`UrlGeneratorDumper` with the Symfony compiled matcher/generator. *PHASE_2 §2.7 already lists both — no amendment needed.*
- **Step 2.7.1 (Snapshot & Three-Stage Uninstall)** — snapshots/backups before destructive package operations. *PHASE_2 §2.7.1 already depends on this step for atomic writes — no amendment needed.*
- **Step 2.9 (Automated Update System)** — reuses `dumpAtomic()` for artefact/registry writes. *PHASE_2 §2.6's own "Provides" line already records this; §2.9 also carries the OPcache-gate note for any non-PHP write path.*
- **Step 5.6 (Marketplace & Extensions)** — re-enabling `pagekit self-update` and its release feed once the `GET /api/update` backend exists. *The pre-existing `// TODO: Step 5.6` marker left in place in `SelfupdateCommand` plus PHASE_5 §5.6 already cover it.*
- **Non-goals:** a general filesystem abstraction (Flysystem-style adapters, a locking layer, a transactional API); vfsStream or any new test dependency; the Symfony Filesystem component as the primitive's implementation.
- **Bridges:** None.

---

## 📌 Follow-on (ROADMAP)

None — no ROADMAP sub-step created. The atomic-or-throw hardening landed in the Finalize fix-loop (see What Changed); everything else already has a PHASE home.

---

## 🧊 Parked (unplanned)

None.

---

## 🧹 Cleanup

None beyond the ticket's own Checklist Step 5 error-handling hygiene (the `SelfupdateCommand` dead block and the empty `Composer` catch), already covered under What Changed and No-Mercy Compliance above.

---

## 🛡️ Audit

None (no Phase 1 audit item in scope; see Phase 1 Audit Closure above).

---

## 🎁 Bonus

None.

---

## 🔍 Research

None.

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_6_Filesystem-Write-Resilience_plan.md` → moves to `migration-docs/tickets/done/` as part of this Finalize
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_6_Filesystem-Write-Resilience.md`
- Predecessor: Step 2.5 — Docker Production Image & Deploy
- Successor: Step 2.7 — Extension Safety System
