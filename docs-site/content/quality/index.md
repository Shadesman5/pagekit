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

Live CI metrics for the `develop` branch. Data is loaded client-side from `quality-snapshot.json`.

!!! info "Demo data"
    Until **ROADMAP Step 2.2**, this page shows placeholder values.
    After Step 2.2, `quality-collect.yml` will update `.github/quality/quality-snapshot.json` on each merge.

<div id="quality-dashboard-app" class="quality-dashboard">
  <p class="quality-loading">Loading quality metrics…</p>
</div>

## Data source

| Item | Location |
|------|----------|
| Live snapshot | `.github/quality/quality-snapshot.json` |
| Schema | `.github/quality/README.md` |
| CI collector | `quality-collect.yml` (Step 2.2) |
| Deploy | `pages-deploy.yml` copies JSON into site root |

<sub>Marker: quality-dashboard:v1 · CI-only · updated on merge to develop</sub>
