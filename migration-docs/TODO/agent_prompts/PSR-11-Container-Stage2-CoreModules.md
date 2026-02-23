# PSR-11 Container Vollmodernisierung – Sub-Step 2.0.1a (Part 2): Core Modules Call Sites

**ROADMAP:** 2.0.1a (Part 2 of 2). Reference: `@ROADMAP.md`.
**Status:** ✅ COMPLETED — See `migration-docs/branches/PSR11_CONTAINER_STAGE2.md`

---

## CONTEXT

**Previous Work (Stage 1) Completed:**
- ✅ Container implements `ContainerInterface` directly
- ✅ `get()` and `has()` are PSR-11 methods
- ✅ Psr11Adapter removed
- ✅ ArrayAccess delegates to get/has (still present)
- ✅ StaticTrait updated

**This Stage:** Migrate all call sites in `app/modules/` from `$app['x']` and `App::x()` to `$app->get('x')` and `App::get('x')`.

**Next Stages:** Stage 3 = System modules, installer, console. Stage 4 = Packages, remove ArrayAccess.

---

## 0. SAFETY CHECKS (CRITICAL)

**Design principle:** System remains functional after each migration batch. ArrayAccess still works (delegates to get()). Both `$app['x']` and `$app->get('x')` resolve identically.

**Test environment:** From workspace root. Console: `php pagekit`. PHPUnit: `./app/vendor/bin/phpunit`. For curl: start app first (`php pagekit start -s localhost:8080 --no-ansi &`).

**AFTER EVERY LOGICAL CHANGE:**
```bash
php pagekit setup
# If HTTP checks required: start app (php pagekit start -s localhost:8080 --no-ansi &), then:
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/admin
./app/vendor/bin/phpunit
```
**IF ANY FAILS → STOP AND FIX!**

**Before starting:** Stage 1 merged into `develop`. Branch from `develop`.

---

## 1. PREPARATION

1.1. Create branch from `develop` (agent chooses branch name)

1.2. Create `migration-docs/branches/PSR11_CONTAINER_STAGE2.md`

1.3. **Discovery – Find all call sites in app/modules/:**
```bash
# ArrayAccess: $app['something']
rg '\$app\[['\''"]\w+' app/modules/ --type php -n

# Static service calls: App::db(), App::cache(), App::module(), etc.
rg 'App::(db|cache|config|module|request|router|events|kernel|url|view|mailer|auth|user|translator|csrf|session|cookie|filter|log|info|finder|theme|widget|menu|node|locator)' app/modules/ --type php -n

# App::get() and App::has() – already correct, no change
rg 'App::(get|has)\(' app/modules/ --type php -l
```

Document: list of files and line counts. Create checklist.

---

## 2. MIGRATION RULES

### 2.1. ArrayAccess → get()

| Before | After |
|--------|-------|
| `$app['db']` | `$app->get('db')` |
| `$app['cache']` | `$app->get('cache')` |
| `$app['config.file']` | `$app->get('config.file')` |
| `isset($app['db'])` | `$app->has('db')` |

### 2.2. Static service shortcuts → App::get()

| Before | After |
|--------|-------|
| `App::db()` | `App::get('db')` |
| `App::cache()` | `App::get('cache')` |
| `App::module('name')` | `App::get('module')->get('name')` |
| `App::config()` | `App::get('config')` |
| `App::request()` | `App::get('request')` |
| etc. | `App::get('service_id')` |

**Note:** `App::get('x')` and `App::has('x')` stay as-is.

### 2.3. Service registration (module index.php)

| Before | After |
|--------|-------|
| `$app['service'] = function($app) {...}` | Keep for now – Stage 4 will change to `$app->set()` if we add that API |

**Stage 2 scope:** Only READ access. Registration stays as `$app['x'] = fn` (ArrayAccess offsetSet). No change in Stage 2.

---

## 3. MIGRATION ORDER

Migrate by module dependency order. Suggested order:
1. `app/modules/application/` (core)
2. `app/modules/config/`
3. `app/modules/auth/`
4. `app/modules/cookie/`
5. `app/modules/database/`
6. `app/modules/feed/`
7. `app/modules/filesystem/`
8. `app/modules/filter/`
9. `app/modules/kernel/`
10. `app/modules/log/`
11. `app/modules/markdown/`
12. `app/modules/migration/`
13. `app/modules/routing/`
14. `app/modules/session/`
15. `app/modules/view/`
16. `app/modules/debug/`

After each module: run safety checks.

---

## 4. EDGE CASES

- **Callable services with args:** `$app['module']('name')` → `$app->get('module')->get('name')` (module manager)
- **Chained access:** `$app['db']->...` → `$app->get('db')->...`
- **In closures:** `function() use ($app) { $app['db']; }` → `$app->get('db')`

---

## 5. VALIDATION

- [ ] No `$app['x']` in `app/modules/` (except registration in index.php)
- [ ] No `App::db()`, `App::cache()`, etc. in `app/modules/` (use `App::get('db')`)
- [ ] All tests pass
- [ ] Web + admin work

---

## 6. DOCUMENTATION

Update `PSR11_CONTAINER_STAGE2.md`:
- List of changed files
- Before/after examples
- Notes for Stage 3

---

## SUCCESS CRITERIA

- All `app/modules/` call sites use `$app->get()` or `App::get()`
- Service registration (index.php) unchanged for now
- All tests pass
- No regressions
