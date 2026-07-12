# Pagekit Docs Site

Static documentation site for [GitHub Pages](https://Shadesman5.github.io/pagekit/).

## Structure

| Path | Purpose |
|------|---------|
| `content/` | Published Markdown pages |
| `javascripts/` | Client-side scripts (quality dashboard) |
| `stylesheets/` | Custom CSS |
| `data/` | Demo JSON for local preview |

CI copies `.github/quality/quality-snapshot.json` into the built site on deploy.

## Local preview

```bash
pip install mkdocs-material
mkdocs serve -f docs-site/mkdocs.yml
```

Open `http://127.0.0.1:8000/`.

## Deploy

Pushes to `develop` trigger `.github/workflows/pages-deploy.yml`.

GitHub Pages source must be set to **GitHub Actions** (Settings → Pages).
