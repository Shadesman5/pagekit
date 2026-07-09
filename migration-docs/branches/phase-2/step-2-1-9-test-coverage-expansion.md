# Step 2.1.9 — Test Coverage Expansion

**Branch:** `feature/test-coverage-expansion`
**ROADMAP Step:** 2.1.9 (Test Coverage Expansion)
**GitHub Issue:** [#156](https://github.com/Shadesman5/pagekit/issues/156)
**Pull Request:** _pending (set at Finalize)_
**Status:** ⏳ In progress
**Date:** 2026-07-09

> This document is seeded during Checklist Step 12 to record the pinned CI
> line-coverage baseline at the moment it was measured. The Orchestrator expands
> it with the full per-step change summary at Finalize.

---

## 📊 CI Line-Coverage Gate — Pinned Baseline (Step 12, §4.2)

A ratcheting minimum line-coverage gate was added to the `phpunit (8.3)` leg of
`.github/workflows/php-quality.yml`. It parses the Clover `<project><metrics>`
node emitted by `--coverage-clover=coverage.xml` and fails the job when line
coverage drops below the pinned floor.

| Metric | Value |
|---|---|
| **Measured line coverage (after Steps 1-11)** | **3.86 %** (`1833 / 47535` statements) |
| **Pinned CI floor** (`MIN_LINE_COVERAGE`) | **3.8 %** (rounded DOWN from 3.8561 %) |
| Pre-ticket baseline (for reference) | 3.21 % (`1524 / 47527`, planning-time) |
| Coverage driver (measurement) | PCOV 1.0.11 / PHP 8.3.6 |
| Coverage driver (CI enforcement) | Xdebug (`shivammathur/setup-php`) |
| PHPUnit | 11.5.55 |
| Measured on | 2026-07-09 |

**Why 3.8 % and not 3.86 %:** the value is rounded **down** to absorb (a) the
small line-count difference between PCOV (used for the local measurement) and
Xdebug (used by CI), and (b) general floating-point jitter. This keeps the gate
stable while still protecting essentially all of the coverage gained in Steps
1-11.

**Ratchet policy:** the floor only ever moves **up**. As coverage grows on
future branches, raise `MIN_LINE_COVERAGE` in the workflow to the new
rounded-down measurement — **never lower it**. The gate is a plain PHP one-liner
(no extra tooling / no new dependency), scoped to the 8.3 matrix leg that already
produces `coverage.xml`.

---

## 📈 Codecov Integration — Badge + Per-PR Delta (Step 13, §4.3)

The same `coverage.xml` Clover report that feeds the ratcheting gate is now also
uploaded to [Codecov](https://about.codecov.io/) so the project can surface a
coverage **badge** and **per-PR coverage-delta comments**.

### What landed in this PR (the codeable half)

| Change | Location | Notes |
|---|---|---|
| Codecov upload step | `.github/workflows/php-quality.yml` (`phpunit` **8.3** leg) | `codecov/codecov-action` pinned by full commit SHA `0fb7174895f61a3b6b78fc075e0cd60383518dac` (`# v5.5.5`), matching the repo's SHA-pinning convention. |
| Non-blocking upload | same step | `fail_ci_if_error: false` — Codecov availability **never** fails the quality gate. Runs `if: matrix.php == '8.3'` only (the leg that emits `coverage.xml`). |
| Token wiring | same step | `token: ${{ secrets.CODECOV_TOKEN }}` — evaluates to empty until the secret exists, so the step runs tokenless (best-effort) meanwhile and becomes reliable automatically once the secret is added (no further code change). `slug: Shadesman5/pagekit` set for correct fork attribution. |
| Coverage badge | `README.md` | `[![codecov](https://codecov.io/gh/Shadesman5/pagekit/graph/badge.svg)](https://codecov.io/gh/Shadesman5/pagekit)` added alongside the PHP / Symfony / Vue / UIkit / MySQL badges. |

### ⚠️ External activation required (flagged, NOT blocking)

Per-PR delta comments **and** a reliable (non-"unknown") badge require a **human**
with repo-admin rights to complete a one-time setup that a Cloud Agent cannot
perform:

1. **Install the Codecov GitHub App** on `Shadesman5/pagekit`
   (<https://github.com/apps/codecov> → Configure → select the repo).
2. **Add the `CODECOV_TOKEN` secret** in
   *GitHub → Settings → Secrets and variables → Actions* (repository secret).
   The value is the repo upload token shown on the Codecov dashboard after the
   app is installed. This makes uploads reliable and enables uploads from fork
   PRs.

Until both are done: the CI upload step still runs but is best-effort/tokenless
and simply no-ops on failure (non-blocking), and the README badge renders as
`unknown`. Nothing else in CI is affected.

**Coveralls is an acceptable equivalent.** If the maintainer prefers
[Coveralls](https://coveralls.io/) over Codecov, swap the upload action for
`coverallsapp/github-action` (also consuming `coverage.xml`) and replace the
badge URL accordingly — the non-blocking, same-report wiring pattern is
identical.
