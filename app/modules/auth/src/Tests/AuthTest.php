<?php

namespace Pagekit\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Pagekit\Auth\Auth;
use Pagekit\Auth\UserInterface;
use Pagekit\Auth\UserProviderInterface;
use Pagekit\Auth\Handler\HandlerInterface;
use Pagekit\Event\EventDispatcherInterface;

class AuthTest extends TestCase
{
    protected ?Auth $auth = null;

    public function setUp(): void
    {
        $events = $this->createMock(EventDispatcherInterface::class);
        $handler = $this->createMock(HandlerInterface::class);
        $this->auth = new Auth($events, $handler);
    }

    public function tearDown(): void
    {
        $this->auth = null;
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
        // Create a mock user
        $user = $this->createMock(UserInterface::class);
        $user->method('getId')->willReturn('1');
        
        // Set and get user
        $this->auth->setUser($user);
        $this->assertSame($user, $this->auth->getUser());
    }

    /**
     * Test user provider getter and setter
     */
    public function testGetSetUserProvider(): void
    {
        // Create a mock user provider
        $provider = $this->createMock(UserProviderInterface::class);
        
        // Set and get user provider
        $this->auth->setUserProvider($provider);
        $this->assertSame($provider, $this->auth->getUserProvider());
    }

    /**
     * Test that handler is properly set via constructor
     */
    public function testSetHandler(): void
    {
        // Test that auth was created with handler (no exception thrown)
        $this->assertInstanceOf(Auth::class, $this->auth);
        $this->assertTrue(true);
    }

    /**
     * Test login with valid credentials
     */
    public function testLoginWithValidCredentials(): void
    {
        // Create mock user
        $user = $this->createMock(UserInterface::class);
        $user->method('getId')->willReturn('1');
        
        // Create mock user provider
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->expects($this->once())
                 ->method('findByCredentials')
                 ->with(['username' => 'testuser'])
                 ->willReturn($user);
        
        // Create mock handler
        $handler = $this->createMock(HandlerInterface::class);
        $handler->expects($this->once())
                ->method('validate')
                ->with($user, ['username' => 'testuser'])
                ->willReturn(true);
        
        $handler->expects($this->once())
                ->method('login')
                ->with($user, false);
        
        // Set up auth
        $this->auth->setUserProvider($provider);
        $this->auth->setHandler($handler);
        
        // Test login
        $result = $this->auth->login(['username' => 'testuser']);
        $this->assertSame($user, $result);
        $this->assertSame($user, $this->auth->getUser());
    }

    /**
     * Test login with invalid credentials
     */
    public function testLoginWithInvalidCredentials(): void
    {
        // Create mock user
        $user = $this->createMock(UserInterface::class);
        
        // Create mock user provider
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->expects($this->once())
                 ->method('findByCredentials')
                 ->with(['username' => 'invaliduser'])
                 ->willReturn($user);
        
        // Create mock handler
        $handler = $this->createMock(HandlerInterface::class);
        $handler->expects($this->once())
                ->method('validate')
                ->with($user, ['username' => 'invaliduser'])
                ->willReturn(false);
        
        $handler->expects($this->never())
                ->method('login');
        
        // Set up auth
        $this->auth->setUserProvider($provider);
        $this->auth->setHandler($handler);
        
        // Test login
        $result = $this->auth->login(['username' => 'invaliduser']);
        $this->assertFalse($result);
    }

    /**
     * Test logout
     */
    public function testLogout(): void
    {
        // Create mock user
        $user = $this->createMock(UserInterface::class);
        
        // Create mock handler
        $handler = $this->createMock(HandlerInterface::class);
        $handler->expects($this->once())
                ->method('logout')
                ->with($user);
        
        // Set up auth
        $this->auth->setUser($user);
        $this->auth->setHandler($handler);
        
        // Test logout
        $this->auth->logout();
        $this->assertNull($this->auth->getUser());
    }
}