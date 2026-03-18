# PSR-11 Container Migration Guide for Extensions

> **Applies to:** Pagekit 1.1.x+ (ROADMAP Step 2.0.1d)
>
> This guide explains how to update legacy Pagekit extensions to work with the modernized PSR-11 Container. ArrayAccess (`$app['x']`) has been removed; all service access and registration now uses explicit methods.

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

### After (Constructor Injection)

```php
use Pagekit\Application as App;
use Pagekit\Module\ModuleManager;

class PostApiController
{
    private readonly mixed $blog;

    public function __construct(
        private readonly ModuleManager $module,
        private readonly mixed $db,
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

        // App::user() stays until Step 2.0.1e (StaticTrait Removal)
        $user = App::user();

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

## Deferred Changes (Step 2.0.1e)

The following patterns are **not yet removed** and will change in the next migration step:

- `App::user()`, `App::db()`, `App::cache()`, `App::router()`, `App::request()`, `App::url()`, `App::content()`, `App::feed()`, `App::response()`, `App::filter()` static calls via `StaticTrait`
- `App::abort()`, `App::redirect()`, `App::on()`, `App::trigger()`, `App::message()` utility methods
- `App::module('x')` shorthand (will be replaced by Repository pattern)
- `$app->someService()` magic via `__call()` on Container

These remain functional for now. Plan to migrate them when Step 2.0.1e lands.
