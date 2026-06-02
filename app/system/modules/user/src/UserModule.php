<?php

declare(strict_types=1);

namespace Pagekit\User;

use Pagekit\Application as App;
use Pagekit\Module\Module;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;

class UserModule extends Module
{
    protected ?App $app = null;

    /** @var array<string, array<string, mixed>> */
    protected array $perms = [];

    public function main(App $app): mixed
    {
        $this->app = $app;
        $app->set('user', function ($app) {

            if (!$user = $app->get('auth')->getUser()) {
                $user = User::create(['roles' => [Role::ROLE_ANONYMOUS]]);
            }

            return $user;
        });

        return null;
    }

    private function assertBooted(): void
    {
        if ($this->app === null) {
            throw new \LogicException('UserModule::main() has not been called yet.');
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getPermissions(): array
    {
        if (!$this->perms) {
            $this->assertBooted();

            foreach ($this->app->get('module') as $module) {
                if ($perms = $module->get('permissions')) {
                    $this->registerPermissions($module->get('name'), $perms);
                }
            }

            $this->app->get('events')->trigger('user.permission', [$this]);
        }

        return $this->perms;
    }

    /**
     * Register permissions.
     *
     * @param array<string, mixed> $permissions
     */
    public function registerPermissions(string $extension, array $permissions = []): void
    {
        $this->perms[$extension] = $permissions;
    }
}
