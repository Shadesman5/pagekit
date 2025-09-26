# PSR-6 Cache Migration Documentation

## Current Status: Phase 1 - Dual System Running

### ✅ What's Working
- doctrine/cache is installed and working
- PSR-6 adapters are created and ready
- All cache operations use doctrine/cache API
- System is stable: Web, Admin, CLI all working

### Test Results (All Passing)
- ✅ `php pagekit list` - CLI works
- ✅ `curl http://localhost:8000` - Web root returns 200
- ✅ `curl http://localhost:8000/admin` - Admin redirects to login
- ✅ `php pagekit clearcache` - Cache clearing works

## Migration Plan

### Phase 1: Add PSR-6 support alongside doctrine/cache ✅
**Status: COMPLETE**
- Created PSR-6 adapters that wrap Symfony Cache
- Adapters extend CacheProvider for backward compatibility
- Both systems can run in parallel
- All code still uses doctrine/cache API

Files created:
- `app/system/modules/cache/src/Adapter/Psr6Adapter.php`
- `app/system/modules/cache/src/Adapter/ArrayAdapter.php`
- `app/system/modules/cache/src/Adapter/ApcuAdapter.php`
- `app/system/modules/cache/src/Adapter/FilesystemAdapter.php`
- `app/system/modules/cache/src/Adapter/PhpFilesAdapter.php`
- `app/system/modules/cache/src/Adapter/NullAdapter.php`

### Phase 2: Migrate core modules ⏳
**Status: PENDING**

Core modules to check:
- `app/modules/routing` - Uses file-based caching, not cache service ✅
- `app/modules/application` - No cache usage ✅
- `app/modules/database` - MetadataManager uses cache (currently doctrine/cache)

### Phase 3: Migrate system modules ⏳
**Status: PENDING**

System modules using cache:
- `app/system/modules/user/src/Event/LoginAttemptListener.php` - Uses doctrine/cache
- `packages/pagekit/blog/src/UrlResolver.php` - Uses doctrine/cache
- `packages/pagekit/blog/src/Event/RouteListener.php` - Uses doctrine/cache

### Phase 4: Remove doctrine/cache ⏳
**Status: PENDING**
- Remove from composer.json
- Remove legacy cache classes
- Clean up compatibility layer

## Migration Method Mapping

### Current (doctrine/cache) → Target (PSR-6)
```php
// Fetch
$data = $cache->fetch($key);
// becomes
$item = $cache->getItem($key);
$data = $item->isHit() ? $item->get() : false;

// Save
$cache->save($key, $data, $ttl);
// becomes
$item = $cache->getItem($key);
$item->set($data);
if ($ttl > 0) {
    $item->expiresAfter($ttl);
}
$cache->save($item);

// Delete
$cache->delete($key);
// becomes
$cache->deleteItem($key);

// Contains
$exists = $cache->contains($key);
// becomes
$exists = $cache->hasItem($key);

// Clear
$cache->flushAll();
// becomes
$cache->clear();
```

## Important Notes

### Why Phase 1 is Critical
- Allows testing PSR-6 adapters without breaking existing code
- Provides fallback if issues arise
- Extensions continue working without changes

### Current Architecture
```
Application Code
    ↓
doctrine/cache API (CacheProvider)
    ↓
CacheModule (decides which implementation)
    ↓
Either:
- Legacy: doctrine/cache implementations
- New: PSR-6 Adapters (wrapping Symfony Cache)
```

### Next Steps for Phase 2-3
1. Enable PSR-6 for one cache at a time in `shouldUsePsr6()`
2. Test thoroughly after each change
3. Update code to use PSR-6 API directly
4. Keep backward compatibility layer

## Testing Checklist

After EVERY change:
- [ ] `php pagekit list` - Must show command list
- [ ] `curl -I http://localhost:8000` - Must return 200
- [ ] `curl -I http://localhost:8000/admin` - Must return 302 or 200
- [ ] `php pagekit clearcache` - Must clear cache successfully

## Files Modified

### Phase 1 Changes
- ✅ `composer.json` - Added doctrine/cache back
- ✅ `app/system/modules/cache/src/CacheModule.php` - Dual system support
- ✅ `app/system/modules/cache/src/FilesystemCache.php` - Legacy cache restored
- ✅ `app/system/modules/cache/src/PhpFileCache.php` - Legacy cache restored
- ✅ All adapters created in `app/system/modules/cache/src/Adapter/`

### Reverted Changes (kept on doctrine/cache)
- ✅ `app/modules/database/src/ORM/MetadataManager.php` - Using doctrine/cache
- ✅ `app/system/modules/user/src/Event/LoginAttemptListener.php` - Using doctrine/cache
- ✅ `packages/pagekit/blog/src/UrlResolver.php` - Using doctrine/cache
- ✅ `packages/pagekit/blog/src/Event/RouteListener.php` - Using doctrine/cache