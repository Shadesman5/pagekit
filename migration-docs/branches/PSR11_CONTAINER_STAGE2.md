# PSR-11 Container Stage 2: Core Modules Call Sites

**ROADMAP Step:** 2.0.1 (Stage 2)
**Branch:** `cursor/psr-11-container-core-71d2`
**Status:** Complete

---

## Objective

Migrate all READ access call sites in `app/modules/` from ArrayAccess (`$app['x']`) to PSR-11 (`$app->get('x')`). Service registration (WRITE via `$app['x'] = ...`) stays as ArrayAccess for now (Stage 4).

## Migration Rules

| Pattern | Before | After |
|---------|--------|-------|
| READ | `$app['x']` | `$app->get('x')` |
| EXISTS | `isset($app['x'])` | `$app->has('x')` |
| WRITE | `$app['x'] = ...` | Keep as-is (Stage 4) |

## Files Changed

| File | READs | EXISTS | Total |
|------|-------|--------|-------|
| `app/modules/application/index.php` | 6 | 0 | 6 |
| `app/modules/config/index.php` | 7 | 0 | 7 |
| `app/modules/auth/index.php` | 6 | 0 | 6 |
| `app/modules/cookie/index.php` | 2 | 0 | 2 |
| `app/modules/database/index.php` | 8 | 0 | 8 |
| `app/modules/kernel/index.php` | 4 | 0 | 4 |
| `app/modules/filesystem/index.php` | 4 | 0 | 4 |
| `app/modules/log/index.php` | 2 | 2 | 4 |
| `app/modules/migration/index.php` | 2 | 0 | 2 |
| `app/modules/routing/index.php` | 10 | 0 | 10 |
| `app/modules/session/index.php` | 10 | 1 | 11 |
| `app/modules/view/index.php` | 12 | 4 | 16 |
| `app/modules/view/modules/twig/index.php` | 3 | 2 | 5 |
| `app/modules/debug/index.php` | 22 | 9 | 31 |
| **Total** | **~98** | **~18** | **~116** |

### No changes needed (only WRITEs):
- `app/modules/feed/index.php`
- `app/modules/filter/index.php`
- `app/modules/markdown/index.php`

## Validation Results

- [x] `php pagekit setup` succeeds
- [x] All 261 PHPUnit tests pass (0 failures)
- [x] No `$app['x']` READ accesses in `app/modules/` (only WRITEs remain)
- [x] No `isset($app['x'])` in `app/modules/`
- [x] No static shortcut calls (`App::db()`, etc.) in `app/modules/`

## Next Stage

Stage 3 will migrate `app/system/`, `app/installer/`, `app/console/` call sites.
