<?php

namespace Pagekit\Menucards\Model;

use Pagekit\Application as App;
use Pagekit\Database\ORM\ModelTrait;

/**
 * Category Model
 * Represents a category within a menu (e.g., "Appetizers", "Main Courses")
 * 
 * @Entity(tableClass="@menucards_category")
 */
class Category implements \JsonSerializable
{
    use ModelTrait;

    /** 
     * @Column(type="integer") 
     * @Id 
     */
    public $id;

    /** 
     * @Column(type="integer") 
     */
    public $menu_id;

    /** 
     * @Column(type="string") 
     */
    public $title;

    /** 
     * @Column(type="integer") 
     */
    public $priority = 0;

    /**
     * Get parent menu
     * 
     * @return Menu|null
     */
    public function getMenu(): ?Menu
    {
        return Menu::find($this->menu_id);
    }

    /**
     * Get products in this category
     * Manual query via pivot table
     * 
     * @return array
     */
    public function getProducts()
    {
        $db = App::db();
        
        // Query products via pivot table
        $productIds = $db->createQueryBuilder()
            ->select('product_id')
            ->from('@menucards_category_product')
            ->where(['category_id' => $this->id])
            ->orderBy('priority', 'ASC')
            ->execute()
            ->fetchAll(\PDO::FETCH_COLUMN);
        
        if (empty($productIds)) {
            return [];
        }
        
        // Fetch products
        return Product::query()
            ->whereInSet('id', $productIds)
            ->get();
    }

    /**
     * Attach product to this category
     * 
     * @param int $productId
     * @param int $priority
     * @return bool
     */
    public function attachProduct(int $productId, int $priority = 0): bool
    {
        $db = App::db();
        
        // Check if already attached
        $exists = $db->createQueryBuilder()
            ->select('1')
            ->from('@menucards_category_product')
            ->where(['category_id' => $this->id, 'product_id' => $productId])
            ->execute()
            ->fetchColumn();
        
        if ($exists) {
            // Update priority
            return (bool) $db->update('@menucards_category_product', 
                ['priority' => $priority],
                ['category_id' => $this->id, 'product_id' => $productId]
            );
        }
        
        // Insert new attachment
        return (bool) $db->insert('@menucards_category_product', [
            'category_id' => $this->id,
            'product_id' => $productId,
            'priority' => $priority
        ]);
    }

    /**
     * Detach product from this category
     * 
     * @param int $productId
     * @return bool
     */
    public function detachProduct(int $productId): bool
    {
        return (bool) App::db()->delete('@menucards_category_product', [
            'category_id' => $this->id,
            'product_id' => $productId
        ]);
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
            'menu_id' => $this->menu_id,
            'title' => $this->title,
            'priority' => $this->priority
        ];
    }
}
