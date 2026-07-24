# Step 2.3 — Docker Dev Experience & Image Hygiene

<!-- Branch doc for Roadmap Step 2.3.
     Path: migration-docs/branches/phase-2/step-2-3-docker-dev-experience-image-hygiene.md -->

**Branch:** `feature/docker-developer-experience`
**ROADMAP Step:** 2.3 (Docker Dev Experience & Image Hygiene)
**GitHub Issue:** [#241](https://github.com/Shadesman5/pagekit/issues/241)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-07-24 20:58
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### Dockerfile reconciliation — capability-set extensions, apt prune, drop baked-code layers (Checklist Step 1)

| File | Change |
|---|---|
| `Dockerfile` | Apt install pruned to justified-only, one-line why comment on every entry: kept `git`, `curl`, `unzip`, `libpng-dev`, `libjpeg62-turbo-dev`, `libfreetype6-dev`, `libzip-dev`; dropped `libmcrypt-dev`, `libxml2-dev`, `libonig-dev`, `libgd-dev`, `zip` (CLI, no consumer). `docker-php-ext-install` cut from 12 entries to the capability set `pdo_mysql`, `gd`, `zip` (base image already bundles `pdo`, `pdo_sqlite`, `sqlite3`, `mbstring`, `xml`, `dom`, `xmlwriter`, `simplexml`); `docker-php-ext-configure gd --with-freetype --with-jpeg` kept. Deleted the baked-code layers (`COPY . /var/www/html`, `chown`/`chmod`, `composer install --no-dev --optimize-autoloader`) — dev stack now relies on the compose bind-mount + in-container `composer install`. `FROM php:8.5-apache`, Composer binary `COPY`, `a2enmod rewrite`, vhost block, `WORKDIR`, `EXPOSE 80`, `CMD` unchanged. |
| `.cursor/Dockerfile` | Same capability-set trim on `docker-php-ext-install`: `pdo_mysql`, `gd`, `zip` (dropped `pdo`, `pdo_sqlite`, `mbstring`, `exif`, `pcntl`, `bcmath` — bundled or unused); PCOV install block untouched. Apt list drops `libxml2-dev`, `libonig-dev`, and `libgd-dev` (consistency fix — see Key Decisions), with one-line why comments added to every remaining entry; `sqlite3`/`libsqlite3-dev` and all agent tooling (git/sudo/curl/wget, Node toolchain, editors, `default-mysql-client`, `ripgrep`/`jq`, GitHub CLI) untouched. `FROM php:8.5-cli` unchanged. |

Tests: none (test-writer: skip — Dockerfiles only, no production PHP under `app/`/`packages/`). Gates: Verifier FAIL → refactorer retry → FAIL → retry → PASS (see Key Decisions); Tester — PHPUnit PASS, PHPStan PASS. hadolint + `php -m` fell through to Manual Work per the ticket's Manual Work list (item 2).

### `.dockerignore` extension — non-runtime paths excluded from build context (Checklist Step 2)

| File | Change |
|---|---|
| `.dockerignore` | Kept all 16 existing entries; regrouped them under category header comments (dependencies, runtime state/logs, local install artefacts, tests/reports/coverage, docs+CI+agent config, Docker artefacts, VCS/editor/OS noise) and added the 16 new entries named in the checklist: `migration-docs/`, `docs-site/`, `tests/`, `.github/`, `.cursor/`, `*.md`, `playwright-report/`, `test-results/`, `.phpunit.cache/`, `config.php`, `pagekit.db`, `*.db`, `Dockerfile`, `.dockerignore`, `docker-compose*.yml`, `docker/`. |

Tests: none (test-writer: skip — build-context file only, no production PHP under `app/`/`packages/`). Gates: Verifier PASS; Tester — PHPUnit PASS, PHPStan PASS.

### Dev compose + `.env` wiring + setup scripts + DB-init deletion (Checklist Step 3)

| File | Change |
|---|---|
| `docker-compose.yml` | Removed the top-level `version:` key. Removed `profiles:` from `mysql` + `phpmyadmin` (bare `docker compose up` now starts every service, closing the trap where MySQL never started because no compose profile is named `default`). `mysql`: dropped `command: --default-authentication-plugin=mysql_native_password` (removed server option in MySQL 8.4) and `env_file:`; added a TCP `healthcheck` (`mysqladmin ping -h 127.0.0.1`, so `web`/`phpmyadmin` cannot start against the first-boot socket-only temporary server); `environment:` now interpolates `${MYSQL_DATABASE}`/`${MYSQL_USER}`/`${MYSQL_PASSWORD}`/`${MYSQL_ROOT_PASSWORD}` from `.env`, with a `${VAR:?Run ./docker-setup.sh first}` guard on both passwords; dropped the `./docker/mysql/init` bind mount. `web`: `depends_on` now gates on `condition: service_healthy`; dropped `env_file:` and the dead `APACHE_DOCUMENT_ROOT` environment entry. `phpmyadmin`: same healthy-gated `depends_on`; dropped `env_file:`; `PMA_HOST`/`PMA_PORT` are now literal `mysql`/`3306` (kept `PMA_USER: root` + `PMA_PASSWORD: ${MYSQL_ROOT_PASSWORD}`). `node` service untouched. |
| `.env.example` (new — replaces deleted `docker.env.example`) | Dev-only header stating this is never a production template. Carries only the 4 vars actually consumed by the stack (`MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`); the old file's 9 dead vars (`DB_HOST`, `DB_PORT`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `PHPMYADMIN_URL`, `PHP_MEMORY_LIMIT`, `PHP_UPLOAD_MAX_FILESIZE`, `PHP_POST_MAX_SIZE`) are gone, and `DB_NAME`/`DB_USER`/`DB_PASSWORD` are renamed to the `MYSQL_*` spelling the mysql image itself expects. |
| `docker-setup.sh` | Generates `.env` (not `docker.env`) from `.env.example`, copying it line-by-line and substituting only the two password lines rather than running `sed` over the file (a generated password containing a shell/regex metacharacter can no longer corrupt the substitution). Password generation now forces `LC_ALL=C`. Dropped the now-dead `$`-escaping step, the `docker.env.example`-missing fallback content block, the `.gitignore`-append logic (redundant with the tracked `*.env` rule), and the trailing self-`chmod +x`. Added `set -eu` and a guard that exits if `.env.example` is missing. Closing hint now reads `docker compose up -d` (v2 spelling). |
| `docker-setup.ps1` | Same rewrite in lockstep: writes `.env` via `[System.IO.File]::WriteAllLines` instead of `Out-File` (writes UTF-8 without a BOM, so no stray byte lands inside the first variable name); dropped the `$`-escaping and the inline fallback content block; same missing-`.env.example` guard; closing hint → `docker compose up -d`. |
| `docker/mysql/init/01-create-database.sql` (deleted) | Removed along with its bind mount — the mysql image's own `MYSQL_*` environment variables already create the database, user and grants on first boot; this file's hardcoded `pagekit`/`pagekit` credential was the last hardcoded credential in the compose stack. |
| `.gitignore` | Dropped the now-redundant `docker.env` line — `*.env` already covers `.env`. |

Tests: none (test-writer: skip — compose YAML, env template and shell scripts only, no production PHP under `app/`/`packages/`). Gates: Verifier PASS; Tester — PHPUnit PASS, PHPStan PASS. Tester also ran the ticket's extra Step-3 checks (`python3` YAML-parse of `docker-compose.yml`, `bash -n docker-setup.sh`); `docker compose config` and the runtime cold start stayed Manual Work per the ticket (no Docker CLI in the VM).

### E2E Docker path retirement — compose + scripts deleted, live docs scrubbed, port realigned (Checklist Step 4)

| File | Change |
|---|---|
| `docker-compose.e2e.yml`, `scripts/e2e-start.sh`, `scripts/e2e-stop.sh`, `scripts/e2e-reset.sh` (all deleted) | Retired the standalone Docker E2E stack outright: CI already runs E2E via `php pagekit start` (never `docker-compose.e2e.yml`), the scripts' `./tests/e2e/fixtures` init mount pointed at a directory absent from the repo, and the compose file carried hardcoded test credentials. Playwright's own `webServer` (`php pagekit start --no-ansi`, `reuseExistingServer` outside CI) is now the only server-lifecycle path for E2E runs. |
| `tests/e2e/README.md` | Prerequisites note and the "Using Docker (Recommended)" / "Manual Setup" split replaced with "Playwright-managed server (default)" / "Your own server" (`NO_SERVER=1` to opt out); fresh-install reset is now `rm -f config.php pagekit.db` instead of `./scripts/e2e-reset.sh`; `site.url` example and the `codegen` command port `8180` → `127.0.0.1:8080` throughout. |
| `tests/e2e/COMPLETE_TEST_PLAN.md`, `tests/e2e/TEST_PLAN_ANALYSIS_2025.md` | German-language planning docs: the "Docker E2E Environment" / "Docker E2E Setup" bullets and the "Docker E2E optimieren" recommendation swapped for the Playwright-managed-server fact — command references only, no plan rewrite. |
| `.cursor/skills/e2e-test-architect/runtime-patterns.md` | "Docker Environment" section (the 3 `e2e-*.sh` calls) replaced with "Server & Fresh State" (`webServer` / `NO_SERVER=1` / `rm -f config.php pagekit.db`); `codegen` example port `8180` → `127.0.0.1:8080`. |
| `migration-docs/TODO/agent_prompts/PROMPT_Vue_Template_Precompilation.md`, `migration-docs/TODO/agent_prompts/phase-1/PROMPT_Complete_Template_Security_Modernization.md` | Command blocks only (§5.5; §4.1 + §"Test Commands"): `e2e-reset.sh`/`e2e-start.sh`/`e2e-stop.sh` calls swapped for `rm -f config.php pagekit.db` (+ a Playwright-manages-the-server comment); no other prompt content touched. |
| `tests/e2e/config/test-config.example.json` | `site.url`/`site.adminUrl` realigned `http://localhost:8180` → `http://127.0.0.1:8080`, matching the `php pagekit start` default bind and CI. |
| `.gitignore` | Dropped the now-dead `/storage-e2e/` and `/tmp-e2e/` lines — both belonged to the retired Docker E2E path. |

Tests: none (test-writer: skip — E2E Docker path retirement and doc/prompt scrubs only, no production PHP under `app/`/`packages/`). Gates: Verifier PASS; Tester — PHPUnit PASS, PHPStan PASS. Tester also ran the ticket's extra Step-4 checks (`python3` JSON-parse of `test-config.example.json`; `rg --hidden` allow-list scan for `docker-compose.e2e|e2e-start|e2e-stop|e2e-reset`) — both run without a Docker CLI, so neither falls to Manual Work this step.

---

## 🧠 Key Decisions (Rationale)

- **`.cursor/Dockerfile` also drops `libgd-dev` (Checklist Step 1).** The ticket's checklist text named only `libxml2-dev` + `libonig-dev` for this file's apt prune. The Verifier's first FAIL caught `libgd-dev` left in with no justification comment; the Refactorer's first retry added comments elsewhere but left `libgd-dev` uncommented pending Architect input, since the checklist hadn't named it here. The Verifier's second FAIL applied the root `Dockerfile`'s own decision-1 rationale — PHP's `gd` extension builds from its bundled `libgd` source, not the system package — noting it holds identically for the agent image; the Refactorer's second retry dropped the package, and the Verifier passed. No Architect escalation was needed since the existing rationale covered it.

---

## ⚠️ Breaking Changes (Extensions)

_TBD_

---

## ⚠️ Risks & Rollout Notes

- **Root `Dockerfile` no longer bakes app code (Checklist Step 1).** `COPY . /var/www/html`, the `chown`/`chmod` layer, and `composer install --no-dev --optimize-autoloader` are gone — the image alone now builds to just PHP 8.5 + Apache + extensions + Composer binary. Building/running it standalone (`docker build` / `docker run`, no compose) leaves `/var/www/html` empty; the documented dev flow (`docker compose up`) is unaffected because its bind mount already covers the same path. Production baking returns with the separate image in Step 2.5.
- **`docker-setup.sh` drops its self-`chmod` and is tracked without the exec bit (Checklist Step 3 — flagged for Step 6).** The checklist's Step 3 scope removed the script's trailing `chmod +x docker-setup.sh` along with the other now-dead fallback logic; the file itself is still git-tracked as `100644` (no exec bit), unchanged from before this step. Verifier note: Step 6's README pass must either instruct `chmod +x docker-setup.sh` before first run or the tracked file's git exec bit must be set — otherwise a fresh clone's `./docker-setup.sh` fails with "Permission denied" (invoking it as `bash docker-setup.sh` is unaffected).

---

## 🔐 Security & Data Impact

_TBD / None_

---

## 🛡️ No-Mercy Compliance

- **Rule 4 (Delete over wrap) — Checklist Step 1:** baked-code layers (`COPY . /var/www/html`, `chown`/`chmod`, `composer install --no-dev`) removed outright rather than gated behind a build arg or left commented out; unused apt libs (`libmcrypt-dev`, `libxml2-dev`, `libonig-dev`, `libgd-dev`, `zip` CLI) and PHP extensions (`pdo`, `pdo_sqlite`, `mbstring`, `exif`, `pcntl`, `bcmath` — all bundled by the base image or unused by Pagekit) dropped rather than kept "just in case."
- **Rule 4 (Delete over wrap) — Checklist Step 3:** `docker/mysql/init/01-create-database.sql` and its hardcoded `pagekit`/`pagekit` credential deleted outright rather than parameterized — the mysql image's own `MYSQL_*` environment variables already produce the same database/user/grants; `docker.env.example`'s 9 dead vars and both setup scripts' `$`-escaping, `.gitignore`-append, and self-`chmod` logic removed rather than kept as unused fallback paths.
- **Rule 4 (Delete over wrap) — Checklist Step 4:** `docker-compose.e2e.yml` and all 3 wrapper scripts deleted outright rather than kept alongside the Playwright-managed path; every live-doc pointer (both `tests/e2e/*.md` docs, the skill doc, and the two stale prompts' command blocks) was scrubbed to the new flow — scrub chosen over allow-listing so no future executing agent inherits a command pointing at a deleted script.

---

## ✅ Verification (links only)

- CI run: _TBD_
- Notable deviations: _TBD / None_

---

## 📋 Phase 1 Audit Closure

_TBD_

---

## 📚 Deferred / Out-of-Scope

_TBD_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_3_Docker-Dev-Experience_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_3_Docker-Dev-Experience.md`
- Predecessor: Step 2.2 — CI/CD Pipeline
- Successor: Step 2.4 — Build Tools (pnpm + Vite)

---

## 📊 <Step-specific appendix>

_TBD — remove this section if not applicable._
