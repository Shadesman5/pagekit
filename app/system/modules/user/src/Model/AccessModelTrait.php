<?php

declare(strict_types=1);

namespace Pagekit\User\Model;

use Pagekit\Database\ORM\Attribute as ORM;

trait AccessModelTrait
{
    /** @var array<int, int> */
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
}
