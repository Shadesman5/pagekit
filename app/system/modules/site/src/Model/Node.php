<?php

declare(strict_types=1);

namespace Pagekit\Site\Model;

use Pagekit\Application as App;
use Pagekit\System\Model\DataModelTrait;
use Pagekit\System\Model\NodeInterface;
use Pagekit\System\Model\NodeTrait;
use Pagekit\User\Model\AccessModelTrait;
use Pagekit\User\Model\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Node entity with Symfony Validator integration (Hybrid Mode).
 *
 * Validation: Uses PHP 8 Attributes (#[Assert\...])
 * ORM: Still uses Doctrine Annotations (@Entity, @Column) - TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
 *
 * @Entity(tableClass="@system_node")
 */
#[\AllowDynamicProperties]
class Node implements NodeInterface, \JsonSerializable
{
    use AccessModelTrait, DataModelTrait, NodeModelTrait, NodeTrait;

    /**
     * @Column(type="integer") @Id
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?int $id = null;

    /**
     * @Column(type="integer")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\PositiveOrZero(message: 'validation.node.priority_invalid')]
    public ?int $parent_id = 0;

    /**
     * @Column(type="integer")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\PositiveOrZero(message: 'validation.node.priority_invalid')]
    public int $priority = 0;

    /**
     * @Column(type="integer")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\Choice(
        choices: [0, 1],
        message: 'validation.node.status_invalid'
    )]
    public int $status = 0;

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'validation.node.slug_required')]
    #[Assert\Regex(
        pattern: '/^[a-z0-9\-_]+$/',
        message: 'validation.node.slug_invalid'
    )]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.node.slug_max_length'
    )]
    public ?string $slug = null;

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?string $path = null;

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\Length(
        max: 500,
        maxMessage: 'validation.node.link_max_length'
    )]
    public ?string $link = null;

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'validation.node.title_required')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.node.title_max_length'
    )]
    public ?string $title = null;

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'validation.node.type_required')]
    public ?string $type = null;

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?string $menu = '';

    protected static array $properties = [
        'accessible' => 'isAccessible'
    ];

    /**
     * Gets the node URL.
     *
     * @param  mixed  $referenceType
     */
    public function getUrl(mixed $referenceType = false): string|false
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
