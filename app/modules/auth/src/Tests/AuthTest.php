<?php

declare(strict_types=1);

namespace Pagekit\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Pagekit\Auth\Auth;
use Pagekit\Auth\UserInterface;
use Pagekit\Auth\UserProviderInterface;
use Pagekit\Auth\Handler\HandlerInterface;
use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Event\EventInterface;

class AuthTest extends TestCase
{
    protected ?Auth $auth = null;
    protected $events;
    protected $handler;

    public function setUp(): void
    {
        $this->events = $this->createMock(EventDispatcherInterface::class);
        $this->handler = $this->createMock(HandlerInterface::class);
        $this->auth = new Auth($this->events, $this->handler);
    }

    public function tearDown(): void
    {
        $this->auth = null;
        $this->events = null;
        $this->handler = null;
    }

    /**
     * Test that Auth instance can be created
     */
    public function testAuthInstantiation(): void
    {
        $this->assertInstanceOf(Auth::class, $this->auth);
    }

    /**
     * Test user getter and setter
     */
    public function testGetSetUser(): void
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getId')->willReturn('1');

        // Handler::write() is called when setting user
        $this->handler->expects($this->once())
            ->method('write')
            ->with('1', false);

        $this->auth->setUser($user);
        $this->assertSame($user, $this->auth->getUser());
    }

    /**
     * Test user provider getter and setter
     */
    public function testGetSetUserProvider(): void
    {
        $provider = $this->createMock(UserProviderInterface::class);

        $this->auth->setUserProvider($provider);
        $this->assertSame($provider, $this->auth->getUserProvider());
    }

    /**
     * Test getUserProvider throws when not set
     */
    public function testGetUserProviderThrowsWhenNotSet(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->auth->getUserProvider();
    }

    /**
     * Test login dispatches event and sets user
     */
    public function testLogin(): void
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getId')->willReturn('1');

        // Handler::write() is called
        $this->handler->expects($this->once())
            ->method('write')
            ->with('1', false);

        // Events::trigger() is called for LOGIN event
        $event = $this->createMock(EventInterface::class);
        $this->events->expects($this->once())
            ->method('trigger')
            ->willReturn($event);

        $result = $this->auth->login($user);
        $this->assertInstanceOf(EventInterface::class, $result);
    }

    /**
     * Test logout dispatches event and removes user
     */
    public function testLogout(): void
    {
        // First, set a user via internal state
        $user = $this->createMock(UserInterface::class);
        $user->method('getId')->willReturn('1');

        // setUser calls write
        $this->handler->method('write');
        $this->auth->setUser($user);

        // Logout calls:
        // 1. handler->read() via getUser() (user already cached, so not called)
        // 2. events->trigger() for LOGOUT event
        // 3. handler->destroy()
        $event = $this->createMock(EventInterface::class);
        $this->events->expects($this->once())
            ->method('trigger')
            ->willReturn($event);

        $this->handler->expects($this->once())
            ->method('destroy');

        $result = $this->auth->logout();
        $this->assertInstanceOf(EventInterface::class, $result);
        $this->assertNull($this->auth->getUser());
    }

    /**
     * Test removeUser clears user and destroys handler session
     */
    public function testRemoveUser(): void
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getId')->willReturn('1');

        $this->handler->method('write');
        $this->auth->setUser($user);

        $this->handler->expects($this->once())
            ->method('destroy');

        $this->auth->removeUser();
        
        // After removeUser, handler->read() returns null, so getUser returns null
        $this->handler->method('read')->willReturn(null);
        $this->assertNull($this->auth->getUser());
    }

    /**
     * Test getUser reads from handler when no user cached
     */
    public function testGetUserReadsFromHandler(): void
    {
        $user = $this->createMock(UserInterface::class);

        $this->handler->expects($this->once())
            ->method('read')
            ->willReturn(42);

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($user);

        $this->auth->setUserProvider($provider);

        $result = $this->auth->getUser();
        $this->assertSame($user, $result);
    }

    /**
     * Test getUser returns null when handler has no user
     */
    public function testGetUserReturnsNullWhenNoSession(): void
    {
        $this->handler->expects($this->once())
            ->method('read')
            ->willReturn(null);

        $this->assertNull($this->auth->getUser());
    }
}
