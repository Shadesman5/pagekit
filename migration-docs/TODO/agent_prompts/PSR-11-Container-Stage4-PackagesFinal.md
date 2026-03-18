# PSR-11 Container Vollmodernisierung – Sub-Step 2.0.1d: Packages + ArrayAccess Removal

**ROADMAP:** 2.0.1d. Reference: `@ROADMAP.md`.

---

## CONTEXT

**Previous Work Completed:**
- ✅ 2.0.1a: Container implements ContainerInterface natively, app/modules/ migrated
- ✅ 2.0.1b: ControllerResolver supports constructor DI
- ✅ 2.0.1c: app/system/, app/installer/, app/console/ migrated (ArrayAccess + DI)
- ⏳ ArrayAccess still present (only for service registration)
- ⏳ packages/ (extensions/themes) still use legacy patterns

**This Sub-Step:** Migrate packages/, add `set()` for service registration, remove ArrayAccess completely, create extension migration guide.

**Next Sub-Step:** 2.0.1e = StaticTrait removal, model repositories, EventTrait/RouterTrait refactoring.

**Result:** Native PSR-11 Container. No ArrayAccess. Clean registration via `set()`.

**Lessons Learned from 2.0.1c** (see `migration-docs/branches/PSR11_CONTAINER_STAGE3.md`, section "Post-Review Fixes"):
1. **Factory services cannot use constructor injection.** If a service is registered with `$app->factory()`, each `get()` must return a fresh instance. Use direct instantiation or `$app->get()` in the method instead.
2. **Module `$app` properties must be nullable.** Module classes receive `$app` in `main()`, but methods may be called before `main()` runs. Always use `protected ?App $app = null` with `App::getInstance()` fallback.
3. **Every `App::getInstance()` needs a TEMPORARY BRIDGE tag** per ROADMAP Rule 5. Bugbot (`.cursor/BUGBOT.md`) enforces this.
4. **`ConfigManager::get()` only accepts one parameter.** Do not pass a default as second arg — it is silently ignored. Use explicit fallback instead.

---

## 0. SAFETY CHECKS (CRITICAL)

**Design principle:** ArrayAccess is removed ONLY after all call sites are migrated. Order: 1) Migrate packages reads, 2) Add set(), 3) Migrate registration, 4) Remove ArrayAccess. System stays functional throughout.

**Test environment:** From workspace root. Console: `php pagekit`. PHPUnit: `./app/vendor/bin/phpunit`. For curl/Playwright: start app first.

**AFTER EVERY LOGICAL CHANGE:**
```bash
php pagekit setup
php pagekit list
# If HTTP/E2E checks required: start app, then:
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/admin
./app/vendor/bin/phpunit
npx playwright test tests/e2e/specs/01-setup/installation.spec.js
```
**IF ANY FAILS → STOP AND FIX!**

**Before starting:** 2.0.1c merged into `develop`. Branch from `develop`.

---

## 1. PREPARATION

1.1. Create branch from `develop` (agent chooses branch name)

1.2. Create `migration-docs/branches/PSR11_CONTAINER_STAGE4.md`

1.3. **Discovery:**
```bash
# Package reads
rg '\$app\[['\''"]\w+' packages/ --type php -n
rg 'App::(db|cache|config|module|request|router|events|kernel|url|view|mailer|auth|user|translator|csrf|session|cookie|filter|log|info|finder|theme|widget|menu|node|locator)' packages/ --type php -n
rg 'App::(abort|redirect|forward|error)\(' packages/ --type php -c
rg 'App::getInstance' packages/ --type php -n

# Service registration (ALL of app/ + packages/)
rg "\$app\[['\''\"][^'\''\"]+['\''\"]\]\s*=" app/ packages/ --type php -n
```

---

## 2. MIGRATE PACKAGES CALL SITES

### 2.1. ArrayAccess → get()

Same rules as 2.0.1c:
- `$app['x']` → `$app->get('x')`
- `isset($app['x'])` → `$app->has('x')`

### 2.2. Controllers in packages → Constructor Injection

Same pattern as 2.0.1c — controllers use constructor injection (2.0.1b infrastructure).

### ⚠️ CRITICAL: App::get() DOES NOT WORK as static call!

**NEVER use `App::get('x')` as a replacement for `App::db()`!** See 2.0.1c for explanation.

### 2.3. Listeners in packages → Constructor Injection via index.php

Same pattern as 2.0.1c.

### 2.4. Models in packages → Temporary workaround

Same as 2.0.1c: `App::module('blog')` → `App::getInstance()->get('module')->get('blog')`
Mark: `// TODO: Replace with Repository pattern in Step 2.0.1e`

### 2.5. DO NOT CHANGE (Deferred to 2.0.1e)

Same as 2.0.1c: `App::abort()`, `App::redirect()`, `App::on()`, etc. stay.

---

## 3. ADD set() FOR SERVICE REGISTRATION

**File:** `app/modules/application/src/Container.php`

- Add `set(string $id, mixed $value): void` — stores value/factory for later resolution
- Keep the same internal logic as current `offsetSet()` (checks for existing raw, stores in $this->values)
- The `factory()` and `extend()` methods should continue to work

---

## 4. MIGRATE SERVICE REGISTRATION (ALL of app/ + packages/)

**Scope:** Every `$app['service'] = function($app) {...}` must become `$app->set('service', function($app) {...})`

**Files:** All module `index.php` in app/modules/, app/system/, app/installer/, app/console/, packages/

**Order:** Core first, then system, installer, console, packages.

**Also migrate:** `$this['x'] = ...` patterns in Container.php and Application.php constructor.

---

## 5. REMOVE ArrayAccess FROM CONTAINER

After all registrations use `set()`:
- Remove `implements \ArrayAccess` from Container
- Delete `offsetGet`, `offsetSet`, `offsetExists`, `offsetUnset`
- Remove `#[\ReturnTypeWillChange]` attribute (was on offsetGet)

Verify: no remaining `$app['x']` or `isset($app['x'])` anywhere:
```bash
rg '\$app\[' app/ packages/ --type php
rg 'isset\(\$app\[' app/ packages/ --type php
rg '\$this\[' app/modules/application/src/ --type php
```
Should return no results.

---

## 6. UPDATE __call MAGIC METHOD

**File:** `app/modules/application/src/Container.php`

The `__call()` method currently provides dynamic service access on instances (`$app->db()` etc.). This stays for now — it will be removed in 2.0.1e with StaticTrait. Mark:
```php
// TODO: Remove __call() magic in Step 2.0.1e (StaticTrait Removal)
```

---

## 7. EXTENSION MIGRATION GUIDE

Create `migration-docs/PSR11_CONTAINER_EXTENSION_MIGRATION.md`:

```markdown
# PSR-11 Container Migration Guide for Extensions

## Breaking Changes

Legacy Pagekit extensions must be updated to work with the modernized Container.

### Service Access

| Old (Legacy) | New (PSR-11) |
|--------------|--------------|
| $app['db'] | $app->get('db') |
| $app['cache'] | $app->get('cache') |
| isset($app['x']) | $app->has('x') |

### Service Registration (module index.php)

| Old (Legacy) | New (PSR-11) |
|--------------|--------------|
| $app['myservice'] = function($app) {...} | $app->set('myservice', function($app) {...}) |

### Controller Dependency Injection

Controllers now support constructor injection. Parameter names must match container service IDs:

| Old (Legacy) | New (DI) |
|--------------|----------|
| App::db() in controller method | Constructor param: private readonly mixed $db |
| App::module('x') | Constructor param: private readonly mixed $module, then $this->module->get('x') |

### Exceptions

- Missing service: `Psr\Container\NotFoundExceptionInterface` (was InvalidArgumentException)
- Resolution error: `Psr\Container\ContainerExceptionInterface`
```

---

## 8. TESTS

- Update ContainerTest: remove ArrayAccess tests, add `set()` tests
- Verify all existing tests pass (controller DI tests from 2.0.1b should still pass)
- Run E2E installation test

---

## 9. VALIDATION

- [ ] No ArrayAccess in Container class
- [ ] No `$app['x']` anywhere in codebase
- [ ] All service registration uses `$app->set()`
- [ ] All service access uses `$app->get()` or constructor injection
- [ ] Extension migration guide created
- [ ] `__call()` on Container has TODO marker for 2.0.1e
- [ ] All tests pass
- [ ] Fresh install works
- [ ] Console works

---

## 10. DOCUMENTATION

Update `PSR11_CONTAINER_STAGE4.md`:
- Complete migration summary for packages
- ArrayAccess removal details
- `set()` API documentation
- Link to extension migration guide
- Notes for 2.0.1e (StaticTrait removal)

---

## SUCCESS CRITERIA

- Container has `get()`, `has()`, `set()` — no ArrayAccess
- No `$app['x']` anywhere in codebase
- All packages migrated (controllers with DI, listeners with injection)
- Extension migration guide published
- All tests pass
- PR ready with full evidence
