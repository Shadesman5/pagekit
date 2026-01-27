<?php

declare(strict_types=1);

namespace Pagekit\Site\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Database\ORM\ModelTrait;
use Pagekit\System\Model\DataModelTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Page entity with PHP 8 Attributes for ORM and Validation.
 */
#[ORM\Entity(tableClass: '@system_page')]
class Page implements \JsonSerializable
{
    use DataModelTrait, ModelTrait;

    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    #[Assert\NotBlank(message: 'validation.page.title_required')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.page.title_max_length'
    )]
    public ?string $title = null;

    #[ORM\Column]
    public ?string $content = '';
}
