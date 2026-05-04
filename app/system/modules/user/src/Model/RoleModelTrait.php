<?php

declare(strict_types=1);

namespace Pagekit\User\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Database\ORM\ModelTrait;
use Pagekit\Event\EventInterface;

trait RoleModelTrait
{
    use ModelTrait;

    #[ORM\Saving]
    public static function saving(EventInterface $event, Role $role): void
    {
        if (!$role->id) {
            $result = self::getConnection()->executeQuery('SELECT MAX(priority) + 1 FROM @system_role');
            $role->priority = $result->fetchOne();
        }
    }
}
