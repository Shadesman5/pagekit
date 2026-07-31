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

---

## 🧠 Key Decisions (Rationale)

- **`composer-deps`'s autoloader dump uses `--optimize`, not an authoritative classmap (Checklist Step 2).** Extensions and themes register their own PSR-4 namespaces with the class loader while the application boots; a classmap that answers authoritatively for every class would resolve those namespaces before PSR-4 is ever consulted and break their autoloading. `--optimize` still collapses the PSR-4 lookup into a classmap for everything already known at build time, without claiming to be the last word on every class.
- **`public/.htaccess`'s `X-Forwarded-Proto` guard is scoped to the HTTPS-forcing rule only (Checklist Step 2).** The `www.`-host redirect above it fires on a hostname mismatch, which a proxy's declared scheme can't repeatedly trigger, so it can't loop behind a TLS-terminating proxy the way the scheme-only `RewriteCond %{HTTPS} off` rule can; the guard went only on the rule that actually needs it.
- **`docker/php/php-prod.ini`'s opcache comment corrected after a Verifier FAIL (Checklist Step 2).** `opcache.validate_timestamps=0` requires every cached path to actually stay unwritten after the build; the Verifier's first pass on this Checklist Step rejected the comment justifying that setting, and the corrected version scopes the guarantee to the two paths that actually hold it — `config.php` (self-invalidated by whichever module writes it) and the root-owned `packages/` registry (unwritable by the request-time `www-data` user) — instead of claiming it for the tree as a whole.

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

---

## ⚠️ Risks & Rollout Notes

- **Weather widget default changes from a shared key to empty (Checklist Step 1).** `system/dashboard`'s `weather.key` no longer ships a working default; the dashboard's location widget shows its "unavailable" state on upgrade until an operator sets `PAGEKIT_WEATHER_API_KEY` or `config.php`'s `system/dashboard.weather.key`. Provider-side rotation of the old key is tracked under Maintainer action (Finalize).
- **`base` now enables `mod_headers`/`mod_expires`, which also reaches the `dev` target (Checklist Step 2).** `a2enmod rewrite headers expires` moved into the shared `base` stage, so a rebuilt dev container starts enforcing `public/.htaccess`'s security headers and cache-expiry rules for the first time — both had silently no-op'd for want of the modules. A running dev container only picks this up on its next image rebuild.

---

## 🔐 Security & Data Impact

- **Committed OpenWeatherMap API key removed (Checklist Step 1).** `app/system/modules/dashboard/index.php`'s hardcoded key and its `AUDIT FIX Step 2.5` marker are gone; the module now defaults `weather.key` to `''` and takes it instead from `PAGEKIT_WEATHER_API_KEY` (via `EnvConfigLoader`) or `config.php`. The key remains in prior git history — provider-side rotation is Maintainer action.
- **Signing secret and DB credentials become environment-settable (Checklist Step 1).** `EnvConfigLoader` lets `PAGEKIT_SECRET` and the MySQL/SQLite connection parameters come from the process environment instead of a writable `config.php` — the basis the immutable-image config model (later Checklist Steps) builds on.
- **Production runtime is non-root, with the application tree read-only (Checklist Step 2).** The `prod` stage's final `USER www-data` runs Apache on the unprivileged `8080`; only `tmp/`, `storage/`, and `$PAGEKIT_DATA_DIR` are `chown`'d to `www-data` — `app/`, `packages/`, and `public/` stay root-owned, so a compromised request can't rewrite the code serving it.
- **Error detail and PHP fingerprinting are off by default (Checklist Step 2).** `docker/php/php-prod.ini` sets `display_errors`/`display_startup_errors` and `expose_php` off on top of `php.ini-production`; errors still reach the container's own log stream via `log_errors`/`error_log`, never the response.
- **Auto-setup credentials come only from the process environment (Checklist Step 2).** `docker/entrypoint.sh`'s `PAGEKIT_AUTO_SETUP` path reads `PAGEKIT_ADMIN_PASSWORD` and the other setup flags from the environment when it calls `php pagekit setup`; none of it is baked into an image layer.
- **The HTTPS-forcing redirect now resolves behind a TLS-terminating proxy (Checklist Step 2).** The `X-Forwarded-Proto` guard (see Key Decisions) stops that rule from redirecting a request the proxy already delivered over HTTPS, pairing with `PAGEKIT_TRUSTED_PROXIES` (Checklist Step 1) for `isSecure()`/absolute-URL generation.

---

## 🛡️ No-Mercy Compliance

- **Rule 4 (Delete over wrap) — Checklist Step 1:** the hardcoded OpenWeatherMap key is deleted outright, not gated behind an env check with the old value kept as fallback; the empty-string default is what the module's own `config('weather.key', '')` call already handles.
- **Rule 5 (audit debt closed) — Checklist Step 1:** the `AUDIT FIX Step 2.5` marker in `app/system/modules/dashboard/index.php` is removed now that the work it flagged is done.
- **Rule 4 (Delete over wrap) — Checklist Step 2:** the prior single-target `Dockerfile` content is absorbed into the `base`/`dev` stages rather than kept beside a new, separate "prod" file — one `Dockerfile`, multi-stage, with `prod` as the default (last) target.

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
