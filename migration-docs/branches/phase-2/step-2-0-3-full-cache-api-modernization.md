# Full Cache API Modernization (PSR-6 Only)

## Overview

This document describes the complete removal of the Pagekit-specific `CacheInterface` compatibility layer and migration of all cache consumers to `Psr\Cache\CacheItemPoolInterface` (PSR-6) directly.

**Migration Date**: April 2026
**Pagekit Version**: 1.2.8
**Branch**: `cursor/full-cache-api-modernization-a484`
**ROADMAP Step**: 2.0.3
**GitHub Issue**: #179
**Status**: ✅ COMPLETED

## Migration Summary

### What Changed
- **Removed**: `Pagekit\Cache\CacheInterface` — Pagekit-specific cache interface (doctrine-like `fetch`/`save`/`contains`/`delete`/`flushAll`)
- **Removed**: `Pagekit\Cache\Adapter\Psr6Adapter` + 5 subclasses — Compatibility wrappers around Symfony pools
- **Updated**: All cache consumers now use `CacheItemPoolInterface` (PSR-6) directly
- **Fixed**: QueryBuilder cache key collision bug (missing bound parameters in hash)
- **Aligned**: CLI `clearcache` command now matches admin "Clear Cache" behavior

### Why This Migration
1. **No Compatibility Layers** (ROADMAP Rule 1) — The Pagekit `CacheInterface` was a Phase 1 compatibility layer that duplicated the PSR-6 contract
2. **No Adapters** (ROADMAP Rule 2) — `Psr6Adapter` and its subclasses were unnecessary wrappers around Symfony adapters
3. **Delete Over Wrap** (ROADMAP Rule 4) — Legacy code paths physically deleted, not commented out
4. **Internal Breaking Changes Allowed** (ROADMAP Rule 3) — Extensions must migrate to `CacheItemPoolInterface`

## Breaking Changes for Extensions

**Removed types:**
- `Pagekit\Cache\CacheInterface` — use `Psr\Cache\CacheItemPoolInterface` instead
- `Pagekit\Cache\Adapter\Psr6Adapter` — use Symfony adapters directly
- `Pagekit\Cache\Adapter\ArrayAdapter` — use `Symfony\Component\Cache\Adapter\ArrayAdapter`
- `Pagekit\Cache\Adapter\FilesystemAdapter` — use `Symfony\Component\Cache\Adapter\FilesystemAdapter`
- `Pagekit\Cache\Adapter\PhpFilesAdapter` — use `Symfony\Component\Cache\Adapter\PhpFilesAdapter`
- `Pagekit\Cache\Adapter\ApcuAdapter` — use `Symfony\Component\Cache\Adapter\ApcuAdapter`
- `Pagekit\Cache\Adapter\NullAdapter` — use `Symfony\Component\Cache\Adapter\NullAdapter`

**API migration guide:**

| Old (CacheInterface)                    | New (CacheItemPoolInterface / PSR-6)                    |
|-----------------------------------------|---------------------------------------------------------|
| `$cache->fetch($key)`                   | `$item = $cache->getItem($key); $item->isHit() ? $item->get() : false` |
| `$cache->contains($key)`               | `$cache->hasItem($key)`                                 |
| `$cache->save($key, $data, $ttl)`      | `$item = $cache->getItem($key); $item->set($data); $item->expiresAfter($ttl); $cache->save($item)` |
| `$cache->delete($key)`                 | `$cache->deleteItem($key)`                              |
| `$cache->flushAll()`                    | `$cache->clear()`                                       |
| `$cache->setNamespace($ns)`            | Pass `$namespace` via Symfony adapter constructor        |

## Technical Implementation

### 1. Deleted Files (7)

```
app/system/modules/cache/src/
├── CacheInterface.php                 # DELETED — Pagekit cache interface
└── Adapter/                           # DELETED — entire directory
    ├── Psr6Adapter.php                # DELETED — base compatibility wrapper
    ├── ArrayAdapter.php               # DELETED — extended Psr6Adapter
    ├── FilesystemAdapter.php          # DELETED — extended Psr6Adapter
    ├── PhpFilesAdapter.php            # DELETED — extended Psr6Adapter
    ├── ApcuAdapter.php                # DELETED — extended Psr6Adapter
    └── NullAdapter.php                # DELETED — extended Psr6Adapter
```

### 2. CacheModule Factory Rewrite

**File:** `app/system/modules/cache/src/CacheModule.php`

- `createCachePool()` returns `CacheItemPoolInterface` (was `CacheInterface`)
- Constructs Symfony adapters directly — no Pagekit wrapper layer
- Namespace/prefix passed via Symfony adapter constructor `$namespace` parameter
- `doClearCache()` uses PSR-6 `clear()` (was `flushAll()`)

### 3. ORM Modernization

**MetadataManager** (`app/modules/database/src/ORM/MetadataManager.php`):
- `$cache` property: `?CacheItemPoolInterface` (was `CacheItemPoolInterface|CacheInterface|null`)
- Single PSR-6 code path in `get()` (removed legacy `CacheInterface` branch)
- `sanitizeCacheKey()`: Replaces PSR-6 reserved characters (`{}()/\@:`) with `_` for class FQCN safety

**QueryBuilder** (`app/modules/database/src/ORM/QueryBuilder.php`):
- `$cache` property: `?CacheItemPoolInterface` (was union type)
- Single PSR-6 code path in `get()` and `first()` (removed `instanceof` dual branches)
- **Bug fix:** `getCacheKey()` now includes bound parameters (`$this->query->params()`) in the hash to prevent cache collisions when the same SQL template has different WHERE values

**EntityManager** (`app/modules/database/src/ORM/EntityManager.php`):
- Already PSR-6 clean (uses `$cache->clear()`)
- TODO for Step 4.3 (TagAwareCacheInterface) preserved

### 4. Consumer Migrations

**LoginAttemptListener** (`app/system/modules/user/src/Event/LoginAttemptListener.php`):
- `$cache` typed as `CacheItemPoolInterface` (was `mixed`)
- `getCacheKey()` sanitizes PSR-6 reserved characters in usernames
- All operations use PSR-6 API (`getItem`/`isHit`/`set`/`save`/`deleteItem`)

**UrlResolver** (`packages/pagekit/blog/src/UrlResolver.php`):
- Static `$cache` typed as `?CacheItemPoolInterface` (was `mixed`)
- Constructor and destructor use PSR-6 API

**RouteListener** (`packages/pagekit/blog/src/Event/RouteListener.php`):
- `$cache` typed as `CacheItemPoolInterface` (was `mixed`)
- `clearCache()` uses `deleteItem()` (was `delete()`)

### 5. ClearCacheCommand Alignment

**File:** `app/console/src/Commands/ClearCacheCommand.php`

- Now calls `$cache->clear()` on the PSR-6 pool before file cleanup
- Adds `opcache_invalidate()` on cleared `.cache` files
- CLI `clearcache` now produces identical results to admin "Clear Cache" button

### 6. Test Rewrite

**Renamed:** `Psr6AdapterTest.php` → `CachePoolTest.php`

All tests rewritten against PSR-6 `CacheItemPoolInterface` contract:
- `testArrayAdapter` — PSR-6 save/get/hasItem/deleteItem/clear
- `testFilesystemAdapter` — persistence, TTL expiration
- `testPhpFilesAdapter` — complex data, large arrays
- `testNullAdapter` — no-op behavior
- `testPsr6PoolContract` — comprehensive PSR-6 contract exercise
- `testNamespaceIsolation` — Symfony constructor namespace isolation
- `testPerformanceBenchmark` — write/read benchmarks via PSR-6

## Test Results

```
PHPUnit 11.5.55 — 280 tests, 652 assertions
Cache-related: 11 tests, all PASS
Pre-existing failures: 10 (IntlServiceLocator + ValidatorTranslator — unrelated)
```

`php pagekit list` — OK, `clearcache` command visible.

## Files Changed

| File | Change |
|------|--------|
| `app/system/modules/cache/src/CacheInterface.php` | DELETED |
| `app/system/modules/cache/src/Adapter/Psr6Adapter.php` | DELETED |
| `app/system/modules/cache/src/Adapter/ArrayAdapter.php` | DELETED |
| `app/system/modules/cache/src/Adapter/FilesystemAdapter.php` | DELETED |
| `app/system/modules/cache/src/Adapter/PhpFilesAdapter.php` | DELETED |
| `app/system/modules/cache/src/Adapter/ApcuAdapter.php` | DELETED |
| `app/system/modules/cache/src/Adapter/NullAdapter.php` | DELETED |
| `app/system/modules/cache/src/CacheModule.php` | Factory rewrite, PSR-6 return type |
| `app/modules/database/src/ORM/MetadataManager.php` | PSR-6 only, key sanitization |
| `app/modules/database/src/ORM/QueryBuilder.php` | PSR-6 only, cache key bug fix |
| `app/modules/database/src/Tests/ORM/QueryBuilderCacheTest.php` | Add params() mock |
| `app/system/modules/user/src/Event/LoginAttemptListener.php` | PSR-6 migration |
| `packages/pagekit/blog/src/UrlResolver.php` | PSR-6 migration |
| `packages/pagekit/blog/src/Event/RouteListener.php` | PSR-6 migration |
| `app/console/src/Commands/ClearCacheCommand.php` | PSR-6 pool clear alignment |
| `app/system/modules/cache/src/Tests/Psr6AdapterTest.php` | DELETED (renamed) |
| `app/system/modules/cache/src/Tests/CachePoolTest.php` | NEW — PSR-6 pool tests |
