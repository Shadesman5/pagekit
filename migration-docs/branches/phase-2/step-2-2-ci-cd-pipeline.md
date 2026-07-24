# Step 2.2 — CI/CD Pipeline

<!-- Branch doc for Roadmap Step 2.2.
     Path: migration-docs/branches/phase-2/step-2-2-ci-cd-pipeline.md -->

**Branch:** `feature/cicd-pipeline`
**ROADMAP Step:** 2.2 (CI/CD Pipeline)
**GitHub Issue:** [#157](https://github.com/Shadesman5/pagekit/issues/157)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-07-24 11:20
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### php-quality.yml → php-tests.yml + rename consumers (Checklist Step 1)

| File | Change |
|---|---|
| `.github/workflows/php-tests.yml` | New workflow `name: PHP Tests`. Same four required job names (`phpunit (${{ matrix.php }})` matrix `['8.5']`, `phpstan`, `cs-fixer`, `security-audit`). PR/merge split: `cs-fixer` + `security-audit` run only on `pull_request`; `phpunit` + `phpstan` on both. Artifacts: JUnit (`junit.xml`), keep Clover; PHPStan JSON report upload. Cache includes `.phpunit.cache`. Push `paths-ignore` adds `.github/quality/**`. Coverage floor `MIN_LINE_COVERAGE: '3.8'` + pins unchanged. |
| `.github/workflows/php-quality.yml` | Deleted (No Mercy rename). |
| `codecov.yml` | Comment path → `.github/workflows/php-tests.yml`. |
| `.cursor/rules/orchestrator-v2-finalize.mdc` | CI gate → `gh pr checks <pr> --watch --fail-fast` (dropped `--workflow "PHP Quality"` lookup). |
| `.cursor/rules/orchestrator-subagent-workflow.mdc` | Early Push / Final Test prose → PHP Tests / `gh pr checks`. |
| `.cursor/agents/architect.md` | Finalize line → waits on PR checks via `gh pr checks <pr> --watch`. |

Tests: none (test-writer skip — CI YAML + rules/docs only). Gates: Verifier PASS; Tester PASS.

### MySQL leg (non-blocking) (Checklist Step 2)

| File | Change |
|---|---|
| `phpunit-mysql.xml.dist` | New PHPUnit config: duplicate of `phpunit.xml.dist` plus `<php><var>` block with all 12 `db_*`/`tmpdb_*` globals (`pdo_mysql`, host `127.0.0.1`, port `3306`, db `pagekit_test`, tmpdb `mysql`, root/root). |
| `.github/workflows/php-tests.yml` | New job `phpunit-mysql` (PR + push): `mysql:8.4` service + `mysqladmin ping` healthcheck, PHP 8.5 + `pdo_mysql`, composer cache/install, `./app/vendor/bin/phpunit -c phpunit-mysql.xml.dist` (no coverage). `continue-on-error: true` + Rule-5 `# TODO: Must be refactored in Step 2.9 (Phase 2 Closeout) — flip to required once the suite is DB-portable`. Required `phpunit (8.5)` job untouched. |

Tests: none (test-writer skip — CI YAML + PHPUnit XML only; MySQL not available in VM). Gates: Verifier PASS; Tester PASS.

### Version SSoT guard (Checklist Step 3)

| File | Change |
|---|---|
| `.github/scripts/check-version-ssot.php` | New plain-PHP guard (no vendor): extracts MAJOR.MINOR from `composer.json` (`require.php` + `config.platform.php`), `php-tests.yml` matrix, `app/installer/requirements.php` `REQUIRED_PHP_VERSION`, `.cursor/Dockerfile` + root `Dockerfile` base images, `README.md` PHP badge; exit 0 when all agree, non-zero + named offender on drift/unreadable. |
| `.github/workflows/php-tests.yml` | New PR-only job `version-ssot` (checkout + setup-php 8.5 `tools: none`, no composer install) runs the script. |

Tests: none (test-writer skip — CI script + YAML only). Gates: Verifier PASS; Tester PASS.

### quality-report.yml sticky PR comment scaffold (Checklist Step 4)

| File | Change |
|---|---|
| `.github/workflows/quality-report.yml` | New workflow `name: Quality Report`. Triggers: `workflow_run` (`types: [completed]`) on `PHP Tests` / `Infection` / `E2E` / `Frontend` + `workflow_dispatch` (`pr` input). Job `quality-report` proceeds only for `workflow_dispatch` or when `workflow_run.event == 'pull_request'`; concurrency per head SHA/PR; permissions `contents`/`actions`/`checks` read + `pull-requests: write`; checkout pin + `node .github/scripts/quality-report.mjs`. |
| `.github/scripts/quality-report.mjs` | Zero-dep Node renderer: resolve PR + head SHA, download gate artifacts, query check-runs for conclusions, render fixed markdown table (coverage %, floor, PHPStan, Infection-diff MSI, E2E smoke, CS-Fixer, security, frontend — "pending" when a gate has not reported), upsert one sticky comment via `gh api` keyed on hidden marker `<!-- quality-report -->`. |

Tests: none (test-writer skip — CI YAML + script only). Gates: Verifier PASS; Tester PASS.

### quality-collect.yml + pages-deploy overlay (Checklist Step 5)

| File | Change |
|---|---|
| `.github/workflows/quality-collect.yml` | New workflow `name: Quality Collect`. Triggers: `workflow_run` (`types: [completed]`, branches `develop`/`main`) on `PHP Tests` / `E2E` + `workflow_dispatch` (`branch` input, default `develop`). Job `quality-collect` proceeds only for dispatch or when `conclusion == 'success'` and `workflow_run.event == 'push'`; concurrency group `quality-collect` (serialize, no cancel); permissions `contents: write` + `actions: write`; checkout develop + `node .github/scripts/quality-snapshot.mjs`. |
| `.github/scripts/quality-snapshot.mjs` | Zero-dep Node collector: latest green push runs of `php-tests.yml` + `e2e.yml` on the branch (skip publish if either missing), download artifacts (Clover / JUnit / PHPStan JSON / Playwright JSON), Nightly Infection MSI null-safe; assemble schema-v2 snapshot (`source: github-actions`, E2E `scope: smoke`, phpunit keys `8.5-sqlite` required / `8.5-mysql` `required: false` via job conclusion); push `.github/quality/quality-snapshot.json` to unprotected `quality-data` (create from develop if absent); `gh workflow run pages-deploy.yml --ref develop`. |
| `.github/workflows/pages-deploy.yml` | Overlay step for `origin/quality-data` → `.github/quality/quality-snapshot.json` (mirror conductor-metrics; seed from develop when branch absent). All third-party actions pinned by commit SHA (`checkout`, `setup-python`, `configure-pages`, `upload-pages-artifact`, `deploy-pages`). |

Tests: none (test-writer skip — CI YAML + script only). Gates: Verifier PASS; Tester PASS.

---

## 🧠 Key Decisions (Rationale)

- **Required checks stay job-name-bound.** Workflow file/`name:` changed to PHP Tests; job `name:` values left byte-identical so the develop ruleset contexts (`phpunit (8.5)`, `phpstan`, `cs-fixer`, `security-audit`) do not orphan.
- **CI watch is PR-wide.** Finalize consumers switched from a single-workflow `gh run list --workflow "PHP Quality"` to `gh pr checks … --watch` so later gates (Infection / E2E / Frontend) are covered without another rename pass.
- **MySQL is a separate non-blocking job.** `phpunit-mysql` never shares the required `phpunit (8.5)` context; only DbUtil consumers honor the MySQL globals today, so the leg stays advisory until Step 2.9 makes the suite DB-portable.
- **Version SSoT is PR-only and dep-free.** Drift is a review-time concern (nothing to check on a protected-branch merge); plain PHP avoids a composer install so the job stays a cheap gate.
- **Sticky report is additive and null-safe.** One comment per PR (marker upsert); Infection / E2E / Frontend are listed now but render "pending" until those workflows land. `workflow_run` only fires from the default branch's copy — `workflow_dispatch` is the post-merge / on-demand validation path for this ticket's PR.
- **Live snapshot never touches develop.** Collector is the sole writer to unprotected `quality-data`; publish only when both gate merge runs are green (half-built dashboards avoided). Missing `e2e.yml` / `nightly.yml` resolve null-safe until later checklist steps. Explicit `pages-deploy` dispatch because `GITHUB_TOKEN` pushes do not re-trigger workflows.

---

## ⚠️ Breaking Changes (Extensions)

None (CI / agent-rule rename only; no extension or runtime API change).

---

## ⚠️ Risks & Rollout Notes

Collector + sticky report `workflow_run` triggers fire only from the default-branch copy — first live collect/dispatch is post-merge (or manual `workflow_dispatch`). Until E2E lands, the collector skips publish (both gates required).

---

## 🔐 Security & Data Impact

`quality-report.yml` adds least-privilege scopes for the sticky comment (`pull-requests: write`, `actions`/`checks`/`contents: read`). `quality-collect.yml` needs `contents: write` (push to `quality-data` only) + `actions: write` (artifact download + `pages-deploy` dispatch); never writes the protected branch. `pages-deploy.yml` action pins tightened to commit SHAs. PHP Tests permissions and Codecov OIDC unchanged.

---

## 🛡️ No-Mercy Compliance

Deleted `.github/workflows/php-quality.yml` in the same step as the new `php-tests.yml` — no dual-workflow shim.

---

## ✅ Verification (links only)

- CI run: _TBD_
- Notable deviations: None (Steps 1–5)

---

## 📋 Phase 1 Audit Closure

_TBD_

---

## 📚 Deferred / Out-of-Scope

**Plan note — PHASE Deferred sync amendments (Architect):** present for §2.9 and §3.6.1; no ROADMAP sub-step added. Land with this ticket commit.

- **Step 2.9 (Phase 2 Closeout)** — Make the PHPUnit suite DB-portable: most DB tests hardcode in-memory-SQLite connections instead of honoring the `$GLOBALS['db_*']` parameters that `DbUtil::getConnection()` already supports — route them through the shared helper so the whole suite genuinely runs against MySQL, then flip the non-blocking `phpunit-mysql` CI leg to a required gate (drop its `continue-on-error`). Why: a MySQL gate that exercises only a handful of tests gives false cross-DB confidence. *(PHASE §2.9 amended)*
- **Step 3.6.1 (E2E Test Suite Rework)** — Lift the CI quarantine as each spec is reworked: remove its `test.describe.fixme` marker and tag it `@ci` so the tag-driven pipelines (PR smoke, merge, nightly) pick it up automatically — spec selection is tags + Playwright projects, never per-pipeline spec copies. Make the `@ci` specs viewport-robust (tablet/mobile Playwright projects), so the nightly viewport legs and the weekly cross-browser sweep can drop their non-blocking `continue-on-error` status. Why: desktop-only specs leave responsive admin/frontend regressions invisible. *(PHASE §3.6.1 amended)*

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_2_CI-CD-Pipeline_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_2_CI-CD-Pipeline.md`
- Predecessor: Step 2.1.14 — PHP Version Upgrade (8.2 → 8.5)
- Successor: Step 2.3 — Docker

---

## 📊 <Step-specific appendix>

_TBD — remove this section if not applicable._
