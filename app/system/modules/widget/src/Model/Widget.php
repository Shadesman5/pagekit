<?php

declare(strict_types=1);

namespace Pagekit\Widget\Model;

use Pagekit\Database\ORM\ModelTrait;
use Pagekit\System\Model\DataModelTrait;
use Pagekit\User\Model\AccessModelTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Widget entity with Symfony Validator integration (Hybrid Mode).
 *
 * Validation: Uses PHP 8 Attributes (#[Assert\...])
 * ORM: Still uses Doctrine Annotations (@Entity, @Column) - TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
 *
 * @Entity(tableClass="@system_widget")
 */
#[\AllowDynamicProperties]
class Widget implements \JsonSerializable
{
    use AccessModelTrait, DataModelTrait, ModelTrait;

    /**
     * @Column(type="integer") @Id
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?int $id = null;

    /**
     * @Column
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'validation.widget.title_required')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.widget.title_max_length'
    )]
    public ?string $title = '';

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'validation.widget.type_required')]
    public ?string $type = null;

    /**
     * @Column(type="integer")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\Choice(
        choices: [0, 1],
        message: 'validation.widget.status_invalid'
    )]
    public int $status = 1;

    /**
     * @Column(type="simple_array")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public array $nodes = [];
}
