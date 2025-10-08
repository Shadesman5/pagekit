<?php

namespace Pagekit\Menucards\Tests\Model;

use PHPUnit\Framework\TestCase;
use Pagekit\Menucards\Model\Product;

/**
 * Product Model Test
 * Unit tests for Product model functionality
 */
class ProductTest extends TestCase
{
    /**
     * Test product creation with default values
     */
    public function testProductCreation(): void
    {
        $product = new Product();
        $product->name = 'Test Product';
        $product->price = 19.99;
        $product->description = 'Test description';

        $this->assertEquals('Test Product', $product->name);
        $this->assertEquals(19.99, $product->price);
        $this->assertEquals('Test description', $product->description);
        $this->assertInstanceOf(\DateTime::class, $product->created);
        $this->assertNull($product->image);
    }

    /**
     * Test product validation - valid product
     */
    public function testValidProductValidation(): void
    {
        $product = new Product();
        $product->name = 'Valid Product';
        $product->price = 10.50;

        $errors = $product->validate();
        
        $this->assertEmpty($errors, 'Valid product should have no validation errors');
    }

    /**
     * Test product validation - missing name
     */
    public function testInvalidProductMissingName(): void
    {
        $product = new Product();
        $product->name = '';
        $product->price = 10.50;

        $errors = $product->validate();
        
        $this->assertArrayHasKey('name', $errors);
        $this->assertStringContainsString('required', $errors['name']);
    }

    /**
     * Test product validation - missing price
     */
    public function testInvalidProductMissingPrice(): void
    {
        $product = new Product();
        $product->name = 'Test Product';

        $errors = $product->validate();
        
        $this->assertArrayHasKey('price', $errors);
    }

    /**
     * Test product validation - negative price
     */
    public function testInvalidProductNegativePrice(): void
    {
        $product = new Product();
        $product->name = 'Test Product';
        $product->price = -5.00;

        $errors = $product->validate();
        
        $this->assertArrayHasKey('price', $errors);
        $this->assertStringContainsString('non-negative', $errors['price']);
    }

    /**
     * Test product validation - invalid price type
     */
    public function testInvalidProductInvalidPriceType(): void
    {
        $product = new Product();
        $product->name = 'Test Product';
        $product->price = 'invalid';

        $errors = $product->validate();
        
        $this->assertArrayHasKey('price', $errors);
    }

    /**
     * Test JSON serialization
     */
    public function testJsonSerialization(): void
    {
        $product = new Product();
        $product->id = 1;
        $product->name = 'Test Product';
        $product->price = 19.99;
        $product->description = 'Test';

        $json = $product->jsonSerialize();

        $this->assertIsArray($json);
        $this->assertEquals(1, $json['id']);
        $this->assertEquals('Test Product', $json['name']);
        $this->assertEquals(19.99, $json['price']);
        $this->assertEquals('Test', $json['description']);
        $this->assertArrayHasKey('created', $json);
    }

    /**
     * Test product with optional image
     */
    public function testProductWithImage(): void
    {
        $product = new Product();
        $product->name = 'Product with Image';
        $product->price = 25.00;
        $product->image = '/path/to/image.jpg';

        $this->assertEquals('/path/to/image.jpg', $product->image);
        
        $errors = $product->validate();
        $this->assertEmpty($errors);
    }

    /**
     * Test product price formatting
     */
    public function testProductPriceFormatting(): void
    {
        $product = new Product();
        $product->name = 'Test';
        $product->price = 10;

        $json = $product->jsonSerialize();
        
        $this->assertIsFloat($json['price']);
        $this->assertEquals(10.0, $json['price']);
    }
}
