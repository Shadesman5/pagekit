# Step 2.1.11: EntityManager DI — remove singleton (Active-Record → Data-Mapper)

<!-- conductor-mode: full -->

**ROADMAP:** 2.1.11. GitHub Issue: #205. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.6 (PHPStan Level 7→8 — typing hardened, no wrap) and Step 2.1.10 (Entity Presentation Layer — `ModelServiceLocator` removed first). Presentation-layer cleanup precedes this persistence-layer refactor.
- **Risk:** High — the static Active-Record API ripples into every model call site (~25 files across `site`, `user`, `widget`, `blog`) and into the ORM test harness.
- **Closes Phase 1 audit:** **Step 1.11 (ORM Modernization)** — removes the `EntityManager` singleton, the last 1.11 item beyond `ModelServiceLocator`. Combined with 2.0.8 + 2.1.6 + 2.1.10, this **fully flips 1.11 ⚠️ → 🛡️** at Finalize.
- **Background:** `EntityManager` registers itself as a static singleton in its constructor (`self::$instance = $this`) and exposes it via `getInstance()`. `ModelTrait::getManager()` falls back to that singleton, and `app/system/index.php` eagerly resolves `db.em` at boot **solely** to populate it so static model calls work. It is the **last global-state access in the model layer**. Tagged in-code:

```php
// TODO: Must be refactored in Step 2.1.11 (EntityManager DI)   // EntityManager.php (property + getInstance())
```

**Goal:** Remove the `EntityManager` singleton and its boot hack; obtain the `EntityManager` (or repositories) via DI so the model layer holds no static global state.

---

## 0. SAFETY CHECKS (CRITICAL)

**Before starting — verify all of these:**

1. **Branch up-to-date with `develop`** (Steps 2.1.6 and 2.1.10 merged).
2. **Full suite green:**
   ```bash
   ./app/vendor/bin/phpunit
   ./app/vendor/bin/phpstan analyse
   ```

**After every checklist batch:**

```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse
```

**IF ANY FAILS → STOP AND FIX!**

---

## 1. DISCOVERY — TRACE ALL CALLERS (Architect mandatory)

Run these scans at ticket-planning time and enumerate every call site in the ticket. Do not rely on this prompt's list alone — reconcile with a fresh `rg` pass.

```bash
# Singleton, boot hack, manager access
rg -n "getInstance\(\)|self::\$instance|static::\$instance|getManager\(\)" app/ packages/
rg -n "db\.em" app/ packages/

# Static Active-Record call sites (per model class)
rg -n "\b(Node|Page|Post|Comment|User|Role|Widget)::(find|findAll|where|query|create)\(" app/ packages/

# Instance persistence that routes through the manager (filter to model entities)
rg -n "->save\(|->delete\(" app/system/ packages/pagekit/ app/modules/

# Node request-scoped static cache
rg -n "self::\$nodes|static \?array \$nodes" app/system/modules/site/
```

**Known touchpoints (verify + extend at planning time):**

| Concern | Location | Action |
|---------|----------|--------|
| Singleton assignment + property | `EntityManager::__construct` `self::$instance = $this` (`EntityManager.php:36`) + `private static ?self $instance` (`:20-21`) | remove |
| Static accessor | `EntityManager::getInstance()` (`EntityManager.php:274-278`) | remove |
| Manager fallback | `ModelTrait::getManager()` (`ModelTrait.php:16-28`) | refactor to DI, no singleton fallback |
| Active-Record statics | `ModelTrait::find/findAll/where/query/create` + instance `save()`/`delete()` (`ModelTrait.php`) | migrate to injected EM/repository |
| EM Active-Record delegation | `EntityManager::find()` calls `"{$entity}::find"` (`EntityManager.php:73-86`) | de-couple per design |
| Boot hack | eager `$app->get('db.em')` incl. its TODO block (`app/system/index.php:96-102`) | **delete** |
| DI service (KEEP) | `db.em` factory (`app/modules/database/index.php:82`) | keep — this is the legit registration |
| Node request cache | `NodeModelTrait::$nodes` static (`NodeModelTrait.php:18-21`) | replace with injected `CacheItemPoolInterface` |
| Call sites (~25 files) | controllers/listeners/providers in `site`, `user`, `widget`, `blog` | migrate |
| Tests coupled to singleton | `EntityManagerTest:51` (asserts `getInstance()`); `UserProviderTest` (`RunInSeparateProcess` + `primeEntityManager()` for `ModelTrait::getManager()`) | rework |
| Deferred integration notes | `UserProviderTest` / `UserTest` docblocks defer DB happy-paths to integration — revisit after DI (inject EM + mock chain, or keep deferred) | update |

---

## 2. ARCHITECTURE — ACTIVE-RECORD → DATA-MAPPER (Architect design pass required)

The target direction (Issue #205 / PHASE_2 §2.1.11): the model layer no longer reaches a process-global `EntityManager`. Persistence and lookup move behind DI — an injected `EntityManager` and/or per-entity repositories (Data Mapper). The exact repository/DI shape and the call-site migration order are the Architect's design decision; the constraints below are fixed.

**Must hold:**

- No `static::$instance` / `self::$instance` and no `getInstance()` on `EntityManager`.
- `ModelTrait::getManager()` no longer falls back to a singleton — the manager is obtained via DI (or `getManager()` is removed and callers use the injected EM/repository directly).
- No boot-time side effect populates a manager (the `app/system/index.php` eager `db.em` resolve is deleted).
- The `db.em` container service stays; it is now the sole access path (injected).
- `NodeModelTrait`'s static request-scoped `$nodes` cache is replaced by an injected `CacheItemPoolInterface` (same global-state removal as the singleton).

**Recommended direction (Architect may refine):** convert entity Active-Record calls to Data-Mapper — `Model::find($id)` → repository/`$em` lookup, `$entity->save()` / `$entity->delete()` → `$em->save($entity)` / `$em->delete($entity)` — and inject the EM/repository into the controllers, listeners, and providers that currently make static model calls.

### In-code flag hygiene (audit 2026-07-07 — Proposal P5 / §9)

- **RC-1** — `app/system/index.php:96`: the eager `db.em` boot line and its TODO block are deleted here, which resolves the mis-targeted `Step 2.1.6` header automatically (no retag needed once the block is gone).
- **RC-2** — `app/system/modules/site/src/Model/NodeModelTrait.php:18`: replacing the static `$nodes` cache with an injected `CacheItemPoolInterface` clears the flagged global mutable state.
- **RC-3** (docs-only, unrelated file — fix opportunistically if the blog migration is touched): `packages/pagekit/blog/src/Migrations/2025/Version20251023070000_CreateBlogTables.php:20` carries a residual `AUDIT FIX Step 2.0.5` note whose timestamp-rename already shipped; convert it to a permanent upgrade note or remove.

### Explicit non-goals (defer)

- **Tag-based cache invalidation** — `EntityManager::invalidateCache()` `$cache->clear()` is tagged for **Step 4.3** (`TagAwareCacheInterface`); do not change invalidation semantics here.
- **`ModelServiceLocator`** — already removed in Step 2.1.10 (prerequisite).
- **`blog/UrlResolver` static cache** — blocked on routing factory DI (Step 1.8); out of scope.
- **Swapping to Doctrine ORM** — this is Pagekit's own ORM; the task removes global state, not the ORM.

---

## 3. WORK ITEMS (non-exhaustive)

The §1 touchpoints are a starting set. Build the checklist from a fresh caller trace and add any call site it surfaces.

- DI access to the `EntityManager` for models (injected EM / repository pattern) — no static singleton
- Remove `static::$instance` + `getInstance()` from `EntityManager`; refactor `ModelTrait::getManager()` off the singleton fallback
- Migrate all `EntityManager::getInstance()` callers and static `Model::find()/where()/query()/create()/findAll()` call sites (site, user, widget, blog)
- Replace `NodeModelTrait::$nodes` static cache with an injected `CacheItemPoolInterface`
- Delete the `$app->get('db.em')` boot line (+ TODO block) in `app/system/index.php`
- Rework ORM tests coupled to the singleton (`EntityManagerTest`, `UserProviderTest`); update deferred-integration docblocks in `UserProviderTest` / `UserTest`
- In-code flag hygiene: RC-1, RC-2 (and RC-3 opportunistically)

---

## 4. TESTING

### 4.1. PHPUnit (required)

**Project test style (match existing suite — no full kernel boot):**

- Instantiate services under test via **constructor injection** + PHPUnit mocks (`createMock()` / partial mocks). Do **not** boot `Application`, `App::getInstance()`, or a full HTTP kernel in unit tests.
- **Mock `Connection`** (and QueryBuilder/Result stubs) for lookup/persistence behaviour — default for listeners, providers, repositories.
- **SQLite `:memory:`** only where SQL semantics matter (existing precedent: `EntityManagerCacheInvalidationTest::bootSqliteManager()`, `MigrationServiceTest`). A bare `new EntityManager(...)` in tests is fine once the constructor no longer registers a process-static singleton.

**Singleton-coupled tests to rework:**

- **`EntityManagerTest`** (`app/modules/database/src/Tests/ORM/EntityManagerTest.php:51`) — `testGetInstance()` asserts `EntityManager::getInstance()`; remove/replace with DI-based access (constructor wiring, no static accessor).
- **`UserProviderTest`** — `RunInSeparateProcess` + `primeEntityManager()` exist solely to prime the process-static singleton for `User::where()` → `ModelTrait::getManager()`. Rework to inject the EM/repository; drop `RunInSeparateProcess` / `primeEntityManager()` once the static fallback is gone.
- **`UserTest`** — does **not** boot the singleton today (it avoids `findRoles()` via Reflection). Update its deferred-integration docblock if `hasPermission()` uncached paths become unit-testable with an injected EM.

**New / extended coverage:**

- Model lookup/persistence via injected EM/repository with **no global state**.
- **`NodeModelTrait`** cache replacement — mock `CacheItemPoolInterface`, assert request-scoped cache behaviour (no static `$nodes`).
- Revisit **`UserProviderTest`** deferred happy-path DB lookups (`find()`, `findByUsername()`, full `findByCredentials()` row hydrate): inject EM + mock chain in unit tests, or keep explicitly deferred in the class docblock (Architect decides per checklist step).

### 4.2. PHPStan

- `./app/vendor/bin/phpstan analyse` — zero new baseline entries.

### 4.3. Playwright E2E (Final Test)

Run the 3 sound E2E specs after CI is green — exercise CRUD paths that used static model access:

- Site nodes (create/edit/delete, menu tree)
- Users/roles admin
- Blog posts + comments

---

## 5. AGGRESSIVE MODERNIZATION RULES

1. **NO COMPATIBILITY LAYERS** — do not keep the singleton alongside DI.
2. **NO ADAPTERS** — do not wrap `getInstance()`; update every call site.
3. **DELETE OVER WRAP** — remove `static::$instance`, `getInstance()`, and the boot hack; no `@deprecated` shim.
4. **INTERNAL BREAKING CHANGES ALLOWED** — the static model API may change; update all internal callers in the same PR. Document any extension-facing model-API change in the branch doc.
5. **NO NEW BRIDGES** — do not introduce a new global manager holder "temporarily".

---

## AUDIT FINDINGS (Phase 1 Review — scoped to this step)

- **`EntityManager` singleton** — `static::$instance` + `getInstance()`; Step 2.1.6 hardened typing only; this step removes it and closes the last 1.11 item.
- **`NodeModelTrait` static cache** — request-scoped `static ?array $nodes` global mutable state; replace with an injected `CacheItemPoolInterface`.
- **Boot-time side effect** — eager `db.em` resolve exists only to prime the singleton; delete it.

---

## SUCCESS CRITERIA

- No `static::$instance` / `self::$instance` / `getInstance()` in `EntityManager`
- No `db.em` boot hack in `app/system/index.php`; the `db.em` service is obtained via DI only
- `ModelTrait::getManager()` no longer falls back to a singleton (or is removed)
- `NodeModelTrait` static `$nodes` replaced by an injected `CacheItemPoolInterface`
- RC-1 and RC-2 flags resolved (RC-3 opportunistically)
- PHPUnit + PHPStan pass; no new baseline entries
- Playwright E2E (3 specs) pass at Final Test

---

## VALIDATION CHECKLIST

_Acceptance bar — not the full checklist._

- [ ] `EntityManager`: `static::$instance` + `getInstance()` removed
- [ ] `ModelTrait::getManager()` off the singleton (DI-based or removed)
- [ ] All `getInstance()` / static `Model::find/where/query/create/findAll` call sites migrated
- [ ] `NodeModelTrait::$nodes` replaced with injected `CacheItemPoolInterface`
- [ ] `app/system/index.php` eager `db.em` boot line + TODO block deleted (RC-1)
- [ ] `db.em` service definition (`app/modules/database/index.php`) retained
- [ ] `EntityManagerTest` / `UserProviderTest` reworked off the singleton; `UserTest` deferred notes updated if applicable
- [ ] RC-2 resolved; RC-3 addressed if the blog migration was touched
- [ ] `./app/vendor/bin/phpunit` passes
- [ ] `./app/vendor/bin/phpstan analyse` passes (no new baseline entries)
- [ ] Playwright E2E (3 specs) pass
- [ ] Branch doc notes any extension-facing model-API change
- [ ] ROADMAP Finalize: Step 1.11 audit cell **⚠️ → 🛡️** (full closure with 2.0.8 + 2.1.6 + 2.1.10)
