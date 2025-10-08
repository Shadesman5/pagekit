<?php

namespace Pagekit\Menucards\Tests\Model;

use PHPUnit\Framework\TestCase;
use Pagekit\Menucards\Model\Category;

/**
 * Category Model Test
 * Unit tests for Category model functionality
 */
class CategoryTest extends TestCase
{
    /**
     * Test category creation with default values
     */
    public function testCategoryCreation(): void
    {
        $category = new Category();
        $category->menu_id = 1;
        $category->title = 'Appetizers';

        $this->assertEquals(1, $category->menu_id);
        $this->assertEquals('Appetizers', $category->title);
        $this->assertEquals(0, $category->priority);
    }

    /**
     * Test category with priority
     */
    public function testCategoryWithPriority(): void
    {
        $category = new Category();
        $category->menu_id = 1;
        $category->title = 'Main Courses';
        $category->priority = 10;

        $this->assertEquals(10, $category->priority);
    }

    /**
     * Test JSON serialization
     */
    public function testJsonSerialization(): void
    {
        $category = new Category();
        $category->id = 1;
        $category->menu_id = 5;
        $category->title = 'Desserts';
        $category->priority = 20;

        $json = $category->jsonSerialize();

        $this->assertIsArray($json);
        $this->assertEquals(1, $json['id']);
        $this->assertEquals(5, $json['menu_id']);
        $this->assertEquals('Desserts', $json['title']);
        $this->assertEquals(20, $json['priority']);
    }

    /**
     * Test category belongs to menu
     */
    public function testCategoryBelongsToMenu(): void
    {
        $category = new Category();
        $category->menu_id = 1;
        $category->title = 'Beverages';

        $this->assertIsInt($category->menu_id);
        $this->assertGreaterThan(0, $category->menu_id);
    }
}
