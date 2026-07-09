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
