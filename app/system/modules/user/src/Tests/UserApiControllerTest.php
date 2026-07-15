<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\Auth\Encoder\PasswordEncoderInterface;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Module\ModuleManager;
use Pagekit\User\Controller\UserApiController;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use Pagekit\User\Model\UserRepository;
use Pagekit\User\UserModule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Covers UserApiController against the injected UserRepository: listing/counting,
 * lookup, save (create/update), delete and bulk actions drive
 * query/find/create/save/delete through the repository. The repository and query
 * builder are mocked; a real Symfony validator exercises the save path's
 * validateOrFail() gate, so no database is required.
 */
class UserApiControllerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testIndexActionPaginatesUsersFromRepositoryQuery(): void
    {
        $first = $this->createUser(1, 'alice');
        $second = $this->createUser(2, 'bob');

        $query = $this->createListingQuery([$first, $second], 2);

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('query')->willReturn($query);

        $request = new Request(['page' => 0, 'limit' => 20]);
        $result = $this->createController($users, request: $request)->indexAction();

        $this->assertSame([$first, $second], $result['users']);
        $this->assertSame(1.0, $result['pages']);
        $this->assertSame(2, $result['count']);
    }

    public function testCountActionReturnsFilteredCountFromRepositoryQuery(): void
    {
        $query = $this->createListingQuery([], 5);

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('query')->willReturn($query);

        $request = new Request(['filter' => ['status' => '1']]);
        $result = $this->createController($users, request: $request)->countAction();

        $this->assertSame(['count' => 5], $result);
    }

    public function testGetActionReturnsUserById(): void
    {
        $user = $this->createUser(5, 'carol');

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('find')->with(5)->willReturn($user);

        $this->assertSame($user, $this->createController($users)->getAction(5));
    }

    public function testGetActionThrowsWhenUserMissing(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('find')->with(404)->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('User not found.');

        $this->createController($users)->getAction(404);
    }

    public function testSaveActionCreatesValidatesAndPersistsNewUser(): void
    {
        $user = new User();

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('find')->with(0)->willReturn(null);
        $users->expects($this->once())->method('create')->willReturn($user);
        $users->expects($this->once())->method('save')->with(
            $this->identicalTo($user),
            $this->callback(static fn (array $data): bool => ($data['roles'] ?? null) === [Role::ROLE_AUTHENTICATED])
        );

        $encoder = $this->createMock(PasswordEncoderInterface::class);
        $encoder->expects($this->once())->method('hash')->with('secret')->willReturn('hashed');

        $request = new Request([], [
            'user' => [
                'name' => 'New User',
                'username' => 'newuser',
                'email' => 'new@example.com',
                'roles' => [Role::ROLE_AUTHENTICATED],
            ],
            'password' => 'secret',
        ]);

        $result = $this->createController($users, request: $request, encoder: $encoder)->saveAction();

        $this->assertSame('success', $result['message']);
        $this->assertSame($user, $result['user']);
        $this->assertSame('hashed', $user->password);
    }

    public function testSaveActionUpdatesExistingUserThroughRepository(): void
    {
        $user = $this->createUser(3, 'existing');
        $user->roles = [Role::ROLE_AUTHENTICATED];

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('find')->with(3)->willReturn($user);
        $users->expects($this->never())->method('create');
        $users->expects($this->once())->method('save')->with(
            $user,
            $this->callback(static fn (array $data): bool => $data['name'] === 'Updated'
                && ($data['roles'] ?? null) === [Role::ROLE_AUTHENTICATED])
        );

        $request = new Request([], [
            'user' => [
                'name' => 'Updated',
                'username' => 'existing',
                'email' => 'existing@example.com',
                'roles' => [Role::ROLE_AUTHENTICATED],
            ],
        ]);

        $result = $this->createController($users, request: $request)->saveAction(3);

        $this->assertSame('success', $result['message']);
        $this->assertSame('Updated', $user->name);
    }

    public function testDeleteActionRemovesUserThroughRepository(): void
    {
        $target = $this->createUser(9, 'delete-me');
        $target->roles = [Role::ROLE_AUTHENTICATED];

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('find')->with(9)->willReturn($target);
        $users->expects($this->once())->method('delete')->with($target);

        $current = $this->createUser(1, 'admin');
        $current->roles = [Role::ROLE_ADMINISTRATOR];

        $result = $this->createController($users, current: $current)->deleteAction(9);

        $this->assertSame('success', $result['message']);
    }

    public function testDeleteActionRejectsSelfDeletion(): void
    {
        $current = $this->createUser(4, 'self');
        $current->roles = [Role::ROLE_ADMINISTRATOR];

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('find');
        $users->expects($this->never())->method('delete');

        $this->expectException(BadRequestHttpException::class);

        $this->createController($users, current: $current)->deleteAction(4);
    }

    public function testBulkDeleteActionDelegatesEachIdToDeleteAction(): void
    {
        $first = $this->createUser(10, 'one');
        $first->roles = [Role::ROLE_AUTHENTICATED];
        $second = $this->createUser(11, 'two');
        $second->roles = [Role::ROLE_AUTHENTICATED];

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->exactly(2))
            ->method('find')
            ->willReturnMap([[10, $first], [11, $second]]);
        $users->expects($this->exactly(2))->method('delete');

        $current = $this->createUser(1, 'admin');
        $current->roles = [Role::ROLE_ADMINISTRATOR];

        $request = new Request([], ['ids' => [10, 11]]);

        $result = $this->createController($users, current: $current, request: $request)->bulkDeleteAction();

        $this->assertSame('success', $result['message']);
    }

    /**
     * @param array<int, User> $entities
     *
     * @return QueryBuilder<User>&MockObject
     */
    private function createListingQuery(array $entities, int $count): QueryBuilder&MockObject
    {
        /** @var QueryBuilder<User>&MockObject $query */
        $query = $this->createMock(QueryBuilder::class);
        $query->method('__call')->willReturnCallback(
            function (string $name, array $args) use ($query, $count): mixed {
                return $name === 'count' ? $count : $query;
            }
        );
        $query->method('get')->willReturn($entities);

        return $query;
    }

    private function createUser(int $id, string $username): User
    {
        $user = new User();
        $user->id = $id;
        $user->username = $username;
        $user->email = $username . '@example.com';
        $user->name = ucfirst($username);

        return $user;
    }

    private function createModuleManager(int $usersPerPage = 20): ModuleManager
    {
        $userModule = $this->createMock(UserModule::class);
        $userModule->method('config')->with('users_per_page')->willReturn($usersPerPage);

        $module = $this->createMock(ModuleManager::class);
        $module->method('get')->with('system/user')->willReturn($userModule);

        return $module;
    }

    private function createController(
        UserRepository $userRepository,
        ?User $current = null,
        ?Request $request = null,
        ?PasswordEncoderInterface $encoder = null,
    ): UserApiController {
        $current ??= $this->adminUser();

        $violations = $this->createMock(ConstraintViolationListInterface::class);
        $violations->method('count')->willReturn(0);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn($violations);

        return new UserApiController(
            $request ?? new Request(),
            $current,
            $this->createModuleManager(),
            $encoder ?? $this->createMock(PasswordEncoderInterface::class),
            $validator,
            $userRepository,
        );
    }

    private function adminUser(): User
    {
        $user = new User();
        $user->id = 1;
        $user->roles = [Role::ROLE_ADMINISTRATOR];

        return $user;
    }
}
