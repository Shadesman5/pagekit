<?php

declare(strict_types=1);

namespace Pagekit\User\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Database\ORM\EntityEvent;
use Pagekit\Database\ORM\ModelTrait;

trait UserModelTrait
{
    use ModelTrait;

    /**
     * Attaches the per-instance role loader used by {@see User::hasPermission()}.
     *
     * Every production `User` is hydrated through {@see \Pagekit\Database\ORM\EntityManager::load()},
     * which fires this handler and wires the loader to the same EntityManager —
     * so permission checks resolve roles through DI without any global model state.
     */
    #[ORM\Init]
    public static function init(EntityEvent $event, User $user): void
    {
        $em = $event->getEntityManager();

        $user->setRoleLoader(static function (array $ids) use ($em): array {
            if (!$ids) {
                return [];
            }

            return $em->getRepository(Role::class)->query()->whereIn('id', $ids)->get();
        });
    }

    #[ORM\Saving]
    public static function saving(EntityEvent $event, User $user): void
    {
        if (!$user->hasRole(Role::ROLE_AUTHENTICATED)) {
            $user->roles[] = Role::ROLE_AUTHENTICATED;
        }
    }
}
