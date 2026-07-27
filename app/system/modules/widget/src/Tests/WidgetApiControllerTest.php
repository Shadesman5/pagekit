<?php

declare(strict_types=1);

namespace Pagekit\Widget\Tests;

use Pagekit\Database\ORM\Repository;
use Pagekit\Intl\Loader\PhpFileLoader;
use Pagekit\Widget\Controller\WidgetApiController;
use Pagekit\Widget\Model\Widget;
use Pagekit\Widget\PositionManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Covers WidgetApiController against the injected Repository<Widget>: index
 * grouping, get, create/update save, delete and copy drive
 * find/create/save/delete through the repository. The repository and
 * PositionManager are mocked; a real Symfony validator (attribute mapping)
 * exercises the save path's validateOrFail() gate, so no database is required.
 */
class WidgetApiControllerTest extends TestCase
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

    public function testIndexActionGroupsAssignedWidgetsAndReturnsUnassigned(): void
    {
        $first = $this->createWidget(1);
        $second = $this->createWidget(2);
        $loose = $this->createWidget(3);

        $widgetRepository = $this->createRepository();
        // Repository::findAll() is keyed by id, so the position grouping can
        // pluck assigned widgets out of the pool by id.
        $widgetRepository->method('findAll')->willReturn([1 => $first, 2 => $second, 3 => $loose]);

        $position = $this->createMock(PositionManager::class);
        $position->method('all')->willReturn(['sidebar' => ['name' => 'sidebar', 'assigned' => [1, 2]]]);

        $result = $this->createController($widgetRepository, $position)->indexAction();

        $this->assertCount(1, $result['positions']);
        $this->assertSame([$first, $second], $result['positions'][0]['widgets'], 'assigned widgets are grouped under their position');
        $this->assertSame([$loose], $result['unassigned'], 'widgets in no position fall through to unassigned');
    }

    public function testGetActionReturnsWidgetWithResolvedPosition(): void
    {
        $widget = $this->createWidget(5);

        $widgetRepository = $this->createRepository();
        $widgetRepository->expects($this->once())->method('find')->with(5)->willReturn($widget);

        $position = $this->createMock(PositionManager::class);
        $position->method('all')->willReturn(['sidebar' => ['name' => 'sidebar', 'assigned' => [5]]]);

        $result = $this->createController($widgetRepository, $position)->getAction(5);

        $this->assertSame($widget, $result);
        $this->assertSame('sidebar', $widget->position, 'the assigned position name is resolved onto the returned widget');
    }

    public function testGetActionThrowsWhenWidgetMissing(): void
    {
        $widgetRepository = $this->createRepository();
        $widgetRepository->expects($this->once())->method('find')->with(404)->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Widget not found.');

        $this->createController($widgetRepository)->getAction(404);
    }

    public function testSaveActionCreatesValidatesAndPersistsNewWidget(): void
    {
        $widget = new Widget();

        $widgetRepository = $this->createRepository();
        $widgetRepository->expects($this->once())->method('create')->willReturn($widget);
        $widgetRepository->expects($this->never())->method('find');

        $data = ['title' => 'My Widget', 'type' => 'text', 'status' => 1, 'position' => 'sidebar'];
        $widgetRepository->expects($this->once())->method('save')->with($this->identicalTo($widget), $data);

        $result = $this->createController($widgetRepository)->saveAction(0, $data);

        $this->assertSame('success', $result['message']);
        $this->assertSame($widget, $result['widget']);
        $this->assertSame('My Widget', $widget->title, 'validated data is assigned onto the entity before persistence');
        $this->assertSame('sidebar', $widget->position, 'the non-column position is restored on the widget after save');
    }

    public function testSaveActionRejectsInvalidWidgetBeforePersisting(): void
    {
        $widgetRepository = $this->createRepository();
        $widgetRepository->method('create')->willReturn(new Widget());
        // A validation failure must abort before the entity reaches the repository.
        $widgetRepository->expects($this->never())->method('save');

        $this->expectException(BadRequestHttpException::class);

        $this->createController($widgetRepository)->saveAction(0, ['title' => '', 'type' => '']);
    }

    public function testSaveActionThrowsWhenUpdatingMissingWidget(): void
    {
        $widgetRepository = $this->createRepository();
        $widgetRepository->expects($this->once())->method('find')->with(7)->willReturn(null);
        $widgetRepository->expects($this->never())->method('create');
        $widgetRepository->expects($this->never())->method('save');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Widget not found.');

        $this->createController($widgetRepository)->saveAction(7, ['title' => 'X', 'type' => 'text']);
    }

    public function testDeleteActionRemovesWidgetThroughRepository(): void
    {
        $widget = $this->createWidget(5);

        $widgetRepository = $this->createRepository();
        $widgetRepository->expects($this->once())->method('find')->with(5)->willReturn($widget);
        $widgetRepository->expects($this->once())->method('delete')->with($widget);

        $result = $this->createController($widgetRepository)->deleteAction(5);

        $this->assertSame('success', $result['message']);
    }

    public function testDeleteActionThrowsWhenWidgetMissing(): void
    {
        $widgetRepository = $this->createRepository();
        $widgetRepository->expects($this->once())->method('find')->with(404)->willReturn(null);
        $widgetRepository->expects($this->never())->method('delete');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Widget not found.');

        $this->createController($widgetRepository)->deleteAction(404);
    }

    public function testCopyActionClonesFoundWidgetWithResetIdentityAndSuffix(): void
    {
        $widget = $this->createWidget(5);
        $widget->title = 'Original';
        $widget->status = 1;

        $request = new Request();
        $request->request->set('ids', [5]);

        $widgetRepository = $this->createRepository();
        $widgetRepository->expects($this->once())->method('find')->with(5)->willReturn($widget);

        // The persisted argument is a distinct clone with a reset identity, an
        // unpublished status and the localized " - Copy" title suffix.
        $widgetRepository->expects($this->once())->method('save')->with($this->callback(
            function (object $copy) use ($widget): bool {
                $this->assertInstanceOf(Widget::class, $copy);
                $this->assertNotSame($widget, $copy, 'the copy is a distinct clone, not the source widget');
                $this->assertNull($copy->id, 'the copy is reset to an unsaved identity');
                $this->assertSame(0, $copy->status, 'the copy starts unpublished');
                $this->assertSame('Original - Copy', $copy->title, 'the copy title carries the localized " - Copy" suffix');

                return true;
            }
        ));

        $result = $this->createController($widgetRepository, request: $request)->copyAction();

        $this->assertSame('success', $result['message']);
    }

    private function createWidget(int $id): Widget
    {
        $widget = new Widget();
        $widget->id = $id;
        $widget->title = 'Widget ' . $id;
        $widget->type = 'text';

        return $widget;
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
     * @param Repository<Widget> $widgetRepository
     */
    private function createController(
        Repository $widgetRepository,
        ?PositionManager $position = null,
        ?Request $request = null,
    ): WidgetApiController {
        return new WidgetApiController(
            $position ?? $this->createMock(PositionManager::class),
            $request ?? new Request(),
            $this->validator,
            $widgetRepository,
        );
    }
}
