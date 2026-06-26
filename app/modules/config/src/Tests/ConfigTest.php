<?php

declare(strict_types=1);

namespace Pagekit\Config\Tests;

use Pagekit\Config\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private Config $config;

    /** @var array<string, mixed> */
    private array $values;

    public function setUp(): void
    {
        $values = [
            'foo' => [
                'bar' => 'test',
            ],
        ];

        $this->config = new Config($values);
        $this->values = $values;
    }

    public function testHas(): void
    {
        $this->assertTrue($this->config->has('foo'));
        $this->assertTrue(!$this->config->has('none'));
    }

    public function testGet(): void
    {
        $this->assertEquals($this->config->get('foo.bar'), 'test');
    }

    public function testToArray(): void
    {
        $this->assertEquals($this->config->toArray(), $this->values);
    }

    public function testSet(): void
    {
        $this->config->set('foo.bar2', 'test2');
        $this->assertEquals($this->config->get('foo.bar2'), 'test2');
        $this->config->offsetSet('foo.bar', 'test3');
        $this->assertTrue($this->config->offsetExists('foo.bar'));
        $this->assertEquals($this->config->offsetGet('foo.bar'), 'test3');
        $this->config->offsetUnset('foo.bar');
        $this->assertEquals($this->config->offsetGet('foo.bar'), null);
    }

    public function testDump(): void
    {
        $this->assertIsString($this->config->dump());
    }
}
