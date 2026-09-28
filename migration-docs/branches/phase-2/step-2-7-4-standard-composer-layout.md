# Step 2.7.4 — Standard Composer Layout

<!-- Branch doc for Roadmap Step 2.7.4.
     Path: migration-docs/branches/phase-2/step-2-7-4-standard-composer-layout.md -->

**Branch:** `feature/standard-composer-layout`
**ROADMAP Step:** 2.7.4 (Standard Composer Layout (vendor/))
**GitHub Issue:** [#271](https://github.com/Shadesman5/pagekit/issues/271)
**Pull Request:** [#308](https://github.com/Shadesman5/pagekit/pull/308)
**Status:** ✅ Complete
**Started:** 2026-09-28 00:18
**Completed:** 2026-09-28 19:07

---

## 🎯 Overview

Composer installs at the repository root. `config.vendor-dir` is unset, so the directory is `vendor/` and the binaries are `vendor/bin`. Boot, the suite, CI, the image, and the release read that directory. `autoload.php` requires `vendor/autoload.php`. `path.vendor` is `$path.'/vendor'`. Nothing falls back to `app/vendor`.

A self-update deletes files the new archive does not list under `vendor/` and under `app/`. `packages/` and `public/` stay. After `composer install`, the install script removes a leftover `app/vendor` directory or symlink.

---

## ✅ What Changed

### Root `vendor/` (Checklist Step 1)

Composer installs at the repository root. `vendor-dir` is unset. Boot, the suite, CI, the image, and the release paths read `vendor/`.

The five workflows cache that directory under the key prefix `composer-root-vendor-`. A cache stored for `app/vendor` is not restored as `vendor`.

`SelfUpdater` deletes files the new archive does not list under `vendor/` as well as under `app/`. `packages/` and `public/` stay. The Step 2.9 note still covers the `public/storage` link and renamed files under `public/`.

| File | Change |
|---|---|
| `composer.json` | `config.vendor-dir` removed. `platform` and `allow-plugins` stay. |
| `autoload.php` | Requires `__DIR__ . '/vendor/autoload.php'`. |
| `public/index.php` | `path.vendor` is `$path.'/vendor'`. |
| `tests/bootstrap.php` | Requires the root `vendor/autoload.php`. |
| `app/system/modules/cache/src/Tests/bootstrap.php` | The require climbs six segments to the root `vendor/autoload.php`. |
| `phpunit.xml.dist`, `phpunit-mysql.xml.dist` | Schema is `vendor/phpunit/phpunit/phpunit.xsd`. The `app/vendor` coverage exclude is gone and is not replaced with a `vendor` directory exclude. |
| `phpstan.neon` | Doctrine, Symfony, and PHPUnit extensions, the `vendor` exclude, and the bootstrap autoload are under `vendor/`. `app/modules/*/vendor` stays excluded. |
| `infection.json.dist` | Schema and `phpUnit.customPath` are under `vendor/`. |
| `.php-cs-fixer.php` | Finder exclude is `vendor`. |
| `.gitignore` | Ignores `/vendor/`. |
| `.dockerignore` | Ignores `vendor/`. |
| `.cursorignore` | The commented vendor line is `# /vendor`. The directory stays indexed. |
| `Dockerfile` | The `composer-deps` copy is `./vendor`. |
| `.github/workflows/php-tests.yml`, `infection.yml`, `nightly.yml`, `e2e.yml`, `e2e-weekly.yml` | Composer cache path is `vendor`, cache keys use `composer-root-vendor-`, and PHPUnit, PHPStan, Infection, and PHP CS Fixer run from `./vendor/bin/`. |
| `.cursor/install.sh`, `.cursor/agents/tester.md`, `.cursor/rules/php.mdc` | PHPUnit and PHPStan are `./vendor/bin/…`. The PHP rule names Composer's default `vendor/`. |
| `app/console/src/Commands/BuildCommand.php` | Release excludes that named `app/vendor` now start with `^vendor\/`. |
| `app/installer/src/SelfUpdater.php` | `$cleanFolder` is `['app', 'vendor']`. |

#### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `tests/Unit/Composer/RootVendorLayoutTest.php` (new) | `composer.json` has no `vendor-dir` or `bin-dir`. Boot, the front controller, and the cache bootstrap read the root `vendor/`. `app/vendor` is not a directory or a link. The suite, image, and workflow files above do not contain `app/vendor`, and the scan skips this file. |
| `tests/Unit/Console/BuildCommandExcludeTest.php` (new) | Vendor excludes start with `^vendor\/`. `vendor/<a>/<b>/tests/Foo.php` matches; `src/Foo.php` and the same path under `app/vendor` do not. |
| `tests/Unit/Installer/SelfUpdaterCleanupTest.php` (new) | An update removes files the archive omits under `vendor/` and `app/`, and leaves `packages/` and `public/`. A failed requirements check, and a missing archive, leave the install in place. |
| `tests/Unit/Package/PackageModuleBoundaryTest.php` | `autoload.php` requires the root autoload and does not contain `app/vendor`. |
| `app/modules/kernel/src/Tests/KernelFoundationOwnershipTest.php` | The Composer loader is the one registered for `<root>/vendor`. |
| `tests/Unit/Console/InstallCommandTest.php`, `tests/Unit/Console/UninstallCommandTest.php`, `tests/Unit/Package/PackageArchiveRequirementTest.php`, `tests/Unit/Package/PackageInstallFromArchiveTest.php`, `tests/Unit/Package/PackageSnapshotGateTest.php`, `tests/Unit/Package/PackageTreeRemovalTest.php`, `tests/Unit/Package/PackageUploadBoundaryTest.php` | `path.vendor` is `<workspace>/vendor`. |

Gates: production verifier PASS; production tester PASS; test-writer done; test verifier PASS; coverage tester PASS. No deviations.

### Living docs and editor exclude (Checklist Step 2)

Instructions and the editor search exclude name Composer's default `./vendor`. `vendor` stays out of `files.exclude` so Intelephense still indexes it. Historical docs and past changelog sentences were left as written.

| File | Change |
|---|---|
| `README.md` | PHPUnit examples are `./vendor/bin/phpunit`. |
| `AGENTS.md` | PHPUnit cell, the vendor-directory bullet (`vendor-dir` unset, binaries `./vendor/bin/…`), and the prod-image copy sentence name `vendor`. |
| `.vscode/settings.json` | `search.exclude` is `**/vendor`. The note says not to put `**/vendor` in `files.exclude`. |
| `.cursor/BUGBOT.md` | Ignore list uses `vendor/`. |
| `tests/e2e/COMPLETE_TEST_PLAN.md` | Pre-work PHPUnit command is `./vendor/bin/phpunit`. |
| `migration-docs/TODO/MODERNISATION_STRATEGY.md` | The baseline count command is `./vendor/bin/phpunit`. |

No tests. Gates: production verifier PASS; production tester PASS; test-writer skipped (docs and editor settings only). No deviations.

### Review (Bugbot + Security) + E2E (Checklist Step 3)

Bugbot's first pass found a leftover `app/vendor` tree. After `composer install`, the install script removes that directory or symlink. A `/app/vendor/` gitignore line was rejected: it would hide a second tree. After install, `app/vendor` is neither a directory nor a symlink, and `./vendor/autoload.php` is the only autoload.

| File | Change |
|---|---|
| `.cursor/install.sh` | After `composer install`, `rm -rf app/vendor`. |

No tests. Gates: Bugbot clean after one fix-loop (leftover `app/vendor`). Security clean. E2E PASS.

---

## 🧠 Key Decisions (Rationale)

- **A leftover `app/vendor` is removed after install, not ignored.** `.cursor/install.sh` runs `rm -rf app/vendor` after `composer install`. A `/app/vendor/` gitignore line was rejected: it would hide a second tree. After install, `app/vendor` is neither a directory nor a symlink, and `./vendor/autoload.php` is the only autoload.

---

## 💥 Breaking Changes (Extensions)

None. An extension archive still installs under `packages/<vendor>/<name>`. This step moves this repository's Composer directory.

---

## ⚠️ Risks & Rollout Notes

- A checkout that still has `app/vendor` and no `vendor/` does not boot until `composer install` writes `./vendor`. The install script then removes `app/vendor`. Commands are `./vendor/bin/…`.
- Workflow caches use the prefix `composer-root-vendor-`. A cache stored for `app/vendor` is not restored as `vendor/`.
- A self-update deletes omitted files under `vendor/` and `app/`. The `public/storage` link and renamed files under `public/` stay Step 2.9.
- Historical docs and past changelog sentences still name `app/vendor` for the layout those changes ran against.

---

## 🔐 Security & Data Impact

`vendor/` stays outside `public/`. Boot loads `vendor/autoload.php` only. The image does not give `www-data` ownership of `vendor/`. A self-update still requires an authenticated administrator. The clean pass on `vendor/` deletes a file the new archive does not list, the same model already used for `app/`.

---

## 🛡️ No-Mercy Compliance

`vendor-dir` is unset. There is no second path and no fallback that reads `app/vendor` when `./vendor` is missing. The Step 2.9 TODO still names the webroot gap (`public/storage`, renamed files under `public/`) and no longer says the clean pass reaches `app/` only. `setUpdateMode()` and its Step 5.6 TODO are unchanged.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

| Gate | Result |
|---|---|
| CI — PHP Tests | ✅ success — [run 36468622437](https://github.com/Shadesman5/pagekit/actions/runs/36468622437) (phpunit 8.5, phpunit-mysql, phpunit-mysql-snapshot, phpstan, cs-fixer, security-audit, version-ssot) |
| CI — Infection | ✅ success — [run 36468622510](https://github.com/Shadesman5/pagekit/actions/runs/36468622510) (infection-diff) |
| CI — Frontend | ✅ success — [run 36468622516](https://github.com/Shadesman5/pagekit/actions/runs/36468622516) |
| CI — Docker Image | ✅ success — [run 36468622540](https://github.com/Shadesman5/pagekit/actions/runs/36468622540) (hadolint, docker-image; publish-image skipped on pull request) |
| CI — E2E workflow | [run 36468622360](https://github.com/Shadesman5/pagekit/actions/runs/36468622360) — e2e-smoke and e2e-merge skipped by repository policy |
| Pull request | [#308](https://github.com/Shadesman5/pagekit/pull/308) |
| codecov/patch | ✅ pass — [PR 308](https://app.codecov.io/gh/Shadesman5/pagekit/pull/308) |
| Coverage gap pass | skipped — the latest codecov comment lists no uncovered production files |
| Cursor Bugbot (PR) | findings fixed |
| Cursor Security Reviewer (PR) | ✅ clean |
| E2E | PASS |

**CI head:** `479dce5834cd9a89263e207628f5ed1a2f912f59`

**Metrics (CI-owned):** [PR #308 quality-report comment](https://github.com/Shadesman5/pagekit/pull/308#issuecomment-5861879946) · [Quality Dashboard](https://Shadesman5.github.io/pagekit/quality/)

**Notable deviations:** Step 1: none. Step 2: none. Step 3: Bugbot found a leftover `app/vendor` tree; the install script removes it; then clean. Security clean. E2E PASS. Finalize: no fix-loop. Coverage gap pass skipped. `e2e-smoke` and `e2e-merge` skipped by repository policy. `publish-image` skipped on the pull request.

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

- **Step 2.8** — the extension package contract, author tooling, and upload archives pack and document root `vendor/`.
- **Step 2.9** — release automation, and the updater beyond this clean-pass directory: the webroot `public/storage` link, and pruning renamed files under `public/`.
- **Step 5.6** — `SelfUpdater::setUpdateMode()` is still empty. The maintenance-mode toggle stays that step's work.
- **Step 4.13** — splitting the repository.
- **Non-goal:** renaming the `app/` directory. No ROADMAP row.
- **Historical records** stay as written. Past changelog sentences name the layout of that change.
- **Bridges:** none.

---

## 📌 Follow-on (ROADMAP)

- 2.7.5 — Data Directory (`data/`)
- 2.8 — Extension Packaging & Prebuilt Assets
- 2.9 — Automated Update System
- 4.13 — Repo Topology Spike
- 5.6 — Marketplace & Extensions (maintenance-mode toggle)

---

## 📥 Review inbox

<!-- Verbatim non-verdict notes from the last clean XL Bugbot and Security replies.
     Doc-writer copies the handover here and does not judge. Post-close review verifies each
     note against the code, writes what is still unowned, then sets this section back to None. -->

None.

---

## 🧊 Parked (unplanned)

<!-- Filled by the post-close review after Finalize: what the finished work left unowned,
     one bullet per finding with the ROADMAP step whose area it belongs to. Doc-writer leaves None. -->

- **A leftover `app/vendor` is packed into a release and baked into the production image.** `composer install` leaves a directory at `app/vendor` in place. The removal is `rm -rf app/vendor` in `.cursor/install.sh`. `.gitignore` anchors `/vendor/` and `.dockerignore` names `vendor/`, so the old directory is untracked and travels with a build context. `BuildCommand::execute()` lists files with `Finder` (`ignoreVCS(true)`) and drops paths matching `$excludes`, which are prefixed `^vendor\/`; `app/vendor/acme/widget/tests/Foo.php` does not match, and `BuildCommandExcludeTest` locks that. The `prod` stage copies that tree with `COPY app ./app`. `SelfUpdater::doCleanup()` deletes a file under `app/` when the archive's name list lacks it, and keeps it when the archive lists it, so a release that packed the directory keeps it on every updated site. Recorded default: a release archive and a production image contain no `app/vendor`. The other exit is to leave the packer as it is and treat a clean checkout as the only build input. → **2.9**

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

**A leftover `app/vendor` in a release.**

- `composer.json` `config` holds `platform` and `allow-plugins` and has no `scripts` entry. `composer install` writes `vendor/` and leaves `app/vendor` on disk.
- `.cursor/install.sh` runs that install, then `rm -rf app/vendor`.
- `.gitignore` ignores `/vendor/`. `.dockerignore` ignores `vendor/`.
- `BuildCommand::$excludes` vendor patterns start with `^vendor\/`. `BuildCommand::execute()` builds `Finder::create()->files()->in($path)->ignoreVCS(true)` and filters with that list. `ignoreVCSIgnored()` is not called, so `.gitignore` does not drop the directory. `BuildCommandExcludeTest` asserts the combined filter does not match `app/vendor/acme/widget/tests/Foo.php`.
- The `prod` stage of `Dockerfile` runs `COPY app ./app` and then `COPY --from=composer-deps /var/www/html/vendor ./vendor`. A context that contains `app/vendor` lands in the image beside the builder's `vendor/`.
- `SelfUpdater::$cleanFolder` is `['app', 'vendor']`. `update()` takes the archive name list from `getFileList()`, `extract()` writes those names, and `cleanup()` calls `doCleanup()` for each clean directory. A file under `app/vendor/` whose relative path is absent from the list is unlinked. A path the archive lists is kept.
- Exit A — the release zip and the image omit or refuse `app/vendor` before packing. The junk excludes stay prefixed `^vendor\/`; the lock in `BuildCommandExcludeTest` changes only if that same filter starts matching the old path. Exit B — builds run from a tree with no `app/vendor`, and the packer stays. Recorded default: A.

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/done/PROMPT_2_7_4_Standard-Composer-Layout_plan.md`
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_4_Standard-Composer-Layout.md`
- Predecessor: Step 2.7.3 — Static Module Registration
- Successor: Step 2.7.5 — Data Directory (data/)
