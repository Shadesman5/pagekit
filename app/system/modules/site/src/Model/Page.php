<?php

declare(strict_types=1);

namespace Pagekit\Site\Model;

use Pagekit\Database\ORM\ModelTrait;
use Pagekit\System\Model\DataModelTrait;

/**
 * @Entity(tableClass="@system_page")
 */
class Page implements \JsonSerializable
{
    use DataModelTrait, ModelTrait;

    /** @Column(type="integer") @Id */
    public ?int $id = null;

    /** @Column(type="string") */
    public ?string $title = null;

    /** @Column */
    public string $content = '';
}
