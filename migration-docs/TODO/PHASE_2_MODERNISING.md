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
- **Forward**: Infection CI wiring → Step 2.2; MSI ratchet / wider scope → Step 2.10

---

### ✅ Step 2.1.9: Test Coverage Expansion

- **Goal**: Raise coverage (CI floor, Codecov, security/ORM edge cases) and grow it with every change.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-9-test-coverage-expansion.md`
- **Forward**: Breadth targets, packages coverage, remaining DB/kernel gaps, Infection widen → Step 2.10; full E2E rework → Step 3.6.1

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
- **Out of scope (permanent)**: Legitimate `mixed` (docblock shapes, `__get`/`__set`, filter/loader/PSR-11, polymorphic returns, `callable` properties). Property-hooks path → Step 2.9.1.

---

### ✅ Step 2.1.13: TinyMCE Security Patch (~5.10.9)

- **Goal**: Patch TinyMCE 5.5.1 (EOL) with a minimal same-major bump to ~5.10.9 — not a full editor modernization.
- **Why**: Close known XSS/mXSS exposure in the admin editor with the smallest safe bump before broader CI/build work.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-13-tinymce-security-patch.md`
- **Forward**: TinyMCE 6+ (build/Vue track); remaining iframe XSS via CSP → later security/CSP work

---

### ✅ Step 2.1.14: PHP Version Upgrade (8.2 → 8.5)

- **Goal**: Raise minimum PHP from 8.2 to **8.5** with a full compatibility audit across Composer, CI, Docker, and runtime guards.
- **Why**: CI/Docker (2.2/2.3/2.5) and Closeout (2.10) must build on the final runtime once — avoid double-touch. Enables Step 2.9 language features.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-14-php-version-upgrade.md`
- **Forward**: Symfony 7 / DBAL 4 → Steps 4.2/4.3; Property Hooks / Autowiring → Step 2.9.x; coverage ratchet → Step 2.10

---

## ✅ Step 2.2: CI/CD Pipeline

- **Goal**: Fast required gates on every PR, heavier jobs on merge/schedule, and quality numbers owned by CI (sticky PR comment + live snapshot + dashboard) instead of agent-written metric tables.
- **Result**: `php-tests.yml` (required PHPUnit/PHPStan/CS-Fixer/security + coverage ratchet, advisory `phpunit-mysql`, PR-only `version-ssot`), `infection.yml` diff gate, `frontend.yml` build gate, `e2e.yml` (PR smoke opt-in via `E2E_SMOKE_PR_ENABLED`, merge run feeding the snapshot), `nightly.yml`, `e2e-weekly.yml`, plus `quality-report.yml` + `quality-collect.yml` writing to the unprotected `quality-data` branch. Playwright selection is `@ci` tags + env-composed projects; agent handoffs and branch docs stay PASS/FAIL.
- **Docs**: `migration-docs/branches/phase-2/step-2-2-ci-cd-pipeline.md`
- **Forward**: MySQL leg to a required gate → Step 2.10; E2E quarantine lift, viewport-robust `@ci` specs and PR-smoke activation → Step 3.6.1; final Prettier/formatting policy → Step 2.4; image build/scan/push in CI → Step 2.5

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
  - ESLint 9 Flat Config; update CI, Docker, `AGENTS.md`
  - Decide the final formatting policy: Prettier is pinned as an advisory devDependency and the `frontend` CI job only checks changed files (`continue-on-error`) because the tree carries ~13k pre-existing style violations — either format the tree once and make the check blocking, or drop Prettier
  - Dropping Webpack 4 removes its vulnerable locked transitives (picomatch, braces, micromatch, serialize-javascript, elliptic — the bulk of the JS audit findings); verify the advisory drop with a before/after dependency audit
  - Verify: `pnpm install && pnpm build` + Playwright smoke + PHPUnit green
- **Out of scope**: Webpack 5, Yarn Berry, Vue 3, TinyMCE 6+
- **Risk**: Medium–High

---

## Step 2.5: Docker Production Image & Deploy

- **Depends on**: Step 2.4 (Vite asset build) and Step 2.2 (CI for image build/scan/push).
- **Goal**: A small, hardened, immutable production image + a dedicated prod compose + image build/scan/push in CI.
- **What**:
  - **Multi-stage**: Composer `--no-dev --optimize-autoloader --classmap-authoritative`; Vite asset build (pnpm); minimal runtime stage carrying only built artefacts.
  - **Hardening**: non-root user; prod `php.ini` (`display_errors=Off`, `opcache.validate_timestamps=0`); `docker-compose.prod.yml` with restart policy and resource limits.
  - **Webserver**: decide nginx + PHP-FPM vs. Apache vs. FrankenPHP (spike) — the driver is the cost of porting the root `.htaccess` (CSP, security headers, file protection, front-controller rewrites).
  - **Webroot**: DocumentRoot is the repo root (no `public/`) — the image must ensure `app/`, `storage/`, `config.php`, `tmp/` are never served.
  - **Config & secrets (12-factor)**: read config, DB credentials, and secrets from env vars; `config.php` stays the default and env overrides it — lightweight, no Symfony secrets-vault. Never bake secrets into the image; env / secret-store only.
  - **First consumer**: move the hardcoded OpenWeatherMap API key in `app/system/modules/dashboard/index.php` onto that env path and rotate the committed key (in-code tag `AUDIT FIX Step 2.5`).
  - **CI**: Hadolint + Trivy + build & push to GHCR; container `HEALTHCHECK` (HTTP/TCP). Optional Redis for cache/session.
- **Out of scope**: Kubernetes/Helm, liveness/readiness probes, HPA, Ingress, PVCs, multi-replica → Step 4.11 (needs the 4.6 health endpoints and a shared-state decision for `storage/` / `tmp/`).
- **Risk**: Medium.

---

## Step 2.6: Filesystem Write Resilience — Atomic Writes & Error-Handling Hygiene

- **Goal**: One shared atomic-write primitive for boot-critical files; clean two silent/dead error-handling sites.
- **Why**: Non-atomic writes can corrupt `config.php` / package registry on crash or concurrent read; the routing cache already has the correct temp+rename pattern — extract and reuse it (no new dependency).
- **What**:
  - Add `Filesystem::dumpAtomic()` (temp + chmod + rename, with existing Windows fallback); unit-test it
  - Route `config.php` and package-registry writes through it; refactor `Router::writeCache()` to the same helper
  - Delete dead commented catch in `SelfupdateCommand`; log (don't swallow) invalid version constraints in Composer helper
- **Provides**: the atomic-write primitive reused by Extension Safety (2.7) fallback writes and the Automated Update System (2.8) — hence sequenced before both.
- **Out of scope**: OpenWeatherMap API key → secrets (Step 2.5)
- **Risk**: Low–Medium

---

## Step 2.7: Extension Safety & Fault Isolation

- **Goal**: Prevent a faulty extension from taking down the whole CMS.
- **Why**: Today a throwable in extension `index.php` / `main()` whitescreens the kernel; admins must still reach the panel to disable the offender. Also needed before a third-party marketplace.
- **What**:
  1. Sandbox module load in `try/catch(\Throwable)`
  2. Log stack traces immediately via Monolog FileHandler (**must work without DB**)
  3. Auto-disable in DB (own try/catch) with `storage/disabled-extensions.json` fallback; boot checks both
  4. Admin flash on next login
  - Lifecycle interface + Blog `scripts.php` → lifecycle class; migrate install rollback on Throwable
  - **Routing dumper**: replace deprecated copied `PhpMatcherDumper` / `UrlGeneratorDumper` with Symfony compiled matcher/generator; keep blog permalink behaviour; prefer content-hash cache freshness over `filemtime`
  - **`UrlResolver` static bridge → DI**: remove `$cache` / `$module` / `$posts` setters and static `getPermalink()` (+ `RouteListener` callers) once routing factory supports DI
  - **`theme-one` static `UrlProvider`**: same DI blocker as UrlResolver — inject when template helpers support it
  - **`UniqueValidator`**: container-aware `ConstraintValidatorFactory`; delete static `setDb()` + boot wiring
- **Out of scope until a second caller**: extract `User::evaluateBooleanExpression()` only if another consumer appears
- **Sequencing**: before Marketplace (Step 5.6)

---

## Step 2.8: Automated Update System — External & Background Updates

- **Goal**: Modern, future-proof update infrastructure for Pagekit CMS.
- **Why**: Long-term maintainability without manual release friction.
- **Priority**: High
- **Release automation (from Step 2.2)**: the CI side of the release — publish tags / GitHub releases and the machine-readable release metadata the updater consumes, so a version bump ends in a real release feed instead of a manual upload. Step 2.2 built quality gates only and left release hooks unrouted.
- **Context**: `migration-docs/TODO/features/AUTOMATED_UPDATE_SYSTEM.md`

---

## Step 2.9: PHP 8.4+ Language Adoption & DX Hardening

- **Status**: 📝 Draft — refine at ticket planning.
- **Prerequisite**: Step 2.1.14 (PHP 8.5). Prefer after 2.7 (routing/DI bridges clearer for Autowiring).
- **Goal**: Adopt useful PHP 8.4/8.5 language features and small DX hardenings **without** bloating the core — DNA gate on every sub-step.
- **Why here (not Phase 3/4):** Backend language/DI work belongs in Phase 2, on Symfony 6.4, **before** Vue (Phase 3) and before Symfony 7 / DBAL 4 (4.2/4.3). Closeout (2.10) then measures the hardened code.
- **Out of scope**: “Eliminate all `mixed`” mega-rewrite (legitimate `mixed` stays); Symfony/DBAL majors; new product features.
- **Risk**: Medium (2.9.1 may No-Go)

### Step 2.9.1: Property Hooks vs PropertyTrait

- **Goal**: Decide whether PHP 8.4 property hooks can replace (parts of) `PropertyTrait` magic accessors without hurting extension DX; implement only on **Go**.
- **Spike (what that means):** A **time-boxed investigation** — small prototype + written Go/No-Go — *before* a full migration. Not the migration itself. If No-Go, keep magic + Docblocks (permanent honest contract) and close the sub-step as decided.
- **DNA gate**: Core simpler to read? Extension DX ≥ today? Core stays light?
- **Risk**: Medium

### Step 2.9.2: Controller FQCN Autowiring

- **Goal**: Resolve controller dependencies by type (FQCN) instead of magic parameter-name / string bindings where it removes fragile wiring **without** adding a heavy DI framework layer.
- **Why after 2.7**: Extension Safety / routing factory DI reduces static bridges first.
- **Risk**: Medium

### Step 2.9.3: Fail-Fast / control-flow hygiene

- **Goal**: Replace known “tooling pacifiers” (e.g. `?? ''` magic defaults that hide null domain state) with honest nullable types or explicit validation/exceptions — targeted sweep, not a repo-wide rewrite.
- **Candidate**: Menu/Node tree root sentinel — top-level nodes carry `parent_id = 0`, resolved through a synthetic in-memory root (`MenuHelper::getRoot()` builds `$nodes[0]` with `parent_id = null`). Replace this magic-0 + synthetic-root construct with an explicit, typed root so traversal no longer mixes `0` and `null` to mean “root” — removes hidden null domain state and the null-array-offset bug class.
- **Candidate**: Clear the 3 PHP 8.4 `parameter.implicitlyNullable` baseline entries in `app/installer/src/Helper/InstallerIO.php` (Symfony Console `InputInterface`/`OutputInterface`/`HelperSet` params) by making them explicit `?Type` — enable the existing PHP-CS-Fixer `nullable_type_declaration_for_default_null_value` rule and drop the baseline ignores instead of carrying them (no new tooling — CS-Fixer already runs in CI).
- **Candidate**: PHPStan logic-hygiene baseline burndown — surgically fix (never `--generate-baseline`) the ~30 logic-level suppressions across production and tests: dead code (`deadCode.unreachable`), redundant/constant conditions (`*.alwaysTrue` / `*.alwaysFalse`, `instanceof.alwaysTrue/False`, `*.alreadyNarrowedType`), the unsafe `new static()` in `QueryBuilder`, and the `array.duplicateKey` in `StringTest` (likely a real test bug). Remove dead branches / redundant guards, then drop the matching baseline entries. Excludes the structural `variable.undefined` view + module-`index.php` bootstrap suppressions (separate scope decision, not yet homed).
- **Candidate**: Remove `extract()` from **production logic** (non-template code) — it fabricates local variables that defeat static analysis and drives ~29 baseline `variable.undefined` / `varTag.variableNotFound` suppressions. Replace with explicit typed locals / array destructuring at `app/modules/database/src/Query/QueryBuilder.php:681,713,735` (`extract($this->parts)` in SQL assembly), `app/modules/routing/src/Request/ParamFetcher.php:80`, `packages/pagekit/blog/src/Controller/PostApiController.php:61`, `packages/pagekit/blog/src/Controller/CommentApiController.php:66`, and `app/system/modules/user/src/Controller/UserApiController.php:55,116`, then surgically drop the matching baseline entries. The API controllers `extract()` user-supplied filter arrays (currently guarded by `EXTR_SKIP`); explicit typed access is safer and analyzable. This homes the production-logic subset the baseline-burndown candidate above excludes — the view/mail-template + `$app` bootstrap `variable.undefined` suppressions stay accepted-by-design per `phpstan.neon`.
- **Candidate**: Adopt the `#[\Override]` attribute (PHP 8.3+) on genuine overrides / interface implementations across `app/` — a compile-time net catching renamed or removed parent methods and signature drift. Mechanical, no behavior change; orthogonal to the baseline (it hardens correctness, it does not clear specific suppressions).
- **Risk**: Low

---

## Step 2.10: Phase 2 Closeout — Test Coverage & Mutation Consolidation

- **Status**: 📝 Draft — refine at ticket planning.
- **Goal**: Phase 2 quality push — breadth coverage, wider Infection scope, data-driven MSI gates.
- **Why**: Per-branch coverage and auth/user mutation testing already exist; closeout raises the bar on the final PHP version (after 2.1.14) and after Language/DX work (2.9) when those land.
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
- **Note**: Closeout consolidates ongoing coverage work — not a replacement for per-branch tests. **Not a hard Phase-2 end:** if new Phase-2 steps are discovered, insert them *before* 2.10 (ROADMAP SSoT) and keep Closeout last among Phase 2.
