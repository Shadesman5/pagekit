<?php

declare(strict_types=1);

namespace Pagekit\Debug\DataCollector;

use DebugBar\DataCollector\DataCollectorInterface;
use Pagekit\Auth\Auth;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use Pagekit\User\Model\UserRepository;

class AuthDataCollector implements DataCollectorInterface
{
    public function __construct(
        private readonly ?Auth $auth = null,
        private readonly ?UserRepository $users = null,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        if (null === $this->auth) {
            return [
                'enabled' => false,
                'authenticated' => false,
                'user_class' => null,
                'user' => '',
                'roles' => [],
            ];
        }

        try {
            $user = $this->auth->getUser();
        } catch (\Exception $e) {
            $user = null;
        }

        if (!$user instanceof User) {
            return [
                'enabled' => true,
                'authenticated' => false,
                'user_class' => null,
                'user' => '',
                'roles' => [],
            ];
        }

        return [
            'enabled' => true,
            'authenticated' => $user->isAuthenticated(),
            'user_class' => get_class($user),
            'user' => $user->getUsername(),
            'roles' => $this->users !== null
                ? array_map(fn (Role $role) => $role->name, $this->users->findRoles($user))
                : [],
        ];

    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'auth';
    }
}
