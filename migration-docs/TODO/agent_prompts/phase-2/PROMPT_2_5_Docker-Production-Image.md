# Step 2.5: Docker Production Image & Deploy

<!-- conductor-mode: full -->

**ROADMAP:** 2.5. GitHub Issue: #158. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.5.

---

## CONTEXT

- **Land after:** 2.4.1 (`public/` webroot — this step bakes that already-established layout into a hardened image) and 2.2 (CI foundation for image build/scan/push). Step 2.3 cleaned the **dev** Docker baseline (Apache stays for compose; no production image).
- **Risk:** Medium — multi-stage image + first 12-factor env path; application touch is limited (OpenWeatherMap key). The webroot itself is no longer a per-step risk here: `public/` (from Step 2.4.1) already makes `app/`, `config.php`, `tmp/` structurally unreachable — this step's job is baking that already-established layout into a hardened image, not deciding it. The webserver **engine** choice (Apache vs. nginx+FPM vs. FrankenPHP) is also not decided here — see Step 4.12.
- **Goal:** A small, hardened, immutable **production** image + dedicated prod compose + image build/scan/push in CI (Hadolint, Trivy, GHCR).
- **Why:** Dev compose bind-mounts the tree and is not deployable. Production needs a baked, non-root, secrets-free image with a verified webserver surface (headers, file protection, front controller) and env-driven config.

### Current state (verified 2026-07-26 — confirm in Discovery, then build; do not rediscover blindly)

- **Root `Dockerfile`** (`php:8.5-apache`): single-stage **dev** image after Step 2.3 — extensions only (`pdo_mysql`, `gd`, `zip`; bundled `pdo`/`pdo_sqlite`/`mbstring`/XML stay implicit), Composer binary copied in, Apache `mod_rewrite` + VirtualHost with `AllowOverride All`, **no application code baked** (compose bind-mounts `.:/var/www/html`). Production baking returns in this step.
- **`.cursor/Dockerfile`**: cloud-agent CLI image — out of scope except where docs claim otherwise; do not turn it into the production runtime.
- **`.dockerignore`**: excludes `node_modules`, `app/vendor/`, `tmp/`, `storage/`, `config.php`, `*.db`, `.env`, tests/reports, `migration-docs/`, `.github/`, `.cursor/`, `*.md`, **and** `Dockerfile`, `docker-compose*.yml`, `docker/`. For a multi-stage prod build that `COPY`s configs from `docker/`, either relocate prod configs into the kept context, use a dedicated ignore file / target, or surgically stop excluding the paths the build needs — Discovery must resolve this before the first green image build.
- **Dev compose** (`docker-compose.yml`): `web` + `mysql` (healthcheck) + `phpmyadmin` + `node` (Yarn watcher until 2.4 lands; after 2.4: pnpm). Credentials from generated gitignored `.env` via `docker-setup.sh` / `.ps1`. No `docker-compose.prod.yml` yet.
- **Dev `docker/php/php.ini`**: `display_errors=On`, opcache with `revalidate_freq=2` — **not** production settings. Prod needs a separate ini (`display_errors=Off`, `opcache.validate_timestamps=0`).
- **Webroot contract (post-2.4.1 — re-verify against its actual outcome in Discovery):** DocumentRoot is `public/`, not the repository root. `public/index.php` is the sole front controller; `public/.htaccess` carries the front-controller rewrite + security headers (HSTS, X-Frame-Options, Permissions-Policy, COOP/CORP — CSP moves to a PHP `ResponseListener` only once Step 3.2.1 lands, otherwise it is still here too); a minimal root `.htaccess` exists only as the shared-hosting fallback (`RewriteRule ^(.*)$ public/$1`) and is irrelevant inside the container, which points its webserver straight at `public/`. `app/`, `config.php`, `tmp/`, and non-public `storage/` paths are **structurally** absent from `public/` — verify this holds inside the image too, not just on the host.
- **Config & secrets:** `config.php` is the install-time default (gitignored). **No env-override layer exists yet** — no Symfony secrets vault wanted. First consumer: hardcoded OpenWeatherMap key in `app/system/modules/dashboard/index.php` (`weather.key`, tagged `// TODO: AUDIT FIX Step 2.5`). `DashboardController` reads `$this->dashboard->config('weather.key', '')`.
- **Composer:** `"vendor-dir": "app/vendor"` — every Composer stage must honour that; never assume root `vendor/`.
- **CI:** Step 2.2 shipped quality gates; **no** Hadolint / Trivy / GHCR image workflow yet. Required-check job names from 2.2 must not be renamed if touched only for docs.
- **Writable runtime dirs:** `storage/` (uploads) and `tmp/` (logs, cache, temp, packages) must be writable by the runtime user; Monolog needs `tmp/logs`.
- **Migrations:** run as init/startup (or explicit entrypoint step), **not** during image build.

---

## PRINCIPLES (hold across every checklist step)

- **Production image is immutable** — bake code + built assets; no bind-mount of the working tree; no secrets in layers.
- **Delete over wrap** — no dual "dev Dockerfile pretending to be prod"; separate artefacts (`Dockerfile` prod target / `Dockerfile.prod`, `docker-compose.prod.yml`, prod `php.ini`). Dev compose from 2.3 stays the developer path.
- **Webroot safety is non-negotiable** — prove `app/`, `config.php`, `tmp/` return 403/404 (or connection-refused) over HTTP before merge; `public/` (from 2.4.1) already makes this structural, but verify it holds inside the container image too.
- **No webserver spike here** — this step ships Apache; the Apache vs. nginx+PHP-FPM vs. FrankenPHP choice belongs to Step 4.12.
- **12-factor, lightweight** — env overrides `config.php`; no Symfony secrets component. Never bake secrets into the image.
- **Agent limitation** — cloud-agent VM has **no Docker daemon**: agents validate statically (Hadolint, compose config, Dockerfile review); runtime image boot / HTTP denial proofs are Manual Work unless a Docker host is available. Never fake a runtime result.
- **Rule 3** — tree stays green after every checklist step; required CI job names stay stable.
- **First secret consumer closes the audit tag** — move OpenWeatherMap key to env, rotate the committed key, remove the `AUDIT FIX Step 2.5` TODO (completed work does not keep step tags in code).

---

## 0. SAFETY CHECKS

Before starting:

1. Branch up-to-date with `develop`.
2. Confirm **2.4.1 has landed** (or this branch includes the `public/` webroot) — the production image builds on that layout, not the pre-2.4.1 root docroot. If 2.4.1 is not merged, STOP and sequence correctly.
3. Baseline green:

```bash
php -v && node -v
composer install --no-interaction --prefer-dist --no-progress
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
pnpm install --frozen-lockfile && pnpm build
```

**IF ANY FAILS → STOP AND FIX** (or report the pre-existing failure) before touching production Docker artefacts.

---

## 1. DISCOVERY

```bash
rg -n 'weather\.key|AUDIT FIX Step 2\.5|openweathermap' app/system/modules/dashboard/
rg -n 'config\.php|getenv|\$_ENV|Env::' app/ public/index.php --glob '!app/vendor/**'
rg -n 'docker-php-ext-install|USER |HEALTHCHECK|FROM ' Dockerfile .cursor/Dockerfile
rg -n 'AllowOverride|DocumentRoot|RewriteRule|FilesMatch|Content-Security-Policy' .htaccess public/.htaccess
rg -n 'hadolint|trivy|ghcr|docker/build-push' .github/workflows/
ls docker/ docker/php/ public/ .dockerignore
```

- Confirm the exact `public/` layout Step 2.4.1 landed (front controller path, `.htaccess` split, `storage/` symlink) — do not assume this file's description is still exact; re-verify.
- Map every `public/.htaccess` concern the image must reproduce (file denials if any remain, headers, rewrites, mime/deflate/expires — decide which are mandatory vs. nice-to-have for v1).
- Resolve **`.dockerignore` vs. prod config COPY** (see Current state).
- Confirm how `config.php` is loaded at boot and the cleanest env-override injection point (small helper / early bootstrap — no vault).
- Confirm GHCR package permissions for `GITHUB_TOKEN` / `packages: write` on this repo.

---

## 2. WORK

Architect: decompose into ordered, individually-green checklist steps. Suggested order: 2.1 (env/secrets path + OpenWeatherMap) → 2.2 (multi-stage Dockerfile + prod php.ini + non-root, on `public/` + Apache) → 2.3 (prod compose + HEALTHCHECK) → 2.4 (CI Hadolint/Trivy/GHCR) → 2.5 (docs). Optional Redis may land with 2.3 or as its own small step if it stays truly optional.

### 2.1 Config & secrets (12-factor) + first consumer

- Env overrides `config.php` for DB credentials and secrets — lightweight; no Symfony secrets-vault.
- Move OpenWeatherMap API key off the hardcoded default in `app/system/modules/dashboard/index.php` onto that env path; rotate the committed key (treat the old key as compromised).
- Remove the `AUDIT FIX Step 2.5` TODO once done (forward-debt tags only for remaining work).
- Document required env vars in a **prod** example file (not the dev-only `.env.example` / `.env` from 2.3).

### 2.2 Multi-stage production image (Apache serving `public/`)

- Stages: Composer (`--no-dev --optimize-autoloader --classmap-authoritative`, honour `app/vendor`); Vite/pnpm asset build (from 2.4, landing in `public/` per 2.4.1); minimal runtime stage with **only** built artefacts + runtime deps.
- Hardening: non-root user; prod `php.ini` (`display_errors=Off`, `opcache.validate_timestamps=0`); no secrets in layers; writable `storage/` + `tmp/` owned correctly.
- Point Apache's DocumentRoot at `public/` inside the image; verify `app/`, `config.php`, `tmp/`, and anything outside `public/` are never served — this should already hold structurally, confirm it survives the container's Apache config too.
- Layer caching tuned for fast rebuilds (deps before app copy where possible).
- Keep the Step 2.3 **dev** `Dockerfile` / compose path working — either a multi-target Dockerfile (`dev` vs `prod`) or a dedicated prod file; delete-over-wrap means no "almost prod" half-measure.

### 2.3 `docker-compose.prod.yml` + HEALTHCHECK

- Restart policy + resource limits.
- Container `HEALTHCHECK` (HTTP or TCP against the front controller / web port).
- Wire DB (and optional Redis for cache/session) via env; no hardcoded credentials.
- Migrations / first-boot: init container or entrypoint — not image build.
- Optional Redis: include only if the wiring is thin and useful; otherwise document as follow-up and skip (do not half-wire).

### 2.4 CI — Hadolint + Trivy + build & push GHCR

- Lint Dockerfile(s) with Hadolint; scan image with Trivy; build & push to GHCR on the agreed trigger (merge to `develop` / tag / workflow_dispatch — Architect chooses; record it).
- Pin third-party actions by commit SHA (repo convention).
- Do **not** rename existing required-check job names from Step 2.2.

### 2.5 Docs

- `README.md` / `AGENTS.md` / Docker docs: prod build, compose up, required env vars, GHCR pull, difference vs. dev compose.
- Note cloud-agent has no Docker daemon; runtime validation is Manual Work on a Docker host.

---

## MANUAL WORK (record in the branch doc — agents do NOT perform these)

1. **Runtime validation on a Docker host:** build the prod image, `docker compose -f docker-compose.prod.yml up`, complete install or boot against existing DB, hit frontend + admin.
2. **Webroot denial proof:** HTTP requests to `/app/`, `/config.php`, `/tmp/` (and any other sensitive path found in Discovery) must not serve file contents from inside the running container.
3. **Header/rewrite parity check** against the `public/.htaccess` rule set inherited from Step 2.4.1.
4. **Rotate OpenWeatherMap key** in the provider dashboard (code rotation alone is insufficient if the old key was ever public).
5. **GHCR:** confirm package visibility and that CI push credentials work on the first protected-branch run if the agent could not push images.

---

## 3. OUT OF SCOPE

- **Kubernetes / Helm, liveness/readiness probes, HPA, Ingress, PVCs, multi-replica** → **Step 4.11** (needs health endpoints from Step 4.6 and a shared-state decision for `storage/` / `tmp/`).
- **Dev container hygiene** (extension set, `.dockerignore` baseline, dev compose healthcheck) → **Step 2.3** (#241) — already landed; only touch if the prod build forces a surgical `.dockerignore` / multi-target change.
- **pnpm + Vite pipeline itself** → **Step 2.4** (#159). **`public/` webroot layout itself** → **Step 2.4.1** — already landed by the time this step starts; do not redesign it here.
- **App health HTTP endpoints for orchestrators** → Step 4.6.
- **Webserver/runtime engine modernization** (FrankenPHP or nginx + PHP-FPM) → **Step 4.12** (needs 4.11's orchestration groundwork and the final identity from 4.7). This step ships Apache.

---

## 4. TESTING

- **Per checklist step (Conductor Tester):** PHPUnit + PHPStan PASS/FAIL. The OpenWeatherMap / env-override step **does** change production PHP — cover with unit/integration tests where meaningful (`test-writer` applies); mark pure Docker/CI/docs steps `test-writer: skip` in `## TESTING STRATEGY`.
- **Static Docker validation in-agent:** Hadolint; `docker compose -f docker-compose.prod.yml config` if CLI present; Dockerfile review. Record what ran vs. Manual Work.
- **Frontend assets:** prod image build must invoke the 2.4 `pnpm build` (or copy pre-built artefacts from that stage) so admin/UI assets exist in `public/` inside the runtime layer.
- **Runtime `docker compose` / image boot is Manual Work** when no Docker daemon is available.

---

## SUCCESS CRITERIA

- Multi-stage production image builds without dev dependencies or build toolchain in the runtime layer; Composer uses `app/vendor` + authoritative classmap.
- Apache serves `public/` (from Step 2.4.1) inside the image, matching the dev baseline; `public/.htaccess` security/rewrite behaviour is in effect; `app/`, `config.php`, `tmp/`, and anything outside `public/` are not HTTP-reachable — structurally, not just by pattern-matching.
- Non-root user; prod `php.ini` with `display_errors=Off` and `opcache.validate_timestamps=0`.
- `docker-compose.prod.yml` starts the stack with restart policy + resource limits; `HEALTHCHECK` reports healthy on a Docker host (Manual Work).
- Config/secrets from env; no secrets baked into the image; OpenWeatherMap key rotated and gone from the repository; `AUDIT FIX Step 2.5` TODO removed.
- CI: Hadolint + Trivy + build & push to GHCR; actions SHA-pinned; required-check names from 2.2 intact.
- README / AGENTS.md (and Docker docs if kept) document prod build/run/env; Manual Work list complete in the branch doc.
- PHPUnit + PHPStan green; E2E smoke still green on the non-Docker path (Playwright + `php pagekit start`).

---

## NOTES FOR THE ARCHITECT

- **No webserver spike in this ticket** — that decision belongs to Step 4.12. This step ships Apache serving `public/`; do not re-litigate the engine choice here.
- **`.dockerignore` currently excludes `docker/`** — fix this deliberately for prod configs; do not silently `COPY` paths that never enter the build context.
- Env override design should stay thin (DNA): one glue point, `config.php` remains the default for classic installs, env wins in containers — no second configuration framework.
- Optional Redis: only if cache/session wiring is already clear; otherwise skip and note under Deferred for a later step — do not invent a Redis integration layer here.
- One ticket / one PR; Conventional Commits; version bump once at Finalize — never inside checklist steps.
