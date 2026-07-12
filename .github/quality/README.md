# Quality Snapshot

Machine-readable CI metrics for the [Quality Dashboard](https://pagekit.github.io/quality/).

## File

| Path | Purpose |
|------|---------|
| `quality-snapshot.json` | Live metrics — updated by `quality-collect.yml` (Step 2.2) |

## Schema

`schemaVersion: 2` — CI-only source (`"source": "github-actions"`).

See `migration-docs/TODO/QUALITY-GATES-EXTERNALIZATION-ANALYSIS.md` §13.2 for the full schema.

## Deploy

`pages-deploy.yml` copies this file to the site root as `quality-snapshot.json`.
The dashboard page loads it client-side via `fetch()`.

## Status

Currently contains **demo data** (`"source": "demo"`).
Live collection will be implemented in ROADMAP Step 2.2.
