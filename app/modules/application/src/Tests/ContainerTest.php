<?php

namespace Pagekit\Tests;

use PHPUnit\Framework\TestCase;
use Pagekit\Container;

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
     * Test ArrayAccess implementation
     */
    public function testArrayAccessImplementation(): void
    {
        // Test isset (offsetExists)
        $this->assertFalse(isset($this->container['test']));
        
        // Test set (offsetSet)
        $this->container['test'] = 'value';
        $this->assertTrue(isset($this->container['test']));
        
        // Test get (offsetGet)
        $this->assertEquals('value', $this->container['test']);
        
        // Test unset (offsetUnset)
        unset($this->container['test']);
        $this->assertFalse(isset($this->container['test']));
    }

    /**
     * Test service as closure
     */
    public function testServiceAsClosure(): void
    {
        $this->container['service'] = function ($c) {
            $obj = new \stdClass();
            $obj->container = $c;
            return $obj;
        };

        $service = $this->container['service'];
        $this->assertInstanceOf(\stdClass::class, $service);
        $this->assertSame($this->container, $service->container);

        // Test singleton behavior
        $service2 = $this->container['service'];
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

        $this->assertEquals(1, $this->container['factory']);
        $this->assertEquals(2, $this->container['factory']);
        $this->assertEquals(3, $this->container['factory']);
    }

    /**
     * Test extend method
     */
    public function testExtend(): void
    {
        $this->container['service'] = fn() => 'original';

        $this->container->extend('service', function ($original, $c) {
            return $original . '-extended';
        });

        $this->assertEquals('original-extended', $this->container['service']);
    }

    /**
     * Test extend throws exception for non-existent service
     */
    public function testExtendThrowsExceptionForNonExistent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"non.existent" is not defined');
        
        $this->container->extend('non.existent', fn($s) => $s);
    }

    /**
     * Test extend throws exception for non-closure service
     */
    public function testExtendThrowsExceptionForNonClosure(): void
    {
        $this->container['scalar'] = 'value';
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"scalar" service definition is not a Closure');
        
        $this->container->extend('scalar', fn($s) => $s);
    }

    /**
     * Test raw method
     */
    public function testRaw(): void
    {
        $closure = fn() => 'value';
        $this->container['service'] = $closure;

        // Before resolution
        $this->assertSame($closure, $this->container->raw('service'));

        // Resolve service
        $value = $this->container['service'];
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

        $this->container['a'] = 1;
        $this->container['b'] = 2;
        $this->container['c'] = 3;

        $keys = $this->container->keys();
        sort($keys);
        $this->assertEquals(['a', 'b', 'c'], $keys);
    }

    /**
     * Test offsetGet throws exception for undefined
     */
    public function testOffsetGetThrowsExceptionForUndefined(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"undefined" is not defined');
        
        $value = $this->container['undefined'];
    }

    /**
     * Test offsetSet throws exception when overriding resolved service
     */
    public function testOffsetSetThrowsExceptionWhenOverriding(): void
    {
        $this->container['service'] = fn() => 'value';
        
        // Resolve the service
        $value = $this->container['service'];
        
        // Try to override - should throw exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot override service definition "service"');
        
        $this->container['service'] = 'new value';
    }

    /**
     * Test constructor with initial values
     */
    public function testConstructorWithInitialValues(): void
    {
        $container = new Container([
            'param1' => 'value1',
            'param2' => 'value2',
            'service' => fn() => 'service_value'
        ]);

        $this->assertEquals('value1', $container['param1']);
        $this->assertEquals('value2', $container['param2']);
        $this->assertEquals('service_value', $container['service']);
    }

    /**
     * Test __call method
     */
    public function testCallMethod(): void
    {
        // For __call to work with arguments, the service must return a callable
        $this->container['callable'] = function ($container) {
            return function ($arg1, $arg2) {
                return $arg1 . '-' . $arg2;
            };
        };

        // Call with arguments
        $result = $this->container->callable('hello', 'world');
        $this->assertEquals('hello-world', $result);

        // Call without arguments returns the resolved service
        $this->container['service'] = fn() => 'value';
        $result = $this->container->service();
        $this->assertEquals('value', $result);
    }
}