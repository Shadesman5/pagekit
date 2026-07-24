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

### Dashboard live alignment (8.5 keys) (Checklist Step 6)

| File | Change |
|---|---|
| `docs-site/content/javascripts/quality-dashboard.js` | PHPUnit rows built dynamically from snapshot `phpunit` keys (`"<php>-<db>"`); non-required legs render ⚪ + conclusion; coverage label drops PHP-version suffix; E2E row uses `scope` label; demo meta copy → "awaiting the first live collection run". |
| `docs-site/data/quality-snapshot.demo.json` | Schema-v2 seed: `"8.5-sqlite"` / `"8.5-mysql"` (`required: false`) + `e2e.scope: "smoke"` (counts refreshed to current suite size). |
| `.github/quality/quality-snapshot.json` | Same seed shape as the demo file (in-repo seed until `quality-data` overlays). |
| `docs-site/content/quality/index.md` | Removed "Demo data" admonition; data-source table → live snapshot on `quality-data`, collector on merge, pages-deploy overlay. |
| `.github/quality/README.md` | Schema example → 8.5 keys + `scope`/`required`; Status → live collection wired, in-repo file is seed only. |

Tests: none (test-writer skip — docs-site JS + snapshot JSON + markdown only). Gates: Verifier PASS; Tester PASS.

### infection.yml — PR diff gate (Checklist Step 7)

| File | Change |
|---|---|
| `.github/workflows/infection.yml` | New workflow `name: Infection`. Trigger: `pull_request` → `develop`/`main`; concurrency per ref (cancel-in-progress); `contents: read`. Job `infection-diff` (required name): checkout `fetch-depth: 0`, PHP 8.5 + PCOV, composer cache/install, fetch `origin/$GITHUB_BASE_REF`; early-exit green when `git diff` touches none of the Infection source scope (`app/modules/auth/src`, `app/system/modules/user/src/{Model,Auth,Event}`); otherwise `./app/vendor/bin/infection --threads=max --git-diff-lines --git-diff-base=origin/$GITHUB_BASE_REF --logger-github --min-covered-msi=80`; upload `tmp/infection/` as `infection-report` when in-scope (`if: always()`). Third-party actions pinned by commit SHA. |

Tests: none (test-writer skip — CI YAML only). Gates: Verifier PASS; Tester PASS — PHPUnit 725 tests / 2057 assertions (5 skipped, exit 0); PHPStan no errors (exit 0).

### frontend.yml — PR gate (Checklist Step 8)

| File | Change |
|---|---|
| `.github/workflows/frontend.yml` | New workflow `name: Frontend`. Trigger: `pull_request` → `develop`/`main`; concurrency per ref (cancel-in-progress); `contents: read`. Job `frontend` (required name): checkout `fetch-depth: 0`, pinned `actions/setup-node` (Node 22 + yarn cache), `yarn install --frozen-lockfile`; **blocking** `yarn compile-js --mode=production` + `yarn gulp`; fetch `origin/$GITHUB_BASE_REF`; advisory ESLint + Prettier `--check` on changed `.js`/`.vue` only (`continue-on-error: true`, skip when none). Actions pinned by commit SHA. |
| `package.json` | Exact pin `prettier@3.9.6` under `devDependencies` (advisory tooling only). |
| `yarn.lock` | Lockfile entry for `prettier@3.9.6`. |

Tests: none (test-writer skip — CI YAML + package lock only). Gates: Verifier PASS; Tester PASS — PHPUnit 725 tests / 2057 assertions (5 skipped, 2 deprecations); PHPStan no errors.

### Playwright selection model (tags + projects) (Checklist Step 9)

| File | Change |
|---|---|
| `playwright.config.js` | Projects composed from env: default `[chromium-desktop]` (Desktop Chrome); `PW_VIEWPORTS=all` adds tablet (820×1180) + mobile (390×844) via explicit `viewport` only (no `isMobile`/`hasTouch`); `PW_BROWSERS=all` adds firefox/webkit (+ viewport variants when both flags set). Names `${browser}-${viewport}`. |
| `tests/e2e/specs/01-setup/installation.spec.js` | `test.describe(…, { tag: '@ci' }, …)`. |
| `tests/e2e/specs/02-core/authentication.spec.js` | Same `@ci` tag. |
| `tests/e2e/specs/02-core/dashboard.spec.js` | Same `@ci` tag. |
| `tests/e2e/specs/02-core/orm-operations.spec.js` | `test.describe.fixme` + Rule-5 `// TODO: Must be refactored in Step 3.6.1 (E2E Test Suite Rework)`. |
| `tests/e2e/specs/02-core/settings.spec.js` | Same quarantine. |
| `tests/e2e/specs/03-content/blog.spec.js` | Same quarantine. |
| `tests/e2e/specs/03-content/media.spec.js` | Same quarantine. |
| `tests/e2e/specs/03-content/pages.spec.js` | Same quarantine. |
| `tests/e2e/specs/04-frontend/public-pages.spec.js` | Same quarantine. |
| `tests/e2e/specs/05-features/user-management.spec.js` | Same quarantine. |
| `tests/e2e/specs/05-features/widgets.spec.js` | Same quarantine. |
| `playwright.smoke.config.js` | Deleted (redundant under the tag model). |
| `package.json` | `test:smoke*` → `--grep @ci` on default config; `test:e2e:install` → default config (no smoke config). |
| `tests/e2e/README.md` | Documents `@ci` / fixme selection + `PW_VIEWPORTS` / project names. |
| `tests/e2e/COMPLETE_TEST_PLAN.md` | Dropped smoke-config reference; points at `@ci` + fixme. |
| `AGENTS.md` | Playwright bullet: `chromium-desktop` default, `PW_VIEWPORTS`, `@ci` / fixme. |
| `.cursor/skills/e2e-test-architect/runtime-patterns.md` | Smoke command → `--grep @ci`; project → `chromium-desktop`. |
| `.gitignore` | Removed `/playwright-smoke-report/**/*` (smoke report path gone with the deleted config). |
| `migration-docs/testing/EXTENSION_ERROR_HANDLING_TEST_GUIDE.md` | `--project=chromium` → `chromium-desktop`. |
| `migration-docs/documentation/EXTENSION_ERROR_HANDLING_README.md` | Same project rename. |
| `migration-docs/documentation/EXTENSION_ERROR_HANDLING_QUICK_REFERENCE.md` | Same project rename. |

Tests: none (test-writer skip — Playwright config/specs/docs only; no production PHP). Gates: Verifier PASS; Tester PASS (PHPUnit + PHPStan green).

### e2e.yml — smoke (PR) + merge (Checklist Step 10)

| File | Change |
|---|---|
| `.github/workflows/e2e.yml` | New workflow `name: E2E`. Triggers: `pull_request` + `push` → `develop`/`main`; concurrency per ref (cancel-in-progress); `contents: read`; narrow dormant `paths-ignore` only (PR: conductor-metrics; push: + `.github/quality/**`). Job `e2e-smoke` (PR only, required name): PHP 8.5 + composer cache/install, writable dirs, Node 22 + yarn cache + `yarn install --frozen-lockfile` (builds assets via postinstall), prepare `test-config.json` from example with CI admin/site URLs, Playwright browser cache keyed on `@playwright/test` version, `npx playwright install --with-deps chromium`, `npx playwright test --grep @ci --project=chromium-desktop` (no prior `php pagekit setup` — installation spec drives the web installer; Playwright `webServer` starts the app), upload JSON always + HTML on failure. Job `e2e-merge` (push only): same selection against the merged tip; uploads `playwright-merge-report` JSON for `quality-collect`. Actions pinned by commit SHA. |

Tests: none (test-writer skip — CI YAML only). Gates: Verifier PASS; Tester PASS (PHPUnit + PHPStan green).

### nightly.yml — guarded Infection full + E2E viewports (Checklist Step 11)

| File | Change |
|---|---|
| `.github/workflows/nightly.yml` | New workflow `name: Nightly`. Triggers: `schedule` cron `0 2 * * *` (~02:00 UTC) + `workflow_dispatch`; concurrency per ref with `cancel-in-progress: false`; `contents: read`. Job `guard`: `workflow_dispatch` always `should_run=true`; scheduled runs only when `git log --since='24 hours ago'` is non-empty (`fetch-depth: 0`). Job `infection-full` (`needs: guard`, `if: should_run`): PHP 8.5 + PCOV, composer cache/install, full `./app/vendor/bin/infection --threads=max` (no git-diff flags; 80/80 from `infection.json.dist`), upload `tmp/infection/infection.json` + `summary.log` as `infection-full-report` (`if: always()`). Job `e2e-viewports` (`needs: guard`, same gate): matrix `project: [chromium-desktop, chromium-tablet, chromium-mobile]` with `PW_VIEWPORTS=all`, same E2E env as Step 10 (composer, writable dirs, Node 22, yarn install, prepared `test-config.json`, Playwright chromium cache/install), `npx playwright test --grep @ci --project=${{ matrix.project }}`; `continue-on-error: ${{ matrix.project != 'chromium-desktop' }}` + Rule-5 `# TODO: Must be refactored in Step 3.6.1 (E2E Test Suite Rework) — tablet/mobile legs non-blocking until @ci specs are viewport-robust`; `fail-fast: false`; upload JSON always + HTML on failure per project. Actions pinned by commit SHA. |

Tests: none (test-writer skip — CI YAML only). Gates: Verifier PASS; Tester PASS — PHPUnit: 725 tests, 2057 assertions (5 skipped, exit 0); PHPStan: No errors (exit 0).

### e2e-weekly.yml — full sweep (dispatch-first) (Checklist Step 12)

| File | Change |
|---|---|
| `.github/workflows/e2e-weekly.yml` | New workflow `name: E2E Weekly`. Triggers: `schedule` cron `0 3 * * 0` (Sunday ~03:00 UTC) + `workflow_dispatch`; concurrency per ref with `cancel-in-progress: false`; `contents: read`. Job `guard` (job-level gate: dispatch always, else `vars.E2E_WEEKLY_ENABLED == 'true'`): dispatch always `should_run=true`; scheduled runs only when `git log --since='7 days ago'` is non-empty (`fetch-depth: 0`) — cron stays inert until an admin sets the repo variable. Job `e2e-sweep` (`needs: guard`, `if: should_run`): matrix of all 9 projects (`{chromium,firefox,webkit}-{desktop,tablet,mobile}`) with `PW_BROWSERS=all` + `PW_VIEWPORTS=all`; same E2E env as Step 10 (composer, writable dirs, Node 22, yarn install, prepared `test-config.json`) but Playwright cache key `playwright-all-*` and `npx playwright install --with-deps` (no browser filter); `npx playwright test --project=${{ matrix.project }}` with **no `--grep`** (fixme'd specs skip, never fail); `continue-on-error: ${{ matrix.project != 'chromium-desktop' }}` + Rule-5 `# TODO: Must be refactored in Step 3.6.1 (E2E Test Suite Rework) — non-chromium-desktop legs non-blocking until @ci specs are cross-browser/viewport-robust`; `fail-fast: false`; upload JSON always + HTML on failure per project. Actions pinned by commit SHA. No cron-gate flip in this ticket (Manual Work: set `E2E_WEEKLY_ENABLED=true`). |

Tests: none (test-writer skip — CI YAML only). Gates: Verifier PASS; Tester PASS — PHPUnit: 725 tests, 2057 assertions (5 skipped, 2 deprecations); PHPStan: no errors.

---

## 🧠 Key Decisions (Rationale)

- **Required checks stay job-name-bound.** Workflow file/`name:` changed to PHP Tests; job `name:` values left byte-identical so the develop ruleset contexts (`phpunit (8.5)`, `phpstan`, `cs-fixer`, `security-audit`) do not orphan.
- **CI watch is PR-wide.** Finalize consumers switched from a single-workflow `gh run list --workflow "PHP Quality"` to `gh pr checks … --watch` so later gates (Infection / E2E / Frontend) are covered without another rename pass.
- **MySQL is a separate non-blocking job.** `phpunit-mysql` never shares the required `phpunit (8.5)` context; only DbUtil consumers honor the MySQL globals today, so the leg stays advisory until Step 2.9 makes the suite DB-portable.
- **Version SSoT is PR-only and dep-free.** Drift is a review-time concern (nothing to check on a protected-branch merge); plain PHP avoids a composer install so the job stays a cheap gate.
- **Sticky report is additive and null-safe.** One comment per PR (marker upsert); Infection / E2E / Frontend are listed now but render "pending" until those workflows land. `workflow_run` only fires from the default branch's copy — `workflow_dispatch` is the post-merge / on-demand validation path for this ticket's PR.
- **Live snapshot never touches develop.** Collector is the sole writer to unprotected `quality-data`; publish only when both gate merge runs are green (half-built dashboards avoided). Missing `e2e.yml` / `nightly.yml` resolve null-safe until later checklist steps. Explicit `pages-deploy` dispatch because `GITHUB_TOKEN` pushes do not re-trigger workflows.
- **Dashboard follows the snapshot, not hardcoded PHP rows.** PHPUnit legs are rendered from whatever keys the collector publishes; non-required legs stay informational (⚪) so the MySQL advisory job never looks like a red required gate. In-repo JSON stays a demo seed until the first post-merge collect overlays `"source": "github-actions"`.
- **Infection PR gate mutates only the diff, and skips out-of-scope PRs.** `--git-diff-lines` + explicit base fetch keep the run under the small auth/user source scope; an early green exit when no scoped files changed avoids bootstrapping Infection on unrelated PRs. `infection.json.dist` thresholds (80 MSI) unchanged — full-suite Infection stays for Nightly (later checklist step).
- **Frontend gate: build blocks, lint advises.** Production webpack + gulp must match a release compile; ESLint/Prettier stay `continue-on-error` and diff-scoped because the tree has ~13k pre-existing style violations — a full-tree lint would always be red. Prettier is an exact-pin advisory dep only (formatting policy lands later with Step 2.4).
- **One Playwright config, selection by tag + projects.** Smoke is `--grep @ci` on the three optimized specs; the other eight stay `test.describe.fixme` (skipped, never red) until Step 3.6.1. Viewport/browser matrices are env-composed on the same config — no duplicate smoke file, no `isMobile` (Firefox constraint). Local bare `npx playwright test <spec>` stays chromium-desktop only.
- **E2E splits PR gate vs merge artifact.** `e2e-smoke` is the required PR context (chromium-desktop + `@ci` only); `e2e-merge` runs the same selection on push so `quality-collect` gets a develop-tip JSON report without bloating the PR job. Install happens via the web UI in the installation spec — CI must not pre-run `php pagekit setup`. Example config alone is not enough: the job fills real admin credentials + `127.0.0.1:8080` origin so the connectivity precheck and installer accept the file.
- **Nightly is guarded, full Infection, and viewport-split.** Idle repos skip the expensive jobs via a 24h `git log` window (`workflow_dispatch` bypasses). Full-suite Infection (no `--git-diff-*`) feeds the snapshot MSI blend; tablet/mobile E2E legs stay non-blocking until Step 3.6.1 makes `@ci` specs viewport-robust — desktop remains the hard signal (`fail-fast: false` so all three legs always report).
- **Weekly sweep is dispatch-first and cron-dormant.** Scheduled runs require repo var `E2E_WEEKLY_ENABLED=true` plus a 7-day commit window; `workflow_dispatch` always proceeds. Full 9-leg matrix (`PW_BROWSERS=all` + `PW_VIEWPORTS=all`) with no `--grep` so fixme quarantine stays skipped-not-failed; only `chromium-desktop` is blocking until Step 3.6.1. Cron sits at Sunday 03:00 UTC (clear of Nightly 02:00). Enabling the var is Manual Work — not flipped in this ticket.

---

## ⚠️ Breaking Changes (Extensions)

None (CI / agent-rule rename only; no extension or runtime API change).

---

## ⚠️ Risks & Rollout Notes

Collector + sticky report `workflow_run` triggers fire only from the default-branch copy — first live collect/dispatch is post-merge (or manual `workflow_dispatch`). With `e2e.yml` present, publish can proceed once both PHP Tests and E2E merge runs are green on develop. New required contexts `infection-diff`, `e2e-smoke`, and `frontend` need a develop ruleset admin add (Manual Work) — Infection lands green on out-of-scope PRs via the early-exit path; Frontend is always a full build on every PR; E2E smoke is chromium-desktop `@ci` only. Callers of `playwright.smoke.config.js` or `--project=chromium` must switch to `--grep @ci` / `--project=chromium-desktop`. Quarantined specs report as skipped until Step 3.6.1. `nightly.yml` and `e2e-weekly.yml` also run only from the default branch (schedule + `workflow_dispatch` for post-merge proof); Infection full MSI stays null in the snapshot until the first green Nightly; record tablet/mobile (and weekly cross-browser) failures from the first dispatch under Manual Work. Weekly cron stays inert until `E2E_WEEKLY_ENABLED=true` is set.

---

## 🔐 Security & Data Impact

`quality-report.yml` adds least-privilege scopes for the sticky comment (`pull-requests: write`, `actions`/`checks`/`contents: read`). `quality-collect.yml` needs `contents: write` (push to `quality-data` only) + `actions: write` (artifact download + `pages-deploy` dispatch); never writes the protected branch. `pages-deploy.yml` action pins tightened to commit SHAs. PHP Tests permissions and Codecov OIDC unchanged.

---

## 🛡️ No-Mercy Compliance

Deleted `.github/workflows/php-quality.yml` in the same step as the new `php-tests.yml` — no dual-workflow shim. Deleted `playwright.smoke.config.js` once `@ci` tags + default-config smoke scripts landed — no parallel smoke config.

---

## ✅ Verification (links only)

- CI run: _TBD_
- Notable deviations: None (Steps 1–12)

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
