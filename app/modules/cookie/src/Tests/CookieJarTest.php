<?php

declare(strict_types=1);

namespace Pagekit\Cookie\Tests;

use Pagekit\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;

class CookieJarTest extends TestCase
{
    private CookieJar $cookieJar;

    public function setUp(): void
    {
        $this->cookieJar = new CookieJar();
    }

    public function testSet(): void
    {
        $cookie = $this->cookieJar->set('testCookie', 'testValue');
        $this->assertEquals('testValue', $cookie->getValue());
    }

    public function testHasGet(): void
    {
        $this->cookieJar->set('testCookie', 'testValue');
        $this->assertTrue($this->cookieJar->has('testCookie'));
        $found = $this->cookieJar->get('testCookie');
        $this->assertNotNull($found);
        $this->assertEquals('testValue', $found->getValue());
    }

    public function testRemove(): void
    {
        $this->cookieJar->set('testCookie', 'testValue');
        $cookie = $this->cookieJar->remove('testCookie');
        $this->assertTrue($cookie->isCleared());
    }

    public function testGetQueuedCookies(): void
    {
        $this->cookieJar->set('cookie1', 'value1');
        $this->cookieJar->set('cookie2', 'value2');
        $this->assertCount(2, $this->cookieJar->getQueuedCookies());
    }
}
