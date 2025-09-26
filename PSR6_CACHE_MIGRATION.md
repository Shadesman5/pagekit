# PSR-6 Cache Migration Documentation

## Current Status: Phase 2 COMPLETE ✅

### Migration Progress
- **Phase 1**: ✅ Dual system running (both doctrine/cache and PSR-6 available)
- **Phase 2**: ✅ PSR-6 adapters active for all main caches
- **Phase 3**: ⏳ Module code migration (keep using doctrine/cache API)
- **Phase 4**: ⏳ Remove doctrine/cache (final step)

### Test Results (All Passing) ✅
```bash
✅ php pagekit list              # CLI works
✅ curl http://localhost:8000    # Web returns 200
✅ curl http://localhost:8000/admin # Admin redirects to login  
✅ php pagekit clearcache        # Cache clearing works
```

## What's Been Done

### Phase 1: Infrastructure ✅
Created PSR-6 adapters that extend `CacheProvider` for backward compatibility:
- `app/system/modules/cache/src/Adapter/Psr6Adapter.php` - Base adapter
- `app/system/modules/cache/src/Adapter/ArrayAdapter.php`
- `app/system/modules/cache/src/Adapter/ApcuAdapter.php`
- `app/system/modules/cache/src/Adapter/FilesystemAdapter.php`
- `app/system/modules/cache/src/Adapter/PhpFilesAdapter.php`
- `app/system/modules/cache/src/Adapter/NullAdapter.php`

### Phase 2: PSR-6 Activation ✅
All main caches now use PSR-6 adapters internally:
- `cache.phpfile` - Used by MetadataManager
- `cache` - Main system cache
- `cache.module` - Module metadata cache

The adapters provide full doctrine/cache API compatibility while using Symfony Cache internally.

### Key Fixes Applied
1. **Namespace handling**: PSR-6 doesn't allow `:` in cache keys, changed to `.` separator
2. **Invalid characters**: Replace `{}()/\@:` with `_` for PSR-6 compliance
3. **Method signatures**: Fixed to match doctrine/cache exactly
4. **Dual API support**: Adapters extend CacheProvider for backward compatibility

## Current Architecture

```
Application Code (using doctrine/cache API)
    ↓
CacheModule::shouldUsePsr6() decides implementation
    ↓
PSR-6 Adapters (extending CacheProvider)
    ↓
Symfony Cache Components (PSR-6)
```

## Files Using Cache (All Still Using doctrine/cache API)

### Core Modules
- `app/modules/database/src/ORM/MetadataManager.php` - Uses `cache.phpfile`
  - `fetch()`, `save()` - Working with PSR-6 adapter ✅

### System Modules  
- `app/system/modules/user/src/Event/LoginAttemptListener.php` - Uses main `cache`
  - `fetch()`, `save()`, `delete()` - Working with PSR-6 adapter ✅
  
### Package Modules
- `packages/pagekit/blog/src/UrlResolver.php` - Uses main `cache`
  - `fetch()`, `save()` - Working with PSR-6 adapter ✅
- `packages/pagekit/blog/src/Event/RouteListener.php` - Uses main `cache`
  - `delete()` - Working with PSR-6 adapter ✅

## Phase 3: Module Migration Plan ⏳

### Current State
All modules still use doctrine/cache API (`fetch`, `save`, `delete`) but the underlying implementation is PSR-6.

### Migration Strategy
1. Keep backward compatibility layer active
2. Migrate modules one by one to PSR-6 API
3. Test thoroughly after each migration
4. Only remove doctrine/cache after ALL modules migrated

### Method Mapping for Future Migration
```php
// OLD (doctrine/cache)
$data = $cache->fetch($key);
$cache->save($key, $data, $ttl);
$cache->delete($key);
$exists = $cache->contains($key);

// NEW (PSR-6)
$item = $cache->getItem($key);
$data = $item->isHit() ? $item->get() : false;

$item = $cache->getItem($key);
$item->set($data);
$item->expiresAfter($ttl);
$cache->save($item);

$cache->deleteItem($key);
$cache->hasItem($key);
```

## Phase 4: Cleanup Plan ⏳

Only after Phase 3 is complete:
1. Remove `doctrine/cache` from composer.json
2. Remove legacy cache classes
3. Remove backward compatibility from adapters
4. Update documentation

## Testing Checklist

After EVERY change, run ALL tests:
```bash
php pagekit list                    # Must work
curl -I http://localhost:8000       # Must return 200
curl -I http://localhost:8000/admin # Must return 302/200
php pagekit clearcache              # Must work
```

## Important Notes

### Why This Approach Works
1. **No Breaking Changes**: All code continues using doctrine/cache API
2. **Gradual Migration**: Can test each component separately
3. **Easy Rollback**: Just flip `shouldUsePsr6()` back to false
4. **Extension Compatible**: Extensions continue working unchanged

### Current Stability
- System is fully functional
- All caches using PSR-6 internally
- Full backward compatibility maintained
- Ready for Phase 3 (code migration)