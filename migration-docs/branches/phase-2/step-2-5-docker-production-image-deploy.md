# Step 2.5 — Docker Production Image & Deploy

<!-- Branch doc for Roadmap Step 2.5.
     Path: migration-docs/branches/phase-2/step-2-5-docker-production-image-deploy.md -->

**Branch:** `feature/docker-production-image`
**ROADMAP Step:** 2.5 (Docker Production Image & Deploy)
**GitHub Issue:** [#158](https://github.com/Shadesman5/pagekit/issues/158)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-07-31 03:10
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### Env-override config layer, trusted proxies & weather-key removal (Checklist Step 1)

| File | Change |
|---|---|
| `app/modules/application/src/Module/Loader/EnvConfigLoader.php` (new) | `ConfigLoader` reading the fixed env map: `PAGEKIT_DEBUG` → `application.debug` (bool via `FILTER_VALIDATE_BOOLEAN`); `PAGEKIT_SECRET` → `system.secret`; `PAGEKIT_DB_DRIVER` (validated against `mysql`/`sqlite`, else throws) + `_HOST/_PORT(int)/_NAME/_USER/_PASSWORD/_PREFIX` → `database.connections.mysql.*`; `PAGEKIT_DB_PATH` → `database.connections.sqlite.path`; `PAGEKIT_WEATHER_API_KEY` → `system/dashboard`'s flat `weather.key`. Reads via `getenv()`; a variable that is unset is left out of the map (no-op), one that is set-but-empty overrides with `''`. |
| `app/modules/application/src/Application/TrustedProxies.php` (new) | `configureFromEnvironment()` parses `PAGEKIT_TRUSTED_PROXIES` (comma-separated addresses/CIDRs, or the literal `REMOTE_ADDR`) and calls `Request::setTrustedProxies()` with `HEADER_X_FORWARDED_FOR\|HOST\|PORT\|PROTO` (`X-Forwarded-Prefix` deliberately excluded); no-op when the variable is unset or blank. |
| `app/modules/application/src/Application.php` | `run()` calls `TrustedProxies::configureFromEnvironment()` immediately before building the request via `Request::createFromGlobals()` — only on that path; a `Request` passed into `run()` keeps whatever trust its caller already configured. |
| `app/system/app.php` | `EnvConfigLoader` registered as the last module loader, after the existing `config.file` `ConfigLoader`. |
| `app/console/app.php` | `EnvConfigLoader` registered last, unconditionally on `config.file` (previously the whole loader-plus-`system`-load block was gated by that one `if`); `module->load('system')` now sits in its own `if ($configFile)` check so a console run with no config yet still builds the env-aware loader chain. |
| `app/installer/app.php` | `EnvConfigLoader` registered last, after the installer's own `ConfigLoader`. |
| `app/system/modules/dashboard/index.php` | Hardcoded OpenWeatherMap key and its `AUDIT FIX Step 2.5` comment removed; `weather.key` default is now `''`. |
| `prod.env.example` (new) | Placeholders (one-line comment each) for every var `EnvConfigLoader`/`TrustedProxies` consume so far: `PAGEKIT_DEBUG`, `PAGEKIT_SECRET`, `PAGEKIT_DB_DRIVER/HOST/PORT/NAME/USER/PASSWORD/PREFIX/PATH`, `PAGEKIT_TRUSTED_PROXIES`, `PAGEKIT_WEATHER_API_KEY`. Grows with compose/entrypoint vars in later Checklist Steps. |

### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `app/modules/application/src/Tests/EnvConfigLoaderTest.php` (new) | Fixed-map correctness (`PAGEKIT_DEBUG` bool-spelling data provider, `PAGEKIT_DB_PORT` int-cast, `PAGEKIT_DB_DRIVER` rejecting an unknown driver, flat `weather.key` shape), unset-env leaves modules untouched, a set-but-empty variable overrides with `''`, and loader-chain precedence (`config.php` survives when the env is silent; the env wins when both are set). |
| `app/modules/application/src/Tests/TrustedProxiesTest.php` (new) | `parse()` against comma/whitespace/stray-comma input and the `REMOTE_ADDR` literal; no-op when the variable is unset or blank; a trusted proxy's `X-Forwarded-*` headers decide `isSecure()`/host/port/client IP while `X-Forwarded-Prefix` and the RFC 7239 `Forwarded` header stay untrusted; `Application::run()` wiring, incl. a caller-supplied `Request` keeping its own trust untouched. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer PASS; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Multi-target Dockerfile, prod php.ini, entrypoint, `.dockerignore` & proxy-aware redirects (Checklist Step 2)

| File | Change |
|---|---|
| `Dockerfile` | Grew from one stage into five. `base`: unchanged apt/extension set, plus `a2enmod headers expires` alongside the existing `rewrite` (both newly enabled; `public/.htaccess`'s `mod_headers`/`mod_expires` blocks had been silently no-op'ing without them) and a `hadolint ignore=DL3008` recorded next to the apt block with a why comment for the deliberately-unpinned package versions. `dev`: today's dev image content moved here verbatim (Composer binary, `AllowOverride All` vhost on `:80`, no baked code) — dev compose now targets it explicitly. `composer-deps` (new): `composer install --no-dev --no-scripts --no-autoloader`, then `composer dump-autoload --no-dev --optimize` — `--optimize`, not an authoritative classmap (see Key Decisions). `assets` (new): `node:22-bookworm-slim`, `corepack enable`, `pnpm install --frozen-lockfile`, `pnpm build` — writes JS bundles, compiled LESS and copied assets into `public/`. `prod` (new, last stage — the default `docker build .` target): `FROM base`; copies `app/vendor` from `composer-deps` and `public/` from `assets`; enables opcache and layers `docker/php/php-prod.ini` on `php.ini-production`; moves Apache to `Listen 8080` with a vhost matching the dev one but logging to the container's own stdout/stderr; recreates the `public/storage` symlink and links `config.php` to `$PAGEKIT_DATA_DIR/config.php`; `chown`s only `tmp/`, `storage/`, and `$PAGEKIT_DATA_DIR` to `www-data` before the final `USER www-data`; OCI labels; `HEALTHCHECK` against `/`; `ENTRYPOINT ["entrypoint.sh"]` + `CMD ["apache2-foreground"]`. |
| `docker/php/php-prod.ini` (new) | Overlay on `php.ini-production`: `display_errors`/`display_startup_errors` and `expose_php` off, `log_errors` to `/proc/self/fd/2`; `opcache.validate_timestamps=0` plus sizing (comment corrected on what that setting needs to hold — see Key Decisions); `session.cookie_httponly`/`use_strict_mode` on; `date.timezone=UTC`; `memory_limit=256M`, `max_execution_time=120`, `upload_max_filesize`/`post_max_size=64M`, `max_input_vars=3000`. |
| `docker/entrypoint.sh` (new) | Recreates `tmp/*`, `storage/`, and `$PAGEKIT_DATA_DIR` on every start (a mounted volume can start empty); (re)points the `config.php` symlink at `$PAGEKIT_DATA_DIR/config.php` when it isn't already; with `PAGEKIT_AUTO_SETUP` on and no `config.php` yet, runs `php pagekit setup --no-interaction` with flags built from whichever `PAGEKIT_DB_*`/`PAGEKIT_ADMIN_*`/`PAGEKIT_SITE_TITLE`/`PAGEKIT_LOCALE` variables are set; with `PAGEKIT_AUTO_MIGRATE` on and an install present, runs `php pagekit migration:migrate --no-interaction`; ends with `exec "$@"` so Apache stays PID 1. |
| `.dockerignore` | Stopped excluding `docker/` (the `prod` stage now `COPY`s `docker/php/php-prod.ini` and `docker/entrypoint.sh` out of it); added `public/app`, `public/packages`, `public/storage` so host-side build leftovers can't shadow the deterministic in-stage `pnpm build`. `Dockerfile*`/`docker-compose*.yml` stay excluded. |
| `docker-compose.yml` | Dev `web` service's `build:` gained `target: dev`, now that the `Dockerfile` builds `prod` by default. |
| `public/.htaccess` | The `RewriteCond %{HTTPS} off` HTTPS-forcing rule gained a `RewriteCond %{HTTP:X-Forwarded-Proto} !https` guard; the `www.`-host redirect above it did not (see Key Decisions). |
| `prod.env.example` | Extended with the vars `docker/entrypoint.sh` reads: `PAGEKIT_DATA_DIR`, `PAGEKIT_AUTO_SETUP`, `PAGEKIT_ADMIN_USERNAME`/`PASSWORD`/`MAIL`, `PAGEKIT_SITE_TITLE`, `PAGEKIT_LOCALE`, `PAGEKIT_AUTO_MIGRATE` — same one-line-comment-per-var format Checklist Step 1 started. |

Tests: none (test-writer: skip — Dockerfile/ini/shell/`.htaccess`, no production PHP under `app/`/`packages/`). Gates: Verifier (production) FAIL → PASS (comment fix on an opcache-invalidation claim — see Key Decisions); Tester PHPUnit+PHPStan PASS.

### Production compose stack & prod env finalization (Checklist Step 3)

| File | Change |
|---|---|
| `docker-compose.prod.yml` (new) | Named project `pagekit-prod` (see Key Decisions). `web`: `image: ghcr.io/shadesman5/pagekit:${PAGEKIT_IMAGE_TAG:-develop}` with a `build: {context: ., target: prod}` fallback; `ports: "8080:8080"`; `environment` passes `PAGEKIT_DEBUG`, `PAGEKIT_SECRET`, `PAGEKIT_DB_PREFIX`, `PAGEKIT_DB_PATH`, `PAGEKIT_TRUSTED_PROXIES`, `PAGEKIT_WEATHER_API_KEY` straight through and the entrypoint-only vars (`PAGEKIT_AUTO_SETUP`, `PAGEKIT_ADMIN_USERNAME`/`PASSWORD`/`MAIL`, `PAGEKIT_SITE_TITLE`, `PAGEKIT_LOCALE`, `PAGEKIT_AUTO_MIGRATE`) unset by default; `PAGEKIT_DB_HOST`/`PORT` default to the bundled service (`mysql`/`3306`) while `PAGEKIT_DB_NAME`/`USER`/`PASSWORD` derive from `MYSQL_DATABASE`/`USER`/`PASSWORD` instead of a separate set (see Key Decisions); named volumes `pagekit_data:/var/www/data` + `pagekit_storage:/var/www/html/storage` (no host bind-mounts; `tmp/` stays container-local); `depends_on: mysql: condition: service_healthy`; `restart: unless-stopped`; `deploy.resources.limits` (`cpus: "2.0"`, `memory: 1G` — see Risks). `mysql`: `mysql:8.4`, named volume `mysql_data`, credentials from `MYSQL_DATABASE`/`USER`/`PASSWORD`/`ROOT_PASSWORD`, TCP `mysqladmin ping` healthcheck (see Key Decisions), no host port published, `restart: unless-stopped`. No phpmyadmin/node services. |
| `prod.env.example` | Reworked into the two-audience shape decision 8 calls for: a new `--- Image ---` section (`PAGEKIT_IMAGE_TAG`) and a new `--- Database server ---` section (`MYSQL_DATABASE`/`USER`/`PASSWORD`/`ROOT_PASSWORD`) for what Compose itself reads, ahead of the existing `PAGEKIT_*` sections for the container. `PAGEKIT_DB_NAME`/`USER`/`PASSWORD` dropped (superseded by the `MYSQL_*` block); `PAGEKIT_DB_HOST`/`PORT` changed from set to commented-out, since `docker-compose.prod.yml` now supplies the same defaults itself. Header comment rewritten around the compose invocation (`docker compose -f docker-compose.prod.yml --env-file prod.env up -d`) and the SQLite/`--no-deps web` zero-DB path. |

Tests: none (test-writer: skip — compose YAML + env example only). Gates: Verifier (production — compose YAML reviewed mechanically, no compose CLI in the VM) PASS; Tester PHPUnit+PHPStan PASS.

### Docker image CI: lint, build, runtime smoke, scan & GHCR publish (Checklist Step 4)

| File | Change |
|---|---|
| `.github/workflows/docker-image.yml` (new) | New workflow `name: Docker Image`, jobs `hadolint` and `docker-image` (`needs: hadolint`) — no collision with existing job names. Triggers: `pull_request` → `main`/`develop`, paths-scoped to `Dockerfile`, `docker/**`, `docker-compose*.yml`, `.dockerignore`, `prod.env.example` and the workflow file itself (lint + build + smoke + scan, no push); `push` → `develop`, unfiltered (the image bakes the application, so any merge can make the published tag stale); `workflow_dispatch`. Workflow-level `contents: read`; the `docker-image` job restates that and adds `packages: write` for its own push steps. `docker-image` steps: Buildx → `docker/build-push-action` (`target: prod`, `load: true`, `push: false`, GHA cache) → `docker compose -f docker-compose.prod.yml --env-file prod.env.example config --quiet` (validates by interpolating the example env directly rather than a copied `prod.env`) → runs the built image with SQLite + `PAGEKIT_AUTO_SETUP=1` + a random admin password, then polls `docker inspect` for the image's own `HEALTHCHECK` status (5-minute budget; fails fast on a container that exits or reports `unhealthy`) → a smoke script that collects every failure instead of stopping at the first: unproxied `/` must redirect (30x) while `/` and `/admin/login` sent with `X-Forwarded-Proto: https` must render (200 + expected markup); `/config.php`, `/composer.json`, `/app/console/app.php`, `/tmp/` must each answer 403/404 and never leak `<?php`/`"require"` content; `X-Frame-Options`/`Content-Security-Policy` must be present on a proxied response → container log dump (`if: always()`) → Trivy scan (`severity: CRITICAL,HIGH`, `ignore-unfixed: true`, `exit-code: 1`) → on `push`/`workflow_dispatch` only: GHCR login via `docker/login-action` with the run's own `GITHUB_TOKEN`, `docker/metadata-action` resolves `type=ref,event=branch` + `type=sha,format=short` tags, then the already-built-and-scanned image is retagged and pushed (no rebuild). `hadolint` job: `hadolint/hadolint-action` on `Dockerfile` at its default (info) threshold. All seven third-party actions pinned by commit SHA with a version comment. |

Tests: none (test-writer: skip — CI workflow YAML only). Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS.

### Docs: README production guide & AGENTS.md caveats (Checklist Step 5)

| File | Change |
|---|---|
| `README.md` | New `### Production (Docker)` subsection under Installation: image pull/build (`docker build --target prod`), the `prod.env.example` → `prod.env` copy step, `docker compose -f docker-compose.prod.yml --env-file prod.env up -d`, the SQLite `--no-deps web` path, and the `PAGEKIT_AUTO_SETUP`/`PAGEKIT_AUTO_MIGRATE` install note; a `PAGEKIT_*` env-var reference table (`DEBUG`/`SECRET`/`DB_*`/`TRUSTED_PROXIES`/`WEATHER_API_KEY`) plus prose for the entrypoint-only vars; a persistence subsection naming `pagekit_data`/`pagekit_storage`/`mysql_data` against what stays disposable (`tmp/`); a TLS/reverse-proxy subsection tying the `X-Forwarded-Proto` guard to `PAGEKIT_TRUSTED_PROXIES`; a `pagekit-prod` compose alias for day-2 operations; and a closing comparison of the two compose files (stage, mounts, ports, resource limits, project name). Elsewhere: the overview bullet now mentions the production image sharing the same `Dockerfile`, and the "Quick Start with Docker (Recommended)" heading drops "(Recommended)" for "(Development)" now that a production path exists too; the Development section's Apache bullet gains `mod_headers`/`mod_expires` (enabled on `base` since Checklist Step 2); the `docker compose up -d` comment and the Docker Environment Security bullets (env-file names, dev-only deploy warning) are reworded to cover both stacks instead of dev alone. |
| `AGENTS.md` | Services table gains a `Docker prod stack` row (`cp prod.env.example prod.env` then `docker compose -f docker-compose.prod.yml --env-file prod.env up -d`; local machines only, no daemon in the VM). Four new caveat bullets: the multi-target `Dockerfile`'s stage order, with `prod` as the default target and `--target dev` as the explicit exception; static-only Docker validation in the VM (hadolint/shellcheck) versus the real build+boot+smoke+Trivy+push proof in `docker-image.yml`; the `prod.env`-not-`.env.prod` naming rationale (the `*.env` gitignore match); and `PAGEKIT_*` vars outranking `config.php` through the last-registered `EnvConfigLoader` (unset = no-op, `getenv()` not `$_ENV`), plus the test-side `putenv()`/`setTrustedProxies()` cleanup note. |

Tests: none (test-writer: skip — docs only). Gates: Verifier (production) FAIL → PASS (AGENTS.md's GHCR publish-scope wording corrected to match Checklist Step 4's `push`/`workflow_dispatch` gating and its `develop`/dispatched-branch + `sha-<short>` tag set); Tester PHPUnit+PHPStan PASS.

### Review (Bugbot + Security) + E2E — fix-loop corrections (Checklist Step 6)

| File | Change |
|---|---|
| `app/installer/requirements.php` | New `configDirectory()`/`isAbsolutePath()` helpers (Bugbot round 1): the pre-install writability check now follows `config.php`'s symlink to the directory its target actually lives in — `$PAGEKIT_DATA_DIR` in the container (Checklist Step 2 decision 4) — instead of testing the link's own directory, which the non-root runtime's read-only application root (Checklist Step 2) made permanently unwritable; without this the requirements page would have failed every container-layout install by construction. |
| `app/console/src/Commands/SetupCommand.php` | New `--db-port`/`--db-path` options (Bugbot round 1): `PAGEKIT_DB_PORT`/`PAGEKIT_DB_PATH` now get written into `config.php` at setup time like every other connection parameter, instead of surviving only through the live `PAGEKIT_DB_*` environment — a container later started without those two variables previously lost its MySQL port / SQLite file location. |
| `docker/entrypoint.sh` | (Bugbot round 1) `install_pagekit()` passes the new `--db-port`/`--db-path` flags when set. (Bugbot round 2) new `link_config()` helper: a failed `config.php` symlink write now logs the cause (read-only application root, misconfigured `PAGEKIT_DATA_DIR`) and exits 1 instead of leaving a stale or missing link behind a bare "Permission denied"; `apache2-foreground` invocations gain `-D PAGEKIT_TRUSTED_PROXY` whenever `PAGEKIT_TRUSTED_PROXIES` is set, guarded to the `apache2` command only so a `migration:migrate`/shell override of `CMD` is unaffected; the `PAGEKIT_AUTO_MIGRATE` comment gains the multi-replica race note (see Risks & Rollout Notes). |
| `public/.htaccess` | (Bugbot round 2) the `X-Forwarded-Proto` guard on the HTTPS-forcing rule (Checklist Step 2) is now wrapped in `<IfDefine PAGEKIT_TRUSTED_PROXY>` — the header only bypasses the redirect on a server actually started with that Apache define, closing the gap where a request reaching the container's port directly could set the header itself and skip the redirect. |
| `docker-compose.prod.yml` | (Bugbot round 1) `deploy.resources.limits`'s comment clarifies Compose v2 applies the limits to a plain `up`, not only to Swarm as the retired v1 binary did. (Bugbot round 2) the top-of-file `PAGEKIT_TRUSTED_PROXIES` comment reworded around the two-part gating this step introduces (the env var *and* the Apache define it now drives). |
| `prod.env.example` | (Bugbot round 2) `PAGEKIT_TRUSTED_PROXIES`'s comment extended to name the Apache-side effect; `PAGEKIT_AUTO_MIGRATE`'s comment extended with the same multi-replica separate-job guidance as the entrypoint. |
| `README.md` | (Bugbot round 1) local Docker quickstart wording clarified — port 8080 access, and the auto-setup-vs-web-installer framing while no proxy is in front. (Bugbot round 2) the Apache config bullet gains the `Define PAGEKIT_TRUSTED_PROXY` requirement; "Install Pagekit" splits `PAGEKIT_AUTO_MIGRATE` into its own paragraph with the multi-replica separate-job command; the TLS/reverse-proxy subsection is rewritten around the same two-part gating as `docker-compose.prod.yml`. |
| `AGENTS.md` | (Bugbot round 2) new caveat: `PAGEKIT_TRUSTED_PROXIES` also configures Apache via `-D PAGEKIT_TRUSTED_PROXY`, not just PHP. (Security round 1) the Docker prod validation caveat rewritten for the three-job pipeline (`hadolint` → `docker-image` → `publish-image`) and the artifact-based publish. |
| `.github/workflows/docker-image.yml` | (Bugbot round 1) new "start without an automatic install" + "probe the web installer" steps boot a second container and assert `/installer` renders (200 + `id="installer"` markup, not the requirements-page failure) — the in-CI proof for the `requirements.php` fix above. (Bugbot round 2) the SQLite/auto-setup and no-auto-install containers both gain `PAGEKIT_TRUSTED_PROXIES`; a third container started with no declared proxy proves `/` still redirects to HTTPS even when sent `X-Forwarded-Proto: https` — the in-CI proof for the `.htaccess` `<IfDefine>` gate. (Security round 1) the `docker-image` job's inline `permissions:` block (which added `packages: write`) is removed, falling back to the workflow's `contents: read` default through build/smoke/scan; a new `publish-image` job (`needs: docker-image`, same `if: github.event_name != 'pull_request'` gate, `permissions: contents: read, packages: write`) downloads the image `docker-image` now saves and uploads as a build artifact, loads it, and runs the GHCR login/tag-resolve/push steps that used to live inline — the digest published is still the one the smoke tests and Trivy scan ran against, just relocated to the one job a pull request can never reach. |

Tests: none (test-writer: skip — this Checklist Step is the mandatory Review + E2E pass with no production refactor work of its own; every row above is a Bugbot/Security fix-loop correction to files Checklist Steps 1–5 already introduced). Gates: Bugbot — 2 fix-loops to clean (round 1: `requirements.php` writability via the `config.php` symlink target, `SetupCommand`/entrypoint DB port+path persistence, docs; round 2: the `.htaccess` proxy-redirect `<IfDefine>` gate, entrypoint symlink-failure diagnostics, `PAGEKIT_AUTO_MIGRATE` race note). Security — 1 fix-loop to clean (`packages: write` isolated to the new `publish-image` job; `docker-image` itself never carries registry-write). Tester — final E2E PASS (3 Playwright `@ci` specs, chromium-desktop, via `php pagekit start`).

---

## 🧠 Key Decisions (Rationale)

- **`composer-deps`'s autoloader dump uses `--optimize`, not an authoritative classmap (Checklist Step 2).** Extensions and themes register their own PSR-4 namespaces with the class loader while the application boots; a classmap that answers authoritatively for every class would resolve those namespaces before PSR-4 is ever consulted and break their autoloading. `--optimize` still collapses the PSR-4 lookup into a classmap for everything already known at build time, without claiming to be the last word on every class.
- **`public/.htaccess`'s `X-Forwarded-Proto` guard is scoped to the HTTPS-forcing rule only (Checklist Step 2).** The `www.`-host redirect above it fires on a hostname mismatch, which a proxy's declared scheme can't repeatedly trigger, so it can't loop behind a TLS-terminating proxy the way the scheme-only `RewriteCond %{HTTPS} off` rule can; the guard went only on the rule that actually needs it.
- **`docker/php/php-prod.ini`'s opcache comment corrected after a Verifier FAIL (Checklist Step 2).** `opcache.validate_timestamps=0` requires every cached path to actually stay unwritten after the build; the Verifier's first pass on this Checklist Step rejected the comment justifying that setting, and the corrected version scopes the guarantee to the two paths that actually hold it — `config.php` (self-invalidated by whichever module writes it) and the root-owned `packages/` registry (unwritable by the request-time `www-data` user) — instead of claiming it for the tree as a whole.
- **`PAGEKIT_DB_NAME`/`USER`/`PASSWORD` derive from `MYSQL_DATABASE`/`MYSQL_USER`/`MYSQL_PASSWORD` instead of a second, duplicate set of variables (Checklist Step 3).** The bundled `mysql` service creates its database/user/grants from the `MYSQL_*` vars on first boot; wiring `docker-compose.prod.yml`'s `web.environment` to those same vars means the application always opens the connection the database container actually created — an operator who fills in only the `MYSQL_*` block cannot get the two sides out of sync.
- **MySQL healthcheck pings over TCP, not the local socket (Checklist Step 3).** First-boot initialization runs a temporary socket-only `mysqld` while it builds the data directory; a socket-based ping would report `healthy` while the port `web`'s `depends_on: condition: service_healthy` waits on is still closed. `mysqladmin ping -h 127.0.0.1` only succeeds once the real, TCP-listening server is up.
- **The prod stack is a separately named Compose project (Checklist Step 3).** `docker-compose.prod.yml` sets `name: pagekit-prod`; without it, Compose would default to the working-copy directory name — the same default `docker-compose.yml` (dev) uses — so starting one stack in a checkout that already ran the other could reuse, and clobber, its containers/volumes.
- **Runtime smoke proves both halves of the Step 2 proxy guard in one run (Checklist Step 4).** `docker-image.yml` probes `/` twice — unproxied, where it must redirect to HTTPS, and with `X-Forwarded-Proto: https`, where it must render 200 — so the workflow demonstrates the `public/.htaccess` rule enforces HTTPS for a direct request and steps aside for one a trusted proxy already terminated, rather than asserting only an aggregate "200 or 30x".
- **`public/.htaccess`'s proxy-trust guard is gated behind an Apache `<IfDefine>`, not the header alone (Checklist Step 6).** `X-Forwarded-Proto` is a request header any client can send; scoping trust to `PAGEKIT_TRUSTED_PROXIES` (Checklist Step 1) constrains what PHP believes but does nothing to Apache's own `.htaccess` evaluation, which runs before PHP ever sees the request. `docker/entrypoint.sh` now mirrors the same variable into `-D PAGEKIT_TRUSTED_PROXY` on the `apache2-foreground` invocation, and the redirect rule only honours the header inside `<IfDefine PAGEKIT_TRUSTED_PROXY>` — a container started without the variable keeps redirecting every plain request regardless of what headers arrive with it.
- **`config.php`'s writability check resolves the symlink to its target, not the link's own directory (Checklist Step 6).** Decision 4 (Checklist Step 2) makes `config.php` a dangling symlink onto `$PAGEKIT_DATA_DIR`, deliberately outside the read-only application root; the pre-existing requirements check tested the link's own directory, which the container's non-root runtime makes unwritable by design. The new `configDirectory()` helper follows the link first, so the check exercises the directory that actually has to accept the file.
- **GHCR publish moves to its own job instead of a permission block inside `docker-image` (Checklist Step 6 — Security).** `packages: write` on the whole `docker-image` job meant every PR-triggered build/smoke/scan run — the one path that executes untrusted branch content — held registry-write it could never use, since the push steps were already skipped there. The new `publish-image` job (`needs: docker-image`, same `if` gate) carries the permission alone, and reloads the already-built-and-scanned image from a build artifact instead of rebuilding it, so the published digest is still the one the smoke tests and Trivy ran against.

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

---

## ⚠️ Risks & Rollout Notes

- **Weather widget default changes from a shared key to empty (Checklist Step 1).** `system/dashboard`'s `weather.key` no longer ships a working default; the dashboard's location widget shows its "unavailable" state on upgrade until an operator sets `PAGEKIT_WEATHER_API_KEY` or `config.php`'s `system/dashboard.weather.key`. Provider-side rotation of the old key is tracked under Maintainer action (Finalize).
- **`base` now enables `mod_headers`/`mod_expires`, which also reaches the `dev` target (Checklist Step 2).** `a2enmod rewrite headers expires` moved into the shared `base` stage, so a rebuilt dev container starts enforcing `public/.htaccess`'s security headers and cache-expiry rules for the first time — both had silently no-op'd for want of the modules. A running dev container only picks this up on its next image rebuild.
- **`web`'s resource limit is a hard ceiling, not a throttle (Checklist Step 3).** `docker-compose.prod.yml`'s `deploy.resources.limits` caps the container at `cpus: "2.0"` / `memory: 1G`, sized for a handful of concurrent requests at the image's 256M per-process PHP limit; reaching the memory ceiling under real traffic is an OOM kill, not a slowdown, so the limit needs raising before traffic does.
- **The Trivy gate can turn a `develop` push red on an unrelated CVE (Checklist Step 4).** `docker-image.yml`'s scan step has no allow-list, so a newly-disclosed CRITICAL/HIGH in a base-image package blocks that push's GHCR publish — and the required `docker-image` check — until a rebuild picks up the fix, regardless of whether the triggering commit touched anything Docker-related.
- **`PAGEKIT_AUTO_MIGRATE` is safe for one replica, documented rather than enforced (Checklist Step 6).** The entrypoint comment and `prod.env.example` both now say so, but nothing stops a multi-replica start from running `migration:migrate` from every container against the same database at once; a stack that scales `web` beyond one instance must run the migration as its own step (`docker compose … run --rm web php pagekit migration:migrate`) before the new build comes up. Orchestration (Step 4.11) is where replica-aware startup would actually enforce this.

---

## 🔐 Security & Data Impact

- **Committed OpenWeatherMap API key removed (Checklist Step 1).** `app/system/modules/dashboard/index.php`'s hardcoded key and its `AUDIT FIX Step 2.5` marker are gone; the module now defaults `weather.key` to `''` and takes it instead from `PAGEKIT_WEATHER_API_KEY` (via `EnvConfigLoader`) or `config.php`. The key remains in prior git history — provider-side rotation is Maintainer action.
- **Signing secret and DB credentials become environment-settable (Checklist Step 1).** `EnvConfigLoader` lets `PAGEKIT_SECRET` and the MySQL/SQLite connection parameters come from the process environment instead of a writable `config.php` — the basis the immutable-image config model (later Checklist Steps) builds on.
- **Production runtime is non-root, with the application tree read-only (Checklist Step 2).** The `prod` stage's final `USER www-data` runs Apache on the unprivileged `8080`; only `tmp/`, `storage/`, and `$PAGEKIT_DATA_DIR` are `chown`'d to `www-data` — `app/`, `packages/`, and `public/` stay root-owned, so a compromised request can't rewrite the code serving it.
- **Error detail and PHP fingerprinting are off by default (Checklist Step 2).** `docker/php/php-prod.ini` sets `display_errors`/`display_startup_errors` and `expose_php` off on top of `php.ini-production`; errors still reach the container's own log stream via `log_errors`/`error_log`, never the response.
- **Auto-setup credentials come only from the process environment (Checklist Step 2).** `docker/entrypoint.sh`'s `PAGEKIT_AUTO_SETUP` path reads `PAGEKIT_ADMIN_PASSWORD` and the other setup flags from the environment when it calls `php pagekit setup`; none of it is baked into an image layer.
- **The HTTPS-forcing redirect now resolves behind a TLS-terminating proxy (Checklist Step 2).** The `X-Forwarded-Proto` guard (see Key Decisions) stops that rule from redirecting a request the proxy already delivered over HTTPS, pairing with `PAGEKIT_TRUSTED_PROXIES` (Checklist Step 1) for `isSecure()`/absolute-URL generation.
- **The database is reachable only on the compose network (Checklist Step 3).** `docker-compose.prod.yml`'s `mysql` service publishes no host port; only `web`, on the same Compose network, can open a connection — the credentials in `prod.env` never have to survive exposure to the host's network interfaces, let alone the open internet.
- **A CVE gate and a scoped, ephemeral token stand between the built image and GHCR (Checklist Step 4).** `docker-image.yml` Trivy-scans the image (`CRITICAL,HIGH`, `ignore-unfixed`, non-zero exit on a hit) before any push step runs; the push itself authenticates with the workflow run's own `GITHUB_TOKEN` under a `packages: write` scope added only on the `docker-image` job (the workflow default stays `contents: read`), and is skipped entirely on `pull_request` events.
- **The forwarded-protocol bypass on the HTTPS redirect now requires the server to have declared a proxy, not just the header (Checklist Step 6).** Closes a self-declared-header gap in the Checklist Step 2 guard: previously a request reaching the container's port directly could send `X-Forwarded-Proto: https` and skip the redirect itself. `public/.htaccess` now honours that header only inside `<IfDefine PAGEKIT_TRUSTED_PROXY>`, which `docker/entrypoint.sh` defines only when `PAGEKIT_TRUSTED_PROXIES` (Checklist Step 1) is actually set — proven in CI by a third container started with no proxy declared, asserted to still redirect when sent the header.
- **`packages: write` no longer reaches a job that runs on pull requests (Checklist Step 6 — supersedes the Checklist Step 4 scoping).** The permission sat on the whole `docker-image` job, which is where every PR's build/boot/smoke/Trivy steps also run; it now lives only on the new `publish-image` job, downstream of a green scan and gated the same way (`github.event_name != 'pull_request'`).

---

## 🛡️ No-Mercy Compliance

- **Rule 4 (Delete over wrap) — Checklist Step 1:** the hardcoded OpenWeatherMap key is deleted outright, not gated behind an env check with the old value kept as fallback; the empty-string default is what the module's own `config('weather.key', '')` call already handles.
- **Rule 5 (audit debt closed) — Checklist Step 1:** the `AUDIT FIX Step 2.5` marker in `app/system/modules/dashboard/index.php` is removed now that the work it flagged is done.
- **Rule 4 (Delete over wrap) — Checklist Step 2:** the prior single-target `Dockerfile` content is absorbed into the `base`/`dev` stages rather than kept beside a new, separate "prod" file — one `Dockerfile`, multi-stage, with `prod` as the default (last) target.
- **Rule 4 (Delete over wrap) — Checklist Step 6:** the GHCR login/tag-resolve/push steps are removed from `docker-image` outright and re-created in the new `publish-image` job — no parallel push path, feature flag, or duplicate permission block bridges the two.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: _TBD / None_

---

## 📋 Phase 1 Audit Closure

_TBD / None_

---

## 👤 Maintainer action

<!-- Human-only follow-ups the maintainer must do (ruleset flips, real Docker/Apache
     verification, secrets, etc.). Not ROADMAP deferrals — those go under Deferred. -->

_TBD / None_

---

## 📚 Deferred / Out-of-Scope

<!-- Future ROADMAP/PHASE work, explicit non-goals, bridges. Do NOT put maintainer
     Manual Work here — that belongs under Maintainer action above. -->

_TBD / None_

---

## 📌 Follow-on (ROADMAP)

_TBD / None_

---

## 🧊 Parked (unplanned)

_TBD / None_

---

## 🧹 Cleanup

_TBD / None_

---

## 🛡️ Audit

_TBD / None_

---

## 🎁 Bonus

_TBD / None_

---

## 🔍 Research

_TBD / None_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_5_Docker-Production-Image_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_5_Docker-Production-Image.md`
- Predecessor: Step 2.4.1 — Webroot Modernization (public/)
- Successor: Step 2.6 — Filesystem Write Resilience

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
