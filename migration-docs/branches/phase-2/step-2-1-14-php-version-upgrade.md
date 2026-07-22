# Step 2.1.14 — PHP Version Upgrade (8.2 → 8.5)

**Branch:** `feature/php-version-upgrade`
**ROADMAP Step:** 2.1.14 (PHP Version Upgrade (8.2 → 8.5))
**GitHub Issue:** [#231](https://github.com/Shadesman5/pagekit/issues/231)
**Pull Request:** [#238](https://github.com/Shadesman5/pagekit/pull/238)
**Status:** ✅ Complete
**Started:** 2026-07-21 22:38
**Completed:** 2026-07-22 00:22

---

## 🎯 Overview

Raises the runtime / Composer / CI PHP floor from 8.2+ to **8.5+**. Composer
platform + Infection cap lift, entry/installer version guards, one own-code
PHP 8.5 deprecation fix (`MenuHelper` null array offset), CI matrix to 8.5
(Travis deleted), and runtime docs / Docker base image aligned. Symfony stays
6.4.x; DBAL stays 3.x; PHPUnit stays 11.x.

---


## ✅ What Changed

### Composer platform bump + Infection cap + lock refresh (Checklist Step 1)

| File | Change |
|---|---|
| `composer.json` | `"php": "^8.5"`; `config.platform.php: "8.5.0"`; `"infection/infection": ">=0.33 <0.35"` (exactly 3 approved edits). |
| `composer.lock` | Regenerated; Symfony direct deps stay 6.4.x; `doctrine/dbal` 3.10.6; `phpunit/phpunit` 11.5.56; `infection/infection` 0.34.0. |
| `phpstan-baseline.neon` | Regenerated after platform bump (see Notable deviations). |

Tests: none (test-writer skip — composer manifest/lock only; no production PHP under `app/` / `packages/`).

### Runtime minimums: entry guard + installer (Checklist Step 2)

| File | Change |
|---|---|
| `index.php` | Runtime version guard `'8.2'` → `'8.5'`. |
| `app/installer/requirements.php` | `REQUIRED_PHP_VERSION` `'8.2.0'` → `'8.5.0'`; OPcache recommendation "PHP 8.2+" → "PHP 8.5+". |

Tests: none (test-writer skip — version-literal strings only; no logic branches).

### MenuHelper null-array-offset deprecation fix (Checklist Step 3)

| File | Change |
|---|---|
| `app/system/modules/site/src/MenuHelper.php` | Short-circuit `$node->parent_id !== null` before `$nodes[$node->parent_id]` so synthetic-root `parent_id=null` never hits a null array offset (PHP 8.5 deprecation). |
| `phpstan-baseline.neon` | `MenuHelper.php` `offsetAccess.invalidOffset` ignore count `3` → `1` (see Notable deviations). |

Tests: `app/system/modules/site/src/Tests/MenuHelperTest.php` — added `testGetRootAttachesNodesUnderSyntheticRootWithNullParentId` (null short-circuit + sibling under synthetic root).

### CI to 8.5 + remove dead Travis (Checklist Step 4)

| File | Change |
|---|---|
| `.github/workflows/php-quality.yml` | PHPUnit matrix `['8.2','8.3']` → `['8.5']`; three `if: matrix.php == '8.3'` → `'8.5'`; phpstan / cs-fixer / security-audit: Setup/php-version/cache keys `8.3` → `8.5`. `MIN_LINE_COVERAGE` + floor-provenance comment unchanged. |
| `.travis.yml` | Deleted (dead Travis matrix; CI is GitHub Actions). |

Tests: none (test-writer skip — CI config only).

### Runtime docs sweep (Checklist Step 5)

| File | Change |
|---|---|
| `Dockerfile` | `FROM php:8.4-apache` → `FROM php:8.5-apache` (version align only; redesign stays 2.3). |
| `README.md` | Badge `php-8.5%2B`; Key Features / Major Changes / Minimum Requirements / Docker / Extension Development strings → PHP 8.5+. |
| `.cursor/rules/pagekit-context.mdc` | Strict typing + Backend stack "PHP 8.2+" → "PHP 8.5+". |
| `.cursor/ROADMAP.md` | Rule 3 bullet + Technical Stack → plain "PHP 8.5+" (dropped "after Step 2.1.14" clauses). Header pointer / tracking row untouched (Finalize). |

Tests: none (test-writer skip — docs only). Final E2E (last checklist step): PASS — see Step 5 gates.

---

## 🧠 Key Decisions (Rationale)

None beyond plan. Infection resolved cleanly to 0.34.0 on PHP 8.5 + PHPUnit
11.5 (no fallback to `<0.34`). Coverage-floor provenance comment in
`php-quality.yml` left untouched (historical measurement fact).

---

## ⚠️ Breaking Changes (Extensions)

**Minimum PHP raised to 8.5.** Hosts, Docker images, and extension Composer
`require.php` must target PHP 8.5+. Runtime entry (`index.php`) and installer
`REQUIRED_PHP_VERSION` reject lower versions. CI no longer runs PHPUnit on
8.2/8.3.

No schema, route, or public HTTP API changes. Symfony 6.4 / DBAL 3.x / PHPUnit
11.x constraints unchanged.

---

## ⚠️ Risks & Rollout Notes

- **Maintainer action (Finalize PR body):** Ruleset "Protect for Develop-Branch" still requires status contexts `phpunit (8.2)` and `phpunit (8.3)`. After matrix rename, jobs report as `phpunit (8.5)` — a repo admin must update required checks to `phpunit (8.5)` (agent `gh` is read-only / non-admin).

---

## 🔐 Security & Data Impact

None. Version floor + one deprecation guard; `composer audit --locked` green on
Finalize CI. No data-model or auth changes.

---

## 🛡️ No-Mercy Compliance

Compliant. No compatibility layers, adapters, or Rule 5 forward-debt tags —
hard cut to PHP 8.5; Travis deleted rather than kept parallel.

---

## ✅ Verification (links only)

| Gate | Result |
|---|---|
| CI — PHP Quality | ✅ success (phpunit 8.5, phpstan, cs-fixer, security-audit) |
| Coverage gap pass | skipped — no codecov bot comment on PR #238 within ~5 min after CI green |
| Cursor Bugbot | ✅ clean — "Bugbot reviewed your changes and found no new issues!" |
| E2E | ✅ PASS |
| Finalize fix-loop | none |

**CI run:** https://github.com/Shadesman5/pagekit/actions/runs/29879681596

**Notable deviations:** Step 1 — plan commit surface was `composer.json` + `composer.lock` only; Tester (1st) FAIL regenerated `phpstan-baseline.neon` into the Step 1 surface (see Step 1 gates). Step 3 — after the null short-circuit, PHPStan baseline `MenuHelper.php:109` `offsetAccess.invalidOffset` count stale (`expected 3` / `occurred 1`); Refactorer lowered count to `1`. Test-writer 1st FAIL: assertion expected `null` path on synthetic root but got `'/'`; PHPStan undefined `parent_id`/`path` on `NodeInterface` — fixed on retry (see Step 3 gates).

**Step 1 gates (Execute):**
- Verifier (1st, production): PASS — `composer.json` exactly 3 approved edits; lock regenerated; Symfony 6.4.x, dbal 3.10.6, phpunit 11.5.56, infection 0.34.0
- Tester (1st): FAIL — PHPUnit PASS (718); PHPStan FAIL exit 1, 73 errors beyond baseline. RCA: platform/require `php ^8.2`→`^8.5`; PHPStan 2.2.5 reported new `parameter.implicitlyNullable` / `offsetAccess.invalidOffset` findings plus unmatched baseline ignore in `DatabaseSessionHandler.php`. Refactorer retry: regenerated `phpstan-baseline.neon`
- Verifier (2nd): PASS
- Tester (2nd): PASS — PHPUnit 718 exit 0; PHPStan no errors exit 0
- Tester Infection smoke: PASS — Infection 0.34.0 MSI/Covered MSI ~99% (≥80); exit 0

**Step 2 gates (Execute):**
- Verifier: PASS
- Tester: PASS — PHPUnit 718 exit 0; PHPStan no errors exit 0

**Step 3 gates (Execute):**
- Verifier (prod 1st): PASS
- Tester (prod 1st): FAIL — PHPUnit PASS (718); PHPStan FAIL `ignore.count` `MenuHelper.php:109` expected 3 times occurred 1 time. Refactorer retry: `phpstan-baseline.neon` count `3`→`1`
- Verifier (prod 2nd): PASS
- Tester (prod 2nd): PASS — PHPUnit 718/2039 (5 skipped, 2 deprecations); PHPStan no errors
- Tester deprecation check: PASS — PHP deprecations 0; PHPUnit metadata deprecations 2 (owned by 2.9)
- Test-writer: `MenuHelperTest.php`
- Verifier (tests 1st): PASS
- Tester (after tests 1st): FAIL — `testGetRootAttachesNodesUnderSyntheticRootWithNullParentId` Failed asserting null identical to `'/'`; PHPStan 2 errors undefined properties `parent_id`/`path` on `NodeInterface`. Test-writer retry
- Verifier (tests 2nd): PASS
- Tester (after tests 2nd): PASS — PHPUnit 719 tests, 2047 assertions (5 skipped, 2 deprecations); PHPStan no errors

**Step 4 gates (Execute):**
- Verifier (production): PASS
- Tester (PHPUnit + PHPStan): PASS — PHPUnit 719 tests, OK (5 skipped); PHPStan no errors

**Step 5 gates (Execute):**
- Verifier (production): PASS
- Tester (PHPUnit + PHPStan): PASS — PHPUnit 719 tests, 2047 assertions (5 skipped, exit 0); PHPStan no errors (exit 0)
- Tester (final E2E): PASS — PHPUnit 719 OK; PHPStan OK; `php pagekit setup` / `php pagekit list` OK; Playwright installation, authentication (14), dashboard (10) all passed

---

## 📋 Phase 1 Audit Closure

None


---

## 📚 Deferred / Out-of-Scope

**Plan note — PHASE Deferred sync amendments (Architect):** present for §2.2 and §2.9; land with this ticket commit.

- **Step 2.2 (CI/CD Pipeline)** — docs-site quality dashboard matrix-key alignment (`quality-dashboard.js` + `quality-snapshot.demo.json` still render 8.2/8.3 PHPUnit legs; rebuilt against live snapshot in 2.2). Version SSoT guard already in §2.2 What. *(PHASE §2.2 amended)*
- **Step 2.9 (Phase 2 Closeout)** — PHPUnit doc-comment metadata deprecations (`ConfigManagerTest::testGet`, `MigrationServiceTest`) + `phpunit.xml.dist` `failOn*` flips; PHPUnit major (12/13) evaluation after metadata cleanup (stays `^11.0` here). *(PHASE §2.9 amended)*
- **Step 2.3 (Docker Production)** — root `Dockerfile` multi-stage/Alpine redesign (this ticket only aligns `FROM` version; already in §2.3 What, no amendment).
- **Non-goals:** Symfony 7 (4.2), DBAL 4 (4.3), Property Hooks / Autowiring / Fail-Fast (2.8.x), coverage-ratchet raise (2.9), PHP 8.5 syntax feature-tourism.
- **Bridges:** None.

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/done/PROMPT_2_1_14_PHP-Version-Upgrade_plan.md` (archive after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_14_PHP-Version-Upgrade.md`
- Predecessor: Step 2.1.13 — TinyMCE Security Patch (~5.10.9)
- Successor: Step 2.2 — CI/CD Pipeline

