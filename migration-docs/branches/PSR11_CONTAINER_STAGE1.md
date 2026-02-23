# PSR-11 Container Stage 1: Native ContainerInterface

**ROADMAP Step:** 2.0.1 (Stage 1)
**Branch:** `cursor/psr-11-container-core-71d2`
**Status:** Complete

---

## Objective

Refactor `Pagekit\Container` to implement `Psr\Container\ContainerInterface` natively, eliminating the `Psr11Adapter` wrapper. ArrayAccess is kept but delegates to `get()`/`has()` so existing `$app['x']` call sites continue working without changes.

## Summary of Changes

### Before

- Container used `ArrayAccess` as primary API (`$app['x']`)
- `getService()` / `hasService()` were PSR-11-like methods (avoiding name conflict with static `App::get()`)
- `Psr11Adapter` wrapped Container for PSR-11 compliance
- `App::get('x')` routed through `__callStatic`

### After

- Container implements `Psr\Container\ContainerInterface` directly
- `get(string $id)` is the PSR-11 entry point (resolution logic lives here)
- `has(string $id)` is the PSR-11 existence check
- `offsetGet()` / `offsetExists()` delegate to `get()` / `has()` (backward compat)
- `Psr11Adapter` deleted
- `getService()` / `hasService()` / `getPsr11Adapter()` removed
- `App::get('x')` changed to `App::getInstance()->get('x')` at 8 call sites (PHP limitation: `__callStatic` is not triggered when method exists as instance method)
- StaticTrait cleaned up (dead `get`/`has` cases removed from `__callStatic`)

## Files Changed

| File | Change |
|------|--------|
| `app/modules/application/src/Container.php` | Implements ContainerInterface, get()/has() as PSR-11 methods, ArrayAccess delegates |
| `app/modules/application/src/Container/Psr11Adapter.php` | Deleted |
| `app/modules/application/src/Application/Traits/StaticTrait.php` | Removed dead cases, updated docblock |
| `app/modules/application/src/Tests/ContainerPsr11Test.php` | Updated for native PSR-11 |
| `app/system/modules/cache/src/CacheModule.php` | `App::get()` -> `App::getInstance()->get()` |
| `app/system/modules/info/src/InfoHelper.php` | `App::get()` -> `App::getInstance()->get()` |
| `app/system/modules/settings/src/Controller/SettingsController.php` | `App::get()` -> `App::getInstance()->get()` |
| `app/system/modules/dashboard/src/Controller/DashboardController.php` | `App::get()` -> `App::getInstance()->get()` |

## Validation Results

- [x] `php pagekit setup` succeeds
- [x] All 261 PHPUnit tests pass (0 failures)
- [x] No references to Psr11Adapter, getService, hasService
- [x] Container implements ContainerInterface
- [x] ArrayAccess delegates to get/has
- [x] NotFoundException implements NotFoundExceptionInterface
- [x] ContainerException implements ContainerExceptionInterface

## Architecture Note: __callStatic / PSR-11 Collision

During this stage, a fundamental limitation was discovered: PHP does not trigger `__callStatic`
when a method with the same name exists as an instance method. Since `Container::get()` is now
a PSR-11 instance method, `App::get('db')` causes a fatal error instead of routing through
`__callStatic`. This affected 8 call sites (changed to `App::getInstance()->get('x')`).

**Resolution plan:** This is properly addressed in the expanded sub-step structure:
- 2.0.1b: DI Infrastructure (ControllerResolver supports constructor injection)
- 2.0.1c: Controllers/listeners migrate to constructor DI (no more `App::x()` service shortcuts)
- 2.0.1e: StaticTrait, EventTrait, RouterTrait deleted entirely

See `migration-docs/TODO/agent_prompts/PSR-11-Container-Vollmodernisierung.md` for the full plan.

## Next Stage

Stage 2 will migrate `app/modules/` call sites from `$app['x']` to `$app->get('x')`.
