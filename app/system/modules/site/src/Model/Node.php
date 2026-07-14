<?php

declare(strict_types=1);

namespace Pagekit\Site\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Database\ORM\ModelTrait;
use Pagekit\Database\ORM\SerializableModelInterface;
use Pagekit\System\Model\DataModelTrait;
use Pagekit\System\Model\NodeInterface;
use Pagekit\System\Model\NodeTrait;
use Pagekit\User\Model\AccessModelTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Node entity with PHP 8 Attributes for ORM and Validation.
 */
#[ORM\Entity(tableClass: '@system_node')]
class Node implements NodeInterface, \JsonSerializable, SerializableModelInterface
{
    use AccessModelTrait;
    use DataModelTrait;
    use ModelTrait;
    use NodeModelTrait;
    use NodeTrait;

    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero(message: 'validation.node.parent_id_invalid')]
    public ?int $parent_id = 0;

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero(message: 'validation.node.priority_invalid')]
    public int $priority = 0;

    #[ORM\Column(type: 'integer')]
    #[Assert\Choice(
        choices: [0, 1],
        message: 'validation.node.status_invalid'
    )]
    public int $status = 0;

    #[ORM\Column(type: 'string')]
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

    #[ORM\Column(type: 'string')]
    public ?string $path = null;

    #[ORM\Column(type: 'string')]
    #[Assert\Length(
        max: 500,
        maxMessage: 'validation.node.link_max_length'
    )]
    public ?string $link = null;

    #[ORM\Column(type: 'string')]
    #[Assert\NotBlank(message: 'validation.node.title_required')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.node.title_max_length'
    )]
    public ?string $title = null;

    #[ORM\Column(type: 'string')]
    #[Assert\NotBlank(message: 'validation.node.type_required')]
    public ?string $type = null;

    #[ORM\Column(type: 'string')]
    public ?string $menu = '';
}
