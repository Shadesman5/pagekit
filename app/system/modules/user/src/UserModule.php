<?php

declare(strict_types=1);

namespace Pagekit\User;

use Pagekit\Application as App;
use Pagekit\Module\Module;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\UserRepository;

class UserModule extends Module
{
    protected ?App $app = null;

    /** @var array<string, array<string, mixed>> */
    protected array $perms = [];

    /**
     * @return mixed Genuinely unknown type — overrides Module::main(); the return value is not consumed by the framework (inherited contract from ModuleInterface).
     */
    public function main(App $app): mixed
    {
        $this->app = $app;

        $app->set('userRepository', fn ($app) => new UserRepository($app->get('db.em')));

        $app->set('roleRepository', fn ($app) => $app->get('db.em')->getRepository(Role::class));

        $app->set('user', function ($app) {

            if (!$user = $app->get('auth')->getUser()) {
                $user = $app->get('userRepository')->create(['roles' => [Role::ROLE_ANONYMOUS]]);
            }

            return $user;
        });

        return null;
    }

    private function assertBooted(): App
    {
        if ($this->app === null) {
            throw new \LogicException('UserModule::main() has not been called yet.');
        }

        return $this->app;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getPermissions(): array
    {
        if (!$this->perms) {
            $app = $this->assertBooted();

            foreach ($app->get('module') as $module) {
                if ($perms = $module->get('permissions')) {
                    $this->registerPermissions($module->get('name'), $perms);
                }
            }

            $app->get('events')->trigger('user.permission', [$this]);
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
