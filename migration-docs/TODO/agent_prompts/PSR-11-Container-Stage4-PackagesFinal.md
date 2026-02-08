# PSR-11 Container Vollmodernisierung – Stage 4: Packages & Final Cleanup

**ROADMAP:** 2.0.5 (Stage 4). Reference: `@ROADMAP.md`.

---

## CONTEXT

**Previous Work (Stage 1 + 2 + 3) Completed:**
- ✅ Container implements ContainerInterface natively
- ✅ All app/ call sites migrated to `$app->get()` / `App::get()`
- ✅ app/modules/, app/system/, app/installer/, app/console/ use get() only
- ⏳ ArrayAccess still present (only for service registration)
- ⏳ packages/ (extensions/themes) still use `$app['x']`

**This Stage:** Migrate packages/, remove ArrayAccess completely, add `set()` for registration, create extension migration guide.

**Result:** Native PSR-11 Container. No ArrayAccess. No legacy patterns.

---

## 0. SAFETY CHECKS (CRITICAL)

**Design principle:** ArrayAccess is removed ONLY after all call sites are migrated. Order: 1) Add set(), 2) Migrate registration, 3) Migrate remaining reads, 4) Remove ArrayAccess. System stays functional throughout.

**Test environment:** From workspace root. Console: `php pagekit`. PHPUnit: `./app/vendor/bin/phpunit`. For curl/Playwright: start app first (`php pagekit start -s localhost:8080 --no-ansi &`).

**AFTER EVERY LOGICAL CHANGE:**
```bash
php pagekit setup
php pagekit list
# If HTTP/E2E checks required: start app (php pagekit start -s localhost:8080 --no-ansi &), then:
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/admin
./app/vendor/bin/phpunit
npx playwright test tests/e2e/specs/01-setup/installation.spec.js
```
**IF ANY FAILS → STOP AND FIX!**

**Before starting:** Stage 1 + 2 + 3 merged into `develop`. Branch from `develop`.

---

## 1. PREPARATION

1.1. Create branch from `develop` (agent chooses branch name)

1.2. Create `migration-docs/branches/PSR11_CONTAINER_STAGE4.md`

1.3. **Discovery:**
```bash
# packages/
rg '\$app\[['\''"]\w+' packages/ --type php -n
rg 'App::' packages/ --type php -n

# Service registration (all of app/)
rg "\$app\[['\''\"][^'\''\"]+['\''\"]\]\s*=" app/ packages/ --type php -n
```

---

## 2. ADD set() FOR SERVICE REGISTRATION

**File:** `app/modules/application/src/Container.php`

- Add `set(string $id, mixed $value): void` – stores value/factory for later resolution
- Keep internal `$this->values[$id] = $value` logic (from current offsetSet)
- Remove `implements \ArrayAccess`
- Remove `offsetGet`, `offsetSet`, `offsetExists`, `offsetUnset`
- All registration must use `$app->set('x', fn)` instead of `$app['x'] = fn`

---

## 3. MIGRATE SERVICE REGISTRATION (ALL OF app/ + packages/)

**Scope:** Every `$app['service'] = function($app) {...}` must become `$app->set('service', function($app) {...})`

**Files:** All module `index.php` in app/modules/, app/system/, app/installer/, app/console/, packages/

**Order:** Core first, then system, installer, console, packages.

---

## 4. MIGRATE PACKAGES CALL SITES

**Scope:** packages/pagekit/blog/, packages/pagekit/theme-one/, any other packages

- `$app['x']` → `$app->get('x')`
- `App::x()` → `App::get('x')`
- `App::module('name')` → `App::get('module')->get('name')`

---

## 5. REMOVE ArrayAccess FROM CONTAINER

After all registrations use `set()`:
- Remove `implements \ArrayAccess` from Container
- Delete offsetGet, offsetSet, offsetExists, offsetUnset
- Verify: no remaining `$app['x']` or `isset($app['x'])` in codebase

```bash
rg '\$app\[' app/ packages/ --type php
rg 'isset\(\$app\[' app/ packages/ --type php
```
Should return no results.

---

## 6. UPDATE StaticTrait

**File:** `app/modules/application/src/Application/Traits/StaticTrait.php`

- Default case (App::db(), App::cache(), etc.): currently uses `offsetGet($name)`. Change to `$instance->get($name)`.
- Remove any offsetGet/offsetExists references.

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
| App::db() | App::get('db') |
| App::module('name') | App::get('module')->get('name') |

### Service Registration (module index.php)

| Old (Legacy) | New (PSR-11) |
|--------------|--------------|
| $app['myservice'] = function($app) {...} | $app->set('myservice', function($app) {...}) |

### Exceptions

- Missing service: `Psr\Container\NotFoundExceptionInterface` (was InvalidArgumentException)
- Resolution error: `Psr\Container\ContainerExceptionInterface`
```

---

## 8. TESTS

- Update ContainerTest: remove ArrayAccess tests
- Add tests for `set()` if not covered
- Verify all PHPUnit tests pass
- Run E2E installation test

---

## 9. VALIDATION

- [ ] No ArrayAccess in Container
- [ ] No `$app['x']` anywhere in codebase
- [ ] All service registration uses `$app->set()`
- [ ] All service access uses `$app->get()` or `App::get()`
- [ ] Extension migration guide created
- [ ] All tests pass
- [ ] Fresh install works

---

## 10. DOCUMENTATION

Update `PSR11_CONTAINER_STAGE4.md` and create `PSR11_CONTAINER_FULL_MODERNIZATION.md` (final summary):
- Complete migration summary
- All 4 stages summarized
- Breaking changes for extensions
- Before/after examples
- Link to extension migration guide

---

## SUCCESS CRITERIA

- Container is pure PSR-11 (get, has, set for registration)
- No ArrayAccess
- No Psr11Adapter
- All call sites migrated
- Extension migration guide published
- All tests pass
- PR ready with full evidence
