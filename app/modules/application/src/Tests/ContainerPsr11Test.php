<?php

declare(strict_types=1);

namespace Pagekit\Tests;

use Pagekit\Application;
use Pagekit\Container;
use Pagekit\Container\ContainerException;
use Pagekit\Container\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Test PSR-11 Container Compliance
 */
class ContainerPsr11Test extends TestCase
{
    protected Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    /**
     * Test that Container directly implements PSR-11 ContainerInterface
     */
    public function testImplementsPsr11Interface(): void
    {
        $this->assertInstanceOf(ContainerInterface::class, $this->container);
    }

    /**
     * Test PSR-11 has() method
     */
    public function testHasMethod(): void
    {
        $this->assertFalse($this->container->has('non.existent'));

        $this->container->set('test.scalar', 'value');
        $this->assertTrue($this->container->has('test.scalar'));

        $this->container->set('test.service', fn () => new \stdClass());
        $this->assertTrue($this->container->has('test.service'));
    }

    /**
     * Test PSR-11 get() method with scalar values
     */
    public function testGetScalarValue(): void
    {
        $this->container->set('test.string', 'hello');
        $this->container->set('test.number', 42);
        $this->container->set('test.array', ['a', 'b', 'c']);

        $this->assertEquals('hello', $this->container->get('test.string'));
        $this->assertEquals(42, $this->container->get('test.number'));
        $this->assertEquals(['a', 'b', 'c'], $this->container->get('test.array'));
    }

    /**
     * Test PSR-11 get() method with service factories (closure resolution)
     */
    public function testGetServiceFactory(): void
    {
        $this->container->set('test.service', function ($container) {
            $obj = new \stdClass();
            $obj->created = true;

            return $obj;
        });

        $service = $this->container->get('test.service');
        $this->assertInstanceOf(\stdClass::class, $service);
        $this->assertTrue($service->created);

        $service2 = $this->container->get('test.service');
        $this->assertSame($service, $service2);
    }

    /**
     * Test PSR-11 get() throws NotFoundException for missing services
     */
    public function testGetThrowsNotFoundException(): void
    {
        $this->expectException(NotFoundException::class);
        $this->container->get('non.existent.service');
    }

    /**
     * Test NotFoundException implements PSR-11 NotFoundExceptionInterface
     */
    public function testNotFoundExceptionImplementsPsr11(): void
    {
        try {
            $this->container->get('missing');
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertInstanceOf(\Psr\Container\NotFoundExceptionInterface::class, $e);
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
        }
    }

    /**
     * Test ContainerException implements PSR-11 ContainerExceptionInterface
     */
    public function testContainerExceptionImplementsPsr11(): void
    {
        $e = new ContainerException('test');
        $this->assertInstanceOf(\Psr\Container\ContainerExceptionInterface::class, $e);
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    /**
     * Test factory services (non-singleton)
     */
    public function testFactoryServices(): void
    {
        $counter = 0;
        $this->container->factory('test.factory', function () use (&$counter) {
            $counter++;
            $obj = new \stdClass();
            $obj->id = $counter;

            return $obj;
        });

        $service1 = $this->container->get('test.factory');
        $service2 = $this->container->get('test.factory');

        $this->assertNotSame($service1, $service2);
        $this->assertEquals(1, $service1->id);
        $this->assertEquals(2, $service2->id);
    }

    /**
     * Test set()/get()/has() methods work together
     */
    public function testSetGetHasMethods(): void
    {
        $this->container->set('old.style', 'value');

        $this->assertTrue($this->container->has('old.style'));
        $this->assertEquals('value', $this->container->get('old.style'));

        $this->container->set('another.old', 'test');
        $this->assertTrue($this->container->has('another.old'));
        $this->assertEquals('test', $this->container->get('another.old'));
    }

    /**
     * Test Application class implements ContainerInterface directly
     */
    public function testApplicationImplementsContainerInterface(): void
    {
        $app = new Application();

        $this->assertInstanceOf(ContainerInterface::class, $app);

        $this->assertTrue($app->has('events'));
        $this->assertTrue($app->has('module'));

        $events = $app->get('events');
        $this->assertNotNull($events);

        $events2 = $app->get('events');
        $this->assertSame($events, $events2);
    }

    /**
     * Test service extension
     */
    public function testServiceExtension(): void
    {
        $this->container->set('test.base', fn () => 'base');

        $this->container->extend('test.base', function ($base, $container) {
            return $base . '-extended';
        });

        $this->assertEquals('base-extended', $this->container->get('test.base'));
    }

    /**
     * Test that get() handles service creation errors properly
     */
    public function testGetHandlesServiceCreationErrors(): void
    {
        $this->container->set('broken.service', function () {
            throw new \RuntimeException('Service creation failed');
        });

        try {
            $this->container->get('broken.service');
            $this->fail('Expected ContainerException was not thrown');
        } catch (ContainerException $e) {
            $this->assertStringContainsString('Error while retrieving', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    /**
     * Test container keys() method
     */
    public function testKeysMethod(): void
    {
        $this->container->set('service1', 'value1');
        $this->container->set('service2', 'value2');
        $this->container->set('service3', 'value3');

        $keys = $this->container->keys();
        $this->assertContains('service1', $keys);
        $this->assertContains('service2', $keys);
        $this->assertContains('service3', $keys);
    }

    /**
     * Test raw() method
     */
    public function testRawMethod(): void
    {
        $closure = fn () => 'resolved';
        $this->container->set('test.closure', $closure);

        $raw = $this->container->raw('test.closure');
        $this->assertSame($closure, $raw);

        $resolved = $this->container->get('test.closure');
        $this->assertEquals('resolved', $resolved);

        $raw2 = $this->container->raw('test.closure');
        $this->assertSame($closure, $raw2);
    }
}
