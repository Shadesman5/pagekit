# 🛠️ Phase 2: Developer Experience – Tools for Quality

**Goal**: Build testing, CI/CD, and developer tools.
**Important**: Can partially run in parallel with Phase 1!

## Step 2.0: Foundation Consolidation

Apply the aggressive modernization rules (defined during Phase 1 execution) retroactively to Phase 1 deliverables. Remove compatibility layers, eliminate wrappers, and harden the architecture before building developer tools on top.

- **Prerequisite**: Phase 1 completed
- **Scope**: All sub-steps address technical debt from Phase 1 that was identified after the No-Mercy rules were established.

### Step 2.0.0: Controller Annotations to PHP 8 Attributes Migration

- **Goal**: Migrate Doctrine Annotations to PHP 8 Attributes for all controllers
- **Prerequisite**: Step 1.14 (Doctrine Attributes) completed

---

### Step 2.0.1: Full PSR-11 Container Modernization

- **Goal**: Fully modernize the container to a native PSR-11 container
- **Prerequisite**: Step 1.14 (Doctrine Attributes) completed
- **Closes Phase 1 audit:** Step 1.6 (PSR-11 Container Compatibility) ⚠️ → 🛡️ — `Psr11Adapter` wrapper deleted in sub-step 2.0.1e (StaticTrait Removal + DI Final); `Container` now natively implements `Psr\Container\ContainerInterface`. Confirmed in `migration-docs/audits/2026/03/AUDIT_REPORT_STEP_2.0-2.0.2_2026-03-27.md`.

---

### Step 2.0.2: Validator-Translator Integration

- **Goal**: Connect Symfony Validator to Pagekit Translator so that validation error messages are returned in the active locale (instead of raw keys like `validation.user.username_required`)
- **Prerequisite**: Step 2.0.1 (PSR-11 Container) completed
- **Closes Phase 1 audit:** Step 1.13 (Validation Update) ⚠️ → 🛡️ — translator gap closed (raw `validation.*` keys → translated strings via domain `validators`). Confirmed in `migration-docs/audits/2026/03/AUDIT_REPORT_STEP_2.0-2.0.2_2026-03-27.md`. The remaining `MenuApiController` manual-validation finding is tracked under **Step 2.1.9** (Test Coverage Expansion), not as a 1.13 audit finding.
- **Tasks**:
  - `ValidatorServiceProvider`: Wire `$builder->setTranslator()` + `setTranslationDomain('validators')`
  - Rename `validation.php` → `validators.php` (system + blog, all locales)
  - Verify: ValidatesRequestTrait returns translated strings in JSON responses
  - (Optional) Extend `ExtensionTranslateCommand` for `#[Assert\...]` message keys
  - Write tests for translated validation messages
- **Result**: API responses contain human-readable, localized error messages

---

### Step 2.0.3: Full Cache API Modernization

- **Goal**: Completely replace Pagekit's own cache system (`CacheInterface`, `Psr6Adapter`, 5 adapter wrappers) with direct usage of Symfony Cache / PSR-6 `CacheItemPoolInterface`
- **Prerequisite**: Step 2.0.2 (Validator-Translator Integration) completed
- **Context**: Step 1.10 internally replaced `doctrine/cache` with `symfony/cache` but kept a compatibility layer (`CacheInterface` + `Psr6Adapter`). This violates Rule 1 (No Compatibility Layers) and Rule 4 (Delete over Wrap). These rules did not exist at the time of Step 1.10.
- **Decision**: `Psr\Cache\CacheItemPoolInterface` (PSR-6) becomes the sole cache interface. No PSR-16, no Symfony Contracts, no custom Pagekit interface. `TagAwareCacheInterface` (Step 4.3) is based on PSR-6 — seamless upgrade path.
- **Tasks**:
  - **Delete (7 files):**
    - `app/system/modules/cache/src/CacheInterface.php` (legacy interface)
    - `app/system/modules/cache/src/Adapter/Psr6Adapter.php` (compatibility layer)
    - `app/system/modules/cache/src/Adapter/ArrayAdapter.php` (thin wrapper)
    - `app/system/modules/cache/src/Adapter/FilesystemAdapter.php` (thin wrapper)
    - `app/system/modules/cache/src/Adapter/PhpFilesAdapter.php` (thin wrapper)
    - `app/system/modules/cache/src/Adapter/ApcuAdapter.php` (thin wrapper)
    - `app/system/modules/cache/src/Adapter/NullAdapter.php` (thin wrapper)
  - **Cache Module:**
    - `CacheModule::createPsr6Cache()`: Change return type to `CacheItemPoolInterface`, create Symfony adapters directly
    - `CacheModule::doClearCache()`: `flushAll()` → `clear()`
    - Namespace handling: Symfony adapters support namespaces natively via constructor parameter
  - **ORM:**
    - `MetadataManager`: Change property/getter/setter to `?CacheItemPoolInterface`, delete legacy else-branch
    - `QueryBuilder`: Same cleanup (union type, legacy branch)
    - `EntityManager`: Already cleaned up (previous review step)
  - **Consumers (each ~3-5 lines of changes):**
    - `LoginAttemptListener`: `mixed $cache` → `CacheItemPoolInterface`, `fetch/save/delete` → PSR-6 API
    - `UrlResolver`: `mixed $cache` → `?CacheItemPoolInterface`, `fetch/save` → PSR-6 API
    - `RouteListener`: `mixed $cache` → `CacheItemPoolInterface`, `delete` → `deleteItem()`
    - `blog/scripts.php`: `clear()` stays (PSR-6 native)
  - **Tests:** `Psr6AdapterTest` → `CachePoolTest`, adapt `QueryBuilderCacheTest`
- **Result**: Container directly provides `CacheItemPoolInterface`, no custom Pagekit cache classes remain
- **Risk**: Low-Medium — ~18 files (7 delete, ~11 modify)
- **No new package needed**: `psr/cache` and `symfony/cache` already present

---

### Step 2.0.4: Package/Migration System Redesign

- **Goal**: Complete redesign of the update and extension lifecycle system. Unify Doctrine Migrations and scripts.php hooks into a single pipeline. Lay the foundation for a future marketplace (Step 5.6).
- **Prerequisite**: Step 2.0.3 (Full Cache API Modernization) completed
- **Context**: Step 1.12 (DB Migration System) was implemented without the aggressive rules. Analysis shows that the update path (login check, update wizard, CLI) does not automatically execute Doctrine Migrations. Version bumps can happen without schema checks. Extensions have no unified install/update pattern.
- **Tasks**:
  - **Unify update pipeline:**
    - Login check (`app/system/index.php`): Check for pending Doctrine Migrations, not just `scripts.php`
    - Update wizard (`MigrationController`): Execute `MigrationService::migrate()` BEFORE `scripts->update()`
    - CLI `pagekit migrate`: Doctrine Migrations + scripts in correct order
    - No silent version bumping without migration check
  - **Clean up CLI:**
    - Unify `pagekit migrate`: Doctrine Migrations + scripts in a single command
    - `migration:*` sub-commands remain for developers (low-level)
  - **Standardize extension lifecycle:**
    - Unified pattern for all extensions (like Blog, but automatic)
    - `PackageManager::enable()` automatically calls `migrateExtension()`
    - `PackageManager::uninstall()` automatically triggers rollback
    - Extensions only need `scripts.php` for non-SQL hooks (config, cache)
    - Documentation/template for extension developers
  - **Harden MigrationService:**
    - Fix error handling in `migrate()` (properly check array return values)
    - Ensure rollback isolation for extensions (only their own namespaces)
    - Status API for pending migrations (for login check)
  - **Marketplace foundation:**
    - Clean `PackageManager` API: `install()`, `update()`, `uninstall()`, `enable()`, `disable()`
    - Modernize ZIP upload (existing upload button in the backend)
    - Per-package extension versioning in config
    - Preparation for marketplace API integration (Step 5.6)
  - **Audit findings (Phase 1 review) — additional tasks:**
    - `DatabaseHandler::createTable()` in `app/modules/auth/src/Handler/DatabaseHandler.php`: deprecated runtime DDL (`@deprecated since Pagekit 1.0`); schema should come exclusively from migrations. Delete method and ensure auth migration covers the table.
    - Blog migration naming inconsistency: `Version001_CreateBlogTables` vs. core `Version20251023061532` (timestamp). Standardize to timestamp format.
    - `MigrationServiceTest` — all tests are **skipped**; no real regression coverage for migrate/rollback. Write actual tests.
    - `MigrationService::getConfigPath()` — unused method (dead code). Delete.
- **Result**: One update path for everything; extensions follow a unified lifecycle; marketplace-ready
- **Risk**: Medium-High — affects Installer, PackageManager, MigrationService, CLI, login flow
- **Affected Files**: `scripts.php`, `system/index.php`, `MigrationService.php`, `MigrationCommand.php`, `MigrationController.php`, `PackageManager.php`, `PackageScripts.php`, `Installer.php`, `blog/scripts.php`, `DatabaseHandler.php`
- **Agent Prompt**: `migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/PROMPT_2_0_4_Package-Migration-System-Redesign.md`

---

### Step 2.0.5: Composer & Autoload Hygiene

- **Goal**: Clean up `composer.json` for reproducible builds, remove dead autoload mappings, resolve dependency anomalies, and prepare a healthy base for CI/CD (Step 2.2).
- **Prerequisite**: Step 2.0.4 (Package/Migration System Redesign) completed
- **Context**: Phase 1 Audit (Steps 1.3, 1.4) revealed several infrastructure issues that were not addressed during Phase 1 because they did not block functionality. With CI/CD coming in Step 2.2, these must be fixed first.
- **Closes Phase 1 audit:** Step 1.4 (Safe Minor Updates) ⚠️ → 🛡️ — composer schema cleanup, dead PSR-4 mappings removed, unused dependencies dropped (`symfony/framework-bundle`, `symfony/twig-bridge`, `symfony/yaml`, `symfony/process`, `paragonie/sodium_compat`, `doctrine/data-fixtures`), `symfony/validator` aligned to `^6.4` LTS, `paragonie/random-lib` replaced with native `random_bytes()`. Step 1.3 (Security Patches) was already 🛡️.
- **Tasks**:
  - **~~Lockfile versioning:~~** (shipped early in Step 2.0.3, PR #187)
    - ~~Remove `/composer.lock` and `/yarn.lock` from `.gitignore`~~
    - ~~Commit `composer.lock` and `yarn.lock` for reproducible builds~~
    - ~~Rewrite `.cursor/install.sh` to use `composer install` (not `update`)~~
  - **Dead PSR-4 mappings:**
    - Remove `Pagekit\Theme\` → `app/system/modules/theme/src` (directory does not exist)
    - Remove `Pagekit\Package\` → `app/system/modules/package/src` (module does not exist)
  - **Unused direct dependencies (verify with `composer why` before removing):**
    - `symfony/framework-bundle` — no PHP imports found in `app/` or `packages/`
    - `symfony/twig-bridge` — no PHP imports found
    - `symfony/yaml` — no PHP imports found
    - `symfony/process` — no PHP imports found
    - `doctrine/data-fixtures` (require-dev) — no PHP imports found
    - `paragonie/sodium_compat` — likely only needed transitively
  - **Version alignment:**
    - `symfony/validator: ^7.4` vs. rest at `^6.4` — decide: align to `^6.4` or document why 7.x is needed
    - `paragonie/random-lib: ~2.0.1` — loosen to `^2.0` or evaluate replacing with native `random_bytes()`
  - **Verify:** `composer validate`, `composer install --dry-run`, `./app/vendor/bin/phpunit`
- **Result**: Clean, consistent `composer.json`; lockfile versioned; no dead autoload entries; CI-ready
- **Risk**: Low — mostly deletions and constraint changes; `composer install` + full test suite validates
- **Agent Prompt**: `migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/PROMPT_2_0_5_Composer-Autoload-Hygiene.md`

---

### Step 2.0.6: Test Infrastructure Cleanup

- **Goal**: Consolidate PHPUnit configuration, migrate test annotations to PHP 8 attributes, remove legacy test imports, ensure all test files follow Phase 2 standards.
- **Prerequisite**: Step 2.0.5 (Composer & Autoload Hygiene) completed
- **Context**: Phase 1 Audit (Step 1.2) found 4 old module-level `phpunit.xml.dist` files with PHPUnit 9 schema, case-sensitivity issues in test paths, PHPDoc annotations instead of PHP 8 attributes, and a test still importing `Doctrine\Common\Cache\ArrayCache`.
- **Closes Phase 1 audit:**
  - **Step 1.2 (PHPUnit Update) ⚠️ → 🛡️** — module-level configs deleted, `@dataProvider` / `@group` PHPDoc migrated to PHP 8 attributes, `ConfigManagerTest` modernized (`ArrayCache` import + `getCache()` helper removed, modern `ConfigManager(Connection, array)` signature, `->willReturn()` mock pattern).
  - **Step 1.8 (Routing System Compatibility) ⚠️ → 🛡️** — silent `InvalidArgumentException` catch in `RoutesLoader::addController()` replaced with a debug-aware handler (re-throw in debug, log via `$app->get('log')`, fallback to `error_log()`), covered by 4 new `RoutesLoaderTest` cases.
- **Tasks**:
  - **Remove/consolidate old PHPUnit configs:**
    - Delete or migrate: `app/modules/filter/phpunit.xml.dist`, `app/modules/filesystem/phpunit.xml.dist`, `app/modules/cookie/phpunit.xml.dist`, `app/modules/auth/phpunit.xml.dist`
    - All tests should run via the root `phpunit.xml.dist` (PHPUnit 11 schema)
  - **Fix test path case-sensitivity:**
    - Root `phpunit.xml.dist`: `tests/Unit` → match actual directory casing (`tests/unit`)
  - **Migrate annotations → PHP 8 attributes:**
    - `@dataProvider` → `#[DataProvider('methodName')]` (6 occurrences in 5 files)
    - `@group` → `#[Group('name')]` (Mail test files)
  - **Fix legacy test imports:**
    - `ConfigManagerTest.php`: Remove `Doctrine\Common\Cache\ArrayCache` import; adapt test to current `ConfigManager` signature
  - **Modernize mock patterns:**
    - `ConfigManagerTest.php`: `$this->returnValue(...)` → `willReturn(...)`
  - **Fix silent exception swallowing:**
    - `RoutesLoader.php`: empty `catch (\InvalidArgumentException $e) {}` → log or re-throw in debug mode
  - **Verify:** `./app/vendor/bin/phpunit` — all tests green
- **Result**: Single PHPUnit config, modern test attributes, no legacy test imports
- **Risk**: Low — test-only changes; PHPUnit suite validates immediately
- **Agent Prompt**: `migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/PROMPT_2_0_6_Test-Infrastructure-Cleanup.md`

---

### Step 2.0.7: Event Dispatcher Bridge Removal

- **Goal**: Remove the unused `SymfonyEventDispatcherBridge` compatibility layer and its associated service registration and test. Pagekit's own Event Dispatcher (`on`/`trigger`/`subscribe`) remains the sole event system — it is deeply integrated, well-tested, and provides features Symfony's dispatcher does not (extra arguments, module manifest events, `PrefixEventDispatcher`).
- **Prerequisite**: Step 2.0.6 (Test Infrastructure Cleanup) completed
- **Context**: Step 1.7 + 1.9 introduced a `SymfonyEventDispatcherBridge` to provide Symfony `EventDispatcherInterface` compatibility. Audit shows **zero production consumers** of the `symfony.event_dispatcher` service — the bridge is dead code. Per Rule 1 (No Compatibility Layers) and Rule 4 (Delete over Wrap), it must be removed.
- **Closes Phase 1 audit:** Step 1.7 (Event System Compatibility) ⚠️ → 🛡️ — `SymfonyEventDispatcherBridge`, its `EventDispatcherCompatibilityTest`, and the `symfony.event_dispatcher` service registration all deleted; PHPStan baseline cleaned (340 lines removed, 0 added; 0 ripgrep hits for `SymfonyEventDispatcherBridge` / `symfony.event_dispatcher` / `EventDispatcherCompatibilityTest` post-merge). Step 1.9 (Symfony 6.4 LTS components) was already 🛡️.
- **Decision**: Pagekit keeps its own dispatcher. Rationale:
  - ~147 call sites (`on`/`trigger`/`subscribe`/`off`) across Kernel, modules, ORM, extensions
  - Unique features: string events with extra arguments, module manifest `events` array, `PrefixEventDispatcher`
  - Replacing would require a Phase-level effort with no clear benefit for extension developers
  - The bridge has zero consumers — removing it is a 3-file change
- **Tasks**:
  - **Delete:** `app/modules/application/src/Event/SymfonyEventDispatcherBridge.php`
  - **Delete:** `app/modules/application/src/Tests/EventDispatcherCompatibilityTest.php`
  - **Update:** `app/modules/application/index.php` — remove `symfony.event_dispatcher` service registration
  - **Verify:** No code references `symfony.event_dispatcher` or `SymfonyEventDispatcherBridge`
  - **Verify:** `./app/vendor/bin/phpunit` green, `php pagekit list` OK
- **Result**: No compatibility bridges in the event system; Pagekit's dispatcher is the single, documented API
- **Risk**: Very Low — 3 files, zero production consumers
- **Agent Prompt**: `migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/PROMPT_2_0_7_Event-Bridge-Removal.md`

---

### Step 2.0.8: Critical Hotfix — `User::hasAccess()` `create_function()` Removal

- **Goal**: Replace `create_function()` in `User::hasAccess()` with a PHP 8.2+-compatible implementation. `create_function()` was **removed in PHP 8.0** and causes a **Fatal Error** when boolean permission expressions (`and`/`or`) are evaluated.
- **Prerequisite**: None — this is a **critical runtime fix** that can be executed at any point.
- **Priority**: HIGHEST — the code path is reachable in production (any permission check with composite expressions).
- **Context**: Phase 1 Audit (Step 1.11/1.13) discovered `create_function()` still in `User::hasAccess()`. This was not caught by prior audits because simple permission checks (`'user: manage users'`) don't trigger the `and`/`or` parser branch.
- **Closes Phase 1 audit (partial):** Step 1.11 (ORM Modernization) — the `User::hasAccess()` `create_function()` removal closes one specific 1.11 finding (legacy code in the `User` model). The remaining 1.11 findings (`EntityManager` singleton, `ModelServiceLocator` / `IntlServiceLocator` static service locators, ORM `Metadata` / `Relation` / `PropertyTrait` typing gaps, `#[AllowDynamicProperties]` on `Node` / `Widget`) are tracked under **Step 2.1.6** (PHPStan Level 8). **1.11 ⚠️ → 🛡️ requires both 2.0.8 and 2.1.6 to land.**
- **Tasks**:
  - **File:** `app/system/modules/user/src/Model/User.php` (~line 221–227)
  - Replace `create_function()` with a safe expression evaluator. Options (in order of preference):
    1. **Simple recursive descent parser** (preferred — no new dependency, ~30 lines, handles `&&` / `||` / `!` and the single-character variants `&` / `|` plus parentheses over permission strings). Note: the existing sanitization regex preserves single `&` / `|` characters, so the evaluator MUST handle both forms or permissions written like `perm1 & perm2` will silently fail. See the canonical grammar in the agent prompt.
    2. **Symfony ExpressionLanguage** (heavier dependency, more flexible — but overkill for `'perm1 && (perm2 || perm3)'`)
    3. **`eval()`** — absolutely NOT acceptable (violates CSP and security goals)
  - Write **unit tests** for composite permission expressions: `'a && b'`, `'a || b'`, `'!a'`, `'(a && b) || c'`, nested parentheses, plus single-operator variants `'a & b'` / `'a | b'` to prove the bitwise / logical equivalence on `0` / `1` values is preserved.
  - Verify: `./app/vendor/bin/phpunit`, `php pagekit list`, admin panel permission checks
- **Result**: `User::hasAccess()` works on PHP 8.2+ with composite permission expressions
- **Risk**: Low-Medium — single method, but affects authorization logic; thorough test coverage required
- **Agent Prompt**: `migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/PROMPT_2_0_8_User-hasAccess-Hotfix.md`

---

## Step 2.1: Static Analysis & Code Quality Tools

- **Goal**: Comprehensive code quality tools and static analysis
- **Prerequisite**: Step 1.14 (Doctrine Attributes) completed
- **Time Estimate**: Split into 9 sub-steps
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

| Step  | Description                       | Time Estimate | Risk        |
| ----- | --------------------------------- | ------------- | ----------- |
| 2.1.1 | Tooling Setup & Baseline          | 1-2 days      | Low         |
| 2.1.2 | CI/CD Integration & Quality Gates | 1-2 days      | Low         |
| 2.1.3 | `strict_types` Migration          | 3-5 days      | Medium-High |
| 2.1.4 | PHPStan Level 5→6 (Return Types)  | 1-2 days      | Low         |
| 2.1.5 | PHPStan Level 6→7 (Null Safety)   | 1-2 days      | Medium      |
| 2.1.6 | PHPStan Level 7→8 (Strict Typing) | 1-2 days      | Medium      |
| 2.1.7 | QueryBuilder API Standardization  | 1-2 days      | Low         |
| 2.1.8 | Infection Mutation Testing        | 2-3 days      | Low         |
| 2.1.9 | Test Coverage Expansion           | Ongoing       | Low         |

---

### Step 2.1.1: Tooling Setup & Baseline

- **Goal**: Install quality tools, document baseline, PSR-12 formatting (without `strict_types`)
- **Prerequisite**: Step 1.14 (Doctrine Attributes) completed
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_1_Tooling-Setup-Baseline.md`
- **Tasks**:
  - Install PHPStan (`phpstan/phpstan`, `phpstan/phpstan-doctrine`, `phpstan/phpstan-symfony`)
  - Configure `phpstan.neon` at Level 5
  - Generate baseline (`phpstan analyse --generate-baseline`) — existing errors documented
  - Install `roave/security-advisories:dev-latest`
  - `.php-cs-fixer.php`: Upgrade `@PSR2` → `@PSR12` (**without** `declare_strict_types` rule!)
  - Run PHP-CS-Fixer: `vendor/bin/php-cs-fixer fix` (formatting only)
  - ESLint/Prettier already configured — no changes needed
- **Result**: Tools running, code is PSR-12 formatted, baseline documented
- **Risk**: Low — formatting only and tool installation

---

### Step 2.1.2: CI/CD Integration & Quality Gates

- **Goal**: Automatic quality checks for every PR
- **Prerequisite**: Step 2.1.1 (Tooling Setup) completed
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_2_CI-CD-Quality-Gates.md`
- **Tasks**:
  - GitHub Actions Workflow: PHPStan check (against baseline, new errors = fail)
  - GitHub Actions Workflow: PHP-CS-Fixer dry-run (style violations = fail)
  - GitHub Actions Workflow: Security audit (`composer audit`)
  - GitHub Actions Workflow: PHPUnit tests
  - Generate code coverage report (document current state)
  - Activate quality gates as required checks for PRs
- **Result**: Every PR is automatically checked, no regression possible
- **Risk**: Low — CI configuration only
- **Note**: Step 2.2 later extends this with E2E tests, matrix builds, and release automation

---

### Step 2.1.3: `strict_types` Migration

- **Goal**: Add `declare(strict_types=1)` to all PHP files
- **Prerequisite**: Step 2.1.2 (CI/CD) completed (so regressions are caught immediately)
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_3_Strict-Types-Migration.md`
- **Tasks**:
  - Migrate **module by module** (not everything at once!)
  - Order: Core modules → System modules → Packages
  - Per module: Add `strict_types` → run tests → fix `TypeError`s
  - Add type casts where needed (`(int)`, `(string)`, etc.)
  - Update PHPStan baseline after each module migration
  - **Audit finding:** ~28 test files currently lack `declare(strict_types=1)` — include these in the migration
  - `.php-cs-fixer.php`: Only activate `declare_strict_types` rule AFTER complete migration
- **Result**: All PHP files have `strict_types`, all tests green
- **Risk**: Medium-High — runtime behavior changes, `TypeError` possible
- **Orchestrator Note**: The Refactorer agent works per module group. After each group: tests → commit → next group. No big bang!
- **Recommended Order**:
  1. `app/modules/filter/` (small, well-tested)
  2. `app/modules/filesystem/` (small, well-tested)
  3. `app/modules/cookie/` (small, well-tested)
  4. `app/modules/auth/` (critical, well-tested)
  5. `app/modules/database/` (core, proceed carefully)
  6. `app/modules/routing/`, `app/modules/view/`, etc.
  7. `app/system/modules/*`
  8. `app/installer/`
  9. `packages/*` (last)

---

### Step 2.1.4: PHPStan Level 5→6 (Return Types)

- **Goal**: Raise PHPStan from Level 5 to Level 6
- **Prerequisite**: Step 2.1.3 (`strict_types` Migration) completed
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_4_PHPStan-Level-6.md`
- **Closes Phase 1 audit:** **Step 1.1 (Mailer Migration) ⚠️ → 🛡️** — the documented 1.1 audit findings (`Mailer::send()` missing `: bool`, `Message::send(&$errors)` untyped out-parameter, `mixed` mailer type in `MailController` / `ResetPasswordController` / `RegistrationController`) are all addressed by the return-type sweep listed under "Audit findings (Phase 1 review)" below.
- **Tasks**:
  - Add missing return types to all methods
  - Clean up union types (e.g., `string|int` → clear decision)
  - Update baseline → fix errors → tests green
- **Result**: PHPStan Level 6 without new baseline entries
- **Risk**: Low — mechanical work, high volume, but logically simple
- **Agent Note**: Refactorer can handle this well — high volume but repetitive patterns
- **Identified from 2.1.1 review:**
  - `AuthDataCollector`: `Auth::getUser()` returns `UserInterface`, but code calls `isAuthenticated()` and `User::findRoles()`, which only exist on the concrete `User` class. Either extend `UserInterface` or narrow the return type of `getUser()`.
  - **Audit findings (Phase 1 review):**
    - `mixed` mailer type in 3 controllers (`MailController`, `ResetPasswordController`, `RegistrationController`) — should be `Pagekit\Mail\Mailer`
    - `Mailer::send()` missing `: bool` return type
    - `Message::send(&$errors)` untyped out-parameter
    - `Post` model: relations as `mixed` instead of `?User`, `?array`
    - `Console execute()` methods: missing `: int` return types, `exit` instead of `return Command::SUCCESS`
    - `Logger::__invoke()` without parameter/return types
    - `TwigLoader::findTemplate()` / `TwigCache::__construct()` missing parent-compatible types
    - `mail/index.php`: unused `auth_mode` config key — remove dead config

---

### Step 2.1.5: PHPStan Level 6→7 (Null Safety)

- **Goal**: Raise PHPStan from Level 6 to Level 7
- **Prerequisite**: Step 2.1.4 (PHPStan Level 6) completed
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_5_PHPStan-Level-7.md`
- **Tasks**:
  - Enforce property types on all class properties
  - Introduce strict null checks (`?string` instead of `string|null`, null guards)
  - "Call to member function on null" — check whether `null` is actually possible or if the type was incorrectly declared
  - Update baseline → fix errors → tests green
- **Result**: PHPStan Level 7 without new baseline entries
- **Risk**: Medium — requires logic understanding ("Can this actually be null here?")
- **Agent Note**: For unclear null logic, involve the Verifier — Refactorer might prematurely add `!= null` guards where the actual problem is an incorrect type

---

### Step 2.1.6: PHPStan Level 7→8 (Strict Typing)

- **Goal**: Raise PHPStan from Level 7 to Level 8 (full type safety)
- **Prerequisite**: Step 2.1.5 (PHPStan Level 7) completed
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_6_PHPStan-Level-8.md`
- **Closes Phase 1 audit:** **Step 1.11 (ORM Modernization) ⚠️ → 🛡️** — the remaining 1.11 audit findings (`EntityManager` singleton, `ModelServiceLocator` / `IntlServiceLocator` static service locators, ORM `Metadata` / `Relation` / `PropertyTrait` typing gaps, `#[AllowDynamicProperties]` on `Node` / `Widget`) are listed under "Audit findings (Phase 1 review)" below. **Requires Step 2.0.8 to also be done** (the `User::hasAccess()` `create_function()` removal closes the User-model portion of 1.11).
- **Tasks**:
  - Eliminate all remaining `mixed` types where avoidable
  - Template parameters for generic collections (where sensible)
  - Documented exceptions for cases where `mixed` is unavoidable (e.g., plugin API)
  - Update baseline → fix errors → tests green
  - ❌ Do NOT use Level 9 (too strict for a CMS with dynamic extension APIs)
  - **Interface design cleanups (identified from 2.1.1 review):**
    - Split `MailerInterface` into `MailerInterface` (send/create) and `MailPluginInterface` (beforeSend/afterSend) — currently mixes mailer and plugin into the same interface
    - `EntityManager`: Remove singleton pattern (`static::$instance`), migrate all call sites to DI
    - `FileLocatorAsset`: Replace static service locator (`setServices()` with `mixed` properties) with DI, type all properties
    - `ResponseListener`: Narrow `mixed $url` property to the correct type (callable/interface)
  - **Audit findings (Phase 1 review):**
    - `ModelServiceLocator` + `IntlServiceLocator` — static service locators; replace with proper DI
    - `PackageController` — `ContainerInterface $app` as God-DI; inject specific services
    - `#[AllowDynamicProperties]` on `Node`, `Widget` — remove and fix dynamic property usage
    - ORM `Metadata`, `Relation`, `PropertyTrait` — incomplete typing throughout
    - `NodeModelTrait` — static request-scoped cache array; replace with proper caching
    - `UrlGeneratorInterface` (Routing) — naming collision with Symfony; rename to `LinkReferenceType` or similar, move `LINK_URL` constant
    - `GetResponseEvent` (Auth) — confusing Symfony-5 naming; rename to `AuthResponseEvent` or similar
  - **Audit findings (Step 2.0 closure review):**
    - `app/modules/database/src/Logging/DebugStack.php` — 42-line dead `@deprecated since DBAL 3.x migration` shim (class + 2 methods) with **zero consumers** in `app/` / `packages/` source (workspace `Grep` for `DebugStack` finds only `migration-docs/` + `CHANGELOG-NEW.md` references). The replacement (`app/modules/debug/src/Middleware/DebugMiddleware.php`) is wired and used. Per Aggressive Rule 4 ("Delete Over Wrap"), this file must be `git rm`'d in 2.1.6 alongside the adjacent `EntityManager` / `ModelServiceLocator` / `IntlServiceLocator` strict-typing work. (Routed from §4.4 Gap List row 1 of `migration-docs/audits/2026/04/AUDIT_REPORT_STEP_2.0_FOUNDATION_CLOSURE_2026-04-28.md`.)
  - **Audit findings (Step 2.1.3 review):**
    - `app/modules/routing/src/Event/AliasListener.php` — dead inline-query-string parser in alias names. The `if (false !== ($queryPos = strpos($aliasName, '?')))` block (lines 50–57, tagged `// TODO: Must be refactored in Step 2.1.6 (PHPStan Level 7→8 / Strict Typing)` per the Rule 5 flagging format) plus the dependent `$name == strtok($alias->getName(), '?')` clause in the `array_filter` (line 39) parse `?param=value` suffixes from the alias `$name` argument of `Routes::alias($path, $name, $defaults)`. Workspace-wide search confirms **zero callers** use this format — `RouteListener` (blog) passes `'@blog/id'`, `NodesListener` passes `$node->link` (a route reference). The `$defaults` parameter has fully replaced this convenience API. **Not to be confused with `Router::generate('route?foo=bar', [...])`** — that is a separate, actively used mechanism in `Router::generate()` (covered by `RouterTest::testGenerateWithQuery()`) and must remain. Per Aggressive Rule 4 ("Delete Over Wrap"), strip the parser block, the `array_filter` `strtok` clause, and the TODO comment together. (Routed from Step 2.1.3 closure — surfaced via user-catch mini-loop iteration 4. The original predecessor comment `// TODO: is this still needed?` predated Rule 5 and was reformatted to the canonical Out-of-scope tag in the same iteration-4 commit.)
- **Result**: PHPStan Level 8 without baseline entries (or with documented, justified exceptions)
- **Risk**: Medium — may require architectural decisions (change interfaces, introduce generics)
- **Agent Note**: Architect decisions may be needed here before the Refactorer starts — not all `mixed` can be replaced by simple type declarations

---

### Step 2.1.7: QueryBuilder API Standardization

- **Goal**: Standardize the DB layer API to Doctrine standards
- **Context**: Currently Pagekit uses a wrapper (execute) that mixes parameter binding and execution. DBAL 3 strictly separates these.
- **Prerequisite**: Step 2.1.2 (CI/CD) completed
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_7_QueryBuilder-API.md`
- **Closes Phase 1 audit:** **Step 1.5 (Doctrine DBAL 3.x) ⚠️ → 🛡️** — the documented 1.5 audit findings (`Connection::exec()` compat alias, `Utility::getSchemaManager()` deprecated → `createSchemaManager()`, `Utility::migrate()` legacy `Comparator()` → `$schemaManager->createComparator()`, DDL via `executeQuery()` → `executeStatement()`, `DbUtil::$realConn->exec()` → `executeStatement()`) are all listed under "Audit findings (Phase 1 review) — additional DBAL cleanup" below.
- **Tasks**:
  - Make methods `executeQuery()` and `executeStatement()` in `Pagekit\Database\Query\QueryBuilder` public
  - Migrate all core calls from `$qb->execute()` to `$qb->executeQuery()` / `$qb->executeStatement()` (Rector or search-replace)
  - **Remove** the old `execute()` method (DELETE OVER WRAP — no `@deprecated` compat layer!)
  - **DBAL Type Normalization:**
    - `JsonArrayType`: Change `getName()` from `'json_array'` to `'json'`
    - Change all `#[ORM\Column(type: 'json_array')]` in entity attributes to `type: 'json'` (`DataModelTrait`, `DatabaseHandler`)
    - `ModelTrait::toArray()`: Change `case 'json_array'` to `case 'json'`
    - `database/index.php`: Remove redundant `Type::addType('json_array', ...)` registration
    - `Connection::registerCustomTypeMappings()`: Simplify/remove `json→json_array` mapping
    - `SimpleArrayType`: Update comments (not a compat layer, but JSON fallback parsing)
  - **Audit findings (Phase 1 review) — additional DBAL cleanup:**
    - `Connection::exec()` — compat alias for `executeStatement()`; remove alias, update call sites
    - `Utility::getSchemaManager()` — deprecated in DBAL 3; use `createSchemaManager()` (also in `Installer.php`, `DbUtil.php`)
    - `Utility::migrate()` — uses `new Comparator()` without Platform (deprecated); use `$schemaManager->createComparator()`
    - `Utility::migrate()` — executes DDL via `executeQuery()` instead of `executeStatement()`
    - `DbUtil` (test helper): `$realConn->exec()` → `executeStatement()`
  - All tests green after migration
- **Result**: Standard Doctrine documentation usable, IDEs recognize correct return types (`Result`)
- **Risk**: Low — purely internal API change, all call sites updated in the same step
- **⚠️ Agent Note (DBAL 3 Return Types)**: `executeQuery()` returns a `Doctrine\DBAL\Result`, NOT a PDO Statement or Boolean like the old `execute()`. All call sites must be migrated to the DBAL 3 Result API:
  - `->fetchAll(PDO::FETCH_ASSOC)` → `->fetchAllAssociative()`
  - `->fetch(PDO::FETCH_ASSOC)` → `->fetchAssociative()`
  - `->fetchColumn()` → `->fetchOne()`
  - `->rowCount()` → stays `->rowCount()` (only for INSERT/UPDATE/DELETE via `executeStatement()`)
  - The Refactorer agent MUST check each call site individually — no blind search-replace!

---

### Step 2.1.8: Infection Mutation Testing

- **Goal**: Introduce mutation testing for security-critical modules
- **Prerequisite**: Step 2.1.6 (PHPStan Level 8) completed + sufficient test coverage (at least 60%+)
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_8_Infection-Mutation-Testing.md`
- **Tasks**:
  - Install Infection (`infection/infection`)
  - Configure `infection.json.dist`
  - Run only for critical modules:
    - `app/modules/auth` (Authentication)
    - `app/system/modules/user` (User Management)
    - NOT for views, templates, markdown (too slow, not critical)
  - Target: 80%+ mutation score for critical modules
- **Result**: Security-critical code is verified through mutation testing
- **Risk**: Low — test tooling only, no code changes

---

### Step 2.1.9: Test Coverage Expansion

- **Goal**: Systematically raise test coverage to target levels
- **Prerequisite**: Step 2.1.2 (CI/CD with coverage reports) completed
- **Time Estimate**: Ongoing (parallel to all further Phase 2 steps)
- **Prompt**: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_9_Test-Coverage-Expansion.md`
- **Closes Phase 1 audit:** **Step 1.10.5 (E2E Testing with Playwright) ⚠️ → 🛡️** — the documented 1.10.5 finding ("Most E2E tests were poorly created, not following best practices; only first 3 tests are reasonably functional. Full E2E rework needed.") is listed under "Audit findings (Phase 1 review)" below. Also picks up the cross-cutting `MenuApiController` manual-validation finding (carryover from the 1.13 audit, not a 1.13 audit finding itself).
- **Tasks**:
  - Coverage targets:
    - Core Modules (`app/modules/`): 80%+
    - System Modules (`app/system/modules/`): 75%+
    - Packages (`packages/`): 60%+
  - Write tests for affected modules with each step
  - Track coverage trends in CI (HTML reports)
  - Edge-case tests for real Pagekit scenarios:
    - Large file uploads (Storage Module)
    - Concurrent admin actions (Session Handling)
    - Database connection failures (ORM Error Handling)
    - `AddRelNofollowFilter` XSS edge cases: Harden filter + activate 3 disabled tests (slash instead of space, null-byte obfuscation, `rel="follow"` replacement) — see `app/modules/filter/src/Tests/AddRelNofollowTest.php`
  - **Audit findings (Phase 1 review):**
    - E2E tests (Step 1.10.5): Most were poorly created, not following best practices; only first 3 tests are reasonably functional. Full E2E rework needed.
    - ~~`MigrationServiceTest` — all tests skipped; write real migrate/rollback coverage~~ (RESOLVED in PR #189, Step 2.0.4 — 12 real tests with in-memory SQLite)
    - `MenuApiController` — manual validation without `#[Assert\...]` / `ValidatesRequestTrait`; add validation + tests
    - `assertEquals` vs `assertSame` — ~200+ occurrences where strict comparison would be more appropriate
  - **Audit findings (Step 2.0.4 review):**
    - `PackageManager::enable()`/`uninstall()` migration integration — no integration tests for auto-migrate on enable, auto-rollback on uninstall, or partial rollback to pre-migration version. Unit-level MigrationService methods are tested.
    - `MigrationCommand` CLI flow — no integration test for the unified Doctrine migrations + scripts pipeline with version bump guard.
- **Result**: Coverage grows organically with every change
- **Risk**: Low — continuous improvement, no big bang
- **Note**: No separate branch — coverage tests are delivered in every feature branch

---

### Step 2.2: CI/CD Pipeline

- **Goal**: Automated CI/CD with E2E & static analysis integration
- **Prerequisite**: Steps 1.10.5 (E2E Tests) and 2.1 (Static Analysis) completed
- **Tasks**:
  - **Workflow 1 — PHP Tests** (`.github/workflows/php-tests.yml`):
    PHPUnit (PHP 8.2/8.3/8.4 × MySQL 8.4/SQLite 3), PHPStan analysis, PHP-CS-Fixer dry-run, security audit
  - **Workflow 2 — E2E Tests** (`.github/workflows/e2e-tests.yml`):
    Playwright on Chromium, fixed backend (PHP 8.3 + MySQL 8.4), 3 viewport jobs (Mobile 375×667, Tablet 768×1024, Desktop 1920×1080)
  - **Workflow 2b — Cross-Browser Tests** (`.github/workflows/e2e-cross-browser.yml`):
    Weekly schedule + manual trigger, Firefox + WebKit, critical test subset only
  - **Workflow 3 — Frontend Tests** (`.github/workflows/frontend-tests.yml`):
    ESLint, Prettier, Yarn build verification
  - Quality gates as required checks for PRs
  - Dependency caching for fast CI (target: all workflows < 10 minutes)
  - Release automation
  - ~~Deploy previews~~ (optional, later)
- **Design Decisions**:
  - E2E tests UI interaction, NOT backend variants — PHPUnit covers the PHP/DB matrix
  - Cross-browser testing runs weekly, not per-PR (catches rendering bugs without blocking PRs)
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

### Step 2.3: Docker Production Setup

- **Goal**: Production-ready Docker
- **Tasks**:
  - Multi-stage builds
  - Alpine Linux images
  - Docker Compose optimization
  - Kubernetes ready

---

### Step 2.4: Build Tools Modernization

- **Goal**: Modern build pipeline
- **Options**:
  - Webpack 5 migration
  - Yarn Berry evaluation (or stay on Yarn 1.22)
  - Vite as an alternative to Webpack
  - ESBuild for faster builds
  - pnpm as a package manager alternative

---

### Step 2.5: Extension Safety & Fault Isolation

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
  - `User::evaluateBooleanExpression()` (`app/system/modules/user/src/Model/User.php:251`) — extract into a standalone `PermissionExpressionEvaluator` service **only if and when a second caller emerges**. As of 2.0.8 closure there is exactly one caller (`User::hasAccess()`), so per Aggressive Rules 1 ("No Compatibility Layers") + 2 ("No Adapters") the helper stays inline as a `private static` method on `User`. No code change required in 2.5 unless extension code or a new permission system surfaces a second caller. Documentation-only route from `migration-docs/branches/step-2-0-8-user-hasaccess-hotfix.md:213-217`. (Routed from §4.4 Gap List row 2 of `migration-docs/audits/2026/04/AUDIT_REPORT_STEP_2.0_FOUNDATION_CLOSURE_2026-04-28.md`.)

---

### Step 2.6: Automated Update System - External & Background Updates

- **Goal**: Modern, future-proof update infrastructure for Pagekit CMS
- **Priority**: High (required for long-term maintainability)
- **Context**: `migration-docs/TODO/features/AUTOMATED_UPDATE_SYSTEM.md`
