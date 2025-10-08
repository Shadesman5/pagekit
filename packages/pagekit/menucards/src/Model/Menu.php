<?php

namespace Pagekit\Menucards\Model;

use Pagekit\Database\ORM\ModelTrait;

/**
 * Menu Model
 * Represents a menu card (e.g., "Breakfast Menu", "Dinner Menu")
 * 
 * @Entity(tableClass="@menucards_menu")
 */
class Menu implements \JsonSerializable
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
    public $title;

    /** 
     * @Column(type="string") 
     */
    public $slug;

    /** 
     * @Column(type="text") 
     */
    public $description;

    /** 
     * @Column(type="smallint") 
     */
    public $status;

    /** 
     * @Column(type="datetime") 
     */
    public $created;

    /**
     * Get categories for this menu
     * Manual query due to Pagekit ORM limitations
     * 
     * @return array
     */
    public function getCategories()
    {
        return Category::where(['menu_id' => $this->id])->orderBy('priority', 'ASC')->get();
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
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status,
            'created' => $this->created ? $this->created->format('Y-m-d H:i:s') : null
        ];
    }
}
