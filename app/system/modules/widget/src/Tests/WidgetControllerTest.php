<?php

declare(strict_types=1);

namespace Pagekit\Widget\Tests;

use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Database\ORM\Repository;
use Pagekit\Site\MenuManager;
use Pagekit\Site\Model\Node;
use Pagekit\Site\Model\NodeRepository;
use Pagekit\User\Model\Role;
use Pagekit\Widget\Controller\WidgetController;
use Pagekit\Widget\Model\Widget;
use Pagekit\Widget\PositionManager;
use Pagekit\Widget\WidgetManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Covers the admin WidgetController against injected repositories: the widget
 * listing and the create/find + role listing flow through the generic
 * Repository<Widget>, the injected NodeRepository (`query()->get()`) and the
 * generic Repository<Role>. The repositories and managers are mocked directly,
 * so the controller actions are exercised without a database.
 */
class WidgetControllerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testIndexActionListsWidgetsNodesTypesAndMenus(): void
    {
        $first = $this->createWidget(5);
        $second = $this->createWidget(8);

        $widgetRepository = $this->createRepository();
        // Repository::findAll() is keyed by id; the view expects a plain list.
        $widgetRepository->method('findAll')->willReturn([5 => $first, 8 => $second]);

        $node = new Node();
        $node->id = 3;

        $menu = $this->createMock(MenuManager::class);

        $widget = $this->createMock(WidgetManager::class);
        $widget->method('all')->willReturn(['text' => ['name' => 'text', 'label' => 'Text']]);

        $result = $this->createController(
            $widgetRepository,
            $this->createNodeRepository([3 => $node]),
            menu: $menu,
            widget: $widget,
        )->indexAction();

        $data = $result['$data'];
        $this->assertIsArray($data);
        $this->assertSame([$first, $second], $data['widgets'], 'widgets must be re-indexed via array_values(findAll())');
        $this->assertSame(['text' => ['name' => 'text', 'label' => 'Text']], $data['types']);

        $config = $data['config'];
        $this->assertIsArray($config);
        $this->assertSame($menu, $config['menus'], 'the MenuManager is handed to the view as-is');
        $this->assertSame([$node], $config['nodes'], 'nodes come from nodeRepository->query()->get(), re-indexed');
    }

    public function testEditActionCreatesNewWidgetForTypeAndListsRoles(): void
    {
        $created = $this->createWidget(null);
        $created->type = 'text';

        $widgetRepository = $this->createRepository();
        $widgetRepository->expects($this->once())->method('create')->with(['type' => 'text'])->willReturn($created);
        $widgetRepository->expects($this->never())->method('find');

        $role = new Role();
        $role->id = 2;

        $result = $this->createController(
            $widgetRepository,
            roleRepository: $this->createRoleRepository([2 => $role]),
        )->editAction(0, 'text');

        $data = $result['$data'];
        $this->assertIsArray($data);
        $this->assertSame($created, $data['widget']);

        $config = $data['config'];
        $this->assertIsArray($config);
        $this->assertSame([$role], $config['roles'], 'roles come from roleRepository->findAll(), re-indexed via array_values');
    }

    public function testEditActionLoadsWidgetAndResolvesItsAssignedPosition(): void
    {
        $widget = $this->createWidget(5);

        $widgetRepository = $this->createRepository();
        $widgetRepository->expects($this->once())->method('find')->with(5)->willReturn($widget);
        $widgetRepository->expects($this->never())->method('create');

        $position = $this->createMock(PositionManager::class);
        $position->method('all')->willReturn([
            'footer' => ['name' => 'footer', 'assigned' => [9]],
            'sidebar' => ['name' => 'sidebar', 'assigned' => [5]],
        ]);

        $result = $this->createController($widgetRepository, position: $position)->editAction(5);

        $data = $result['$data'];
        $this->assertIsArray($data);
        $this->assertSame($widget, $data['widget']);
        $this->assertSame('sidebar', $widget->position, 'the position holding the widget id must be resolved onto the widget');
    }

    public function testEditActionThrowsWhenWidgetIsMissing(): void
    {
        $widgetRepository = $this->createRepository();
        $widgetRepository->expects($this->once())->method('find')->with(99)->willReturn(null);

        $controller = $this->createController($widgetRepository);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Widget not found.');

        $controller->editAction(99);
    }

    private function createWidget(?int $id): Widget
    {
        $widget = new Widget();
        $widget->id = $id;
        $widget->type = 'text';

        return $widget;
    }

    /**
     * @param Repository<Widget>    $widgetRepository
     * @param Repository<Role>|null $roleRepository
     */
    private function createController(
        Repository $widgetRepository,
        ?NodeRepository $nodeRepository = null,
        ?Repository $roleRepository = null,
        ?WidgetManager $widget = null,
        ?MenuManager $menu = null,
        ?PositionManager $position = null,
    ): WidgetController {
        return new WidgetController(
            $widget ?? $this->createMock(WidgetManager::class),
            $menu ?? $this->createMock(MenuManager::class),
            $position ?? $this->createMock(PositionManager::class),
            $widgetRepository,
            $nodeRepository ?? $this->createNodeRepository([]),
            $roleRepository ?? $this->createRoleRepository(),
        );
    }

    /**
     * @return Repository<Widget>&MockObject
     */
    private function createRepository(): Repository&MockObject
    {
        /** @var Repository<Widget>&MockObject $repository */
        $repository = $this->createMock(Repository::class);

        return $repository;
    }

    /**
     * @param  array<int, Node> $nodes the nodes NodeRepository::query()->get() yields
     * @return NodeRepository&MockObject
     */
    private function createNodeRepository(array $nodes): NodeRepository&MockObject
    {
        /** @var QueryBuilder<Node>&MockObject $query */
        $query = $this->createMock(QueryBuilder::class);
        $query->method('get')->willReturn($nodes);

        $repository = $this->createMock(NodeRepository::class);
        $repository->method('query')->willReturn($query);

        return $repository;
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
