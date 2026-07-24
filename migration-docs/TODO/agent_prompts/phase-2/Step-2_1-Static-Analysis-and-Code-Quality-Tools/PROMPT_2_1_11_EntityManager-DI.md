# Step 2.1.11: EntityManager DI — remove singleton (Active-Record → Data-Mapper)

<!-- conductor-mode: full -->

**ROADMAP:** 2.1.11. GitHub Issue: #205. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.1.11.

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

## 1. DISCOVERY — TRACE ALL CALLERS

Reconcile with a fresh `rg` pass at planning time; enumerate every call site in the ticket checklist.

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

## 2. ARCHITECTURE — ACTIVE-RECORD → DATA-MAPPER

The model layer no longer reaches a process-global `EntityManager`. Persistence and lookup move behind DI — an injected `EntityManager` and/or per-entity repositories (Data Mapper). The exact repository/DI shape and call-site migration order are the Architect's design decision; the constraints below are fixed.

**Must hold:**

- No `static::$instance` / `self::$instance` and no `getInstance()` on `EntityManager`.
- `ModelTrait::getManager()` no longer falls back to a singleton — the manager is obtained via DI (or `getManager()` is removed and callers use the injected EM/repository directly).
- No boot-time side effect populates a manager (the `app/system/index.php` eager `db.em` resolve is deleted).
- The `db.em` container service stays; it is now the sole access path (injected).
- `NodeModelTrait`'s static request-scoped `$nodes` cache is replaced by an injected `CacheItemPoolInterface`.
- No new global manager holder or temporary bridge (Aggressive Rule 5).

**Recommended direction (Architect may refine):** convert entity Active-Record calls to Data-Mapper — `Model::find($id)` → repository/`$em` lookup, `$entity->save()` / `$entity->delete()` → `$em->save($entity)` / `$em->delete($entity)` — and inject the EM/repository into the controllers, listeners, and providers that currently make static model calls.

### In-code flag hygiene (audit 2026-07-07 — Proposal P5 / §9)

- **RC-1** — `app/system/index.php:96`: delete the eager `db.em` boot line and its TODO block (resolves the mis-targeted `Step 2.1.6` header automatically).
- **RC-2** — `app/system/modules/site/src/Model/NodeModelTrait.php:18`: replace static `$nodes` cache with injected `CacheItemPoolInterface`.
- **RC-3** (docs-only, opportunistic if blog migration touched): `packages/pagekit/blog/src/Migrations/2025/Version20251023070000_CreateBlogTables.php:20` — convert residual `AUDIT FIX Step 2.0.5` note to permanent upgrade note or remove.

### Explicit non-goals (defer)

- **ORM cache invalidation strategy** — Step 4.3 (`TagAwareCacheInterface`). Do not change `EntityManager::invalidateCache()` semantics here.
- **`blog/UrlResolver` static bridge** — Step 2.5. Out of scope.
- **Doctrine ORM swap** — non-goal. Remove global state in Pagekit's ORM only.

---

## 3. TESTING (step-specific)

**PHPUnit style for this step:**

- Constructor injection + PHPUnit mocks; **no** full kernel boot in unit tests.
- Mock `Connection` (and QueryBuilder/Result stubs) for lookup/persistence behaviour.
- SQLite `:memory:` only where SQL semantics matter (`EntityManagerCacheInvalidationTest::bootSqliteManager()`, `MigrationServiceTest` precedent).

**Singleton-coupled tests to rework:**

- **`EntityManagerTest`** (`EntityManagerTest.php:51`) — remove/replace `testGetInstance()`.
- **`UserProviderTest`** — drop `RunInSeparateProcess` / `primeEntityManager()` once static fallback is gone; inject EM/repository.
- **`UserTest`** — update deferred-integration docblock if `hasPermission()` uncached paths become unit-testable with injected EM.

**New / extended coverage:**

- Model lookup/persistence via injected EM/repository with no global state.
- **`NodeModelTrait`** — mock `CacheItemPoolInterface`; assert request-scoped cache behaviour (no static `$nodes`).
- **`UserProviderTest`** deferred happy-path DB lookups — inject EM + mock chain, or keep explicitly deferred (Architect decides per step).

**E2E focus (final Execute step):** site nodes CRUD + menu tree; users/roles admin; blog posts + comments.

---

## SUCCESS CRITERIA

- Caller inventory documented in ticket (fresh `rg` at planning time)
- No `static::$instance` / `self::$instance` / `getInstance()` in `EntityManager`
- `ModelTrait::getManager()` off the singleton (DI-based or removed)
- All `getInstance()` / static `Model::find/where/query/create/findAll` call sites migrated
- `NodeModelTrait` static `$nodes` replaced with injected `CacheItemPoolInterface` (RC-2)
- `app/system/index.php` eager `db.em` boot line + TODO block deleted (RC-1); `db.em` service definition (`app/modules/database/index.php`) retained
- `EntityManagerTest` / `UserProviderTest` reworked off the singleton; `UserTest` deferred notes updated if applicable
- RC-3 addressed if blog migration was touched
- `./app/vendor/bin/phpunit` + `./app/vendor/bin/phpstan analyse` pass (no new baseline entries)
- Branch doc notes any extension-facing model-API change
- ROADMAP Finalize: Step 1.11 audit cell **⚠️ → 🛡️** (full closure with 2.0.8 + 2.1.6 + 2.1.10)
