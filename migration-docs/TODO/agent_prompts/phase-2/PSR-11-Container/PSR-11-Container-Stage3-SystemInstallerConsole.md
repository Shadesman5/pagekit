# PSR-11 Container Vollmodernisierung – Sub-Step 2.0.1c: System, Installer, Console + DI Migration

**ROADMAP:** 2.0.1c. Reference: `@ROADMAP.md`.

---

## CONTEXT

**Previous Work Completed:**
- ✅ 2.0.1a: Container implements ContainerInterface, app/modules/ call sites migrated
- ✅ 2.0.1b: ControllerResolver supports constructor dependency injection

**This Sub-Step:** Migrate all call sites in `app/system/`, `app/installer/`, `app/console/` from legacy patterns to PSR-11 + constructor injection.

**Next Sub-Step:** 2.0.1d = Packages, remove ArrayAccess, final cleanup.

---

## 0. SAFETY CHECKS (CRITICAL)

**Design principle:** System remains functional. ArrayAccess still works for unmigrated code. Controllers now use constructor injection (from 2.0.1b).

**Test environment:** From workspace root. Console: `php pagekit` (e.g. `php pagekit setup`, `php pagekit list`). PHPUnit: `./app/vendor/bin/phpunit`. For curl/Playwright: start app first (`php pagekit start -s localhost:8080 --no-ansi &`).

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

**Before starting:** 2.0.1b merged into `develop`. Branch from `develop`.

---

## 1. PREPARATION

1.1. Create branch from `develop` (agent chooses branch name)

1.2. Create `migration-docs/branches/PSR11_CONTAINER_STAGE3.md`

1.3. **Discovery:**
```bash
# ArrayAccess reads in app/system/
rg '\$app\[['\''"]\w+' app/system/ --type php -n
rg '\$app\[['\''"]\w+' app/installer/ --type php -n
rg '\$app\[['\''"]\w+' app/console/ --type php -n

# Static service access: App::db(), App::cache(), App::module(), etc.
rg 'App::(db|cache|config|module|request|router|events|kernel|url|view|mailer|auth|user|translator|csrf|session|cookie|filter|log|info|finder|theme|widget|menu|node|locator)' app/system/ --type php -n
rg 'App::(db|cache|config|module|request|router|events|kernel|url|view|mailer|auth|user|translator|csrf|session|cookie|filter|log|info|finder|theme|widget|menu|node|locator)' app/installer/ --type php -n
rg 'App::(db|cache|config|module|request|router|events|kernel|url|view|mailer|auth|user|translator|csrf|session|cookie|filter|log|info|finder|theme|widget|menu|node|locator)' app/console/ --type php -n

# App::getInstance()->get() calls (from Stage 1 workaround)
rg 'App::getInstance\(\)->get' app/system/ app/installer/ app/console/ --type php -n

# App::abort(), App::redirect(), etc. (RouterTrait — DO NOT change in this step)
rg 'App::(abort|redirect|forward|error)\(' app/system/ app/installer/ app/console/ --type php -c

# App::on(), App::subscribe(), App::trigger() (EventTrait — DO NOT change in this step)
rg 'App::(on|subscribe|trigger)\(' app/system/ app/installer/ app/console/ --type php -c
```

Document: file list, occurrence counts. Create checklist.

---

## 2. MIGRATION RULES

### ⚠️ CRITICAL: App::get() DOES NOT WORK as static call!

**PHP limitation:** `Container::get()` is an instance method (PSR-11). PHP does NOT trigger `__callStatic('get', ...)` when a method with that name exists. Therefore `App::get('db')` causes a fatal error.

**NEVER use `App::get('x')` as a replacement for `App::db()`!**

### 2.1. ArrayAccess → get() (module index.php files)

| Before | After |
|--------|-------|
| `$app['db']` | `$app->get('db')` |
| `$app['cache']` | `$app->get('cache')` |
| `$app['config.file']` | `$app->get('config.file')` |
| `isset($app['db'])` | `$app->has('db')` |

### 2.2. Static service access in CONTROLLERS → Constructor Injection

**Controllers have DI support from 2.0.1b.** Replace `App::x()` with constructor-injected dependencies.

| Before | After |
|--------|-------|
| `App::db()` | `private readonly mixed $db` (constructor param, resolves from container service 'db') |
| `App::module('blog')` | `private readonly mixed $module` → `$this->module->get('blog')` |
| `App::cache()` | `private readonly mixed $cache` (constructor param) |
| `App::config()` | `private readonly mixed $config` (constructor param) |
| `App::user()` | `private readonly mixed $user` (constructor param, resolves 'user' service) |
| `App::request()` | Prefer method param injection via route attributes; OR constructor `private readonly mixed $request` |
| `App::url()` | `private readonly mixed $url` (constructor param) |

**Example transformation:**
```php
// BEFORE:
class CacheController {
    public function clearAction() {
        App::cache()->flushAll();
        App::module('system')->clearCache();
    }
}

// AFTER:
class CacheController {
    public function __construct(
        private readonly mixed $cache,
        private readonly mixed $module,
    ) {}

    public function clearAction() {
        $this->cache->flushAll();
        $this->module->get('system')->clearCache();
    }
}
```

### 2.3. Static service access in LISTENERS → Constructor Injection (via index.php)

Listeners are instantiated in module `index.php` files where `$app` is available. Pass services via constructor:

| Before (index.php) | After (index.php) |
|---------------------|-------------------|
| `new AccessListener` | `new AccessListener($app->get('auth'), $app->get('db'))` |

| Before (listener class) | After (listener class) |
|--------------------------|------------------------|
| `App::auth()` | `$this->auth` (injected via constructor) |
| `App::db()` | `$this->db` (injected via constructor) |

### 2.4. App::getInstance()->get() → Constructor Injection

The 8 call sites from Stage 1 that use `App::getInstance()->get('x')` should be migrated to constructor injection if they are in controllers or listeners.

| Before | After |
|--------|-------|
| `App::getInstance()->get('path.cache')` | `private readonly mixed $pathCache` (constructor param 'path.cache') |
| `App::getInstance()->get('config.file')` | `private readonly mixed $configFile` (constructor param 'config.file') |
| `App::getInstance()->get('system.api')` | `private readonly mixed $systemApi` (constructor param 'system.api') |

**Note:** Container service IDs with dots (e.g. 'path.cache') require the ControllerResolver to resolve by parameter name matching. Verify this works in 2.0.1b.

### 2.5. DO NOT CHANGE (Deferred to 2.0.1e)

These patterns work via explicit static trait methods (RouterTrait, EventTrait) and will be refactored when StaticTrait is removed in 2.0.1e:

| Pattern | Trait | Status |
|---------|-------|--------|
| `App::abort(code, msg)` | RouterTrait | Keep — mark: `// TODO: Replace with throw HttpException in Step 2.0.1e` |
| `App::redirect(url)` | RouterTrait | Keep — mark: `// TODO: Replace with return RedirectResponse in Step 2.0.1e` |
| `App::forward(name, params)` | RouterTrait | Keep — mark: `// TODO: Step 2.0.1e` |
| `App::error(callback)` | RouterTrait | Keep — mark: `// TODO: Step 2.0.1e` |
| `App::on(event, cb)` | EventTrait | Keep — mark: `// TODO: Step 2.0.1e` |
| `App::subscribe(listener)` | EventTrait | Keep — mark: `// TODO: Step 2.0.1e` |
| `App::trigger(event)` | EventTrait | Keep — mark: `// TODO: Step 2.0.1e` |

### 2.6. Models → Temporary workaround (Deferred to 2.0.1e)

Models are ORM-managed and cannot use constructor injection. For now:

| Before | After |
|--------|-------|
| `App::module('blog')` | `App::getInstance()->get('module')->get('blog')` |
| `App::user()` | `App::getInstance()->get('user')` |

Mark each with: `// TODO: Replace with Repository pattern in Step 2.0.1e`

---

## 3. MIGRATION ORDER

1. **app/system/modules/** — System modules: cache, dashboard, finder, info, intl, mail, settings, site, user, widget, content, comment, captcha
2. **app/system/src/** — System core: SystemModule, SystemMenu, controllers, validators
3. **app/installer/** — Installer controllers, helpers, package management
4. **app/console/** — Console commands

After each area: run safety checks. Installer and console are critical — test `php pagekit setup` and `php pagekit list`.

---

## 4. INSTALLER-SPECIFIC

Installer runs before config exists. Verify:
- Services that might not exist during install (config, db)
- `App::getInstance()->get('config')` during install — StaticTrait has dummy config fallback
- No crashes when config not yet created
- Run fresh install E2E: `npx playwright test tests/e2e/specs/01-setup/installation.spec.js`

---

## 5. SERVICE REGISTRATION (index.php files)

Service registration stays as ArrayAccess for now (2.0.1d will change to `set()`):
- `$app['service'] = fn($app) => ...` — Keep as-is

But WITHIN service factories, replace reads:
- `$app['db']` → `$app->get('db')`

---

## 6. VALIDATION

- [ ] No `$app['x']` READ access in app/system/, app/installer/, app/console/ (only WRITEs remain)
- [ ] No `App::db()`, `App::cache()`, `App::module()`, etc. in controllers (replaced with DI)
- [ ] No `App::db()` etc. in listeners (replaced with constructor injection via index.php)
- [ ] No `App::getInstance()->get()` in controllers/listeners (replaced with DI)
- [ ] `App::abort()`, `App::redirect()` etc. still work (RouterTrait untouched)
- [ ] Models use `App::getInstance()->get()` with TODO markers for 2.0.1e
- [ ] `php pagekit setup` works
- [ ] `php pagekit list` works
- [ ] Fresh install E2E passes
- [ ] All PHPUnit tests pass

---

## 7. DOCUMENTATION

Update `PSR11_CONTAINER_STAGE3.md`:
- Changed files (controllers with new constructors, listeners with injected deps)
- Migration pattern examples
- Installer/console-specific notes
- List of `App::abort()` / `App::redirect()` calls deferred to 2.0.1e
- List of model `App::getInstance()->get()` calls deferred to 2.0.1e
- Notes for 2.0.1d

---

## SUCCESS CRITERIA

- All app/system/, app/installer/, app/console/ ArrayAccess reads use `$app->get()`
- Controllers use constructor injection (no more `App::db()` service shortcuts)
- Listeners receive dependencies via constructor (passed from index.php)
- RouterTrait/EventTrait calls preserved with TODO markers
- Model static access uses `App::getInstance()->get()` with TODO markers
- Installer works (fresh install)
- Console works
- All tests pass
