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

<div id="quality-dashboard-app" class="quality-dashboard">
  <p class="quality-loading">Loading quality metrics…</p>
</div>

## Data source

| Item | Location |
|------|----------|
| Live snapshot | `quality-data` branch → `.github/quality/quality-snapshot.json` (in-repo file is the seed) |
| Schema | `.github/quality/README.md` |
| CI collector | `quality-collect.yml` — runs on merge to `develop` |
| Deploy | `pages-deploy.yml` overlays the `quality-data` snapshot into the site root |

<sub>Marker: quality-dashboard:v1 · CI-only · updated on merge to develop</sub>
