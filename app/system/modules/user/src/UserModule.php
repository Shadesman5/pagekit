<?php

namespace Pagekit\User;

use Pagekit\Application as App;
use Pagekit\Module\Module;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;

class UserModule extends Module
{
    protected array $perms = [];

    /**
     * {@inheritdoc}
     */
    public function main(App $app): void
    {
        $app['user'] = function ($app) { // TODO: Must be refactored in Step 2.0.1d (Packages + ArrayAccess Removal)

            if (!$user = $app->get('auth')->getUser()) {
                $user = User::create(['roles' => [Role::ROLE_ANONYMOUS]]);
            }

            return $user;
        };
    }

    public function getPermissions(): array
    {
        if (!$this->perms) {

            foreach (App::module() as $module) {
                if ($perms = $module->get('permissions')) {
                    $this->registerPermissions($module->get('name'), $perms);
                }
            }

            App::trigger('user.permission', [$this]);
        }

        return $this->perms;
    }

    /**
     * Register permissions.
     *
     * @param string $extension
     * @param array  $permissions
     */
    public function registerPermissions($extension, array $permissions = []): void
    {
        $this->perms[$extension] = $permissions;
    }
}
