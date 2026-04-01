<?php

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

        if (null === $user) {
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
            // TODO: Must be refactored in Step 2.1.4 (PHPStan Level 5→6 — Return Types) —
            // Auth::getUser() returns UserInterface which lacks isAuthenticated() and roles.
            // Either extend UserInterface or type-narrow $user to User here.
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
