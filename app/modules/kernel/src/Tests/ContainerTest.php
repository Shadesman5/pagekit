<?php

declare(strict_types=1);

namespace Pagekit\Tests;

use Pagekit\Container;
use Pagekit\Container\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Test Container basic functionality
 */
class ContainerTest extends TestCase
{
    protected Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    /**
     * Test set()/get()/has() methods
     */
    public function testSetAndGetMethods(): void
    {
        $this->assertFalse($this->container->has('test'));

        $this->container->set('test', 'value');
        $this->assertTrue($this->container->has('test'));

        $this->assertEquals('value', $this->container->get('test'));
    }

    /**
     * Test set() with scalar, closure, and factory override prevention
     */
    public function testSetMethod(): void
    {
        $this->container->set('scalar', 42);
        $this->assertEquals(42, $this->container->get('scalar'));

        $this->container->set('string', 'hello');
        $this->assertEquals('hello', $this->container->get('string'));

        $closure = fn () => 'from_closure';
        $this->container->set('closure_service', $closure);
        $this->assertEquals('from_closure', $this->container->get('closure_service'));

        // Resolved service cannot be overridden
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot override service definition "closure_service"');
        $this->container->set('closure_service', fn () => 'new');
    }

    /**
     * Test service as closure
     */
    public function testServiceAsClosure(): void
    {
        $this->container->set('service', function ($c) {
            $obj = new \stdClass();
            $obj->container = $c;

            return $obj;
        });

        $service = $this->container->get('service');
        $this->assertInstanceOf(\stdClass::class, $service);
        $this->assertSame($this->container, $service->container);

        // Test singleton behavior
        $service2 = $this->container->get('service');
        $this->assertSame($service, $service2);
    }

    /**
     * Test factory pattern
     */
    public function testFactory(): void
    {
        $counter = 0;
        $this->container->factory('factory', function () use (&$counter) {
            $counter++;

            return $counter;
        });

        $this->assertEquals(1, $this->container->get('factory'));
        $this->assertEquals(2, $this->container->get('factory'));
        $this->assertEquals(3, $this->container->get('factory'));
    }

    /**
     * Test extend method
     */
    public function testExtend(): void
    {
        $this->container->set('service', fn () => 'original');

        $this->container->extend('service', function ($original, $c) {
            return $original . '-extended';
        });

        $this->assertEquals('original-extended', $this->container->get('service'));
    }

    /**
     * Test extend on already-resolved service updates raw() consistently.
     */
    public function testExtendOnResolvedServiceUpdatesRaw(): void
    {
        $this->container->set('service', fn () => 'original');

        $resolved = $this->container->get('service');
        $this->assertEquals('original', $resolved);

        $this->container->extend('service', function ($original, $c) {
            return $original . '-decorated';
        });

        $this->assertEquals('original-decorated', $this->container->get('service'));
        $this->assertEquals('original-decorated', $this->container->raw('service'));
    }

    /**
     * Test extend throws exception for non-existent service
     */
    public function testExtendThrowsExceptionForNonExistent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"non.existent" is not defined');

        $this->container->extend('non.existent', fn ($s) => $s);
    }

    /**
     * Test extend throws exception for non-closure service
     */
    public function testExtendThrowsExceptionForNonClosure(): void
    {
        $this->container->set('scalar', 'value');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"scalar" service definition is not a Closure');

        $this->container->extend('scalar', fn ($s) => $s);
    }

    /**
     * Test raw method
     */
    public function testRaw(): void
    {
        $closure = fn () => 'value';
        $this->container->set('service', $closure);

        // Before resolution
        $this->assertSame($closure, $this->container->raw('service'));

        // Resolve service
        $value = $this->container->get('service');
        $this->assertEquals('value', $value);

        // After resolution, raw still returns closure
        $this->assertSame($closure, $this->container->raw('service'));
    }

    /**
     * Test raw throws exception for undefined
     */
    public function testRawThrowsExceptionForUndefined(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"undefined" is not defined');

        $this->container->raw('undefined');
    }

    /**
     * Test keys method
     */
    public function testKeys(): void
    {
        $this->assertEquals([], $this->container->keys());

        $this->container->set('a', 1);
        $this->container->set('b', 2);
        $this->container->set('c', 3);

        $keys = $this->container->keys();
        sort($keys);
        $this->assertEquals(['a', 'b', 'c'], $keys);
    }

    /**
     * Test get() throws NotFoundException for undefined
     */
    public function testGetThrowsNotFoundException(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('"undefined" is not defined');

        $this->container->get('undefined');
    }

    public function testGetThrowsNotFoundExceptionInterface(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);

        $this->container->get('undefined');
    }

    /**
     * Test set() throws exception when overriding resolved service
     */
    public function testSetThrowsExceptionWhenOverriding(): void
    {
        $this->container->set('service', fn () => 'value');

        // Resolve the service
        $value = $this->container->get('service');

        // Try to override - should throw exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot override service definition "service"');

        $this->container->set('service', 'new value');
    }

    /**
     * Test constructor with initial values
     */
    public function testConstructorWithInitialValues(): void
    {
        $container = new Container([
            'param1' => 'value1',
            'param2' => 'value2',
            'service' => fn () => 'service_value',
        ]);

        $this->assertEquals('value1', $container->get('param1'));
        $this->assertEquals('value2', $container->get('param2'));
        $this->assertEquals('service_value', $container->get('service'));
    }

}
