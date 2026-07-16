<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Tests;

use Pagekit\Kernel\Controller\ControllerResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Properties below are intentionally typed `mixed` to verify the ControllerResolver resolves
 * constructor parameters by NAME (matching container service IDs), not by TYPE.
 *
 * @phpstan-type ResolverTestService mixed
 */
class ControllerResolverTest extends TestCase
{
    public function testControllerWithNoConstructor(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $resolver = new ControllerResolver($container);

        $method = new \ReflectionMethod($resolver, 'instantiateController');
        $controller = $method->invoke($resolver, NoConstructorController::class);

        $this->assertInstanceOf(NoConstructorController::class, $controller);
        $this->assertSame('ok', $controller->indexAction());
    }

    public function testControllerWithContainerServices(): void
    {
        $dbMock = new \stdClass();
        $dbMock->name = 'database';
        $cacheMock = new \stdClass();
        $cacheMock->name = 'cache';

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            ['db', true],
            ['cache', true],
        ]);
        $container->method('get')->willReturnMap([
            ['db', $dbMock],
            ['cache', $cacheMock],
        ]);

        $resolver = new ControllerResolver($container);

        $method = new \ReflectionMethod($resolver, 'instantiateController');
        $controller = $method->invoke($resolver, ServiceInjectedController::class);

        $this->assertInstanceOf(ServiceInjectedController::class, $controller);
        $this->assertSame($dbMock, $controller->getDb());
        $this->assertSame($cacheMock, $controller->getCache());
    }

    public function testControllerWithDefaultValues(): void
    {
        $dbMock = new \stdClass();
        $dbMock->name = 'database';

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            ['db', true],
            ['mode', false],
        ]);
        $container->method('get')->willReturnMap([
            ['db', $dbMock],
        ]);

        $resolver = new ControllerResolver($container);

        $method = new \ReflectionMethod($resolver, 'instantiateController');
        $controller = $method->invoke($resolver, DefaultValueController::class);

        $this->assertInstanceOf(DefaultValueController::class, $controller);
        $this->assertSame($dbMock, $controller->getDb());
        $this->assertSame('default', $controller->getMode());
    }

    public function testControllerWithUnknownRequiredParam(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $resolver = new ControllerResolver($container);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/\$unknownService/');

        $method = new \ReflectionMethod($resolver, 'instantiateController');
        $method->invoke($resolver, UnresolvableController::class);
    }

    public function testControllerWithMixedParams(): void
    {
        $dbMock = new \stdClass();
        $dbMock->name = 'database';
        $cacheMock = new \stdClass();
        $cacheMock->name = 'cache';

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            ['db', true],
            ['mode', false],
            ['cache', true],
        ]);
        $container->method('get')->willReturnMap([
            ['db', $dbMock],
            ['cache', $cacheMock],
        ]);

        $resolver = new ControllerResolver($container);

        $method = new \ReflectionMethod($resolver, 'instantiateController');
        $controller = $method->invoke($resolver, MixedParamsController::class);

        $this->assertInstanceOf(MixedParamsController::class, $controller);
        $this->assertSame($dbMock, $controller->getDb());
        $this->assertSame('production', $controller->getMode());
        $this->assertSame($cacheMock, $controller->getCache());
    }

    public function testIntegrationResolveControllerFromRequest(): void
    {
        $dbMock = new \stdClass();
        $dbMock->name = 'database';
        $cacheMock = new \stdClass();
        $cacheMock->name = 'cache';

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            ['db', true],
            ['cache', true],
        ]);
        $container->method('get')->willReturnMap([
            ['db', $dbMock],
            ['cache', $cacheMock],
        ]);

        $resolver = new ControllerResolver($container);

        $request = new Request();
        $request->attributes->set('_controller', ServiceInjectedController::class . '::getDb');

        $callable = $resolver->getController($request);

        $this->assertIsArray($callable);
        $this->assertCount(2, $callable);
        $this->assertInstanceOf(ServiceInjectedController::class, $callable[0]);
        $this->assertSame('getDb', $callable[1]);
        $this->assertSame($dbMock, $callable[0]->getDb());
        $this->assertSame($cacheMock, $callable[0]->getCache());
    }
}

class NoConstructorController
{
    public function indexAction(): string
    {
        return 'ok';
    }
}

class ServiceInjectedController
{
    public function __construct(
        private readonly mixed $db,
        private readonly mixed $cache
    ) {
    }

    public function getDb(): mixed
    {
        return $this->db;
    }

    public function getCache(): mixed
    {
        return $this->cache;
    }
}

class DefaultValueController
{
    public function __construct(
        private readonly mixed $db,
        private readonly string $mode = 'default'
    ) {
    }

    public function getDb(): mixed
    {
        return $this->db;
    }

    public function getMode(): string
    {
        return $this->mode;
    }
}

class UnresolvableController
{
    public function __construct(private readonly mixed $unknownService)
    {
    }
}

class MixedParamsController
{
    public function __construct(
        private readonly mixed $db,
        private readonly string $mode = 'production',
        private readonly mixed $cache = null
    ) {
    }

    public function getDb(): mixed
    {
        return $this->db;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getCache(): mixed
    {
        return $this->cache;
    }
}
