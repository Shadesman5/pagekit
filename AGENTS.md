# AGENTS.md

## Cursor Cloud specific instructions

### Overview

Pagekit CMS is a modular PHP CMS built on Symfony 6.4 components with a Vue.js 2.6 + UIkit 3.5 frontend. The codebase uses Webpack 4 for JS/Vue bundling and Gulp for LESS/CSS compilation.

### Services

| Service | Command | Notes |
|---|---|---|
| PHP dev server | `php -S localhost:8080 index.php` | Serves the full app; run from workspace root |
| PHPUnit | `./app/vendor/bin/phpunit` | 261 tests; no external DB needed |
| ESLint | `yarn lint` | Pre-existing style errors (~13k); runs correctly |
| Webpack (JS build) | `yarn compile-js --mode=production` | Or `yarn watch-js` for dev |
| Gulp (LESS build) | `yarn compile-less` | Or `yarn watch-less` for dev |
| Both watchers | `yarn watch-all` | Runs webpack + gulp in parallel |

### Non-obvious caveats

- **Vendor directory is `app/vendor/`**, not the standard `vendor/`. Composer is configured via `"config": {"vendor-dir": "app/vendor"}` in `composer.json`. PHPUnit binary is at `./app/vendor/bin/phpunit`.
- **`yarn install` triggers a full production build** via its `postinstall` script (`yarn compile-js --mode=production && gulp`). This is expected and takes ~7s.
- **First run requires Pagekit web installer.** If `/workspace/config.php` does not exist, the app redirects to the installer at `/`. Use SQLite for zero-dependency setup. The installer creates `config.php` and the SQLite database at `/workspace/pagekit.db`.
- **`config.php` is gitignored** and must be created via the installer on each fresh environment. After installer completion, admin login is at `/index.php/admin/login`.
- **PHP built-in server uses `index.php` as router file.** Always pass it: `php -S localhost:8080 index.php`.
- **ESLint has ~13k pre-existing style errors** (indent, arrow-parens, etc.). These are not regressions; the codebase predates the current ESLint config.
- **Writable directories needed:** `tmp/` (logs, cache, temp, packages) and `storage/` must be writable. Create them with `mkdir -p tmp/logs tmp/cache tmp/temp tmp/packages storage`.
- **`php pagekit start`** is documented in README but just wraps `php -S 0.0.0.0:8080 index.php`. Use the direct command for more control.
- **E2E tests (Playwright)** require the dev server running and a completed installation. Config at `tests/e2e/config/test-config.json` (copy from `.example.json`).
- **Modernisation workflow** is defined in `.cursor/rules/` (push.mdc, feature-branch.mdc, orchestrator-subagent-workflow.mdc) and `.cursor/ROADMAP.md`.
