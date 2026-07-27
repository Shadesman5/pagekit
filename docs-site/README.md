# Pagekit CMS — Modernization Dashboard

Internal documentation site for the Pagekit **modernization program** — roadmap metrics, CI quality, and branch docs. End-user / product documentation is out of scope here (planned for a separate repository).

## Structure

| Path | Purpose |
|------|---------|
| `content/project/` | Roadmap + Conductor metrics dashboard |
| `content/quality/` | CI quality dashboard |
| `content/javascripts/` | Client-side dashboard loaders |
| `content/stylesheets/` | Dashboard CSS |
| `data/conductor-metrics/` | Local mkdocs mirror (gitignored; copy from `.github/conductor/metrics/`) |

CI copies `.github/quality/quality-snapshot.json` and `.github/conductor/metrics/` into the built site on deploy.

## Local preview

```bash
pip install mkdocs-material
# optional: refresh local metrics mirror for mkdocs serve
node .github/conductor/enrich-metrics-cursor.mjs --copy-local
mkdocs serve -f docs-site/mkdocs.yml
```

Open `http://127.0.0.1:8000/pagekit/project/roadmap/` for Conductor metrics.

**Windows:** after setting `CURSOR_API_KEY` as a system env var, restart Cursor (or open a new terminal) so scripts see the key.

## Deploy

Pushes to `develop` trigger `.github/workflows/pages-deploy.yml`.

GitHub Pages source must be set to **GitHub Actions** (Settings → Pages).

## Conductor metrics backfill

Historical runs (before live collection):

```bash
node .github/conductor/backfill-metrics.mjs [--dry-run] [--limit N]
```

Requires authenticated `gh` CLI.
