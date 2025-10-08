<?php

namespace Pagekit\Menucards\Model;

use Pagekit\Application as App;
use Pagekit\Database\ORM\ModelTrait;

/**
 * Product Model
 * Represents a product/dish that can be added to menu categories
 * 
 * @Entity(tableClass="@menucards_product")
 */
class Product implements \JsonSerializable
{
    use ModelTrait;

    /** 
     * @Column(type="integer") 
     * @Id 
     */
    public $id;

    /** 
     * @Column(type="string") 
     */
    public $name;

    /** 
     * @Column(type="text", nullable=true) 
     */
    public ?string $description = null;

    /** 
     * @Column(type="decimal", precision=10, scale=2) 
     */
    public $price;

    /** 
     * @Column(type="string", nullable=true) 
     */
    public ?string $image = null;

    /** 
     * @Column(type="datetime") 
     */
    public $created;

    /**
     * Constructor - Initialize with current timestamp
     */
    public function __construct()
    {
        $this->created = new \DateTime();
    }

    /**
     * Get categories this product belongs to
     * Manual query via pivot table
     * 
     * @return array
     */
    public function getCategories()
    {
        $db = App::db();
        
        // Query categories via pivot table
        $categoryIds = $db->createQueryBuilder()
            ->select('category_id')
            ->from('@menucards_category_product')
            ->where(['product_id' => $this->id])
            ->execute()
            ->fetchAll(\PDO::FETCH_COLUMN);
        
        if (empty($categoryIds)) {
            return [];
        }
        
        // Fetch categories
        return Category::query()
            ->whereInSet('id', $categoryIds)
            ->get();
    }

    /**
     * Validate product data
     * 
     * @return array Validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];
        
        if (empty($this->name) || trim($this->name) === '') {
            $errors['name'] = 'Product name is required';
        }
        
        if (!isset($this->price) || !is_numeric($this->price)) {
            $errors['price'] = 'Valid product price is required';
        } elseif ((float)$this->price < 0) {
            $errors['price'] = 'Product price must be non-negative';
        }
        
        return $errors;
    }

    /**
     * JSON serialization
     * 
     * @return array
     */
    public function jsonSerialize(): mixed
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => (float) $this->price,
            'image' => $this->image,
            'created' => $this->created ? $this->created->format('Y-m-d H:i:s') : null
        ];
    }
}
