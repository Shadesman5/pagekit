<?php

declare(strict_types=1);

namespace Pagekit\User\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Database\ORM\ModelTrait;
use Pagekit\Event\EventInterface;

trait UserModelTrait
{
    use ModelTrait;

    /**
     * Narrows a {@see \Pagekit\Database\ORM\QueryBuilder::first()} result
     * (typed `?object` because the query builder is shared across all mapped
     * entities) down to the calling `static::class` instance. Mirrors the
     * runtime guard already present in {@see ModelTrait::find()}.
     */
    private static function firstAsStatic(?object $entity): ?static
    {
        if ($entity === null) {
            return null;
        }
        if (!$entity instanceof static) {
            throw new \LogicException(sprintf(
                'QueryBuilder::first() returned %s, expected %s',
                get_class($entity),
                static::class
            ));
        }

        return $entity;
    }

    /**
     * {@inheritdoc}
     */
    public static function findByUsername(string $username): ?User
    {
        return self::firstAsStatic(static::where(compact('username'))->first());
    }

    /**
     * {@inheritdoc}
     */
    public static function findByEmail(string $email): ?User
    {
        return self::firstAsStatic(static::where(compact('email'))->first());
    }

    /**
     * {@inheritdoc}
     */
    public static function findByLogin(string $login): ?User
    {
        return self::firstAsStatic(
            static::where(['username' => $login])->orWhere(['email' => $login])->first()
        );
    }

    /**
     * {@inheritdoc}
     */
    public static function updateLogin(User $user): void
    {
        static::where(['id' => $user->id])->update(['login' => date('Y-m-d H:i:s')]);
    }

    /**
     * Finds user's roles.
     *
     * @param  User $user
     * @return Role[]
     */
    public static function findRoles(User $user): array
    {
        static $cached = [];

        if ($ids = array_diff($user->roles, array_keys($cached))) {
            $cached += Role::where('id IN ('.implode(',', $user->roles).')')->get();
        }

        return array_intersect_key($cached, array_flip($user->roles));
    }

    #[ORM\Saving]
    public static function saving(EventInterface $event, User $user): void
    {
        if (!$user->hasRole(Role::ROLE_AUTHENTICATED)) {
            $user->roles[] = Role::ROLE_AUTHENTICATED;
        }
    }
}
