# 🛠️ Phase 2: Developer Experience – Tools for Quality

**Goal**: Build testing, CI/CD, and developer tools.
**Important**: Can partially run in parallel with Phase 1!

## ✅ Step 2.0: Foundation Consolidation

Apply the aggressive modernization rules (defined during Phase 1 execution) retroactively to Phase 1 deliverables. Remove compatibility layers, eliminate wrappers, and harden the architecture before building developer tools on top.

- **Prerequisite**: Phase 1 completed
- **Docs**: `migration-docs/branches/phase-2/step-2-0-foundation-closure.md`

### ✅ Step 2.0.0: Controller Annotations to PHP 8 Attributes Migration

- **Goal**: Migrate Doctrine Annotations to PHP 8 Attributes for all controllers
- **Prerequisite**: Step 1.14 (Doctrine Attributes) completed
- **Docs**: `migration-docs/audits/2026/03/AUDIT_REPORT_STEP_2.0-2.0.2_2026-03-27.md` (2.0.0 was an audit with minimal fixes; controller attributes shipped in PR #111) + `CHANGELOG-NEW.md` § Pagekit 1.1.0

---

### ✅ Step 2.0.1: Full PSR-11 Container Modernization

- **Goal**: Fully modernize the container to a native PSR-11 container
- **Prerequisite**: Step 1.14 (Doctrine Attributes) completed
- **Docs**: `migration-docs/branches/phase-2/step-2-0-1-psr-11-container/` (9 docs; main: `step-2-0-1-full-modernization.md`)

---

### ✅ Step 2.0.2: Validator-Translator Integration

- **Goal**: Connect Symfony Validator to Pagekit Translator so that validation error messages are returned in the active locale (instead of raw keys like `validation.user.username_required`)
- **Prerequisite**: Step 2.0.1 (PSR-11 Container) completed
- **Docs**: `migration-docs/branches/phase-1/step-1-13-validation-system.md` + `migration-docs/branches/phase-2/step-2-0-2-validation-phase2-discovery.md`

---

### ✅ Step 2.0.3: Full Cache API Modernization

- **Goal**: Completely replace Pagekit's own cache system (`CacheInterface`, `Psr6Adapter`, 5 adapter wrappers) with direct usage of Symfony Cache / PSR-6 `CacheItemPoolInterface`
- **Prerequisite**: Step 2.0.2 (Validator-Translator Integration) completed
- **Docs**: `migration-docs/branches/phase-2/step-2-0-3-full-cache-api-modernization.md`

---

### ✅ Step 2.0.4: Package/Migration System Redesign

- **Goal**: Complete redesign of the update and extension lifecycle system. Unify Doctrine Migrations and scripts.php hooks into a single pipeline. Lay the foundation for a future marketplace (Step 5.6).
- **Prerequisite**: Step 2.0.3 (Full Cache API Modernization) completed
- **Docs**: `migration-docs/branches/phase-2/step-2-0-4-package-migration-system-redesign.md`

---

### ✅ Step 2.0.5: Composer & Autoload Hygiene

- **Goal**: Clean up `composer.json` for reproducible builds, remove dead autoload mappings, resolve dependency anomalies, and prepare a healthy base for CI/CD (Step 2.2).
- **Prerequisite**: Step 2.0.4 (Package/Migration System Redesign) completed
- **Docs**: `migration-docs/branches/phase-2/step-2-0-5-composer-autoload-hygiene.md`

---

### ✅ Step 2.0.6: Test Infrastructure Cleanup

- **Goal**: Consolidate PHPUnit configuration, migrate test annotations to PHP 8 attributes, remove legacy test imports, ensure all test files follow Phase 2 standards.
- **Prerequisite**: Step 2.0.5 (Composer & Autoload Hygiene) completed
- **Docs**: `migration-docs/branches/phase-2/step-2-0-6-test-infrastructure-cleanup.md`

---

### ✅ Step 2.0.7: Event Dispatcher Bridge Removal

- **Goal**: Remove the unused `SymfonyEventDispatcherBridge` compatibility layer and its associated service registration and test. Pagekit's own Event Dispatcher (`on`/`trigger`/`subscribe`) remains the sole event system — it is deeply integrated, well-tested, and provides features Symfony's dispatcher does not (extra arguments, module manifest events, `PrefixEventDispatcher`).
- **Prerequisite**: Step 2.0.6 (Test Infrastructure Cleanup) completed
- **Docs**: `migration-docs/branches/phase-2/step-2-0-7-event-bridge-removal.md`

---

### ✅ Step 2.0.8: Critical Hotfix — `User::hasAccess()` `create_function()` Removal

- **Goal**: Replace `create_function()` in `User::hasAccess()` with a PHP 8.2+-compatible implementation. `create_function()` was **removed in PHP 8.0** and causes a **Fatal Error** when boolean permission expressions (`and`/`or`) are evaluated.
- **Prerequisite**: None — this is a **critical runtime fix** that can be executed at any point.
- **Docs**: `migration-docs/branches/phase-2/step-2-0-8-user-hasaccess-hotfix.md`

---

## Step 2.1: Static Analysis & Code Quality Tools

- **Goal**: Comprehensive code quality tools and static analysis
- **Prerequisite**: Step 1.14 (Doctrine Attributes) completed
- **Time Estimate**: Split into 11 sub-steps
- **Pagekit Principle**: Tools for developers, core stays lightweight!

**Modernization Targets**:

| Metric                    | Current State     | Target                        |
| ------------------------- | ----------------- | ----------------------------- |
| PHP files (excl. vendor)  | ~750-770          | all modernized                |
| Files with `strict_types` | ~113 (~15%)       | 100%                          |
| Files with return types   | ~15%              | ~100% (PHPStan Level 8)       |
| Typed properties          | ~15%              | ~100%                         |
| Test files                | 39 (~200 methods) | 80%+ coverage core            |
| CI/CD for tests           | not available     | complete pipeline             |
| PHPStan                   | not installed     | Level 8                       |
| Infection                 | not installed     | 80%+ score (critical modules) |

> ⚠️ **Why split up?** The original plan (one block) was unrealistic.
> Adding `strict_types` to hundreds of files is not a style fix — it changes runtime behavior
> and can cause `TypeError` exceptions. PHPStan on a largely untyped codebase will report
> hundreds of errors. Each sub-step is independently testable and committable.

**Sub-steps Overview**:

| Step   | Description                       | Time Estimate | Risk        |
| ------ | --------------------------------- | ------------- | ----------- |
| 2.1.1  | Tooling Setup & Baseline          | 1-2 days      | Low         |
| 2.1.2  | CI/CD Integration & Quality Gates | 1-2 days      | Low         |
| 2.1.3  | `strict_types` Migration          | 3-5 days      | Medium-High |
| 2.1.4  | PHPStan Level 5→6 (Return Types)  | 1-2 days      | Low         |
| 2.1.5  | PHPStan Level 6→7 (Null Safety)   | 1-2 days      | Medium      |
| 2.1.6  | PHPStan Level 7→8 (Strict Typing) | 1-2 days      | Medium      |
| 2.1.7  | QueryBuilder API Standardization  | 1-2 days      | Low         |
| 2.1.8  | Infection Mutation Testing        | 2-3 days      | Low         |
| 2.1.9  | Test Coverage Expansion           | Ongoing       | Low         |
| 2.1.10 | Entity Presentation Layer (DTO)   | 2-3 days      | Medium-High |

---

### ✅ Step 2.1.1: Tooling Setup & Baseline

- **Goal**: Install quality tools, document baseline, PSR-12 formatting (without `strict_types`)
- **Prerequisite**: Step 1.14 (Doctrine Attributes) completed
- **Docs**: No dedicated branch doc (early Workflow V1, PR #178) — partial notes in `CHANGELOG-NEW.md` § Pagekit 1.2.6

---

### ✅ Step 2.1.2: CI/CD Integration & Quality Gates

- **Goal**: Automatic quality checks for every PR
- **Prerequisite**: Step 2.1.1 (Tooling Setup) completed
- **Docs**: `migration-docs/branches/phase-2/step-2-1-2-cicd-quality-gates.md`

---

### ✅ Step 2.1.3: `strict_types` Migration

- **Goal**: Add `declare(strict_types=1)` to all PHP files
- **Prerequisite**: Step 2.1.2 (CI/CD) completed (so regressions are caught immediately)
- **Docs**: `migration-docs/branches/phase-2/step-2-1-3-strict-types-migration.md`

---

### ✅ Step 2.1.4: PHPStan Level 5→6 (Return Types)

- **Goal**: Raise PHPStan from Level 5 to Level 6
- **Prerequisite**: Step 2.1.3 (`strict_types` Migration) completed
- **Docs**: `migration-docs/branches/phase-2/step-2-1-4-phpstan-level-6.md`

---

### ✅ Step 2.1.5: PHPStan Level 6→7 (Null Safety)

- **Goal**: Raise PHPStan from Level 6 to Level 7
- **Prerequisite**: Step 2.1.4 (PHPStan Level 6) completed
- **Docs**: `migration-docs/branches/phase-2/step-2-1-5-phpstan-level-7.md`

---

### ✅ Step 2.1.6: PHPStan Level 7→8 (Strict Typing)

- **Goal**: Raise PHPStan from Level 7 to Level 8 (full type safety)
- **Prerequisite**: Step 2.1.5 (PHPStan Level 7) completed
- **Docs**: `migration-docs/branches/phase-2/step-2-1-6-phpstan-level-8.md`

---

### ✅ Step 2.1.7: QueryBuilder API Standardization

- **Goal**: Standardize the DB layer API to Doctrine standards
- **Prerequisite**: Step 2.1.2 (CI/CD) completed
- **Docs**: `migration-docs/branches/phase-2/step-2-1-7-querybuilder-api.md`

---

### Step 2.1.8: Infection Mutation Testing

- **Goal**: Introduce mutation testing for the security-critical **classes** of the auth + user modules
- **Prerequisite**: Step 2.1.6 (PHPStan Level 8) completed. A coverage driver (Xdebug/PCOV) must be available — Infection cannot run without one. **Note:** the "60%+ coverage" target is **not** a hard pre-existing prerequisite — it is **not yet met** for these modules, so this step **writes the missing security-core unit tests itself** (mutation testing needs a test base; killing a mutant = adding a targeted test).
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_8_Infection-Mutation-Testing.md`
- **Tasks**:
  - Install Infection (`infection/infection`, ≥ 0.29 for PHPUnit 11) + configure `infection.json.dist`
  - Scope to security-critical **classes only** (not whole modules):
    - `app/modules/auth/src` (`Auth`, `DatabaseHandler`, `NativePasswordEncoder`)
    - `app/system/modules/user/src` → `Model` (`User`, `Role`), `Auth` (`UserProvider`), `Event` (`AccessListener`, `AuthorizationListener`)
    - NOT the 7 user controllers (→ 2.1.9), NOT views/templates/markdown (too slow, not critical)
  - Write the missing unit tests for the untested security core (esp. `NativePasswordEncoder`, `UserProvider::validateCredentials`, `DatabaseHandler::read/destroy`, `Role`, authorization listeners)
  - Target: 80%+ MSI **and** 80%+ Covered MSI on the configured classes
  - CI wiring is **deferred to Step 2.2** (non-blocking scheduled/manual job) — not added here
- **Result**: Security-critical code is verified through mutation testing; the auth + user security core gains a real unit-test baseline
- **Risk**: Low — test tooling + tests only, no production changes to satisfy the tool (a mutant exposing a real bug is a finding, fixed/flagged). **Effort**: Medium (writes a test baseline, not just a tool install)

---

### Step 2.1.9: Test Coverage Expansion

- **Goal**: Systematically raise test coverage to target levels
- **Prerequisite**: Step 2.1.2 (CI/CD with coverage reports) completed
- **Time Estimate**: Ongoing (parallel to all further Phase 2 steps)
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_9_Test-Coverage-Expansion.md`
- **Phase 1 audit:** Step 2.1.9 does **not** flip a Phase-1 audit cell. The E2E rework that resolves **Step 1.10.5 (E2E Testing with Playwright) ⚠️ → 🛡️** is a large, orthogonal effort (full Playwright rework; only ~3 of the 11 specs are sound) and has been **re-homed to Step 3.6.1 (E2E Test Suite Rework)**, where it adopts the Step 3.6 `data-testid` selector strategy — 1.10.5 therefore flips to 🛡️ when 3.6.1 lands, not here (the audit-closure line lives in the Step 3.6.1 section of `PHASE_3_MODERNISING.md`). 2.1.9 still picks up the cross-cutting `MenuApiController` manual-validation finding (carryover from the 1.13 audit, **not** a 1.13 audit-cell closure).
- **Tasks**:
  - Coverage targets:
    - Core Modules (`app/modules/`): 80%+
    - System Modules (`app/system/modules/`): 75%+
    - Packages (`packages/`): 60%+
  - Write tests for affected modules with each step
  - Track coverage trends in CI (HTML reports); CI `phpunit` job emits `--coverage-text --coverage-clover` (established by Step 2.1.2)
  - **Pin a minimum line-coverage threshold in CI** — fail PRs that drop below the Architect-defined baseline; ratchet upward over time, never below
  - **Wire Codecov/Coveralls** — README coverage badge + per-PR coverage-delta comments
  - Edge-case tests for real Pagekit scenarios:
    - Large file uploads (Storage Module)
    - Concurrent admin actions (Session Handling)
    - Database connection failures (ORM Error Handling)
    - ORM query-cache invalidation (data-integrity, not optional): verify `EntityManager::save()`/`delete()` actually evict cached query results (`invalidateCache()` → `$cache->clear()`, `app/modules/database/src/ORM/EntityManager.php`). Regression net for the planned Step 4.3 tag-based-invalidation refactor
    - `AddRelNofollowFilter` XSS edge cases: Harden filter + activate 3 disabled tests (slash instead of space, null-byte obfuscation, `rel="follow"` replacement) — see `app/modules/filter/src/Tests/AddRelNofollowTest.php`
  - **Decouple core tests from extension config** — `RouterTest::testCacheKeyReflectsRouteAffectingOptions()` (`app/modules/routing/src/Tests/RouterTest.php:200-273`) uses `blog.permalink` as its example option. `permalink` is **not** core: it is defined by the blog package (`packages/pagekit/blog/index.php`), and core `Router` only references it in a comment. The behaviour under test (route-affecting options must participate in the router cache key) is generic core logic → use a **generic option name** (e.g. `test.route_option`). Principle: core tests must not depend on extension-specific config; extension-specific tests belong in the extension's own repo/test suite once third-party extensions ship their own coverage.
  - **`setAccessible()` PHP 8.5 forward-compat cleanup** — `ReflectionMethod/Property::setAccessible()` is a **no-op since PHP 8.1** and **`#[\Deprecated]` since PHP 8.5** (removal targeted for PHP 9); on 8.5 it emits deprecation notices while having no effect → delete all calls (safe on PHP 8.2+, no behaviour change). Test sites (this step): `app/modules/routing/src/Tests/RouterTest.php:213,252`, `app/modules/database/src/Tests/ORM/QueryBuilderCacheTest.php:77,92,219`, `app/system/modules/mail/src/Tests/MessageTest.php:207`. Two adjacent production sites belong in the same sweep: `app/system/modules/mail/src/Message.php:391,403,438`, `app/modules/kernel/src/Event/ExceptionListener.php:67`.
  - **`StreamWrapper::$context` dynamic-property deprecation (audit 2026-07-07 — TD-16 / Proposal P3)** — PHP 8.2 emits `Creation of dynamic property Pagekit\Filesystem\StreamWrapper::$context is deprecated` because PHP assigns the stream context to `$context` while the class declares no such property. It fires **4× per test run** — it is the _single_ real source behind the "4 PHP deprecations" in the current suite baseline (§2 of the audit). Fix: declare `public $context;` on `app/modules/filesystem/src/StreamWrapper.php` (**untyped** on purpose — PHP may assign `null` or a stream-context resource; this matches the stream-wrapper contract, same accept-by-design rationale as TD-10). One-line change; makes the suite deprecation-clean apart from 2 framework-internal PHPUnit metadata deprecations. Same "PHP 8.x deprecation hygiene" bucket as the `setAccessible()` cleanup above. Routed from AUDIT_REPORT_TECH_DEBT_INVENTORY_2026-07-07 §6 TD-16.
  - **Audit findings (Phase 1 review):**
    - E2E tests (Step 1.10.5): Most were poorly created, not following best practices; only ~3 of 11 specs are reasonably functional. Full E2E rework **re-homed to Step 3.6.1** (adopts the 3.6 `data-testid` strategy) — resolves 1.10.5 there, not in 2.1.9.
    - ~~`MigrationServiceTest` — all tests skipped; write real migrate/rollback coverage~~ (RESOLVED in PR #189, Step 2.0.4 — 12 real tests with in-memory SQLite)
    - `MenuApiController` — manual validation without `#[Assert\...]` / `ValidatesRequestTrait`; add validation + tests
    - `assertEquals` vs `assertSame` — ~200+ occurrences where strict comparison would be more appropriate
  - **Audit findings (Step 2.0.4 review):**
    - `PackageManager::enable()`/`uninstall()` migration integration — no integration tests for auto-migrate on enable, auto-rollback on uninstall, or partial rollback to pre-migration version. Unit-level MigrationService methods are tested.
    - `MigrationCommand` CLI flow — no integration test for the unified Doctrine migrations + scripts pipeline with version bump guard.
  - **Audit findings (Step 2.0.1c Bugbot review, PR #169):**
    - DI-wiring integration tests — the Stage-3 static→injection migration changed 25 controllers, 7 listeners, installer controllers, `PackageManager`, and module classes without dedicated tests. Add tests for controller constructor-injection resolution (`ControllerResolver`), the module `$app ?? App::getInstance()` fallback, factory-service behaviour (e.g. `finder` returns fresh instances), and `PackageManager` with/without container availability.
- **Result**: Coverage grows organically with every change
- **Risk**: Low — continuous improvement, no big bang
- **Note**: No separate branch — coverage tests are delivered in every feature branch

---

### Step 2.1.10: Entity Presentation Layer (ModelServiceLocator → DTO/Presenter)

- **Goal**: Remove the transitional static `ModelServiceLocator` and move presentation/infrastructure concerns out of the `Node` / `Post` entities into a proper DTO/presenter layer with constructor DI.
- **Prerequisite**: Step 2.1.6 (PHPStan Level 7→8) — the locator's `getUrl()` / `getUser()` / `getModule()` return types are narrowed to concrete types there first.
- **Issue**: GitHub #204 (sub-issue of #147 — Step 2.1)
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_10_Entity-Presentation-Layer.md`
- **Context**: `ModelServiceLocator` was introduced in Step 2.0.1e (PSR-11 / StaticTrait removal) as a deliberate temporary bridge, so entities could reach the `url` / `user` / `module` services without the old global `App` anti-pattern. It is the **last static service locator in the model layer** and is tagged in-code: `// TODO: Must be refactored in Step 2.1.10 (Entity Presentation Layer) — replace ModelServiceLocator with proper DTO/presenter pattern (GitHub #204)`.
- **Closes Phase 1 audit:** **Step 1.11 (ORM Modernization)** — **partial:** removes the `ModelServiceLocator` "static service locator" finding that Step 2.1.6 only type-narrowed. **1.11 stays ⚠️ after this step** and flips to 🛡️ only when the `EntityManager` singleton is also removed in **Step 2.1.11** (after 2.0.8 + 2.1.6 + 2.1.10 + 2.1.11 have all landed).
- **Problem** — entities currently mix persistence with presentation/infrastructure concerns:
  - `Node::getUrl()` / `Node::jsonSerialize()` — needs the URL generator
  - `Node::isAccessible()` / `Post::isAccessible()` — needs the current user
  - `Post::isCommentable()` — needs the blog module config
  - `Post::jsonSerialize()` — needs the URL generator
- **Tasks**:
  - Introduce per-entity presenters/serializers (e.g. `NodePresenter`, `PostPresenter`) with constructor DI (`UrlGenerator`, current `User`, blog config)
  - Move URL / access / comment logic out of the entities, or pass dependencies explicitly via method parameters
  - When relocating `Node::getUrl()` here, type its `$referenceType` as `int|string` (match `UrlProvider::get()`: string `BASE_PATH` + int `LINK_URL=100`), not `mixed` — residual narrowing from the Step 2.1.6 `mixed` audit
  - Convert API/JSON output to the presenter/DTO path (`jsonSerialize()` callers → presenter)
  - Update all call sites in the `site` module and the `blog` package
  - **Delete** `ModelServiceLocator` entirely (Aggressive Rule 4: Delete over Wrap) incl. its `init()` wiring in `SiteModule`
  - All tests green (PHPUnit + Playwright E2E)
- **Result**: Zero static service locators in the model layer; entities are pure domain/persistence objects; API serialization runs through DI-based presenters.
- **Risk**: Medium-High — touches entity serialization and every `jsonSerialize()` / `getUrl()` call site; requires an Architect design pass before the Refactorer starts (trace all callers).
- **Sequencing guardrail (audit 2026-07-07 — Proposal P6 / SL-1):** Step 2.1.10 **must precede any Step 4.2 (REST API v2) serialization work.** New API responses must serialize through the DI presenters introduced here — **not** through entity `jsonSerialize()` (which reaches services via `ModelServiceLocator`). Any 4.2 endpoint built on the entity-`jsonSerialize()` path gets thrown away when the locator is removed. Mirror-note added in `PHASE_4_MODERNISING.md` §4.2.

---

### Step 2.1.11: EntityManager DI — remove singleton (Active-Record → Data-Mapper)

- **Goal**: Replace the static Active-Record access in the model layer (static `Model::find()` / `where()` backed by the `EntityManager` singleton) with an injected `EntityManager` / repositories (Data Mapper); remove the singleton and its boot hack.
- **Prerequisite**: Step 2.1.6 (PHPStan Level 7→8) — 2.1.6 only **hardens the typing** of the singleton (no wrap, one mechanism); the architectural removal happens here. Also Step 2.1.10 (DTO/presenter layer) — presentation-layer cleanup precedes the persistence-layer refactor.
- **Issue**: GitHub #205 (sub-issue of #147 — Step 2.1)
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_11_EntityManager-DI.md`
- **Context**: The `EntityManager` registers itself as a static singleton in its constructor (`static::$instance = $this`, `EntityManager.php`), exposed via `getInstance()`. `ModelTrait::getManager()` falls back to it, and `app/system/index.php` eagerly resolves `db.em` at boot **solely** to populate the singleton so static model calls work. It is the **last global-state access in the model layer** after `ModelServiceLocator` (2.1.10).
- **Closes Phase 1 audit:** **Step 1.11 (ORM Modernization)** — removes the `EntityManager` singleton finding (the last 1.11 item beyond `ModelServiceLocator`). Combined with 2.0.8 + 2.1.6 + 2.1.10, this completes 1.11.
- **Tasks**:
  - Introduce DI access to the `EntityManager` for models (injected EM / repository pattern) — no static singleton
  - Remove `static::$instance` + `getInstance()` from `EntityManager`; refactor `ModelTrait::getManager()` to obtain the EM without the singleton fallback
  - Migrate all `EntityManager::getInstance()` callers and static `Model::find()/where()/...` call sites
  - Replace `NodeModelTrait`'s static request-scoped `$nodes` cache (`app/system/modules/site/src/Model/NodeModelTrait.php:18-21`, tagged in-code `// TODO: BACKWARD COMPATIBILITY … removed in Step 2.1.11`) with an injected `CacheItemPoolInterface` — same static-global-state removal as the singleton
  - **Delete** the `$app->get('db.em')` boot line in `app/system/index.php` (Aggressive Rule 4: Delete over Wrap)
  - All tests green (PHPUnit + Playwright E2E)
- **Result**: Zero static singletons in the model layer; the `EntityManager` is obtained via DI; no boot-time side-effect hack.
- **Risk**: High — ripples into every static model call site; requires an Architect design pass (Active-Record → Data-Mapper migration strategy) before the Refactorer starts.
- **In-code flag hygiene (audit 2026-07-07 — Proposal P5 / §9 RC-1, RC-2), do while touching these files:**
  - **RC-1** — `app/system/index.php:96`: the TODO header points at the completed Step 2.1.6, but the comment body itself says the work (removing the `db.em` boot hack) is _this_ step. Retag `2.1.6` → `Step 2.1.11 (#205)` — the boot line is deleted here anyway.
  - **RC-2** — `app/system/modules/site/src/Model/NodeModelTrait.php:18`: the flag reads "Must be refactored later" with **no step ID**; add `Step 2.1.11` (this step replaces the static request-scoped `$nodes` cache with an injected `CacheItemPoolInterface`).
  - **RC-3** (docs-only, unrelated file — fix opportunistically when the blog migration is next touched): `packages/pagekit/blog/src/Migrations/2025/Version20251023070000_CreateBlogTables.php:20` carries a residual `AUDIT FIX Step 2.0.5` note; the timestamp-rename it referenced already shipped in 2.0.5 ✅, so the line now only documents a runtime data-migration note for pre-existing installs — convert it to a permanent upgrade note or remove.

---

### Step 2.1.12: Residual `mixed` narrowing (typed properties & signatures)

- **Goal**: Narrow the last few _avoidable_ `mixed` occurrences from the Step 2.1.6 `mixed` audit to honest, concrete types — no behaviour change, purely developer-facing type accuracy (lightweight, IDE/PHPStan-friendly).
- **Prerequisite**: Step 2.1.6 (PHPStan Level 8) — these are the residual narrowable candidates left after the L8 sweep.
- **Issue**: GitHub #217 (sub-issue of #147 — Step 2.1)
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_12_Residual-Mixed-Narrowing.md`
- **Scope note**: This is **not** a blanket "remove all `mixed`" pass (there is no PHPStan-Level-9 step planned). The bulk of remaining `mixed` is legitimate and stays: docblock array shapes, magic-method proxies, filter/loader/PSR-11 contracts, polymorphic `preg_replace` returns, and `callable`-typed properties (PHP forbids `callable` as a native property type → `mixed` + `@var callable…` docblock is the idiomatic best practice). Only the genuinely narrowable cases below are in scope.
- **Tasks**:
  - `app/system/modules/captcha/src/CaptchaListener.php:146` — `verifyToken(mixed $gRecaptchaResponse, mixed $secret)` → `string`. Modernise the single call site (line 141) to feed real strings via Symfony 6.4 typed request accessors: `$request->request->getString('gRecaptchaResponse')` (instead of `$request->get(...)`, which may return an array/null) + `(string) $this->captchaModule->config('recaptcha_secret')`.
  - `app/system/modules/site/src/Controller/NodeController.php:23` — `protected mixed $site` → `SiteModule`. `$this->module->get('system/site')` returns the `SiteModule` instance (used as `->getTypes()` / `->getType()` / `->config()`). Best practice: inject `SiteModule` via constructor DI instead of `ModuleManager::get()`, then declare `private readonly SiteModule $site`.
  - `app/system/src/Model/DataModelTrait.php:13` — `public mixed $data = null` → `public ?array $data = null` with `@var array<string, mixed>|null`. All assignments are arrays (`= []`, `array_replace_recursive(...)`), the column is `json_array`, and `JsonArrayType::convertToPHPValue()` always returns an array — so the property is `?array` (the `(array)` cast in `get()` becomes redundant).
- **Result**: Fewer avoidable `mixed`; cleaner IDE/PHPStan signals in the model, site-controller and captcha layers.
- **Risk**: Low — signature/property narrowing plus one small call-site change; PHPUnit + PHPStan green.
- **Note**: `Node::getUrl()`'s `mixed $referenceType` → `int|string` is handled in **Step 2.1.10** (it is relocated as a presentation concern there). `PregReplaceFilter::filter()` return, `ExceptionListener::$controller` and `WrappedListener::$listener` were reviewed and deliberately **kept `mixed`** (honest polymorphic return / PHP `callable`-property limitation).

---

## Step 2.2: CI/CD Pipeline

- **Goal**: Automated CI/CD with E2E & static analysis integration
- **Prerequisite**: Steps 1.10.5 (E2E Tests) and 2.1 (Static Analysis) completed
- **Tasks**:
  - **Workflow 1 — PHP Tests** (`.github/workflows/php-tests.yml`):
    PHPUnit (PHP 8.2/8.3/8.4 × MySQL 8.4/SQLite 3), PHPStan analysis, PHP-CS-Fixer dry-run, security audit
  - **Workflow 1b — Mutation Testing (Infection)** — deferred here from Step 2.1.8. Infection was installed and configured for the auth + user security-critical classes in 2.1.8, but is intentionally **not** wired into the per-PR gate (mutation testing is slow). Add a **non-blocking, scheduled + manual-dispatch** job (`workflow_dispatch` + weekly `schedule`), mirroring the Workflow 2b cross-browser rationale. Runs `./app/vendor/bin/infection --min-msi=80` on the configured security core; regressions are surfaced without blocking every PR.
  - **Workflow 2 — E2E Tests** (`.github/workflows/e2e-tests.yml`):
    Playwright on Chromium, fixed backend (PHP 8.3 + MySQL 8.4), 3 viewport jobs (Mobile 375×667, Tablet 768×1024, Desktop 1920×1080)
  - **Workflow 2b — Cross-Browser Tests** (`.github/workflows/e2e-cross-browser.yml`):
    Weekly schedule + manual trigger, Firefox + WebKit, critical test subset only
  - **Workflow 3 — Frontend Tests** (`.github/workflows/frontend-tests.yml`):
    ESLint, Prettier, Yarn build verification
  - Quality gates as required checks for PRs
  - Dependency caching for fast CI (target: all workflows < 10 minutes)
  - Release automation
  - **Version Single Source of Truth guard** — `composer.json` `require.php` (`^8.2`) is the authoritative minimum-PHP constraint; the CI matrix (PHP versions in Workflow 1), `app/installer/requirements.php` (`REQUIRED_PHP_VERSION`), `.cursor/Dockerfile` (`php:8.3-cli`) and `README.md` (badge + "8.2–8.4") must **consume/track** it, not redefine it. Add a CI check that fails on drift (assert `REQUIRED_PHP_VERSION` and the matrix floor equal composer's `require.php`), so the minimum version lives in exactly one place. Docker/CI pin _test/runtime targets_ — they are not the source (so "put the version in Docker" is the wrong direction). Also capture the extension floors currently inline in `requirements.php` (APCu `5.1.0`, PCRE `8.0`). (Discovered during PR #212 triage; the `requirements.php` content modernization itself was handled in Step 2.1.6.)
  - ~~Deploy previews~~ (optional, later)
- **Design Decisions**:
  - E2E tests UI interaction, NOT backend variants — PHPUnit covers the PHP/DB matrix
  - Cross-browser testing runs weekly, not per-PR (catches rendering bugs without blocking PRs)
  - Mutation testing (Infection, from 2.1.8) runs scheduled + manual, not per-PR — too slow to gate every PR, same rationale as cross-browser
  - Edge cases (large uploads, session timeout, concurrent edits) integrated into E2E suite
  - Load/performance tests belong in staging before major releases, NOT in CI
- **Quality Gates** (every PR must pass):
  - All PHPUnit tests green
  - PHPStan (no new errors vs. baseline)
  - No security vulnerabilities
  - PHP-CS-Fixer PSR-12 compliant
  - All E2E tests green
  - ESLint/Prettier checks
  - Code coverage not below target (core: 75%, packages: 60%)

---

## Step 2.3: Docker Production Setup

- **Goal**: Production-ready Docker
- **Tasks**:
  - Multi-stage builds
  - Alpine Linux images
  - Docker Compose optimization
  - Kubernetes ready

---

## Step 2.4: Build Tools Modernization

- **Goal**: Modern build pipeline
- **Options**:
  - Webpack 5 migration
  - Yarn Berry evaluation (or stay on Yarn 1.22)
  - Vite as an alternative to Webpack
  - ESBuild for faster builds
  - pnpm as a package manager alternative

---

## Step 2.5: Extension Safety & Fault Isolation

- **Goal**: Prevent faulty extensions from crashing the entire CMS (white screen of death).
- **Context**: Currently extensions are loaded directly at boot time. An error in an `index.php` or `main()` function takes down the kernel.
- **Tasks**:
  1. **Sandboxed Loading**: Refactor `ModuleManager` to load modules inside a `try/catch(\Throwable)` block.
  2. **Logging (FIRST, DB-INDEPENDENT!)**: The exact stack trace must be written to `pagekit.log` **immediately** (Monolog FileHandler). This step MUST NOT require a database connection — if the extension has destroyed the DB connection, logging must still work.
  3. **Auto-Disable (own try/catch!)**: Attempt to deactivate the module in the database. This DB write must be in a **separate** `try/catch(\Throwable)`. If the DB write fails (e.g., because the extension has corrupted the DB connection), write to a local file instead (`storage/disabled-extensions.json`). On the next boot, the kernel checks **both** sources (DB + fallback file).
  4. **Admin Alert**: On the next login, the admin must see a flash message: _"Extension X was deactivated due to a critical error."_
- **Why**: Dramatically increases stability. A syntax error in a plugin must not prevent access to the admin panel to uninstall it.
- **Technical Implementation**:
  - [ ] Introduce `Pagekit\System\Extension\ExtensionLifecycleInterface`.
  - [ ] Convert the Blog extension from `scripts.php` to a lifecycle class.
  - [ ] Refactor `ModuleManager`/`ExtensionManager` to execute these classes inside `try/catch(\Throwable)` blocks.
  - [ ] Logging via Monolog FileHandler (`storage/logs/pagekit.log`) — MUST work without DB.
  - [ ] Auto-disable: Primary via DB, fallback via `storage/disabled-extensions.json`. Boot sequence checks both sources.
  - [ ] Integration with the new migrations system (Step 1.12): On Throwable during `onInstall` → automatic DB rollback.
  - [ ] Implementation of admin alert flash messages.
- **Audit findings (Step 2.0.8 review):**
  - `User::evaluateBooleanExpression()` (`app/system/modules/user/src/Model/User.php:251`) — extract into a standalone `PermissionExpressionEvaluator` service **only if and when a second caller emerges**. As of 2.0.8 closure there is exactly one caller (`User::hasAccess()`), so per Aggressive Rules 1 ("No Compatibility Layers") + 2 ("No Adapters") the helper stays inline as a `private static` method on `User`. No code change required in 2.5 unless extension code or a new permission system surfaces a second caller. Documentation-only route from `migration-docs/branches/phase-2/step-2-0-8-user-hasaccess-hotfix.md:213-217`. (Routed from §4.4 Gap List row 2 of `migration-docs/audits/2026/04/AUDIT_REPORT_STEP_2.0_FOUNDATION_CLOSURE_2026-04-28.md`.)
- **Routing dumper modernization (Symfony 4.3 deprecation) — added 2026-06-30:**
  - `app/modules/routing/src/Matcher/Dumper/PhpMatcherDumper.php` is a copied clone of Symfony's deprecated `PhpMatcherDumper` (carries `@deprecated since Symfony 4.3`). Replace it — and the paired `UrlGeneratorDumper` — with Symfony's native `CompiledUrlMatcherDumper` + `CompiledUrlMatcher` and `CompiledUrlGeneratorDumper` + `CompiledUrlGenerator`.
  - Rework `Router::getMatcher()`/`getGenerator()` to the compiled-route-data format (no more dumping a subclass via reflection; the `instantiateMatcher()`/`instantiateGenerator()` helpers go away).
  - Re-implement the custom `UrlGenerator::doGenerate()`/`getRouteProperties()` (`LinkReferenceType` + `_variables`) on top of `CompiledUrlGenerator` — this powers the blog permalink alias system, so guard it with the existing `RouterTest` + blog permalink coverage.
  - **Why Step 2.5:** pairs with the routing factory/DI rework here (the `UrlResolver` static bridge is already tagged for this step). No functional breakage on Symfony 6.4 (the deprecated classes still ship); clears the deprecation ahead of a future Symfony 7 jump.
  - **Route-cache freshness axis (audit 2026-07-07 — TD-03), review while reworking `getCache()`:** `Router::getCache()` already invalidates correctly on **content/options** — the key is `sha1(serialize($this->resource).serialize($this->options))` (`app/modules/routing/src/Router.php:417`), so route-collection or option changes (e.g. `blog.permalink`) force a fresh dump (this is the 1.2.21 fix). What remains mtime-based is only the **freshness** guard: `filemtime($file) >= $this->resource->getModified()` (`:430`). `filemtime()` is an _implicit_, coarse (1-second granularity) and deploy-fragile signal — a backup restore / `rsync` / `touch` can reset mtimes so a stale dump reads as "fresh" (or a valid dump reads as stale). When moving `getMatcher()`/`getGenerator()` to `CompiledUrlMatcher/Generator` above, **prefer making the content hash the sole invalidation signal** (or an explicit `ConfigCache`/version marker) and drop the mtime heuristic at `:430`. **Low priority / already mitigated** (the key covers content + options) — this is a consolidation, not a bug; fold the review into this rework rather than opening a standalone step. Routed from AUDIT_REPORT_TECH_DEBT_INVENTORY_2026-07-07 §6 TD-03 / §7 D1.
- **`blog/UrlResolver` static bridge DI (deferred from Step 2.1.6):**
  - `packages/pagekit/blog/src/UrlResolver.php:25,29,35` — `private static` cache/module references with setters (static service locator). The `mixed $cache` typing was already fixed in Step 2.0.3 (`?CacheItemPoolInterface`); only the static-locator/DI removal remains. **Blocker:** the Router instantiates resolvers via `new $class` without DI, so the static holder cannot be removed in isolation — the routing factory (`ParamsResolver` bootstrap) must support DI first. Same blocker class as `theme-one/functions.php` below; both are unblocked by the routing factory/DI rework scheduled in this step.
- **`theme-one` static `UrlProvider` DI (deferred from Step 2.1.6) — added 2026-06-30:**
  - `packages/pagekit/theme-one/functions.php` (~line 8): the `ThemeOneHelpers` static `UrlProvider` is global state at the theme boundary. Step 2.1.6 completed the **typing** portion (`private static ?UrlProvider $url`, no more `mixed`), but the **DI** portion was both omitted from the 2.1.6 ticket and is structurally blocked: template helper functions are invoked from PHP templates with no injection mechanism, so the static holder cannot be removed in isolation. Replace it with proper DI once template helpers support injection — this aligns with the routing factory/DI rework already scheduled here (same blocker class as the `blog/UrlResolver` static bridge). The in-source TODO tag has been retagged from `Step 2.1.6` to `Step 2.5` accordingly. Tracked as the open `theme-one/functions.php` checkbox on Issue #153 and PR #212.
- **`UniqueValidator` static service-locator → DI (audit 2026-07-07 — TD-05 / Proposal P1). DECISION (2026-07-08): DI (container-aware factory).**
  - `app/system/src/Validator/Constraints/UniqueValidator.php:22,24` — `private static mixed $db` + static `setDb()` locator, wired at `app/system/index.php:90`. **Same root cause** as the `blog/UrlResolver` and `theme-one` bridges above: Symfony's default `ConstraintValidatorFactory` instantiates validators via `new $class()`, so a static holder bridges the `db` service (the class docblock itself documents this).
  - **Resolution:** register a **container-aware `ConstraintValidatorFactory`** so `UniqueValidator` receives `db` via constructor DI; then delete `setDb()` + `static mixed $db` **and** the `UniqueValidator::setDb($app->get('db'))` boot wiring at `app/system/index.php:90` (Aggressive Rule 4: Delete over Wrap). Consistent with SL-2 (same "framework `new $class()`" theme).
  - **Rationale (DI chosen over the DNA "accept" fallback):** best practice + future-proofing — a container-aware factory establishes a **reusable DI seam** for any future validator that needs services, instead of accreting more static `setX()` bridges. The DNA-simple "accept + document like `IntlServiceLocator` / `StreamWrapper` (TD-09/TD-10)" option was considered and **explicitly rejected**.
  - The legacy `->execute()` at `:69` and the `mixed` typing are **already covered by Step 2.1.7** — only the static-locator/DI removal belongs here. Routed from AUDIT_REPORT_TECH_DEBT_INVENTORY_2026-07-07 §5 SL-2 / §6 TD-05.
- **Sequencing guardrail (audit 2026-07-07 — Proposal P6):** Step 2.5 (extension fault isolation) **must precede Step 5.6** (Marketplace & Extensions). The "faulty extension crashes the kernel" risk (audit TD-15 / D4) is bounded _today_ only because sole first-party extensions ship; a third-party marketplace makes it live.

---

## Step 2.6: Automated Update System - External & Background Updates

- **Goal**: Modern, future-proof update infrastructure for Pagekit CMS
- **Priority**: High (required for long-term maintainability)
- **Context**: `migration-docs/TODO/features/AUTOMATED_UPDATE_SYSTEM.md`

---

## Step 2.7: Filesystem Write Resilience — Atomic Writes & Error-Handling Hygiene

- **Goal**: Generalize the routing-cache resilience fix (atomic temp file + `rename()`) into a single shared filesystem primitive and route all **boot-critical** file writes (`config.php`, package registry) through it — so a crash or a concurrent read mid-write can no longer corrupt a file that is `include`d on every request. Bundle two adjacent error-handling micro-cleanups found in the same audit (dead code / silent catch).
- **Prerequisite**: None — self-contained hardening, can run at any point. **Recommended before Step 2.6** (Automated Update System) and **reusable by Step 2.5** (the `storage/disabled-extensions.json` fallback write) — both add further write sites that should use the same helper rather than re-implementing it.
- **Source**: `migration-docs/audits/2026/07/AUDIT_REPORT_TECH_DEBT_INVENTORY_2026-07-07.md` — Decision-Critical Shortlist **SL-3** + Master Inventory rows **TD-19** (primary), **TD-20**, **TD-18**. This is the audit's own §8 **Proposal P2** (atomic-write utility) + **P4** (opportunistic cleanups).
- **Context**: The 1.2.21 routing hotfix proved the failure class — a **non-atomic write + narrow catch** caused an HTTP 500 on concurrent route-cache regeneration. `Router::writeCache()` (`app/modules/routing/src/Router.php:442-468`) already implements the **correct** pattern: unique temp file → `chmod` → atomic `rename()`, with a documented direct-write fallback (and `getMatcher()/getGenerator()` degrade safely to the non-cached path on a partial read). The identical non-atomic `file_put_contents()` pattern still writes higher-value files — above all `config.php`, which is `include`d on **every** boot. This step extracts the proven pattern **once** and reuses it (Pagekit DNA: no over-engineering, no new dependency — it is _extraction_, not new design).
- **Tasks**:
  - **Extract the shared helper** — add `Pagekit\Filesystem\Filesystem::dumpAtomic(string $file, string $content): void` (or an equivalent single method), implementing the temp + `chmod` + atomic `rename()` logic with the existing Windows / locked-destination direct-write fallback. Cover it with a unit test (`app/modules/filesystem/src/Tests/`).
  - **Route `config.php` writes through it** (currently non-atomic `file_put_contents`):
    - `app/system/modules/settings/src/Controller/SettingsController.php:59` (`saveAction()`)
    - `app/installer/src/Installer.php:205` (already has a `write-failed` guard, but the write itself is not atomic)
  - **Route the package-registry writes through it**:
    - `app/installer/src/Helper/Composer.php:228` (`writeConfig()`)
    - `app/installer/src/Controller/PackageController.php:207` (a `{}` bootstrap write to a temp `composer.json` — lower priority than the config/registry writes; verify scope during ticket planning)
  - **Refactor `Router::writeCache()`** to delegate to the shared helper (single source of the pattern — Rules 1/2: no duplicated implementation), keeping the existing safe-fallback behaviour of `getMatcher()/getGenerator()`.
  - **Opportunistic — bundled (same audit, same D6 domain, no extra dependency):**
    - **TD-18** — delete the commented-out `catch` block in `app/console/src/Commands/SelfupdateCommand.php:70-85` (dead code; Aggressive Rule 4 "Delete over Wrap"). The _live_ self-update flow itself is Step 5.6 territory — only the dead comment block is removed here.
    - **TD-20** — the empty `catch (\UnexpectedValueException $e) {}` in `app/installer/src/Helper/Composer.php:63-64` silently swallows an invalid version constraint during package refresh. Log it (or narrow / handle) instead of swallowing.
  - All tests green (PHPUnit + Playwright E2E).
- **Result**: One shared atomic-write primitive; `config.php` and the package registry can no longer be corrupted by a crash / concurrent read mid-write; no duplicated temp+rename logic anywhere; two silent / dead error-handling sites cleaned.
- **Risk**: Low-Medium — small, localized changes plus one extracted helper. The routing path already relies on the same logic and is covered by `RouterTest::testCorruptCacheFileFallsBackInsteadOfFatal`.
- **Not in scope (tracked elsewhere):** the hardcoded OpenWeatherMap **API key** (`app/system/modules/dashboard/index.php:48-49`, audit **TD-22**, Critical) is the second half of SL-3 but is already tagged **Step 4.2** (env / secrets migration). The audit recommends _accelerating_ + rotating it — decision left to the user; not folded here to keep this step a pure filesystem-resilience unit.

---

## Step 2.8: PHP Version Upgrade — Raise Minimum to Latest Stable

- **Status**: 📝 Draft (added 2026-07-09) — scope to be refined during ticket planning.
- **Goal**: Raise the minimum supported PHP version from **8.2** to the latest stable release (currently **8.4**; confirm the newest stable at execution time) with a full compatibility audit of everything the bump touches, cleanup of any newly-surfaced deprecations, and _optional_ adoption of newly-available language features where they genuinely improve readability/maintainability (no over-engineering — Pagekit DNA).
- **Prerequisite**: Phase 2.1 complete (`strict_types` + PHPStan Level 8 + the CI test suite) — the existing green suite is the safety net for the bump. **Recommended before Step 2.9** so the final coverage/mutation push runs on the final PHP version.
- **Tasks (draft)**:
  - **Single source of truth (see Step 2.2 guard):** `composer.json` `require.php` is authoritative. Bump it, then update every _consumer_ so the 2.2 drift-check stays green — CI matrix floor (Workflow 1), `app/installer/requirements.php` (`REQUIRED_PHP_VERSION` + extension floors), `.cursor/Dockerfile`, and `README.md` (badge + supported-range text).
  - **Compatibility audit:** systematically determine what the bump affects — removed/deprecated functions, behavioural changes, and each dependency's PHP constraint. Feed findings into concrete sub-fixes (this is the "genau prüfen was betroffen ist" part).
  - **Relax dependency caps that only existed for 8.2:** e.g. `infection/infection` is capped at `<0.33` purely for the PHP-8.2 CI leg (see the Step 2.1.8 branch doc) — raise it to a current release once 8.2 is dropped. Re-scan all `composer.json` constraints for similar 8.2-only caps.
  - **Deprecation cleanup:** resolve any new PHP deprecation notices surfaced by the higher version (relates to the `failOn*` hygiene tracked for Step 2.1.9).
  - **Optional feature adoption:** e.g. 8.3 typed class constants / `#[\Override]` / `json_validate()`, 8.4 property hooks / asymmetric visibility — apply only where they simplify existing code.
  - All quality gates green on the new version (PHPUnit, PHPStan L8, cs-fixer, security-audit, E2E).
- **Risk**: Medium — broad blast radius, but bounded by the Phase 2.1 test + static-analysis net.

---

## Step 2.9: Phase 2 Closeout — Test Coverage & Mutation Consolidation

- **Status**: 📝 Draft (added 2026-07-09) — scope to be refined during ticket planning.
- **Goal**: Final quality push that closes out Phase 2 — consolidate the ongoing Step 2.1.9 coverage work, extend tests beyond the security-critical core, and raise the mutation-testing gates (`minMsi` / `minCoveredMsi`) on a **data-driven** basis. Widen the Infection scope past the auth + user security core.
- **Prerequisite**: Step 2.8 (PHP upgrade) — runs on the final PHP version so gates/constraints are set once. Builds on Step 2.1.9 (ongoing coverage) and Step 2.2 (the non-blocking Infection CI job).
- **Tasks (draft)**:
  - **Resolve the deferred Infection markers** surfaced in 2.1.8 (if not already cleared by 2.1.9): the injectable-clock time-boundary mutants (`DatabaseHandler::read:53`, `LoginAttemptListener::onPreAuthenticate:43`) and the `failOn*` gate flip + `UserAccessTest` ignore drops. Use the §2.4 defer-marker scan in the 2.1.9 prompt to confirm none remain.
  - **Extend coverage** to the DB-bound integration paths deferred from 2.1.9 (e.g. `UserProvider` happy-path lookups, `UserListener` handlers, `User::hasPermission` uncached branch) and to further high-risk modules (database/ORM, filesystem).
  - **Edge-case scenarios carried over from 2.1.9** — these real-Pagekit-scenario tests are listed in the 2.1.9 prompt/issue (#156) but were **not** implemented in PR #218 (which delivered the ORM query-cache invalidation + `AddRelNofollowFilter` XSS edge cases instead). Add them here so they land with the broader coverage push rather than piecemeal:
    - **Large file uploads** (Storage / filesystem module)
    - **Concurrent admin actions** (session handling)
    - **Database connection failures** (ORM error handling)
  - **`packages/` coverage** — deferred from 2.1.9, where `packages/` is explicitly **not** in the measured `phpunit.xml.dist <source>` scope (the coverage gate only covers `app/modules`, `app/system`, `app/console`). Add the packages to the measured `<source>` (or a dedicated testsuite) first, then raise `packages/pagekit/blog/` to 60 %+ and `packages/pagekit/theme-one/` to 30 %+ (theme is mostly views → low target).
  - **Widen the Infection scope** in `infection.json.dist` beyond auth + user, prioritising data-integrity code.
  - **Ratchet the gates:** measure the actual MSI / Covered MSI on the widened scope, then set `minMsi` / `minCoveredMsi` just below the measured value and raise incrementally — never below (same ratchet principle as the 2.1.9 line-coverage gate).
- **Note**: This is the Phase 2 _closeout_ consolidation of the ongoing Step 2.1.9 effort — **not** a replacement for it. Coverage keeps growing per-branch until here, where the bar is formally raised.
