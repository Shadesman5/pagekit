<?php

namespace Pagekit\Menucards\Model;

use Pagekit\Database\ORM\ModelTrait;
use Pagekit\Database\ORM\Annotation\Entity;
use Pagekit\Database\ORM\Annotation\Column;
use Pagekit\System\Model\DataModelTrait;

/**
 * @Entity(tableClass="@menucards_product")
 */
class Product
{
    use ModelTrait, DataModelTrait;

    /**
     * @Column(type="integer")
     * @var int
     */
    public $id;

    /**
     * @Column(type="string")
     * @var string
     */
    public $name;

    /**
     * @Column(type="text")
     * @var string|null
     */
    public $description;

    /**
     * @Column(type="decimal", precision=10, scale=2)
     * @var float|null
     */
    public $price;

    /**
     * @Column(type="string")
     * @var string|null
     */
    public $image;

    /**
     * @Column(type="text")
     * @var string|null
     */
    public $allergens;

    /**
     * @Column(type="datetime")
     * @var \DateTime|null
     */
    public $created;

    /**
     * @Column(type="datetime")
     * @var \DateTime|null
     */
    public $modified;

    /**
     * Get formatted price
     */
    public function getFormattedPrice(string $currency = '€'): string
    {
        if ($this->price === null) {
            return '';
        }

        return number_format($this->price, 2, ',', '.') . ' ' . $currency;
    }

    /**
     * Get allergens as array
     */
    public function getAllergensArray(): array
    {
        if (empty($this->allergens)) {
            return [];
        }

        return array_map('trim', explode(',', $this->allergens));
    }

    /**
     * Pre-save hook
     */
    public function preSave(): void
    {
        // Debug: Entity is being saved
        error_log('[Menucards] Product preSave: ' . ($this->name ?? 'no name') . ' with price: ' . ($this->price ?? 'no price'));

        if (!$this->id) {
            $this->created = new \DateTime();
        }
        $this->modified = new \DateTime();
    }
}
