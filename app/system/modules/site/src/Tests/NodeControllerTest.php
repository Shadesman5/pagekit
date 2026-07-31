<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Pagekit\Application\UrlProvider;
use Pagekit\Database\ORM\Repository;
use Pagekit\Routing\Router;
use Pagekit\Site\Controller\NodeController;
use Pagekit\Site\MenuManager;
use Pagekit\Site\Model\Node;
use Pagekit\Site\Model\NodeRepository;
use Pagekit\Site\SiteModule;
use Pagekit\User\Model\Role;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Covers the admin NodeController against the injected NodeRepository (orphan
 * repair, node find/create) and the generic Repository<Role> (role listing). The
 * repositories and the SiteModule are mocked directly and passed to the
 * controller, so the actions are exercised without a database.
 */
class NodeControllerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testIndexActionRedirectsWhenOrphanedNodesAreRepaired(): void
    {
        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->expects($this->once())->method('fixOrphanedNodes')->willReturn(3);

        $redirect = new RedirectResponse('/admin/site/page');
        $router = $this->createMock(Router::class);
        $router->expects($this->once())->method('redirect')->with('@site/page')->willReturn($redirect);

        $result = $this->createController($nodeRepository, $this->createRoleRepository(), $this->createMock(SiteModule::class), null, $router)->indexAction();

        $this->assertSame($redirect, $result, 'a non-zero orphan repair count must redirect back to the page list');
    }

    public function testIndexActionReturnsListViewWhenNoOrphansRepaired(): void
    {
        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->method('fixOrphanedNodes')->willReturn(0);

        $menu = $this->createMock(MenuManager::class);
        $menu->method('getPositions')->willReturn(['sidebar' => ['name' => 'sidebar', 'label' => 'Sidebar']]);

        $site = $this->createMock(SiteModule::class);
        $site->method('getTypes')->willReturn(['page' => ['id' => 'page', 'label' => 'Page']]);

        $result = $this->createController($nodeRepository, $this->createRoleRepository(), $site, $menu)->indexAction();

        $this->assertIsArray($result);
        $data = $result['$data'];
        $this->assertIsArray($data);
        $config = $data['config'];
        $this->assertIsArray($config);
        $this->assertSame(['sidebar' => ['name' => 'sidebar', 'label' => 'Sidebar']], $config['menus']);
        $this->assertSame([['id' => 'page', 'label' => 'Page']], $data['types'], 'types must be re-indexed via array_values');
    }

    public function testIndexActionReturnsEmptyTypesWhenGetTypesIsNull(): void
    {
        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->method('fixOrphanedNodes')->willReturn(0);

        $site = $this->createMock(SiteModule::class);
        $site->method('getTypes')->willReturn(null);

        $result = $this->createController($nodeRepository, $this->createRoleRepository(), $site)->indexAction();

        $this->assertIsArray($result);
        $data = $result['$data'];
        $this->assertIsArray($data);
        $this->assertSame([], $data['types'], 'a null getTypes() result must become an empty list, not a TypeError');
    }

    public function testEditActionReturnsExistingNodeWithRoles(): void
    {
        $node = new Node();
        $node->id = 8;
        $node->type = 'page';

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->expects($this->once())->method('find')->with('8')->willReturn($node);
        $nodeRepository->expects($this->never())->method('create');

        $role = new Role();
        $role->id = 2;
        $role->name = 'Editor';

        $site = $this->createMock(SiteModule::class);
        $site->method('getType')->with('page')->willReturn(['id' => 'page', 'label' => 'Page']);

        $result = $this->createController($nodeRepository, $this->createRoleRepository([2 => $role]), $site)->editAction('8');

        $this->assertIsArray($result);
        $data = $result['$data'];
        $this->assertIsArray($data);
        $this->assertSame($node, $data['node']);
        $this->assertSame([$role], $data['roles'], 'roles must come from roleRepository->findAll() re-indexed via array_values');
    }

    public function testEditActionThrowsWhenNumericNodeIsMissing(): void
    {
        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->expects($this->once())->method('find')->with('404')->willReturn(null);

        $controller = $this->createController($nodeRepository, $this->createRoleRepository(), $this->createMock(SiteModule::class));

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Node not found.');

        $controller->editAction('404');
    }

    public function testEditActionCreatesNewNodeForNonNumericType(): void
    {
        $node = new Node();
        $node->type = 'link';

        $nodeRepository = $this->createMock(NodeRepository::class);
        // A non-numeric id is a type name: the node is created, never fetched.
        $nodeRepository->expects($this->never())->method('find');
        $nodeRepository->expects($this->once())->method('create')->with(['type' => 'link'])->willReturn($node);

        $site = $this->createMock(SiteModule::class);
        $site->method('getType')->with('link')->willReturn(['id' => 'link', 'label' => 'Link']);

        $result = $this->createController($nodeRepository, $this->createRoleRepository(), $site)->editAction('link');

        $this->assertIsArray($result);
        $data = $result['$data'];
        $this->assertIsArray($data);
        $this->assertSame($node, $data['node']);
        $this->assertSame('', $node->menu, 'an empty menu argument is assigned verbatim to the new node');
    }

    public function testEditActionRedirectsWhenNodeTypeIsUnavailable(): void
    {
        $node = new Node();
        $node->id = 4;
        $node->type = 'blog';

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->expects($this->once())->method('find')->with('4')->willReturn($node);

        $site = $this->createMock(SiteModule::class);
        $site->method('getType')->with('blog')->willReturn(null);

        $redirect = new RedirectResponse('/admin/site/page');
        $router = $this->createMock(Router::class);
        $router->expects($this->once())->method('redirect')->with('@site/page')->willReturn($redirect);

        $result = $this->createController($nodeRepository, $this->createRoleRepository(), $site, null, $router)->editAction('4');

        $this->assertSame($redirect, $result, 'a node whose extension type is gone must return to the page list');
    }

    /**
     * @param Repository<Role> $roleRepository
     */
    private function createController(
        NodeRepository $nodeRepository,
        Repository $roleRepository,
        SiteModule $site,
        ?MenuManager $menu = null,
        ?Router $router = null,
    ): NodeController {
        return new NodeController(
            $site,
            $menu ?? $this->createMock(MenuManager::class),
            $this->createMock(UrlProvider::class),
            $router ?? $this->createMock(Router::class),
            $nodeRepository,
            $roleRepository,
        );
    }

    /**
     * @param  array<int, Role> $roles
     * @return Repository<Role>&MockObject
     */
    private function createRoleRepository(array $roles = []): Repository&MockObject
    {
        /** @var Repository<Role>&MockObject $repository */
        $repository = $this->createMock(Repository::class);
        $repository->method('findAll')->willReturn($roles);

        return $repository;
    }
}
