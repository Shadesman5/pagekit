# Quality Snapshot

Machine-readable CI metrics for the [Quality Dashboard](https://shadesman5.github.io/pagekit/quality/).

## Files

| Path | Purpose |
|------|---------|
| `quality-snapshot.json` | Current `develop` tip. Seed only — live metrics are published to the `quality-data` branch by `quality-collect.yml` |
| `quality-history.json` | Append-only series behind the dashboard charts. Lives on `quality-data` only; there is no in-repo seed |

## Schema

`schemaVersion: 3` — CI-only source (`"source": "github-actions"`; seed and demo files use `"source": "demo"`).

Top-level shape (see `quality-snapshot.json` for a full example):

```json
{
  "schemaVersion": 3,
  "source": "github-actions",
  "updatedAt": "ISO-8601",
  "branch": "develop",
  "commit": "sha of the merge both gate runs describe",
  "workflows": {
    "phpTests": { "runId": 0, "conclusion": "success" },
    "e2eTests": { "runId": 0, "conclusion": "success" },
    "infectionFull": { "runId": 0, "conclusion": "success", "scheduled": true }
  },
  "phpunit": {
    "8.5-sqlite": { "db": "sqlite", "required": true, "tests": 0, "failures": 0 },
    "8.5-mysql": { "db": "mysql", "required": false, "conclusion": "success" }
  },
  "phpstan": { "level": 8, "errors": 0, "baselineBlocks": 0, "suppressedErrors": 0 },
  "coverage": { "linePercent": 0, "pinnedFloor": 0, "statements": 0, "covered": 0 },
  "infection": {
    "prDiff": { "msi": null, "coveredMsi": null, "scope": "diff" },
    "dailyFull": {
      "msi": null, "coveredMsi": null,
      "killed": null, "escaped": null, "timedOut": null, "errors": null, "totalMutants": null,
      "scope": "full", "runAt": null
    }
  },
  "e2e": { "scope": "smoke", "viewports": ["mobile", "tablet", "desktop"], "specsTotal": 0, "specsPassed": 0, "durationMs": 0 },
  "gates": {
    "csFixer": "pass",
    "securityAudit": "pass",
    "frontendLint": "pass",
    "codecov": "non-blocking",
    "bugbot": "n/a"
  }
}
```

### Fields

- **`phpunit`** — one entry per test leg, keyed `"<php>-<db>"` (e.g. `"8.5-sqlite"`, `"8.5-mysql"`). Required legs carry `tests`/`failures` counts; non-required legs (`"required": false`, e.g. the non-blocking MySQL leg) carry only a job `conclusion`. The dashboard renders these rows dynamically and marks non-required legs as informational (⚪).
- **`e2e.scope`** — `"smoke"` for the per-merge run, `"full"` for the weekly sweep.
- **`infection.prDiff`** — always `null` here. Diff-scoped MSI is a per-PR number and belongs in the PR comment, not in the branch snapshot.
- **`infection.dailyFull`** — full-suite MSI and mutant counts from the latest successful Nightly; `null` until the first nightly has run, which the dashboard shows as `awaiting nightly`. The dashboard marks the row ✅/❌ against the `minMsi` / `minCoveredMsi` threshold of `infection.json.dist` (80).

## History

`quality-history.json` is the series the dashboard charts. It shares `schemaVersion` with the snapshot:

```json
{
  "schemaVersion": 3,
  "branch": "develop",
  "cap": 90,
  "points": [
    {
      "at": "ISO-8601",
      "sha": "…",
      "coverage": { "linePercent": 6.1, "pinnedFloor": 3.8 },
      "phpunit": { "tests": 725, "failures": 0 },
      "phpstan": { "errors": 0, "baselineBlocks": 351, "suppressedErrors": 695 },
      "infection": { "msi": 99.19, "coveredMsi": 99.19, "killed": 363, "escaped": 3, "timedOut": 2, "errors": 1 },
      "e2e": { "specsPassed": 25, "specsTotal": 25, "scope": "smoke" }
    }
  ]
}
```

**Diff-guard.** Collect runs on every merge and every nightly, but the metrics rarely move. A point is appended only when one of these differs from the last point:

- `coverage.linePercent`
- `phpunit.tests` / `phpunit.failures` (required SQLite leg)
- `phpstan.errors` / `baselineBlocks` / `suppressedErrors`
- `infection` `msi` / `coveredMsi` / `killed` / `escaped` — compared only while the nightly reports, so a temporarily missing run cannot register as a change twice
- `e2e.specsPassed` / `specsTotal`

An unchanged collection still refreshes the snapshot's `updatedAt` and run IDs; the series stays put. The file keeps the last **90** points so the dashboard can fetch it on every page load.

## Collect triggers

`quality-collect.yml` runs on any successful non-PR run of:

| Event | Why |
|-------|-----|
| Push (merge) of **PHP Tests** or **E2E** on `develop` | New tip numbers — published once both gates are green on the same commit |
| **Nightly** (scheduled or dispatched) | Its full-suite MSI is the dashboard's only Infection number; waiting for the next merge would leave it stale |
| `workflow_dispatch` | Manual collection (ops, or after a local landing) |

## Deploy

`pages-deploy.yml` overlays the snapshot and history from the `quality-data` branch when they exist (falling back to the in-repo seed, and to no charts when there is no history), then the MkDocs hook copies both to the site root. The dashboard loads them client-side via `fetch()`.

## Reporting surfaces

Three surfaces, three jobs — no duplication:

| Surface | Role |
|---------|------|
| GitHub Checks | The merge gate. PASS / FAIL, nothing else |
| Sticky PR comment | PR impact. This PR's numbers and their delta vs the develop snapshot; no PASS/FAIL column |
| Pages dashboard | Project health for `develop`. Current numbers with verdicts, plus the trend |

## Status

Live collection is wired: `quality-collect.yml` blends the latest green merge runs (PHP Tests + E2E, plus the latest Nightly for full Infection MSI) and publishes to the unprotected **`quality-data`** branch — never via a Ruleset bypass on `develop`.

The in-repo `quality-snapshot.json` is the **seed** (`"source": "demo"`), served until a live collection run overlays it (`"source": "github-actions"`). Both collector scripts accept `DRY_RUN=1` to render their output locally without writing anything — the only way to see the real result before the change reaches the default branch, since `workflow_run` and `workflow_dispatch` always execute the copy that lives there.
