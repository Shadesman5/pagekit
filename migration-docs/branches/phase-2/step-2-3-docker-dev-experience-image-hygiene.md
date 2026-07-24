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

---

## 🧠 Key Decisions (Rationale)

- **`.cursor/Dockerfile` also drops `libgd-dev` (Checklist Step 1).** The ticket's checklist text named only `libxml2-dev` + `libonig-dev` for this file's apt prune. The Verifier's first FAIL caught `libgd-dev` left in with no justification comment; the Refactorer's first retry added comments elsewhere but left `libgd-dev` uncommented pending Architect input, since the checklist hadn't named it here. The Verifier's second FAIL applied the root `Dockerfile`'s own decision-1 rationale — PHP's `gd` extension builds from its bundled `libgd` source, not the system package — noting it holds identically for the agent image; the Refactorer's second retry dropped the package, and the Verifier passed. No Architect escalation was needed since the existing rationale covered it.

---

## ⚠️ Breaking Changes (Extensions)

_TBD_

---

## ⚠️ Risks & Rollout Notes

- **Root `Dockerfile` no longer bakes app code (Checklist Step 1).** `COPY . /var/www/html`, the `chown`/`chmod` layer, and `composer install --no-dev --optimize-autoloader` are gone — the image alone now builds to just PHP 8.5 + Apache + extensions + Composer binary. Building/running it standalone (`docker build` / `docker run`, no compose) leaves `/var/www/html` empty; the documented dev flow (`docker compose up`) is unaffected because its bind mount already covers the same path. Production baking returns with the separate image in Step 2.5.

---

## 🔐 Security & Data Impact

_TBD / None_

---

## 🛡️ No-Mercy Compliance

- **Rule 4 (Delete over wrap) — Checklist Step 1:** baked-code layers (`COPY . /var/www/html`, `chown`/`chmod`, `composer install --no-dev`) removed outright rather than gated behind a build arg or left commented out; unused apt libs (`libmcrypt-dev`, `libxml2-dev`, `libonig-dev`, `libgd-dev`, `zip` CLI) and PHP extensions (`pdo`, `pdo_sqlite`, `mbstring`, `exif`, `pcntl`, `bcmath` — all bundled by the base image or unused by Pagekit) dropped rather than kept "just in case."

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
