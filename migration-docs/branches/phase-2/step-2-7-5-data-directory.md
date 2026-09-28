# Step 2.7.5 — Data Directory

<!-- Branch doc for Roadmap Step 2.7.5.
     Path: migration-docs/branches/phase-2/step-2-7-5-data-directory.md -->

**Branch:** `feature/data-directory`
**ROADMAP Step:** 2.7.5 (Data Directory (data/))
**GitHub Issue:** [#299](https://github.com/Shadesman5/pagekit/issues/299)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-09-28 22:15
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

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

---

## 🧠 Key Decisions (Rationale)

- **The failure names the directory and the warning the failed call left.** `RuntimeDirectories::failure` throws the directory path, or `{directory}: {warning}` when `error_get_last()` left a message. A fixed sentence in place of that warning was rejected. The message contains the directory, and contains that warning text when the failed call left one.
- **The package-boundary stand-in names the three private paths inside its temp tree.** `PackageModuleBoundaryTest::managerRegisteredBy` sets `path.data`, `path.snapshots`, and `path.system` under that root. Leaving them unset was rejected: the installer include calls `ensure()` on those keys, and a missing key type-errors before registration. `registrationRoot()` creates `data` there before the include, and the three paths stay inside that temp tree.
- **The installer baseline keeps its two identifiers.** Undefined `$config` and `$path` in `app/installer/app.php` are counted 4 and 4. A new ignore was rejected. Those counts match the reads of the boot variables the include injects.
- **The installation README and the spec agree on the two files that mean installed.** `tests/e2e/README.md` requires no `config.php` and no `data/pagekit.db`, and says a root `pagekit.db` is not that database. Leaving the old "no pagekit.db" sentence was rejected: it would still treat the root file as the install. That README and `installation.spec.js` name the same two files.
- **The printed fresh-install line follows the clean-state command.** `TestConfig::printSetupInstructions` names `config.php`, `pagekit.db`, and `data/pagekit.db`. Naming only the SQLite default there was rejected: the clean-state command also removes a leftover root `pagekit.db`. The default path and `test-config.example.json` are `data/pagekit.db`.

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

---

## ⚠️ Risks & Rollout Notes

_TBD / None_

---

## 🔐 Security & Data Impact

_TBD / None_

---

## 🛡️ No-Mercy Compliance

_TBD_

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

## 📥 Review inbox

<!-- Verbatim non-verdict notes from the last clean XL Bugbot and Security replies.
     Doc-writer copies the handover here and does not judge. Post-close review verifies each
     note against the code, writes what is still unowned, then sets this section back to None. -->

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

- Ticket: `migration-docs/tickets/active/PROMPT_2_7_5_Data-Directory_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_5_Data-Directory.md`
- Predecessor: Step 2.7.4 — Standard Composer Layout (vendor/)
- Successor: Step 2.8 — Extension Packaging & Prebuilt Assets

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
