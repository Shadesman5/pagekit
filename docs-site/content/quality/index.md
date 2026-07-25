---
hide:
  - toc
extra_javascript:
  - javascripts/quality-dashboard.js
extra_css:
  - stylesheets/dashboard-layout.css
  - stylesheets/quality-dashboard.css
---

# Quality Dashboard

Project health for the `develop` branch: the current numbers with their verdicts, and the trend
behind them. Both are loaded client-side from files CI publishes — nothing here is written by hand.

Per-pull-request numbers live in the sticky Quality Report comment on the PR itself, and the merge
verdict lives in the GitHub Checks. This page is the branch view.

<div id="quality-dashboard-app" class="quality-dashboard">
  <p class="quality-loading">Loading quality metrics…</p>
</div>

## Data source

| Item | Location |
|------|----------|
| Live snapshot | `quality-data` branch → `.github/quality/quality-snapshot.json` (in-repo file is the seed) |
| Chart history | `quality-data` branch → `.github/quality/quality-history.json` — appended only when a metric changes, last 90 points |
| Schema | `.github/quality/README.md` |
| CI collector | `quality-collect.yml` — runs on merge to `develop` and after each Nightly |
| Deploy | `pages-deploy.yml` overlays both files from `quality-data` into the site root |

<sub>Marker: quality-dashboard:v2 · CI-only · updated on merge to develop and after each nightly</sub>
