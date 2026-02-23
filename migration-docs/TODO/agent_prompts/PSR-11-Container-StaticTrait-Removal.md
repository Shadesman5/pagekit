# PSR-11 Container Vollmodernisierung – Sub-Step 2.0.1e: StaticTrait Removal + DI Final

**ROADMAP:** 2.0.1e. Reference: `@ROADMAP.md`.

---

## CONTEXT

**Previous Work Completed:**
- ✅ 2.0.1a: Container implements ContainerInterface natively, app/modules/ migrated
- ✅ 2.0.1b: ControllerResolver supports constructor DI
- ✅ 2.0.1c: app/system/, app/installer/, app/console/ migrated (controllers/listeners use DI)
- ✅ 2.0.1d: packages/ migrated, ArrayAccess removed, `set()` added

**Remaining legacy patterns (from 2.0.1c/d TODO markers):**
- `App::abort()`, `App::redirect()`, `App::forward()`, `App::error()` — via RouterTrait
- `App::on()`, `App::subscribe()`, `App::trigger()` — via EventTrait
- `App::getInstance()` — via StaticTrait
- `App::getInstance()->get('x')` — in models (~10 calls)
- `$app->db()`, `$app->module()` etc. — via Container `__call()` magic

**This Sub-Step:** Remove ALL magic static/dynamic access. Delete StaticTrait, EventTrait, RouterTrait. Introduce repository pattern for models. Result: zero `App::` static calls, zero magic methods.

---

## 0. SAFETY CHECKS (CRITICAL)

**Design principle:** This is the final cleanup. Every `App::` call and `__call()` usage must be replaced with explicit code. The system must remain functional throughout. Work in small batches — one trait/pattern at a time.

**Test environment:** From workspace root. Console: `php pagekit`. PHPUnit: `./app/vendor/bin/phpunit`. For curl/Playwright: start app first.

**AFTER EVERY LOGICAL CHANGE:**
```bash
php pagekit setup
php pagekit list
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/admin
./app/vendor/bin/phpunit
npx playwright test tests/e2e/specs/01-setup/installation.spec.js
```
**IF ANY FAILS → STOP AND FIX!**

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

# Remaining App:: anything
rg 'App::' app/ packages/ --type php -n
```

Document ALL findings. Create a checklist per pattern type.

---

## 2. REPLACE RouterTrait CALLS

### 2.1. App::abort() → throw HttpException

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

**Mapping:**
| Code | Exception Class |
|------|----------------|
| 400 | `BadRequestHttpException` |
| 401 | `UnauthorizedHttpException` |
| 403 | `AccessDeniedHttpException` |
| 404 | `NotFoundHttpException` |
| 405 | `MethodNotAllowedHttpException` |
| Other | `HttpException($code, $message)` |

**Note:** Check if `Pagekit\Kernel\Exception\` has custom exception classes. Use those if they exist, otherwise use Symfony's.

### 2.2. App::redirect() → return RedirectResponse

```php
// BEFORE:
return App::redirect($url, $params, 302);

// AFTER (in controller):
return $this->router->redirect($url, $params, 302);
// Inject router via constructor: private readonly mixed $router
```

### 2.3. App::forward() → use kernel directly

```php
// BEFORE:
return App::forward($name, $params);

// AFTER (in controller):
// Inject kernel and router, replicate the forward logic
```

### 2.4. App::error() → register via events service

```php
// BEFORE:
App::error($callback, $priority);

// AFTER (in index.php where $app is available):
$app->get('events')->on('exception', new ExceptionListenerWrapper($callback), $priority);
```

---

## 3. REPLACE EventTrait CALLS

### 3.1. In module index.php files (have `$app`):

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
$app->get('events')->subscribe(new SomeListener);
```

### 3.2. In controllers (have DI):

If any controller uses `App::trigger()`:
```php
// Inject events service via constructor
public function __construct(private readonly mixed $events) {}

// Then:
$this->events->trigger('event', $args);
```

### 3.3. Fix SymfonyEventDispatcherBridge::dispatch()

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

## 4. REPLACE App::getInstance() IN MODELS

### 4.1. Introduce Repository Pattern

For each model that uses `App::getInstance()->get('x')`, create a repository:

**Example: Post model**
```php
// BEFORE (in Post model):
App::getInstance()->get('module')->get('blog');
App::getInstance()->get('user');

// AFTER: Create PostRepository
class PostRepository {
    public function __construct(
        private readonly mixed $module,
        private readonly mixed $user,
        private readonly mixed $db,
    ) {}

    public function getPostWithMeta(Post $post): array {
        $blogModule = $this->module->get('blog');
        // ... logic that was in the model
    }
}
```

**Register repository as service in module index.php:**
```php
$app->set('blog.post.repository', fn($app) => new PostRepository(
    $app->get('module'),
    $app->get('user'),
    $app->get('db'),
));
```

**Move service-dependent logic from models to repositories.** Models should be pure data objects (entities). Service access belongs in repositories/services.

### 4.2. Affected Models (from discovery)

Based on current codebase analysis (~10 App:: calls in models):
- `packages/pagekit/blog/src/Model/Post.php` — `App::module('blog')`, `App::user()`, `App::url()`
- `app/system/modules/site/src/Model/Node.php` — `App::url()`, `App::user()`

---

## 5. REMOVE Container::__call() MAGIC

**File:** `app/modules/application/src/Container.php`

Delete the `__call()` method entirely. Any remaining `$app->db()`, `$app->module()` style calls must be converted to `$app->get('db')`, `$app->get('module')` first.

```bash
# Verify no dynamic instance calls remain:
rg '\$app->(db|cache|config|module|request|router|events|kernel|url|view|mailer|auth|user)\(' app/ packages/ --type php
```

---

## 6. DELETE TRAITS

### 6.1. Delete StaticTrait

**File:** `app/modules/application/src/Application/Traits/StaticTrait.php` → DELETE

Remove `use StaticTrait` from Application class.

Remove `static::$instance = $this` from Container constructor.

### 6.2. Delete EventTrait

**File:** `app/modules/application/src/Application/Traits/EventTrait.php` → DELETE

Remove `use EventTrait` from Application class.

### 6.3. Delete RouterTrait

**File:** `app/modules/application/src/Application/Traits/RouterTrait.php` → DELETE

Remove `use RouterTrait` from Application class.

### 6.4. Update Application Class

**File:** `app/modules/application/src/Application.php`

After removing all traits:
```php
class Application extends Container
{
    // No more trait usage
    protected bool $booted = false;

    public function __construct(array $values = []) { ... }
    public function boot(): void { ... }
    public function run(?Request $request = null): void { ... }
    public function inConsole(): bool { ... }
}
```

---

## 7. FINAL VERIFICATION

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
rg '\$app->(db|cache|config|module)\(' app/ packages/ --type php
```

---

## 8. TESTS

- Remove/update tests that test StaticTrait, __callStatic, __call behavior
- Add repository tests
- Verify all PHPUnit tests pass
- Run full E2E installation test
- Run admin login + basic navigation E2E

---

## 9. VALIDATION

- [ ] StaticTrait.php deleted
- [ ] EventTrait.php deleted
- [ ] RouterTrait.php deleted
- [ ] Container has no `__call()` method
- [ ] Zero `App::` static calls in codebase (except use/class declarations)
- [ ] Zero `$app->service()` dynamic calls
- [ ] Models use repository pattern (no direct service access)
- [ ] All `App::abort()` replaced with throw HttpException
- [ ] All `App::redirect()` replaced with Router/RedirectResponse
- [ ] All event registration uses `$app->get('events')` directly
- [ ] `php pagekit setup` works
- [ ] Fresh install E2E passes
- [ ] All PHPUnit tests pass
- [ ] Console commands work

---

## 10. DOCUMENTATION

Create `PSR11_CONTAINER_STATICTRAIT_REMOVAL.md`:
- Summary of all deleted code (traits, magic methods)
- Repository pattern documentation
- Before/after examples for each pattern
- Extension migration notes (how extensions should handle abort, redirect, etc.)

Create `PSR11_CONTAINER_FULL_MODERNIZATION.md` (final summary):
- Complete 2.0.1 migration summary (all 5 sub-steps)
- Before/after architecture comparison
- Breaking changes for extensions
- Link to extension migration guide (from 2.0.1d)

---

## SUCCESS CRITERIA

- Zero static access patterns (`App::anything()`)
- Zero magic methods (`__call`, `__callStatic`)
- Zero trait files (StaticTrait, EventTrait, RouterTrait deleted)
- Container is pure PSR-11: `get()`, `has()`, `set()`, `factory()`, `extend()`, `raw()`, `keys()`
- Controllers use constructor injection
- Listeners use constructor injection
- Models use repository pattern
- All tests pass
- PR ready with full evidence
- PSR-11 Container Vollmodernisierung COMPLETE
