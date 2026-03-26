# PSR-11 Container Vollmodernisierung – Sub-Step 2.0.1b: DI Infrastructure

**ROADMAP:** 2.0.1b. Reference: `@ROADMAP.md`.

---

## CONTEXT

**Previous Work (2.0.1a) Completed:**
- ✅ Container implements `ContainerInterface` natively with `get()` / `has()`
- ✅ Psr11Adapter removed
- ✅ All `app/modules/` call sites migrated from `$app['x']` to `$app->get('x')`
- ✅ ArrayAccess still present (delegates to get/has)

**This Sub-Step:** Add infrastructure for constructor-based dependency injection in controllers, listeners, and console commands. No application code changes — only the DI wiring layer.

**Why before 2.0.1c?** Sub-step 2.0.1c will migrate ~200+ `App::db()`, `App::module()`, etc. calls in controllers to constructor injection. The ControllerResolver must support this FIRST.

**Next Sub-Step:** 2.0.1c migrates call sites using the DI infrastructure built here.

---

## 0. SAFETY CHECKS (CRITICAL)

**Design principle:** Infrastructure changes only. No behavioral changes. All existing controllers continue to work (they have no constructor parameters). After this step, controllers CAN use constructor injection but are not required to.

**Test environment:** From workspace root. Console: `php pagekit` (e.g. `php pagekit setup`, `php pagekit list`). PHPUnit: `./app/vendor/bin/phpunit`. For curl/Playwright: start app first.

**AFTER EVERY LOGICAL CHANGE:**
```bash
php pagekit setup
./app/vendor/bin/phpunit
```
**IF ANY FAILS → STOP AND FIX!**

**Before starting:** 2.0.1a merged into `develop`. Branch from `develop`.

---

## 1. PREPARATION

1.1. Create branch from `develop` (agent chooses branch name)

1.2. Create `migration-docs/branches/PSR11_CONTAINER_DI_INFRASTRUCTURE.md`

1.3. **Analyze current instantiation patterns:**
```bash
# Controller instantiation
rg 'instantiateController' app/modules/kernel/ --type php -n

# How ControllerResolver gets registered
rg 'resolver' app/modules/kernel/index.php --type php -n

# Listener registration patterns
rg '\$app->subscribe\(' app/ packages/ --type php -n

# Console command instantiation
rg 'new \$class' app/console/ --type php -n
```

---

## 2. CONTROLLER DI: UPDATE ControllerResolver

**File:** `app/modules/kernel/src/Controller/ControllerResolver.php`

### 2.1. Add Container to ControllerResolver

The resolver needs access to the container to resolve constructor dependencies.

**Change:** Add container as constructor parameter (alongside existing logger).

### 2.2. Update `instantiateController()` for Constructor Injection

**Current behavior (line 135-138):**
```php
protected function instantiateController($class): object
{
    return new $class();
}
```

**Required behavior:**
1. Use reflection to inspect the controller constructor
2. If no constructor or no parameters → `new $class()` (backward compatible)
3. For each constructor parameter:
   a. Check if parameter **name** exists as container service ID → resolve via `$container->get($paramName)` (Pagekit services use string IDs like 'db', 'cache', 'module')
   b. If parameter has a default value → use default
   c. Otherwise → throw clear error
4. Instantiate with resolved parameters

**Why parameter-name resolution?** Pagekit's container uses string service IDs (`'db'`, `'cache'`, `'module'`), not class-name keys. Constructor parameters MUST match service IDs:

```php
// This works because parameter $db matches service ID 'db':
class SomeController {
    public function __construct(private readonly mixed $db) {}
}
// Resolver calls: $container->get('db')
```

### 2.3. Update ControllerResolver Registration

**File:** `app/modules/kernel/index.php`

Change the resolver registration to pass the container:
```php
// Before:
$app['resolver'] = fn() => new ControllerResolver();

// After: Pass container so resolver can inject dependencies
$app['resolver'] = fn($app) => new ControllerResolver($app);
```

---

## 3. CONSOLE COMMAND DI (OPTIONAL IMPROVEMENT)

**File:** `app/console/index.php` and `app/modules/application/src/Application/Console/Application.php`

Console commands already use `setContainer()` setter injection. For consistency:
- Update command instantiation to support constructor injection (same pattern as controllers)
- Keep `setContainer()` as fallback for backward compatibility

**Note:** Only ~3 `App::` calls exist in commands — this is low priority. If complexity is too high, mark with `// TODO: Improve in Step 2.0.1e` and skip.

---

## 4. TESTS

### 4.1. ControllerResolver Tests

Create or update `app/modules/kernel/src/Tests/ControllerResolverTest.php`:

- Test: Controller with NO constructor → instantiated normally (backward compat)
- Test: Controller with constructor params matching container services → resolved correctly
- Test: Controller with default values → defaults used when service not in container
- Test: Controller with unknown required param → clear error thrown
- Test: Mixed params (some from container, some with defaults) → resolved correctly

### 4.2. Integration Test

- Test: Register a service, create a controller class with matching constructor param, resolve controller → service injected correctly

---

## 5. VALIDATION

- [ ] Existing controllers (no constructor params) still work
- [ ] `php pagekit setup` succeeds
- [ ] All PHPUnit tests pass
- [ ] New ControllerResolver tests pass
- [ ] No behavioral changes for existing code
- [ ] ControllerResolver has container access

---

## 6. DOCUMENTATION

Update `PSR11_CONTAINER_DI_INFRASTRUCTURE.md`:
- ControllerResolver changes
- How controller DI works (parameter name matching)
- Example: before/after for a controller

---

## SUCCESS CRITERIA

- ControllerResolver can inject constructor dependencies from the container
- Existing parameterless controllers work without changes
- Parameter resolution uses service ID matching (parameter name = service ID)
- Clear error messages for unresolvable parameters
- All tests pass, no regressions
