<?php

declare(strict_types=1);

namespace Pagekit\Debug\DataCollector;

use DebugBar\DataCollector\DataCollectorInterface;
use Pagekit\Auth\Auth;
use Pagekit\User\Model\User;

class AuthDataCollector implements DataCollectorInterface
{
    protected ?\Pagekit\Auth\Auth $auth = null;

    /**
     * Constructor.
     *
     * @param Auth $auth
     */
    public function __construct(?Auth $auth = null)
    {
        $this->auth = $auth;
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
            'roles' => array_map(fn ($role) => $role->name, User::findRoles($user)),
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
