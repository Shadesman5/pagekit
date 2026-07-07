# 🔍 Technical Debt & Modernization Audit — Debt Inventory

## 1. Header

| Field | Value |
| --- | --- |
| **Date** | 2026-07-07 |
| **Auditor** | Architect (read-only audit) |
| **PHP version (runtime)** | 8.3.6 (CLI) — project floor `^8.2` (`composer.json`) |
| **PHPStan level** | 8 (max for this project — Level 9 explicitly rejected in ROADMAP) |
| **ROADMAP `Current Step`** | **2.1.7** (QueryBuilder API Standardization) |
| **Current version** | 1.2.21 |
| **Standards (yardstick)** | `.cursor/rules/pagekit-context.mdc`, `.cursor/rules/pagekit-standards.mdc`, `.cursor/ROADMAP.md` (THE 5 AGGRESSIVE RULES), `migration-docs/TODO/MODERNISATION_STRATEGY.md` (Pagekit DNA) |
| **Source of truth (progress)** | `.cursor/ROADMAP.md` (steps) + `CHANGELOG-NEW.md` (shipped work) + `migration-docs/TODO/PHASE_2_MODERNISING.md` (audit-finding routing) |
| **Scope** | Backend PHP (`app/`, `packages/`). Frontend (Vue 2.6 / UIkit) intentionally excluded — Phase 3 owns it; only NEW/untracked frontend debt flagged. |

This is a **read-only** audit. No application code was changed. The only file written is this report.

---

## 2. Current-State Baseline

Commands run from repo root on 2026-07-07 (per the task's methodology §2):

```bash
$ grep -c "message:" phpstan-baseline.neon
331                        # baseline blocks

$ grep -oP 'count:\s*\K[0-9]+' phpstan-baseline.neon | awk '{s+=$1} END{print s+0}'
632                        # suppressed errors

$ ./app/vendor/bin/phpunit --colors=never 2>&1 | grep -E '^(OK \(|Tests:)'
Tests: 328, Assertions: 711, Warnings: 1, Deprecations: 4, PHPUnit Deprecations: 2, Skipped: 5.

$ php -v | head -n1
PHP 8.3.6 (cli) ...
```

**Reading of the baseline:**

- **PHPStan Level 8, 331 baseline blocks / 632 suppressed errors.** Matches the Quality Metrics Tracker row for Step 2.1.6 (#212, 2026-07-01): `331 / 632`. No drift since the Level-8 landing.
- **Test suite is green-with-issues, not clean.** PHPUnit reports **`Tests:` (not `OK (…)`)** because of **5 skipped**, **1 warning**, **4 PHP deprecations**, **2 PHPUnit deprecations**. The 5 skips are environment-gated mail/SMTP tests (accepted). The 4 PHP deprecations are all **one real, untracked issue** — see **TD-16** (`StreamWrapper::$context` dynamic property). This is a live signal worth closing.
- **No full E2E run performed** (per task rule). Suite is 25 specs (constant since 1.10.5) per the Quality Metrics Tracker.

---

## 3. Executive Summary

**Overall health: good and improving.** The foundation-modernization work (Phases 1–2.0, and PHPStan 2.1.1→2.1.6) has genuinely removed the deepest legacy: the global `App` god-object is gone (PSR-11 DI, Steps 2.0.1a–e), `create_function()` is gone (2.0.8), `#[AllowDynamicProperties]` is gone (2.1.6), Doctrine annotations are on PHP 8 attributes (1.14/2.0.0), and there is **zero WordPress/Laravel code**. The routing/cache archetype cited in the task is **already half-resolved**: the two production bugs (stale-cache-key on permalink switch, and the concurrent-write HTTP 500) were **fixed in 1.2.21** — this audit confirms the fixes and does **not** re-report them.

What remains is mostly **known and tracked**: the model layer is still Active-Record with a static `EntityManager` singleton (2.1.11) and a `ModelServiceLocator` (2.1.10); the routing factory still instantiates via `new $class()` and clones a `@deprecated` Symfony dumper (2.5); extension boot has no fault isolation (2.5). The audit's value-add is **(a)** confirming which archetype items are done vs. residual, and **(b)** surfacing a small set of **untracked** items.

**Counts**

| By severity | # | | By decision-urgency | # |
| --- | --- | --- | --- | --- |
| 🟥 Critical | 1 | | 🔴 DECIDE-NOW | 3 (→ 3 shortlist clusters) |
| 🟧 High | 5 | | 🟡 SCHEDULE | 15 |
| 🟨 Medium | 8 | | 🟢 OPPORTUNISTIC | 4 |
| 🟩 Low | 10 | | ✅ Accept (documented) | 2 |

**Findings tracked vs. untracked:** of the 24 inventory findings, **17 are already tracked** to a ROADMAP step/flag (confirm-and-link), **5 are untracked** (TD-05, TD-16, TD-18, TD-19, TD-20), and **2 are accepted-by-design** (TD-09, TD-10). Separately, **3 flag-reconciliation items** (RC-1/2/3 in §9) are docs-only (stale/under-tagged).

**Key takeaways**

1. **The single most foundation-shaping decision is the model-layer persistence architecture** (Active-Record → Data-Mapper: 2.1.10 + 2.1.11). It is already scheduled — the risk is *building the REST API v2 (4.2) on entity `jsonSerialize()` before the presenter layer exists*, which would be thrown away.
2. **The routing factory DI rework (2.5) is the second foundation item** — it is the shared root cause (`new $class()`) behind three static bridges and blocks retiring the `@deprecated since Symfony 4.3` dumper clone.
3. **The archetype's "non-atomic write / narrow-catch" pattern does exist elsewhere and is untracked** — most importantly **non-atomic `config.php` writes** (`SettingsController`, `Installer`). This is the same failure class that produced the routing 500, but on a boot-critical file. Cheap to standardize now (the Router already has an atomic temp+rename helper).
4. **One Critical-by-policy item:** a **hardcoded third-party API key** in `dashboard/index.php` (tracked to 4.2). Value not reproduced here.
5. **PHPStan suppressions (632) are not evenly spread** — they concentrate in the Active-Record model layer (`user` 49, `blog` 23, `site` 19, `database` 17) and theme/view PHP (`theme-one` 38, `theme` 17). The model cluster shrinks with 2.1.10/2.1.11; the theme cluster is Phase-3 territory.
6. **Flag hygiene is strong** — nearly every in-code TODO maps to a real step. Two exceptions: one **stale** tag (`app/system/index.php:96` points at the completed 2.1.6; the work is 2.1.11) and one **under-tagged** flag (`NodeModelTrait`).

---

## 4. Definition-of-Modern Scorecard (D1–D10)

| Domain | RAG | One-line justification |
| --- | :---: | --- |
| **D1** Routing / URL / cache / paths | 🟡 | Archetype bugs fixed (cache key + atomic write, 1.2.21); DI gap (`new $class()`), deprecated dumper clone, and mtime-based freshness remain — mostly tracked to 2.5. |
| **D2** DI & service architecture | 🟡 | Global `App` god-object eliminated (2.0.1x) ✅; residual static locators are scheduled (2.1.10/2.1.11/2.5); `UniqueValidator` static locator is **untracked**. |
| **D3** ORM & database | 🟡 | Legacy `execute()` + DBAL-3 deprecations bounded to 4 files, tracked to the **current** step (2.1.7); Active-Record magic-methods tied to 2.1.11. |
| **D4** Module / extension boot | 🟡 | No `try/catch(\Throwable)` around module `include`/boot — a faulty extension crashes the kernel; tracked to 2.5. Bounded risk today (no third-party marketplace yet). |
| **D5** Legacy idioms & anti-patterns | 🟢 | No WP/Laravel-isms, no annotations, no `create_function`, no `AllowDynamicProperties`. Only a `StreamWrapper::$context` deprecation + one commented-out `catch` remain. |
| **D6** Error handling & resilience | 🟡 | Routing race fixed & fallback added (1.2.21); but **non-atomic `config.php` writes** and a few silent catches are **untracked**. |
| **D7** Public / Extension API & DX | 🟡 | Real public API is Step 4.2 by design; platform API names are intentional (not debt); extension-boundary static bridges tracked to 2.5. |
| **D8** Configuration & state | 🟡 | **Hardcoded API key** (Critical, tracked 4.2); non-atomic config writes (TD-19); per-request static cache (2.1.11). |
| **D9** Tests, types & static analysis | 🟡 | PHPStan L8 achieved ✅; 632 suppressions concentrated in model/theme layers; mutation testing (2.1.8) + coverage expansion (2.1.9) pending. |
| **D10** Flag / TODO reconciliation | 🟢 | Nearly all flags map to a step; 1 stale, 1 under-tagged, 1 residual "AUDIT FIX" note — all docs-only fixes. |

---

## 5. 🔴 Decision-Critical Shortlist (now-or-throwaway)

These are the choices that, if deferred, cause work built on top to be reworked or discarded.

### SL-1 — Model-layer persistence architecture: Active-Record → Data-Mapper

- **The decision:** commit (now) to the direction that entities become pure domain/persistence objects, with presentation via DI-based presenters/DTOs (2.1.10) and persistence via an injected `EntityManager`/repositories (2.1.11) — removing the `ModelServiceLocator` and the `EntityManager` **singleton** (`static::$instance` + the `$app->get('db.em')` boot hack).
- **Why foundation-shaping:** every model, API response, and template that is written *now* against static `Model::find()` + entity `jsonSerialize()` (which reaches services via `ModelServiceLocator`) is coupled to a pattern already slated for removal. **REST API v2 (Step 4.2)** in particular must serialize through presenters, not `Node::jsonSerialize()`/`Post::jsonSerialize()`.
- **Options / trade-offs:** (a) proceed as scheduled (2.1.10 then 2.1.11) — clean, but High risk, ripples into every static model call site; (b) keep Active-Record and add a thin API DTO only for 4.2 — cheaper short-term, but leaves two persistence idioms and re-introduces the coupling the audit found. **(c)** freeze new model/API surface until 2.1.10 lands — safest, slows feature work.
- **Recommended:** **(a)**, and sequence 2.1.10 **before** any 4.2 API-shape work. Do not start 4.2 serialization on entities.
- **Thrown away if deferred:** any 4.2 endpoints or new admin JSON built on entity `jsonSerialize()`; per-entity URL/access logic wired through the locator.
- **Dependent steps:** 2.1.8 (mutation testing wants stable model seams), 2.1.9 (coverage), **4.2 (REST API v2)**, 4.3 (tag cache touches the same entities).
- **Evidence:** `app/system/modules/site/src/ModelServiceLocator.php:13`; `app/modules/database/src/ORM/EntityManager.php:20,269`; `app/system/index.php:96-102` (`$app->get('db.em')` boot hack); `app/system/modules/site/src/Model/NodeModelTrait.php:18`. Tracked: **#204 (2.1.10)**, **#205 (2.1.11)**.

### SL-2 — Routing factory DI + Symfony compiled matcher/generator (Step 2.5)

- **The decision:** rebuild the routing factory so resolvers are created **through the container** (not `new $class()`), and migrate the matcher/generator to Symfony's native `CompiledUrlMatcher`/`CompiledUrlGenerator` (retiring the cloned `@deprecated since Symfony 4.3` `PhpMatcherDumper` and the reflection-based `instantiate*()` helpers).
- **Why foundation-shaping:** the `new $class()` instantiation (`Router::getResolver()`) is the **shared root cause** of three static bridges — `blog/UrlResolver` (static `setCache()/setModule()`), `theme-one/functions.php` (static `UrlProvider`), and it is the same class of problem as `UniqueValidator`'s static `setDb()`. Each new resolver/extension added before this lands must copy the static-bridge workaround. The deprecated dumper also blocks a future Symfony 7 jump.
- **Options / trade-offs:** (a) full rework as scheduled in 2.5 — Medium effort, clears the deprecation and unblocks DI; (b) keep the clone but only add DI to resolvers — partial, leaves the Symfony-4.3 deprecation; (c) do nothing until Symfony 7 forces it — highest eventual cost.
- **Recommended:** **(a)** as already scoped in 2.5. Pair the DI factory and the compiled-dumper migration in one step (they touch the same `Router::getMatcher()/getGenerator()` code).
- **Thrown away if deferred:** every new static bridge added to work around `new $class()`; a later, larger forced migration under Symfony 7.
- **Dependent steps:** removal of `blog/UrlResolver` + `theme-one` static bridges (both tagged 2.5), Symfony 7 upgrade (future).
- **Evidence:** `app/modules/routing/src/Router.php:484` (`new $resolver()`), `:171,212` (dumpers), `:239-273` (reflection instantiate); `app/modules/routing/src/Matcher/Dumper/PhpMatcherDumper.php:31` (`@deprecated since Symfony 4.3`); `packages/pagekit/blog/src/UrlResolver.php:25,29,35`. Tracked: **Step 2.5** (PHASE_2 §2.5 "Routing dumper modernization" + "blog/UrlResolver static bridge DI").

### SL-3 — Resilience & secrets: standardize atomic writes + retire the hardcoded API key

- **The decision (two coupled, cheap-now items):**
  1. **Adopt one shared atomic-write helper** (temp file + `rename()`) for boot-critical files — the Router already implements exactly this (`Router::writeCache()`); extract it to a `Filesystem` utility and use it for `config.php` and the package registry.
  2. **Move the hardcoded OpenWeatherMap API key** out of `dashboard/index.php` into env/secrets (tracked to 4.2 — recommend accelerating).
- **Why foundation-shaping:** #1 is the direct generalization of the archetype's own lesson — the routing 500 was a **non-atomic write + narrow catch**; the identical pattern still writes `config.php` (loaded on *every* request) non-atomically. Deciding the pattern now (before more write sites accrete) is far cheaper than retrofitting scattered sites later. #2 is a live credential in version control.
- **Options:** (#1) shared helper (recommended, ~1 small class, DNA-respecting) vs. per-site ad-hoc fixes (drifts). (#2) env var (recommended) vs. leave until 4.2 (accepts a committed key longer).
- **Recommended:** extract `Filesystem::dumpAtomic()` and route config/registry writes through it (fold into 2.5 or a small 2.x sub-step); rotate + env the API key.
- **Thrown away if deferred:** none structurally, but risk accumulates (corrupt `config.php` under a crash/concurrent read → site-wide outage; leaked key).
- **Evidence:** `app/system/modules/settings/src/Controller/SettingsController.php:59`; `app/installer/src/Installer.php:205`; `app/installer/src/Helper/Composer.php:228`; contrast the correct pattern at `app/modules/routing/src/Router.php:442-468`. Secret: `app/system/modules/dashboard/index.php:48-49` (value redacted; tracked 4.2).

---

## 6. Master Debt Inventory

Severity: 🟥 Critical · 🟧 High · 🟨 Medium · 🟩 Low. Urgency: 🔴 DECIDE-NOW · 🟡 SCHEDULE · 🟢 OPPORTUNISTIC · ✅ Accept.
Effort: S ≤ ~1 file/localized · M = a few files · L = architectural/many call sites.

| ID | Dom | Finding | Evidence (path:line) | Sev | Urg | Best-practice target | Eff | Tracked? | Disposition |
| --- | --- | --- | --- | :---: | :---: | --- | :---: | --- | --- |
| TD-01 | D1 | Route resolvers created via `new $class()` (no container) → forces static bridges | `routing/src/Router.php:484` | 🟧 | 🔴 | Container-built resolver factory (DI) | L | Step 2.5 | Existing (2.5) / SL-2 |
| TD-02 | D1 | Cloned `@deprecated since Symfony 4.3` `PhpMatcherDumper` + `UrlGeneratorDumper` + reflection instantiation | `routing/.../PhpMatcherDumper.php:31`; `Router.php:171,212,239-273` | 🟨 | 🟡 | `CompiledUrlMatcher/Generator` (Symfony native) | L | Step 2.5 | Existing (2.5) / SL-2 |
| TD-03 | D1 | Route-cache **freshness** inferred from file mtimes (not explicit/versioned) | `Router.php:418,430` | 🟨 | 🟢 | Explicit version/tag-based invalidation | M | Partial | Propose fold → 2.5 |
| TD-04 | D1 | Core `Router` comment names module key `blog.permalink` (residual conceptual coupling; runtime coupling already removed) | `Router.php:411-416` | 🟩 | 🟢 | Generic wording; core ignorant of modules | S | 2.1.9 (test decouple) | Existing (2.1.9) / accept comment |
| TD-05 | D2 | `UniqueValidator` **static service locator** (`setDb()`, `static mixed $db`) — validator built by Symfony via `new $class()` | `system/src/Validator/Constraints/UniqueValidator.php:22,24`; wired `system/index.php:90` | 🟨 | 🟡 | Container-aware `ConstraintValidatorFactory` **or** accept (documented) | M | **Untracked** | Propose (fold 2.5) or Accept |
| TD-06 | D2 | `EntityManager` **singleton** (`static::$instance`, `getInstance()`) + boot hack | `database/src/ORM/EntityManager.php:20,269`; `system/index.php:96-102` | 🟧 | 🔴 | Injected EM / repositories (Data-Mapper) | L | Step 2.1.11 (#205) | Existing / SL-1 |
| TD-07 | D2 | `ModelServiceLocator` static locator inside entities (presentation in domain objects) | `site/src/ModelServiceLocator.php:13`; `site/.../Node.php`; `blog/.../Post.php` | 🟧 | 🔴 | DI presenters/DTOs | L | Step 2.1.10 (#204) | Existing / SL-1 |
| TD-08 | D2/D8 | `NodeModelTrait` static request-scoped `$nodes` cache (global state) | `site/src/Model/NodeModelTrait.php:18` | 🟨 | 🟡 | Injected `CacheItemPoolInterface` | M | Step 2.1.11 | Existing (2.1.11) |
| TD-09 | D2 | `IntlServiceLocator` static accessor bridge for PHP global fns `__()/_c()/_i()/_n()` | `intl/src/IntlServiceLocator.php:16-52` | 🟩 | ✅ | — (DI-constructed; global-fn bridge is idiomatic) | — | Accepted by design | **Accept** (documented) |
| TD-10 | D2 | `StreamWrapper` static `setFilesystem()` bridge (PHP instantiates stream wrappers) | `filesystem/src/StreamWrapper.php:14,19` | 🟩 | ✅ | — (PHP stream-wrapper contract) | — | n/a | **Accept** (documented) |
| TD-11 | D3 | Legacy `->execute()` API (mixes bind+exec) — 4 call sites | `UniqueValidator.php:69`; `database/src/ORM/QueryBuilder.php`; `blog/.../PostModelTrait.php`; `site/.../NodeModelTrait.php` | 🟨 | 🟡 | `executeQuery()`/`executeStatement()` + `Result` API | M | **Step 2.1.7 (current)** | Existing (2.1.7) |
| TD-12 | D3 | DBAL-3 deprecated APIs: `Connection::exec()` alias, `Utility::getSchemaManager()`, `new Comparator()` | `database/src/Connection.php:246`; `database/src/Utility.php:30,39,243`; `installer/src/Installer.php:240` | 🟨 | 🟡 | `executeStatement()`, `createSchemaManager()`, `createComparator()` | M | Step 2.1.7 | Existing (2.1.7) |
| TD-13 | D3 | `json_array` legacy type mapping / normalization | `database/src/Connection.php:120-134`; `database/src/Types/JsonArrayType.php:16` | 🟩 | 🟡 | Native `'json'` type | S | Step 2.1.7 | Existing (2.1.7) |
| TD-14 | D3 | ORM Active-Record magic (`__get`/`__set`/`__call` → `mixed`) | `database/src/ORM/PropertyTrait.php:18,46`; `Table.php:71`; `ORM/QueryBuilder.php:276` | 🟩 | 🟡 | Reduced by Data-Mapper move; `mixed` documented | L | Step 2.1.11 | Existing (2.1.11) / accept documented `mixed` |
| TD-15 | D4 | No fault isolation: module `include`/loaders/`boot` have no `try/catch(\Throwable)` — a faulty extension crashes the kernel | `application/src/Module/ModuleManager.php:104-115,134-136`; `application/src/Application.php:36` | 🟧 | 🟡 | Sandboxed load + auto-disable + DB-independent logging | L | Step 2.5 | Existing (2.5) |
| TD-16 | D5/D9 | `StreamWrapper::$context` **dynamic property** deprecation (PHP 8.2) — fires 4× in the test run | `filesystem/src/StreamWrapper.php` (no `$context` decl); PHPUnit `--display-deprecations` | 🟩 | 🟡 | Declare `public $context;` | S | **Untracked** | Propose (fold → 2.1.9) |
| TD-17 | D5 | `setAccessible()` — no-op since 8.1, `#[\Deprecated]` in 8.5 | `mail/src/Message.php:391,403,438`; `kernel/src/Event/ExceptionListener.php:67` | 🟩 | 🟡 | Delete calls (safe on 8.2+) | S | Step 2.1.9 | Existing (2.1.9) |
| TD-18 | D5 | Commented-out `catch` block (dead code; violates Rule 4 "delete over wrap") | `console/src/Commands/SelfupdateCommand.php:78` | 🟩 | 🟢 | Delete | S | **Untracked** | Propose (opportunistic) |
| TD-19 | D6/D8 | **Non-atomic writes to boot-critical files** (`config.php`, package registry) — same class as the fixed routing 500 | `settings/.../SettingsController.php:59`; `installer/src/Installer.php:205`; `installer/src/Helper/Composer.php:228`; `installer/.../PackageController.php:207` | 🟧 | 🟡 | Shared atomic temp+`rename()` helper (already in `Router::writeCache`) | M | **Untracked** | Propose new sub-step / SL-3 |
| TD-20 | D6 | Empty catch swallows `UnexpectedValueException` silently | `installer/src/Helper/Composer.php:63-64` | 🟩 | 🟢 | Log or narrow/handle | S | **Untracked** | Propose (opportunistic) |
| TD-21 | D7 | Static bridges leak to the extension boundary (theme/blog helpers rely on global state) | `theme-one/functions.php:8`; `blog/src/UrlResolver.php:25` | 🟨 | 🟡 | DI once routing factory supports it | M | Step 2.5 | Existing (2.5) |
| TD-22 | D8 | **Hardcoded third-party API key** in module config (value redacted) | `system/modules/dashboard/index.php:48-49` | 🟥 | 🟡 | env var / secrets management | S | Step 4.2 (tagged) | Existing (4.2) — accelerate / SL-3 |
| TD-23 | D9 | 632 PHPStan suppressions concentrated in Active-Record model layer + theme/view PHP | `phpstan-baseline.neon` (user 49, theme-one 38, blog 23, installer 23, site 19, theme 17, database 17) | 🟨 | 🟡 | Shrinks via 2.1.10/2.1.11 (model) + Phase 3 (theme) | L | 2.1.9/2.1.10/2.1.11 + Phase 3 | Existing |
| TD-24 | D9 | Residual avoidable `mixed` (captcha/site-controller/data-model) | per PHASE_2 §2.1.12 (`captcha/.../CaptchaListener.php:146`, `site/.../NodeController.php:23`, `system/src/Model/DataModelTrait.php:13`) | 🟩 | 🟡 | Concrete types | S | Step 2.1.12 | Existing (2.1.12) |

**Reconciliation-only rows** (see §9): RC-1 stale flag `system/index.php:96`; RC-2 under-tagged `NodeModelTrait.php:18`; RC-3 residual `AUDIT FIX Step 2.0.5` note in the blog migration.

---

## 7. Per-domain deep-dives

### D1 — Routing, URL generation, cache & path resolution 🟡

The task's archetype cluster is the right lens; here is its **current** disposition (verified against 1.2.21):

- ✅ **Fixed (confirm-and-link, do not re-report):**
  - *Stale-cache-key on permalink switch* — `Router::getCache()` now folds **all** router options into the key: `sha1(serialize($this->resource).serialize($this->options))` (`Router.php:417`). CHANGELOG 1.2.21 "Blog post URLs no longer show 'Disabled'…".
  - *HTTP 500 on concurrent route regeneration* — writes are now atomic (temp + `rename()`, `Router::writeCache()` `:442-468`) and `getMatcher()/getGenerator()` fall back to the non-cached path on any corrupted cache (`:184-189`, `:225-230`). Covered by `RouterTest::testCorruptCacheFileFallsBackInsteadOfFatal`.
- 🟡 **Residual / tracked to 2.5** (SL-2): `new $resolver()` DI gap (`:484`, **TD-01**); cloned deprecated dumper + reflection instantiation (**TD-02**).
- 🟢 **Residual / untracked-ish:** freshness still uses `filemtime($file) >= $this->resource->getModified()` (`:418,430`, **TD-03**). This is mitigated (the *key* now changes on option/route changes), but the *freshness* axis is still mtime-based — recommend folding a review into the 2.5 rework rather than a standalone step.
- 🟩 The core `Router` still names `blog.permalink` in an explanatory comment (`:411-416`, **TD-04**). The runtime coupling is gone (generic `serialize($this->options)`); only the comment + the `RouterTest` example remain. Test decoupling is already a 2.1.9 task ("use a generic option name").

Path resolution (`Locator`/`Filesystem`/`Path`) is well-typed and unit-tested (`Locator`, `Path` test suites green) — no findings.

### D2 — Dependency Injection & service architecture 🟡

**Big positive:** the global `App` god-object is gone. A workspace search for `App::`, `app()`, `global $`, `$GLOBALS` finds **one** benign hit (`InfoHelper.php:31`, `$GLOBALS['_SERVER']` via Symfony `ServerBag`, in a phpinfo helper). This confirms the PSR-11 DI migration (2.0.1a–e) achieved its goal.

The remaining static state is a **family with a single root cause** — an object is instantiated *outside* the container (by PHP or a framework factory), so a static holder bridges it:

| Static holder | Instantiated by | Status |
| --- | --- | --- |
| `EntityManager` singleton (**TD-06**) | eager `db.em` boot hack | Tracked 2.1.11 (SL-1) |
| `ModelServiceLocator` (**TD-07**) | entities (no DI) | Tracked 2.1.10 (SL-1) |
| `blog/UrlResolver` (**TD-21**) | `Router` `new $class()` | Tracked 2.5 (SL-2) |
| `theme-one` `UrlProvider` (**TD-21**) | PHP template helper fns | Tracked 2.5 |
| **`UniqueValidator::setDb()` (TD-05)** | **Symfony `ConstraintValidatorFactory` `new $class()`** | **Untracked** |
| `IntlServiceLocator` (**TD-09**) | PHP global fns `__()` | **Accept** (DI-constructed + documented bridge) |
| `StreamWrapper::setFilesystem()` (**TD-10**) | PHP `stream_wrapper_register` | **Accept** (PHP contract) |

**TD-05 (`UniqueValidator`) is the one untracked member.** It is defensible to **accept** it (a single validator; Pagekit DNA says don't over-engineer), but the modern option exists: register a container-aware `ConstraintValidatorFactory` so validators resolve `db` via DI. Recommend deciding it alongside SL-2 (same "framework `new $class()`" theme). Note it also carries a legacy `->execute()` (`:69`, swept by 2.1.7) and `static mixed $db` typing.

### D3 — ORM & database layer 🟡

Scope of the **current step (2.1.7)** is confirmed **small and well-bounded**: only **4 files** still call `->execute()` (**TD-11**), and the DBAL-3 deprecations are localized (`Connection::exec()` alias `:246`; `Utility::getSchemaManager()` `:39` + the deprecated call `:30`; `new Comparator()` `:243`; `Installer.php:240`) (**TD-12**). Encouragingly, `MigrationService` already uses the modern `createSchemaManager()` (`:435`) — the migration subsystem is ahead of `Utility`. The `json_array`→`json` normalization (**TD-13**) is the other 2.1.7 item and is TODO-tagged in place.

The ORM's Active-Record magic (`PropertyTrait::__get/__set`, `Table`/`QueryBuilder` `__call`, **TD-14**) returns `mixed` with justified docblocks ("Genuinely unknown type — proxied…"). This is acceptable *as documented `mixed`* and structurally shrinks when 2.1.11 moves toward Data-Mapper. No new action.

### D4 — Module / extension system & boot 🟡

The Step 2.5 premise is verified precisely:

- `ModuleManager::register()` does `include $file` for each module manifest with **no** guard (`:134-136`) — a parse/fatal error in any `index.php` aborts registration.
- `ModuleManager::load()` runs the loader pipeline (`$loader->load($module)`) with **no** guard (`:104-115`).
- `Application::boot()` fires `trigger('boot', [$this])` with **no** isolation (`:36`) — any module's boot listener throwing aborts the whole boot.

This is exactly TD-15 → **Step 2.5** (sandboxed load, auto-disable with DB-independent logging, admin flash). Practical risk **today** is bounded (only first-party `blog`/`theme-one` ship), which is why RAG is 🟡 not 🔴 — but it becomes 🔴 the moment a third-party marketplace (5.6) exists, so 2.5 must precede 5.6.

### D5 — Legacy idioms & anti-patterns 🟢

Strongest domain. Searches for `add_action`/`add_filter`/`WP_`/`get_post` (WordPress), `dd(`/`collect(`/`Str::` (Laravel), `create_function(`, `Doctrine\Common\Annotations`/`@ORM\` (deprecated annotations), and `#[AllowDynamicProperties]` return **zero** real production hits (the `collect(` hits are legitimate DebugBar `DataCollector::collect(): array` methods; the `create_function` hit is a comment noting its removal). This validates the `pagekit-context.mdc` "NO WORDPRESS / NO LARAVEL" constraint and confirms the annotation→attribute (1.14/2.0.0) and `create_function` (2.0.8) closures.

Two tiny residuals: **TD-16** (`StreamWrapper::$context` dynamic-property deprecation — a real, untracked runtime deprecation, trivial to fix by declaring the property) and **TD-18** (a commented-out `catch` in `SelfupdateCommand`). `setAccessible()` (**TD-17**) is already a 2.1.9 task.

### D6 — Error handling, logging & resilience 🟡

The routing fix (atomic write + `\Throwable` fallback) is the model to generalize. The audit finds the **same failure class untracked elsewhere**:

- **TD-19 (Medium-High):** `config.php` is written non-atomically by `SettingsController::saveAction()` (`:59`) and `Installer` (`:205`), and the package registry by `Composer`/`PackageController`. `config.php` is `include`d on *every* boot; a crash or concurrent read mid-write can corrupt the running site — the exact hazard the routing fix addressed, on a higher-value file. The Router already contains a reusable atomic temp+`rename()` implementation (`writeCache()`), so the fix is *extraction*, not new design (DNA-respecting). → SL-3.
- **TD-20 (Low):** an empty `catch (\UnexpectedValueException)` in `Composer.php:63-64` silently swallows. Minor.

The broad `catch (\Exception)` usage across controllers/installer is mostly legitimate (converts to flash/JSON errors). `Connection::registerCustomTypeMappings()` has a silent catch (`:136`) but with a documented boot-ordering rationale — acceptable. The DB-independent extension logging requirement is owned by 2.5.

### D7 — Public / Extension API surface & DX 🟡

By design, the **real** public API (versioned, JWT, OpenAPI) is Step 4.2 — so "no stable public API yet" is *not* debt. Correctly **not** flagged: the platform API names (`$date`, `$number`, `$currency`, `$http`, `$url`) are a clean modern reimplementation (ROADMAP Rule 3), not a compat layer. Renames already shipped: `UrlGeneratorInterface`→`LinkReferenceType`, `GetResponseEvent`→`AuthResponseEvent` (2.1.6). The extension lifecycle was unified in 2.0.4. The remaining DX debt is that **extension-facing helpers still reach global state via static bridges** (`theme-one/functions.php`, `blog/UrlResolver`, **TD-21**) — tracked to 2.5. The module manifest (`index.php` array + boot closures) is the de-facto extension API; it works and is consistent, but its fault-tolerance is D4/2.5.

### D8 — Configuration & state management 🟡

- **TD-22 (Critical-by-policy):** a hardcoded third-party (OpenWeatherMap) **API key** sits in `dashboard/index.php` config (`:48-49`). Per audit rules the value is **not reproduced** here. It is already TODO-tagged `AUDIT FIX Step 4.2 — move API key to env variable / secrets management`, so it is tracked; mitigating context is that it is a free-tier demo-widget key, but a committed credential is Critical regardless. Recommend accelerating to env/secrets and rotating (SL-3).
- **TD-19** (config write atomicity) and **TD-08** (`NodeModelTrait` static per-request cache → 2.1.11) are the other state items. Router per-instance `setOption()` is fine (not shared global state).

### D9 — Tests, types & static analysis 🟡

- **PHPStan Level 8 achieved** (the project ceiling; Level 9 explicitly rejected). 331 blocks / 632 suppressions, stable since 2.1.6.
- **Suppression heat-map (TD-23)** — where the 632 live:
  `user 49 · theme-one 38 · blog 23 · installer 23 · site 19 · theme 17 · database 17 · console 13 · routing 11 · filter 11 · …`
  The **model cluster** (`user`+`site`+`blog`+`database` ≈ 108) is Active-Record magic + entity/DTO gaps → shrinks with **2.1.10/2.1.11**. The **theme/view cluster** (`theme-one`+`theme` ≈ 55) is Phase-3 territory (frontend-adjacent PHP). `installer` (23) is legacy `requirements.php`/DBAL, partly addressed by 2.1.6/2.1.7.
- **Test suite is green-with-issues** (**TD-25** folded into TD-16): 5 skips (env-gated SMTP — accept), 1 warning, **4 PHP deprecations = the single `StreamWrapper::$context` issue (TD-16)**, 2 PHPUnit metadata deprecations (framework-internal, low priority).
- Coverage expansion (2.1.9), mutation testing (2.1.8), and residual `mixed` (2.1.12) are scheduled. The old `MigrationServiceTest` "all skipped" finding is **RESOLVED** (2.0.4 — 12 real tests, now green in this run).

### D10 — Flag / TODO reconciliation 🟢 → see §9.

---

## 8. Proposed ROADMAP changes (proposals only — no files edited)

> None of these edit ROADMAP/PHASE files in this run. They are recommendations for the user/Orchestrator.

1. **P1 — `UniqueValidator` static locator (TD-05).** Either (a) **fold into Step 2.5** under the "framework instantiates via `new $class()`" theme — provide a container-aware `ConstraintValidatorFactory` so `UniqueValidator` gets `db` via DI; or (b) **explicitly accept** it in PHASE_2 with the same justification as `StreamWrapper`/`IntlServiceLocator` (single validator, Pagekit DNA). **Recommend (a)** for consistency with SL-2. (Its `execute()`+`mixed` are already covered by 2.1.7.)

2. **P2 — Atomic-write utility for boot-critical files (TD-19).** Add a small sub-step (suggested **2.5** scope addition, or a standalone **2.x** hygiene sub-step): extract the Router's temp+`rename()` into `Pagekit\Filesystem\Filesystem::dumpAtomic()` (or similar) and route `config.php` (`SettingsController`, `Installer`) and package-registry writes through it. Small, DNA-respecting, directly generalizes the archetype's own fix.

3. **P3 — `StreamWrapper::$context` deprecation (TD-16).** Fold into the existing **Step 2.1.9** "`setAccessible()` PHP-8.5 forward-compat cleanup" checklist (both are PHP-8.x deprecation hygiene): declare `public $context;` on `StreamWrapper`. One-line fix; removes 4 deprecations from the suite.

4. **P4 — Opportunistic cleanups.** `SelfupdateCommand.php:78` commented-out `catch` (TD-18) and `Composer.php:63-64` empty catch (TD-20) — fix when those files are next touched (no dedicated step needed).

5. **P5 — Flag retagging (docs-only, see §9).** Update the stale/under-tagged in-code flags: `system/index.php:96` (2.1.6 → 2.1.11), `NodeModelTrait.php:18` (add the 2.1.11 ID), and convert the blog-migration `AUDIT FIX Step 2.0.5` line into a permanent upgrade note (or remove). *These are code-comment edits — out of scope for this read-only run; listed for a future maintenance pass.*

6. **P6 — Sequencing guardrail (no new step).** Record in the ROADMAP that **Step 2.1.10 must precede any Step 4.2 API-serialization work** (SL-1) and **Step 2.5 must precede Step 5.6 marketplace** (D4/SL-2). These are the two "now-or-throwaway" ordering constraints.

---

## 9. Orphan / stale flag reconciliation

Every in-code flag (`TODO`/`HACK`/`FIXME`/`BRIDGE`/`BACKWARD COMPATIBILITY`/`Must be refactored`/`AUDIT FIX`) in non-test PHP, mapped to its ROADMAP home. Query: `TODO|HACK|FIXME|BRIDGE|BACKWARD COMPATIBILITY|Must be refactored` (type php, excluding `**/Tests/**`).

| Flag location | Tagged step | Verdict |
| --- | --- | --- |
| `routing/.../PhpMatcherDumper.php:18` | 2.5 | ✅ maps |
| `theme-one/functions.php:8` (TEMPORARY BRIDGE) | 2.5 | ✅ maps |
| `blog/src/UrlResolver.php:25,29,35` (TEMPORARY BRIDGE) | 2.5 | ✅ maps |
| `database/src/Types/JsonArrayType.php:16` | 2.1.7 | ✅ maps (current step) |
| `database/src/Connection.php:120` | 2.1.7 | ✅ maps (current step) |
| `database/src/ORM/EntityManager.php:20,269` | 2.1.11 | ✅ maps |
| `database/src/ORM/EntityManager.php:289` | 4.3 | ✅ maps |
| `site/src/ModelServiceLocator.php:13` | 2.1.10 (#204) | ✅ maps |
| `console/.../{Install,Update,Selfupdate,Build}Command.php` | 5.6 | ✅ maps |
| `installer/src/SelfUpdater.php:238` | 5.6 | ✅ maps |
| `intl/functions*.php`, `intl/.../PoFileLoader.php:108`, `console/.../PhpNodeVisitor.php:46`, `console/.../ExtensionTranslateCommand.php:149`, view templates (`view/index.php:19`, user/widget/blog `views/*`) | 3.4.6 | ✅ maps (frontend/translation; Phase 3) |
| `dashboard/index.php:48` (AUDIT FIX) | 4.2 | ✅ maps (see TD-22, Critical) |
| **`system/index.php:96` (RC-1)** | **2.1.6** | ⚠️ **STALE** — 2.1.6 is ✅ done; the comment body itself says the work is **2.1.11 (#205)**. Tag header should read 2.1.11. |
| **`site/src/Model/NodeModelTrait.php:18` (RC-2)** | *(none — "Must be refactored later")* | ⚠️ **UNDER-TAGGED** — PHASE_2 §2.1.11 owns it, but the in-code flag omits the step ID. Add `Step 2.1.11`. |
| **`blog/.../Version20251023070000_CreateBlogTables.php:20` (RC-3)** | **AUDIT FIX Step 2.0.5** | ⚠️ **RESIDUAL** — the file rename (timestamp format) is done (2.0.5 ✅); the line now only documents a runtime data-migration note for pre-existing installs. Convert to a permanent upgrade note or remove. |

**No true orphans** (every flag maps to a real step or a documented note). Three docs-only hygiene items (RC-1/2/3), addressed by proposal P5.

---

## 10. Appendix — Methodology & reproducible queries

**Approach:** (1) baseline (§2); (2) breadth-first PHP-scoped ripgrep/Grep, counts/locations before content to protect context; (3) cross-check each hit against `.cursor/ROADMAP.md`, `CHANGELOG-NEW.md`, and `PHASE_2_MODERNISING.md`; (4) evidence = `path:line` + query; (5) recommend the *simplest modern* fix (Pagekit DNA — no over-engineering). Frontend (Vue/JS/LESS) deliberately not re-inventoried (Phase 3).

**Exact queries used** (all via Grep, `type: php`; most excluded `**/Tests/**`):

```text
# Baseline
grep -c "message:" phpstan-baseline.neon
grep -oP 'count:\s*\K[0-9]+' phpstan-baseline.neon | awk '{s+=$1} END{print s+0}'
./app/vendor/bin/phpunit --colors=never 2>&1 | grep -E '^(OK \(|Tests:)'
./app/vendor/bin/phpunit --colors=never --display-deprecations --display-warnings   # deprecation source (TD-16)
grep -oP 'path:\s*\K.*' phpstan-baseline.neon | sed -E 's#^(...)#\1#' | sort | uniq -c | sort -rn   # TD-23 heat-map

# D2 DI / statics
::getInstance|new static\(|public static function set[A-Z]|ServiceLocator|static::\$instance
\bApp::|function app\(|\$GLOBALS|global \$

# D5 deprecated / legacy idioms
@deprecated
setAccessible|new Comparator|getSchemaManager\(|->exec\(|create_function|__get\(|__set\(|__call\(|AllowDynamicProperties
add_action|add_filter|WP_|get_post|\bdd\(|\bcollect\(|Str::|Doctrine\\Common\\Annotations|@ORM\\

# D3 ORM / DBAL
->execute\(\)
public function exec\(|function getSchemaManager|createSchemaManager\(|->getModified\(

# D4 boot / module system
function boot|function load|foreach|try|catch|Throwable   (scoped to app/modules/application/src)

# D6 resilience
catch \(\\?(Error|Throwable|\\Exception)\b|catch \([^)]*\) \{\s*\}|@?file_put_contents\(   (multiline)

# D10 flags
TODO|HACK|FIXME|BRIDGE|BACKWARD COMPATIBILITY|Must be refactored|Refactor in Phase
public \$context|protected \$context   (TD-16 confirmation)
```

**Files read for verification (representative):** `app/modules/routing/src/Router.php`; `app/modules/routing/src/Matcher/Dumper/PhpMatcherDumper.php`; `app/system/modules/intl/src/IntlServiceLocator.php`; `app/system/src/Validator/Constraints/UniqueValidator.php`; `app/modules/filesystem/src/StreamWrapper.php`; `app/system/index.php`; `app/system/modules/dashboard/index.php`; `app/system/modules/settings/src/Controller/SettingsController.php`; `app/modules/application/src/Module/ModuleManager.php`; `app/modules/application/src/Application.php`; `app/modules/database/src/{Connection,Utility}.php`; `app/modules/database/src/ORM/QueryBuilder.php`.

**Cross-references:** `.cursor/ROADMAP.md` (tracking table + 5 rules); `migration-docs/TODO/PHASE_2_MODERNISING.md` (Steps 2.1.7–2.1.12, 2.5 audit-finding routing); `CHANGELOG-NEW.md` 1.2.19–1.2.21 (routing fixes, IntlServiceLocator DI, AllowDynamicProperties removal); `MODERNISATION_STRATEGY.md` (Pagekit DNA + Quality Metrics Tracker).

---

*End of report. Read-only audit — no application code modified. Proposals in §8 are recommendations only; ROADMAP/PHASE files were not edited.*
