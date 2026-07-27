<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Pagekit\Content\ContentHelper;
use Pagekit\Database\ORM\Repository;
use Pagekit\Site\Controller\PageController;
use Pagekit\Site\Model\Node;
use Pagekit\Site\Model\Page;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Covers the front-end PageController against the injected Repository<Page>: the
 * page is resolved via the repository's find(). The repository and the
 * ContentHelper are mocked directly, so no database is required.
 */
class PageControllerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testIndexActionAppliesContentPluginsAndReturnsView(): void
    {
        $page = new Page();
        $page->id = 3;
        $page->title = 'About';
        $page->content = 'raw';

        /** @var Repository<Page>&MockObject $pages */
        $pages = $this->createMock(Repository::class);
        $pages->expects($this->once())->method('find')->with(3)->willReturn($page);

        $content = $this->createMock(ContentHelper::class);
        $content->expects($this->once())->method('applyPlugins')->with('raw', $this->anything())->willReturn('processed');

        $node = new Node();

        $result = (new PageController($content, $node, $pages))->indexAction(3);

        $this->assertSame('processed', $page->content, 'the page body must be run through the content plugins');
        $this->assertSame($page, $result['page']);
        $this->assertSame($node, $result['node'], 'the injected current node is passed through to the view');
    }

    public function testIndexActionThrowsWhenPageMissing(): void
    {
        /** @var Repository<Page>&MockObject $pages */
        $pages = $this->createMock(Repository::class);
        $pages->expects($this->once())->method('find')->with(0)->willReturn(null);

        $controller = new PageController($this->createMock(ContentHelper::class), new Node(), $pages);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Page not found.');

        $controller->indexAction();
    }
}
