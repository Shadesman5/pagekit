# Quality Snapshot

Machine-readable CI metrics for the [Quality Dashboard](https://pagekit.github.io/quality/).

## File

| Path | Purpose |
|------|---------|
| `quality-snapshot.json` | Seed snapshot; live metrics are published to the `quality-data` branch by `quality-collect.yml` |

## Schema

`schemaVersion: 2` — CI-only source (`"source": "github-actions"`; demo files use `"source": "demo"`).

Top-level shape (see `quality-snapshot.json` for a full demo):

```json
{
  "schemaVersion": 2,
  "source": "github-actions",
  "updatedAt": "ISO-8601",
  "branch": "develop",
  "workflows": {
    "phpTests": { "runId": 0, "conclusion": "success" },
    "e2eTests": { "runId": 0, "conclusion": "success" },
    "frontendTests": { "runId": 0, "conclusion": "success" },
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
    "dailyFull": { "msi": null, "coveredMsi": null, "scope": "full", "runAt": null }
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
- **`infection.dailyFull`** — full-suite MSI from the latest successful Nightly run; `null` until the first nightly has run.

## Deploy

`pages-deploy.yml` overlays the snapshot from the `quality-data` branch when it exists (falling back to the in-repo seed), then copies it to the site root as `quality-snapshot.json`. The dashboard page loads it client-side via `fetch()`.

## Status

Live collection is wired: `quality-collect.yml` blends the latest green merge runs (PHP Tests + E2E, plus the latest Nightly for full Infection MSI) and publishes the snapshot to the unprotected **`quality-data`** branch — never via a Ruleset bypass on `develop`.

The in-repo `quality-snapshot.json` is the **seed** (`"source": "demo"`), served until the first live collection run overlays it (`"source": "github-actions"`).
