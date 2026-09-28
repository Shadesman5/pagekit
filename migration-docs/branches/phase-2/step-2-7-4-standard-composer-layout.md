# Step 2.7.4 — Standard Composer Layout

<!-- Branch doc for Roadmap Step 2.7.4.
     Path: migration-docs/branches/phase-2/step-2-7-4-standard-composer-layout.md -->

**Branch:** `feature/standard-composer-layout`
**ROADMAP Step:** 2.7.4 (Standard Composer Layout (vendor/))
**GitHub Issue:** [#271](https://github.com/Shadesman5/pagekit/issues/271)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-09-28 00:18
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

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

---

## 🧠 Key Decisions (Rationale)

_TBD / None_

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

- Ticket: `migration-docs/tickets/active/PROMPT_2_7_4_Standard-Composer-Layout_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_4_Standard-Composer-Layout.md`
- Predecessor: Step 2.7.3 — Static Module Registration
- Successor: Step 2.7.5 — Data Directory (data/)

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
