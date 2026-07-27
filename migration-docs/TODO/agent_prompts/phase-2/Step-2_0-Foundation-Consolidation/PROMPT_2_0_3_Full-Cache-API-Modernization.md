# Step 2.0.3: Full Cache API Modernization (PSR-6 only)

**ROADMAP:** 2.0.3 — Foundation Consolidation.  
**GitHub Issue:** #179 (this step). **Legacy reference:** Step 1.10 — Issue #130, PR #62 (`feature/psr6-cache-migration`).  
**Prerequisite:** Step 2.0.2 (Validator–Translator Integration, Issue #146) merged on your branch.

**Also read:** `.cursor/ROADMAP.md` (5 aggressive rules), `migration-docs/TODO/PHASE_2_MODERNISING.md` (Step 2.0.3 bullet list), `migration-docs/TODO/MODERNISATION_STRATEGY.md` (principles).

---

## 1. CONTEXT

### 1.1 Why this step exists

Step **1.10** replaced `doctrine/cache` with `symfony/cache` but kept a **Pagekit-specific compatibility surface**:

- `Pagekit\Cache\CacheInterface` (doctrine-like `fetch` / `save` / `contains` / `delete` / `flushAll`)
- `Pagekit\Cache\Adapter\Psr6Adapter` and five thin subclasses wrapping Symfony pools

That matched the **Phase 1** prompt (explicit backward compatibility). **Phase 2** adopts the **No Mercy** rules from `.cursor/ROADMAP.md`:

1. **No compatibility layers** — remove `CacheInterface` + `Psr6Adapter`.
2. **No adapters** — no new wrappers; update all call sites.
3. **Delete over wrap** — delete legacy code paths, do not leave dual APIs.
4. **Internal breaking changes allowed** — extensions that relied on `Pagekit\Cache\CacheInterface` must migrate to `Psr\Cache\CacheItemPoolInterface` (document in PR / CHANGELOG).

### 1.2 Target architecture (single contract)

- **Sole cache type:** `Psr\Cache\CacheItemPoolInterface` (PSR-6).
- **Do not** introduce PSR-16 (`Psr\SimpleCache`), Symfony Cache contracts as the primary type, or a new Pagekit cache interface.
- **Future:** Step **4.3** (Performance) may use `TagAwareCacheInterface` — Symfony’s tag-aware pools implement PSR-6; this step stays pool-centric.

### 1.3 Packages

No new Composer packages: `psr/cache` and `symfony/cache` are already required.

---

## 2. CRITICAL: PSR-6 KEY VALIDITY (DO NOT SKIP)

`Psr6Adapter::getNamespacedId()` currently **replaces reserved characters** in keys (`{}()/\@:`) so Symfony’s `CacheItem::validateKey()` does not throw.

After deleting `Psr6Adapter`, **any key that still contains class FQCNs, SQL fragments, or user input** must remain valid.

**Known hotspot:** `MetadataManager` builds IDs like `sprintf('%s%s.%s', $prefix, $hash, $name)` where `$name` is a **PHP class name** (contains `\`). **Backslash and other reserved chars must be normalized** before `getItem()` / `save()`, or metadata caching will fail at runtime.

**Minimum requirement:**

- Preserve behavior equivalent to today’s `Psr6Adapter::getNamespacedId()` for **dynamic** keys (at least metadata IDs). Prefer **one** small, documented place (e.g. a `private static` helper on `MetadataManager`, or a single `final` utility class under `Pagekit\Cache` **without** implementing a second cache API) — **not** a second pool wrapper.
- Audit **login attempt keys** (`auth.login_attempts_` + username): if usernames can contain reserved characters, normalize or hash the username segment.

**QueryBuilder** cache keys are `orm_query_` + `md5(...)` — typically safe; confirm no reserved characters leak into the key string.

---

## 3. SAFETY & VERIFICATION

**Workspace root.** PHPUnit: `./app/vendor/bin/phpunit`. Console: `php pagekit list`.

**After each coherent chunk of work (recommended: after each numbered section below):**

```bash
./app/vendor/bin/phpunit
php pagekit list
```

If anything fails → fix before continuing.

**Optional smoke (if local install exists):** hit frontend/admin once; run cache-related flows if you touch `CacheModule::doClearCache` (e.g. settings save that clears cache).

---

## 4. DISCOVERY (BASELINE)

Run and keep the outputs for your PR notes:

```bash
rg "Pagekit\\\\Cache\\\\CacheInterface|Pagekit\\\\Cache\\\\Adapter" app/ packages/ --glob "*.php"
rg "->fetch\\(|->contains\\(|->flushAll\\(|->save\\([^I]" app/ packages/ --glob "*.php"
rg "CacheInterface|Psr6Adapter" app/ packages/ --glob "*.php"
```

Adjust patterns if noisy; goal is **zero** references to deleted types when done.

---

## 5. DELETE THESE FILES (7)

Remove entirely (git `git rm` or delete + commit):

| File |
|------|
| `app/system/modules/cache/src/CacheInterface.php` |
| `app/system/modules/cache/src/Adapter/Psr6Adapter.php` |
| `app/system/modules/cache/src/Adapter/ArrayAdapter.php` |
| `app/system/modules/cache/src/Adapter/FilesystemAdapter.php` |
| `app/system/modules/cache/src/Adapter/PhpFilesAdapter.php` |
| `app/system/modules/cache/src/Adapter/ApcuAdapter.php` |
| `app/system/modules/cache/src/Adapter/NullAdapter.php` |

If `app/system/modules/cache/src/Adapter/` is empty afterward, remove the directory.

---

## 6. `CacheModule` — FACTORY & CLEAR

**File:** `app/system/modules/cache/src/CacheModule.php`

### 6.1 Imports

- Remove all `use Pagekit\Cache\Adapter\...` imports.
- Use `Symfony\Component\Cache\Adapter\ArrayAdapter`, `FilesystemAdapter`, `PhpFilesAdapter`, `ApcuAdapter`, `NullAdapter` (exact set as today’s mapping).

### 6.2 `createPsr6Cache(array $config): CacheItemPoolInterface`

- **Return type:** `Psr\Cache\CacheItemPoolInterface` (rename method to `createCachePool` only if you update all internal references — optional; consistency is nice).
- **Construct Symfony adapters directly** with the same storage selection logic as now (`array`, `apcu` with fallback to `PhpFilesAdapter`, `file`, `phpfile`, `null`, `nocache` → array).
- **Namespace / prefix:** Today, `prefix` in config is applied via `setNamespace()` on the Pagekit wrapper. Symfony adapters take **`$namespace` as the first constructor argument** (see Symfony 6.4 signatures). Pass `($config['prefix'] ?? '')` (or equivalent) into the adapter constructor instead of calling `setNamespace()` on a removed type.
- **Paths / lifetime:** Preserve current path fallbacks (`$config['path']`, temp dir) and default lifetime behavior (`0` = default pool lifetime unless config adds `default_lifetime` later — do not invent new config keys unless already present).

### 6.3 `doClearCache()`

- Replace `$app->get('cache')->flushAll()` with **`$app->get('cache')->clear()`** (PSR-6 `CacheItemPoolInterface::clear()`).

### 6.4 `ClearCacheCommand` — Align CLI with pool (IN SCOPE)

**File:** `app/console/src/Commands/ClearCacheCommand.php`

The CLI command `php pagekit clearcache` currently **only** deletes `*.cache` files from `path.cache`. It does **not** call `flushAll()` / `clear()` on the PSR-6 pool — meaning APCu and other non-file caches are never cleared from CLI.

**Best practice:** The CLI command should do the **same** as `CacheModule::doClearCache()`:

1. Call `$this->container->get('cache')->clear()` (clears the PSR-6 pool — works for all backends: file, APCu, array).
2. **Keep** the `*.cache` file cleanup (these are Symfony's compiled route/metadata caches, not PSR-6 items).
3. Optionally call `opcache_invalidate()` on cleared files (like `doClearCache` already does).

This ensures `php pagekit clearcache` and admin "Clear Cache" button produce **identical results** — important for developers and ops.

**Implementation sketch:**

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    if ($this->container->has('cache')) {
        $this->container->get('cache')->clear();
    }

    foreach ((array) glob($this->container->get('path.cache') . '/*.cache') as $file) {
        @unlink($file);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file);
        }
    }

    $this->line('Cache cleared.');
    return Command::SUCCESS;
}
```

---

## 7. ORM — REMOVE LEGACY UNION TYPES & BRANCHES

### 7.1 `MetadataManager`

**File:** `app/modules/database/src/ORM/MetadataManager.php`

- Property `getCache()` / `setCache()` / internal `$cache`: type **`?CacheItemPoolInterface` only**.
- Remove `use Pagekit\Cache\CacheInterface`.
- Remove the entire **“legacy CacheInterface”** branch in `get()`; keep a **single** PSR-6 read/write path using `getItem` / `isHit` / `set` / `save($item)`.
- Apply **Section 2** key normalization for metadata cache IDs before pool access.

### 7.2 `QueryBuilder`

**File:** `app/modules/database/src/ORM/QueryBuilder.php`

- `$cache` property and `cache()` method parameter: **`?CacheItemPoolInterface` only** (remove `CacheInterface` from union).
- Remove all `instanceof CacheItemPoolInterface` vs `else` branches in `get()` and `first()` — **one** PSR-6 path only.
- Remove `use Pagekit\Cache\CacheInterface`.
- **FIX BUG — Cache key must include bound parameters:** `getCacheKey()` currently hashes only `getSQL()` + `serialize($this->relations)`. Two queries with the **same SQL but different WHERE values** produce the **same cache key** → stale/wrong results. Fix: include the query's **bound parameters** in the hash. Example: `md5($sql . serialize($this->relations) . serialize($this->query->getParameters()) . $suffix)`.

### 7.3 `EntityManager`

**File:** `app/modules/database/src/ORM/EntityManager.php`

PHASE_2 notes prior cleanup. Re-verify: **no** `CacheInterface` imports or legacy branches remain. Comments referencing future `TagAwareCacheInterface` may stay if accurate.

---

## 8. CONSUMERS — MIGRATE TO PSR-6 API

### 8.1 `LoginAttemptListener`

**File:** `app/system/modules/user/src/Event/LoginAttemptListener.php`

- Constructor: `private readonly CacheItemPoolInterface $cache` (import `Psr\Cache\CacheItemPoolInterface`).
- Replace:
  - `fetch($key)` → get item, `isHit()`, `get()`; treat miss as `[]`.
  - `save($key, $data)` → `getItem($key)`, `set($data)`, `save($item)` (set TTL only if product requirements demand it; current code used infinite lifetime).
  - `delete($key)` → `deleteItem($key)`.
- **Wiring:** `app/system/modules/user/index.php` already passes `$app->get('cache')` — once `cache` is a pool, types align.

### 8.2 `UrlResolver` (static cache)

**File:** `packages/pagekit/blog/src/UrlResolver.php`

- `private static ?CacheItemPoolInterface $cache` (nullable if resolver can run without boot).
- `setCache(?CacheItemPoolInterface $cache): void` (or non-null if blog always sets it in `boot` — match actual lifecycle).
- Constructor: load `CACHE_KEY` via PSR-6 (`getItem` / `isHit` / `get()`).
- `__destruct`: if dirty, `getItem`, `set`, `save`.
- Apply **Section 2** if the key or stored structure risks invalid keys (static key `blog.routing` is fine).

### 8.3 `RouteListener`

**File:** `packages/pagekit/blog/src/Event/RouteListener.php`

- `private readonly CacheItemPoolInterface $cache`.
- `clearCache()`: `deleteItem(UrlResolver::CACHE_KEY)`.

### 8.4 `blog/scripts.php`

**File:** `packages/pagekit/blog/scripts.php`

- Uninstall hook: `$app->get('cache')->clear()` is already PSR-6–compatible **once** the `cache` service is a `CacheItemPoolInterface`. Re-verify after `CacheModule` change; no legacy `flushAll()` here.

---

## 9. TESTS

### 9.1 Rename / rewrite cache module tests

**Current:** `app/system/modules/cache/src/Tests/Psr6AdapterTest.php`

- Rename class/file to **`CachePoolTest`** (or `SymfonyCachePoolTest`) and update PHPUnit / autoload discovery if needed.
- Tests must target **`CacheItemPoolInterface`** returned by `CacheModule`’s factory logic — **not** `fetch`/`save`/`flushAll`.
- Cover:
  - At least one **filesystem / phpfile / array** scenario (as practical in unit tests).
  - **Namespace / prefix:** two logical “pools” with different prefixes do not collide (if applicable).
  - **`clear()`** clears the pool.
- Remove tests that only exist to assert **CacheInterface** behavior.

### 9.2 `QueryBuilderCacheTest`

**File:** `app/modules/database/src/Tests/ORM/QueryBuilderCacheTest.php`

- Already mocks `CacheItemPoolInterface`; update only if signatures or behavior of `QueryBuilder::cache()` change.
- If you add integration-style tests for `get()`/`first()` cache hits, use mocked items (`isHit`, `get`, `set`, `save`).

### 9.3 Full suite

```bash
./app/vendor/bin/phpunit
```

---

## 10. EXTENSION / PUBLIC SURFACE NOTE

Removing `Pagekit\Cache\CacheInterface` is a **breaking change for any third-party extension** that type-hinted or implemented it. Mention in:

- PR description + `CHANGELOG-NEW.md` (per project workflow),
- Short “migrate to `CacheItemPoolInterface`” note (PSR-6 `getItem` / `save` patterns).

---

## 11. SUCCESS CRITERIA (CHECKLIST)

- [ ] All **7** legacy files deleted; no `Pagekit\Cache\CacheInterface` or `Adapter\Psr6Adapter` references remain in `app/` or `packages/`.
- [ ] `cache` (and other named caches from config) resolve to **`CacheItemPoolInterface`** instances built from **Symfony** adapters only.
- [ ] `CacheModule::doClearCache` uses **`clear()`**, not `flushAll()`.
- [ ] `MetadataManager` and `QueryBuilder` use **PSR-6 only**; metadata cache keys remain **valid** (Section 2).
- [ ] `QueryBuilder::getCacheKey()` includes **bound parameters** in the hash (no cache collisions).
- [ ] `LoginAttemptListener`, `UrlResolver`, `RouteListener`, blog uninstall script work against pools.
- [ ] `ClearCacheCommand` calls `clear()` on the PSR-6 pool (not just file deletion).
- [ ] `./app/vendor/bin/phpunit` **green**; `php pagekit list` **OK**.

---

## 12. OPTIONAL: PHASE_1 PROMPT VS CURRENT RULES

The Step **1.10** agent prompt in `PHASE_1_MODERNISING.md` explicitly asked for **backward compatibility** and phased dual systems. **This Step 2.0.3 prompt deliberately reverses that** per ROADMAP **Rules 1 & 4**. Do not reintroduce a compatibility layer “for extensions”; document breakage instead.

---

## 13. TRACKING REFERENCES (INCONSISTENCIES TO RESOLVE IN DOCS, NOT IN CODE)

| Topic | Note |
|-------|------|
| ROADMAP Issue column | Step 2.0.3 lists **#179**; legacy cache work is **#130** / PR **#62**. |
| `ClearCacheCommand` | Now **in scope** (Section 6.4): align CLI `clearcache` with `CacheModule::doClearCache()` by calling pool `clear()`. |
| `blog/scripts.php` + `clear()` | Works today because concrete Pagekit adapters exposed `clear()` via `Psr6Adapter`; it was **not** part of `CacheInterface`. After this step, `clear()` is the **official** PSR-6 API — cleaner. |

---

**End of prompt.**
