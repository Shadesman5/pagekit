<?php

declare(strict_types=1);

namespace Pagekit\User\Event;

use Pagekit\Auth\Event\LoginEvent;
use Pagekit\Event\Event;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;

class UserListener implements EventSubscriberInterface
{
    /**
     * Updates user's last login time
     */
    public function onUserLogin(LoginEvent $event): void
    {
        User::updateLogin($event->getUser());
    }

    public function onRoleDelete(Event $event, Role|int $role): void
    {
        User::removeRole($role);
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
