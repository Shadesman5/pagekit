<?php

namespace Pagekit\Menucards\Tests\Model;

use PHPUnit\Framework\TestCase;
use Pagekit\Menucards\Model\Menu;

/**
 * Menu Model Test
 * Unit tests for Menu model functionality
 */
class MenuTest extends TestCase
{
    /**
     * Test menu creation with default values
     */
    public function testMenuCreation(): void
    {
        $menu = new Menu();
        $menu->title = 'Test Menu';
        $menu->slug = 'test-menu';

        $this->assertEquals('Test Menu', $menu->title);
        $this->assertEquals('test-menu', $menu->slug);
        $this->assertEquals(0, $menu->status);
        $this->assertInstanceOf(\DateTime::class, $menu->created);
        $this->assertNull($menu->description);
    }

    /**
     * Test menu with description
     */
    public function testMenuWithDescription(): void
    {
        $menu = new Menu();
        $menu->title = 'Breakfast Menu';
        $menu->slug = 'breakfast';
        $menu->description = 'Our delicious breakfast options';

        $this->assertEquals('Our delicious breakfast options', $menu->description);
    }

    /**
     * Test menu status values
     */
    public function testMenuStatusValues(): void
    {
        $menu = new Menu();
        $menu->title = 'Test Menu';
        $menu->slug = 'test';
        
        // Default status
        $this->assertEquals(0, $menu->status);
        
        // Published status
        $menu->status = 1;
        $this->assertEquals(1, $menu->status);
    }

    /**
     * Test JSON serialization
     */
    public function testJsonSerialization(): void
    {
        $menu = new Menu();
        $menu->id = 1;
        $menu->title = 'Test Menu';
        $menu->slug = 'test-menu';
        $menu->description = 'Test description';
        $menu->status = 1;

        $json = $menu->jsonSerialize();

        $this->assertIsArray($json);
        $this->assertEquals(1, $json['id']);
        $this->assertEquals('Test Menu', $json['title']);
        $this->assertEquals('test-menu', $json['slug']);
        $this->assertEquals('Test description', $json['description']);
        $this->assertEquals(1, $json['status']);
        $this->assertArrayHasKey('created', $json);
    }

    /**
     * Test menu created timestamp format
     */
    public function testMenuCreatedTimestamp(): void
    {
        $menu = new Menu();
        $menu->title = 'Test';
        $menu->slug = 'test';

        $json = $menu->jsonSerialize();
        
        $this->assertMatchesRegularExpression(
            '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',
            $json['created']
        );
    }
}
