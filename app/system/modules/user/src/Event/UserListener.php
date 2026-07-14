<?php

declare(strict_types=1);

namespace Pagekit\User\Event;

use Pagekit\Auth\Event\LoginEvent;
use Pagekit\Event\EventInterface;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use Pagekit\User\Model\UserRepository;

class UserListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Updates user's last login time
     */
    public function onUserLogin(LoginEvent $event): void
    {
        $user = $event->getUser();

        if ($user instanceof User) {
            $this->users->updateLogin($user);
        }
    }

    public function onRoleDelete(EventInterface $event, Role $role): void
    {
        $this->users->removeRole((int) $role->id);
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, string>
     */
    public function subscribe(): array
    {
        return [
            'auth.login' => 'onUserLogin',
            'model.role.deleted' => 'onRoleDelete',
        ];
    }
}
