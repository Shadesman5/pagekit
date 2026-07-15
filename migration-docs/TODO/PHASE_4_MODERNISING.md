# 🚀 Phase 4: Production-Ready Release – Essential Features

**Goal**: Make Pagekit 2.0.0 production-ready with essential features.
**Prerequisite**: Phases 1–3 MUST be completed!

**Sequencing:** Run **4.7 → 4.8** before Steps 4.2/4.3. Complete **4.0** before the 2.0.0 release.

---

## Step 4.0: Accessibility & WCAG Baseline

- **Goal**: WCAG 2.2 Level AA verification for admin and `packages/pagekit/theme-one` before 2.0.0 release.
- **Prerequisite**: Step 3.5.1 and Step 3.6.1 completed
- **Tasks**:
  - CI: `@axe-core/playwright` on critical flows (login, admin navigation, content editing, public page) — blocking gate (was report-only in 3.6.1)
  - Manual checklist: keyboard-only navigation, screenreader spot-check, form errors, heading hierarchy, alt text, modal focus traps
  - Theme-one: semantic HTML, skip link, landmarks, contrast, focus styles
  - Document a11y statement and extension-author expectations (Step 5.6 precursor)
  - DACH reference (docs): BITV 2.0 / EN 301 549
- **Result**: Documented WCAG 2.2 AA baseline; CI prevents regressions on critical flows.
- **Risk**: Medium — may require UI/template fixes before 2.0.0 tag.

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
  - Image Optimization — see **Step 4.6 (Native Image Pipeline & Media Manager)** for the full media pipeline (AVIF/WebP, responsive `<picture>`, focal point). 4.3 covers general asset-level optimization; Step 4.6 Sub-Step A can be pulled forward here if the PageSpeed win is wanted inside 2.0.0.
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

---

## Step 4.6: Native Image Pipeline & Media Manager

- **Goal**: A fully automated, native image pipeline in the core. Editors upload high-resolution originals; the system automatically serves modern formats (AVIF/WebP), responsive `<picture>`/`srcset` variants (1x + 2x Retina), native lazy-loading, plus per-image **focal point** and **cover/contain** display control. Zero-config for end users, open-source dependencies only, non-destructive (originals stay untouched).
- **Status note**: This is a **net-new feature**, not legacy modernization, so it does **not** block the 2.0.0 "modernization complete" milestone. Placed at 4.6 = built after the 4.5 rebrand, i.e. natively in the `Kernkit\` namespace (no later rename needed). If the PageSpeed win is wanted inside 2.0.0, **Sub-Step A** (below) can be pulled forward into Step 4.3.
- **⚠️ Greenfield warning**: The codebase currently has **no** server-side image processing (no GD/Imagick code, no library), **no** responsive-image output (`srcset`/`<picture>`), **no** media-metadata entity (images are plain path strings in a JSON `data` column), and **no** Symfony Messenger/queue. This step builds all of it. The clean integration points that already exist: the **Filesystem module** (`Pagekit\Filesystem` + `Locator` + `FileAdapter`, `storage:` prefix) and the **content-plugin pipeline** (`ContentHelper::applyPlugins()`, `content.plugins` event).

### Architecture principle — editor-independent (why this works without the Block Editor)

The `<picture>` markup is produced at **render time** by a new content plugin (`ImagePipelinePlugin`, registered with a priority that runs **after** `MarkdownPlugin`), **not** in the editor. Every editor (HTML, TinyMCE, CodeMirror, textarea) just stores a plain `<img>` / `![]()`; the plugin upgrades them all automatically. Focal point + display mode live centrally on the **media asset**, so they work without editor changes too. The Modern Block Editor (Step 5.1) later adds the nicest *per-placement* focal-point UX — an enhancement, **not** a prerequisite. → **Do not wait for 5.1.**

### Sub-steps

- **4.6 A — Backend engine + render helper (the core)**: integrate an image library (**`league/glide`** recommended — on-demand, cached, **no Messenger needed**; alternative `intervention/image` v3 with an upload trigger), storage/cache layout under `storage/media/`, size/format **presets**, the `<picture>` render helper, `ImagePipelinePlugin`, native `loading="lazy"` (+ `fetchpriority="high"` for hero images), an AVIF **capability check** with graceful WebP/JPEG fallback, and add `avif` to the finder upload whitelist. Editor-independent; delivers automatic optimization for the entire existing content base. *(Can ship as early as 4.3 if desired.)*
- **4.6 B — Media metadata + focal-point UI**: introduce a media-asset metadata model (alt text, `display_mode` = cover/contain, `focal_point` x/y in percent); extend `<InputImageMeta>` (`app/system/app/components/input-image-meta.vue`) with a display-mode toggle, a click-to-set focal-point selector, and a live preview. **Best after Vue 3 (Step 3.4)** for the elegant `<style> v-bind()` variant; a Vue 2.6 `:style`-binding version is possible earlier.
- **4.6 C — Block editor integration (optional enhancement)**: per-placement focal point / fit via `data-*` overrides, edited directly in the **Modern Block Editor (Step 5.1)**. Non-blocking.

### Security & caching (must-haves)

- **Signed transform URLs + fixed presets** (Glide HMAC) to prevent cache-flooding DoS from arbitrary `?w=…` params. The signing key comes from config/env, **never** hardcoded. Ties into Step 4.1.
- **Upload validation**: MIME sniffing (not just extension), dimension/size limits; SVG is never converted/scaled and is sanitized on delivery; animated GIF/WebP are passed through, not flattened.
- **Cache invalidation**: content-hash cache keys (`hash(origin bytes) + transform params`). Originals are immutable (a "changed image" = new upload = new key); a focal-point change needs **no** regeneration (pure CSS `object-position`). Add a `php pagekit image:cache:clear` command + a cleanup cron for orphaned variants.

### Constraints corrected from the initial concept (architecture mismatches to avoid)

- **`v-bind()` in CSS is Vue-3-only** → use `:style` object binding under Vue 2.6, or defer the polished UI to after Step 3.4.
- **No media entity exists today** → a media table (`pk_media`) is a new subsystem; use an **integer** id per Doctrine convention (a UUID would need a dedicated decision/ADR).
- **Symfony Messenger is not installed** → prefer on-demand (Glide) over async pre-generation to avoid introducing a queue dependency.
- **Do not hardcode `/media/…` URLs** → resolve via `UrlProvider`/`FileAdapter` + the `storage:` locator (base-path / sub-directory / CDN-safe).
- **AVIF is an environment dependency** (GD+libavif or Imagick+libheif), not just a library choice → capability check + graceful fallback.

- **Dependencies**: Filesystem module (present); Vue 3 (Step 3.4) for the polished UI; signed URLs relate to Step 4.1 (Security); overlaps the "Image Optimization" item in Step 4.3 (Performance).
- **Complexity**: High — a full greenfield subsystem spanning backend, rendering, and UI.

---

## Step 4.7: Symfony 6.4 → 7.x Upgrade

- **Goal**: Upgrade Symfony components from 6.4 LTS to 7.x.
- **Prerequisite**: Phase 3 complete; Steps 2.5, 2.8, 2.1.11 completed
- **Tasks**:
  - Bump all `symfony/*` in `composer.json` from `^6.4` → `^7.0` (confirm latest 7.x patch at execution time)
  - Resolve Symfony 7 breaking changes (HTTP Kernel, Routing, Validator, Translation, Console, etc.)
  - Update `symfony/phpunit-bridge`, `symfony/browser-kit`, dev bundles to 7.x
  - Re-enable / tune `SYMFONY_DEPRECATIONS_HELPER` if still disabled after Step 2.9
  - Full PHPUnit + PHPStan L8 + Playwright E2E green
- **Explicit non-goals:** Doctrine DBAL 4 (Step 4.8), frontend changes, new features
- **Result**: Symfony 7.x; update ROADMAP stack reference and README badges.
- **Risk**: Medium–High — broad component surface.

---

## Step 4.8: Doctrine DBAL 3 → 4 Upgrade

- **Goal**: Upgrade `doctrine/dbal` from 3.x to 4.x on Pagekit's custom ORM layer.
- **Prerequisite**: Step 4.7 completed; Steps 2.1.11, 2.1.7, 2.9 completed
- **Tasks**:
  - Bump `doctrine/dbal` `^3.8` → `^4.0` in `composer.json`
  - Migrate breaking changes: type system, removed APIs (`requiresSQLCommentHint()`, type mappings), platform differences
  - Update `doctrine/migrations` compatibility if required
  - Audit custom DBAL usage: `Pagekit\Database\ORM`, `QueryBuilder`, connection wrappers, schema tools, migration runners
  - Full PHPUnit (incl. ORM integration paths from 2.1.9/2.9) + PHPStan green
  - Coordinate with Step 4.3 if tag-based cache invalidation touches DBAL types
- **Result**: DBAL 4.x on custom ORM.
- **Risk**: High — custom ORM + QueryBuilder + migrations.

---

## Step 4.9: Cross-Repo Dev Dashboard & Real-Time Conductor Metrics

- **Goal**: Move modernization observability into a dedicated **`kernkit/dev-dashboard`** GitHub Pages site — Conductor metrics, CI quality, and roadmap progress **across all Kernkit repos**, visible **during feature-branch runs** (not only after merge to `develop`).
- **Prerequisite**: Step 4.5 (rebrand + clean repo split) completed; Conductor metrics pattern proven in the Pagekit playground repo.
- **Why Phase 4 (not earlier):**
  - Clean repo boundaries come with the 4.5 Kernkit split — avoid building cross-repo plumbing twice on the learning sandbox.
  - Real-time branch metrics matter most once Kernkit is the production core.
- **Architecture sketch:**
  - **Push model:** Conductor post-phase hook POSTs session deltas via **`gh api`** / `curl` to the dev-dashboard repo (`metrics/incoming/{repo}/{branch}/…`) — no wait for PR merge.
  - **Dashboard repo:** static/MkDocs site; ROADMAP tracking table + accordion metrics (same UX as today).
  - **Aggregation:** GitHub Action on dev-dashboard (`repository_dispatch` or incoming-folder merge) builds `index.json`.
  - **Auth:** fine-grained PAT or GitHub App with `contents: write` on dev-dashboard only.
- **Scope:** Port current `docs-site/` modernization pages; keep product docs in `kernkit/docs`; generalise backfill for any repo/workflow via `gh api`.
- **Non-goals:** Agents writing to dashboard; replacing `.cursor/ROADMAP.md` as agent SoT.
- **Result**: One URL for live metrics across repos/branches; intervene before the 360-minute GHA cap.
- **Risk**: Low–Medium — mostly JSON + Pages plumbing.
