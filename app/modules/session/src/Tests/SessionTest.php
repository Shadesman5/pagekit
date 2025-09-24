<?php

namespace Pagekit\Session\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;

class SessionTest extends TestCase
{
    protected ?Session $session = null;

    public function setUp(): void
    {
        // Use MockArraySessionStorage for testing
        $storage = new MockArraySessionStorage();
        $this->session = new Session($storage, new AttributeBag(), new FlashBag());
    }

    public function tearDown(): void
    {
        if ($this->session && $this->session->isStarted()) {
            $this->session->save();
        }
        $this->session = null;
    }

    /**
     * Test that Session instance can be created
     */
    public function testSessionInstantiation(): void
    {
        $this->assertInstanceOf(Session::class, $this->session);
    }

    /**
     * Test session start
     */
    public function testSessionStart(): void
    {
        $this->assertFalse($this->session->isStarted());
        $this->session->start();
        $this->assertTrue($this->session->isStarted());
    }

    /**
     * Test get and set session attributes
     */
    public function testGetSetAttributes(): void
    {
        $this->session->start();
        
        // Set attribute
        $this->session->set('test_key', 'test_value');
        $this->assertEquals('test_value', $this->session->get('test_key'));
        
        // Test with default value
        $this->assertEquals('default', $this->session->get('non_existent', 'default'));
    }

    /**
     * Test has attribute
     */
    public function testHasAttribute(): void
    {
        $this->session->start();
        
        $this->assertFalse($this->session->has('test_key'));
        
        $this->session->set('test_key', 'test_value');
        $this->assertTrue($this->session->has('test_key'));
    }

    /**
     * Test remove attribute
     */
    public function testRemoveAttribute(): void
    {
        $this->session->start();
        
        $this->session->set('test_key', 'test_value');
        $this->assertTrue($this->session->has('test_key'));
        
        $removed = $this->session->remove('test_key');
        $this->assertEquals('test_value', $removed);
        $this->assertFalse($this->session->has('test_key'));
    }

    /**
     * Test all attributes
     */
    public function testAllAttributes(): void
    {
        $this->session->start();
        
        $this->session->set('key1', 'value1');
        $this->session->set('key2', 'value2');
        
        $all = $this->session->all();
        $this->assertIsArray($all);
        $this->assertArrayHasKey('key1', $all);
        $this->assertArrayHasKey('key2', $all);
        $this->assertEquals('value1', $all['key1']);
        $this->assertEquals('value2', $all['key2']);
    }

    /**
     * Test clear all attributes
     */
    public function testClearAttributes(): void
    {
        $this->session->start();
        
        $this->session->set('key1', 'value1');
        $this->session->set('key2', 'value2');
        
        $this->session->clear();
        
        $all = $this->session->all();
        $this->assertEmpty($all);
    }

    /**
     * Test flash messages
     */
    public function testFlashMessages(): void
    {
        $this->session->start();
        
        // Add flash message
        $this->session->getFlashBag()->add('success', 'Operation successful');
        
        // Get flash message
        $messages = $this->session->getFlashBag()->get('success');
        $this->assertIsArray($messages);
        $this->assertContains('Operation successful', $messages);
        
        // Flash message should be removed after getting
        $messages = $this->session->getFlashBag()->get('success');
        $this->assertEmpty($messages);
    }

    /**
     * Test peek flash messages
     */
    public function testPeekFlashMessages(): void
    {
        $this->session->start();
        
        // Add flash message
        $this->session->getFlashBag()->add('info', 'Information message');
        
        // Peek flash message (should not remove it)
        $messages = $this->session->getFlashBag()->peek('info');
        $this->assertIsArray($messages);
        $this->assertContains('Information message', $messages);
        
        // Message should still be there
        $messages = $this->session->getFlashBag()->peek('info');
        $this->assertNotEmpty($messages);
    }

    /**
     * Test session id
     */
    public function testSessionId(): void
    {
        $this->session->start();
        
        $id = $this->session->getId();
        $this->assertNotEmpty($id);
        $this->assertIsString($id);
        
        // Set new id
        $newId = 'new_session_id_123';
        $this->session->setId($newId);
        $this->assertEquals($newId, $this->session->getId());
    }

    /**
     * Test session name
     */
    public function testSessionName(): void
    {
        $name = $this->session->getName();
        $this->assertNotEmpty($name);
        $this->assertIsString($name);
        
        // Set new name
        $newName = 'CUSTOM_SESSION';
        $this->session->setName($newName);
        $this->assertEquals($newName, $this->session->getName());
    }

    /**
     * Test session invalidate
     */
    public function testSessionInvalidate(): void
    {
        $this->session->start();
        $this->session->set('test', 'value');
        
        $oldId = $this->session->getId();
        
        // Invalidate session
        $this->session->invalidate();
        
        // Session should have new ID
        $this->assertNotEquals($oldId, $this->session->getId());
        
        // Data should be cleared
        $this->assertNull($this->session->get('test'));
    }

    /**
     * Test session migrate
     */
    public function testSessionMigrate(): void
    {
        $this->session->start();
        $this->session->set('test', 'value');
        
        $oldId = $this->session->getId();
        
        // Migrate session (keep data)
        $this->session->migrate(false);
        
        // Session should have new ID
        $this->assertNotEquals($oldId, $this->session->getId());
        
        // Data should be preserved
        $this->assertEquals('value', $this->session->get('test'));
    }
}