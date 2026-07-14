<?php

declare(strict_types=1);

namespace Pagekit\User\Model;

use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Repository;

/**
 * Data-mapper repository for {@see User}, hosting the finders that used to live
 * as statics on `UserModelTrait`.
 *
 * @extends Repository<User>
 */
class UserRepository extends Repository
{
    public function __construct(EntityManager $em)
    {
        parent::__construct($em, $em->getMetadata(User::class));
    }

    /**
     * Finds a user by username.
     */
    public function findByUsername(string $username): ?User
    {
        return $this->where(compact('username'))->first();
    }

    /**
     * Finds a user by email address.
     */
    public function findByEmail(string $email): ?User
    {
        return $this->where(compact('email'))->first();
    }

    /**
     * Finds a user matching the given credentials.
     *
     * @param array<string, mixed> $credentials
     */
    public function findByCredentials(array $credentials): ?User
    {
        return $this->where($credentials)->first();
    }

    /**
     * Stamps the user's last login timestamp.
     */
    public function updateLogin(User $user): void
    {
        $this->where(['id' => $user->id])->update(['login' => date('Y-m-d H:i:s')]);
    }

    /**
     * Finds a user's roles, keyed by role id.
     *
     * @return array<int|string, Role>
     */
    public function findRoles(User $user): array
    {
        if (!$user->roles) {
            return [];
        }

        $roles = $this->em->getRepository(Role::class)->query()->whereIn('id', $user->roles)->get();

        return array_intersect_key($roles, array_flip($user->roles));
    }
}
