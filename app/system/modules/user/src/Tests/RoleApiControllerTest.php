<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\Database\ORM\Repository;
use Pagekit\Intl\Loader\PhpFileLoader;
use Pagekit\User\Controller\RoleApiController;
use Pagekit\User\Model\Role;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Covers RoleApiController after its Step 5 migration onto the injected
 * Repository<Role>: listing, lookup, save (create/update), delete and bulk
 * actions now drive findAll/find/create/save/delete through the repository
 * instead of the former static Role model API. The repository is mocked
 * directly with a real Symfony validator on the save path, so no database is
 * required.
 */
class RoleApiControllerTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        $translator = new Translator('en_US');
        $translator->addLoader('php', new PhpFileLoader());
        $translator->addResource(
            'php',
            __DIR__ . '/../../../../languages/en_US/validators.php',
            'en_US',
            'validators'
        );

        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->setTranslator($translator)
            ->setTranslationDomain('validators')
            ->getValidator();
    }

    public function testIndexActionReturnsAllRolesReindexed(): void
    {
        $editor = $this->createRole(4, 'Editor');
        $author = $this->createRole(5, 'Author');

        $roles = $this->createRepository();
        $roles->expects($this->once())->method('findAll')->willReturn([4 => $editor, 5 => $author]);

        $result = $this->createController($roles)->indexAction();

        $this->assertSame([$editor, $author], $result, 'the id-keyed set must be re-indexed via array_values');
    }

    public function testGetActionReturnsRoleById(): void
    {
        $role = $this->createRole(3, 'Administrator');

        $roles = $this->createRepository();
        $roles->expects($this->once())->method('find')->with(3)->willReturn($role);

        $this->assertSame($role, $this->createController($roles)->getAction(3));
    }

    public function testGetActionThrowsWhenRoleMissing(): void
    {
        $roles = $this->createRepository();
        $roles->expects($this->once())->method('find')->with(404)->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->createController($roles)->getAction(404);
    }

    public function testSaveActionCreatesValidatesAndPersistsNewRole(): void
    {
        $role = new Role();

        $roles = $this->createRepository();
        $roles->expects($this->once())->method('find')->with(0)->willReturn(null);
        $roles->expects($this->once())->method('create')->willReturn($role);
        $roles->expects($this->once())->method('save')->with($this->identicalTo($role), ['name' => 'Editor', 'priority' => 10]);

        $result = $this->createController($roles)->saveAction(0, ['name' => 'Editor', 'priority' => 10]);

        $this->assertSame('success', $result['message']);
        $this->assertSame($role, $result['role']);
        $this->assertSame('Editor', $role->name);
        $this->assertSame(10, $role->priority);
    }

    public function testSaveActionUpdatesExistingRoleThroughRepository(): void
    {
        $role = $this->createRole(7, 'Old name');

        $roles = $this->createRepository();
        $roles->expects($this->once())->method('find')->with(7)->willReturn($role);
        $roles->expects($this->never())->method('create');
        $roles->expects($this->once())->method('save')->with($role, ['name' => 'New name', 'priority' => 5]);

        $result = $this->createController($roles)->saveAction(7, ['name' => 'New name', 'priority' => 5]);

        $this->assertSame('success', $result['message']);
        $this->assertSame('New name', $role->name);
        $this->assertSame(5, $role->priority);
    }

    public function testDeleteActionRemovesRoleThroughRepository(): void
    {
        $role = $this->createRole(8, 'Disposable');

        $roles = $this->createRepository();
        $roles->expects($this->once())->method('find')->with(8)->willReturn($role);
        $roles->expects($this->once())->method('delete')->with($role);

        $result = $this->createController($roles)->deleteAction(8);

        $this->assertSame('success', $result['message']);
    }

    public function testBulkSaveActionDelegatesEachRoleToSaveAction(): void
    {
        $role = $this->createRole(2, 'Two');

        $roles = $this->createRepository();
        $roles->method('find')->willReturnMap([[0, null], [2, $role]]);
        $roles->method('create')->willReturn(new Role());
        $roles->expects($this->exactly(2))->method('save');

        $request = new Request([], ['roles' => [
            ['name' => 'One', 'priority' => 1],
            ['id' => 2, 'name' => 'Two', 'priority' => 2],
        ]]);

        $result = $this->createController($roles, $request)->bulkSaveAction();

        $this->assertSame('success', $result['message']);
    }

    public function testBulkDeleteActionDelegatesEachIdToDeleteAction(): void
    {
        $first = $this->createRole(1, 'One');
        $second = $this->createRole(2, 'Two');

        $roles = $this->createRepository();
        $roles->expects($this->exactly(2))
            ->method('find')
            ->willReturnMap([[1, $first], [2, $second]]);
        $roles->expects($this->exactly(2))->method('delete');

        $request = new Request([], ['ids' => [1, 2, 0]]);

        $result = $this->createController($roles, $request)->bulkDeleteAction();

        $this->assertSame('success', $result['message']);
    }

    private function createRole(int $id, string $name): Role
    {
        $role = new Role();
        $role->id = $id;
        $role->name = $name;

        return $role;
    }

    /**
     * @return Repository<Role>&MockObject
     */
    private function createRepository(): Repository&MockObject
    {
        /** @var Repository<Role>&MockObject $repository */
        $repository = $this->createMock(Repository::class);

        return $repository;
    }

    /**
     * @param Repository<Role> $roleRepository
     */
    private function createController(Repository $roleRepository, ?Request $request = null): RoleApiController
    {
        return new RoleApiController(
            $request ?? new Request(),
            $this->validator,
            $roleRepository,
        );
    }
}
