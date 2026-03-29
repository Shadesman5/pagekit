<?php

declare(strict_types=1);

namespace Pagekit\User\Controller;

use function Pagekit\__;

use Pagekit\Routing\Attribute\Request;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Access(admin: true)]
class UserController
{
    public function __construct(
        private readonly mixed $user,
        private readonly mixed $module,
    ) {
    }

    #[Access('user: manage users')]
    #[Request(['filter' => 'array', 'page' => 'int'])]
    public function indexAction($filter = [], $page = null): array
    {
        $roles = $this->getRoles();
        unset($roles[Role::ROLE_AUTHENTICATED]);

        return [
            '$view' => [
                'title' => __('Users'),
                'name' => 'system/user/admin/user-index.php',
            ],
            '$data' => [
                'config' => [
                    'statuses' => User::getStatuses(),
                    'roles' => array_values($roles),
                    'emailVerification' => $this->module->get('system/user')->config('require_verification'),
                    'filter' => (object) $filter,
                    'page' => $page,
                ],
            ],
        ];
    }

    #[Access('user: manage users')]
    #[Request(['id' => 'int'])]
    public function editAction($id = 0): array
    {
        if (!$id) {
            $user = User::create(['roles' => [Role::ROLE_AUTHENTICATED]]);
        } elseif (!$user = User::find($id)) {
            throw new NotFoundHttpException('User not found.');
        }

        return [
            '$view' => [
                'title' => $id ? __('Edit User') : __('Add User'),
                'name' => 'system/user/admin/user-edit.php',
            ],
            '$data' => [
                'user' => $user,
                'config' => [
                    'statuses' => User::getStatuses(),
                    'roles' => array_values($this->getRoles($user)),
                    'emailVerification' => $this->module->get('system/user')->config('require_verification'),
                    'currentUser' => $this->user->id,
                ],
            ],
        ];
    }

    #[Access('user: manage user permissions')]
    public function permissionsAction(): array
    {
        return [
            '$view' => [
                'title' => __('Permissions'),
                'name' => 'system/user/admin/permission-index.php',
            ],
            '$data' => [
                'permissions' => $this->module->get('system/user')->getPermissions(),
                'roles' => array_values(Role::query()->orderBy('priority')->get()),
            ],
        ];
    }

    #[Access('user: manage user permissions')]
    #[Request(['id' => 'int'])]
    public function rolesAction($id = null): array
    {
        return [
            '$view' => [
                'title' => __('Roles'),
                'name' => 'system/user/admin/role-index.php',
            ],
            '$config' => [
                'role' => $id,
            ],
            '$data' => [
                'permissions' => $this->module->get('system/user')->getPermissions(),
                'roles' => array_values(Role::query()->orderBy('priority')->get()),
            ],
        ];
    }

    #[Access('system: access settings')]
    public function settingsAction(): array
    {
        return [
            '$view' => [
                'title' => __('User Settings'),
                'name' => 'system/user/admin/settings.php',
            ],
            '$data' => [
                'config' => $this->module->get('system/user')->config(),
            ],
        ];
    }

    protected function getRoles(?User $user = null): array
    {
        $roles = [];
        $self = $user && $user->id === $this->user->id;
        foreach (Role::where(['id <> ?'], [Role::ROLE_ANONYMOUS])->orderBy('priority')->get() as $role) {

            $r = $role->jsonSerialize();

            if ($role->isAuthenticated()) {
                $r['disabled'] = true;
            }

            if ($user && $role->isAdministrator() && (!$this->user->isAdministrator() || $self)) {
                $r['disabled'] = true;
            }

            $roles[$r['id']] = $r;
        }

        return $roles;
    }
}
