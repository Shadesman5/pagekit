<?php

namespace Pagekit\Menucards\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pagekit\Menucards\Model\Category;

class CategoryModelTest extends TestCase
{
    protected Category $category;

    protected function setUp(): void
    {
        $this->category = new Category();
    }

    public function testCategoryHasRequiredProperties(): void
    {
        $this->assertObjectHasProperty('id', $this->category);
        $this->assertObjectHasProperty('menu_id', $this->category);
        $this->assertObjectHasProperty('title', $this->category);
        $this->assertObjectHasProperty('description', $this->category);
        $this->assertObjectHasProperty('priority', $this->category);
    }

    public function testDefaultPriorityIsZero(): void
    {
        $this->assertEquals(0, $this->category->priority);
    }
}
