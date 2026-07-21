# Quality Snapshot

Machine-readable CI metrics for the [Quality Dashboard](https://pagekit.github.io/quality/).

## File

| Path | Purpose |
|------|---------|
| `quality-snapshot.json` | Live metrics — updated by `quality-collect.yml` (Step 2.2) |

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
  "phpunit": { "8.2": { "db": "sqlite", "tests": 0, "failures": 0 } },
  "phpstan": { "level": 8, "errors": 0, "baselineBlocks": 0, "suppressedErrors": 0 },
  "coverage": { "linePercent": 0, "pinnedFloor": 0, "statements": 0, "covered": 0 },
  "infection": {
    "prDiff": { "msi": null, "coveredMsi": null, "scope": "diff" },
    "dailyFull": { "msi": null, "coveredMsi": null, "scope": "full", "runAt": null }
  },
  "e2e": { "viewports": ["mobile", "tablet", "desktop"], "specsTotal": 0, "specsPassed": 0, "durationMs": 0 },
  "gates": {
    "csFixer": "pass",
    "securityAudit": "pass",
    "frontendLint": "pass",
    "codecov": "non-blocking",
    "bugbot": "n/a"
  }
}
```

## Deploy

`pages-deploy.yml` copies this file to the site root as `quality-snapshot.json`.
The dashboard page loads it client-side via `fetch()`.

## Status

Currently contains **demo data** (`"source": "demo"`).
Live collection is ROADMAP Step 2.2 (`quality-collect.yml`). Write the live file to an unprotected data branch — not via a Ruleset bypass on `develop`.
