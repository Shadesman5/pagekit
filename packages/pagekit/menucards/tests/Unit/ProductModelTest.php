<?php

namespace Pagekit\Menucards\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pagekit\Menucards\Model\Product;

class ProductModelTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        $this->product = new Product();
    }

    public function testProductHasRequiredProperties(): void
    {
        $this->assertObjectHasProperty('id', $this->product);
        $this->assertObjectHasProperty('name', $this->product);
        $this->assertObjectHasProperty('description', $this->product);
        $this->assertObjectHasProperty('price', $this->product);
        $this->assertObjectHasProperty('image', $this->product);
        $this->assertObjectHasProperty('allergens', $this->product);
    }

    public function testGetFormattedPriceReturnsFormattedString(): void
    {
        $this->product->price = 19.90;
        $formatted = $this->product->getFormattedPrice();
        
        $this->assertStringContainsString('19,90', $formatted);
        $this->assertStringContainsString('€', $formatted);
    }

    public function testGetFormattedPriceReturnsEmptyForNullPrice(): void
    {
        $this->product->price = null;
        $formatted = $this->product->getFormattedPrice();
        
        $this->assertEmpty($formatted);
    }

    public function testGetAllergensArrayReturnsArrayFromString(): void
    {
        $this->product->allergens = 'Gluten, Lactose, Nuts';
        $allergens = $this->product->getAllergensArray();
        
        $this->assertIsArray($allergens);
        $this->assertCount(3, $allergens);
        $this->assertEquals('Gluten', $allergens[0]);
        $this->assertEquals('Lactose', $allergens[1]);
        $this->assertEquals('Nuts', $allergens[2]);
    }

    public function testGetAllergensArrayReturnsEmptyArrayForEmptyString(): void
    {
        $this->product->allergens = '';
        $allergens = $this->product->getAllergensArray();
        
        $this->assertIsArray($allergens);
        $this->assertEmpty($allergens);
    }

    public function testGetAllergensArrayReturnsEmptyArrayForNull(): void
    {
        $this->product->allergens = null;
        $allergens = $this->product->getAllergensArray();
        
        $this->assertIsArray($allergens);
        $this->assertEmpty($allergens);
    }
}
