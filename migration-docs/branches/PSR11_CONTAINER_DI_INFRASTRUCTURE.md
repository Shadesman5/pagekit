# PSR-11 Container DI Infrastructure (Step 2.0.1b)

**Branch:** `cursor/psr-11-container-infrastructure-b43c`
**ROADMAP Step:** 2.0.1b
**Issue:** #163

## Summary

Add infrastructure for constructor-based dependency injection in controllers.
No application code changes -- only the DI wiring layer.

## Previous Work (2.0.1a - PR #161)

- Container implements `ContainerInterface` natively with `get()` / `has()`
- Psr11Adapter removed
- All `app/modules/` call sites migrated from `$app['x']` to `$app->get('x')`
- ArrayAccess still present (delegates to get/has)

## Changes

### ControllerResolver DI Support

**File:** `app/modules/kernel/src/Controller/ControllerResolver.php`

- Added `ContainerInterface` as constructor parameter
- Updated `instantiateController()` to use reflection for constructor injection
- Parameter-name resolution: parameter `$db` resolves to container service `'db'`
- Backward compatible: controllers with no constructor still work via `new $class()`

### Registration Update

**File:** `app/modules/kernel/index.php`

- Resolver registration passes container: `fn($app) => new ControllerResolver($app)`

### Tests

**File:** `app/modules/kernel/src/Tests/ControllerResolverTest.php`

- No-constructor controller (backward compat)
- Constructor params matching container services
- Default values used when service not in container
- Unknown required param throws clear error
- Mixed params (container + defaults)
- Integration: full resolve cycle

## Deferred

- Console Command constructor DI: deferred to Step 2.0.1e (commands use setter injection via `setContainer()`)
- Listener DI: not needed (listeners are instantiated inline, not via resolver)

## How to Test

```bash
./app/vendor/bin/phpunit --filter ControllerResolverTest
php pagekit setup --db-driver=sqlite
```
