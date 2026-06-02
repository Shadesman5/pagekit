<?php

declare(strict_types=1);

namespace Pagekit\Widget\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Database\ORM\ModelTrait;
use Pagekit\System\Model\DataModelTrait;
use Pagekit\User\Model\AccessModelTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Widget entity with PHP 8 Attributes for ORM and Validation.
 */
#[ORM\Entity(tableClass: '@system_widget')]
#[\AllowDynamicProperties]
class Widget implements \JsonSerializable
{
    use AccessModelTrait;
    use DataModelTrait;
    use ModelTrait;

    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'validation.widget.title_required')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.widget.title_max_length'
    )]
    public ?string $title = '';

    #[ORM\Column(type: 'string')]
    #[Assert\NotBlank(message: 'validation.widget.type_required')]
    public ?string $type = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\Choice(
        choices: [0, 1],
        message: 'validation.widget.status_invalid'
    )]
    public int $status = 1;

    /** @var array<int, int|string> */
    #[ORM\Column(type: 'simple_array')]
    public array $nodes = [];
}
