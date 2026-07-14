<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Pagekit\Database\ORM\Repository;
use Pagekit\Site\Controller\PageApiController;
use Pagekit\Site\Model\Page;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Covers PageApiController after its Step 4 migration onto the injected
 * Repository<Page>: listing and single-page lookup now go through the
 * repository (findAll()/find()) instead of the entity's former static ORM API
 * (and the dead per-call instanceof guards are gone). The repository is mocked
 * directly, so no database is required.
 */
class PageApiControllerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testIndexActionReturnsAllPagesReindexed(): void
    {
        $one = new Page();
        $one->id = 1;
        $two = new Page();
        $two->id = 2;

        /** @var Repository<Page>&MockObject $pages */
        $pages = $this->createMock(Repository::class);
        $pages->expects($this->once())->method('findAll')->willReturn([1 => $one, 2 => $two]);

        $result = (new PageApiController($pages))->indexAction();

        $this->assertSame([$one, $two], $result, 'the id-keyed set must be re-indexed via array_values');
    }

    public function testGetActionReturnsPageById(): void
    {
        $page = new Page();
        $page->id = 5;

        /** @var Repository<Page>&MockObject $pages */
        $pages = $this->createMock(Repository::class);
        $pages->expects($this->once())->method('find')->with(5)->willReturn($page);

        $this->assertSame($page, (new PageApiController($pages))->getAction(5));
    }

    public function testGetActionThrowsWhenPageMissing(): void
    {
        /** @var Repository<Page>&MockObject $pages */
        $pages = $this->createMock(Repository::class);
        $pages->expects($this->once())->method('find')->with(404)->willReturn(null);

        $controller = new PageApiController($pages);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Page not found.');

        $controller->getAction(404);
    }
}
