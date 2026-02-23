# PSR-11 Container DI Infrastructure (Step 2.0.1b)

**Branch:** `cursor/psr-11-container-infrastructure-b43c`
**ROADMAP Step:** 2.0.1b
**Issue:** #163
**Status:** Complete

## Summary

Add infrastructure for constructor-based dependency injection in controllers.
No application code changes -- only the DI wiring layer. After this step,
controllers CAN use constructor injection but are not required to.

## Previous Work (2.0.1a - PR #161)

- Container implements `ContainerInterface` natively with `get()` / `has()`
- Psr11Adapter removed
- All `app/modules/` call sites migrated from `$app['x']` to `$app->get('x')`
- ArrayAccess still present (delegates to get/has)

## Changes

### ControllerResolver DI Support

**File:** `app/modules/kernel/src/Controller/ControllerResolver.php`

- Added `Psr\Container\ContainerInterface` as optional first constructor parameter
- Updated `instantiateController(string $class): object` with reflection-based DI:
  1. If no container is set -> fallback to `new $class()` (safety net)
  2. If no constructor or no parameters -> `new $class()` (backward compatible)
  3. For each constructor parameter:
     - Check `$container->has($paramName)` -> resolve via `$container->get($paramName)`
     - If parameter has default value -> use default
     - Otherwise -> throw `RuntimeException` with clear message
  4. Instantiate with resolved parameters via `$reflectionClass->newInstanceArgs($args)`
- Parameter-name resolution: parameter `$db` resolves to container service `'db'`

**Example (future controller with DI):**
```php
class SomeController {
    public function __construct(
        private readonly mixed $db,
        private readonly mixed $cache
    ) {}
}
// Resolver calls: $container->get('db'), $container->get('cache')
```

**Example (existing controller without DI - unchanged):**
```php
class ExistingController {
    // No constructor params -> new ExistingController() as before
}
```

### Registration Update

**File:** `app/modules/kernel/index.php`

- Resolver registration passes container: `fn($app) => new ControllerResolver($app)`
- ArrayAccess registration flagged with TODO for Step 2.0.1d

### Console Command DI Deferral

**Files:** `app/modules/application/src/Application/Console/Application.php`, `app/console/index.php`

- Console commands use setter injection via `setContainer()` -- this pattern requires
  StaticTrait removal before conversion to constructor DI
- TODO comments added referencing Step 2.0.1e (StaticTrait Removal + DI Final)

### Tests

**File:** `app/modules/kernel/src/Tests/ControllerResolverTest.php`

6 tests, 20 assertions:
1. `testControllerWithNoConstructor` - backward compat, plain `new $class()`
2. `testControllerWithContainerServices` - params `$db`, `$cache` resolved from container
3. `testControllerWithDefaultValues` - param has default, service not in container -> default used
4. `testControllerWithUnknownRequiredParam` - throws `RuntimeException` with descriptive message
5. `testControllerWithMixedParams` - some from container, some defaults
6. `testIntegrationResolveControllerFromRequest` - full `getController()` cycle with DI

## Validation Results

- [x] Existing controllers (no constructor params) still work
- [x] `php pagekit setup` succeeds
- [x] All PHPUnit tests pass (267 tests, 646 assertions)
- [x] New ControllerResolver tests pass (6 tests, 20 assertions)
- [x] No behavioral changes for existing code
- [x] ControllerResolver has container access

## Deferred

| Item | Deferred To | Reason |
|------|-------------|--------|
| Console Command constructor DI | Step 2.0.1e | Commands use setter injection via `setContainer()`. Requires StaticTrait removal first. |
| Listener DI | Not needed | Listeners are instantiated inline with manual arguments, not via resolver. |
| ArrayAccess registration removal | Step 2.0.1d | `$app['resolver'] = ...` uses ArrayAccess. Dedicated removal step. |
| Controller code migration to DI | Step 2.0.1c | ~200+ `App::db()`, `App::module()` calls in controllers. Infrastructure built here, migration next. |

## How to Test

```bash
# Run ControllerResolver tests specifically
./app/vendor/bin/phpunit --filter ControllerResolverTest

# Run all tests (regression check)
./app/vendor/bin/phpunit

# Integration check
php pagekit setup --db-driver=sqlite
```
