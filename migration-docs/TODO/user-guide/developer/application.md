# Application

<p class="uk-article-lead">The *Application* is Pagekit's service container and the central object every [module](modules.md) interacts with. The container implements **PSR-11** (`Psr\Container\ContainerInterface`) and exposes Pagekit's services — database, cache, events, router, modules — via a single, typed API.</p>

<ul class="uk-list">
    <li><a href="#accessing-a-service">Accessing a Service</a></li>
    <li><a href="#defining-a-service">Defining a Service</a></li>
    <li><a href="#extending-a-service">Extending a Service</a></li>
    <li><a href="#common-services">Common Services</a></li>
</ul>

## Accessing a Service

The recommended way to access services is **constructor injection** with typed properties. The router resolves controllers through the container and injects all declared dependencies automatically.

```php
namespace Pagekit\Hello\Controller;

use Pagekit\Database\Connection;
use Pagekit\Routing\Attribute\Route;
use Psr\Container\ContainerInterface;

#[Route('/hello')]
class HelloController
{
    public function __construct(
        private readonly Connection $db,
        private readonly ContainerInterface $container,
    ) {}

    public function indexAction(): array
    {
        $posts = $this->db->createQueryBuilder()
            ->select('*')
            ->from('@blog_post')
            ->fetchAllAssociative();

        return ['posts' => $posts];
    }
}
```

In contexts where constructor injection is not available — typically a module bootstrap closure — call `get()` on the `Application` instance directly:

```php
use Pagekit\Application;

return [

    'name' => 'hello',

    'main' => function (Application $app) {
        $cache = $app->get('cache');
        $db    = $app->get('db');

        if ($app->has('mailer')) {
            $mailer = $app->get('mailer');
        }
    },

];
```

The container exposes the full PSR-11 surface plus a small set of helpers:

| Method | Description |
|--------|-------------|
| `$app->get(string $id): mixed` | Resolve a service. Throws `Pagekit\Container\NotFoundException` if the id is not registered. |
| `$app->has(string $id): bool` | Check whether a service id is registered. |
| `$app->set(string $id, mixed $value): void` | Register a service value or factory closure. |
| `$app->factory(string $id, \Closure $closure): void` | Register a factory service that resolves to a fresh instance on every `get()`. |
| `$app->extend(string $id, \Closure $closure): void` | Decorate an existing service definition. |
| `$app->keys(): array` | List all registered service ids. |
| `$app->remove(string $id): void` | Unregister a service. |

## Defining a Service

Services are registered as **lazy closures**. The closure receives the container as its only argument and is executed the first time the service is resolved. The result is cached, so subsequent `get()` calls return the same instance.

The canonical place to register services is the `main()` method of a module class:

```php
namespace Acme\Hello;

use Pagekit\Application;
use Pagekit\Module\Module;

class HelloModule extends Module
{
    public function main(Application $app): void
    {
        $app->set('hello.client', function (Application $app) {
            return new HttpClient($app->get('config')->get('hello.endpoint'));
        });
    }
}
```

Services may also be registered inline from a module bootstrap closure (see [modules](modules.md)):

```php
'main' => function (Application $app) {
    $app->set('hello.client', fn (Application $app) =>
        new HttpClient($app->get('config')->get('hello.endpoint'))
    );
},
```

For services that must be **re-created on every access** (for example a new logger context per request), use `factory()`:

```php
$app->factory('hello.logger', fn (Application $app) =>
    new Logger($app->get('config'))
);
```

## Extending a Service

`extend()` lets you decorate an already-registered service. The closure receives the original instance and the container, and must return the replacement.

```php
$app->extend('events', function ($events, Application $app) {
    $events->on('kernel.request', function () { /* ... */ });

    return $events;
});
```

Trying to `extend()` a service that has not been registered throws `\InvalidArgumentException`. Factory services (registered via `factory()`) cannot be extended in place — wrap the factory closure itself instead.

## Common Services

| Service id | Type / Class | Description |
|------------|--------------|-------------|
| `app` | `Pagekit\Application` | The application instance itself. |
| `db` | `Pagekit\Database\Connection` (Doctrine DBAL 3.x) | Default database connection and query builder factory. |
| `cache` | `Psr\Cache\CacheItemPoolInterface` (Symfony Cache adapter) | PSR-6 cache pool. |
| `events` | `Pagekit\Event\EventDispatcher` | Pagekit's event dispatcher. See [events](events.md). |
| `module` | `Pagekit\Module\ModuleManager` | Module loader and lifecycle manager. |
| `migration` | `Pagekit\Migration\MigrationService` | Doctrine Migrations 3.x runner for core and extensions. |
| `router` | `Pagekit\Routing\Router` | URL matcher and generator. |
| `request` | `Symfony\Component\HttpFoundation\Request` | The current HTTP request. |
| `config` | `Pagekit\Config\Config` | Configuration manager. |
| `user` | `Pagekit\User\Model\User` | The currently authenticated user (or anonymous). |
| `view` | `Pagekit\View\View` | View renderer and template helpers. |

Run `$app->keys()` at runtime to inspect the full list of registered services in your installation.
