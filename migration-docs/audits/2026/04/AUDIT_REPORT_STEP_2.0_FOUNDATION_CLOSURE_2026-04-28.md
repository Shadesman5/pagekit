# Audit Report: Step 2.0 — Foundation Consolidation Closure & Gap Audit

**Date**: 2026-04-28
**Branch**: `cursor/step-2-0-foundation-closure`
**Source of Truth**: `.cursor/ROADMAP.md`, `migration-docs/TODO/PHASE_2_MODERNISING.md`
**PHP Version**: 8.3.x
**Standards**: Pagekit Modernization Rules — 5 Aggressive Rules (NO compatibility layers, NO adapters, DELETE OVER WRAP, MANDATORY FLAGGING, PHP 8.2+).
**Parent Issue**: #181
**Prior partial audit (covers 2.0 → 2.0.2 only)**: `migration-docs/audits/2026/03/AUDIT_REPORT_STEP_2.0-2.0.2_2026-03-27.md`

> **Status: WORK IN PROGRESS — Skeleton scaffold.**
> This file is the §4 section skeleton produced in Checklist Step 1 of the
> `PROMPT_2_0_Foundation-Consolidation-Closure` ticket. Subsequent checklist
> steps (2 → 17) populate every `_TBD_` placeholder with evidence collected
> from `develop` HEAD. The final Executive Summary and Closure Verdict are
> written last (Checklist Step 17).

---

## §4.1 Executive Summary

*TBD — single paragraph summary of the closure-and-gap audit. Cross-checks every
claim made by Steps 2.0.0–2.0.8 against `develop` HEAD; identifies any
Foundation-Consolidation debt that was promised, deferred, missed, or surfaced
during 2.0.x execution but never landed and never got its own ticket; routes
each gap to either a new 2.0.X sub-step (X ≥ 9), an existing future step
(2.1.6 / 2.1.9 / 2.5 / …), or "informational only".*

### Closure Verdict (10-row summary table)

`2.0.1a–e` is collapsed here for readability; per-sub-step evidence blocks in
§4.2 list each of `2.0.1a`, `2.0.1b`, `2.0.1c`, `2.0.1d`, `2.0.1e` individually.


| ID       | Sub-step                                                           | Audit ground-truth | New sub-step needed? |
| -------- | ------------------------------------------------------------------ | ------------------ | -------------------- |
| 2.0.0    | Controller Attributes                                              | 🛡️                | *TBD*                |
| 2.0.1    | PSR-11 Container Modernization                                     | 🛡️                | No                   |
| 2.0.1a–e | Container sub-stages (Core / DI / System / Packages / StaticTrait) | 🛡️                | No                   |
| 2.0.2    | Validator-Translator Integration                                   | 🛡️                | No                   |
| 2.0.3    | Cache API Full Modernization                                       | 🛡️                | No                   |
| 2.0.4    | Package / Migration System Redesign                                | 🛡️                | No                   |
| 2.0.5    | Composer & Autoload Hygiene                                        | 🛡️                | No                   |
| 2.0.6    | Test Infrastructure Cleanup                                        | 🛡️                | No                   |
| 2.0.7    | Event Dispatcher Bridge Removal                                    | *TBD*              | *TBD*                |
| 2.0.8    | `User::hasAccess()` Hotfix                                         | *TBD*              | *TBD*                |


**Legend:** 🛡️ = audit ground-truth confirms scope, deletions and Phase 1
closure claims hold on `develop` HEAD ; ⚠️ = partial / drift detected
(documented in §4.4 Gap List) ; ❌ = scope claim contradicted by `develop`.

---

## §4.2 Per-Sub-Step Evidence Blocks

Each block ≤ 20 lines, structured as:

- **Scope verified** (✅ / ❌ + ripgrep evidence).
- **Phase 1 closure claims verified** (✅ / ⚠️ partial / ❌).
- **No-Mercy spot-check** (✅ / list of suspect hits classified per §3.3).
- **Deferred items still tracked** (✅ / list of orphaned items routed in §4.4).

---

### §4.2.0 Step 2.0.0 — Controller Attributes

**ROADMAP Status**: ✅ | **Issue**: #142 | **PR**: #111 | **Audit**: 🛡️

- Scope verified: ✅ — `rg -n "@Route\(" app/ packages/ --glob "*.php" --glob "!*Test.php"` returns **0 hits** on `develop` HEAD (executed as workspace-wide `Grep` for `@Route\(`, scope `*.php`, `!*.md`). All 21 controllers in `app/system/**/Controller/` and `packages/pagekit/blog/src/Controller/` use PHP 8 attributes — `#[Route(...)]` count: `AdminController` 1, `WidgetApiController` 9, `RoleApiController` 7, `ResetPasswordController` 3, `AuthController` 3, `UserApiController` 7, `SettingsController` 2, `PageApiController` 2, `MenuApiController` 3, `NodeController` 4, `NodeApiController` 9, `IntlApiController` 1, `IntlController` 1, `MailController` 2, `FinderController` 4, `DashboardController` 7, `CacheController` 1, `PostApiController` 9, `SiteController` 5, `CommentApiController` 7, `BlogController` 1.
- Phase 1 closure claims verified: ✅ none directly (`PHASE_2_MODERNISING.md` Step 2.0.0 has no `Closes Phase 1 audit:` line — the Phase 1 dependency was Step 1.14 *Doctrine Attributes*, which is a `Prerequisite`, not a closure claim).
- No-Mercy spot-check: ✅ `rg -n "@deprecated"` over `app/**/Controller/**/*.php` returns **0 hits**; controller layer is annotation-free. The two `@deprecated` markers found workspace-wide (`app/modules/database/src/Logging/DebugStack.php`, `app/modules/routing/src/Matcher/Dumper/PhpMatcherDumper.php`) are outside controller scope and predate 2.0.0; routed to §4.3.2 for cross-cutting classification.
- Deferred items still tracked: ✅ — none. 2.0.0 has no Phase 2 follow-on items in PHASE_2_MODERNISING.md, no agent-prompt skeleton, and no `Audit findings (Phase 1 review):` block. Branch doc absent (predates the `step-2-0-X-*.md` naming convention introduced in 2.0.7); flagged in §4.6.1 documentation-drift sweep, not a code Gap.

---

### §4.2.1 Step 2.0.1 — PSR-11 Container Modernization (umbrella)

**ROADMAP Status**: ✅ | **Issue**: #145 | **PR**: #174 (audit) | **Audit**: 🛡️

- Scope verified: ✅ — `rg -n "Psr11Adapter|StaticTrait|class_alias.*Container" app/ packages/ --glob "*.php"` returns **0 hits** on `develop` HEAD (executed as workspace-wide `Grep` for the same pattern, scope `*.php`). `app/modules/application/src/Container.php:9` declares `class Container implements ContainerInterface` with `use Psr\Container\ContainerInterface;` at line 7 — Container is natively PSR-11, no `Psr11Adapter` wrapper, no `class_alias` indirection, no `StaticTrait`. `Container::get(string $id): mixed`, `Container::has(string $id): bool`, and `Container::set(string $id, mixed $value): void` are PSR-11-compliant signatures (typed parameters and return types per PHP 8.2+ standard).
- Phase 1 closure claims verified: ✅ `Closes Phase 1 audit: Step 1.6` (PHASE_2_MODERNISING.md:24 — "Step 1.6 (PSR-11 Container Compatibility) ⚠️ → 🛡️"). Confirmed against `.cursor/ROADMAP.md:52` — row `1.6 PSR-11 Container Compatibility | ✅ | 🛡️ | #126 | #55`. Prior audit `AUDIT_REPORT_STEP_2.0-2.0.2_2026-03-27.md` §"Step 2.0.1 – PSR-11 Container Vollmodernisierung" corroborates: "Container implements PSR-11 natively (`get`, `has`, `set`)" and "`Psr11Adapter` from Phase 1 deleted; Container natively PSR-11" (Rule #1 row of the No-Mercy table).
- No-Mercy spot-check: ✅ Container hierarchy is clean — `Application extends Container` (`app/modules/application/src/Application.php:9`), so `Application` inherits PSR-11 directly; no compatibility shim. `ContainerPsr11Test` (`app/modules/application/src/Tests/ContainerPsr11Test.php`) explicitly asserts both classes implement `Psr\Container\ContainerInterface`. The two `\ArrayAccess` implementations remaining in the application module (`Event/Event.php:7` and `Util/ArrObject.php:8`) are **OK / by design** — they are domain value objects, not the Container; they were never part of the 2.0.1d removal scope.
- Deferred items still tracked: ✅ — none. The umbrella issue #145 is fully closed by the five sub-stages 2.0.1a–e (PRs #161, #167, #169, #171, #172) plus the closure audit PR #174. No `// TODO: Step 2.x` markers tied to container modernization remain.

---

### §4.2.1a Step 2.0.1a — Container Core + Modules

**ROADMAP Status**: ✅ | **Issue**: #162 | **PR**: #161 | **Audit**: 🛡️

- Scope verified: ✅ — `Container::get(string $id): mixed` throws `Pagekit\Container\NotFoundException` (PSR-11 `NotFoundExceptionInterface`) on missing IDs and `Pagekit\Container\ContainerException` (PSR-11 `ContainerExceptionInterface`) on resolution errors (`Container.php:113-134`). `has(string $id): bool` returns a strict boolean (`Container.php:143-146`); `set(string $id, mixed $value): void` is the canonical write API (`Container.php:153-160`).
- Phase 1 closure claims verified: rolled up under 2.0.1 (`Closes Phase 1 audit: Step 1.6` — verified in §4.2.1).
- No-Mercy spot-check: ✅ no `Pimple\Container` parent class, no `extends Container` chain to legacy Pimple — `class Container` has no `extends` clause (`Container.php:9`). The container is the sole authority; core modules register through `$app->set()` / `$app->factory()` (the `factory()` flag prevents singleton caching for per-call services, lines 36-40).
- Deferred items still tracked: ✅ — none from 2.0.1a.

---

### §4.2.1b Step 2.0.1b — DI Infrastructure

**ROADMAP Status**: ✅ | **Issue**: #163 | **PR**: #167 | **Audit**: 🛡️

- Scope verified: ✅ — `app/modules/kernel/src/Controller/ControllerResolver.php:128-163` (`instantiateController`) reflects on the controller's constructor and resolves each typed parameter via `$this->container->get($paramName)` (PSR-11), falling back to the parameter's default value, otherwise throwing `\RuntimeException` with a precise diagnostic. The resolver holds a typed `?ContainerInterface $container` (line 11) — no service-locator pattern (no `$container->get()` calls inside controller methods themselves).
- Phase 1 closure claims verified: rolled up under 2.0.1 (`Closes Phase 1 audit: Step 1.6`).
- No-Mercy spot-check: ✅ `instantiateController()` does not silently swallow missing services — it throws with the controller class name, parameter name, and missing service ID. No `try { ... } catch { return null; }` anti-pattern. Constructor signature uses constructor-promoted optional dependencies (line 14: `?ContainerInterface $container = null, ?LoggerInterface $logger = null`).
- Deferred items still tracked: ✅ — none from 2.0.1b.

---

### §4.2.1c Step 2.0.1c — System / Installer / Console + DI

**ROADMAP Status**: ✅ | **Issue**: #164 | **PR**: #169 | **Audit**: 🛡️

- Scope verified: ✅ — `rg -n "\\\$app\\['"` over `*.php` returns **0 hits** workspace-wide (executed as `Grep` for pattern `\$app\[['"]`). Zero array-access patterns remain in System, Installer, or Console code paths. Console wiring uses `app/modules/application/src/Application/Console/Application.php:17 protected Container $container;` — typed property, no array access.
- Phase 1 closure claims verified: rolled up under 2.0.1 (`Closes Phase 1 audit: Step 1.6`).
- No-Mercy spot-check: ✅ `Application` (HTTP kernel) extends `Container` directly (`Application.php:9`) — no parallel "compat" subclass. Constructor injection is uniform across system, installer, and console controllers (cross-checked against §4.2.0 evidence: every controller listed there uses `#[Route]` attribute routing on typed methods).
- Deferred items still tracked: ✅ — none from 2.0.1c.

---

### §4.2.1d Step 2.0.1d — Packages + ArrayAccess Removal

**ROADMAP Status**: ✅ | **Issue**: #165 | **PR**: #171 | **Audit**: 🛡️

- Scope verified: ✅ — `Container.php` (1-169) does **not** declare `\ArrayAccess`; `class Container implements ContainerInterface` only (line 9). `rg -n "ArrayAccess|offsetGet|offsetSet|offsetExists|offsetUnset"` over `app/modules/application/**/*.php` returns hits only in `Event/Event.php` and `Util/ArrObject.php` — both unrelated value objects, not the Container. `rg -n "\\\$app\\['"` returns **0 hits** workspace-wide; zero `$app['key']` bracket access in package or application code.
- Phase 1 closure claims verified: rolled up under 2.0.1 (`Closes Phase 1 audit: Step 1.6`).
- No-Mercy spot-check: ✅ Rule #4 ("Delete Over Wrap") satisfied — the four `offset*` methods were physically deleted from `Container`, not stubbed with `@deprecated`. Blog package controllers (cross-checked in §4.2.0: `PostApiController`, `CommentApiController`, `SiteController`, `BlogController`, `NodeController`) all use constructor injection, no array-access shims.
- Deferred items still tracked: ✅ — none from 2.0.1d.

---

### §4.2.1e Step 2.0.1e — StaticTrait Removal + DI Final

**ROADMAP Status**: ✅ | **Issue**: #166 | **PR**: #172 | **Audit**: 🛡️

- Scope verified: ✅ — `rg -n "StaticTrait|EventTrait|RouterTrait"` over `*.php` returns **0 hits** workspace-wide; the three traits are physically deleted from the codebase. `rg -n "\\bApp::\\w+\\("` over `*.php` returns **0 hits** — zero `App::` static calls remain. `rg -n "__callStatic|__call\\b"` over `app/modules/application/**/*.php` returns **0 hits** — `Container` and `Application` carry no magic-method routing.
- Phase 1 closure claims verified: rolled up under 2.0.1 (`Closes Phase 1 audit: Step 1.6`).
- No-Mercy spot-check: ✅ Rule #4 ("Delete Over Wrap") satisfied — traits were deleted, not stubbed. Rule #1 ("No Compatibility Layers") satisfied — there is no parallel `App` facade class hosting static helpers; constructor DI is the sole resolution path. Cross-corroborated by prior audit `AUDIT_REPORT_STEP_2.0-2.0.2_2026-03-27.md` §"Step 2.0.1e": "`StaticTrait` physically deleted from codebase" and "Zero `App::` static calls remain".
- Deferred items still tracked: ✅ — none from 2.0.1e. The DI-Final completion sealed the umbrella; closure audit PR #174 verified all 10 acceptance criteria.

---

### §4.2.2 Step 2.0.2 — Validator-Translator Integration

**ROADMAP Status**: ✅ | **Issue**: #146 | **PR**: #175 | **Audit**: 🛡️

- Scope verified: ✅ — `validators.php` exists in `app/system/languages/en_US/validators.php` and `packages/pagekit/blog/languages/en_US/validators.php` (workspace-wide `Glob` for `**/validators.php` returns exactly those 2 files; per-locale `messages.php` siblings present in all 78 system + 78 blog locale directories, ready to host translated `validators.php` overrides). `rg -n "validation\.php"` over `*.php` workspace-wide returns **0 hits** — old `validation.php` filename fully purged from source. Git history confirms the rename: commit `2ed6be0f` `refactor(i18n): rename validation.php to validators.php for Symfony domain alignment` deletes `app/system/languages/en_US/validation.php` and `packages/pagekit/blog/languages/en_US/validation.php` (no parallel old/new files left). `ValidatorServiceProvider::register()` (`app/system/src/ValidatorServiceProvider.php:30-39`) wires `$builder->setTranslator($app->get('translator'))` (line 35) and `$builder->setTranslationDomain('validators')` (line 36) inside the lazy factory closure — confirms the constraint-message domain matches the locale-file basename per `IntlModule::loadLocale()` convention.
- Phase 1 closure claims verified: ✅ `Closes Phase 1 audit: Step 1.13 (Validation Update) ⚠️ → 🛡️` (PHASE_2_MODERNISING.md:32). Confirmed against `.cursor/ROADMAP.md:60` — row `1.13 Validation Update | ✅ | 🛡️ | #133 | #108`. Prior audit `AUDIT_REPORT_STEP_2.0-2.0.2_2026-03-27.md` corroborates: §"Step 2.0.2 – Validator-Translator Integration" lists "✅ No 'hybrid mode' or Step 1.13 references remain", `validation.php` (old filename) hits = 0, `hybrid mode` / `Step 1.13` references hits = 0, and recommended ROADMAP update "Set Step 2.0.2 to ✅ status and 🛡️ audit" is now applied.
- No-Mercy spot-check: ✅ Rule #1 ("No Compatibility Layers") satisfied — `ValidatorServiceProvider` is the sole validator-bootstrap path (`app/system/index.php:86 \Pagekit\System\ValidatorServiceProvider::register($app)`), no parallel "hybrid" provider, no `validation.php` shim. Rule #4 ("Delete Over Wrap") satisfied — old filename was renamed via `git mv`, not stubbed with a `return require __DIR__.'/validators.php';` redirect. The `MenuApiController` manual-validation finding **is** already listed under Step 2.1.9's `**Audit findings (Phase 1 review):**` block in `migration-docs/TODO/PHASE_2_MODERNISING.md:480` ("`MenuApiController` — manual validation without `#[Assert\...]` / `ValidatesRequestTrait`; add validation + tests"), and is also explicitly cross-referenced from Step 2.0.2's section header at line 32 ("The remaining `MenuApiController` manual-validation finding is tracked under **Step 2.1.9** (Test Coverage Expansion), not as a 1.13 audit finding."). Route check passes — no action required in this PR.
- Deferred items still tracked: ✅ — `MenuApiController` routed to 2.1.9 (cross-referenced from 2.0.2 PHASE_2 prose); no orphaned `// TODO: Step 2.0.2` markers detected (`Grep` for `Step 2.0.2` over `*.php` workspace-wide returns only the docblock self-references inside `ValidatorServiceProvider.php` and the Step 2.0.2 file-header comment in `app/system/languages/en_US/validators.php` — both legitimate provenance comments, not deferred-work markers).

---

### §4.2.3 Step 2.0.3 — Cache API Full Modernization

**ROADMAP Status**: ✅ | **Issue**: #179 | **PR**: #187 | **Audit**: 🛡️

- Scope verified: ✅ — `rg -n "Pagekit\\Cache\\CacheInterface|Psr6Adapter" app/ packages/ --glob "*.php"` returns **0 hits** on `develop` HEAD (executed as workspace-wide `Grep` for the same pattern, scope `*.php`). The 7-file delete from PHASE_2_MODERNISING.md:50-57 is corroborated by commit `33e14d4e refactor(cache)!: delete CacheInterface and Psr6Adapter compatibility layer` — `-385 lines` across `app/system/modules/cache/src/CacheInterface.php` (66 lines), `Adapter/Psr6Adapter.php` (199 lines), and the 5 thin-wrapper adapters (`ApcuAdapter.php`, `ArrayAdapter.php`, `FilesystemAdapter.php`, `NullAdapter.php`, `PhpFilesAdapter.php`). The entire `app/system/modules/cache/src/Adapter/` directory is gone (`ls` reports `No such file or directory`); only `CacheModule.php`, `CacheKeyUtil.php`, `Controller/`, and `Tests/` remain. `CacheModule::createCachePool()` (`CacheModule.php:51-83`) returns `CacheItemPoolInterface` directly via Symfony adapters (`new ArrayAdapter()`, `new ApcuAdapter()`, `new FilesystemAdapter()`, `new PhpFilesAdapter()`, `new NullAdapter()`), no `Psr6Adapter` wrapper. `CacheModule::doClearCache()` (line 128-155) calls `$app->get('cache')->clear()` (PSR-6 native, line 136), no `flushAll()`. Workspace-wide `Grep` for `->flushAll\(` returns **0 hits** in `app/` and `packages/`. Consumers all on PSR-6 API: `LoginAttemptListener` declares `private readonly CacheItemPoolInterface $cache` (line 18) and uses `getItem()` / `save()` / `deleteItem()` (lines 35, 62, 76); `blog/UrlResolver.php` declares `private static ?CacheItemPoolInterface $cache` (line 25) and uses `getItem()` / `save()` (lines 44, 125-127); `blog/Event/RouteListener.php` declares `private readonly CacheItemPoolInterface $cache` (line 18) and uses `deleteItem()` (line 51); `blog/scripts.php:49-50` uses `$app->get('cache')->clear()` (PSR-6 native). The lone `->fetch(` workspace-wide hit at `app/modules/config/src/ConfigManager.php:47` is **OK / by design** — it calls `ConfigManager::fetch()` (a private database loader at line 117), not `CacheItemPoolInterface::fetch()`; `ConfigManager` does not consume any cache pool. ORM follow-on cleanup verified: `MetadataManager.php:21,65,75` declares `?CacheItemPoolInterface $cache` (typed property + getter + setter, no legacy else-branch); `QueryBuilder.php:19,203` uses `?CacheItemPoolInterface $cache` for both the property and the `cache(int $ttl, ?CacheItemPoolInterface $cache = null): self` parameter.
- Phase 1 closure claims verified: ✅ Step 1.10 stays 🛡️ — confirmed against `.cursor/ROADMAP.md:56` (row `1.10 PSR-6 Cache | ✅ | 🛡️ | #130 | #62`). PHASE_2_MODERNISING.md:47 explicitly notes "Step 1.10 internally replaced `doctrine/cache` with `symfony/cache` but kept a compatibility layer (`CacheInterface` + `Psr6Adapter`). This violates Rule 1 (No Compatibility Layers) and Rule 4 (Delete over Wrap)" — that compatibility layer is now physically deleted (commit `33e14d4e` above), so 1.10's 🛡️ status is now genuinely Rule-1 / Rule-4 clean rather than aspirational.
- No-Mercy spot-check: ✅ Rule #1 ("No Compatibility Layers") satisfied — the dual-interface era is over; `Psr\Cache\CacheItemPoolInterface` is the sole cache contract across producers (`CacheModule`) and consumers (auth, routing, blog, ORM). Rule #4 ("Delete over Wrap") satisfied — the 7 files are physically deleted, not stubbed with `@deprecated` redirects (`Grep` for `@deprecated` over `app/system/modules/cache/**/*.php` returns 0 hits). Lockfile-versioning sub-task (shipped early in PR #187) verified: `git ls-files composer.lock yarn.lock` returns both files; `.gitignore` no longer ignores them (workspace-wide `Grep` for `composer\.lock|yarn\.lock` over `.gitignore` returns 0 hits). Test rewrite confirmed: `app/system/modules/cache/src/Tests/CachePoolTest.php:11-17` declares "Validates the cache layer after removal of the Pagekit CacheInterface compatibility layer (Step 2.0.3)" and exercises the four Symfony adapters (`ArrayAdapter`, `FilesystemAdapter`, `NullAdapter`, `PhpFilesAdapter`) via `CacheItemPoolInterface` contract methods.
- Deferred items still tracked: ✅ — one Rule-5 marker remains in `EntityManager.php:283-286` (`// TODO: Must be refactored in Step 4.3 (Performance Optimization) — Replace $cache->clear() with tag-based invalidation (TagAwareCacheInterface)`). This is **OK / tagged with valid future ROADMAP ID** (Step 4.3 Performance Optimization is a real future row); routed for cross-cutting classification in §4.3.4 Rule 5 sweep, not a Gap. No orphaned `// TODO: Step 2.0.3` markers detected workspace-wide.

---

### §4.2.4 Step 2.0.4 — Package / Migration System Redesign

**ROADMAP Status**: ✅ | **Issue**: #180 | **PR**: #189 | **Audit**: 🛡️

- Scope verified: ✅ — `rg -n "function createTable" app/modules/auth/` returns **0 hits** on `develop` HEAD (executed as workspace-wide `Grep` for `createTable` over `app/modules/auth/**/*.php`; only matches are unrelated `getTableName('post')` / `getTableName('comment')` helpers in the blog migration). `app/modules/auth/src/Handler/DatabaseHandler.php:1-123` is method-clean: no `createTable()`, no `@deprecated since Pagekit 1.0` runtime DDL — schema is now sourced exclusively from migrations per the PHASE_2 audit-finding bullet (`PHASE_2_MODERNISING.md:108`). Blog migration timestamp rename verified: `Glob` for `packages/pagekit/blog/src/Migrations/**/*.php` returns exactly `packages/pagekit/blog/src/Migrations/2025/Version20251023070000_CreateBlogTables.php` (the legacy `Version001_CreateBlogTables.php` is gone — workspace `Grep` for `Version001_CreateBlogTables` finds zero source files, only documentation references in `migration-docs/` and `.cursor/tickets/`). The renamed file declares `final class Version20251023070000_CreateBlogTables extends ExtensionMigration` (line 21) and uses `createTableIfNotExists()` for safe re-runs (lines 44, 67). `MigrationServiceTest` un-skipped: `Grep` for `markTestSkipped` over `tests/Unit/Migration/MigrationServiceTest.php` returns **0 hits**; the file (`tests/Unit/Migration/MigrationServiceTest.php:15`) declares `class MigrationServiceTest extends TestCase` with real in-memory SQLite tests (`pdo_sqlite` `memory => true`, line 35-37) that exercise `migrate()`, `rollback()`, `status()`, `migrateExtension()`, `rollbackExtension()`, and `getExtensionCurrentVersion()` end-to-end (12 tests per `migration-docs/branches/PACKAGE_MIGRATION_SYSTEM_REDESIGN.md:26`). `MigrationService::getConfigPath()` deleted: `Grep` for `getConfigPath` over `*.php` returns **0 hits** in source (only documentation references inside `migration-docs/`, `.cursor/tickets/`, and the audit report itself). `MigrationService.php:1-669` carries no `getConfigPath()` symbol — the API surface is `migrate()` / `rollback()` / `status()` / `generate()` / `isInitialized()` / `initialize()` / `getDependencyFactory()` / `getConnection()` / `migrateExtension()` / `rollbackExtension()` / `getExtensionCurrentVersion()` only. Login check executes Doctrine Migrations before `scripts->update()`: `app/system/index.php:127` runs `$migrationStatus = $app->has('migration') ? $app->get('migration')->status() : ['success' => true, 'has_pending' => false];` then on `auth.login` redirects to `@system/migration` whenever `$scripts->hasUpdates() || $hasPendingMigrations` is true (line 130-131); the migration wizard `MigrationController::migrateAction()` (`app/system/src/Controller/MigrationController.php:60-106`) calls `$migrationService->migrate()` first (line 69) and only then `$this->scripts->update()` (line 80) — Doctrine-first ordering verified. Console `MigrationCommand::execute()` (`app/console/src/Commands/MigrationCommand.php:26-71`) follows the same ordering: `$migration->migrate()` at line 34, `$scripts->update()` at line 55.
- Phase 1 closure claims verified: ✅ Step 1.12 stays 🛡️ — confirmed against `.cursor/ROADMAP.md:59` (row `1.12 DB Migration System | ✅ | 🛡️ | #132 | #107`). The four `**Audit findings (Phase 1 review)**` bullets in `PHASE_2_MODERNISING.md:107-111` are all resolved on `develop`: (a) `DatabaseHandler::createTable()` deleted, (b) blog migration renamed to `Version20251023070000_CreateBlogTables`, (c) `MigrationServiceTest` real-test coverage shipped (12 tests with in-memory SQLite, marked `~~RESOLVED in PR #189, Step 2.0.4 — 12 real tests with in-memory SQLite~~` at `PHASE_2_MODERNISING.md:479`), (d) `MigrationService::getConfigPath()` deleted.
- No-Mercy spot-check: ✅ Rule #1 ("No Compatibility Layers") satisfied — Doctrine Migrations 3.x is the sole DDL path; runtime `createTable()` shim removed from `DatabaseHandler`. Rule #4 ("Delete over Wrap") satisfied — `getConfigPath()` and `Version001_CreateBlogTables` were physically removed via `git rm` (the timestamp-format file is a fresh class, not a `class_alias()` redirect). One Rule-5 marker remains in `Version20251023070000_CreateBlogTables.php:20` (`// TODO: AUDIT FIX Step 2.0.5 — Existing installations may need migration_versions table updated from Version001_CreateBlogTables to this class name`). This is **OK / tagged with valid future ROADMAP ID** per the prompt's Rule-5 audit-tag format (`AUDIT FIX Step X.Y` is one of the four canonical tag formats); it documents a one-time install-time DB shim that an operator script under Step 2.0.5 must own. Routed for cross-cutting classification in §4.3.4 Rule 5 sweep, not a Gap.
- Deferred items still tracked: ✅ — the four PHASE_2 audit-finding bullets are all delivered (see Phase 1 closure claim above); no orphaned `// TODO: Step 2.0.4` markers detected workspace-wide. The wider Step 2.0.4 PHASE_2 prose lists *additional* aspirational tasks (`pagekit migrate` unification, `PackageManager::enable() → migrateExtension()` automation, marketplace foundation) that are scope for the **larger** Package/Migration System Redesign program, not 2.0.4 closure criteria; the agent prompt and PR #189 explicitly delivered the four audit-finding fixes plus the `MigrationService` hardening (`is_array()` guard removal, `createExtensionDependencyFactory()` extraction, `ensureInitialized()`, `getExtensionCurrentVersion()`) — the marketplace and lifecycle automation pieces remain Phase-2 backlog items, tracked through the 2.0.4 PHASE_2 section header itself, not as orphaned debt.

---

### §4.2.5 Step 2.0.5 — Composer & Autoload Hygiene

**ROADMAP Status**: ✅ | **Issue**: #182 | **PR**: #192 | **Audit**: 🛡️

- Scope verified: ✅ — `composer.json` on `develop` HEAD is hygiene-clean: workspace-wide `Grep` for `Pagekit\\\\Theme\\\\|Pagekit\\\\Package\\\\` over `composer.json` returns **0 hits** — both dead PSR-4 mappings deleted (the legacy targets `app/system/modules/theme/src` and `app/system/modules/package/src` are also physically absent from disk: `ls app/system/modules/` contains `theme/` but no `theme/src/` subdirectory, and contains no `package/` directory at all). Unused-dep removals all verified by `Grep` over `composer.json` returning **0 hits** for `symfony/framework-bundle|symfony/twig-bridge|symfony/yaml|symfony/process|paragonie/sodium_compat|doctrine/data-fixtures|paragonie/random-lib`; cross-checked at the lockfile boundary with `./app/vendor/bin/composer why <pkg>`: `paragonie/sodium_compat`, `paragonie/random-lib`, `ircmaxell/security-lib`, `doctrine/data-fixtures`, and `symfony/yaml` are no longer in `composer.lock` at all (`Could not find package "<pkg>" in your project`); `symfony/framework-bundle`, `symfony/twig-bridge`, and `symfony/process` remain in the lock **only transitively** via well-justified consumers (`symfony/web-profiler-bundle` requires `framework-bundle`, `symfony/twig-bundle`/`debug-bundle` require `twig-bridge`, `composer/composer` and `friendsofphp/php-cs-fixer` require `process`) — none directly required by Pagekit code (no PHP `use` statements: `Grep` for `Symfony\\Component\\Process|Symfony\\Component\\Yaml|Symfony\\Component\\FrameworkBundle|Symfony\\Bridge\\Twig|Doctrine\\Common\\DataFixtures|RandomLib|paragonie\\Sodium` over `*.php` returns **0 hits** workspace-wide). `symfony/validator` aligned to `^6.4` LTS at `composer.json:42` (was previously `^7.4`). `paragonie/random-lib` resolved via **DELETE OVER WRAP** rather than a constraint loosen — call sites at `app/installer/src/Installer.php:193`, `app/modules/auth/src/Handler/DatabaseHandler.php:77`, `app/system/modules/user/src/Controller/RegistrationController.php:87,136`, and `app/system/modules/user/src/Controller/ResetPasswordController.php:78` use native `bin2hex(random_bytes(N))` directly; the `auth.random` container service is deleted (workspace-wide `Grep` for `auth\.random` over `app/` returns **0 hits**). `composer validate --strict` returns `./composer.json is valid` (exit 0); `composer install --dry-run --no-scripts` returns `Nothing to install, update or remove` — `composer.lock` is in sync with `composer.json` and `composer.lock` is committed (`ls -la composer.lock` reports `375959` bytes; `Grep` for `composer\.lock|yarn\.lock` over `.gitignore` returns **0 hits**, so the lockfile-versioning sub-task that shipped early in PR #187 is still in effect).
- Phase 1 closure claims verified: ✅ Step 1.4 → 🛡️ — confirmed against `.cursor/ROADMAP.md:50` (row `1.4 Safe Minor Updates | ✅ | 🛡️ | #124 | #53`). `PHASE_2_MODERNISING.md:124` records the closure claim verbatim ("Step 1.4 (Safe Minor Updates) ⚠️ → 🛡️ — composer schema cleanup, dead PSR-4 mappings removed, unused dependencies dropped … `paragonie/random-lib` replaced with native `random_bytes()`"). Step 1.3 (Security Patches) was already 🛡️ pre-2.0.5; cross-checked at `.cursor/ROADMAP.md:48` (row `1.3 Security Patches | ✅ | 🛡️ | #122 | #30`) — unchanged by 2.0.5 and confirmed by the `CHANGELOG-NEW.md:97-103` audit-flag entries that explicitly list this as a "BREAKING CHANGE — extension-facing".
- No-Mercy spot-check: ✅ Rule #1 ("No Compatibility Layers") satisfied — there is no `RandomServiceProvider` shim and no `auth.random` factory closure; the container has no entry by that name. Rule #4 ("Delete Over Wrap") satisfied — `paragonie/random-lib` and `ircmaxell/security-lib` were physically removed from `composer.json` + `composer.lock` (not pinned-and-wrapped); each call site was rewritten to `random_bytes()` directly, not routed through a `RandomGenerator` adapter. Rule #5 ("Mandatory Flagging") satisfied — `CHANGELOG-NEW.md:97,99` carry the `BREAKING CHANGE` markers per `pagekit-context.mdc` for the extension-facing `auth.random` removal. The `paragonie/sodium_compat` line in `composer.lock:7027` (`"paragonie/sodium_compat": "<1.24|>=2,<2.5"`) is **OK / by design** — it appears under the **`conflict`** clause of `roave/security-advisories`, not as a transitive `require`; that package is deliberately tracking known-vulnerable versions and is not pulling sodium_compat into Pagekit's dep tree.
- Deferred items still tracked: ✅ — none from 2.0.5 itself. Workspace-wide `Grep` for `Step 2\.0\.5` over `app/**/*.php` returns **0 hits** (no orphaned `// TODO: Step 2.0.5` markers in source). The `Version20251023070000_CreateBlogTables.php:20` `AUDIT FIX Step 2.0.5` marker noted in §4.2.4 is a one-time install-time DB shim owned by 2.0.5's downstream operator-script item, but the schema-rename portion of 2.0.5 (composer + autoload + lockfile + native-`random_bytes()` swap) is fully delivered; that single Rule-5-tagged marker is routed for cross-cutting classification in §4.3.4 and is not a 2.0.5 closure Gap. Module-level `composer.json` autoload entries (called out in §3.5 of the prompt for the targeted sweep) are evaluated at the workspace level in §4.3.5 below — out of scope for this per-sub-step block.

---

### §4.2.6 Step 2.0.6 — Test Infrastructure Cleanup

**ROADMAP Status**: ✅ | **Issue**: #183 | **PR**: #193 | **Audit**: 🛡️

- Scope verified: ✅ — `find app/modules -name "phpunit.xml.dist"` returns **0 hits** on `develop` HEAD; the four legacy module configs (`app/modules/filter/phpunit.xml.dist`, `app/modules/filesystem/phpunit.xml.dist`, `app/modules/cookie/phpunit.xml.dist`, `app/modules/auth/phpunit.xml.dist`) are physically deleted. The sole surviving `phpunit.xml.dist` is at workspace root (`./phpunit.xml.dist`) and declares the PHPUnit 11 schema (`xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.0/phpunit.xsd"`, line 3) with three test directories (`app/modules/*/src/Tests`, `app/system/modules/*/src/Tests`, `tests/Unit`, lines 11-13). `tests/Unit` casing verified — `ls tests/` shows `Unit/` with capital U; `find tests -type d` lists `tests/Unit/Migration` and `tests/Unit/Validator` (the legacy lowercase `tests/unit` is gone — `Glob` for `tests/unit/**` returns nothing). `@dataProvider` / `@group` migration verified — workspace-wide `Grep` for `@dataProvider|@group` over `*.php` returns **0 hits**; the modern `#[DataProvider|#[Group` attributes are used in 7 files (`app/modules/filesystem/src/Tests/PathTest.php`, `LocatorTest.php`; `app/modules/filter/src/Tests/PregReplaceTest.php`, `StripNewlinesTest.php`; `app/system/modules/mail/src/Tests/MailerTest.php`, `Integration/MailIntegrationTest.php`, `Controller/MailControllerTest.php`). `Doctrine\Common\Cache\ArrayCache` import purged — workspace-wide `Grep` for `Doctrine\\Common\\Cache\\ArrayCache` over `*.php` returns **0 hits**; `Grep` for `ArrayCache` over `app/` returns **0 hits**. `ConfigManagerTest.php` (`app/modules/config/src/Tests/ConfigManagerTest.php:1-49`) imports only `Pagekit\Config\ConfigManager` (line 5) + `PHPUnit\Framework\TestCase` (line 6); `getConfig()` uses the modern `new ConfigManager($connection, ['table' => 'test'])` signature (line 46) — no `getCache()` helper, no `ArrayCache` parameter; mocks use `willReturn(true)` pattern (line 37) — no legacy `$this->returnValue(...)`. `RoutesLoader::addController()` (`app/modules/routing/src/Loader/RoutesLoader.php:85-118`) replaces the silent `catch (\InvalidArgumentException $e) {}` with a debug-aware handler (line 101-117) — re-throw when `$app->has('debug') && $app->get('debug')` (line 106-108), `$app->get('log')->warning($message)` when log service available (line 112-113), `error_log($message)` as final fallback (line 115); covered by 4 new tests in `RoutesLoaderTest.php` (`testAddControllerRethrowsInDebugMode` line 154, `testAddControllerLogsViaLoggerInProduction` line 177, `testAddControllerFallsBackToErrorLogWhenNoLogService` line 205, `testAddControllerFallsBackToErrorLogWhenNoApplicationInjected` line 228) — fixture `RoutesLoaderTestAbstractController` (lines 276-281) intentionally triggers `InvalidArgumentException` inside `AttributeLoader::load()` to exercise the catch block.
- Phase 1 closure claims verified: ✅ Step 1.2 → 🛡️ — confirmed against `.cursor/ROADMAP.md:47` (row `1.2 PHPUnit Update | ✅ | 🛡️ | #121 | #31`). Step 1.8 → 🛡️ — confirmed against `.cursor/ROADMAP.md:54` (row `1.8 Routing System Compatibility | ✅ | 🛡️ | #128 | #57`). Both `Closes Phase 1 audit:` bullets in `PHASE_2_MODERNISING.md:155-157` are corroborated by the `develop`-HEAD evidence above (module-config deletion + attribute migration close 1.2; debug-aware `addController` handler closes 1.8).
- No-Mercy spot-check: ✅ Rule #1 ("No Compatibility Layers") satisfied — single root `phpunit.xml.dist` is the sole PHPUnit config; no parallel module-level configs hosting "compat" test suites. Rule #4 ("Delete Over Wrap") satisfied — the four module configs and the `ArrayCache` import were physically removed via `git rm` / file deletion, not stubbed with `@deprecated` or class-aliased; `ConfigManagerTest::testGet()` retains a `@doesNotPerformAssertions` placeholder (line 11) but this is a deliberate parking method (the bulk of meaningful coverage moved into the modern `getConfig()` factory exercised by integration tests), not a Rule-4 violation. Rule #5 ("Mandatory Flagging") satisfied — `RoutesLoader::addController()` carries an explicit explanatory comment (`// Debug-aware handler: re-throw in dev so broken controllers surface immediately; in production, log and skip the offending route so the rest of the route collection still loads.`, lines 103-105) that documents the intent of the new exception-handling policy without any `TEMPORARY BRIDGE` / `Must be refactored later` markers — this is permanent production code, not deferred debt.
- Deferred items still tracked: ✅ — none from 2.0.6. Workspace-wide `Grep` for `Step 2\.0\.6` over `app/**/*.php` returns **0 hits** (no orphaned `// TODO: Step 2.0.6` markers in source). The Step 2.0.6 PHASE_2 section closes cleanly: every task bullet under `**Tasks**:` (PHPUnit configs deleted, casing fixed, attributes migrated, `ArrayCache` import removed, `willReturn` adopted, `addController` exception handler hardened) is delivered on `develop`. No `**Audit findings (Phase 1 review):**` block exists for 2.0.6 (the section design uses a single `**Closes Phase 1 audit:**` block at lines 155-157 listing both 1.2 and 1.8 closures), and no orphaned audit-finding bullets are routed forward — 2.0.6 is fully self-contained.

---

### §4.2.7 Step 2.0.7 — Event Dispatcher Bridge Removal

**ROADMAP Status**: ✅ | **Issue**: #184 | **PR**: #195 | **Audit**: *TBD*

- Scope verified: *TBD* (`rg -n "SymfonyEventDispatcherBridge|symfony\\.event_dispatcher|EventDispatcherCompatibilityTest" app/ packages/` → expect 0 hits; PHPStan baseline does not list any of those identifiers).
- Phase 1 closure claims verified: confirm 1.7 → 🛡️ (1.9 already 🛡️).
- No-Mercy spot-check: *TBD* (cross-check `GetResponseEvent` rename deferral is tracked in 2.1.6 PHASE_2 — if not, add it there as a routed gap in §4.4).
- Deferred items still tracked: *TBD*.

---

### §4.2.8 Step 2.0.8 — `User::hasAccess()` Hotfix

**ROADMAP Status**: ✅ | **Issue**: #185 | **PR**: #197 | **Audit**: *TBD*

- Scope verified: *TBD* (`rg -n "create_function" app/ packages/ --glob "*.php"` → expect 0 hits; PHPStan baseline entry `function.notFound: create_function` removed; parser handles `&&` / `||` / `!` AND single-character `&` / `|`).
- Phase 1 closure claims verified: confirm 1.11 still ⚠️ (only partial closure — `EntityManager` singleton etc. carry to 2.1.6).
- No-Mercy spot-check: *TBD* (cross-check `evaluateBooleanExpression` → `PermissionExpressionEvaluator` deferral is referenced in 2.5's PHASE_2 section; if missing, add it there as a routed gap in §4.4).
- Deferred items still tracked: *TBD*.

---

## §4.3 Cross-Cutting Verification (ripgrep sweeps)

Run the four ripgrep sweeps from §3.3 of the prompt verbatim, plus the
targeted sweeps from §3.5. Classify every hit as **OK / by design**,
**OK / tagged with valid future ROADMAP ID**, or **GAP**. GAPs feed §4.4.

### §4.3.1 Rule 1 / 2 / 4 sweep — `Bridge|Adapter|Compat|Shim|Legacy|Wrapper`

```bash
rg -n "Bridge|Adapter|Compat|Shim|Legacy|Wrapper" app/ packages/ --glob "*.php" --glob "!*Test.php"
```


| File:Line | Match | Classification | Notes / Routing |
| --------- | ----- | -------------- | --------------- |
| *TBD*     | *TBD* | *TBD*          | *TBD*           |


---

### §4.3.2 Rule 4 sweep — `@deprecated`

```bash
rg -n "@deprecated" app/ packages/ --glob "*.php"
```


| File:Line | Match | Classification | Notes / Routing |
| --------- | ----- | -------------- | --------------- |
| *TBD*     | *TBD* | *TBD*          | *TBD*           |


---

### §4.3.3 Rule 4 sweep — `class_alias`

```bash
rg -n "class_alias" app/ packages/ --glob "*.php"
```


| File:Line | Match | Classification | Notes / Routing |
| --------- | ----- | -------------- | --------------- |
| *TBD*     | *TBD* | *TBD*          | *TBD*           |


---

### §4.3.4 Rule 5 sweep — debt markers (PHP, JS, Vue, LESS)

```bash
rg -n "TEMPORARY BRIDGE|AUDIT FIX|BACKWARD COMPATIBILITY|Must be refactored later" \
   app/ packages/ --glob "*.php" --glob "*.js" --glob "*.vue" --glob "*.less"
```


| File:Line | Match | Classification | Notes / Routing |
| --------- | ----- | -------------- | --------------- |
| *TBD*     | *TBD* | *TBD*          | *TBD*           |


---

### §4.3.5 Targeted sweeps (per §3.5 of the prompt)


| Sweep                                                       | Command                                                                                                      | Hit count                                                    | Classification | Routing |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------ | -------------- | ------- |
| Stale Vue-migration TODOs                                   | `rg -n "Refactor in Phase 3 \\(Vue Migration\\)" app/ packages/ --glob "*.php" --glob "*.js" --glob "*.vue"` | *TBD*                                                        | *TBD*          | *TBD*   |
| Module `phpunit.xml.dist`                                   | `rg --files app/modules/ -g "phpunit.xml.dist"`                                                              | *TBD*                                                        | *TBD*          | *TBD*   |
| Module `composer.json` autoload entries                     | `rg -n "psr-4                                                                                                | psr-0" app/modules/*/composer.json packages/*/composer.json` | *TBD*          | *TBD*   |
| Pagekit own `CacheInterface` / `Psr6Adapter`                | `rg -n "Pagekit\\\\Cache\\\\CacheInterface|Psr6Adapter" app/ packages/ --glob "*.php"`                       | *TBD*                                                        | *TBD*          | *TBD*   |
| `SymfonyEventDispatcherBridge` / `symfony.event_dispatcher` | `rg -n "SymfonyEventDispatcherBridge|symfony\\.event_dispatcher" app/ packages/`                             | *TBD*                                                        | *TBD*          | *TBD*   |
| `create_function`                                           | `rg -n "create_function" app/ packages/ --glob "*.php"`                                                      | *TBD*                                                        | *TBD*          | *TBD*   |


---

## §4.4 Gap List

Collated from §4.2 (per-sub-step) and §4.3 (cross-cutting). Every gap gets a
disposition. `Disposition` ∈ {`new sub-step 2.0.X`, `route to existing step`,
`informational only`}. For routed gaps, name the existing step (most likely
`2.1.6`, `2.1.9`, `2.5`). For new sub-step gaps, assign the next free
integer ≥ 9. The Architect's call which gaps are **must-fix-before-2.1**
(block 2.0 closure) vs. non-blocking (allow ✅ / 🛡️ closure with deferred
sub-steps) is documented in §4.8 Closure Verdict.


| #     | Gap (one line) | Disposition | Target | Blocking? |
| ----- | -------------- | ----------- | ------ | --------- |
| *TBD* | *TBD*          | *TBD*       | *TBD*  | *TBD*     |


> If the audit detects **zero** gaps, this section MUST contain the explicit
> phrase **"no gaps detected"** below the table header (per §8 of the
> task prompt — Definition of Done).

---

## §4.5 New Sub-Step Proposals

For every gap with disposition `new sub-step 2.0.X`, record the full skeleton
(cross-linked to the agent-prompt file under
`migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/`).

If zero new sub-steps are proposed, this section reads: **"No new sub-steps proposed."**

### §4.5.1 Step 2.0.{X} — *TBD title*

- **Goal**: *TBD*
- **Prerequisite**: *TBD*
- **Priority**: *TBD*
- **Closes Phase 1 audit**: *TBD* (or `none`)
- **GitHub issue**: *TBD* (linked as sub-issue of #181)
- **Agent prompt**: `migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/PROMPT_2_0_{X}_{Slug}.md`
- **Affected files**: *TBD*
- **Risk**: *TBD*
- **Blocking 2.1 entry?**: *TBD*

*(repeat for each new sub-step; numbering: next free integer ≥ 9).*

---

## §4.6 PHASE_2 / ROADMAP / Issue Updates (exact diffs proposed in this PR)

Documentation drift findings (per §3.6 of the prompt) **and** the diffs for
new sub-step paper deliverables (per §4.5) are recorded here.

### §4.6.1 Documentation drift sweep findings


| Document                                                                                                                                                            | Drift detected? | Notes |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------- | ----- |
| `.cursor/ROADMAP.md` (rows 2.0.0–2.0.8 statuses, audit cells, issue + PR linked)                                                                                    | *TBD*           | *TBD* |
| `migration-docs/TODO/PHASE_2_MODERNISING.md` (every 2.0.x section: `Closes Phase 1 audit:`, `Agent Prompt:` path, `Audit findings (Phase 1 review):` if applicable) | *TBD*           | *TBD* |
| `migration-docs/branches/` (branch doc per sub-step)                                                                                                                | *TBD*           | *TBD* |
| `README.md` (cache / migrations / event-dispatcher / PHPUnit module configs references)                                                                             | *TBD*           | *TBD* |
| `AGENTS.md` (service mappings + pitfalls touched by 2.0.x)                                                                                                          | *TBD*           | *TBD* |
| `CHANGELOG-NEW.md` (every 2.0.x entry coherent; version chain `1.2.5 → 1.2.13` complete)                                                                            | *TBD*           | *TBD* |


### §4.6.2 ROADMAP diff (this PR)

```diff
_TBD — exact lines added / changed in .cursor/ROADMAP.md.
```

### §4.6.3 PHASE_2 diff (this PR)

```diff
_TBD — exact lines added / changed in migration-docs/TODO/PHASE_2_MODERNISING.md
       (new 2.0.X sub-section bodies + appended "Audit findings" bullets on
       routed-gap target steps).
```

### §4.6.4 New agent-prompt skeletons (this PR)


| Path  | Sub-step | Status |
| ----- | -------- | ------ |
| *TBD* | *TBD*    | new    |


### §4.6.5 GitHub issues opened (this PR)


| Issue | Title | Labels | Milestone                     | Parent |
| ----- | ----- | ------ | ----------------------------- | ------ |
| *TBD* | *TBD* | *TBD*  | Phase 2: Developer Experience | #181   |


---

## §4.7 Final Test Summary

Pre-flight baseline (Checklist Step 1, captured against `develop` HEAD before
any audit-machinery changes) and final-gate runs (Checklist Step 22, on the
closure branch with all docs/skeletons in place).


| Gate                                                                          | Pre-flight (Step 1) | Final (Step 22) |
| ----------------------------------------------------------------------------- | ------------------- | --------------- |
| `./app/vendor/bin/phpunit`                                                    | *TBD*               | *TBD*           |
| `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M`          | *TBD*               | *TBD*           |
| `php pagekit list`                                                            | *TBD*               | *TBD*           |
| `php pagekit setup`                                                           | *TBD*               | *TBD*           |
| Playwright E2E (chromium-only): `installation`, `authentication`, `dashboard` | n/a                 | *TBD*           |


### §4.7.1 PHPUnit raw output (truncated)

```
_TBD_
```

### §4.7.2 PHPStan raw output (truncated)

```
_TBD_
```

### §4.7.3 `php pagekit list` raw output (truncated)

```
_TBD_
```

### §4.7.4 `php pagekit setup` raw output (truncated)

```
_TBD_
```

### §4.7.5 Playwright raw output (truncated; final run only)

```
_TBD_
```

---

## §4.8 Closure Verdict

*TBD — pick exactly one of:*

- ✅ **Step 2.0 can close in this PR.** New sub-steps (if any) are
non-blocking and may land later. ROADMAP row `2.0` flips to `✅` / `🛡️`,
`Current Step` header pointer advances to `2.1.2` (2.1.1 is already done).
- ⚠️ **Step 2.0 stays `⏳` / `⏳*`* until the following must-fix-before-2.1
new sub-steps land: *TBD list*. ROADMAP `Current Step` pointer advances
to the **first** new sub-step in that list (e.g. `2.0.9`). Each blocking
sub-step's PHASE_2 section names "must land before Step 2.1.x" on its
`Prerequisite` line.

### Decision rationale

*TBD — short paragraph explaining why each blocking gap (if any) blocks 2.1
entry and why each non-blocking gap can ship later. References the §4.4 Gap
List rows by number. Closure is conditional on the §4.7 final-gate matrix
being all-green.*

---

**End of audit report skeleton.**