<?php

declare(strict_types=1);

namespace Pagekit\User\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Database\ORM\EntityEvent;
use Pagekit\Database\ORM\ModelTrait;

trait RoleModelTrait
{
    use ModelTrait;

    #[ORM\Saving]
    public static function saving(EntityEvent $event, Role $role): void
    {
        if (!$role->id) {
            $result = $event->getEntityManager()->getConnection()->executeQuery('SELECT MAX(priority) + 1 FROM @system_role');
            $role->priority = $result->fetchOne();
        }
    }
}
