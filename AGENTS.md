# AGENTS.md

## Cursor Cloud specific instructions

### Overview

Pagekit CMS is a modular PHP CMS built on Symfony 6.4 components with a Vue.js 2.6 + UIkit 3.5 frontend. The codebase uses Webpack 4 for JS/Vue bundling and Gulp for LESS/CSS compilation.

### Services

| Service | Command | Notes |
|---|---|---|
| PHP dev server | `php -S localhost:8080 index.php` | Serves the full app; run from workspace root |
| PHPUnit | `./app/vendor/bin/phpunit` | 326 tests; no external DB needed |
| ESLint | `yarn lint` | Pre-existing style errors (~13k); runs correctly |
| Webpack (JS build) | `yarn compile-js --mode=production` | Or `yarn watch-js` for dev |
| Gulp (LESS build) | `yarn compile-less` | Or `yarn watch-less` for dev |
| Both watchers | `yarn watch-all` | Runs webpack + gulp in parallel |

### Non-obvious caveats

- **Vendor directory is `app/vendor/`**, not the standard `vendor/`. Composer is configured via `"config": {"vendor-dir": "app/vendor"}` in `composer.json`. PHPUnit binary is at `./app/vendor/bin/phpunit`.
- **`yarn install` triggers a full production build** via its `postinstall` script (`yarn compile-js --mode=production && gulp`). This is expected and takes ~7s.
- **First run requires a Pagekit installation.** If `/workspace/config.php` does not exist, the app redirects to the web installer at `/`. For headless/agent setups, prefer the non-interactive CLI: `php pagekit setup -u admin -p '<password>' -t "Pagekit Dev" -m admin@example.com -d sqlite --no-interaction`. Either path creates `config.php` and the SQLite database at `/workspace/pagekit.db`. Two quirks: (1) `setup` prints `Done`/`Existing Pagekit installation detected` but returns a non-zero exit code — verify success by checking that `config.php` exists; (2) re-running `setup` against an existing install is safe — it aborts instead of clobbering the DB, so it will NOT reset an existing admin password.
- **`config.php` is gitignored** and must be created via the installer on each fresh environment. After installer completion, admin login is at `/index.php/admin/login`.
- **PHP built-in server uses `index.php` as router file.** Always pass it: `php -S localhost:8080 index.php`.
- **ESLint has ~13k pre-existing style errors** (indent, arrow-parens, etc.). These are not regressions; the codebase predates the current ESLint config.
- **Writable directories needed:** `tmp/` (logs, cache, temp, packages) and `storage/` must be writable. Create them with `mkdir -p tmp/logs tmp/cache tmp/temp tmp/packages storage`.
- **`php pagekit start`** is documented in README but just wraps `php -S 0.0.0.0:8080 index.php`. Use the direct command for more control.
- **E2E tests (Playwright)** require the dev server running and a completed installation. Config at `tests/e2e/config/test-config.json` (copy from `.example.json`).
- **Playwright browsers in Cloud Agent VM:** Only **chromium** is installed (`.cursor/Dockerfile`). Firefox/webkit need root for `playwright install-deps`, which is unavailable in the cloud agent VM. The default `playwright.config.js` projects list is chromium-only; set `PW_BROWSERS=all` to enable firefox/webkit (intended for CI/CD pipelines on full hosts).
- **Modernisation workflow** is defined in `.cursor/rules/` (push.mdc, feature-branch.mdc, orchestrator-subagent-workflow.mdc) and `.cursor/ROADMAP.md`.

### Cursor Cloud Agent pitfalls

- **Secret names must be valid bash identifiers.** The Cursor platform exposes the names of injected secrets via `CLOUD_AGENT_INJECTED_SECRET_NAMES` (a space-separated list). If a secret name in the Cursor Dashboard contains whitespace (e.g. `PAGEKIT BACKGROUND AGENT` instead of `PAGEKIT_BACKGROUND_AGENT`), the platform-internal pre-commit secret scanner breaks because the list is parsed as separate tokens. Symptom: `git commit` fails until invoked with `--no-verify`. **Fix:** rename all secrets in [Cursor Dashboard → Cloud Agents → Secrets](https://cursor.com/dashboard/cloud-agents) to match `[A-Z_][A-Z0-9_]*` (uppercase + underscores, no spaces).
- **Environment is repo-versioned.** `.cursor/environment.json` lives in the repo and references `.cursor/Dockerfile` + `.cursor/install.sh` + `.cursor/start.sh`. This takes precedence over personal/team configs in the Cursor Dashboard (see [resolution order](https://cursor.com/docs/cloud-agent/setup#environment-resolution-order)) and ensures every cloud agent — including Bugbot on PRs — uses the same setup.
