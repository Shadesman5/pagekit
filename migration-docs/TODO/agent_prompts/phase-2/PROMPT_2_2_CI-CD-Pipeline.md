# Step 2.2: CI/CD Pipeline

<!-- conductor-mode: full -->

**ROADMAP:** 2.2. GitHub Issue: #157. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.2.

---

## CONTEXT

- **Land after:** 2.1.14 — PHP **8.5** is already the single source of truth everywhere. Must land before 2.3 (Docker) and 2.4 (build tools).
- **Risk:** Medium — E2E flake, snapshot-write loops on protected branches, required-check renames breaking merge.
- **Goal:** Fast, reliable quality gates on every PR; heavier jobs on merge/schedule. **CI is the single source of truth for documented quality metrics** (coverage, Infection MSI, E2E counts, gate conclusions), surfaced only via a sticky PR comment + `.github/quality/quality-snapshot.json` + the MkDocs quality dashboard — **never** via agents writing metric tables into branch docs.
- **Why:** PRs need a trustworthy gate without multi-hour runs; heavy work (full Infection, full/cross-browser E2E) belongs on schedule.

### Current state (confirm in Discovery, then build — do not rediscover blindly)

- `.github/workflows/php-quality.yml` — 4 jobs: `phpunit` (PHP 8.5, SQLite, PCOV + Clover, Codecov non-blocking via OIDC, **ratcheted** inline line-coverage floor), `phpstan` (Level 8 + baseline), `cs-fixer` (dry-run, `--allow-risky=yes`), `security-audit` (`composer audit --locked`). Concurrency + `paths-ignore` for metrics already set. Actions pinned by commit SHA.
- Coverage floor is a **ratchet** (`MIN_LINE_COVERAGE`, only ever raised). It is **not** a 75 %/60 % target.
- `infection.json.dist` — scoped to auth + user security core, `minMsi`/`minCoveredMsi` = 80. **Not wired into any workflow.** `infection/infection` is already a dev dependency.
- Playwright: `playwright.config.js` runs **chromium only** by default; `PW_BROWSERS=all` adds firefox + webkit; there are **no viewport variants** (single `Desktop Chrome`). 11 specs under `tests/e2e/specs/`, incl. `01-setup/installation.spec.js`, `02-core/authentication.spec.js`, `02-core/dashboard.spec.js`. `webServer` = `php pagekit start`; `tests/e2e/config/test-config.json` is gitignored (copy from `.example.json`).
- **E2E readiness (critical):** only the 3 specs above are optimized (and slightly dated); the other 8 specs are **not** optimized and may fail. Treat unfinished specs as quarantined (see §3.3).
- Dashboard scaffolding exists but is **demo/stale**: `.github/quality/quality-snapshot.json` has `"source": "demo"`; `docs-site/content/javascripts/quality-dashboard.js` and `docs-site/data/quality-snapshot.demo.json` still render **8.2/8.3 PHPUnit legs that no longer exist**. `docs-site/hooks/copy_snapshot.py` copies the snapshot into the built site. **`quality-collect.yml` does not exist yet.**
- `.github/workflows/pages-deploy.yml` already triggers on `docs-site/**` and `.github/quality/quality-snapshot.json` and overlays the `conductor-metrics` data branch — reuse this pattern for the live snapshot.
- PHP minimum is **8.5** in every SSoT consumer: `composer.json` (`require.php` `^8.5`, `config.platform.php` `8.5.0`), `app/installer/requirements.php` (`REQUIRED_PHP_VERSION`), `.cursor/Dockerfile` (`php:8.5-cli`), `README.md`, `.cursor/rules/pagekit-context.mdc`.

---

## PRINCIPLES (hold across every checklist step)

- **CI = SSoT for documented quality metrics** — no local/agent numbers in branch docs or LLM-formatted reports.
- **Tester / test-writer = gates only** — PASS/FAIL (+ new test files); no metric handoff to doc-writer.
- **Branch doc = narrative** — what changed, deviations, deferrals; one link line to the PR sticky comment + dashboard.
- **No LLM metrics formatting** — sticky comment and snapshot are produced from CI artefacts by scripts.
- **JSON is the machine contract** — `.github/quality/quality-snapshot.json` feeds the dashboard.
- **No Mercy** — rename/replace `php-quality.yml`; do not keep old and new workflows in parallel (delete over wrap).
- **No duplicate specs, no duplicate work** — one E2E spec source, filtered per pipeline (§3.3); PR gates are not re-run wholesale on merge (§2 Trigger Matrix).

---

## 0. SAFETY CHECKS

Before starting:

1. Branch up-to-date with `develop`.
2. Baseline green on PHP 8.5:

```bash
php -v
composer install --no-interaction --prefer-dist --no-progress
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

**IF ANY FAILS → STOP AND FIX** (or report the pre-existing failure) before touching CI.

---

## 1. DISCOVERY

```bash
ls .github/workflows/
rg -n '8\.2|8\.3|8\.4' .github/workflows/ docs-site/ .github/quality/
rg -n '"source"|phpunit|infection' .github/quality/quality-snapshot.json docs-site/data/quality-snapshot.demo.json
rg -n 'PW_BROWSERS|projects|devices|viewport|grep|tag' playwright.config.js
rg -n 'MIN_LINE_COVERAGE|minMsi|minCoveredMsi' .github/workflows/ infection.json.dist
```

Record the **exact required status-check names** currently enforced by the branch Ruleset before renaming any workflow/job (renames orphan the required checks):

```bash
gh api repos/Shadesman5/pagekit/rulesets --jq '.[].name'
# then inspect the ruleset(s) gating develop for required_status_checks
```

---

## 2. TRIGGER MATRIX (authoritative what / where / when)

This matrix is the SSoT for the pipeline layout. Implement exactly this split.

| Trigger | When | PR-required? | Jobs |
| --- | --- | --- | --- |
| **PR** — `pull_request` → `develop`/`main` | every push to the PR | **yes** (gate, < 10 min) | PHPUnit (8.5 × SQLite) + coverage floor · PHPStan L8 · CS-Fixer · security-audit · Infection **diff** · **E2E smoke** (`@ci` × `chromium-desktop`) · Frontend |
| **Merge** — `push` → `develop`/`main` | after merge | no* | PHPUnit + coverage · PHPStan L8 · **E2E** (`@ci` × `chromium-desktop`) · **`quality-collect`** (write live snapshot). **No** re-run of CS-Fixer / security-audit / Frontend / Infection-diff |
| **Nightly** — `schedule` ~02:00 + `workflow_dispatch` | daily, **only if new commits** since last run | no | Infection **FULL** · **E2E** (`@ci` × **3 chromium viewports**) · (optional) `composer audit` vs latest advisories |
| **Weekly** — `workflow_dispatch` now / `schedule` later + guard | weekly, **only if new commits**; **manual until enabled** | no | **E2E ALL specs** × {chromium, firefox, webkit} × **3 viewports** (full sweep) |

\* Merge jobs are not PR-required but must stay green — a red `develop` is a stop-the-line signal.

**Guards (apply everywhere):**
- **PR/Merge:** `concurrency: cancel-in-progress` per ref (only the latest commit runs); `paths-ignore` for metrics/snapshot/data-branch paths so data writes never re-trigger CI.
- **Nightly + Weekly:** a shared **new-commits guard** (§3.6) skips the run when nothing was pushed since the last run; `workflow_dispatch` always forces.

---

## 3. WORK

Architect: decompose into ordered, individually-green checklist steps. Suggested order: 3.1 → 3.5 reporting scaffold → 3.2 → 3.4 → 3.3 (E2E, largest) → 3.6 → 3.7.

### 3.1 Workflow 1 — PHP Tests (`php-tests.yml`, migrated from `php-quality.yml`)

- Rename `php-quality.yml` → `php-tests.yml` (delete the old file — No Mercy). Keep the 4 gate jobs.
- **PR + Merge:** PHPUnit matrix = PHP 8.5 × {SQLite, MySQL}. Add a health-checked MySQL service container for the MySQL leg.
  - **Decide (Architect):** confirm the PHPUnit bootstrap/config can target MySQL. If the suite is **not DB-portable** yet, keep **SQLite required** and either mark the MySQL leg `continue-on-error: true` (non-blocking) or defer it with a `// TODO: ... Step 2.9` forward marker + record it under Manual Work (§ Manual Work). Do not fake a green MySQL leg.
- Keep PHPStan (L8 + baseline, no new entries), CS-Fixer (dry-run), security-audit as single 8.5 jobs (PR only; not re-run on merge).
- **Preserve the ratcheted coverage floor** — do NOT raise it to 75 %/60 % (that is Step 2.9).
- On **merge**, run PHPUnit **with coverage** + PHPStan so `quality-collect` (§3.5) has fresh develop-tip numbers.
- Realign required status-check names after the rename (§3.7 + Manual Work).

### 3.2 Workflow 1b — Infection

- **PR (required):** diff-scoped mutation on the auth + user security core only. Use Infection's git-diff filter (`--git-diff-base=origin/develop` / `--git-diff-lines`) so only changed lines mutate; enforce `minCoveredMsi` ≥ 80. Keep it small and fast.
- **Nightly (not required, guarded):** the **full** Infection suite; runs only when the new-commits guard passes (§3.6); `workflow_dispatch` forces.

### 3.3 E2E strategy & workflows

**Selection model (no duplicate specs — single source of truth):**

- **Viewports & browsers = Playwright *projects*, never separate spec files.** Define projects such as `chromium-desktop`, `chromium-tablet`, `chromium-mobile`, `firefox-desktop`, `webkit-desktop` (explicit `viewport` or device presets). Specs stay viewport-agnostic; pipelines select with `--project=…`.
- **Spec selection = tags, filtered with `--grep`.** Tag the ready/critical specs (currently the 3 optimized ones) with **`@ci`**. Pipelines that need the curated set pass `--grep @ci`; the weekly full sweep passes **no** grep (runs everything).
- **Quarantine unfinished specs.** The 8 not-yet-optimized specs stay **untagged** and, where they are known to break, are marked `test.fixme('reason')` (or `test.skip`) so they show as skipped — not failed — in the weekly full sweep. As each spec is optimized later, it gets the `@ci` tag / loses `fixme`. **Do not repair or rewrite these specs in this ticket** (out of scope — Step 3.6.1); record the quarantine list under Manual Work.

**Workflows:**

- **W2 — E2E smoke (PR, required):** `--grep @ci`, project `chromium-desktop` (1 viewport). CI setup: composer install, writable dirs, copy `test-config.json` from `.example.json`, `php pagekit setup ... -d sqlite --no-interaction`, `npx playwright install --with-deps chromium`. Keep well under ~10 min.
- **W2a — E2E on merge (`push` → develop/main, not required):** identical selection to W2 (`--grep @ci`, `chromium-desktop`, 1 viewport) but against the merged tip; feeds `quality-collect`.
- **W2b — E2E nightly (guarded, not required):** `--grep @ci` across **3 chromium viewports** (`chromium-desktop` + `chromium-tablet` + `chromium-mobile`).
  - **Tablet/mobile caveat:** the current `@ci` specs are desktop-optimized and are expected to fail on tablet/mobile. **Do not fix them here.** Make the tablet/mobile legs non-blocking (`fixme` those spec×viewport combos or `continue-on-error`) so the nightly run is not permanently red, and **record every failing spec×viewport combo under Manual Work** for the user to optimize after the ticket.
- **W2c — E2E weekly (full sweep, not required):** **all** specs (no grep) × {chromium, firefox, webkit} (`PW_BROWSERS=all`, `npx playwright install --with-deps`) × 3 viewports.
  - **Enablement:** ship with **`workflow_dispatch` only** (no active `schedule`) so the user can validate manually first. Prefer a togglable cron gated by a repo variable — `schedule:` present but the job `if: github.event_name == 'workflow_dispatch' || vars.E2E_WEEKLY_ENABLED == 'true'` — so switching to automatic is a variable flip, not a commit. Record "enable weekly E2E" under Manual Work.

### 3.4 Workflow 3 — Frontend (PR)

- **ESLint:** the legacy tree has **~13k pre-existing errors** (`AGENTS.md`) — a blocking full-tree lint is impossible. Scope the gate to **changed files** (diff-based) or make ESLint **advisory/non-blocking**. Do not "fix 13k errors" here.
- **Prettier:** format check (same scoping caveat).
- **Build verification (the solid gate):** `yarn install` + production build (`yarn compile-js --mode=production` + `gulp`) must succeed with no broken imports.

### 3.5 Workflow 4 — Quality reporting

- **`quality-report.yml` (PR):** assemble a **sticky PR comment** from CI artefacts (coverage, PHPStan, Infection-diff, E2E smoke, CS-Fixer, security, frontend) with an **idempotent hidden marker** so re-runs update in place. Built by a script from artefacts (no LLM formatting). Agents only **link** it.
- **`quality-collect.yml` (merge):** on **green** `push` → `develop`/`main`, build the live `quality-snapshot.json` (`"source": "github-actions"`, existing schema v2) and write it to an **unprotected data branch** (mirror the `conductor-metrics` pattern). **Do not** push to protected `develop`, and **do not** weaken the Ruleset with an Actions bypass. Must **not** re-trigger the PHP workflow (`paths-ignore` / `[skip ci]`). Reconcile `pages-deploy.yml` to overlay the snapshot from that data branch.
  - **Snapshot sourcing (blend):** coverage / PHPStan / E2E come from the **merge** jobs (develop tip); Infection **full** MSI comes from the **last nightly** run. **Label E2E numbers by scope** (`smoke` vs `full`) so a green merge snapshot does not imply the whole suite passed.
- **MkDocs dashboard:** point `quality-dashboard.js` + `copy_snapshot.py` at the live snapshot; **remove the demo banner**; align matrix keys/labels to **PHP 8.5-only** (remove the 8.2/8.3 legs) in both `docs-site/content/javascripts/quality-dashboard.js` and `docs-site/data/quality-snapshot.demo.json`. Reflect the E2E scope labels.

### 3.6 Scheduled-run guard (shared by nightly + weekly)

A lightweight first job gates the expensive jobs so identical commits are never re-run:

```yaml
guard:
  runs-on: ubuntu-latest
  outputs:
    should_run: ${{ steps.check.outputs.should_run }}
  steps:
    - uses: actions/checkout@<pinned-sha>
      with: { fetch-depth: 0 }
    - id: check
      run: |
        if [ "${{ github.event_name }}" = "workflow_dispatch" ]; then
          echo "should_run=true" >> "$GITHUB_OUTPUT"; exit 0
        fi
        # nightly: 24 hours; weekly: 7 days
        if [ -n "$(git log --since='24 hours ago' --oneline)" ]; then
          echo "should_run=true" >> "$GITHUB_OUTPUT"
        else
          echo "should_run=false" >> "$GITHUB_OUTPUT"
        fi
```

Downstream jobs: `needs: guard` + `if: needs.guard.outputs.should_run == 'true'`. A last-successful-run-SHA comparison is an acceptable alternative to the `--since` window; `workflow_dispatch` must always bypass the guard.

### 3.7 Cross-cutting

- **Required status checks + Ruleset alignment:** after finalizing workflow/job names, update the required-checks list. Likely a **manual admin action** — record the exact required set under Manual Work: `php-tests` (phpunit SQLite [+ MySQL if required], phpstan, cs-fixer, security-audit, coverage floor), `infection-diff`, `e2e-smoke`, `frontend`.
- **Dependency caching:** composer (`app/vendor`), yarn/npm, Playwright browsers, PHPUnit cache.
- **Version SSoT guard:** a small CI job/script asserting `composer.json` `require.php`/`platform.php` == PHPUnit matrix == `app/installer/requirements.php` `REQUIRED_PHP_VERSION` == `.cursor/Dockerfile` base == `README.md` badge. Fail on drift.
- **Agent/rule slimming (same step):** strip metric tables from `migration-docs/branches/branch-doc-skeleton.md` (Verification stays links-only; the `📊 appendix` must not become a metrics table). Update orchestrator handoff rules so Tester/test-writer pass **PASS/FAIL + changed files only** (no verbatim metric dumps to doc-writer) — see `.cursor/rules/orchestrator-*.mdc` and `.cursor/agents/{tester,test-writer,doc-writer}.md`.

---

## MANUAL WORK (record in the branch doc — agents do NOT perform these)

Log these (and any newly discovered ones) under the branch doc's "Deferred / Out-of-Scope" as an explicit **Manual Work Required** list for the user to action after the ticket:

1. **Required status-check realignment** in the branch Ruleset after the `php-quality.yml` → `php-tests.yml` rename (admin rights needed).
2. **Enable weekly E2E** — flip `vars.E2E_WEEKLY_ENABLED` to `true` (or add the `schedule:` cron) once specs are ready.
3. **Tablet/mobile viewport compatibility** of the current `@ci` specs — every failing spec×viewport combo from W2b (kept non-blocking here); user optimizes, then removes `fixme` / flips blocking.
4. **Un-quarantine the remaining specs** — the 8 untagged/`fixme` specs get optimized, tagged `@ci`, and enabled for the weekly full sweep.
5. Any MySQL-leg deferral (§3.1) if the suite was not DB-portable.

---

## 4. OUT OF SCOPE

- Big coverage push to 75 %/60 % → **Step 2.9** (keep the ratchet; do not jump the floor).
- **Repairing / rewriting E2E specs** (incl. tablet/mobile compat, the 8 unfinished specs) → user post-ticket + **Step 3.6.1**. This ticket only wires triggers, tags, projects, and quarantine.
- Edge-case E2E scenarios (large upload, session timeout, concurrent edits, network/DB loss) → **Step 2.9 / 3.6.1**.
- `data-testid` selector strategy → **Step 3.6**.
- MSI ratchet / widening Infection past auth + user → **Step 2.9**.
- Docker images (2.3), pnpm + Vite build-tool swap (2.4).
- Keeping any 8.2/8.3/8.4 leg anywhere.

---

## 5. TESTING

- **Per checklist step (local Conductor Tester):** PHPUnit + PHPStan PASS/FAIL. On the last Execute step + Finalize fix-loops: the 3 `@ci` E2E specs (`chromium-desktop`) locally — **PASS/FAIL only, never documented as numbers**.
- **CI is the real gate:** the new/renamed PR workflows must go green on the PR. Validate workflow YAML (`actionlint` if available). Trigger the nightly/weekly workflows once via `workflow_dispatch` to prove they run (weekly full sweep is expected to surface quarantined specs as skipped, not failed).
- **Manual:** `php pagekit setup ... -d sqlite --no-interaction` + admin login sanity.

---

## SUCCESS CRITERIA

- `php-tests.yml` replaces `php-quality.yml` (old file deleted); PHPUnit matrix = PHP 8.5 × {SQLite, MySQL} (MySQL required, or documented non-blocking/deferred); PHPStan L8, CS-Fixer, security-audit on PR; **ratcheted coverage floor preserved**.
- Merge (`push` → develop/main) runs **only** PHPUnit+coverage, PHPStan, E2E `@ci` × `chromium-desktop`, and `quality-collect` — no wholesale re-run of the PR gate.
- Infection: PR diff gate (required) + **guarded nightly full** + `workflow_dispatch`.
- E2E implemented via **tags + projects, zero duplicate specs**: smoke (`@ci` × desktop, PR-required) · on-merge (`@ci` × desktop) · nightly (`@ci` × 3 viewports, guarded, tablet/mobile non-blocking) · weekly (all specs × 3 browsers × 3 viewports, `workflow_dispatch`/flag, guarded). Viewports added to `playwright.config.js` as projects.
- Unfinished specs quarantined (`fixme`/untagged), **not repaired**; quarantine + tablet/mobile failures recorded under Manual Work.
- New-commits guard active on nightly **and** weekly; `workflow_dispatch` bypasses it.
- `quality-report.yml` idempotent sticky PR comment from CI artefacts; `quality-collect.yml` writes the live snapshot (blended sourcing, E2E scope-labeled) to an unprotected data branch without re-triggering CI; pages-deploy overlays it.
- Dashboard on live snapshot; demo banner removed; 8.2/8.3 legs gone; E2E scope labels shown.
- Version SSoT guard job in place.
- Branch-doc skeleton + orchestrator handoff rules slimmed (no metric tables).
- Manual Work list complete in the branch doc; required-check realignment either done or listed there.
- All PR-required gates green; no 8.2/8.3/8.4 remnants anywhere.

---

## NOTES FOR THE ARCHITECT

- Pin every third-party action by commit SHA (repo convention).
- Keep each **PR-required** workflow under ~10 minutes; push heavy work to nightly/weekly.
- One ticket / one PR, but this Roadmap Step is large — sequence checklist steps foundation-first and keep the tree green after each.
- This prompt follows `PHASE_2_MODERNISING.md` §2.2 (SSoT). Issue #157 has been modernized to match; where anything disagrees, §2.2 + this prompt win.
