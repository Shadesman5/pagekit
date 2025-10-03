<?php

namespace Pagekit\Menucards\Model;

use Pagekit\Database\ORM\ModelTrait;
use Pagekit\Database\ORM\Annotation\Entity;
use Pagekit\Database\ORM\Annotation\Column;
use Pagekit\Database\ORM\Annotation\HasMany;
use Pagekit\System\Model\DataModelTrait;

/**
 * @Entity(tableClass="@menucards_menu")
 */
class Menu
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
    public $title;

    /**
     * @Column(type="string")
     * @var string
     */
    public $slug;

    /**
     * @Column(type="text")
     * @var string|null
     */
    public $description;

    /**
     * @Column(type="smallint")
     * @var int
     */
    public $status = 0;

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
     * @HasMany(targetEntity="Category", keyFrom="id", keyTo="menu_id")
     * @var Category[]
     */
    public $categories;

    /**
     * Get status as string
     */
    public function getStatusText(): string
    {
        $statuses = [
            0 => 'Unpublished',
            1 => 'Published',
            2 => 'Draft'
        ];

        return $statuses[$this->status] ?? 'Unknown';
    }

    /**
     * Pre-save hook
     */
    public function preSave(): void
    {
        // Debug: Entity is being saved
        error_log('[Menucards] Menu preSave: ' . ($this->title ?? 'no title'));

        if (!$this->id) {
            $this->created = new \DateTime();
        }
        $this->modified = new \DateTime();
    }
}
