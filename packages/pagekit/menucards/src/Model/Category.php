<?php

namespace Pagekit\Menucards\Model;

use Pagekit\Database\ORM\ModelTrait;
use Pagekit\Database\ORM\Annotation\Entity;
use Pagekit\Database\ORM\Annotation\Column;
use Pagekit\Database\ORM\Annotation\BelongsTo;
use Pagekit\Database\ORM\Annotation\ManyToMany;
use Pagekit\System\Model\DataModelTrait;

/**
 * @Entity(tableClass="@menucards_category")
 */
class Category
{
    use ModelTrait, DataModelTrait;

    /**
     * @Column(type="integer")
     * @var int
     */
    public $id;

    /**
     * @Column(type="integer")
     * @var int
     */
    public $menu_id;

    /**
     * @Column(type="string")
     * @var string
     */
    public $title;

    /**
     * @Column(type="text")
     * @var string|null
     */
    public $description;

    /**
     * @Column(type="integer")
     * @var int
     */
    public $priority = 0;

    /**
     * @BelongsTo(targetEntity="Menu", keyFrom="menu_id", keyTo="id")
     * @var Menu
     */
    public $menu;

    /**
     * @ManyToMany(targetEntity="Product", tableThrough="@menucards_category_product", keyThroughFrom="category_id", keyThroughTo="product_id")
     * @var Product[]
     */
    public $products;

    /**
     * Pre-save hook
     */
    public function preSave(): void
    {
        // Debug: Entity is being saved
        error_log('[Menucards] Category preSave: ' . ($this->title ?? 'no title') . ' for menu_id: ' . ($this->menu_id ?? 'none'));
    }
}
