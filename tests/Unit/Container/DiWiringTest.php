<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Container;

use Pagekit\Application;
use Pagekit\Cache\CacheModule;
use Pagekit\User\UserModule;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Finder\Finder;

/**
 * DI-wiring integration tests for the PSR-11 container + Stage-3 module classes.
 *
 * Covers the DI-wiring audit items left open after the Step 2.1.4 ControllerResolver
 * work (ticket 2.1.9, Checklist Step 9):
 *   (a) the module app-resolution mechanism that replaced the legacy
 *       `$app ?? App::getInstance()` static fallback — Stage-3 modules resolve the
 *       *injected* Application (stored in main()) and throw when accessed before
 *       boot, so there is no silent reach for a global singleton;
 *   (b) factory-service freshness — a service registered via factory() (e.g. the
 *       `finder`) yields a fresh instance per resolution, while a shared service
 *       closure yields one cached singleton;
 *   (c) container resolution of a couple of Stage-3-migrated Module classes.
 *
 * ControllerResolver constructor-injection is already covered by
 * Pagekit\Kernel\Tests\ControllerResolverTest (6 tests) and is NOT retested here.
 */
class DiWiringTest extends TestCase
{
    // ------------------------------------------------------------------
    // (a) Module app-resolution — the former $app ?? App::getInstance() fallback
    // ------------------------------------------------------------------

    public function testContainerResolvesAppServiceToItself(): void
    {
        $app = new Application();

        // The Application registers itself as the `app` service in its constructor.
        // This self-reference is the modern DI replacement for the removed
        // `App::getInstance()` global singleton.
        self::assertSame($app, $app->get('app'));
        self::assertInstanceOf(Application::class, $app->get('app'));
    }

    public function testStageThreeModuleWiresIntoInjectedApplication(): void
    {
        $injected = new Application();
        $other = new Application();

        $module = new CacheModule($this->cacheModuleOptions());
        $module->main($injected);

        // Services land in the *injected* container, never in an ambient global one.
        self::assertTrue($injected->has('cache'));
        self::assertFalse($other->has('cache'));
    }

    public function testCacheModuleThrowsWhenUsedBeforeBoot(): void
    {
        $module = new CacheModule($this->cacheModuleOptions());

        // No silent App::getInstance() fallback: an unbooted module refuses to
        // resolve an application rather than reaching for a global one.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CacheModule::main() has not been called yet.');

        $module->clearCache();
    }

    public function testUserModuleThrowsWhenUsedBeforeBoot(): void
    {
        $module = new UserModule(['name' => 'user', 'path' => '', 'config' => []]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('UserModule::main() has not been called yet.');

        $module->getPermissions();
    }

    // ------------------------------------------------------------------
    // (b) Factory-service freshness vs. shared singleton
    // ------------------------------------------------------------------

    public function testFactoryServiceReturnsFreshInstancePerResolution(): void
    {
        $app = new Application();

        // Mirrors SystemModule's registration (app/system/src/SystemModule.php).
        $app->factory('finder', fn () => Finder::create());

        $first = $app->get('finder');
        $second = $app->get('finder');

        self::assertInstanceOf(Finder::class, $first);
        self::assertInstanceOf(Finder::class, $second);
        self::assertNotSame($first, $second, 'factory() must yield a fresh instance per resolution');
    }

    public function testSharedServiceReturnsSameInstancePerResolution(): void
    {
        $app = new Application();

        // Same producing closure, registered via set() rather than factory().
        $app->set('shared_finder', fn () => Finder::create());

        $first = $app->get('shared_finder');
        $second = $app->get('shared_finder');

        self::assertInstanceOf(Finder::class, $first);
        self::assertSame($first, $second, 'set() must cache and share a single instance');
    }

    // ------------------------------------------------------------------
    // (c) Container resolution of Stage-3-migrated Module classes
    // ------------------------------------------------------------------

    public function testCacheModuleRegistersResolvableCachePool(): void
    {
        $app = new Application();

        $module = new CacheModule($this->cacheModuleOptions());
        $module->main($app);

        $pool = $app->get('cache');

        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
    }

    public function testUserModuleRegistersResolvableUserService(): void
    {
        $app = new Application();

        $currentUser = new \stdClass();
        $currentUser->id = 42;

        // Stub the `auth` dependency the `user` factory closure pulls from the
        // container — proves the module's registered service resolves through the
        // injected container's own service graph.
        $app->set('auth', new class ($currentUser) {
            public function __construct(private readonly object $user)
            {
            }

            public function getUser(): object
            {
                return $this->user;
            }
        });

        $module = new UserModule(['name' => 'user', 'path' => '', 'config' => []]);
        $module->main($app);

        self::assertTrue($app->has('user'));
        self::assertSame($currentUser, $app->get('user'));
    }

    /**
     * Minimal in-memory (array storage) cache-module options.
     *
     * @return array<string, mixed>
     */
    private function cacheModuleOptions(): array
    {
        return [
            'name' => 'cache',
            'path' => '',
            'config' => [
                'caches' => [
                    'cache' => ['storage' => 'array'],
                ],
                'nocache' => false,
            ],
        ];
    }
}
