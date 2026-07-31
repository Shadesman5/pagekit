# Phase 4: Production-Ready Release – Essential Features

**Goal**: Make Pagekit 2.0.0 production-ready with essential features.
**Prerequisite**: Phases 1–3 MUST be completed!

**Sequencing:** Table order = execution order (top → bottom). Stack majors (**4.2 Symfony 7**, **4.3 DBAL 4**) run **before** product API/Perf (**4.4**, **4.5**). Complete **4.0** before the 2.0.0 release. Open rows may still be redefined; completed history is fixed in ROADMAP.

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

---

## Step 4.2: Symfony 6.4 → 7.x Upgrade

- **Goal**: Upgrade Symfony components from 6.4 LTS to 7.x.
- **Prerequisite**: Phase 3 complete; Steps 2.7, 2.1.14, 2.1.11 completed
- **Tasks**:
  - Bump all `symfony/*` in `composer.json` from `^6.4` → `^7.0` (confirm latest 7.x patch at execution time)
  - Resolve Symfony 7 breaking changes (HTTP Kernel, Routing, Validator, Translation, Console, etc.)
  - Update `symfony/phpunit-bridge`, `symfony/browser-kit`, dev bundles to 7.x
  - Re-enable / tune `SYMFONY_DEPRECATIONS_HELPER` if still disabled after Step 2.11
  - Full PHPUnit + PHPStan L8 + Playwright E2E green
- **Explicit non-goals:** Doctrine DBAL 4 (Step 4.3), frontend changes, new features
- **Result**: Symfony 7.x; update ROADMAP stack reference and README badges.
- **Risk**: Medium–High — broad component surface.

---

## Step 4.3: Doctrine DBAL 3 → 4 Upgrade

- **Goal**: Upgrade `doctrine/dbal` from 3.x to 4.x on Pagekit's custom ORM layer.
- **Prerequisite**: Step 4.2 completed; Steps 2.1.11, 2.1.7, 2.10 completed
- **Tasks**:
  - Bump `doctrine/dbal` `^3.8` → `^4.0` in `composer.json`
  - Migrate breaking changes: type system, removed APIs (`requiresSQLCommentHint()`, type mappings), platform differences
  - Update `doctrine/migrations` compatibility if required
  - Audit custom DBAL usage: `Pagekit\Database\ORM`, `QueryBuilder`, connection wrappers, schema tools, migration runners
  - Full PHPUnit (incl. ORM integration paths from 2.1.9/2.10) + PHPStan green
  - Coordinate with Step 4.5 if tag-based cache invalidation touches DBAL types
- **Result**: DBAL 4.x on custom ORM.
- **Risk**: High — custom ORM + QueryBuilder + migrations.

---

## Step 4.4: REST API v2

- **Goal**: Modern REST API
- **Prerequisite**: Steps 4.2 and 4.3 preferred (API on final Symfony/DBAL stack); Step 2.1.10 presenters exist
- **Components**:
  - OpenAPI 3.0 Documentation
  - JWT Authentication
  - API Versioning
  - Rate Limiting
  - **Token scopes, including read-only scopes** — so an integration (or an agent) can be granted read access without write capability
  - **Outbound webhooks**: publish domain events to registered subscriber URLs with signing and retry. The existing Event Dispatcher is the seam — keep domain events stable rather than inventing a parallel notification path.
- **API serialization:** All API v2 responses must go through DI presenters/DTOs (`NodePresenter`, `PostPresenter`, and equivalents). Controllers that still return raw entities via `jsonSerialize()` (e.g. `UserApiController`, `RoleApiController`, `WidgetApiController`, `CommentApiController`) must move onto that path — do not add new endpoints that serialize entities directly.
- **Secrets:** API keys and tokens read from env / secret store — the mechanism itself ships with Step 2.5, which also migrates the hardcoded OpenWeatherMap key.

---

## Step 4.5: Performance Optimization

- **Goal**: Production-ready performance
- **Features**:
  - Redis/Memcached Support
  - Image Optimization — see **Step 4.8 (Native Image Pipeline & Media Manager)** for the full media pipeline (AVIF/WebP, responsive `<picture>`, focal point). 4.5 covers general asset-level optimization; Step 4.8 Sub-Step A can be pulled forward here if the PageSpeed win is wanted inside 2.0.0.
  - Asset Pipeline Optimization
  - Query Performance Tuning
  - **ORM Cache Invalidation Strategy** — `EntityManager::invalidateCache()` currently uses `$cache->clear()` (clears the entire cache pool on every `save()`/`delete()`). Replace with tag-based invalidation via `TagAwareCacheInterface` to only invalidate cache entries for the affected entity type. See: `app/modules/database/src/ORM/EntityManager.php` (in-code TODO tagged Step 4.5).
  - **`NodeRepository` request-cache invalidation:** `NodeRepository` keeps a per-request `ArrayAdapter(0, false)` cache (`find` / `findAll` / `findByMenu` with `$cached = true`) with **no invalidation on save**, so mutations can leave stale nodes in the same request. Decide and implement an invalidation strategy (tag-aware or explicit flush on `save`/`delete`).
  - **Role-lookup cache (optional):** `User::hasPermission()` resolves roles via a per-instance loader; there is no shared role cache. If profiling shows hot `hasPermission` / `findRoles` paths, add a scoped DI-backed cache (not a process-static).

---

## Step 4.6: Monitoring & Health Checks

- **Goal**: Production monitoring essentials
- **Components**:
  - Health Check Endpoints
  - Basic Application Metrics
  - Error Tracking (Sentry/Rollbar)
  - Performance Monitoring

---

## Step 4.7: Rebranding — Pagekit → Kernkit

- **Goal**: Give the CMS its own independent identity by renaming it from "Pagekit" to **Kernkit**. The MIT license covers the *code*, not the "Pagekit" name or logo — this establishes a trademark-safe, independent brand while keeping the required heritage attribution.
- **Prerequisite**: Phase 4 modernization complete.
- **Naming — one wordmark `Kernkit`** (first capital only; never `KernKit`, because inner capitals hurt SEO/typing and read dated on a wordmark):
  - **Code — `Kernkit`**: PHP namespace `Pagekit\` → `Kernkit\`, class/identifier prefixes, internal string identifiers. Keep it *consistent* — do not mix casings in code.
  - **Composer / Packagist / repo / org — lowercase `kernkit`** (Composer requires a lowercase vendor): `pagekit/*` → `kernkit/*`; new home `github.com/kernkit/kernkit` (+ `kernkit/docs`).
  - **Branding / logo / display name — `Kernkit`**: README title, UI branding, wordmark, marketing copy.
- **Scope (CMS only — public open-source rebrand):**
  - **Namespaces & code**: sweep `Pagekit\` → `Kernkit\` across `app/`, `packages/`, and tests; update the PSR-4 autoload prefix in `composer.json` and regenerate the autoloader; sweep remaining `pagekit` string identifiers (config keys, cookie/session names, cache namespaces) and the log file name (`pagekit.log` → `kernkit.log`).
  - **CLI**: rename the `pagekit` console binary/entry point to `kernkit` (behaviour identical; `php kernkit …`).
  - **composer.json**: package `name`, `description`, `homepage`, support URLs → `kernkit`.
  - **README.md & docs**: title (`# Pagekit CMS - Modernized` → Kernkit), badges, screenshot alt-text, clone/install URLs (`Shadesman5/pagekit` → `kernkit/kernkit`); fresh docs home at `kernkit/docs`.
  - **Branding assets**: create a new **Kernkit** logo/wordmark. **Do NOT reuse the original Pagekit logo** (copyright YOOtheme — only the code is MIT).
  - **Heritage attribution (keep!)**: retain the MIT copyright in `LICENSE` and add a README/footer note: _"Originally based on Pagekit CMS (MIT License)."_
  - **Verify**: full PHPUnit + Playwright E2E green after the namespace sweep; fresh install **and** upgrade path still boot; no stray `Pagekit` identifiers on live code paths (historical mentions in `CHANGELOG`/migration history may remain).
- **Structure note**: may be split into sub-steps if the single pass is too large — e.g. **4.7.1** namespace/code sweep, **4.7.2** composer + CLI + config identifiers, **4.7.3** README + docs + branding assets.
- **Legal note**: a trademark search for the chosen name is advisable before the public launch (DPMA/EUIPO) — out of scope for the code work here, tracked by the maintainer separately.
- **Risk**: Medium — mechanical but very wide-reaching (touches every namespaced file plus build/config/CLI). Mitigated by the green-test-suite gate.

---

## Step 4.8: Native Image Pipeline & Media Manager

- **Goal**: A fully automated, native image pipeline in the core. Editors upload high-resolution originals; the system automatically serves modern formats (AVIF/WebP), responsive `<picture>`/`srcset` variants (1x + 2x Retina), native lazy-loading, plus per-image **focal point** and **cover/contain** display control. Zero-config for end users, open-source dependencies only, non-destructive (originals stay untouched).
- **Status note**: This is a **net-new feature**, not legacy modernization, so it does **not** block the 2.0.0 "modernization complete" milestone. Placed at 4.8 = built after the 4.7 rebrand, i.e. natively in the `Kernkit\` namespace (no later rename needed). If the PageSpeed win is wanted inside 2.0.0, **Sub-Step A** (below) can be pulled forward into Step 4.5.
- **Greenfield warning**: The codebase currently has **no** server-side image processing (no GD/Imagick code, no library), **no** responsive-image output (`srcset`/`<picture>`), **no** media-metadata entity (images are plain path strings in a JSON `data` column), and **no** Symfony Messenger/queue. This step builds all of it. The clean integration points that already exist: the **Filesystem module** (`Pagekit\Filesystem` + `Locator` + `FileAdapter`, `storage:` prefix) and the **content-plugin pipeline** (`ContentHelper::applyPlugins()`, `content.plugins` event).

### Architecture principle — editor-independent (why this works without the Block Editor)

The `<picture>` markup is produced at **render time** by a new content plugin (`ImagePipelinePlugin`, registered with a priority that runs **after** `MarkdownPlugin`), **not** in the editor. Every editor (HTML, TinyMCE, CodeMirror, textarea) just stores a plain `<img>` / `![]()`; the plugin upgrades them all automatically. Focal point + display mode live centrally on the **media asset**, so they work without editor changes too. The Modern Block Editor (Step 5.1) later adds the nicest *per-placement* focal-point UX — an enhancement, **not** a prerequisite. → **Do not wait for 5.1.**

### Sub-steps

- **4.8 A — Backend engine + render helper (the core)**: integrate an image library (**`league/glide`** recommended — on-demand, cached, **no Messenger needed**; alternative `intervention/image` v3 with an upload trigger), storage/cache layout under `storage/media/`, size/format **presets**, the `<picture>` render helper, `ImagePipelinePlugin`, native `loading="lazy"` (+ `fetchpriority="high"` for hero images), an AVIF **capability check** with graceful WebP/JPEG fallback, and add `avif` to the finder upload whitelist. Editor-independent; delivers automatic optimization for the entire existing content base. *(Can ship as early as 4.5 if desired.)*
- **4.8 B — Media metadata + focal-point UI**: introduce a media-asset metadata model (alt text, `display_mode` = cover/contain, `focal_point` x/y in percent); extend `<InputImageMeta>` (`app/system/app/components/input-image-meta.vue`) with a display-mode toggle, a click-to-set focal-point selector, and a live preview. **Best after Vue 3 (Step 3.3)** for the elegant `<style> v-bind()` variant; a Vue 2.6 `:style`-binding version is possible earlier.
- **4.8 C — Block editor integration (optional enhancement)**: per-placement focal point / fit via `data-*` overrides, edited directly in the **Modern Block Editor (Step 5.1)**. Non-blocking.

### Security & caching (must-haves)

- **Signed transform URLs + fixed presets** (Glide HMAC) to prevent cache-flooding DoS from arbitrary `?w=…` params. The signing key comes from config/env, **never** hardcoded. Ties into Step 4.1.
- **Upload validation**: MIME sniffing (not just extension), dimension/size limits; SVG is never converted/scaled and is sanitized on delivery; animated GIF/WebP are passed through, not flattened.
- **Cache invalidation**: content-hash cache keys (`hash(origin bytes) + transform params`). Originals are immutable (a "changed image" = new upload = new key); a focal-point change needs **no** regeneration (pure CSS `object-position`). Add a `php pagekit image:cache:clear` command + a cleanup cron for orphaned variants.

### Constraints corrected from the initial concept (architecture mismatches to avoid)

- **`v-bind()` in CSS is Vue-3-only** → use `:style` object binding under Vue 2.6, or defer the polished UI to after Step 3.3.
- **No media entity exists today** → a media table (`pk_media`) is a new subsystem; use an **integer** id per Doctrine convention (a UUID would need a dedicated decision/ADR).
- **Symfony Messenger is not installed** → prefer on-demand (Glide) over async pre-generation to avoid introducing a queue dependency.
- **Do not hardcode `/media/…` URLs** → resolve via `UrlProvider`/`FileAdapter` + the `storage:` locator (base-path / sub-directory / CDN-safe).
- **AVIF is an environment dependency** (GD+libavif or Imagick+libheif), not just a library choice → capability check + graceful fallback.

- **Dependencies**: Filesystem module (present); Vue 3 (Step 3.3) for the polished UI; signed URLs relate to Step 4.1 (Security); overlaps the "Image Optimization" item in Step 4.5 (Performance).
- **Complexity**: High — a full greenfield subsystem spanning backend, rendering, and UI.

---

## Step 4.9: Cross-Repo Dev Dashboard & Real-Time Conductor Metrics

- **Goal**: Move modernization observability into a dedicated **`kernkit/dev-dashboard`** GitHub Pages site — Conductor metrics, CI quality, and roadmap progress **across all Kernkit repos**, visible **during feature-branch runs** (not only after merge to `develop`).
- **Prerequisite**: Step 4.7 (rebrand + clean repo split) completed; Conductor metrics pattern proven in the Pagekit playground repo.
- **Why Phase 4 (not earlier):**
  - Clean repo boundaries come with the 4.7 Kernkit split — avoid building cross-repo plumbing twice on the learning sandbox.
  - Real-time branch metrics matter most once Kernkit is the production core.
- **Architecture sketch:**
  - **Push model:** Conductor post-phase hook POSTs session deltas via **`gh api`** / `curl` to the dev-dashboard repo (`metrics/incoming/{repo}/{branch}/…`) — no wait for PR merge.
  - **Dashboard repo:** static/MkDocs site; ROADMAP tracking table + accordion metrics (same UX as today).
  - **Aggregation:** GitHub Action on dev-dashboard (`repository_dispatch` or incoming-folder merge) builds `index.json`.
  - **Auth — one GitHub App, not a PAT:** `contents: write` on dev-dashboard only, plus the pull-request permission the comment bot needs. It hands out short-lived installation tokens instead of a long-lived personal token, and unlike the built-in Actions token its activity triggers downstream workflows — the reason the metadata and project automation still depends on a personal access token today.
  - **Bot identity:** the author of an automated comment is the App slug, so the sticky quality-report comment posts as `kernkit[bot]` instead of `github-actions[bot]`, which is the fixed and unrenameable identity of the built-in Actions token. Reserve the slug together with the wordmark — App slugs are globally unique across GitHub.
- **Scope:** Port current `docs-site/` modernization pages; keep product docs in `kernkit/docs`; generalise backfill for any repo/workflow via `gh api`.
- **Non-goals:** Agents writing to dashboard; replacing `.cursor/ROADMAP.md` as agent SoT.
- **Result**: One URL for live metrics across repos/branches; intervene before the 360-minute GHA cap.
- **Risk**: Low–Medium — mostly JSON + Pages plumbing.

---

## Step 4.10: Cookie Consent & Privacy Baseline

- **Goal**: Give the system a GDPR/ePrivacy-compliant cookie-consent feature — essential for running EU/production sites. Provides a consent banner + preference center, script/cookie gating until consent, a server-side consent log for accountability, and an admin UI to manage cookie categories and entries. Ships as a **bundled first-party extension** (in `packages/`, installer-activated like `pagekit/blog` and `theme-one`): independently versioned and updatable, and enabled, disabled, or replaced **per site** — never forced on every install.
- **Prerequisite**: Steps 3.3 (Vue 3), 4.1 (CSP/Security Headers), 4.3 (DBAL 4), 4.4 (REST API v2), and 4.7 (Rebranding) completed.
- **Building blocks**: **orestbida/cookieconsent** (v3) as the vanilla-JS frontend engine; cookie definitions seeded from the **Open Cookie Database**.

### Scope

- **Consent banner + preference center** on the public site, styled with UIkit; runs client-side so page caching stays intact.
- **Script & cookie gating**: block non-essential scripts/cookies until explicit consent, grouped by category (necessary / functional / analytics / marketing …).
- **Consent log**: record consent-id, chosen categories, timestamp, and a hashed IP (no raw PII); configurable retention.
- **Cookie registry + admin UI** (Vue 3): manage categories and cookie entries, seeded from a bundled Open Cookie Database snapshot for auto-categorization.
- **Compliance defaults**: equal-weight Accept/Reject, no pre-ticked non-essential categories, scroll ≠ consent, a "manage preferences" revocation entry point, a consent-expiry cap, GPC signal honoring.
- **Google Consent Mode v2 wiring**: map consent categories to Google's consent signals (default-denied → granted on consent).

### Out of scope

- **IAB TCF v2.x**: interface/hook only — a site with a real IAB CMP registration binds its own TCF CMP.
- **Advanced add-ons** (automated cookie scanner, geo-targeting / per-jurisdiction rulesets, cookie-policy generator): separate opt-in extensions — see Step 5.2.1.

- **Result**: The CMS ships a working privacy baseline as a bundled extension; operators enable + configure it per site; advanced/ad-tech needs are served by additional extensions via documented seams.

---

## Step 4.11: Container Orchestration & Deployment

- **Goal**: Run the production image on an orchestrator — manifests / Helm chart, probes, scaling, and the shared-state work that multi-replica actually requires.
- **Why here**: Deferred out of Step 2.5, which builds and pushes the image but stops at a single-container compose. Orchestration depends on things Phase 4 delivers first: health endpoints for meaningful probes (4.6), a shared cache/session backend (4.5), and the final image name after the rebrand (4.7).
- **Prerequisite**: Steps 2.5 (production image + GHCR push), 4.5 (Redis cache/session), 4.6 (health check endpoints), 4.7 (Rebranding).
- **What**:
  - **Manifests / Helm chart**: Deployment, Service, Ingress, ConfigMap for application config, external Secret references — never secrets in manifests.
  - **Probes**: liveness / readiness (plus startup where boot is slow) wired to the 4.6 endpoints. A readiness probe that only checks TCP is worthless while migrations run.
  - **Scaling**: HorizontalPodAutoscaler hints; resource requests/limits derived from measured 4.5 numbers, not guesses.
  - **Shared state (the real blocker)**: `storage/` (uploads) and `tmp/` (cache, logs, packages) are node-local today. Decide PVC (ReadWriteMany) vs. object storage for uploads, and route sessions/cache to Redis instead of the filesystem — without this a second replica serves inconsistent state.
  - **Migrations**: init container or pre-upgrade hook; never during image build, never concurrently across replicas.
- **Out of scope**: Managed-service specifics (EKS/GKE/AKS provisioning), service mesh, GitOps tooling.
- **Risk**: Medium–High — the shared-state decision reaches into uploads, cache, and sessions.

---

## Step 4.12: Production Runtime Engine — FrankenPHP (or nginx + PHP-FPM)

- **Goal**: Replace Apache/mod_php with a modern app server serving the `public/` webroot that Step 2.4.1 already established. **FrankenPHP is the preferred direction** — a single binary with worker mode, native HTTPS, and its own static-file serving fits the project's lightweight-core philosophy (one glue point per concern) better than running nginx + PHP-FPM as two separate processes that must be kept in sync. nginx + PHP-FPM stays the fallback if FrankenPHP's PHP 8.5+ / worker-mode maturity has not held up by then.
- **Why here (after 4.11)**: The webroot **layout** question is already solved by Step 2.4.1, independently of the webserver **engine** — `public/` works under Apache today exactly as it will under FrankenPHP. What is left for this step is purely the engine swap, and sequencing that after 4.11 keeps orchestration risk isolated: manifests, probes, and the shared-state migration (PVC/object storage, Redis) get proven once on the known-working Apache setup before the web-serving layer itself changes (Rule 3: only one unproven thing changes at a time). Waiting this long also makes the swap cheaper: Step 3.2.1 already moves CSP out of `.htaccess`/`public/.htaccess` into a server-agnostic PHP `ResponseListener`, so the remaining header/rewrite set to port is small.
- **Prerequisite**: Step 4.11 (orchestration + shared-state groundwork); Step 4.7 (Rebranding — final namespace/image identity, so this lands once under the permanent name).
- **What**:
  - **Spike (re-verify, do not assume any earlier verdict still holds)**: FrankenPHP vs. nginx + PHP-FPM against current maturity data.
  - **Security parity**: port the remaining `public/.htaccess` header/rewrite set (HSTS, X-Frame-Options, Permissions-Policy, COOP/CORP, front-controller rewrite) to the chosen server's config — CSP itself is already framework-level via 3.2.1 and needs no porting.
  - **Orchestration follow-up**: adjust the 4.11 Helm chart / health-probe paths for the new entrypoint (FrankenPHP or PHP-FPM port/socket) — a mechanical follow-up on an already-working deployment, not a redesign.
  - **Docker**: retire the Step 2.5 Apache production stage once the new one is proven, or keep it as a documented fallback target (Architect decides) — no permanent dual-webserver maintenance burden (Rule 4, delete over wrap).
  - **Shared hosting / zip distribution (Step 2.9) is untouched**: it stays on Apache/PHP-FPM + the same `public/` webroot from 2.4.1 — this step only changes the container runtime.
- **Out of scope**: Redesigning the orchestration model itself (Helm structure, scaling policy, shared-state architecture) — that is 4.11's decision. Changing the webroot layout — already done by 2.4.1.
- **Risk**: Medium — mechanical porting work (the remaining headers, rewrites) against an already-green orchestrated baseline; the hard problems (shared state, health probes, CSP portability, webroot layout) are solved by the time this step starts.

---

## Step 4.13: Repo Topology Spike — Monolith vs Packages vs Multi-Repo

- **Goal**: Time-boxed Go/No-Go on whether the application repo stays a single tree, extracts Composer packages inside one repo, or splits into multiple repos — driven by agent context/token limits and CI blast radius.
- **What (spike only)**:
  - Map extractable seams (`app/modules`, `packages/*`) and cyclic coupling hotspots
  - Compare: (A) stay monolith + stricter module boundaries, (B) path-repo Composer packages, (C) multi-repo + Packagist/GH packages
  - Estimate agent/CI cost: context size, cross-repo PR choreography, version skew
  - Written Go/No-Go + follow-up tickets only if Go
- **Out of scope**: Executing a split inside this step; Agentic pipelines (**5.9**)
- **Risk**: Low for the spike; High if a premature split is forced
