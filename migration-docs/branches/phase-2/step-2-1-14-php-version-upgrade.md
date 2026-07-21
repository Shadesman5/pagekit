# Step 2.1.14 — PHP Version Upgrade (8.2 → 8.5)

**Branch:** `feature/php-version-upgrade`
**ROADMAP Step:** 2.1.14 (PHP Version Upgrade (8.2 → 8.5))
**GitHub Issue:** [#231](https://github.com/Shadesman5/pagekit/issues/231)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-07-21 22:38
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

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

---

## 🧠 Key Decisions (Rationale)

_TBD / None_

---

## ⚠️ Breaking Changes (Extensions)

_TBD_

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

- CI run: _TBD_
- Notable deviations: Step 1 — plan commit surface was `composer.json` + `composer.lock` only; Tester (1st) FAIL regenerated `phpstan-baseline.neon` into the Step 1 surface (see Step 1 gates).

**Step 1 gates (Execute):**
- Verifier (1st, production): PASS — `composer.json` exactly 3 approved edits; lock regenerated; Symfony 6.4.x, dbal 3.10.6, phpunit 11.5.56, infection 0.34.0
- Tester (1st): FAIL — PHPUnit PASS (718); PHPStan FAIL exit 1, 73 errors beyond baseline. RCA: platform/require `php ^8.2`→`^8.5`; PHPStan 2.2.5 reported new `parameter.implicitlyNullable` / `offsetAccess.invalidOffset` findings plus unmatched baseline ignore in `DatabaseSessionHandler.php`. Refactorer retry: regenerated `phpstan-baseline.neon`
- Verifier (2nd): PASS
- Tester (2nd): PASS — PHPUnit 718 exit 0; PHPStan no errors exit 0
- Tester Infection smoke: PASS — Infection 0.34.0 MSI/Covered MSI ~99% (≥80); exit 0

**Step 2 gates (Execute):**
- Verifier: PASS
- Tester: PASS — PHPUnit 718 exit 0; PHPStan no errors exit 0

---

## 📋 Phase 1 Audit Closure

_TBD_

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

- Ticket: `migration-docs/tickets/active/PROMPT_2_1_14_PHP-Version-Upgrade_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_14_PHP-Version-Upgrade.md`
- Predecessor: Step 2.1.13 — TinyMCE Security Patch (~5.10.9)
- Successor: Step 2.2 — CI/CD Pipeline
