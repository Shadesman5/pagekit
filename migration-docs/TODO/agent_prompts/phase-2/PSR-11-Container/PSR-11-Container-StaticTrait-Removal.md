# PSR-11 Container Vollmodernisierung – Sub-Step 2.0.1e: StaticTrait Removal + DI Final

**ROADMAP:** 2.0.1e. Reference: `@ROADMAP.md`.

---

## CONTEXT

**Previous Work Completed:**
- ✅ 2.0.1a: Container implements ContainerInterface natively, app/modules/ READ migrated
- ✅ 2.0.1b: ControllerResolver supports constructor DI
- ✅ 2.0.1c: app/system/, app/installer/, app/console/ migrated (controllers/listeners use DI)
- ✅ 2.0.1d: packages/ migrated, ArrayAccess removed, `set()` added, `\ArrayAccess` deleted

**Remaining legacy patterns (from 2.0.1c/d TODO markers):**

| Category | Pattern | Approximate Count |
|----------|---------|-------------------|
| RouterTrait | `App::abort()` | 79 (52 app + 27 packages) |
| RouterTrait | `App::redirect()` | 18 (17 app + 1 packages) |
| RouterTrait | `App::forward()` | 0 |
| EventTrait | `App::on()` | 1 |
| EventTrait | `App::trigger()` | 5 |
| StaticTrait | `App::getInstance()` | ~44 in 22 files |
| `__call` magic | `$app->config()`, `$app->module()`, etc. | ~45 instance calls |
| `__callStatic` | `App::user()`, `App::request()`, `App::cache()`, etc. | ~60+ in packages |
| Intl globals | `App::translator()`, `App::intl()` | 5 (global functions) |
| Misc static | `App::path()`, `App::debug()`, `App::log()` | 4 |
| **Total** | | **~260+ call sites** |

**This Sub-Step:** Remove ALL magic static/dynamic access. Delete StaticTrait, EventTrait, RouterTrait. Delete `__call()` and `__callStatic()`. Introduce repository pattern for models. Solve Intl global functions. Result: zero `App::` static calls, zero magic methods.

**⚠️ SCOPE NOTE:** Given ~260+ call sites across heterogeneous patterns, the Architect SHOULD decompose this into granular checklist steps (one per pattern-batch). Each step must leave the system functional (all PHPUnit tests green).

---

## 0. SAFETY CHECKS (CRITICAL)

**Design principle:** This is the final cleanup. Every `App::` call and `__call()` usage must be replaced with explicit code. The system must remain functional throughout. Work in small batches — one pattern type at a time.

**Test environment:** From workspace root. Console: `php pagekit`. PHPUnit: `./app/vendor/bin/phpunit`. For curl/Playwright: start app first with `php -S localhost:8080 index.php`.

**AFTER EVERY LOGICAL CHANGE (per checklist step):**
```bash
php pagekit setup
php pagekit list
./app/vendor/bin/phpunit
```
**IF ANY FAILS → STOP AND FIX!**

**E2E TESTS (Playwright) — run ONCE at the very end (after all migration steps, before documentation):**
```bash
npx playwright test tests/e2e/specs/01-setup/installation.spec.js
npx playwright test tests/e2e/specs/02-core/authentication.spec.js
npx playwright test tests/e2e/specs/02-core/dashboard.spec.js
```
Rationale: Playwright tests take significant time. PHPUnit catches regressions per step; Playwright validates the full integration once at the end.

**Before starting:** 2.0.1d merged into `develop`. Branch from `develop`.

---

## 1. PREPARATION

1.1. Create branch from `develop` (agent chooses branch name)

1.2. Create `migration-docs/branches/PSR11_CONTAINER_STATICTRAIT_REMOVAL.md`

1.3. **Discovery — find ALL remaining magic patterns:**
```bash
# StaticTrait usage
rg 'App::getInstance\(\)' app/ packages/ --type php -n
rg 'static::\$instance' app/ --type php -n

# RouterTrait calls
rg 'App::(abort|redirect|forward|error)\(' app/ packages/ --type php -n

# EventTrait calls
rg 'App::(on|subscribe|trigger)\(' app/ packages/ --type php -n

# __call magic (instance dynamic calls)
rg '\$app->(db|cache|config|module|request|router|events|kernel|url|view|mailer|auth|user)\(' app/ packages/ --type php -n
rg '\$this->(db|cache|config|module|request|router|events|kernel|url|view|mailer|auth|user)\(' app/ packages/ --type php -n

# ALL remaining App:: static calls (excluding use/namespace)
rg 'App::' app/ packages/ --type php -n

# Intl global functions
rg 'App::(translator|intl)\(' app/ packages/ --type php -n

# Misc: App::path, App::debug, App::log
rg 'App::(path|debug|log)\(' app/ packages/ --type php -n

# TEMPORARY BRIDGE markers (should all be resolved in this step)
rg 'TEMPORARY BRIDGE' app/ packages/ --type php -n

# EntityManager singleton (related pattern — evaluate if in scope)
rg 'static::\$instance' app/modules/database/src/ORM/EntityManager.php -n
```

Document ALL findings with exact counts. Create a checklist per pattern type.

---

## 2. MIGRATE `$app->service()` INSTANCE CALLS TO `$app->get('service')`

**Prerequisite for Section 9 (`__call()` removal).** These calls dispatch through `Container::__call()` and must be converted to explicit `$app->get()` before `__call()` can be deleted.

### 2.1. Known instance-level `__call` patterns (~45 calls)

| Pattern | Count | Locations |
|---------|-------|-----------|
| `$app->config(` | ~14 | site/index.php, widget/index.php, system/index.php, SiteModule, SystemModule |
| `$app->module(` | ~26 | theme views, user mails/views, installer, system, settings, editor, view |
| `$app->request()` | ~1 | system/index.php |
| `$app->url(` | ~1 | view/index.php |
| `$app->view()` | ~1 | site/widgets/menu.php |
| `$app->error(` | ~2 | installer/index.php, routing/index.php |

### 2.2. Migration pattern

```php
// BEFORE (__call magic):
$app->config('system')

// AFTER (explicit PSR-11):
$app->get('config')->get('system')
```

**⚠️ IMPORTANT — `$app->config()` vs `$app->get('config')`:**
`$app->config(...)` dispatches to the `config` service's `__invoke()` or first argument. Verify the service signature before migrating. If `$app->config('system')` calls `Config::__invoke('system')`, the replacement is `$app->get('config')('system')`. If it calls `Config::get('system')`, use `$app->get('config')->get('system')`.

**⚠️ IMPORTANT — `$app->error()` is RouterTrait, not `__call`:**
`$app->error()` may route through RouterTrait if `$app` is an Application instance. Check whether it's `Application::error()` (RouterTrait) or `Container::__call('error')`. Handle in Section 3 (RouterTrait) if it's the former.

### 2.3. `$this->` patterns in module classes

`$this->config()` in Module/Package subclasses is the module's own `config()` method, NOT `__call` magic. **Do not migrate these.** Only migrate patterns where `$this` is a `Container`/`Application` instance.

---

## 3. REPLACE RouterTrait CALLS (~97 call sites)

### 3.1. App::abort() → throw HttpException (~79 calls)

`App::abort($code, $message)` wraps `HttpKernel::abort()` which throws typed HTTP exceptions.

**In controllers (have DI):** Replace with direct exception throw:
```php
// BEFORE:
App::abort(403, 'Access denied');

// AFTER:
throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Access denied');
// Or for generic codes:
throw new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'Access denied');
```

**In listeners:** Same pattern — throw HttpException directly.

**In console commands** (e.g. `SelfupdateCommand`): Replace with `throw new \RuntimeException($message)` or appropriate exception (console context has no HTTP).

**Mapping:**
| Code | Exception Class |
|------|----------------|
| 400 | `BadRequestHttpException` |
| 401 | `UnauthorizedHttpException` |
| 403 | `AccessDeniedHttpException` |
| 404 | `NotFoundHttpException` |
| 405 | `MethodNotAllowedHttpException` |
| 500 | `HttpException(500, $message)` or `\RuntimeException` (in console) |
| Other | `HttpException($code, $message)` |

**Note:** Check if `Pagekit\Kernel\Exception\` has custom exception classes. Use those if they exist, otherwise use Symfony's.

**Affected files (app/):** ValidatesRequestTrait, NodeApiController, UserApiController, PageController, MenuApiController, RoleApiController, UpdateController, CaptchaListener, ResetPasswordController, WidgetController, AdminController, NodeController, MaintenanceListener, PackageController, UserController, ProfileController, RegistrationController, AccessListener, SelfupdateCommand, SettingsController

**Affected files (packages/):** UrlResolver, CommentApiController, PostApiController, BlogController, SiteController

### 3.2. App::redirect() → return RedirectResponse (~18 calls)

```php
// BEFORE:
return App::redirect($url, $params, 302);

// AFTER (in controller — inject router via constructor):
return $this->router->redirect($url, $params, 302);
```

**Affected files:** RegistrationController (4), ResetPasswordController (4), AdminController (2), MigrationController (2), ProfileController (1), AuthController (1), NodeController (1), BlogController (1)

### 3.3. App::forward() → inject kernel + router

0 internal calls currently, but this is public API that extensions may use. The functionality (sub-request forwarding) must remain available as an injectable alternative.

**Current implementation** (RouterTrait):
```php
public static function forward($name, $parameters = []): Response
{
    return static::kernel()->handle(
        Request::create(
            static::router()->generate($name, $parameters), 'GET', [],
            static::request()->cookies->all(), [],
            static::request()->server->all()
        ));
}
```

**Replacement — in controllers (inject kernel + router):**
```php
public function __construct(
    private readonly mixed $kernel,
    private readonly mixed $router,
) {}

protected function forward(string $name, array $parameters = []): Response
{
    $request = $this->router->getRequest();
    return $this->kernel->handle(
        Request::create(
            $this->router->generate($name, $parameters), 'GET', [],
            $request->cookies->all(), [],
            $request->server->all()
        )
    );
}
```

**For extensions:** Document in migration guide that `App::forward()` is replaced by injecting `kernel` + `router` and replicating the sub-request pattern above.

### 3.4. App::error() / $app->error() → register via events service (~2 calls)

```php
// BEFORE (in index.php where $app is available):
$app->error($callback, $priority);

// AFTER:
$app->get('events')->on('exception', new ExceptionListenerWrapper($callback), $priority);
```

**⚠️ Check RouterTrait::error() signature** — it wraps ExceptionListenerWrapper internally. The replacement must replicate this wrapping. Read the trait source before migrating.

**Affected files:** `app/installer/index.php`, `app/modules/routing/index.php`

---

## 4. REPLACE EventTrait CALLS (~6 call sites)

### 4.1. In module index.php files (have `$app`):

```php
// BEFORE:
App::on('event', $callback, $priority);
App::subscribe(new SomeListener);
App::trigger('event', $args);

// AFTER:
$app->get('events')->on('event', $callback, $priority);
$app->get('events')->subscribe(new SomeListener);
$app->get('events')->trigger('event', $args);
```

**⚠️ IMPORTANT:** EventTrait has special logic for pre-boot registration (uses `extend()` to defer). In module index.php, check if the call happens inside a 'boot' callback (app is booted → direct call works) or in 'main' callback (app not yet booted → need deferred registration).

For 'main' callbacks, use Container::extend():
```php
// In 'main' callback (before boot):
$app->extend('events', function ($dispatcher) use ($callback) {
    $dispatcher->on('event', $callback);
    return $dispatcher;
});
```

For 'boot' callbacks (after boot):
```php
// In 'boot' callback (after boot):
$app->get('events')->on('event', $callback);
```

**Affected files:**
- `App::on()` — `app/system/modules/cache/src/CacheModule.php` (1 call)
- `App::trigger()` — `app/system/modules/site/src/SiteModule.php`, `app/system/modules/finder/src/Controller/FinderController.php`, `app/system/modules/content/src/ContentHelper.php`, `app/system/modules/user/src/UserModule.php`, `app/installer/src/Package/PackageManager.php`

### 4.2. In controllers (have DI):

If any controller uses `App::trigger()`:
```php
// Inject events service via constructor
public function __construct(private readonly mixed $events) {}

// Then:
$this->events->trigger('event', $args);
```

### 4.3. Fix SymfonyEventDispatcherBridge::dispatch()

**File:** `app/modules/application/src/Event/SymfonyEventDispatcherBridge.php`

**Audit finding (Step 1.7):** `dispatch()` is a no-op — it returns the event without forwarding to Pagekit's event system.

**Fix:** Make `dispatch()` actually forward to Pagekit's `trigger()`:
```php
// BEFORE:
public function dispatch(object $event, ?string $eventName = null): object
{
    return $event; // no-op!
}

// AFTER:
public function dispatch(object $event, ?string $eventName = null): object
{
    $name = $eventName ?? get_class($event);
    $this->dispatcher->trigger($name, [$event]);
    return $event;
}
```

**Note:** The bridge is registered as `symfony.event_dispatcher` in `app/modules/application/index.php`. Verify it still works after the fix. If no Symfony component actually consumes this service, consider whether the bridge should be deleted entirely (DELETE OVER WRAP rule). If any Symfony component needs it (e.g. future Symfony HttpKernel integration), keep it with the fix.

---

## 5. MIGRATE REMAINING `App::*` STATIC SHORTCUTS (~60+ in packages, misc in app)

These are `__callStatic` calls in `StaticTrait` that proxy to `Container::__call()`. They must all be replaced before StaticTrait can be deleted.

### 5.1. Blog Package Controllers (already have DI for `module`)

The blog controllers received constructor DI for `module` in 2.0.1d, but still use `App::*` for all other services. **Expand constructor injection** to cover all needed services:

| Controller | Remaining `App::*` calls |
|------------|--------------------------|
| `PostApiController` | `App::user()`, `App::request()`, `App::filter()`, `App::db()`, `App::module()` |
| `CommentApiController` | `App::user()`, `App::request()`, `App::db()`, `App::module()` |
| `BlogController` | `App::module()`, `App::user()`, `App::abort()`, `App::redirect()` |
| `SiteController` | `App::module()`, `App::db()`, `App::content()`, `App::feed()`, `App::response()`, `App::url()` |

```php
// BEFORE:
App::user()

// AFTER (inject via constructor):
$this->user
```

### 5.2. Blog UrlResolver (~5 calls)

**File:** `packages/pagekit/blog/src/UrlResolver.php`

| Line | Pattern |
|------|---------|
| constructor | `App::cache()->fetch(...)` |
| `__destruct` | `App::cache()->save(...)` |
| resolve | `App::abort(404, ...)` (×2) |
| getPermalink | `App::module('blog')` |

UrlResolver needs constructor DI for `cache` and `module`. `App::abort()` → throw HttpException (see Section 3.1).

### 5.3. Blog Event Listener

**File:** `packages/pagekit/blog/src/Event/RouteListener.php`

Has tagged `App::*` calls from 2.0.1d. Inject needed services via constructor (listener already gets DI from index.php).

### 5.4. Misc Static Calls

| Pattern | File | Notes |
|---------|------|-------|
| `App::path()` | `app/installer/src/SelfUpdater.php` | **No TODO marker — add or fix** |
| `App::debug()` | `app/installer/src/Controller/PackageController.php` (×2) | |
| `App::log()` | `app/installer/src/Controller/PackageController.php` | |
| `App::view()` | `packages/pagekit/theme-one/functions.php` | Tagged for 2.0.1e |
| `App::routes()` | Blog package | |
| `App::router()` | Blog package | |

For `App::path()`: replace with injected `path` service or `$app->get('path')`.
For `App::debug()`: replace with injected `debug` config or `$app->get('config')->get('app.debug')`.
For `App::log()`: replace with injected `log` service.

---

## 6. RESOLVE `App::getInstance()` BRIDGES (~44 calls in 22 files)

These are TEMPORARY BRIDGE patterns from 2.0.1c/d. In classes that already have constructor DI, replace `App::getInstance()->get('x')` with the injected property.

### 6.1. System Controllers (already have DI — expand constructor params)

| Controller | `App::getInstance()` usage |
|------------|----------------------------|
| `SettingsController` | `App::getInstance()->get('config')` |
| `DashboardController` | `App::getInstance()->get('module')` |
| `ProfileController` | `App::getInstance()->get('user')` |
| `RegistrationController` | `App::getInstance()->get('user')` |
| `ResetPasswordController` | `App::getInstance()->get('mailer')` |
| `UserApiController` | `App::getInstance()->get('user')` |

These controllers already use constructor DI from 2.0.1c. Add the missing services to their constructors.

### 6.2. System Helpers & Services (need DI introduction or expansion)

| Class | `App::getInstance()` usage | Strategy |
|-------|----------------------------|----------|
| `ValidatesRequestTrait` | `App::getInstance()->get('db')`, `App::getInstance()->get('user')` | **Convert trait to service or abstract method** — traits can't have constructors. Option A: require using classes to provide a `getContainer()` method. Option B: delete trait, inline logic where used. Option C: Convert to a `RequestValidator` service with DI, inject into controllers that need it. |
| `MenuHelper` | `App::getInstance()->get('url')`, `App::getInstance()->get('user')` | Already instantiated in index.php — pass services via constructor |
| `FileLocatorAsset` | `App::getInstance()->get('locator')`, `App::getInstance()->get('url')` | Pass services via constructor from index.php |
| `DashboardModule` | `App::getInstance()->get('module')`, `App::getInstance()->get('user')` | Expand constructor DI |
| `CacheModule` | `App::getInstance()->get('events')` | Expand constructor DI |
| `UniqueValidator` | `App::getInstance()->get('db')` | Expand constructor DI |

### 6.3. Installer Components (~15+ calls — heaviest area)

| Class | `App::getInstance()` calls | Strategy |
|-------|----------------------------|----------|
| `PackageManager` | 9 calls (`get('config')`, `get('path')`, `get('module')`, etc.) | Introduce constructor DI. PackageManager is instantiated in installer index.php — pass all needed services. |
| `PackageFactory` | 1 call (`get('url')`) | Pass via constructor |
| `PackageScripts` | 1 call (passes `App::getInstance()` to scripts) | Pass `$app` explicitly from caller |
| `InstallerController` | 1 call (`new Installer(App::getInstance())`) | Inject via constructor DI |
| `MarketplaceController` | 2 calls (`get('system.api')`) | Inject via constructor |
| `UpdateController` | 2 calls (`get('system.api')`, `get('path.temp')`) | Inject via constructor |
| `PackageController` | 2 calls (`get('system.api')`) | Inject via constructor |
| `install.php` | 1 call | Use `$app` from calling context |
| `install-demo.php` | 1 call | Use `$app` from calling context |

### 6.4. `static::$instance` in Container.php

Remove `static::$instance = $this` assignment from Container constructor. This is the backing store for `StaticTrait::getInstance()`. After all `App::getInstance()` calls are eliminated, this line and the property can be deleted.

---

## 7. INTL GLOBAL FUNCTIONS (5 calls — special handling)

**Problem:** `__()`, `_c()`, `_i()` (+ `Pagekit\__()`, `Pagekit\_c()`, `Pagekit\_n()`) are **global helper functions** that cannot use constructor injection. They currently call `App::translator()` and `App::intl()`.

**Files:**
- `app/system/modules/intl/functions.php` — `__()`, `_c()`, `_i()`
- `app/system/modules/intl/functions-pagekit-namespace.php` — `Pagekit\__()`, `Pagekit\_c()`, `Pagekit\_n()`

**Strategy — Lightweight Service Locator for Intl:**

```php
// New: app/system/modules/intl/src/IntlServiceLocator.php
final class IntlServiceLocator
{
    private static ?TranslatorInterface $translator = null;
    private static ?IntlDateFormatter $intl = null;

    public static function setTranslator(TranslatorInterface $translator): void { ... }
    public static function getTranslator(): TranslatorInterface { ... }
    public static function setIntl(mixed $intl): void { ... }
    public static function getIntl(): mixed { ... }
}
```

```php
// In IntlModule boot (or intl/index.php boot callback):
IntlServiceLocator::setTranslator($app->get('translator'));
IntlServiceLocator::setIntl($app->get('intl'));
```

```php
// In functions.php:
function __($id, array $parameters = [], $domain = 'messages', $locale = null) {
    return IntlServiceLocator::getTranslator()->trans($id, $parameters, $domain, $locale);
}
```

**Justification:** Global functions are a stable extension API (ROADMAP: "Platform API names may be kept as clean modern reimplementations"). A dedicated service locator is narrower and more explicit than `App::getInstance()`. It breaks the dependency on StaticTrait while preserving the `__()` / `_c()` public API.

---

## 8. MODELS → REPOSITORY PATTERN

### 8.1. Introduce Repository Pattern

For each model that accesses services, create a repository or move service logic to the controller/caller:

**Example: Post model**
```php
// BEFORE (in Post model):
App::module('blog');
App::user();
App::url('@blog/id', ['id' => $this->id], 'base');

// AFTER: Move service-dependent logic to PostRepository or to the caller
class PostRepository {
    public function __construct(
        private readonly mixed $module,
        private readonly mixed $user,
        private readonly mixed $url,
    ) {}

    public function getBlogConfig(): array { ... }
    public function getPostUrl(Post $post): string { ... }
}
```

**Register repository as service in module index.php:**
```php
$app->set('blog.post.repository', fn($app) => new PostRepository(
    $app->get('module'),
    $app->get('user'),
    $app->get('url'),
));
```

**Move service-dependent logic from models to repositories.** Models should be pure data objects (entities). Service access belongs in repositories/services.

### 8.2. Affected Models

| Model | Patterns | Count |
|-------|----------|-------|
| `packages/pagekit/blog/src/Model/Post.php` | `App::module('blog')`, `App::user()`, `App::url()` | 3 |
| `app/system/modules/site/src/Model/Node.php` | `App::getInstance()->get('url')`, `App::getInstance()->get('user')` | 2 |

### 8.3. EntityManager Singleton (evaluate scope)

`app/modules/database/src/ORM/EntityManager.php` has its own `static::$instance` pattern (2 occurrences). This is independent of `StaticTrait` but follows the same anti-pattern.

**Decision for Architect:** If removing `EntityManager::$instance` is low-risk and straightforward, include it. If it requires significant ORM refactoring, defer to a later step with a TODO marker.

---

## 9. REMOVE MAGIC METHODS

### 9.1. Delete `Container::__call()`

**File:** `app/modules/application/src/Container.php`

Delete the `__call()` method entirely. **Prerequisite:** All `$app->service()` instance calls must already be migrated (Section 2).

```bash
# Verify no dynamic instance calls remain:
rg '\$app->(db|cache|config|module|request|router|events|kernel|url|view|mailer|auth|user|path|debug|log|filter|feed|content|response)\(' app/ packages/ --type php
```

### 9.2. Delete `__callStatic()` (in StaticTrait)

This is deleted automatically when StaticTrait is deleted (Section 10). But verify no `App::anything()` calls remain first.

---

## 10. DELETE TRAITS

### 10.1. Delete StaticTrait

**File:** `app/modules/application/src/Application/Traits/StaticTrait.php` → DELETE

Remove `use StaticTrait` from Application class.

Remove `static::$instance = $this` from Container constructor.

Remove `protected static ?self $instance = null` property from Container.

### 10.2. Delete EventTrait

**File:** `app/modules/application/src/Application/Traits/EventTrait.php` → DELETE

Remove `use EventTrait` from Application class.

### 10.3. Delete RouterTrait

**File:** `app/modules/application/src/Application/Traits/RouterTrait.php` → DELETE

Remove `use RouterTrait` from Application class.

### 10.4. Update Application Class

**File:** `app/modules/application/src/Application.php`

After removing all traits:
```php
class Application extends Container
{
    protected bool $booted = false;

    public function __construct(array $values = []) { ... }
    public function boot(): void { ... }
    public function run(?Request $request = null): void { ... }
    public function inConsole(): bool { ... }
}
```

### 10.5. Clean up Traits directory

Delete `app/modules/application/src/Application/Traits/` directory if empty.

---

## 11. TESTS & VERIFICATION

### 11.1. PHPUnit (run per step throughout)

- Remove/update tests that test StaticTrait, __callStatic, __call behavior
- Add repository tests (PostRepository, etc.)
- Verify all PHPUnit tests pass

### 11.2. Final E2E Tests (run ONCE after all migration steps)

```bash
npx playwright test tests/e2e/specs/01-setup/installation.spec.js
npx playwright test tests/e2e/specs/02-core/authentication.spec.js
npx playwright test tests/e2e/specs/02-core/dashboard.spec.js
```

### 11.3. Smoke Tests

```bash
php pagekit setup
php pagekit list
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/admin
```

---

## 12. FINAL VERIFICATION

```bash
# Zero App:: static calls (except use statements and class references)
rg 'App::' app/ packages/ --type php -n | grep -v '^.*use ' | grep -v '\\\\App'
# Should return NOTHING

# Zero __call magic
rg '__call\(' app/modules/application/src/Container.php

# Zero __callStatic magic
rg '__callStatic\(' app/modules/application/src/ --type php

# Zero trait files
ls app/modules/application/src/Application/Traits/
# Should be empty or directory should not exist

# Zero $app->service() dynamic calls
rg '\$app->(db|cache|config|module|request|router|events|kernel|url|view|mailer|auth|user|path|debug|log|filter|feed|content|response)\(' app/ packages/ --type php

# Zero App::getInstance()
rg 'App::getInstance\(\)' app/ packages/ --type php

# Zero TEMPORARY BRIDGE markers
rg 'TEMPORARY BRIDGE' app/ packages/ --type php
```

---

## 13. VALIDATION CHECKLIST

- [ ] StaticTrait.php deleted
- [ ] EventTrait.php deleted
- [ ] RouterTrait.php deleted
- [ ] Traits directory removed
- [ ] Container has no `__call()` method
- [ ] Container has no `static::$instance` property
- [ ] Zero `App::` static calls in codebase (except use/class declarations)
- [ ] Zero `$app->service()` dynamic calls via `__call`
- [ ] Zero `App::getInstance()` calls
- [ ] Zero `TEMPORARY BRIDGE` TODO markers
- [ ] Models use repository pattern (no direct service access)
- [ ] All `App::abort()` replaced with throw HttpException
- [ ] All `App::redirect()` replaced with Router/RedirectResponse
- [ ] All event registration uses `$app->get('events')` directly
- [ ] Intl functions use IntlServiceLocator (not App::translator())
- [ ] ValidatesRequestTrait refactored (no App::getInstance())
- [ ] PackageManager uses constructor DI (no App::getInstance())
- [ ] `php pagekit setup` works
- [ ] All PHPUnit tests pass
- [ ] All Playwright E2E tests pass
- [ ] Console commands work (`php pagekit list`)

---

## 14. DOCUMENTATION

Create `migration-docs/branches/PSR11_CONTAINER_STATICTRAIT_REMOVAL.md`:
- Summary of all deleted code (traits, magic methods)
- Repository pattern documentation
- IntlServiceLocator documentation
- Before/after examples for each pattern
- Extension migration notes (how extensions should handle abort, redirect, events, etc.)
- Full file list of changed files with pattern counts

Create `migration-docs/PSR11_CONTAINER_FULL_MODERNIZATION.md` (final summary):
- Complete 2.0.1 migration summary (all 5 sub-steps a–e)
- Before/after architecture comparison
- Breaking changes for extensions
- Link to extension migration guide (from 2.0.1d)

---

## SUCCESS CRITERIA

- Zero static access patterns (`App::anything()`)
- Zero magic methods (`__call`, `__callStatic`)
- Zero trait files (StaticTrait, EventTrait, RouterTrait deleted)
- Zero `TEMPORARY BRIDGE` markers
- Container is pure PSR-11: `get()`, `has()`, `set()`, `factory()`, `extend()`, `raw()`, `keys()`
- Controllers use constructor injection exclusively
- Listeners use constructor injection exclusively
- Models use repository pattern (no direct service access)
- Intl global functions use dedicated service locator
- All PHPUnit tests pass
- All Playwright E2E tests pass
- PR ready with full evidence
- PSR-11 Container Vollmodernisierung COMPLETE
