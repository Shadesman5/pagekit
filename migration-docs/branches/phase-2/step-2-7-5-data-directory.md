# Step 2.7.5 — Data Directory

<!-- Branch doc for Roadmap Step 2.7.5.
     Path: migration-docs/branches/phase-2/step-2-7-5-data-directory.md -->

**Branch:** `feature/data-directory`
**ROADMAP Step:** 2.7.5 (Data Directory (data/))
**GitHub Issue:** [#299](https://github.com/Shadesman5/pagekit/issues/299)
**Pull Request:** [#313](https://github.com/Shadesman5/pagekit/pull/313)
**Status:** ✅ Complete
**Started:** 2026-09-28 22:15
**Completed:** 2026-09-29 02:54

---

## 🎯 Overview

Private runtime state lives under `data/`. Boot names `path.data` as `<root>/data`, snapshots as `data/snapshots`, and the failure record as `data/state`. `RuntimeDirectories::ensure()` leaves those three directories mode `0700` or throws. A fresh SQLite install writes `data/pagekit.db`. An existing `config.php` that already names a database file keeps it.

`tmp/` holds cache, temp, logs, and sessions. Nothing copies `tmp/snapshots` or `tmp/system`. The production image mounts `pagekit_data` at `/var/www/html/data` and refuses to start unless that directory is writable. A release excludes live files under `data/` and any `db.dump`, and packs the six guard files by name.

---

## ✅ What Changed

### Private state under `data/` (Checklist Step 1)

Boot names `path.data` as `$path.'/data'`, with snapshots and the failure record inside it. `RuntimeDirectories::ensure()` leaves those three directories present and mode `0700`: `system` and `console` call it before their apps load, and the installer calls it only after the requirements list is empty. A fresh SQLite install uses `data/pagekit.db`. The image keeps that directory at `/var/www/html/data` on `pagekit_data`.

| File | Change |
|---|---|
| `app/modules/filesystem/src/RuntimeDirectories.php` (new) | `ensure()` creates a missing directory, then `chmod` `0700`. A failed `mkdir` or `chmod`, or a mode that is not `0700`, throws `\RuntimeException`. |
| `public/index.php` | Adds `path.data`. `path.snapshots` is `$path.'/data/snapshots'` and `path.system` is `$path.'/data/state'`. `system` and `console` call `ensure()` on those three paths before their apps are required. |
| `app/installer/app.php` | After the requirements list is empty, `ensure()` runs on the three paths, before `new App`. |
| `app/installer/requirements.php` | The writable list includes `$path/data`. |
| `app/modules/database/index.php` | SQLite `'path'` is `data/pagekit.db`. |
| `app/console/src/Commands/BuildCommand.php` | Release exclude `^data\/[^\/]+\.db`. |
| `phpstan-baseline.neon` | Undefined `$config` and `$path` counts in `app/installer/app.php` are 4 and 4. |
| `data/.htaccess`, `data/snapshots/.htaccess`, `data/state/.htaccess` | `Require all denied`. |
| `data/.gitignore` | Ignores `*` and keeps its guards plus `snapshots/` and `state/` and the guards inside each. |
| `data/snapshots/.gitignore`, `data/state/.gitignore` | Ignore `*` and keep `.htaccess` and `.gitignore`. |
| `tmp/snapshots/.htaccess`, `tmp/snapshots/.gitignore`, `tmp/system/.htaccess`, `tmp/system/.gitignore` | Deleted. |
| `Dockerfile` | `PAGEKIT_DATA_DIR` is `/var/www/html/data`. Copies `data/`, `chmod 0700` that directory, and `chown`s it to `www-data` with `tmp` and `storage`. `PAGEKIT_DB_PATH` follows the variable. The `config.php` symlink stays aimed at `$PAGEKIT_DATA_DIR/config.php`. |
| `docker/entrypoint.sh` | Fallback data directory is `/var/www/html/data`. `chmod 0700` when the account can, then exits unless the serving user can write it. |
| `docker-compose.prod.yml` | `pagekit_data` mounts at `/var/www/html/data`. |
| `prod.env.example` | Example `PAGEKIT_DATA_DIR` and `PAGEKIT_DB_PATH` are `/var/www/html/data` and `/var/www/html/data/pagekit.db`. |
| `.dockerignore` | Ignores the contents of `data/` except the guards, and ignores `**/db.dump`. |
| `.github/workflows/docker-image.yml` | Asserts the database file and `config.php` use `/var/www/html/data/pagekit.db`, and that `/var/www/html/data` is a directory the serving user can write. |

#### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `tests/Unit/Filesystem/RuntimeDirectoriesTest.php` (new) | `ensure()` leaves mode `0700`. A create, chmod, or mode that cannot stay owner-only throws. The message is the directory, and includes the warning when the failed call left one. |
| `tests/Unit/Filesystem/WritablePathPostureTest.php` (new) | Reads boot paths and image wiring without booting. Temp, cache, and logs stay under `tmp/`; data, snapshots, and state stay under `data/`; storage stays under `storage/`. Shipped `data/` guards deny requests. `system` and `console` call `ensure()` before their apps; the installer calls it after the requirements exit and before `new App`. `requirements.php` lists `$path/data`. The image workflow asserts `/var/www/html/data`. |
| `tests/Unit/Snapshot/SnapshotPathWiringTest.php` | Deleted. |
| `app/modules/database/src/Tests/SqlitePathResolutionTest.php` | With the working directory on `public/`, the module default resolves to `<root>/data/pagekit.db` and stays outside `public/`. `tearDown` removes that file and the `data/` directory. |
| `tests/Unit/Console/BuildCommandExcludeTest.php` | `data/pagekit.db` and `data/other.db` match the exclude. Guards, a dump, and a nested `data/` do not. |
| `tests/Unit/Installer/SelfUpdaterCleanupTest.php` | Clean folders stay `app` and `vendor`. Ignore folders stay `packages` and `storage`. |
| `tests/Unit/Package/PackageModuleBoundaryTest.php` | `registrationRoot()` creates `data`. The stand-in config names the three private paths under that temp root. The installer include leaves them mode `0700`; the other boots leave `data` at `0755` and do not create the children. |
| `app/modules/kernel/src/Tests/EnvConfigLoaderTest.php` | The single-variable case expects the module default `data/pagekit.db`. |

Gates: production verifier PASS; production tester PASS; test-writer done; test verifier PASS; coverage tester PASS. No deviations.

### Upgrade note and living instructions (Checklist Step 2)

Living instructions and the installation spec point at `data/`. A fresh install writes `data/pagekit.db`. The spec and its README treat a root `pagekit.db` as a leftover, not the installation database. The printed setup line removes that root file as well as `data/pagekit.db`.

| File | Change |
|---|---|
| `README.md` | `data/` is writable and mode `0700`; `tmp/` and `storage/` stay writable; `packages/` stays writable. Three lifetimes, and a one-time hand copy of `tmp/snapshots` to `data/snapshots` and `tmp/system` to `data/state`. An existing `config.php` that names `pagekit.db` keeps that file. Shared hosting lists `data/` beside `config.php`. `pagekit_data` mounts at `/var/www/html/data` with no `tmp/snapshots` link. A volume that already exists keeps its files at the volume root; a `config.php` that still says `/var/www/data/pagekit.db` is overridden while `PAGEKIT_DB_PATH` is set, and the container-local failure record has to be copied to `data/state` before recreate. Runtime package location is Step 2.8. The `archive --dir` example is `/var/www/html/data`. |
| `AGENTS.md` | A first run writes `/workspace/data/pagekit.db`. The prod image uses `PAGEKIT_DATA_DIR=/var/www/html/data` and refuses to start unless `data/` is writable. The Windows `PAGEKIT_DB_PATH` example is `/var/www/html/data/pagekit.db`. |
| `.cursor/agents/tester.md` | Both clean-state commands are `rm -f config.php pagekit.db data/pagekit.db`. |
| `.cursor/skills/e2e-test-architect/runtime-patterns.md` | The installation-spec clean state includes `data/pagekit.db`. |
| `tests/e2e/README.md` | The installation spec needs no `config.php` and no `data/pagekit.db`. A root `pagekit.db` is not that database. The default SQLite file is `data/pagekit.db`. The clean-state command includes it. |
| `tests/e2e/config/test-config.example.json` | SQLite `path` is `data/pagekit.db`. |
| `tests/e2e/helpers/test-config.js` | The SQLite default path is `data/pagekit.db`. `printSetupInstructions` also names a root `pagekit.db`. |
| `tests/e2e/specs/01-setup/installation.spec.js` | Not installed means no `config.php` and no `data/pagekit.db`. After a SQLite install the file is `data/pagekit.db`. A root `pagekit.db` is not the installation database. |

No PHPUnit tests. Gates: production verifier PASS; production tester PASS; test-writer skip. No deviations.

### Release exclude (Checklist Step 3)

A release ZIP could pack live `data/snapshots`, `data/state`, and `data/config.php`. The Step 1 pattern matched only `data/*.db`. Every path under `data/` is excluded except the six guard files, and `db.dump` is excluded anywhere. `execute()` adds those guards by name, because Finder ignores dotfiles.

| File | Change |
|---|---|
| `app/console/src/Commands/BuildCommand.php` | `$excludes` drops every path under `data/` except the six guards, and drops `(^|/)db.dump$`. `DATA_GUARDS` is added with `addFile`. |
| `tests/Unit/Console/BuildCommandExcludeTest.php` | The filter matches `data/pagekit.db`, `data/*.db-wal`, `data/*.db-shm`, `data/config.php`, snapshot dumps and metadata, `data/state/<file>`, and any `db.dump`. The six guards and a nested `data/` do not match. `execute()` adds each `DATA_GUARDS` entry. |

Gates: Bugbot clean. Security: one medium (release ZIP could pack live `data/snapshots`, `data/state`, and `data/config.php`); fix-loop excluded live `data/` except the six guards and any `db.dump`. Bugbot and Security clean again on that tree, then clean a second time before E2E. E2E PASS.

---

## 🧠 Key Decisions (Rationale)

- **The failure names the directory and the warning the failed call left.** `RuntimeDirectories::failure` throws the directory path, or `{directory}: {warning}` when `error_get_last()` left a message. A fixed sentence in place of that warning was rejected. The message contains the directory, and contains that warning text when the failed call left one.
- **The package-boundary stand-in names the three private paths inside its temp tree.** `PackageModuleBoundaryTest::managerRegisteredBy` sets `path.data`, `path.snapshots`, and `path.system` under that root. Leaving them unset was rejected: the installer include calls `ensure()` on those keys, and a missing key type-errors before registration. `registrationRoot()` creates `data` there before the include, and the three paths stay inside that temp tree.
- **The installer baseline keeps its two identifiers.** Undefined `$config` and `$path` in `app/installer/app.php` are counted 4 and 4. A new ignore was rejected. Those counts match the reads of the boot variables the include injects.
- **The installation README and the spec agree on the two files that mean installed.** `tests/e2e/README.md` requires no `config.php` and no `data/pagekit.db`, and says a root `pagekit.db` is not that database. Leaving the old "no pagekit.db" sentence was rejected: it would still treat the root file as the install. That README and `installation.spec.js` name the same two files.
- **The printed fresh-install line follows the clean-state command.** `TestConfig::printSetupInstructions` names `config.php`, `pagekit.db`, and `data/pagekit.db`. Naming only the SQLite default there was rejected: the clean-state command also removes a leftover root `pagekit.db`. The default path and `test-config.example.json` are `data/pagekit.db`.
- **Live files under `data/` stay out of a release, and the six guards stay in.** `BuildCommand::$excludes` drops every path under `data/` except those guards, and `(^|/)db.dump$` drops a dump anywhere. A blanket `^data/` was rejected: the filter would also drop the guards. Excluding only `data/snapshots/`, `data/state/`, and `data/config.php` was rejected: a SQLite sidecar or any other file beside the database would still be packed. The filter matches `data/pagekit.db`, `data/*.db-wal`, `data/*.db-shm`, `data/config.php`, `data/snapshots/<id>/db.dump`, `data/state/<file>`, and any `db.dump`, and does not match the six guard paths.
- **The six guards are packed by name.** `BuildCommand::DATA_GUARDS` is `addFile`'d in `execute()`. Relying on the filter exception alone was rejected: Finder ignores dotfiles, so that exception never sees them. `execute()` adds each entry, and the list stays those six paths.

---

## 💥 Breaking Changes (Extensions)

None. Callers still read `path.snapshots` and `path.system`. Those directories now sit under `data/`.

---

## ⚠️ Risks & Rollout Notes

- An installation that still has `tmp/snapshots` or `tmp/system` is moved once by hand: copy to `data/snapshots` and `data/state`. The application does not copy them.
- An existing `config.php` that names `pagekit.db` keeps that file. Only a new install writes `data/pagekit.db`.
- A `pagekit_data` volume created at `/var/www/data` keeps its files at the volume root; recreating the container mounts that root at `/var/www/html/data`. While `PAGEKIT_DB_PATH` is set, a `config.php` that still says `/var/www/data/pagekit.db` is overridden. The failure record under `tmp/system` was container-local and is not on the volume.
- System and console throw before they load when `data/`, `data/snapshots`, or `data/state` cannot be left at mode `0700`. The installer lists an unwritable `data/` on the requirements page and calls `ensure()` only after that list is empty.
- The entrypoint narrows `data/` to `0700` when the account can, and still starts when that `chmod` fails and the directory is writable. The first PHP boot then throws if the process owns the directory and cannot hold `0700`.

---

## 🔐 Security & Data Impact

`data/`, `data/snapshots`, and `data/state` sit beside `public/`, not under it, and nothing under `public/` links to them. Each ships `Require all denied`. `ensure()` leaves them mode `0700` or throws, so system and console do not serve on a missing or wider only-copy directory. The SQLite resolver still rejects a path under the public webroot. A release and the image build context leave out live files under `data/` and any `db.dump`, and the release still packs the six guards.

---

## 🛡️ No-Mercy Compliance

No dual read and no copy-on-boot. `tmp/snapshots` and `tmp/system` are not created and are not named in production boot, the entrypoint, or the image workflow. `RuntimeDirectories::ensure()` is the one place that sets mode `0700`. `SelfUpdater` does not list `data/` in the clean or ignore folders, so an update does not delete that tree and a release can still extract the guards. No bridge.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

| Gate | Result |
|---|---|
| CI — PHP Tests | ✅ success — [run 36514367498](https://github.com/Shadesman5/pagekit/actions/runs/36514367498) (phpunit 8.5, phpunit-mysql, phpunit-mysql-snapshot, phpstan, cs-fixer, security-audit, version-ssot) |
| CI — Infection | ✅ success — [run 36514367497](https://github.com/Shadesman5/pagekit/actions/runs/36514367497) (infection-diff) |
| CI — Frontend | ✅ success — [run 36514367496](https://github.com/Shadesman5/pagekit/actions/runs/36514367496) |
| CI — Docker Image | ✅ success — [run 36514367511](https://github.com/Shadesman5/pagekit/actions/runs/36514367511) (hadolint, docker-image; publish-image skipped on pull request) |
| CI — E2E workflow | e2e-smoke and e2e-merge skipped (PR smoke is opt-in, not a failure) |
| Pull request | [#313](https://github.com/Shadesman5/pagekit/pull/313) |
| Coverage gap pass | ran — first verifier FAIL (`BuildCommandDataGuardTest` duplicated `BuildCommandExcludeTest`); the same behavior stayed in `BuildCommandExcludeTest`; retry passed |
| Cursor Bugbot (PR) | skipped |
| Cursor Security Reviewer (PR) | skipped |
| E2E | PASS |

**CI head:** `5dc24710b6d49931334a260425e3f27ec83391b4`

**Metrics (CI-owned):** [PR #313 quality-report comment](https://github.com/Shadesman5/pagekit/pull/313#issuecomment-5882409328) · [Quality Dashboard](https://shadesman5.github.io/pagekit/quality/)

**Notable deviations:** Step 1: none. Step 2: none. Step 3: Security found a release ZIP could pack live `data/snapshots`, `data/state`, and `data/config.php`; the exclude then drops every path under `data/` except the six guards, and any `db.dump`. Bugbot and Security clean after that fix, then clean again before E2E. E2E PASS. Finalize: coverage verifier FAIL on a second class `BuildCommandDataGuardTest`; the same behavior stayed in `BuildCommandExcludeTest` and the retry passed. PR Bugbot skipped. PR Security skipped. `e2e-smoke` and `e2e-merge` skipped (PR smoke is opt-in). `publish-image` skipped on the pull request.

---

## 📋 Phase 1 Audit Closure

None.

---

## 👤 Maintainer action

<!-- Human-only follow-ups the maintainer must do (ruleset flips, real Docker/Apache
     verification, secrets, etc.). Not ROADMAP deferrals — those go under Deferred. -->

None.

---

## 📚 Deferred / Out-of-Scope

<!-- Future ROADMAP/PHASE work, explicit non-goals, bridges. Do NOT put maintainer
     Manual Work here — that belongs under Maintainer action above. -->

- **Step 2.8** — where a runtime-installed package is writable in the production image. `path.packages` stays `<root>/packages` and does not move into `data/`.
- **Step 2.9** — backup tooling and release-update notes. This step makes `data/`, `storage/`, and `config.php` the set a backup copies. Dump format, restore, and snapshot retention stay as they are.
- **Non-goal:** moving `config.php` out of the application root. The image keeps the symlink into the data volume.
- **Non-goal:** a separate log directory or log rotation. Logs stay under `tmp/`.
- **Historical records** stay as written. Past changelog sentences name the layout of that change.
- **Bridges:** none.

---

## 📌 Follow-on (ROADMAP)

- 2.8 — Extension Packaging & Prebuilt Assets
- 2.9 — Automated Update System

---

## 📥 Review inbox

<!-- Verbatim non-verdict notes from the last clean XL Bugbot and Security replies.
     Doc-writer copies the handover here and does not judge. Post-close review verifies each
     note against the code, writes what is still unowned, then sets this section back to None. -->

Security:
## Security review result

The change is a net hardening of where private runtime state lives and how it is protected.

---

### Scope reviewed

The diff implements Step 2.7.5: move SQLite DB, package snapshots, and extension failure state from `tmp/` / repo root into `data/`, enforce owner-only directories, update Docker volume layout, and tighten release/build exclusions.

---

### Areas examined (no exploitable issues)

#### 1. Web exposure of `data/` (DB dumps, snapshots)

**Controls (unchanged + strengthened):**

- `path.data`, `path.snapshots`, and `path.system` sit beside `public/`, not under it (`public/index.php`).
- SQLite resolution still joins relative paths to the app root and **rejects any path under `path.public`**:

```64:77:app/modules/database/index.php
                    // Reject any resolved path under the document root — Apache
                    // .htaccess is not universal (Nginx / php -S).
                    $publicRoot = $app->has('path.public')
                        ? (string) $app->get('path.public')
                        : $app->get('path').'/public';
                    $dbPath = $canonicalizeFilesystemPath($connectionParams['path']);
                    $publicDir = Path::directory($canonicalizeFilesystemPath($publicRoot));
                    if (str_starts_with($dbPath, $publicDir) || $dbPath === rtrim($publicDir, '/')) {
                        throw new \InvalidArgumentException(sprintf(
                            'SQLite database path "%s" must not be under the public webroot "%s".',
                            $connectionParams['path'],
                            $publicRoot,
                        ));
                    }
```

- Shipped `data/.htaccess`, `data/snapshots/.htaccess`, and `data/state/.htaccess` use `Require all denied`.
- Docker vhost keeps `DocumentRoot /var/www/html/public`; `data/` is not under the docroot.
- Shared-hosting root rewrite still sends `/data/*` → `public/data/*` (404), not app-root `data/`.

**Attacker-controlled path:** None over HTTP without misconfiguration that already existed for `config.php` / `tmp/`.

---

#### 2. Permission enforcement (`RuntimeDirectories`)

`RuntimeDirectories::ensure()` is **fail-closed** for system/console/installer boots: create (if missing), `chmod 0700`, verify mode, else `\RuntimeException` (boot/installer does not continue).

Boot order calls `ensure(path.data)` → `ensure(path.snapshots)` → `ensure(path.system)` before the app loads, so parent `data/` is narrowed before children are created.

**Installer gap considered:** requirements only check `is_writable($path/data)` before `ensure()`. That is not an exposure window: `ensure()` still runs before `new App` and blocks service if mode cannot be held at `0700`.

---

#### 3. Docker entrypoint `chmod … || true`

```109:117:docker/entrypoint.sh
# A volume mounted over the private directory can arrive wider than owner-only.
# Narrow it when this account may; one owned by someone else can refuse the mode
# and still be writable, which is what the start actually requires.
chmod 0700 "$data_dir" 2>/dev/null || true

if ! can_write "$data_dir"; then
    echo "entrypoint: data/ has to be writable by the serving user because the snapshots, the failure record, and an SQLite file live there ($data_dir)" >&2
    exit 1
fi
```

Entrypoint can start if `chmod` fails but the directory is writable. **Impact:** local/co-tenant read of `data/` on a mis-mounted volume (e.g. overly permissive host volume). That requires host/volume misconfiguration and local access, not a remote HTTP primitive. PHP `ensure()` on the first request still enforces `0700` when the process owns the directory. **Not rated medium+** under the triage rules.

---

#### 4. Release / image leakage of secrets

**Improvements in the diff:**

- `BuildCommand` excludes live content under `data/` (including `data/pagekit.db`, `db.dump`, snapshot metadata) while explicitly packing guard files.
- `.dockerignore` excludes `data/*` (except guards) and `**/db.dump`.

This closes a real gap: the old exclude matched root `pagekit.db` only; the new default `data/pagekit.db` is now excluded.

`SelfUpdater` still does not list `data/` in `ignoreFolder` (pre-existing pattern). Mitigation is the build filter; a malicious update ZIP would require admin-level update authority.

---

#### 5. Volume path change (`/var/www/data` → `/var/www/html/data`)

Operational/migration concern, not a new remote attack path. `PAGEKIT_DB_PATH` still overrides `config.php` when set; webroot and `.htaccess` guards are unchanged in effect.

---

#### 6. Removed `tmp/snapshots` symlink logic

Removing symlink indirection **reduces** misconfiguration risk (snapshots landing in disposable `tmp/`). CI now checks `data/` writability instead of symlink target integrity—a test coverage change, not a product bypass.

---

#### 7. Authorization / snapshots

Snapshot ID validation and admin-only restore/purge behavior live in existing `SnapshotStore` / controller code; this diff only moves the store path to `data/snapshots`. No new unauthenticated route or privilege annotation was added.

---

### Security-related TODOs in the diff

None represent missing controls on newly introduced attacker-controlled paths. Deferred work (Step 2.8 container package writability, Step 2.9 backup/update hardening) is forward debt, not an open hole introduced here.

---

### Optional hardening (below report threshold)

- Add `/data` and `/data/pagekit.db` to the `docker-image.yml` webroot-denial probes alongside `/config.php` and `/tmp`—regression detection only.
- Add `data/` to `SelfUpdater::$ignoreFolder` in a future update step for defense-in-depth against a bad release artifact.

---

### Conclusion

The diff moves high-value state out of cache-eligible `tmp/`, enforces owner-only directories at boot, adds defense-in-depth Apache denials, and prevents DB/snapshot content from entering release ZIPs or Docker build context. Traced HTTP, path-resolution, and permission paths do not yield a realistic medium-or-higher exploit introduced by these changes.

---

## 🧊 Parked (unplanned)

<!-- Filled by the post-close review after Finalize: what the finished work left unowned,
     one bullet per finding with the ROADMAP step whose area it belongs to. Doc-writer leaves None. -->

None.

---

## 🧹 Cleanup

<!-- Removed in passing (deleted files, dropped baseline/ignore entries, dead code). Doc-writer from
     the handover; the post-close review adds what the diff shows and the handover missed. -->

None.

---

## 🛡️ Audit

<!-- No-Mercy leftovers of the shipped diff that have no owner (forward-debt tags, added baseline
     entries, ANOMALIES patterns), each with the ROADMAP step that resolves it. Post-close review. -->

None.

---

## 🎁 Bonus

<!-- Work delivered beyond the ticket. Doc-writer from the handover; the post-close review adds
     what the diff shows and the handover missed. -->

None.

---

## 🔍 Research

<!-- The verified facts behind each DECISION the post-close review raised — symbols, call chain,
     what each exit deletes or adds — so the maintainer can decide without re-reading the tree. -->

None.

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/done/PROMPT_2_7_5_Data-Directory_plan.md`
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_5_Data-Directory.md`
- Predecessor: Step 2.7.4 — Standard Composer Layout (vendor/)
- Successor: Step 2.8 — Extension Packaging & Prebuilt Assets
