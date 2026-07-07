# 🚀 Phase 4: Production-Ready Release – Essential Features

**Goal**: Make Pagekit 2.0.0 production-ready with essential features.
**Prerequisite**: Phases 1-3 MUST be completed!

---

## Step 4.1: Basic Security & Modern Auth

- **Goal**: Essential security features & modern authentication
- **Features**:
  - **2FA (Two-Factor Authentication)**
    - Mandatory for admins (enhanced security)
    - Optional for users (user convenience)
    - TOTP support (Google Authenticator, etc.)
  - **OAuth2 Client (Social Login)**
    - Login via Google
    - Login via GitHub
    - Login via Microsoft
    - Login via Meta (Facebook)
    - Extensible for additional providers
    - Library: league/oauth2-client
  - **Password Policies**
    - Configurable minimum length
    - Complexity requirements
    - Password history
    - Breach check (HaveIBeenPwned API)
  - **Rate Limiting**
    - Brute-force protection
    - Per IP and per user
    - Configurable thresholds
  - **Security Headers**
    - CSP, HSTS, X-Frame-Options
    - Optimized for modern browsers

---

## Step 4.2: REST API v2

- **Goal**: Modern REST API
- **Components**:
  - OpenAPI 3.0 Documentation
  - JWT Authentication
  - API Versioning
  - Rate Limiting
- **⚠️ Sequencing guardrail (audit 2026-07-07 — Proposal P6 / SL-1):** **Step 2.1.10 (Entity Presentation Layer / DTO) MUST land before any serialization work here.** API responses must be produced by the DI-based presenters/DTOs from Step 2.1.10 — **not** by entity `jsonSerialize()` (`Node`/`Post`), which reaches services through the transitional static `ModelServiceLocator` slated for removal. Building 4.2 endpoints on the entity-`jsonSerialize()` path means throwing that work away once 2.1.10/2.1.11 remove the locator + Active-Record singleton. See `PHASE_2_MODERNISING.md` §2.1.10.
- **Related audit item (already tracked here):** the hardcoded OpenWeatherMap **API key** in `app/system/modules/dashboard/index.php:48-49` (audit **TD-22**, Critical-by-policy; in-code tag `AUDIT FIX Step 4.2 — move API key to env variable / secrets management`). Audit SL-3 recommends **accelerating** the move to env/secrets **and rotating** the committed key rather than waiting for the full 4.2 build.

---

## Step 4.3: Performance Optimization

- **Goal**: Production-ready performance
- **Features**:
  - Redis/Memcached Support
  - Image Optimization
  - Asset Pipeline Optimization
  - Query Performance Tuning
  - **ORM Cache Invalidation Strategy** — `EntityManager::invalidateCache()` currently uses `$cache->clear()` (clears the entire cache pool on every `save()`/`delete()`). Replace with tag-based invalidation via `TagAwareCacheInterface` (Symfony 6.4) to only invalidate cache entries for the affected entity type. See: `app/modules/database/src/ORM/EntityManager.php`

---

## Step 4.4: Monitoring & Health Checks

- **Goal**: Production monitoring essentials
- **Components**:
  - Health Check Endpoints
  - Basic Application Metrics
  - Error Tracking (Sentry/Rollbar)
  - Performance Monitoring

---

## Step 4.5: Rebranding — Pagekit → Kernkit (after the first production release)

- **Goal**: Give the CMS its own independent identity by renaming it from "Pagekit" to **Kernkit / KernKit**. The MIT license covers the *code*, not the "Pagekit" name or logo — this establishes a trademark-safe, independent brand while keeping the required heritage attribution.
- **Prerequisite**: **The first production release (2.0.0) has shipped — still under the `Pagekit` name.** The rebrand is the *next* thing after that release, deliberately **not** part of the 2.0.0 release itself (so 2.0.0 stays a clean "modernization complete" milestone under the original name, and the rename is isolated from feature work).
- **Naming decision (maintainer, 2026-07-08):**
  - **Code — uniform `Kernkit`** (single capital): PHP namespace `Pagekit\` → `Kernkit\`, class/identifier prefixes, internal string identifiers. Keep it *consistent* — do not mix casings in code.
  - **Composer / Packagist / repo / org — lowercase `kernkit`** (Composer requires a lowercase vendor): `pagekit/*` → `kernkit/*`; new home `github.com/kernkit/kernkit` (+ `kernkit/docs`).
  - **Branding / logo / display name — `KernKit`** (camelCase): README title, UI branding, wordmark, marketing copy.
- **Scope (CMS only — public open-source rebrand):**
  - **Namespaces & code**: sweep `Pagekit\` → `Kernkit\` across `app/`, `packages/`, and tests; update the PSR-4 autoload prefix in `composer.json` and regenerate the autoloader; sweep remaining `pagekit` string identifiers (config keys, cookie/session names, cache namespaces) and the log file name (`pagekit.log` → `kernkit.log`).
  - **CLI**: rename the `pagekit` console binary/entry point to `kernkit` (behaviour identical; `php kernkit …`).
  - **composer.json**: package `name`, `description`, `homepage`, support URLs → `kernkit`.
  - **README.md & docs**: title (`# Pagekit CMS - Modernized` → KernKit), badges, screenshot alt-text, clone/install URLs (`Shadesman5/pagekit` → `kernkit/kernkit`); fresh docs home at `kernkit/docs`.
  - **Branding assets**: create a new **KernKit** logo/wordmark. **Do NOT reuse the original Pagekit logo** (copyright YOOtheme — only the code is MIT).
  - **Heritage attribution (keep!)**: retain the MIT copyright in `LICENSE` and add a README/footer note: _"Originally based on Pagekit CMS (MIT License)."_
  - **Verify**: full PHPUnit + Playwright E2E green after the namespace sweep; fresh install **and** upgrade path still boot; no stray `Pagekit` identifiers on live code paths (historical mentions in `CHANGELOG`/migration history may remain).
- **Structure note**: may be split into sub-steps if the single pass is too large — e.g. **4.5.1** namespace/code sweep, **4.5.2** composer + CLI + config identifiers, **4.5.3** README + docs + branding assets.
- **Legal note**: a trademark search for the chosen name is advisable before the public launch (DPMA/EUIPO) — out of scope for the code work here, tracked by the maintainer separately.
- **Risk**: Medium — mechanical but very wide-reaching (touches every namespaced file plus build/config/CLI). Mitigated by the green-test-suite gate and by running *after* a stable, already-shipped 2.0.0 release.
