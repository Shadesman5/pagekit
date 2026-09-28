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

### Review (Bugbot + Security) + E2E (Checklist Step 3)

Bugbot's first pass found a leftover `app/vendor` tree. After `composer install`, the install script removes that directory or symlink. A `/app/vendor/` gitignore line was rejected: it would hide a second tree. After install, `app/vendor` is neither a directory nor a symlink, and `./vendor/autoload.php` is the only autoload.

| File | Change |
|---|---|
| `.cursor/install.sh` | After `composer install`, `rm -rf app/vendor`. |

No tests. Gates: Bugbot clean after one fix-loop (leftover `app/vendor`). Security clean. E2E PASS.

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

## 📥 Review inbox

<!-- Verbatim non-verdict notes from the last clean XL Bugbot and Security replies.
     Doc-writer copies the handover here and does not judge. Post-close review verifies each
     note against the code, writes what is still unowned, then sets this section back to None. -->

Security:
## Security review: Step 2.7.4 (standard Composer layout)

**Scope reviewed:** Composer vendor relocation from `app/vendor` to root `./vendor`, plus related boot/CI/Docker/release/updater path updates in the diff.

---

### Summary

This diff is a structural/layout migration. Production boot still loads a single autoload entry point (`autoload.php` → `./vendor/autoload.php`), the web document root remains `public/`, and the self-updater change (`SelfUpdater::$cleanFolder` includes `vendor`) aligns cleanup with existing `app/` behavior rather than introducing a new trust boundary.

---

### Areas examined

#### 1. HTTP exposure of `vendor/`

**Changed:** `public/index.php` sets `'path.vendor' => $path.'/vendor'`.

**Finding:** No new web exposure.

- Apache/Docker use `DocumentRoot` = `public/`; requests for `/vendor/...` resolve under `public/`, not the repo-root tree.
- Root `.htaccess` rewrites all requests to `public/` when the host points at the repo root with mod_rewrite enabled.
- CI already probes denial of out-of-webroot paths (`/config.php`, `/composer.json`, etc.) in `docker-image.yml`.

Moving from `app/vendor` to `vendor` does not place dependencies inside the served tree. Misconfigured hosts that expose the repo root could already leak dependency files under `app/vendor`; the conventional `vendor/` path is not a new vulnerability class and requires the same misconfiguration.

#### 2. Autoload integrity / dual-vendor confusion

**Changed:** `composer.json` (removed `vendor-dir`), `autoload.php`, bootstraps, Dockerfile copy path.

**Finding:** Single autoload path; no fallback to `app/vendor`.

Console and system boot use `$path.'/autoload.php'`, which requires root `vendor/autoload.php` only. Grep shows no production `app/**/*.php` reads `path.vendor` today; the config key is inert for runtime authorization or file access.

`.cursor/install.sh` adding `rm -rf app/vendor` reduces stale duplicate trees after install. That is local workspace maintenance on a trusted install path, not a cross-user boundary.

#### 3. SelfUpdater: `vendor` added to `$cleanFolder`

**Changed:** `app/installer/src/SelfUpdater.php` — `$cleanFolder = ['app', 'vendor']`.

**Finding:** Security-neutral to positive; not newly exploitable.

- Update flow remains admin-gated: `UpdateController` has `#[Access('system: software updates', admin: true)]` and CSRF on update actions.
- Cleanup deletes files under `vendor/` (and `app/`) that are absent from the archive file list—the same model that already applied to `app/`. An incomplete malicious archive could brick the install; that requires an authenticated updater-capable admin and matches pre-existing `app/` cleanup risk, not a new attacker path from this diff.
- Removing stale vendor files after updates reduces the chance orphaned vulnerable dependency code persists— a hygiene win.

Pre-existing updater concerns (zip path validation, empty `setUpdateMode()`, SSRF in `downloadAction(string $url)`) are unchanged by this diff and were not re-reported.

#### 4. Release packaging (`BuildCommand`)

**Changed:** Exclude regexes from `^app\/vendor` to `^vendor`.

**Finding:** No regression.

Vendor junk exclusions (oauth examples, debugbar resources, package tests/docs) were ported to the new path prefix. `BuildCommandExcludeTest` locks the behavior. Release contents are equivalent, just under `vendor/` instead of `app/vendor/`.

#### 5. Docker / filesystem permissions

**Changed:** `Dockerfile` copies `./vendor`; test asserts no `chown` on `vendor/`.

**Finding:** Consistent with existing hardening—application tree stays root-owned/read-only; `vendor/` is not writable by `www-data`. No privilege-escalation path introduced.

#### 6. CI cache keys

**Changed:** Cache path `vendor` with `composer-root-vendor-*` keys.

**Finding:** Operational correctness change. Avoids restoring an `app/vendor` cache into `./vendor`. No cross-tenant or unauthenticated amplification path.

#### 7. Coverage / static analysis config

**Changed:** Removed `<directory>app/vendor</directory>` from PHPUnit coverage excludes.

**Finding:** No impact. Coverage `<include>` roots are only under `app/`; `vendor/` was never in scope.

#### 8. Security-related TODOs in the diff

The trimmed `SelfUpdater` TODO still defers `public/storage` link and renamed assets under `public/` to Step 2.9. That is forward debt on an admin-only update path and was not introduced or worsened by adding `vendor` to the clean pass.

---

### Conclusion

The diff does not introduce concrete, exploitable issues in authorization, injection, credential exposure, cross-user access, webroot bypass, or agent/tool trust boundaries. The meaningful production touchpoints (`autoload.php`, `public/index.php`, `SelfUpdater`, `BuildCommand`, Docker layout) preserve or slightly improve the prior security posture.

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
