# 🛠️ Phase 2: Developer Experience – Tools for Quality

**Goal**: Build testing, CI/CD, and developer tools.
**Important**: Can partially run in parallel with Phase 1!

## ✅ Step 2.0: Foundation Consolidation

Apply the aggressive modernization rules (defined during Phase 1 execution) retroactively to Phase 1 deliverables. Remove compatibility layers, eliminate wrappers, and harden the architecture before building developer tools on top.

- **Docs**: `migration-docs/branches/phase-2/step-2-0-foundation-closure.md`

### ✅ Step 2.0.0: Controller Annotations to PHP 8 Attributes Migration

- **Goal**: Migrate Doctrine Annotations to PHP 8 Attributes for all controllers
- **Docs**: `migration-docs/audits/2026/03/AUDIT_REPORT_STEP_2.0-2.0.2_2026-03-27.md` (2.0.0 was an audit with minimal fixes; controller attributes shipped in PR #111) + `CHANGELOG-NEW.md` § Pagekit 1.1.0

---

### ✅ Step 2.0.1: Full PSR-11 Container Modernization

- **Goal**: Fully modernize the container to a native PSR-11 container
- **Docs**: `migration-docs/branches/phase-2/step-2-0-1-psr-11-container/` (9 docs; main: `step-2-0-1-full-modernization.md`)

---

### ✅ Step 2.0.2: Validator-Translator Integration

- **Goal**: Connect Symfony Validator to Pagekit Translator so that validation error messages are returned in the active locale (instead of raw keys like `validation.user.username_required`)
- **Docs**: `migration-docs/branches/phase-1/step-1-13-validation-system.md` + `migration-docs/branches/phase-2/step-2-0-2-validation-phase2-discovery.md`

---

### ✅ Step 2.0.3: Full Cache API Modernization

- **Goal**: Completely replace Pagekit's own cache system (`CacheInterface`, `Psr6Adapter`, 5 adapter wrappers) with direct usage of Symfony Cache / PSR-6 `CacheItemPoolInterface`
- **Docs**: `migration-docs/branches/phase-2/step-2-0-3-full-cache-api-modernization.md`

---

### ✅ Step 2.0.4: Package/Migration System Redesign

- **Goal**: Complete redesign of the update and extension lifecycle system. Unify Doctrine Migrations and scripts.php hooks into a single pipeline. Lay the foundation for a future marketplace (Step 5.6).
- **Docs**: `migration-docs/branches/phase-2/step-2-0-4-package-migration-system-redesign.md`

---

### ✅ Step 2.0.5: Composer & Autoload Hygiene

- **Goal**: Clean up `composer.json` for reproducible builds, remove dead autoload mappings, resolve dependency anomalies, and prepare a healthy base for CI/CD (Step 2.2).
- **Docs**: `migration-docs/branches/phase-2/step-2-0-5-composer-autoload-hygiene.md`

---

### ✅ Step 2.0.6: Test Infrastructure Cleanup

- **Goal**: Consolidate PHPUnit configuration, migrate test annotations to PHP 8 attributes, remove legacy test imports, ensure all test files follow Phase 2 standards.
- **Docs**: `migration-docs/branches/phase-2/step-2-0-6-test-infrastructure-cleanup.md`

---

### ✅ Step 2.0.7: Event Dispatcher Bridge Removal

- **Goal**: Remove the unused `SymfonyEventDispatcherBridge` compatibility layer and its associated service registration and test. Pagekit's own Event Dispatcher (`on`/`trigger`/`subscribe`) remains the sole event system — it is deeply integrated, well-tested, and provides features Symfony's dispatcher does not (extra arguments, module manifest events, `PrefixEventDispatcher`).
- **Docs**: `migration-docs/branches/phase-2/step-2-0-7-event-bridge-removal.md`

---

### ✅ Step 2.0.8: Critical Hotfix — `User::hasAccess()` `create_function()` Removal

- **Goal**: Replace `create_function()` in `User::hasAccess()` with a PHP 8.2+-compatible implementation. `create_function()` was **removed in PHP 8.0** and causes a **Fatal Error** when boolean permission expressions (`and`/`or`) are evaluated.
- **Docs**: `migration-docs/branches/phase-2/step-2-0-8-user-hasaccess-hotfix.md`

---

## Step 2.1: Static Analysis & Code Quality Tools

- **Goal**: Code quality tooling and static analysis — tools for developers, core stays lightweight.
- **Open sub-steps**: 2.1.13 (TinyMCE), 2.1.14 (PHP 8.5). Completed work: see Docs under each ✅ row.

**Sub-steps Overview**:

| Step   | Description                         | Status |
| ------ | ----------------------------------- | ------ |
| 2.1.1  | Tooling Setup & Baseline            | ✅     |
| 2.1.2  | CI/CD Integration & Quality Gates   | ✅     |
| 2.1.3  | `strict_types` Migration            | ✅     |
| 2.1.4  | PHPStan Level 5→6 (Return Types)    | ✅     |
| 2.1.5  | PHPStan Level 6→7 (Null Safety)     | ✅     |
| 2.1.6  | PHPStan Level 7→8 (Strict Typing)   | ✅     |
| 2.1.7  | QueryBuilder API Standardization    | ✅     |
| 2.1.8  | Infection Mutation Testing          | ✅     |
| 2.1.9  | Test Coverage Expansion             | ✅     |
| 2.1.10 | Entity Presentation Layer (DTO)     | ✅     |
| 2.1.11 | EntityManager DI (remove singleton) | ✅     |
| 2.1.12 | Residual `mixed` narrowing          | ✅     |
| 2.1.13 | TinyMCE Security Patch (~5.10.9)    | ✅     |
| 2.1.14 | PHP Version Upgrade (8.2 → 8.5)     | ✅     |

---

### ✅ Step 2.1.1: Tooling Setup & Baseline

- **Goal**: Install quality tools, document baseline, PSR-12 formatting (without `strict_types`).
- **Docs**: No dedicated branch doc (early Workflow V1, PR #178) — partial notes in `CHANGELOG-NEW.md` § Pagekit 1.2.6

---

### ✅ Step 2.1.2: CI/CD Integration & Quality Gates

- **Goal**: Automatic quality checks for every PR.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-2-cicd-quality-gates.md`

---

### ✅ Step 2.1.3: `strict_types` Migration

- **Goal**: Add `declare(strict_types=1)` to all PHP files.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-3-strict-types-migration.md`

---

### ✅ Step 2.1.4: PHPStan Level 5→6 (Return Types)

- **Goal**: Raise PHPStan from Level 5 to Level 6.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-4-phpstan-level-6.md`

---

### ✅ Step 2.1.5: PHPStan Level 6→7 (Null Safety)

- **Goal**: Raise PHPStan from Level 6 to Level 7.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-5-phpstan-level-7.md`

---

### ✅ Step 2.1.6: PHPStan Level 7→8 (Strict Typing)

- **Goal**: Raise PHPStan from Level 7 to Level 8 (full type safety).
- **Docs**: `migration-docs/branches/phase-2/step-2-1-6-phpstan-level-8.md`

---

### ✅ Step 2.1.7: QueryBuilder API Standardization

- **Goal**: Standardize the DB layer API to Doctrine standards.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-7-querybuilder-api.md`

---

### ✅ Step 2.1.8: Infection Mutation Testing

- **Goal**: Mutation testing for the security-critical auth + user classes (80%+ MSI / Covered MSI).
- **Docs**: `migration-docs/branches/phase-2/step-2-1-8-infection-mutation-testing.md`
- **Forward**: Infection CI wiring → Step 2.2; MSI ratchet / wider scope → Step 2.11

---

### ✅ Step 2.1.9: Test Coverage Expansion

- **Goal**: Raise coverage (CI floor, Codecov, security/ORM edge cases) and grow it with every change.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-9-test-coverage-expansion.md`
- **Forward**: Breadth targets, packages coverage, remaining DB/kernel gaps, Infection widen → Step 2.11; full E2E rework → Step 3.6.1

---

### ✅ Step 2.1.10: Entity Presentation Layer (ModelServiceLocator → DTO/Presenter)

- **Goal**: Remove `ModelServiceLocator`; move URL/access/comment presentation off entities onto DI presenters.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-10-entity-presentation-layer.md`
- **Forward**: Remaining raw-entity API `jsonSerialize()` → presenters → Step 4.4

---

### ✅ Step 2.1.11: EntityManager DI — remove singleton (Active-Record → Data-Mapper)

- **Goal**: Replace static Active-Record model access with DI repositories / EntityManager; remove the EM singleton and boot hack.
- **Why**: Last process-global state in the model layer after presenters landed — persistence must be injectable and testable without process isolation.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-11-entitymanager-di.md`
- **Forward**: UrlResolver bridge → Step 2.7; raw-entity JSON → Step 4.4; ORM/request-cache invalidation → Step 4.5; residual `mixed` → Step 2.1.12

---

### ✅ Step 2.1.12: Residual `mixed` narrowing (typed properties & signatures)

- **Goal**: Narrow the last avoidable `mixed` sites to honest concrete types — no behaviour change; IDE/PHPStan clarity only.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-12-residual-mixed-narrowing.md`
- **Out of scope (permanent)**: Legitimate `mixed` (docblock shapes, `__get`/`__set`, filter/loader/PSR-11, polymorphic returns, `callable` properties). Property-hooks path → Step 2.10.1.

---

### ✅ Step 2.1.13: TinyMCE Security Patch (~5.10.9)

- **Goal**: Patch TinyMCE 5.5.1 (EOL) with a minimal same-major bump to ~5.10.9 — not a full editor modernization.
- **Why**: Close known XSS/mXSS exposure in the admin editor with the smallest safe bump before broader CI/build work.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-13-tinymce-security-patch.md`
- **Forward**: TinyMCE 6+ (build/Vue track); remaining iframe XSS via CSP → later security/CSP work

---

### ✅ Step 2.1.14: PHP Version Upgrade (8.2 → 8.5)

- **Goal**: Raise minimum PHP from 8.2 to **8.5** with a full compatibility audit across Composer, CI, Docker, and runtime guards.
- **Why**: CI/Docker (2.2/2.3/2.5) and Closeout (2.11) must build on the final runtime once — avoid double-touch. Enables Step 2.10 language features.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-14-php-version-upgrade.md`
- **Forward**: Symfony 7 / DBAL 4 → Steps 4.2/4.3; Property Hooks / Autowiring → Step 2.10.x; coverage ratchet → Step 2.11

---

## ✅ Step 2.2: CI/CD Pipeline

- **Goal**: Fast required gates on every PR, heavier jobs on merge/schedule, and quality numbers owned by CI (sticky PR comment + live snapshot + dashboard) instead of agent-written metric tables.
- **Result**: `php-tests.yml` (required PHPUnit/PHPStan/CS-Fixer/security + coverage ratchet, advisory `phpunit-mysql`, PR-only `version-ssot`), `infection.yml` diff gate, `frontend.yml` build gate, `e2e.yml` (PR smoke opt-in via `E2E_SMOKE_PR_ENABLED`, merge run feeding the snapshot), `nightly.yml`, `e2e-weekly.yml`, plus `quality-report.yml` + `quality-collect.yml` writing to the unprotected `quality-data` branch. Playwright selection is `@ci` tags + env-composed projects; agent handoffs and branch docs stay PASS/FAIL.
- **Docs**: `migration-docs/branches/phase-2/step-2-2-ci-cd-pipeline.md`
- **Forward**: MySQL leg to a required gate → Step 2.11; E2E quarantine lift, viewport-robust `@ci` specs and PR-smoke activation → Step 3.6.1; final Prettier/formatting policy → Step 2.4; image build/scan/push in CI → Step 2.5

---

## Step 2.3: Docker Developer Experience & Image Hygiene

- **Goal**: A clean, reproducible dev container workflow; fix the drift in the existing Docker artefacts. No production image.
- **What**:
  - **Extensions**: drop `xml`/`dom`/`xmlwriter`/`simplexml` (built-in on PHP 8.5) and reconcile the `Dockerfile` set with `.cursor/Dockerfile`; keep `pdo_mysql`, `pdo_sqlite`, `mbstring`, `gd`, `zip` and verify `exif`/`bcmath`/`pcntl` are actually used. `ext-intl` is not needed (no `NumberFormatter`/`ext-intl` usage).
  - **`.dockerignore`**: exclude non-runtime paths (`migration-docs/`, `tests/`, `docs-site/`, `.github/`, `.cursor/`, `*.md`, coverage/report artefacts).
  - **Dev compose**: MySQL `healthcheck` + `depends_on: condition: service_healthy`; document the SQLite (zero-DB) path.
  - **DB init**: remove the hardcoded password in `01-create-database.sql`; rely on the image `MYSQL_*` env.
  - **Env**: mark `docker.env.example` as dev-only.
  - **Docs**: align the Docker dev quickstart in `README` / `AGENTS.md`.
- **Out of scope**: multi-stage / production image, webserver change → Step 2.5; Vite pipeline → Step 2.4.
- **Risk**: Low.

---

## Step 2.4: Build Tools Modernization

- **Goal**: Replace Yarn 1 + Webpack 4 + Gulp with **pnpm + Vite** (single frontend pipeline).
- **Why**: Prerequisite for Phase 3; one modern toolchain instead of three legacy ones.
- **What**:
  - pnpm as package manager; Vite for JS + LESS/assets; remove Webpack/Gulp/Yarn
  - Minimal Vue 2.6.12 → **2.7.16** bump (pull-forward of the Step 3.2 version bump, approved 2026-07-24): the Vue-2.6-only `vite-plugin-vue2` is EOL (Vite ≤ 4), the official `@vitejs/plugin-vue2` requires Vue ≥ 2.7 — bump + plugin swap + compat verification only; Composition-API trials and deprecation analysis stay in Step 3.2
  - ESLint 9 Flat Config; update CI, Docker, `AGENTS.md`
  - Decide the final formatting policy: Prettier is pinned as an advisory devDependency and the `frontend` CI job only checks changed files (`continue-on-error`) because the tree carries ~13k pre-existing style violations — either format the tree once and make the check blocking, or drop Prettier
  - Dropping Webpack 4 removes its vulnerable locked transitives (picomatch, braces, micromatch, serialize-javascript, elliptic — the bulk of the JS audit findings); verify the advisory drop with a before/after dependency audit
  - Verify: `pnpm install && pnpm build` + Playwright smoke + PHPUnit green
- **Out of scope**: Webpack 5, Yarn Berry, Vue 3 / Composition-API adoption, TinyMCE 6+
- **Risk**: Medium–High

---

## Step 2.4.1: Webroot Modernization — Adopt `public/`

- **Depends on**: Step 2.4 (Vite/pnpm asset build) — this step targets the final build-output paths directly, so it must land first.
- **Goal**: Move the web-servable surface to a dedicated `public/` directory (the Symfony/Laravel convention) so that `app/`, `config.php`, `tmp/`, `vendor/`, and the non-public parts of `storage/` become **structurally** unreachable over HTTP — not just denied by `.htaccess` pattern-matching.
- **Why now (not later, and independent of the webserver engine)**: Step 2.4 already rewrites every asset-output path in the repository (17 module build configs); targeting `public/` directly from that rewrite is one pass instead of two. The webroot **layout** is independent of the webserver/runtime **engine** (Apache today; FrankenPHP is the Step 4.12 candidate) — `public/` works fine under Apache, exactly like it does for every Symfony/Laravel app on shared hosting today. Modern hosting panels (IONOS, most Plesk-based hosts) let you point the document root at a subdirectory directly; hosts that do not offer that setting still work via the well-established root-`.htaccess` rewrite fallback (`RewriteRule ^(.*)$ public/$1`). This does **not** narrow hosting compatibility.
- **What**:
  - **`public/index.php`** becomes the sole front controller — thin: resolve the app root one level up (`dirname(__DIR__)`), delegate to the existing `app/$env/app.php` boot chain unchanged.
  - **Build outputs land in `public/`**: Vite JS/CSS bundles, vendor asset copies (UIkit, TinyMCE, …), theme CSS — update the Step 2.4 build config's output targets accordingly (coordinate with 2.4 if it has not fully landed yet).
  - **`storage/` uploads**: expose only the public subset via a symlink (`public/storage → ../storage/...`, mirroring Laravel's `storage:link` convention) — never the whole `storage/` tree.
  - **`.htaccess` split**: move the front-controller rewrite + security headers (HSTS, X-Frame-Options, Permissions-Policy, COOP/CORP — CSP stays here until Step 3.2.1 moves it to a PHP `ResponseListener`) into `public/.htaccess`. Add a minimal root `.htaccess` with the `RewriteRule ^(.*)$ public/$1 [L,QSA]` fallback for hosts that cannot repoint their document root. Delete the now-structurally-redundant `<FilesMatch>` deny rules for files that simply no longer exist inside `public/`.
  - **Installer & self-updater**: audit `app/installer/` and any path assumption tied to `__DIR__` being the servable root; update to the new `public/` + app-root split.
  - **Docs**: `README.md` "Manual Installation" gets a "Shared hosting" subsection documenting both paths (document-root change vs. root-`.htaccess` fallback) — the counterpart to the zip-artefact side of the same story in Step 2.9.
- **Out of scope**: Changing the webserver/runtime engine — stays Apache here; the Apache-vs-FrankenPHP-vs-nginx+PHP-FPM decision is Step 4.12. This step is layout-only.
- **Risk**: Medium — touches the front controller and every static-asset path; mitigated by Step 2.4 having just rewritten those paths, and by full PHPUnit/PHPStan/Playwright E2E green as the gate.

---

## Step 2.5: Docker Production Image & Deploy

- **Depends on**: Step 2.4.1 (`public/` webroot) and Step 2.2 (CI for image build/scan/push).
- **Goal**: A small, hardened, immutable production image + a dedicated prod compose + image build/scan/push in CI.
- **What**:
  - **Multi-stage**: Composer `--no-dev --optimize-autoloader --classmap-authoritative`; Vite asset build (pnpm); minimal runtime stage carrying only built artefacts.
  - **Hardening**: non-root user; prod `php.ini` (`display_errors=Off`, `opcache.validate_timestamps=0`); `docker-compose.prod.yml` with restart policy and resource limits.
  - **Webserver**: stays **Apache**, matching the proven Step 2.3 dev baseline — the nginx + PHP-FPM vs. FrankenPHP evaluation is a deliberately separate decision, deferred to **Step 4.12** (informed by CSP moving to PHP middleware in 3.2.1 and by proven container orchestration in 4.11). No spike in this step.
  - **Webroot**: builds on the `public/`-only webroot already established by Step 2.4.1 — the image must ensure `app/`, `config.php`, `tmp/`, and anything outside `public/` are never served.
  - **Config & secrets (12-factor)**: read config, DB credentials, and secrets from env vars; `config.php` stays the default and env overrides it — lightweight, no Symfony secrets-vault. Never bake secrets into the image; env / secret-store only.
  - **First consumer**: move the hardcoded OpenWeatherMap API key in `app/system/modules/dashboard/index.php` onto that env path and rotate the committed key (in-code tag `AUDIT FIX Step 2.5`).
  - **CI**: Hadolint + Trivy + build & push to GHCR; container `HEALTHCHECK` (HTTP/TCP). Optional Redis for cache/session.
- **Out of scope**: Kubernetes/Helm, liveness/readiness probes, HPA, Ingress, PVCs, multi-replica → Step 4.11 (needs the 4.6 health endpoints and a shared-state decision for `storage/` / `tmp/`). Webserver/runtime engine modernization → Step 4.12.
- **Risk**: Medium.

---

## Step 2.6: Filesystem Write Resilience — Atomic Writes & Error-Handling Hygiene

- **Goal**: One shared atomic-write primitive for boot-critical files; clean two silent/dead error-handling sites.
- **Why**: Non-atomic writes can corrupt `config.php` / package registry on crash or concurrent read; the routing cache already has the correct temp+rename pattern — extract and reuse it (no new dependency).
- **What**:
  - Add `Filesystem::dumpAtomic()` (temp + chmod + rename; **atomic or throw** — no non-atomic `LOCK_EX` degrade); unit-test it
  - Route `config.php` and package-registry writes through it; refactor `Router::writeCache()` to the same helper
  - Delete dead commented catch in `SelfupdateCommand`; log (don't swallow) invalid version constraints in Composer helper
- **Provides**: the atomic-write primitive reused by Extension Safety (2.7) fallback writes and the Automated Update System (2.9) — hence sequenced before both.
- **Out of scope**: OpenWeatherMap API key → secrets (Step 2.5)
- **Risk**: Low–Medium

---

## Step 2.7: Extension Safety & Fault Isolation

- **Goal**: Prevent a faulty extension from taking down the whole CMS.
- **Why**: Today a throwable in extension `index.php` / `main()` whitescreens the kernel; admins must still reach the panel to disable the offender. Also needed before a third-party marketplace.
- **What**:
  1. Sandbox module load in `try/catch(\Throwable)` — registration is part of the window: it `include`s every `packages/*/*/index.php` on disk before anything is enabled, so a top-level throwable in a *disabled* extension still kills the boot and auto-disable alone does not protect against it. Harden that window inside the barrier; do **not** replace PHP discovery with a static manifest here (**2.7.3**)
  2. Log stack traces immediately via Monolog FileHandler (**must work without DB**)
  3. Auto-disable in DB (own try/catch) with a DB-less fallback file under a dedicated private path (prefer `tmp/system/`, own path key — never `storage/`, never `tmp/temp` / `path.cache`); boot checks both. A routine cache/temp clear must not sweep the record and silently re-enable a broken extension
  4. Admin notice on the next admin request, derived from the durable disable record — a session flash written at failure time auto-expires and usually lands in an anonymous visitor's session
  - Lifecycle interface + Blog `scripts.php` → lifecycle class; migrate install rollback on Throwable, including the double-fault case when migration rollback itself throws (log separately, keep the original error reportable, recovery must not throw uncaught)
  - **Routing dumper**: replace deprecated copied `PhpMatcherDumper` / `UrlGeneratorDumper` with Symfony compiled matcher/generator; keep blog permalink behaviour; prefer content-hash cache freshness over `filemtime`
  - **`UrlResolver` static bridge → DI**: remove `$cache` / `$module` / `$posts` setters and static `getPermalink()` (+ `RouteListener` callers) once routing factory supports DI
  - **`theme-one` template-helper statics**: the helpers are plain functions in a required file, so their URL provider is parked in a static property (`ThemeOneHelpers::$url`) — a different blocker than UrlResolver's `new $resolver()`, and it needs an injectable seam for template-level helpers
  - **`UniqueValidator`**: container-aware `ConstraintValidatorFactory`; delete static `setDb()` + boot wiring
- **Out of scope until a second caller**: extract `User::evaluateBooleanExpression()` only if another consumer appears
- **Sequencing**: before Snapshot (**2.7.1**), Dependency Integrity (**2.7.2**), Static Module Registration (**2.7.3**), Extension Packaging (**2.8**) and Marketplace (**5.6**)
- **Out of scope here**: full Snapshot/Backup UI and Three-Stage Uninstall retention — **Step 2.7.1**; module dependency graph fail-closed — **Step 2.7.2**; static discovery so inactive packages never execute PHP — **Step 2.7.3**

---

## Step 2.7.1: Snapshot & Three-Stage Uninstall

- **Depends on**: Step 2.6 (atomic writes), Step 2.7 (fault isolation / lifecycle seams).
- **Goal**: Automatic snapshots before destructive package operations, plus a three-stage uninstall path (disable → uninstall → purge after retention) with restore.
- **Why separate from 2.7**: Fault isolation (sandbox / auto-disable) is already a full step; dump/restore + retention UI would overload it. Updates (**2.9**) reuse the same snapshot primitive for rollback.
- **What**:
  - Snapshot store under `tmp/snapshots/` (never DocRoot): metadata, DB dump, config, optional files
  - Trigger before uninstall / major package ops; retention (e.g. 30 days) + one-click restore
  - Three-stage uninstall: disable → uninstall (code/data soft-removed) → purge after retention window
  - Admin UX for list / restore / purge
  - Admin feedback when a package's `disable` / `uninstall` lifecycle hook throws: the stage must still complete (an administrator must be able to leave a misbehaving package), but the panel must show that the hook failed and point at the log — today that failure is log-only and the action reports plain success
  - Theme circuit breaker: a failing theme is retried on every request today (never auto-disabled — recovery + per-request fallback). After N consecutive load failures, stop executing that theme and serve `theme-default` (or the existing blank fallback) until an administrator clears the failure / re-selects the theme — otherwise an expensive theme bug (memory exhaustion, hanging query) is a visitor-facing DoS
  - Harden `ExtensionFailureStore` against concurrent read-modify-write: `dumpAtomic()` prevents torn reads, but two workers that each `all()` → mutate → `write()` can lose one entry; serialize the RMW cycle (e.g. `flock`) so parallel failures and clears do not overwrite each other
  - This path is the **only** route for a removal that no one explicitly requested: automatic dependency cleanup (**5.0**) may deactivate, but any deletion it triggers goes through disable → uninstall → purge with a snapshot first, so the data stays restorable
- **Out of scope**: Marketplace signing; background update orchestration (**2.9**); process-level PHP sandboxing for enabled packages (Phase 5 §5.6 future candidate)
- **Risk**: Medium — DB dump portability (SQLite/MySQL), storage growth; theme circuit-breaker threshold must not strand a site that is mid-fix without a clear admin reset path

---

## Step 2.7.2: Module Dependency Integrity

- **Depends on**: Step 2.7 (fault isolation, auto-disable, durable failure record + admin notification — this step reuses that enforcement seam).
- **Goal**: Make the module dependency graph honest and answerable in both directions, so activation can never leave a half-wired application and no destructive package operation runs blind.
- **Why**: `ModuleManager::resolveModules()` skips a `require` entry that is not registered — silently. `load()` throws only for a directly requested unknown module name, so a missing dependency yields a partially booted application whose failure surfaces later as a missing service. `PackageManager::disable()` performs no dependency check at all. Both are tolerable while the `require` lists are maintained in code; they become a fault source the moment operators activate and deactivate modules themselves (Step 5.0).
- **What**:
  - **Fail closed on unsatisfied requirements**: an unregistered or inactive `require` entry must refuse the activation, or disable the dependent module and report it through the Step 2.7 admin notification (durable failure record surfaced on admin requests — not a session flash, which auto-expires and usually lands in an anonymous visitor's session). The failure must name the missing module — never a silent skip.
  - **Circular requirements at validation time**: `resolveModules()` already detects cycles but raises them during boot; surface them when a package is validated or activated instead.
  - **Reverse index**: derive `requiredBy` from the registered manifests so "what depends on this module" is answerable without scanning at call time.
  - **The active theme counts as a dependent**: the activation registry is two keys — the `extensions` list and `site.theme` (`SystemModule` loads `array_merge($this->config['extensions'], (array) $theme)`). A check that reads only `extensions` will happily disable a module the active theme requires and break the frontend.
  - **Pre-flight for destructive operations**: before disable or uninstall, report what would happen — active dependents that block it, modules that would be left orphaned, and whether the module owns tables or settings of its own (data risk). One query that both the admin UI and the API consume.
- **Out of scope**: Automatic removal of orphaned dependencies and the install-reason bookkeeping it needs (**5.0**); snapshots and retention for destructive operations (**2.7.1**); replacing PHP-executed package discovery (**2.7.3**).
- **Sequencing**: before **5.0** — operator-managed activation of core modules must not ship while unsatisfied dependencies stay quiet. Prefer before **2.7.3** so the graph hardens against the current registration model first; **2.7.3** then re-homes discovery without reopening the fail-closed rules.
- **Risk**: Low–Medium — one resolver behaviour change plus a read-only graph. The behaviour change can strand an installation whose manifests were already inconsistent, which is why the failure has to be explicit about the missing module.

---

## Step 2.7.3: Static Module Registration

- **Depends on**: Step 2.7 (fault barrier / auto-disable seams), prefer after Step 2.7.2 (dependency graph already fail-closed against registered names).
- **Goal**: Discover packages from static metadata so boot never executes PHP for inactive / on-disk-only packages; run module PHP (`index.php` / equivalent) only for packages that are actually being loaded.
- **Why**: The Step 2.7 registration barrier (per-include `try/catch (\Throwable)`) is still execution-to-discover: every on-disk package's top-level `index.php` runs, side effects included, and failures the engine does not raise as catchable throwables — fatal compile errors such as duplicate class/function declarations, `exit`/`die` at top level, resource exhaustion — still kill the boot. Before third-party packaging (**2.8**) and the marketplace (**5.6**) distribute arbitrary trees, discovery must not require executing those trees.
- **What**:
  1. Static package identity/metadata file (e.g. `module.json` beside `composer.json`, or a constrained subset of an existing manifest) carrying at least name and the fields registration needs before load — no PHP evaluation to learn that a package exists
  2. `ModuleManager::register()` (or its successor) reads static metadata for on-disk packages; `include` / require of executable module entry points is restricted to enabled packages (and core modules that must always load)
  3. Migrate first-party packages (`packages/pagekit/*`, `app/modules/*`, installer/system) onto the static manifest; delete any dual path that still discovers via blind `include` of every `index.php`
  4. Keep the Step 2.7 fault barrier for the remaining load / `main()` / lifecycle windows — static discovery removes the registration window's PHP hazard, it does not replace load isolation
  5. Package contract alignment: whatever shape 2.7.3 settles becomes required input for Extension Packaging (**2.8**)
- **Out of scope**: prebuilt JS/CSS author tooling and upload ZIP shape (**2.8**); marketplace signing (**5.6**); process-level sandboxing of enabled extension PHP (not Core / not 2.x debt — optional Phase 5 candidate after marketplace trust, see PHASE_5 §5.6 Future candidate)
- **Sequencing**: after 2.7 (+ preferably 2.7.2), before 2.8 — the packaging contract must not freeze `index.php`-as-discovery if this step is about to retire it
- **Risk**: High — boot discovery rewrite touching every module manifest; parse-error class of failures finally becomes containable for inactive packages

---

## Step 2.8: Extension Packaging & Prebuilt Assets

- **Depends on**: Step 2.4 (static Vite entry manifest), Step 2.7 (fault isolation); absorb the manifest shape from Step **2.7.3** when that step has landed (do not freeze `index.php`-only discovery in the published contract if 2.7.3 replaces it).
- **Goal**: One package shape for distributed extensions and themes — PHP/views plus **prebuilt** `app/bundle/*.js` and compiled CSS — valid for the admin upload today and for the marketplace later.
- **Why**: Bundle entries live in the first-party-only core manifest `scripts/bundle-entries.mjs`; no core build step produces a third-party bundle. A package that ships sources only has no build path at all — `pnpm build` never sees it, and target hosts have no Node. The admin upload (`admin/system/package/upload` → `PackageManager`) installs and enables such a package today, silently without its JS.
- **What**:
  1. Contract: required package layout (`composer.json` `type: pagekit-extension` / `pagekit-theme`, static module metadata from **2.7.3** once available / `index.php` until then, the lifecycle file — `extra.lifecycle` pointing to `lifecycle.php` (or another path the package names) that returns a `PackageLifecycleInterface` implementation and `require`s its class file itself, because at install time the package's own autoload is not yet registered — views, `app/bundle/*.js`, compiled CSS); the runtime globals a bundle may rely on (`Vue` / `UIkit` / `UIkit.util` as script-tag externals); what a package must never expect (core build step, core manifest entry, Node on the host). Rename the current `extra.scripts` / `scripts.php` names to `extra.lifecycle` / `lifecycle.php` in core readers, shipped packages (`app/system`, Blog), and the published contract — one key and one conventional filename only (no dual-key compat).
  2. Author-side build preset: a documented Vite config authors copy into their own package — same externals and IIFE output shape the core pipeline emits, so a package bundle behaves like a first-party one. Documentation only here: no published package, no core dependency.
  3. Author-side packaging: build + zip flow producing an upload-ready ZIP (build output in, sources and dev files out).
  4. A sample extension carrying a Vue bundle uploads, installs, enables and renders without any core build run; a source-only package fails with a clear diagnostic instead of a missing bundle.
  5. Drop the assumption that the core build serves third-party packages from `ArchiveCommand` / `BuildCommand` and the installer docs.
  6. Dependency declarations must agree: a package's Composer `require` (what must exist on disk, carrying the version constraints) and its module manifest `require` (what must be loaded first) may not contradict each other. Validate at packaging time, so an installed package cannot present a dependency graph the loader disagrees with.
  7. Webroot publication: only `public/` is served, and the core build publishes only in-repo packages — a runtime-installed/uploaded package has no publisher, so its bundles, CSS and icons are unreachable over HTTP. Package install/enable must copy the servable files (`app/bundle/*.js`, compiled CSS, icons/images) into the `public/` mirror and uninstall must remove them; `pagekit archive` must include built bundles from their `public/` location so a package ZIP is complete.
- **Out of scope**: marketplace API, host, catalogue and package signing (Step 5.6); the build preset as a published, versioned npm package (Step 5.7); rewriting boot discovery itself (Step 2.7.3).
- **Sequencing**: after 2.7 (and 2.7.3 when scheduled ahead of packaging), before 5.6 — the marketplace distributes against this contract.
- **Risk**: Low–Medium — contract, docs and author tooling; the only core code touch is the upload/install diagnostic.

---

## Step 2.9: Automated Update System — External & Background Updates

- **Goal**: Modern, future-proof update infrastructure for Pagekit CMS.
- **Why**: Long-term maintainability without manual release friction.
- **Priority**: High
- **Release automation (from Step 2.2)**: the CI side of the release — publish tags / GitHub releases and the machine-readable release metadata the updater consumes, so a version bump ends in a real release feed instead of a manual upload. Step 2.2 built quality gates only and left release hooks unrouted. The same release hook must also push **release-tagged container images** (semver + `latest`) to GHCR: the `docker-image` workflow publishes only moving `develop` / commit-SHA tags, so deployments have no stable image tag to pin until releases produce one.
- **Two distribution artifacts, one build, one webroot layout (no forked app code)**: since Step 2.4.1, both artifacts ship the **identical `public/`-webroot layout** — (1) **classic tarball/zip**: `composer install --no-dev --optimize-autoloader` + Vite build (`pnpm build`, post-2.4) output, zipped as-is, ready to unzip onto any Apache/PHP-FPM shared host — document root pointed at `public/` (most modern panels, incl. IONOS) or the root-`.htaccess` rewrite fallback from 2.4.1 for hosts that lock the document root. This stays the **default, widest-reach** distribution — today it is still a manual, undocumented step; CI-building it and attaching it to GitHub Releases is core scope here. (2) **container image** (Step 2.5, later Step 4.12 for the runtime-engine swap): the identical build, with `public/` copied into the image the same way. Both come from the same source tree, the same build commands, and now the same webroot layout — packaging is the only difference.
- **Webroot packaging details**: both artifacts must carry a complete `public/` tree — published assets plus the `public/storage` symlink. Plain zip extraction drops symlinks, so the classic artifact (or the installer/updater on first run) must recreate it; updates must also prune stale published files under `public/` (bundle and asset names change between releases, while the self-updater's clean pass covers only `app/`).
- **Atomic writes (from Step 2.6)**: reuse `Filesystem::dumpAtomic()` for PHP state the next boot `require`s (registry, manifests, dumped caches). If this step also writes non-PHP artefacts (zip payloads, checksums, JSON feeds, binary blobs), either keep those on a separate write path or extend `dumpAtomic()` so `opcache_invalidate()` runs only for `.php` targets — today every dumpAtomic write invalidates OPcache unconditionally because all current callers are PHP-only.
- **Context**: `migration-docs/TODO/features/AUTOMATED_UPDATE_SYSTEM.md`

---

## Step 2.10: PHP 8.4+ Language Adoption & DX Hardening

- **Status**: 📝 Draft — refine at ticket planning.
- **Prerequisite**: Step 2.1.14 (PHP 8.5). Prefer after 2.7 (routing/DI bridges clearer for Autowiring).
- **Goal**: Adopt useful PHP 8.4/8.5 language features and small DX hardenings **without** bloating the core — DNA gate on every sub-step.
- **Why here (not Phase 3/4):** Backend language/DI work belongs in Phase 2, on Symfony 6.4, **before** Vue (Phase 3) and before Symfony 7 / DBAL 4 (4.2/4.3). Closeout (2.11) then measures the hardened code.
- **Out of scope**: “Eliminate all `mixed`” mega-rewrite (legitimate `mixed` stays); Symfony/DBAL majors; new product features.
- **Risk**: Medium (2.10.1 may No-Go)

### Step 2.10.1: Property Hooks vs PropertyTrait

- **Goal**: Decide whether PHP 8.4 property hooks can replace (parts of) `PropertyTrait` magic accessors without hurting extension DX; implement only on **Go**.
- **Spike (what that means):** A **time-boxed investigation** — small prototype + written Go/No-Go — *before* a full migration. Not the migration itself. If No-Go, keep magic + Docblocks (permanent honest contract) and close the sub-step as decided.
- **DNA gate**: Core simpler to read? Extension DX ≥ today? Core stays light?
- **Risk**: Medium

### Step 2.10.2: Controller FQCN Autowiring

- **Goal**: Resolve controller dependencies by type (FQCN) instead of magic parameter-name / string bindings where it removes fragile wiring **without** adding a heavy DI framework layer.
- **Why after 2.7**: Extension Safety / routing factory DI reduces static bridges first.
- **Risk**: Medium

### Step 2.10.3: Fail-Fast / control-flow hygiene

- **Goal**: Replace known “tooling pacifiers” (e.g. `?? ''` magic defaults that hide null domain state) with honest nullable types or explicit validation/exceptions — targeted sweep, not a repo-wide rewrite.
- **Candidate**: Menu/Node tree root sentinel — top-level nodes carry `parent_id = 0`, resolved through a synthetic in-memory root (`MenuHelper::getRoot()` builds `$nodes[0]` with `parent_id = null`). Replace this magic-0 + synthetic-root construct with an explicit, typed root so traversal no longer mixes `0` and `null` to mean “root” — removes hidden null domain state and the null-array-offset bug class.
- **Candidate**: Clear the 3 PHP 8.4 `parameter.implicitlyNullable` baseline entries in `app/installer/src/Helper/InstallerIO.php` (Symfony Console `InputInterface`/`OutputInterface`/`HelperSet` params) by making them explicit `?Type` — enable the existing PHP-CS-Fixer `nullable_type_declaration_for_default_null_value` rule and drop the baseline ignores instead of carrying them (no new tooling — CS-Fixer already runs in CI).
- **Candidate**: PHPStan logic-hygiene baseline burndown — surgically fix (never `--generate-baseline`) the ~30 logic-level suppressions across production and tests: dead code (`deadCode.unreachable`), redundant/constant conditions (`*.alwaysTrue` / `*.alwaysFalse`, `instanceof.alwaysTrue/False`, `*.alreadyNarrowedType`), the unsafe `new static()` in `QueryBuilder`, and the `array.duplicateKey` in `StringTest` (likely a real test bug). Remove dead branches / redundant guards, then drop the matching baseline entries. Excludes the structural `variable.undefined` view + module-`index.php` bootstrap suppressions (separate scope decision, not yet homed).
- **Candidate**: Remove `extract()` from **production logic** (non-template code) — it fabricates local variables that defeat static analysis and drives ~29 baseline `variable.undefined` / `varTag.variableNotFound` suppressions. Replace with explicit typed locals / array destructuring at `app/modules/database/src/Query/QueryBuilder.php:681,713,735` (`extract($this->parts)` in SQL assembly), `app/modules/routing/src/Request/ParamFetcher.php:80`, `packages/pagekit/blog/src/Controller/PostApiController.php:61`, `packages/pagekit/blog/src/Controller/CommentApiController.php:66`, and `app/system/modules/user/src/Controller/UserApiController.php:55,116`, then surgically drop the matching baseline entries. The API controllers `extract()` user-supplied filter arrays (currently guarded by `EXTR_SKIP`); explicit typed access is safer and analyzable. This homes the production-logic subset the baseline-burndown candidate above excludes — the view/mail-template + `$app` bootstrap `variable.undefined` suppressions stay accepted-by-design per `phpstan.neon`.
- **Candidate**: Adopt the `#[\Override]` attribute (PHP 8.3+) on genuine overrides / interface implementations across `app/` — a compile-time net catching renamed or removed parent methods and signature drift. Mechanical, no behavior change; orthogonal to the baseline (it hardens correctness, it does not clear specific suppressions).
- **Risk**: Low

---

## Step 2.11: Phase 2 Closeout — Test Coverage & Mutation Consolidation

- **Status**: 📝 Draft — refine at ticket planning.
- **Goal**: Phase 2 quality push — breadth coverage, wider Infection scope, data-driven MSI gates.
- **Why**: Per-branch coverage and auth/user mutation testing already exist; closeout raises the bar on the final PHP version (after 2.1.14) and after Language/DX work (2.10) when those land.
- **What**:
  - Clear remaining Infection defer markers (injectable-clock mutants, related ignores) if still present
  - Flip residual `phpunit.xml.dist` gates (`failOnDeprecation`, `failOnPhpunitDeprecation`, `failOnNotice`) to `"true"` after clearing leftover metadata deprecations / notice noise — the two known doc-comment metadata sites are `ConfigManagerTest::testGet` and `MigrationServiceTest` (migrate to PHPUnit attributes)
  - Evaluate a PHPUnit major upgrade (12/13) once the doc-comment metadata is migrated — PHPUnit 12 drops doc-comment metadata support, so the cleanup must land first; the runtime already satisfies 12 (PHP ≥ 8.3) and 13 (PHP ≥ 8.4.1)
  - Extend coverage: DB-bound paths (`UserProvider` happy paths, `UserListener`, uncached `hasPermission`), high-risk modules (ORM, filesystem)
  - Edge scenarios: large uploads, concurrent admin actions, DB connection failures
  - Bring `packages/` into measured coverage; raise blog (~60 %+) and theme-one (~30 %+)
  - Make the PHPUnit suite DB-portable: most DB tests hardcode in-memory-SQLite connections instead of honoring the `$GLOBALS['db_*']` parameters that `DbUtil::getConnection()` already supports — route them through the shared helper so the whole suite genuinely runs against MySQL, then flip the non-blocking `phpunit-mysql` CI leg to a required gate (drop its `continue-on-error`). Why: a MySQL gate that exercises only a handful of tests gives false cross-DB confidence
  - Widen `infection.json.dist` past auth + user (data-integrity first); ratchet `minMsi` / `minCoveredMsi` from measured values
  - Remaining hygiene if still open: `assertEquals`→`assertSame` where strictness matters; controller DI-wiring / factory-service integration tests; `MigrationCommand` CLI pipeline coverage
- **Note**: Closeout consolidates ongoing coverage work — not a replacement for per-branch tests. **Not a hard Phase-2 end:** if new Phase-2 steps are discovered, insert them *before* 2.11 (ROADMAP SSoT) and keep Closeout last among Phase 2.
