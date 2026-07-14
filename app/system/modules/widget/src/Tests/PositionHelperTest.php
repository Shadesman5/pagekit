<?php

declare(strict_types=1);

namespace Pagekit\Widget\Tests;

use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Database\ORM\Repository;
use Pagekit\Site\Model\Node;
use Pagekit\User\Model\User;
use Pagekit\View\View;
use Pagekit\Widget\Model\TypeInterface;
use Pagekit\Widget\Model\Widget;
use Pagekit\Widget\PositionHelper;
use Pagekit\Widget\PositionManager;
use Pagekit\Widget\WidgetManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers PositionHelper after its Step 6 migration off the static Widget model
 * API. The active-widget set is now pulled through the injected
 * Repository<Widget> (a `where(['status' => 1])->get()` finder) rather than the
 * former static model query, and the two former function statics (`$widgets` /
 * `$positions`) became the instance properties `$activeWidgets` /
 * `$renderedPositions`, so each per-request helper memoizes its own lookups and
 * no widget state leaks across helper instances (the reason the old suite needed
 * process isolation).
 *
 * The repository, PositionManager, WidgetManager and View collaborators are
 * mocked, so the access/node/type gate the position render applies is asserted
 * without a database or a booted view engine.
 */
class PositionHelperTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testRenderIncludesAccessibleAssignedWidgetLoadedFromRepository(): void
    {
        $widget = $this->createWidget(1, type: 'text');

        $type = $this->createMock(TypeInterface::class);
        $type->method('render')->with($widget)->willReturn('RENDERED HTML');

        $helper = $this->createHelper(
            $this->createActiveWidgetsRepository([1 => $widget]),
            $this->createPositions(['sidebar' => [1]]),
            $this->createWidgetManager(['text' => $type]),
        );

        $rendered = $this->captureRenderedWidgets($helper, 'sidebar');

        $this->assertSame([$widget], $rendered, 'the accessible assigned widget must be handed to the view');
        $this->assertSame('RENDERED HTML', $widget->get('result'), 'the rendered type output must be stored on the widget');
    }

    public function testRenderExcludesInaccessibleRestrictedAndUnknownTypeWidgets(): void
    {
        $accessible = $this->createWidget(1, type: 'text');
        $forbidden = $this->createWidget(2, type: 'text', roles: [99]);
        $otherNode = $this->createWidget(3, type: 'text', nodes: [7]);
        $unknownType = $this->createWidget(4, type: 'ghost');

        $type = $this->createMock(TypeInterface::class);
        $type->method('render')->willReturn('OK');

        $helper = $this->createHelper(
            $this->createActiveWidgetsRepository([1 => $accessible, 2 => $forbidden, 3 => $otherNode, 4 => $unknownType]),
            $this->createPositions(['sidebar' => [1, 2, 3, 4]]),
            // Only 'text' resolves to a renderable type; 'ghost' is unknown.
            $this->createWidgetManager(['text' => $type]),
            currentNodeId: 1,
        );

        $rendered = $this->captureRenderedWidgets($helper, 'sidebar');

        $this->assertSame(
            [$accessible],
            $rendered,
            'a widget must be dropped when the user lacks its role, the current node is outside its node list, or its type is unknown'
        );
    }

    public function testActiveWidgetSetIsQueriedOnceAndMemoizedPerInstance(): void
    {
        /** @var QueryBuilder<Widget>&MockObject $query */
        $query = $this->createMock(QueryBuilder::class);
        $query->method('get')->willReturn([]);

        /** @var Repository<Widget>&MockObject $repository */
        $repository = $this->createMock(Repository::class);
        // The status-1 lookup happens once, then the instance-level cache serves
        // every later call (the former `static $widgets` is now $activeWidgets).
        $repository->expects($this->once())
            ->method('where')
            ->with(['status' => 1])
            ->willReturn($query);

        $helper = $this->createHelper($repository, $this->createPositions([]), $this->createWidgetManager([]));

        $helper->exists('sidebar');
        $helper->exists('footer');
        $helper->exists('sidebar');
    }

    public function testSeparateInstancesLoadTheirOwnWidgetState(): void
    {
        $type = $this->createMock(TypeInterface::class);
        $type->method('render')->willReturn('OK');

        $first = $this->createWidget(1, type: 'text');
        $second = $this->createWidget(2, type: 'text');

        $helperA = $this->createHelper(
            $this->createActiveWidgetsRepository([1 => $first]),
            $this->createPositions(['sidebar' => [1, 2]]),
            $this->createWidgetManager(['text' => $type]),
        );
        $helperB = $this->createHelper(
            $this->createActiveWidgetsRepository([2 => $second]),
            $this->createPositions(['sidebar' => [1, 2]]),
            $this->createWidgetManager(['text' => $type]),
        );

        // With the former function static the second helper would have reused the
        // first helper's cached widget set; instance properties keep them apart.
        $this->assertSame([$first], $this->captureRenderedWidgets($helperA, 'sidebar'));
        $this->assertSame([$second], $this->captureRenderedWidgets($helperB, 'sidebar'));
    }

    public function testExistsReturnsFalseForUnregisteredPosition(): void
    {
        $helper = $this->createHelper(
            $this->createActiveWidgetsRepository([1 => $this->createWidget(1, type: 'text')]),
            $this->createPositions(['sidebar' => [1]]),
            $this->createWidgetManager([]),
        );

        $this->assertFalse($helper->exists('nonexistent'), 'an unknown position resolves to no widgets');
    }

    /**
     * @param array<int, int>        $roles
     * @param array<int, int|string> $nodes
     */
    private function createWidget(int $id, string $type, array $roles = [], array $nodes = []): Widget
    {
        $widget = new Widget();
        $widget->id = $id;
        $widget->status = 1;
        $widget->type = $type;
        $widget->roles = $roles;
        $widget->nodes = $nodes;

        return $widget;
    }

    /**
     * Builds a Repository<Widget> whose status-1 query returns the given set,
     * keyed by widget id (the shape PositionHelper indexes by assigned id).
     *
     * @param  array<int, Widget> $widgets
     * @return Repository<Widget>&MockObject
     */
    private function createActiveWidgetsRepository(array $widgets): Repository&MockObject
    {
        /** @var QueryBuilder<Widget>&MockObject $query */
        $query = $this->createMock(QueryBuilder::class);
        $query->method('get')->willReturn($widgets);

        /** @var Repository<Widget>&MockObject $repository */
        $repository = $this->createMock(Repository::class);
        $repository->method('where')->with(['status' => 1])->willReturn($query);

        return $repository;
    }

    /**
     * @param array<string, array<int, int>> $assignments position name => assigned widget ids
     */
    private function createPositions(array $assignments): PositionManager
    {
        $positions = $this->createMock(PositionManager::class);
        $positions->method('get')->willReturnCallback(
            static fn (string $name): ?array => isset($assignments[$name])
                ? ['name' => $name, 'assigned' => $assignments[$name]]
                : null
        );

        return $positions;
    }

    /**
     * @param array<string, TypeInterface> $types widget type name => renderable type
     */
    private function createWidgetManager(array $types): WidgetManager
    {
        $widget = $this->createMock(WidgetManager::class);
        $widget->method('get')->willReturnCallback(
            static fn (string $name): ?TypeInterface => $types[$name] ?? null
        );

        return $widget;
    }

    /**
     * @param Repository<Widget> $widgets
     */
    private function createHelper(
        Repository $widgets,
        PositionManager $positions,
        WidgetManager $widget,
        int $currentNodeId = 1,
    ): PositionHelper {
        $user = new User();
        $user->roles = [];

        $node = new Node();
        $node->id = $currentNodeId;

        return new PositionHelper($positions, $user, $node, $widget, $widgets);
    }

    /**
     * Registers a View that records the widgets handed to it, then renders the
     * position — the only observable output of the protected getWidgets() gate.
     * The captured value is returned as mixed because it is read back out of the
     * View::render() parameter bag (asserted against a concrete list by callers).
     */
    private function captureRenderedWidgets(PositionHelper $helper, string $position): mixed
    {
        $captured = null;

        $view = $this->createMock(View::class);
        $view->method('render')->willReturnCallback(
            function (string $name, array $parameters = []) use (&$captured): string {
                $captured = $parameters['widgets'] ?? null;

                return '';
            }
        );

        $helper->register($view);
        $helper->render($position);

        return $captured;
    }
}
