# PSR-11 Container Migration Guide for Extensions

> **Applies to:** Pagekit 1.2.x+ (ROADMAP Step 2.0.1e completed)
>
> This guide explains how to update legacy Pagekit extensions to work with the modernized PSR-11 Container. ArrayAccess (`$app['x']`), all `App::*` static shortcuts, magic `__call()` proxying, and static traits have been removed. All service access and registration now uses explicit methods and constructor dependency injection.

---

## Breaking Changes

### Service Access

All `$app['x']` array-style reads must be replaced with `$app->get('x')` method calls, and `isset()` checks with `$app->has()`.

| Old (Legacy) | New (PSR-11) | Notes |
|---|---|---|
| `$app['db']` | `$app->get('db')` | Returns the database connection |
| `$app['cache']` | `$app->get('cache')` | Returns the cache service |
| `$app['config']` | `$app->get('config')` | Returns the config manager |
| `$app['url']` | `$app->get('url')` | Returns the URL generator |
| `$app['view']` | `$app->get('view')` | Returns the view service |
| `$app['events']` | `$app->get('events')` | Returns the event dispatcher |
| `$app['router']` | `$app->get('router')` | Returns the router |
| `$app['session']` | `$app->get('session')` | Returns the session |
| `$app['auth']` | `$app->get('auth')` | Returns the auth service |
| `$app['module']` | `$app->get('module')` | Returns the module manager |
| `$app['migration']` | `$app->get('migration')` | Returns the migration service |
| `$app['mailer']` | `$app->get('mailer')` | Returns the mailer service |
| `$app['filter']` | `$app->get('filter')` | Returns the filter manager |
| `$app['log']` | `$app->get('log')` | Returns the logger |
| `$app['kernel']` | `$app->get('kernel')` | Returns the HTTP kernel |
| `isset($app['x'])` | `$app->has('x')` | Check if a service is registered |

### Service Registration (module index.php)

All `$app['x'] = ...` array-style assignments must be replaced with `$app->set('x', ...)`.

| Old (Legacy) | New (PSR-11) |
|---|---|
| `$app['myservice'] = function($app) { ... };` | `$app->set('myservice', function($app) { ... });` |
| `$app['myservice'] = $object;` | `$app->set('myservice', $object);` |
| `$app['myservice'] = 'scalar';` | `$app->set('myservice', 'scalar');` |

**Factory services** (each `get()` returns a fresh instance) continue to use the `factory()` method:

```php
// Old
$app['request'] = $app->factory(function($app) {
    return new Request();
});

// New
$app->factory('request', function($app) {
    return new Request();
});
```

**Extending services** continues to use the `extend()` method:

```php
$app->extend('view', function($view, $app) {
    $view->addHelper(new MyHelper());
    return $view;
});
```

> **Important:** Once a service has been resolved (i.e., `get()` has been called on it), its definition cannot be overridden. Calling `set()` on an already-resolved service throws `\RuntimeException`.

---

## Controller Dependency Injection

Controllers now support constructor injection. The `ControllerResolver` automatically injects container services whose IDs match the constructor parameter names.

### Before (Legacy)

```php
use Pagekit\Application as App;

class PostApiController
{
    /**
     * @Route("/api/blog/post")
     */
    public function indexAction(): array
    {
        $module = App::module('blog');
        $db = App::db();
        $user = App::user();

        // ...
    }
}
```

### After (Constructor Injection — Fully Modernized)

```php
use Pagekit\Module\ModuleManager;
use Pagekit\Auth\Auth;

class PostApiController
{
    private readonly mixed $blog;

    public function __construct(
        private readonly ModuleManager $module,
        private readonly mixed $db,
        private readonly Auth $auth,
    ) {
        $this->blog = $this->module->get('blog');
    }

    /**
     * @Route("/api/blog/post")
     */
    public function indexAction(): array
    {
        $config = $this->blog->config('posts_per_page');
        $posts = $this->db->createQueryBuilder()/* ... */;
        $user = $this->auth->getUser();

        // ...
    }
}
```

### Parameter Matching Rules

| Constructor Parameter Name | Resolved From |
|---|---|
| `$db` | `$app->get('db')` |
| `$module` | `$app->get('module')` (ModuleManager) |
| `$cache` | `$app->get('cache')` |
| `$view` | `$app->get('view')` |
| `$events` | `$app->get('events')` |
| `$router` | `$app->get('router')` |
| `$session` | `$app->get('session')` |
| `$auth` | `$app->get('auth')` |

The parameter name **must match** the container service ID exactly.  Parameters that do not match a service ID are skipped (they must have default values or be provided by the route).

---

## Exception Types

The container now throws PSR-11 compliant exceptions instead of generic PHP exceptions.

| Scenario | Old Exception | New Exception | PSR-11 Interface |
|---|---|---|---|
| Service not found | `\InvalidArgumentException` | `Pagekit\Container\NotFoundException` | `Psr\Container\NotFoundExceptionInterface` |
| Error during resolution | *(uncaught)* | `Pagekit\Container\ContainerException` | `Psr\Container\ContainerExceptionInterface` |
| Override resolved service | `\RuntimeException` | `\RuntimeException` | *(unchanged)* |

### Catching Exceptions

```php
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;

// Check before access (preferred)
if ($app->has('myservice')) {
    $service = $app->get('myservice');
}

// Or catch the PSR-11 interface
try {
    $service = $app->get('myservice');
} catch (NotFoundExceptionInterface $e) {
    // Service is not registered
} catch (ContainerExceptionInterface $e) {
    // Error while resolving the service
}
```

---

## Quick Migration Checklist

1. **Search** your extension for `$app['` — replace every occurrence with `$app->get('` / `$app->set('` / `$app->has('` as appropriate.
2. **Search** for `isset($app[` — replace with `$app->has(`.
3. **Controllers** — add constructor parameters for services you use. Remove `App::db()`, `App::module()` etc. from method bodies.
4. **Update exception handling** — catch `NotFoundExceptionInterface` instead of `\InvalidArgumentException` for missing services.
5. **Test** — run `./app/vendor/bin/phpunit` and verify your extension loads.

---

## Removed Static & Magic Patterns (Step 2.0.1e)

The following patterns have been **removed** as of Step 2.0.1e. All call sites must be updated.

### `App::abort()` — Throw Symfony HTTP Exceptions

```php
// Old
App::abort(404, 'Page not found');
App::abort(403, 'Access denied');

// New — throw the appropriate Symfony exception directly
throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Page not found');
throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Access denied');
```

| HTTP Code | Symfony Exception Class |
|---|---|
| 400 | `BadRequestHttpException` |
| 403 | `AccessDeniedHttpException` |
| 404 | `NotFoundHttpException` |
| 405 | `MethodNotAllowedHttpException` |
| 409 | `ConflictHttpException` |
| 500 | `HttpException` (generic, pass code as first arg) |

### `App::redirect()` — Inject Router

```php
// Old
return App::redirect('@blog/post', ['id' => $id]);

// New — inject 'router' via constructor, then:
return $this->router->redirect('@blog/post', ['id' => $id]);
```

### `App::on()`, `App::subscribe()`, `App::trigger()` — Use Event Dispatcher

```php
// Old
App::on('boot', function ($event, $app) { /* ... */ });
App::trigger('custom.event', [$data]);

// New — via $app container instance:
$app->get('events')->on('boot', function ($event, $app) { /* ... */ });
$app->get('events')->trigger('custom.event', [$data]);

// Or inject 'events' via constructor:
$this->events->on('boot', function ($event, $app) { /* ... */ });
```

### `App::user()`, `App::db()`, `App::cache()`, etc. — Constructor DI

All `App::*()` static service accessors have been removed. Use constructor dependency injection instead.

```php
// Old
$user = App::user();
$db = App::db();
$cache = App::cache();

// New — inject services via constructor parameters matching the container ID:
public function __construct(
    private readonly Auth $auth,
    private readonly mixed $db,
    private readonly mixed $cache,
) {}

// Then use:
$user = $this->auth->getUser();
$posts = $this->db->createQueryBuilder()/* ... */;
$value = $this->cache->fetch('key');
```

### `$app->module('x')` Magic — Explicit ModuleManager

```php
// Old (via __call magic)
$blog = $app->module('blog');

// New
$blog = $app->get('module')->get('blog');
```

### `$app->config('x')` Magic — Explicit Config Access

```php
// Old (via __call magic)
$value = $app->config('app.debug');

// New
$value = $app->get('config')('app.debug');
```

### `App::forward()` — Sub-Request Forwarding (Removed)

`App::forward()` from `RouterTrait` has been removed. There were 0 internal calls, but extensions may have used it. Replace with injected `kernel` + `router` services:

```php
// Old
return App::forward($route, $parameters);

// New — inject 'kernel' and 'router' via constructor, then:
use Symfony\Component\HttpFoundation\Request;

$url = $this->router->generate($route, $parameters);
$subRequest = Request::create($url);
return $this->kernel->handle($subRequest);
```

### `$app->error()` — Event-Based Exception Handling

```php
// Old
$app->error(function (\Exception $e) { /* ... */ });

// New
use Pagekit\Event\ExceptionListenerWrapper;
$app->get('events')->on('exception', new ExceptionListenerWrapper(function (\Exception $e) {
    // ...
}));
```

---

## Complete Migration Checklist

Use this checklist when migrating an extension from pre-2.0.1 Pagekit to 1.2.x+.

### ArrayAccess Removal (Step 2.0.1d)

- [ ] Replace all `$app['x']` reads with `$app->get('x')`
- [ ] Replace all `$app['x'] = ...` writes with `$app->set('x', ...)`
- [ ] Replace all `isset($app['x'])` checks with `$app->has('x')`
- [ ] Update exception handling to catch `NotFoundExceptionInterface` instead of `\InvalidArgumentException`

### Static & Magic Removal (Step 2.0.1e)

- [ ] Replace `App::abort(code)` with `throw new` Symfony HTTP exception (see table above)
- [ ] Replace `App::redirect(...)` with `$this->router->redirect(...)` (inject `router`)
- [ ] Replace `App::forward(...)` with sub-request via injected `kernel` + `router` (see above)
- [ ] Replace `App::on/subscribe/trigger(...)` with `$app->get('events')->on/subscribe/trigger(...)`
- [ ] Replace `App::user()` with constructor-injected `$this->auth->getUser()`
- [ ] Replace `App::db()`, `App::cache()`, `App::url()`, etc. with constructor-injected services
- [ ] Replace `$app->module('x')` with `$app->get('module')->get('x')`
- [ ] Replace `$app->config('x')` with `$app->get('config')('x')`
- [ ] Replace `$app->error(callback)` with `$app->get('events')->on('exception', new ExceptionListenerWrapper(callback))`
- [ ] Remove all `use Pagekit\Application as App;` imports that only existed for static calls
- [ ] Run `./app/vendor/bin/phpunit` to verify

> **Note:** The global translation functions `__()`, `_c()`, and `_i()` are unchanged. They are backed by `IntlServiceLocator` internally — no extension changes needed.

---

## Further Reading

- [PSR-11 Container Full Modernization Summary](PSR11_CONTAINER_FULL_MODERNIZATION.md) — complete overview of all 2.0.1 sub-steps (a–e), architecture comparison, and breaking changes
