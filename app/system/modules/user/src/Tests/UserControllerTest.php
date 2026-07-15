<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Database\ORM\Repository;
use Pagekit\Module\ModuleManager;
use Pagekit\User\Controller\UserController;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use Pagekit\User\Model\UserRepository;
use Pagekit\User\UserModule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Covers UserController against the injected UserRepository and Repository<Role>:
 * admin views resolve users and roles through repository find/create/query.
 * Collaborators are mocked directly, so no database is required.
 */
class UserControllerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testEditActionCreatesNewUserThroughRepositoryWhenIdIsZero(): void
    {
        $created = new User();
        $created->roles = [Role::ROLE_AUTHENTICATED];

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('create')
            ->with(['roles' => [Role::ROLE_AUTHENTICATED]])
            ->willReturn($created);
        $users->expects($this->never())->method('find');

        $roles = $this->createRoleQuery([]);

        $result = $this->createController($users, $roles)->editAction(0);

        $this->assertSame($created, $result['$data']['user']);
        $this->assertSame('Add User', $result['$view']['title']);
    }

    public function testEditActionLoadsExistingUserThroughRepository(): void
    {
        $existing = $this->createUser(5, 'editor');
        $existing->roles = [Role::ROLE_AUTHENTICATED];

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('find')->with(5)->willReturn($existing);
        $users->expects($this->never())->method('create');

        $roles = $this->createRoleQuery([]);

        $result = $this->createController($users, $roles)->editAction(5);

        $this->assertSame($existing, $result['$data']['user']);
        $this->assertSame('Edit User', $result['$view']['title']);
    }

    public function testEditActionThrowsWhenUserMissing(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('find')->with(404)->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('User not found.');

        $this->createController($users, $this->createRoleQuery([]))->editAction(404);
    }

    public function testPermissionsActionListsRolesFromRepositoryQuery(): void
    {
        $role = $this->createRole(4, 'Editor');

        $roles = $this->createRoleQuery([$role]);

        $result = $this->createController($this->createMock(UserRepository::class), $roles)->permissionsAction();

        $this->assertSame([$role], $result['$data']['roles']);
        $this->assertArrayHasKey('system', $result['$data']['permissions']);
    }

    public function testRolesActionListsRolesFromRepositoryQuery(): void
    {
        $role = $this->createRole(6, 'Author');

        $roles = $this->createRoleQuery([$role]);

        $result = $this->createController($this->createMock(UserRepository::class), $roles)->rolesAction(6);

        $this->assertSame([$role], $result['$data']['roles']);
        $this->assertSame(6, $result['$config']['role']);
    }

    public function testIndexActionExposesUserStatusesAndRolesConfig(): void
    {
        $authenticated = $this->createSerializableRole(Role::ROLE_AUTHENTICATED, 'Authenticated');
        $editor = $this->createSerializableRole(4, 'Editor');

        $roles = $this->createRoleQuery([$authenticated, $editor]);

        $result = $this->createController($this->createMock(UserRepository::class), $roles)
            ->indexAction(['status' => '1'], 2);

        $this->assertSame('Users', $result['$view']['title']);
        $this->assertSame(2, $result['$data']['config']['page']);
        $this->assertEquals((object) ['status' => '1'], $result['$data']['config']['filter']);
        $this->assertCount(1, $result['$data']['config']['roles'], 'the authenticated role is omitted from the index listing');
        $this->assertSame('Editor', $result['$data']['config']['roles'][0]['name']);
    }

    /**
     * @return Role&MockObject
     */
    private function createSerializableRole(int $id, string $name): Role&MockObject
    {
        $role = $this->getMockBuilder(Role::class)
            ->onlyMethods(['jsonSerialize'])
            ->getMock();
        $role->id = $id;
        $role->name = $name;
        $role->method('jsonSerialize')->willReturn(['id' => $id, 'name' => $name]);

        return $role;
    }

    /**
     * @param array<int, Role> $roles
     *
     * @return Repository<Role>&MockObject
     */
    private function createRoleQuery(array $roles): Repository&MockObject
    {
        /** @var QueryBuilder<Role>&MockObject $query */
        $query = $this->createMock(QueryBuilder::class);
        $query->method('__call')->willReturn($query);
        $query->method('get')->willReturn($roles);

        /** @var Repository<Role>&MockObject $repository */
        $repository = $this->createMock(Repository::class);
        $repository->method('where')->with(['id <> ?'], [Role::ROLE_ANONYMOUS])->willReturn($query);
        $repository->method('query')->willReturn($query);

        return $repository;
    }

    private function createUser(int $id, string $username): User
    {
        $user = new User();
        $user->id = $id;
        $user->username = $username;

        return $user;
    }

    private function createRole(int $id, string $name): Role
    {
        $role = new Role();
        $role->id = $id;
        $role->name = $name;

        return $role;
    }

    private function createUserModule(): UserModule
    {
        $userModule = $this->createMock(UserModule::class);
        $userModule->method('config')->willReturnMap([
            ['require_verification', false],
            [[], ['registration' => 'open']],
        ]);
        $userModule->method('getPermissions')->willReturn(['system' => ['perm' => 'label']]);

        return $userModule;
    }

    private function createModuleManager(): ModuleManager
    {
        $module = $this->createMock(ModuleManager::class);
        $module->method('get')->with('system/user')->willReturn($this->createUserModule());

        return $module;
    }

    /**
     * @param Repository<Role> $roleRepository
     */
    private function createController(UserRepository $userRepository, Repository $roleRepository): UserController
    {
        $current = new User();
        $current->id = 1;
        $current->roles = [Role::ROLE_ADMINISTRATOR];

        return new UserController(
            $current,
            $this->createModuleManager(),
            $userRepository,
            $roleRepository,
        );
    }
}
