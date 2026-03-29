<?php

declare(strict_types=1);

namespace Pagekit\User\Model;

use Pagekit\Database\ORM\Attribute as ORM;

trait AccessModelTrait
{
    #[ORM\Column(type: 'simple_array')]
    public array $roles = [];

    /**
     * @param  int $role
     */
    public function hasRole(int $role): bool
    {
        return in_array($role, $this->roles);
    }

    /**
     * @param  User $user
     */
    public function hasAccess(User $user): bool
    {
        return !$this->roles or array_intersect($user->roles, $this->roles);
    }

    /**
     * @param  Role|int $role
     */
    public static function removeRole(Role|int $role): int
    {
        if ($role instanceof Role) {
            $role = $role->id;
        }

        $db = self::getConnection();
        $platform = $db->getDatabasePlatform();

        return $db->executeStatement('UPDATE '.self::getMetadata()->getTable().' SET roles = NULLIF('.$platform->getTrimExpression("REPLACE (".$platform->getConcatExpression($db->quote(','), 'roles', $db->quote(',')).", ',{$role},', ',')", 3, $db->quote(',')). ", '')");
    }
}
