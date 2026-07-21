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

| Step   | Description                       | Status |
| ------ | --------------------------------- | ------ |
| 2.1.1  | Tooling Setup & Baseline          | ✅     |
| 2.1.2  | CI/CD Integration & Quality Gates | ✅     |
| 2.1.3  | `strict_types` Migration          | ✅     |
| 2.1.4  | PHPStan Level 5→6 (Return Types)  | ✅     |
| 2.1.5  | PHPStan Level 6→7 (Null Safety)   | ✅     |
| 2.1.6  | PHPStan Level 7→8 (Strict Typing) | ✅     |
| 2.1.7  | QueryBuilder API Standardization  | ✅     |
| 2.1.8  | Infection Mutation Testing        | ✅     |
| 2.1.9  | Test Coverage Expansion           | ✅     |
| 2.1.10 | Entity Presentation Layer (DTO)   | ✅     |
| 2.1.11 | EntityManager DI (remove singleton) | ✅   |
| 2.1.12 | Residual `mixed` narrowing        | ✅     |
| 2.1.13 | TinyMCE Security Patch (~5.10.9)  | ⏳     |
| 2.1.14 | PHP Version Upgrade (8.2 → 8.5)   | ⏳     |

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
- **Forward**: Infection CI wiring → Step 2.2; MSI ratchet / wider scope → Step 2.9

---

### ✅ Step 2.1.9: Test Coverage Expansion

- **Goal**: Raise coverage (CI floor, Codecov, security/ORM edge cases) and grow it with every change.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-9-test-coverage-expansion.md`
- **Forward**: Breadth targets, packages coverage, remaining DB/kernel gaps, Infection widen → Step 2.9; full E2E rework → Step 3.6.1

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
- **Forward**: UrlResolver bridge → Step 2.5; raw-entity JSON → Step 4.4; ORM/request-cache invalidation → Step 4.5; residual `mixed` → Step 2.1.12

---

### ✅ Step 2.1.12: Residual `mixed` narrowing (typed properties & signatures)

- **Goal**: Narrow the last avoidable `mixed` sites to honest concrete types — no behaviour change; IDE/PHPStan clarity only.
- **Docs**: `migration-docs/branches/phase-2/step-2-1-12-residual-mixed-narrowing.md`
- **Out of scope (permanent)**: Legitimate `mixed` (docblock shapes, `__get`/`__set`, filter/loader/PSR-11, polymorphic returns, `callable` properties). Property-hooks path → Step 2.8.1.

---

### Step 2.1.13: TinyMCE Security Patch (~5.10.9)

- **Goal**: Patch TinyMCE 5.5.1 (EOL) with a minimal same-major bump to ~5.10.9 — not a full editor modernization.
- **Why**: Close known XSS/mXSS exposure in the admin editor with the smallest safe bump before broader CI/build work.
- **Issue**: GitHub #230 (sub-issue of #147)
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_13_TinyMCE-Security-Patch.md`
- **Source**: `migration-docs/audits/2026/04/DEPENDENCY-AUDIT-2026-04.md` §4.1
- **What**:
  - Bump `tinymce` in `package.json` `~5.5.1` → `~5.10.9`
  - Smoke-test admin editor; `yarn audit` before/after; `yarn compile-js --mode=production` + Playwright smoke
- **Out of scope**: TinyMCE 6+ (build/Vue track); remaining iframe XSS via CSP later
- **Land before**: Step 2.1.14 / 2.2
- **Risk**: Low

---

### Step 2.1.14: PHP Version Upgrade (8.2 → 8.5)

- **Status**: 📝 Draft — refine at ticket planning.
- **Goal**: Raise minimum PHP from 8.2 to **8.5** (current latest stable at planning time) with a full compatibility audit.
- **Why**: CI/Docker (2.2/2.3) and Closeout (2.9) must build on the final runtime once — avoid double-touch. Enables Step 2.8 language features.
- **Issue**: GitHub #231 (sub-issue of #147)
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_14_PHP-Version-Upgrade.md`
- **What**:
  - Bump `composer.json` `require.php` to `^8.5` and `config.platform.php` to `8.5.0`
  - Update every version SSoT consumer: CI matrix, `requirements.php`, `.cursor/Dockerfile`, README/badges, ROADMAP stack
  - `composer update` + resolve Dev-Tool bumps (CS-Fixer, PHPUnit, Infection, PHPStan plugins)
  - Deprecation cleanup of **own** code; quality gates green (PHPUnit, PHPStan L8, CS-Fixer, Infection scope, Playwright smoke)
- **Out of scope**: Symfony 7 / DBAL 4 (4.2/4.3); Property Hooks / Autowiring (2.8.x); coverage ratchet (2.9); feature-tourism syntax rewrites
- **Land before**: Steps 2.2, 2.3, 2.8, 2.9
- **Risk**: Medium

---

## Step 2.2: CI/CD Pipeline

- **Land after**: Step 2.1.14 (PHP matrix / images target 8.5)
- **Goal**: Reliable, fast quality gates on every PR; heavier jobs on merge/schedule; documented quality numbers (coverage, Infection, E2E counts, gate conclusions) come only from GitHub Actions — via sticky PR comment + `quality-snapshot.json` + MkDocs quality dashboard — never from agents writing metric tables into branch docs.
- **Why**: PRs need a trustworthy gate without multi-hour runs; agents burning tokens on coverage/Infection tables duplicates CI and drifts from truth; merge/`develop` still need fuller E2E and Infection.
- **Principles**:
  - **CI = SSoT for documented quality metrics** — no local/agent numbers in branch docs or LLM-formatted reports
  - **Tester / test-writer = gates only** — PASS/FAIL (and new test files); no metric handoff to doc-writer
  - **Branch doc = narrative** — what changed, deviations, deferrals; one link line to PR sticky comment + quality dashboard
  - **No LLM metrics formatting** — sticky comment and snapshot from CI artefacts via scripts
  - **JSON is the machine contract** — `.github/quality/quality-snapshot.json` feeds the MkDocs quality dashboard
- **What**:
  - **Workflow 1 — PHP Tests** (`php-tests.yml`, migrate from existing `php-quality.yml`): PHPUnit matrix (PHP × MySQL/SQLite), PHPStan, CS-Fixer dry-run, security audit, line-coverage ratchet
  - **Workflow 1b — Infection**: PR = diff-scoped on auth + user security core (≥ 80 % MSI, required); full suite daily on `develop`/`main` + `workflow_dispatch` (not required on PR)
  - **Workflow 2 — E2E smoke (PR)**: Playwright Chromium, **3 specs only** (installation, authentication, dashboard), **1 viewport** — required check; keeps PR signal high without full-suite cost
  - **Workflow 2a — E2E on merge**: push → `develop`/`main` — Chromium, growing suite, **3 viewports**
  - **Workflow 2b — Cross-browser E2E**: weekly (+ manual) Firefox + WebKit
  - **Workflow 3 — Frontend**: ESLint, Prettier, build verification per PR
  - **Workflow 4 — Quality reporting** (all in this step):
    - `quality-report.yml` — sticky PR comment from CI artefacts (idempotent marker); agents only link it
    - `quality-collect.yml` — on green merge to `develop`/`main`, write live `quality-snapshot.json` (`source: github-actions`); must not re-trigger PHP CI (`paths-ignore` / `[skip ci]`). Write the snapshot to an unprotected data branch (direct bot push to protected `develop` is blocked by the Ruleset — do not weaken the Ruleset with an Actions bypass)
    - MkDocs quality dashboard — already scaffolded; point it at the live snapshot; remove demo banner; align the PHPUnit matrix keys/labels in `docs-site/content/javascripts/quality-dashboard.js` + `docs-site/data/quality-snapshot.demo.json` with the PHP 8.5-only CI matrix (they still render 8.2/8.3 legs that no longer exist)
  - Required status checks + Ruleset alignment, dependency caching, release automation hooks as needed
  - Version SSoT guard (`composer.json` `require.php` → CI matrix, `requirements.php`, Dockerfile, README)
  - **Agent/rule slimming (same step)**: strip metric tables from branch-doc skeleton; orchestrator handoffs pass changed files + narrative deltas only (no verbatim Tester dumps to doc-writer)
- **Local (Conductor Tester)**: PHPUnit + PHPStan PASS/FAIL; same 3 E2E smoke specs on last Execute step + Finalize fix-loops — PASS/FAIL only, not documented as numbers
- **PR required**: PHPUnit matrix, PHPStan, CS-Fixer, security audit, coverage floor, Frontend, Infection diff, **E2E smoke (3 specs)**
- **Not required on PR**: full/merge E2E suite, Infection full, cross-browser weekly; Codecov non-blocking
- **Risks**: Infection-diff false greens → daily full suite; E2E flake on PR → keep smoke tiny and Chromium-only; snapshot loops → path filters + no PHP workflow on data-only pushes

---

## Step 2.3: Docker Production Setup

- **Goal**: Production-ready Docker images and Compose.
- **Why**: Reproducible deploys and a clear path toward orchestration.
- **What**: Multi-stage builds, Alpine images, Compose optimization, Kubernetes-ready layout

---

## Step 2.4: Build Tools Modernization

- **Goal**: Replace Yarn 1 + Webpack 4 + Gulp with **pnpm + Vite** (single frontend pipeline).
- **Why**: Prerequisite for Phase 3; one modern toolchain instead of three legacy ones.
- **What**:
  - pnpm as package manager; Vite for JS + LESS/assets; remove Webpack/Gulp/Yarn
  - ESLint 9 Flat Config; update CI, Docker, `AGENTS.md`
  - Dropping Webpack 4 removes its vulnerable locked transitives (picomatch, braces, micromatch, serialize-javascript, elliptic — the bulk of the JS audit findings); verify the advisory drop with a before/after dependency audit
  - Verify: `pnpm install && pnpm build` + Playwright smoke + PHPUnit green
- **Out of scope**: Webpack 5, Yarn Berry, Vue 3, TinyMCE 6+
- **Risk**: Medium–High

---

## Step 2.5: Extension Safety & Fault Isolation

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

## Step 2.6: Automated Update System — External & Background Updates

- **Goal**: Modern, future-proof update infrastructure for Pagekit CMS.
- **Why**: Long-term maintainability without manual release friction.
- **Priority**: High
- **Context**: `migration-docs/TODO/features/AUTOMATED_UPDATE_SYSTEM.md`

---

## Step 2.7: Filesystem Write Resilience — Atomic Writes & Error-Handling Hygiene

- **Goal**: One shared atomic-write primitive for boot-critical files; clean two silent/dead error-handling sites.
- **Why**: Non-atomic writes can corrupt `config.php` / package registry on crash or concurrent read; the routing cache already has the correct temp+rename pattern — extract and reuse it (no new dependency).
- **What**:
  - Add `Filesystem::dumpAtomic()` (temp + chmod + rename, with existing Windows fallback); unit-test it
  - Route `config.php` and package-registry writes through it; refactor `Router::writeCache()` to the same helper
  - Delete dead commented catch in `SelfupdateCommand`; log (don't swallow) invalid version constraints in Composer helper
- **Out of scope**: OpenWeatherMap API key → secrets (Step 4.4)
- **Recommended before**: Step 2.6; reusable by Step 2.5 fallback writes
- **Risk**: Low–Medium

---

## Step 2.8: PHP 8.4+ Language Adoption & DX Hardening

- **Status**: 📝 Draft — refine at ticket planning.
- **Prerequisite**: Step 2.1.14 (PHP 8.5). Prefer after 2.5 (routing/DI bridges clearer for Autowiring).
- **Goal**: Adopt useful PHP 8.4/8.5 language features and small DX hardenings **without** bloating the core — DNA gate on every sub-step.
- **Why here (not Phase 3/4):** Backend language/DI work belongs in Phase 2, on Symfony 6.4, **before** Vue (Phase 3) and before Symfony 7 / DBAL 4 (4.2/4.3). Closeout (2.9) then measures the hardened code.
- **Out of scope**: “Eliminate all `mixed`” mega-rewrite (legitimate `mixed` stays); Symfony/DBAL majors; new product features.
- **Risk**: Medium (2.8.1 may No-Go)

### Step 2.8.1: Property Hooks vs PropertyTrait

- **Goal**: Decide whether PHP 8.4 property hooks can replace (parts of) `PropertyTrait` magic accessors without hurting extension DX; implement only on **Go**.
- **Spike (what that means):** A **time-boxed investigation** — small prototype + written Go/No-Go — *before* a full migration. Not the migration itself. If No-Go, keep magic + Docblocks (permanent honest contract) and close the sub-step as decided.
- **DNA gate**: Core simpler to read? Extension DX ≥ today? Core stays light?
- **Risk**: Medium

### Step 2.8.2: Controller FQCN Autowiring

- **Goal**: Resolve controller dependencies by type (FQCN) instead of magic parameter-name / string bindings where it removes fragile wiring **without** adding a heavy DI framework layer.
- **Why after 2.5**: Extension Safety / routing factory DI reduces static bridges first.
- **Risk**: Medium

### Step 2.8.3: Fail-Fast / control-flow hygiene

- **Goal**: Replace known “tooling pacifiers” (e.g. `?? ''` magic defaults that hide null domain state) with honest nullable types or explicit validation/exceptions — targeted sweep, not a repo-wide rewrite.
- **Risk**: Low

---

## Step 2.9: Phase 2 Closeout — Test Coverage & Mutation Consolidation

- **Status**: 📝 Draft — refine at ticket planning.
- **Goal**: Phase 2 quality push — breadth coverage, wider Infection scope, data-driven MSI gates.
- **Why**: Per-branch coverage and auth/user mutation testing already exist; closeout raises the bar on the final PHP version (after 2.1.14) and after Language/DX work (2.8) when those land.
- **What**:
  - Clear remaining Infection defer markers (injectable-clock mutants, related ignores) if still present
  - Flip residual `phpunit.xml.dist` gates (`failOnDeprecation`, `failOnPhpunitDeprecation`, `failOnNotice`) to `"true"` after clearing leftover metadata deprecations / notice noise — the two known doc-comment metadata sites are `ConfigManagerTest::testGet` and `MigrationServiceTest` (migrate to PHPUnit attributes)
  - Evaluate a PHPUnit major upgrade (12/13) once the doc-comment metadata is migrated — PHPUnit 12 drops doc-comment metadata support, so the cleanup must land first; the runtime already satisfies 12 (PHP ≥ 8.3) and 13 (PHP ≥ 8.4.1)
  - Extend coverage: DB-bound paths (`UserProvider` happy paths, `UserListener`, uncached `hasPermission`), high-risk modules (ORM, filesystem)
  - Edge scenarios: large uploads, concurrent admin actions, DB connection failures
  - Bring `packages/` into measured coverage; raise blog (~60 %+) and theme-one (~30 %+)
  - Widen `infection.json.dist` past auth + user (data-integrity first); ratchet `minMsi` / `minCoveredMsi` from measured values
  - Remaining hygiene if still open: `assertEquals`→`assertSame` where strictness matters; controller DI-wiring / factory-service integration tests; `MigrationCommand` CLI pipeline coverage
- **Note**: Closeout consolidates ongoing coverage work — not a replacement for per-branch tests. **Not a hard Phase-2 end:** if new Phase-2 steps are discovered, insert them *before* 2.9 (ROADMAP SSoT) and keep Closeout last among Phase 2.
