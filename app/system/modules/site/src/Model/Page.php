<?php

declare(strict_types=1);

namespace Pagekit\Site\Model;

use Pagekit\Database\ORM\ModelTrait;
use Pagekit\System\Model\DataModelTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Page entity with Symfony Validator integration (Hybrid Mode).
 *
 * Validation: Uses PHP 8 Attributes (#[Assert\...])
 * ORM: Still uses Doctrine Annotations (@Entity, @Column) - TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
 *
 * @Entity(tableClass="@system_page")
 */
class Page implements \JsonSerializable
{
    use DataModelTrait, ModelTrait;

    /**
     * @Column(type="integer") @Id
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?int $id = null;

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'validation.page.title_required')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.page.title_max_length'
    )]
    public ?string $title = null;

    /**
     * @Column
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?string $content = '';
}
