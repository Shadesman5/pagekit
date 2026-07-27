---
hide:
  - toc
extra_javascript:
  - javascripts/conductor-dashboard.js
extra_css:
  - stylesheets/dashboard-layout.css
  - stylesheets/conductor-dashboard.css
---

# Project Roadmap

!!! info "Read-only mirror"
    Agents write [`.cursor/ROADMAP.md`](https://github.com/Shadesman5/pagekit/blob/develop/.cursor/ROADMAP.md) in Git.
    This page mirrors the tracking table and adds **Conductor metrics** when you expand a row.

<div id="conductor-metrics-app" class="conductor-metrics">
  <p class="cm-loading">Loading roadmap &amp; conductor metrics…</p>
</div>

### Columns

| Column | Source | Meaning |
|--------|--------|---------|
| ID … PR | `.cursor/ROADMAP.md` | Same tracking table as agents use |
| Runs | Conductor metrics | Workflow dispatches with parsed sessions |
| Tokens | Conductor metrics | Sum of all sessions for this step |

Expand a row for session detail, phase breakdown, charts, and links to Cursor agents / GHA jobs.

!!! note "Sync commands"
    ```bash
    node .github/conductor/sync-roadmap-snapshot.mjs
    node .github/conductor/backfill-metrics.mjs   # historical GHA logs
    ```

## Data sources

| Item | Location |
|------|----------|
| Agent SoT | `.cursor/ROADMAP.md` |
| Roadmap snapshot | `.github/conductor/metrics/roadmap-snapshot.json` |
| Metrics index | `.github/conductor/metrics/index.json` |
| Session files | `.github/conductor/metrics/sessions/{uuid}.json` |
| Live collector | `.github/conductor/metrics.mjs` |
| Future (Step 4.9) | `kernkit/dev-dashboard` — cross-repo, real-time branch metrics |

<sub>Marker: conductor-metrics:v2 · roadmap table + metrics accordion</sub>
