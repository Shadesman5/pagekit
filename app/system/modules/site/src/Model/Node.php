<?php

declare(strict_types=1);

namespace Pagekit\Site\Model;

use Pagekit\Application as App;
use Pagekit\System\Model\DataModelTrait;
use Pagekit\System\Model\NodeInterface;
use Pagekit\System\Model\NodeTrait;
use Pagekit\User\Model\AccessModelTrait;
use Pagekit\User\Model\User;

/**
 * @Entity(tableClass="@system_node")
 */
#[\AllowDynamicProperties]
class Node implements NodeInterface, \JsonSerializable
{
    use AccessModelTrait, DataModelTrait, NodeModelTrait, NodeTrait;

    /** @Column(type="integer") @Id */
    public ?int $id = null;

    /** @Column(type="integer") */
    public ?int $parent_id = 0;

    /** @Column(type="integer") */
    public int $priority = 0;

    /** @Column(type="integer") */
    public int $status = 0;

    /** @Column(type="string") */
    public ?string $slug = null;

    /** @Column(type="string") */
    public ?string $path = null;

    /** @Column(type="string") */
    public ?string $link = null;

    /** @Column(type="string") */
    public ?string $title = null;

    /** @Column(type="string") */
    public ?string $type = null;

    /** @Column(type="string") */
    public ?string $menu = '';

    protected static array $properties = [
        'accessible' => 'isAccessible'
    ];

    /**
     * Gets the node URL.
     *
     * @param  mixed  $referenceType
     */
    public function getUrl(mixed $referenceType = false): string
    {
        return App::url($this->link, [], $referenceType);
    }

    public function isAccessible(?User $user = null): bool
    {
        return $this->status && $this->hasAccess($user ?: App::user());
    }

    /**
     * {@inheritdoc}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray(['url' => $this->getUrl('base')]);
    }
}
