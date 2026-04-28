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
| 2.0.3    | Cache API Full Modernization                                       | *TBD*              | *TBD*                |
| 2.0.4    | Package / Migration System Redesign                                | *TBD*              | *TBD*                |
| 2.0.5    | Composer & Autoload Hygiene                                        | *TBD*              | *TBD*                |
| 2.0.6    | Test Infrastructure Cleanup                                        | *TBD*              | *TBD*                |
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

**ROADMAP Status**: ✅ | **Issue**: #179 | **PR**: #187 | **Audit**: *TBD*

- Scope verified: *TBD* (`rg -n "Pagekit\\\\Cache\\\\CacheInterface|Psr6Adapter" app/ packages/ --glob "*.php"` → expect 0 hits; zero `fetch()` / `flushAll()` calls on cache pools; `LoginAttemptListener`, `UrlResolver`, `RouteListener`, `blog/scripts.php` consumers use PSR-6 API).
- Phase 1 closure claims verified: confirm 1.10 stays 🛡️.
- No-Mercy spot-check: *TBD* (verify `composer.lock` + `yarn.lock` committed — lockfile-versioning sub-task that shipped early in 2.0.3 PR #187).
- Deferred items still tracked: *TBD*.

---

### §4.2.4 Step 2.0.4 — Package / Migration System Redesign

**ROADMAP Status**: ✅ | **Issue**: #180 | **PR**: #189 | **Audit**: *TBD*

- Scope verified: *TBD* (`DatabaseHandler::createTable()` deleted (`rg -n "function createTable" app/modules/auth/`); blog migration renamed to timestamp format (`rg -n "Version001_CreateBlogTables|Version[0-9]{14}.*Blog" app/`); `MigrationServiceTest` un-skipped (no `markTestSkipped`); `MigrationService::getConfigPath()` deleted; login check + update wizard execute Doctrine Migrations before `scripts->update()`).
- Phase 1 closure claims verified: confirm 1.12 stays 🛡️.
- No-Mercy spot-check: *TBD* (flag any audit-findings sub-bullet still untouched as a Gap).
- Deferred items still tracked: *TBD*.

---

### §4.2.5 Step 2.0.5 — Composer & Autoload Hygiene

**ROADMAP Status**: ✅ | **Issue**: #182 | **PR**: #192 | **Audit**: *TBD*

- Scope verified: *TBD* (`composer.json`: dead PSR-4 mappings `Pagekit\Theme\` and `Pagekit\Package\` removed; unused deps removed (`symfony/framework-bundle`, `symfony/twig-bridge`, `symfony/yaml`, `symfony/process`, `paragonie/sodium_compat`, `doctrine/data-fixtures`); `symfony/validator` aligned to `^6.4`; `paragonie/random-lib` resolved (replaced or loosened); `composer validate` clean and `composer.lock` consistent).
- Phase 1 closure claims verified: confirm 1.4 → 🛡️.
- No-Mercy spot-check: *TBD*.
- Deferred items still tracked: *TBD*.

---

### §4.2.6 Step 2.0.6 — Test Infrastructure Cleanup

**ROADMAP Status**: ✅ | **Issue**: #183 | **PR**: #193 | **Audit**: *TBD*

- Scope verified: *TBD* (zero module-level `phpunit.xml.dist` (`rg --files app/modules/ -g "phpunit.xml.dist"`); `tests/Unit` casing fixed; `@dataProvider` / `@group` migrated to `#[DataProvider]` / `#[Group]`; `Doctrine\Common\Cache\ArrayCache` import removed from `ConfigManagerTest`; `RoutesLoader::addController()` no longer silently swallows `InvalidArgumentException`).
- Phase 1 closure claims verified: confirm 1.2 + 1.8 → 🛡️.
- No-Mercy spot-check: *TBD*.
- Deferred items still tracked: *TBD*.

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