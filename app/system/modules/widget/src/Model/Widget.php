<?php

declare(strict_types=1);

namespace Pagekit\Widget\Model;

use Pagekit\Database\ORM\ModelTrait;
use Pagekit\System\Model\DataModelTrait;
use Pagekit\User\Model\AccessModelTrait;

/**
 * @Entity(tableClass="@system_widget")
 */
#[\AllowDynamicProperties]
class Widget implements \JsonSerializable
{
    use AccessModelTrait, DataModelTrait, ModelTrait;

    /** @Column(type="integer") @Id */
    public ?int $id = null;

    /** @Column */
    public ?string $title = '';

    /** @Column(type="string") */
    public ?string $type = null;

    /** @Column(type="integer") */
    public int $status = 1;

    /** @Column(type="simple_array") */
    public array $nodes = [];
}
