<?php

namespace Pagekit\Menucards\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pagekit\Menucards\Model\Menu;

class MenuModelTest extends TestCase
{
    protected Menu $menu;

    protected function setUp(): void
    {
        $this->menu = new Menu();
    }

    public function testMenuHasRequiredProperties(): void
    {
        $this->assertObjectHasProperty('id', $this->menu);
        $this->assertObjectHasProperty('title', $this->menu);
        $this->assertObjectHasProperty('slug', $this->menu);
        $this->assertObjectHasProperty('description', $this->menu);
        $this->assertObjectHasProperty('status', $this->menu);
    }

    public function testGetStatusTextReturnsCorrectStatus(): void
    {
        $this->menu->status = 0;
        $this->assertEquals('Unpublished', $this->menu->getStatusText());

        $this->menu->status = 1;
        $this->assertEquals('Published', $this->menu->getStatusText());

        $this->menu->status = 2;
        $this->assertEquals('Draft', $this->menu->getStatusText());
    }

    public function testGetStatusTextReturnsUnknownForInvalidStatus(): void
    {
        $this->menu->status = 999;
        $this->assertEquals('Unknown', $this->menu->getStatusText());
    }

    public function testDefaultStatusIsUnpublished(): void
    {
        $this->assertEquals(0, $this->menu->status);
    }
}
