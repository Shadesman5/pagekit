<?php

namespace Pagekit\Tests;

use PHPUnit\Framework\TestCase;
use Pagekit\Application;
use Pagekit\Container;
use Pagekit\Container\NotFoundException;
use Pagekit\Container\ContainerException;
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
     * Test that Container provides PSR-11 compatibility via adapter
     */
    public function testImplementsPsr11Interface(): void
    {
        $adapter = $this->container->getPsr11Adapter();
        $this->assertInstanceOf(ContainerInterface::class, $adapter);
    }

    /**
     * Test PSR-11 compatible hasService() method
     */
    public function testHasMethod(): void
    {
        // Test non-existent service
        $this->assertFalse($this->container->hasService('non.existent'));

        // Test scalar value
        $this->container['test.scalar'] = 'value';
        $this->assertTrue($this->container->hasService('test.scalar'));

        // Test service factory
        $this->container['test.service'] = fn() => new \stdClass();
        $this->assertTrue($this->container->hasService('test.service'));
    }

    /**
     * Test PSR-11 compatible getService() method with scalar values
     */
    public function testGetScalarValue(): void
    {
        $this->container['test.string'] = 'hello';
        $this->container['test.number'] = 42;
        $this->container['test.array'] = ['a', 'b', 'c'];

        $this->assertEquals('hello', $this->container->getService('test.string'));
        $this->assertEquals(42, $this->container->getService('test.number'));
        $this->assertEquals(['a', 'b', 'c'], $this->container->getService('test.array'));
    }

    /**
     * Test PSR-11 compatible getService() method with service factories
     */
    public function testGetServiceFactory(): void
    {
        $this->container['test.service'] = function ($container) {
            $obj = new \stdClass();
            $obj->created = true;
            return $obj;
        };

        $service = $this->container->getService('test.service');
        $this->assertInstanceOf(\stdClass::class, $service);
        $this->assertTrue($service->created);

        // Test that same instance is returned (singleton behavior)
        $service2 = $this->container->getService('test.service');
        $this->assertSame($service, $service2);
    }

    /**
     * Test PSR-11 compatible getService() throws NotFoundException for missing services
     */
    public function testGetThrowsNotFoundException(): void
    {
        $this->expectException(NotFoundException::class);
        $this->container->getService('non.existent.service');
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

        $service1 = $this->container->getService('test.factory');
        $service2 = $this->container->getService('test.factory');

        $this->assertNotSame($service1, $service2);
        $this->assertEquals(1, $service1->id);
        $this->assertEquals(2, $service2->id);
    }

    /**
     * Test backward compatibility with ArrayAccess
     */
    public function testBackwardCompatibilityWithArrayAccess(): void
    {
        // Test setting via ArrayAccess
        $this->container['old.style'] = 'value';

        // Test getting via PSR-11 compatible methods
        $this->assertTrue($this->container->hasService('old.style'));
        $this->assertEquals('value', $this->container->getService('old.style'));

        // Test setting via offsetSet
        $this->container->offsetSet('another.old', 'test');
        $this->assertTrue($this->container->hasService('another.old'));
        $this->assertEquals('test', $this->container->getService('another.old'));
    }

    /**
     * Test Application class PSR-11 compatibility
     */
    public function testApplicationPsr11Compatibility(): void
    {
        $app = new Application();
        
        // Application can get a PSR-11 adapter
        $adapter = $app->getPsr11Adapter();
        $this->assertInstanceOf(ContainerInterface::class, $adapter);

        // Test built-in services via adapter
        $this->assertTrue($adapter->has('events'));
        $this->assertTrue($adapter->has('module'));

        // Test getting services via adapter
        $events = $adapter->get('events');
        $this->assertNotNull($events);
        
        // Test direct methods on Application
        $this->assertTrue($app->hasService('events'));
        $events2 = $app->getService('events');
        $this->assertSame($events, $events2);
    }

    /**
     * Test service extension
     */
    public function testServiceExtension(): void
    {
        $this->container['test.base'] = fn() => 'base';

        $this->container->extend('test.base', function ($base, $container) {
            return $base . '-extended';
        });

        $this->assertEquals('base-extended', $this->container->getService('test.base'));
    }

    /**
     * Test that getService() handles service creation errors properly
     */
    public function testGetHandlesServiceCreationErrors(): void
    {
        $this->container['broken.service'] = function () {
            throw new \RuntimeException('Service creation failed');
        };

        try {
            $this->container->getService('broken.service');
            $this->fail('Expected ContainerException was not thrown');
        } catch (ContainerException $e) {
            $this->assertStringContainsString('Error while retrieving', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    /**
     * Test container keys() method still works
     */
    public function testKeysMethod(): void
    {
        $this->container['service1'] = 'value1';
        $this->container['service2'] = 'value2';
        $this->container['service3'] = 'value3';

        $keys = $this->container->keys();
        $this->assertContains('service1', $keys);
        $this->assertContains('service2', $keys);
        $this->assertContains('service3', $keys);
    }

    /**
     * Test raw() method still works
     */
    public function testRawMethod(): void
    {
        $closure = fn() => 'resolved';
        $this->container['test.closure'] = $closure;

        // Get raw closure before resolution
        $raw = $this->container->raw('test.closure');
        $this->assertSame($closure, $raw);

        // After resolution, raw should still return the closure
        $resolved = $this->container->getService('test.closure');
        $this->assertEquals('resolved', $resolved);
        
        $raw2 = $this->container->raw('test.closure');
        $this->assertSame($closure, $raw2);
    }
}