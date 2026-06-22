<?php

declare(strict_types=1);

namespace Pagekit\User\Controller;

use function Pagekit\__;

use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Request as RequestAttr;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Access(admin: true)]
class UserController
{
    public function __construct(
        private readonly User $user,
        private readonly ModuleManager $module,
    ) {
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    #[Access('user: manage users')]
    #[RequestAttr(['filter' => 'array', 'page' => 'int'])]
    public function indexAction(array $filter = [], ?int $page = null): array
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

    /**
     * @return array<string, mixed>
     */
    #[Access('user: manage users')]
    #[RequestAttr(['id' => 'int'])]
    public function editAction(int $id = 0): array
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
    #[Access('user: manage user permissions')]
    #[RequestAttr(['id' => 'int'])]
    public function rolesAction(?int $id = null): array
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getRoles(?User $user = null): array
    {
        $roles = [];
        $self = $user && $user->id === $this->user->id;
        foreach (Role::where(['id <> ?'], [Role::ROLE_ANONYMOUS])->orderBy('priority')->get() as $role) {
            if (!$role instanceof Role) {
                throw new \LogicException(sprintf(
                    'QueryBuilder::get() returned %s, expected %s',
                    get_class($role),
                    Role::class
                ));
            }

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
