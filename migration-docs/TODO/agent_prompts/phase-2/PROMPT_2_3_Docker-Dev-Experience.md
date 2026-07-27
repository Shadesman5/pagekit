# Step 2.3: Docker Developer Experience & Image Hygiene

<!-- conductor-mode: full -->

**ROADMAP:** 2.3. GitHub Issue: #241. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.3.

---

## CONTEXT

- **Land after:** 2.2 (CI/CD Pipeline) — the CI workflows are stable and do **not** use Docker. Must land before 2.4 (build tools touch the Docker docs) and 2.5 (the production image builds on a clean dev baseline).
- **Risk:** Low — config + docs only; no application runtime code changes.
- **Goal:** A clean, reproducible **dev** container workflow; fix the drift in the existing Docker artefacts. **No production image** — that is Step 2.5.
- **Why:** The Docker setup predates the PHP 8.5 upgrade and has drifted: two Dockerfiles with different extension sets, a dev compose whose MySQL default path is broken in at least three independent ways (auth flag, profiles, env wiring), a 16-line `.dockerignore` that still copies docs and tests into the build context, and a DB init script carrying a password the image environment already provides.

### Current state (verified 2026-07-24 — confirm in Discovery, then build; do not rediscover blindly)

- **Root `Dockerfile`** (`php:8.5-apache`): installs `xml` / `dom` / `xmlwriter` / `simplexml` via `docker-php-ext-install` — all built into PHP 8.5 base images — plus `exif` / `pcntl` / `bcmath`, for which **no usage was found**: no `exif_*` / `bc*` / `pcntl_*` calls outside `app/vendor/`, and `composer.json` requires none of them (its `ext-*` list: dom, json, libxml, mbstring, xml, xmlwriter, zip, simplexml, pdo). The apt list carries dead `libmcrypt-dev` (mcrypt left PHP in 7.2) and `libxml2-dev` (only needed to compile the XML extensions being dropped).
- **Build steps shadowed by the dev bind mount:** the image runs `COPY . /var/www/html` + `composer install --no-dev`, but `docker-compose.yml` bind-mounts `.:/var/www/html` over it — the baked-in code and vendor dir are invisible in the documented dev flow (`docker-compose exec web composer install` per README).
- **`.cursor/Dockerfile`** (`php:8.5-cli`, cloud-agent image): already dropped the four XML extensions but still installs `exif` / `pcntl` / `bcmath`; adds PCOV. The two Dockerfiles disagree on the extension set.
- **`.dockerignore`**: 16 lines; missing `migration-docs/`, `tests/`, `docs-site/`, `.github/`, `.cursor/`, `*.md`, coverage/report artefacts.
- **`docker-compose.yml`**:
  - bare `depends_on: [mysql]` — no healthcheck, `web` can start before MySQL accepts connections;
  - `command: --default-authentication-plugin=mysql_native_password` — this server option was **removed in MySQL 8.4**; with `image: mysql:8.4` the server refuses to start on a cold volume (PHP 8.5 mysqlnd speaks `caching_sha2_password` natively, the flag is obsolete);
  - obsolete top-level `version: '3.8'` key (Compose v2 warns);
  - **profile wiring broken:** `mysql` + `phpmyadmin` carry `profiles: [mysql, default]`, but Compose never auto-activates a profile named `default` — a bare `docker compose up` does not enable them while `web` still `depends_on` the disabled `mysql` (verify the exact failure mode); README additionally documents a `--profile sqlite` that no compose file defines;
  - **env wiring broken:** the `environment:` mappings (`MYSQL_DATABASE: ${DB_NAME}`, `MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}`, …) interpolate from the **shell / `.env` file**, not from `env_file: docker.env` — on a clean shell they resolve to empty strings and override the correct env_file values (`environment:` beats `env_file`).
- **`docker-compose.e2e.yml`**: same bare `depends_on`; hardcoded test credentials; mounts `./tests/e2e/fixtures`, which **does not exist** in the repo; `node:20-alpine` vs the main compose's `node:22-alpine`; driven only by the legacy `scripts/e2e-start.sh` / `e2e-stop.sh` / `e2e-reset.sh` (which call the v1 `docker-compose` binary). **CI E2E does not use Docker** — `playwright.config.js` `webServer` is `php pagekit start`.
- **`docker/mysql/init/01-create-database.sql`**: hardcoded `IDENTIFIED BY 'pagekit'`; duplicates exactly what the mysql image already does from `MYSQL_DATABASE` / `MYSQL_USER` / `MYSQL_PASSWORD` env — delete candidate.
- **`docker.env.example`**: no dev-only marking; carries `APP_URL` / `PHPMYADMIN_URL` / `PHP_MEMORY_LIMIT` / `PHP_UPLOAD_MAX_FILESIZE` / `PHP_POST_MAX_SIZE` vars whose consumers are unclear (the php.ini hardcodes its own values — verify and drop dead vars). `docker-setup.sh` / `docker-setup.ps1` generate `docker.env` with random passwords.
- **`docker/php/php.ini`**: carries `opcache.fast_shutdown` (removed since PHP 7.2).
- **Docs drift:** `README.md` quickstart references the nonexistent `sqlite` profile and claims "all required extensions"; `migration-docs/documentation/DOCKER.md` documents the same drifted setup; `AGENTS.md` has no compose quickstart and no SQLite (zero-DB) note in its Docker context.

---

## PRINCIPLES (hold across every checklist step)

- **Dev-only scope** — no production image, no hardening pass, no webserver change. **The dev image stays Apache** (user-confirmed 2026-07-24); the nginx + PHP-FPM vs Apache vs FrankenPHP evaluation belongs to Step 2.5, driven by the `.htaccess` porting cost (CSP, security headers, file protection, front-controller rewrites).
- **Delete over wrap** — dead Docker config (init SQL, dead apt packages, unused extensions, dead env vars) is removed, not commented out or kept "just in case".
- **Capability set = SSoT:** `pdo_mysql`, `pdo_sqlite`, `mbstring`, `gd`, `zip` (+ PCOV in the agent image only). **Bundled ≠ installed:** whatever the official base image already ships stays implicit; explicit `docker-php-ext-install` only for what is actually missing. `ext-intl` stays out (no `NumberFormatter` / ext-intl usage).
- **No credentials in tracked artefacts** — `docker.env` stays gitignored; the image `MYSQL_*` env provides DB bootstrap; setup scripts generate secrets.
- **Cold-start truth** — `docker compose up` immediately after running nothing but the setup script must work: no manual shell exports, no undocumented steps.
- **Agent limitation** — the cloud-agent VM has **no Docker daemon**: agents validate statically (hadolint, `docker compose config`, YAML checks); runtime validation is explicit Manual Work for the user. Never fake a runtime result.
- **No application runtime code changes** — PHPUnit + PHPStan stay green untouched; if they go red, something out of scope was touched.

---

## 0. SAFETY CHECKS

Before starting:

1. Branch up-to-date with `develop`.
2. Baseline green:

```bash
php -v
composer install --no-interaction --prefer-dist --no-progress
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

**IF ANY FAILS → STOP AND FIX** (or report the pre-existing failure) before touching Docker artefacts.

---

## 1. DISCOVERY

```bash
rg -n 'docker-php-ext-install|pecl|apt-get install' Dockerfile .cursor/Dockerfile
rg -n 'exif_|bcadd|bcsub|bcmul|bcdiv|bccomp|bcpow|pcntl_' app/ packages/ --glob '!app/vendor/**'
rg -n '"ext-' composer.json
rg -n 'profiles|depends_on|healthcheck|default-authentication-plugin|env_file|version:' docker-compose.yml docker-compose.e2e.yml
rg -n 'sqlite|profile|docker' README.md AGENTS.md migration-docs/documentation/DOCKER.md
rg -n 'docker-compose|docker compose' scripts/
ls docker/ docker/mysql/init/ tests/e2e/ 2>/dev/null
```

- Record the **bundled extension list** of the base images: if Docker is available, `docker run --rm php:8.5-apache php -m` and `docker run --rm php:8.5-cli php -m`; otherwise take the official `php` image documentation as the source, state the assumption in the ticket, and put the `php -m` confirmation under Manual Work.
- Confirm which `docker.env.example` variables have actual consumers (compose interpolation, container env, scripts).
- Confirm whether anything outside `scripts/e2e-*.sh` references `docker-compose.e2e.yml` (docs count; CI does not use it).

---

## 2. WORK

Architect: decompose into ordered, individually-green checklist steps. Suggested order: 2.1 → 2.2 → 2.3 + 2.4 (compose + DB init together — one functional unit) → 2.5 → 2.6 → 2.7 (docs last, after reality is fixed).

### 2.1 Dockerfile reconciliation (root `Dockerfile` + `.cursor/Dockerfile`)

- Drop `xml` / `dom` / `xmlwriter` / `simplexml` from the root `Dockerfile` (built into PHP 8.5).
- Drop `exif` / `bcmath` / `pcntl` from **both** Dockerfiles — Discovery pre-check found no usage. If a genuine consumer surfaces, keep the extension with a one-line justification comment instead.
- Enforce the capability set (`pdo_mysql`, `pdo_sqlite`, `mbstring`, `gd`, `zip`): explicitly install **only** what the base image does not bundle (expected: `pdo_mysql`, `gd`, `zip`); drop redundant installs of bundled extensions (`pdo`, `pdo_sqlite`, `sqlite3`, `mbstring` — verify against the recorded `php -m`).
- Prune the apt lists to match: remove `libmcrypt-dev`; remove `libxml2-dev` / `libonig-dev` if only the dropped/bundled extensions needed them; keep the gd/zip build libs (`libpng-dev`, `libjpeg62-turbo-dev`, `libfreetype6-dev`, `libzip-dev`).
- **Decide (Architect):** whether the dev image keeps baking app code (`COPY . …` + `composer install --no-dev`) at all, given the dev bind mount shadows it and README documents in-container `composer install`. If kept, the `.dockerignore` fix (2.2) must shrink the build context; if dropped, the image reduces to PHP + Apache + extensions + Composer binary.
- Every remaining `docker-php-ext-install` entry and apt lib must have an obvious justification (short comment) — acceptance criterion in #241.
- `.cursor/Dockerfile`: touch **only** the extension block; the agent tooling (node, yarn, Playwright, gh, PCOV, users/dirs) stays as-is.

### 2.2 `.dockerignore`

- Extend beyond the current 16 lines to exclude non-runtime paths: `migration-docs/`, `tests/`, `docs-site/`, `.github/`, `.cursor/`, `*.md`, coverage/report artefacts (verify actual names in the tree: `coverage/`, `.phpunit.cache/`, `playwright-report/`, `test-results/`, `build/logs/`, …).
- Keep the existing entries (`node_modules`, `.git`, `tmp/`, `storage/`, `app/vendor/`, …).

### 2.3 Dev compose (`docker-compose.yml`)

- **MySQL healthcheck** (`mysqladmin ping` or equivalent) + gate dependents with `depends_on: { mysql: { condition: service_healthy } }` (`web`, `phpmyadmin`).
- **Drop** `--default-authentication-plugin=mysql_native_password` (removed in MySQL 8.4; default `caching_sha2_password` works with PHP 8.5 mysqlnd).
- Remove the obsolete top-level `version:` key (both compose files, if the e2e file survives 2.5).
- **Fix the profile wiring** so both documented paths actually work: the default MySQL path (`docker compose up` → web + mysql + phpmyadmin + node) and the SQLite zero-DB path (web + node only, `php pagekit setup … -d sqlite` documented). Mechanism is the Architect's choice (drop profiles from the default services and use a profile/override only for the alternative path, or explicit `COMPOSE_PROFILES` documented in README) — but README/DOCKER.md and the compose file must agree afterwards, and no phantom `sqlite` profile may remain.
- **Fix the env wiring:** resolve the `env_file: docker.env` vs `${VAR}` interpolation mismatch — either drop the redundant interpolated `environment:` mappings and feed containers via `env_file` with the names the images expect, or standardize on Compose's native `.env` (setup scripts then write that file). Acceptance: a cold `docker compose up` right after `./docker-setup.sh` / `docker-setup.ps1` works with **no manual exports**; both setup scripts stay aligned with the chosen mechanism.
- Node service stays on `node:22-alpine` + Yarn 1 (Webpack/Gulp pipeline is Step 2.4 — do not touch the build tooling).

### 2.4 DB init

- Delete `docker/mysql/init/01-create-database.sql` and the init-mount if the image env fully covers it (`MYSQL_DATABASE` / `MYSQL_USER` / `MYSQL_PASSWORD` already create the DB, the user, and the grants). No hardcoded credentials remain in any tracked Docker artefact.

### 2.5 E2E compose — decide: modernize or retire (user-confirmed decision point)

- Evidence: unused by CI (Playwright manages its own `php pagekit start` webServer); the `./tests/e2e/fixtures` init-mount points at a directory that does not exist; hardcoded test credentials; scripts call the v1 `docker-compose` binary; node image drifts from the main compose.
- **Architect decides with evidence** and records the decision + rationale in the ticket and branch doc:
  - **(a) Retire:** delete `docker-compose.e2e.yml` + `scripts/e2e-start.sh` / `e2e-stop.sh` / `e2e-reset.sh` and scrub doc references (README, DOCKER.md, `tests/e2e/*.md` pointers) — if the Playwright-managed path fully supersedes the Docker E2E path.
  - **(b) Modernize:** MySQL healthcheck + `service_healthy` gating, credentials via env (no hardcoded values), Compose-v2 CLI in the scripts, node image aligned, dead fixtures mount fixed or removed.

### 2.6 Env + php.ini hygiene

- Mark `docker.env.example` explicitly **dev-only** (header comment: never a production template; production config/secrets handling arrives in Step 2.5).
- Drop `docker.env.example` variables without a consumer (per Discovery); keep the setup scripts generating exactly the variables the chosen env wiring needs.
- `docker/php/php.ini`: remove `opcache.fast_shutdown` (removed since PHP 7.2); sanity-check the remaining values are dev-appropriate (leave `display_errors = On` etc. — this file is dev-only).

### 2.7 Docs alignment (last — after reality is fixed)

- **`README.md`**: Docker quickstart matches the fixed compose — real profile/path commands only (no phantom `sqlite` profile), MySQL default path + SQLite zero-DB path both documented, extension claims match the reconciled set, setup-script flow unchanged.
- **`AGENTS.md`**: align the Docker context — the SQLite zero-DB path note, and the `.cursor/Dockerfile` extension set if mentioned; keep the cloud-agent caveats accurate.
- **`migration-docs/documentation/DOCKER.md`**: update to the new reality — or fold the content into README and delete the file if it is fully redundant (Architect decides; delete over wrap).

---

## MANUAL WORK (record in the branch doc — agents do NOT perform these)

Log these (and any newly discovered ones) under the branch doc's "Deferred / Out-of-Scope" as an explicit **Manual Work Required** list for the user to action after the ticket:

1. **Runtime validation on a Docker host:** cold `docker compose up` on the MySQL path reaches a working Pagekit install (no boot race, no restart loop on a cold volume, `web` starts only after MySQL reports healthy); the documented SQLite path works end-to-end.
2. **`php -m` confirmation** of the bundled-extension assumptions in both images, if Docker was unavailable in-agent during Discovery.
3. **Regenerate the local `docker.env`** (and `.env`, if that mechanism was chosen) via the setup script — existing local files may carry the old variable wiring.
4. If the E2E compose path was retired: remove local `storage-e2e/` / `tmp-e2e/` leftovers.

---

## 3. OUT OF SCOPE

- **Multi-stage / production image, hardening, prod compose, container `HEALTHCHECK` for prod, image build/scan/push in CI (Hadolint/Trivy/GHCR), 12-factor env/secrets** → **Step 2.5** (#158).
- **Webserver decision** (nginx + PHP-FPM vs Apache vs FrankenPHP) → **Step 2.5**. The dev image stays Apache — do not port `.htaccess` rules to another server in this ticket (user-confirmed 2026-07-24).
- **pnpm + Vite pipeline**, any Webpack/Gulp/Yarn replacement → **Step 2.4** (#159).
- **OpenWeatherMap API key → env path + rotation** → **Step 2.5** (tagged `AUDIT FIX Step 2.5` in `app/system/modules/dashboard/index.php`).
- **CI workflow changes** — Step 2.2 just landed; only doc references may be touched, no workflow edits.
- **E2E spec repair/rework** → Step 3.6.1; retiring the Docker E2E wrapper (2.5 above) does not touch specs.

---

## 4. TESTING

- **Per checklist step (Conductor Tester):** PHPUnit + PHPStan PASS/FAIL — both must stay green untouched (no runtime code changes in this ticket; a regression means out-of-scope drift).
- **Static Docker validation in-agent:** hadolint on both Dockerfiles (static binary download works without root) if the network permits; `docker compose -f docker-compose.yml config` if a compose CLI is present; otherwise YAML well-formedness + manual review. Record in the branch doc which validations actually ran and which fell through to Manual Work.
- **Docs consistency:** every documented command must exist against the actual artefacts — no phantom profiles, services, or scripts (grep-verify README/DOCKER.md/AGENTS.md examples against the compose files).
- **Runtime `docker compose up` is Manual Work** — the cloud-agent VM has no Docker daemon. Do not fake or simulate a runtime result.

---

## SUCCESS CRITERIA

- Root `Dockerfile` and `.cursor/Dockerfile` agree on the PHP extension set; the XML quartet is gone from the root file; `exif` / `bcmath` / `pcntl` dropped (or a found consumer documented); every remaining extension and apt lib has a justification; dead apt packages (`libmcrypt-dev`, …) removed.
- `.dockerignore` excludes docs, tests, CI/agent config, and coverage/report artefacts; build context carries runtime code only.
- Dev compose: MySQL healthcheck + `service_healthy` gating; MySQL 8.4 starts on a cold volume (auth-plugin flag gone); no obsolete `version:` key; profile wiring consistent with the docs (no phantom `sqlite` profile); env wiring works cold after the setup script with no manual exports.
- `docker/mysql/init/01-create-database.sql` deleted; **no credentials in any tracked Docker artefact**; `docker.env` stays gitignored.
- E2E compose decision (retire vs modernize) made, implemented, and recorded with evidence in the ticket + branch doc.
- `docker.env.example` marked dev-only with dead variables dropped; `docker/php/php.ini` cleaned (`opcache.fast_shutdown` gone).
- README / AGENTS.md / DOCKER.md match the actual artefacts; MySQL default path and SQLite zero-DB path both documented and real.
- PHPUnit + PHPStan green; **no application runtime code changed**.
- Manual Work list complete in the branch doc (runtime validation, `php -m` confirmation, local env regeneration).

---

## NOTES FOR THE ARCHITECT

- This is a **config + docs ticket**: no production PHP under `app/` / `packages/` changes, so mark the checklist steps `test-writer: skip` in `## TESTING STRATEGY` accordingly.
- Keep checklist steps small and individually green; compose + DB init (2.3 + 2.4) are one functional unit — do not split them across commits that leave the MySQL path broken.
- Where a Current-state claim cannot be verified in-agent (no Docker daemon), do not guess: implement per documented behavior (MySQL 8.4 release notes, the Compose spec, the official `php` image extension list), state the assumption, and put the runtime confirmation under Manual Work.
- Issue #241 matches `PHASE_2_MODERNISING.md` §2.3; this prompt adds verified current-state findings (MySQL 8.4 auth flag, profile/env wiring, dead fixtures mount, php.ini hygiene). Where anything disagrees, §2.3 + this prompt win.
- One ticket / one PR; Conventional Commits; the version bump happens once at Finalize — never inside checklist steps.
