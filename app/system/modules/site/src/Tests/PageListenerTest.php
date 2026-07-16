<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Pagekit\Database\ORM\Repository;
use Pagekit\Event\EventInterface;
use Pagekit\Site\Event\PageListener;
use Pagekit\Site\Model\Node;
use Pagekit\Site\Model\Page;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers PageListener against an injected Repository<Page>: page persistence and
 * deletion flow through the repository (save()/delete()/find()/create()). The
 * repository is mocked directly, so the find-or-create resolution and the
 * node-link rewrite are asserted with no database.
 */
class PageListenerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testOnNodeSavePersistsExistingPageAndRewritesNodeLink(): void
    {
        $request = new Request();
        $request->request->set('node', ['id' => 7, 'type' => 'page']);
        $request->request->set('page', ['title' => 'Hello', 'content' => 'World']);

        $page = new Page();
        $page->id = 7;

        /** @var Repository<Page>&MockObject $pages */
        $pages = $this->createMock(Repository::class);
        $pages->expects($this->once())->method('find')->with(7)->willReturn($page);
        $pages->expects($this->never())->method('create');
        $pages->expects($this->once())->method('save')->with($page, ['title' => 'Hello', 'content' => 'World']);

        (new PageListener($pages))->onNodeSave($this->createMock(EventInterface::class), $request);

        $node = $request->request->all()['node'];
        $this->assertIsArray($node);
        $this->assertSame('@page/7', $node['link'], 'the node link must point at the saved page id');
        $this->assertIsArray($node['data']);
        $this->assertSame(['id' => 7], $node['data']['defaults']);
    }

    public function testOnNodeSaveCreatesPageWhenNodeHasNoIdYet(): void
    {
        $request = new Request();
        $request->request->set('node', ['type' => 'page']);
        $request->request->set('page', ['title' => 'Fresh']);

        $created = new Page();
        $created->id = 99;

        /** @var Repository<Page>&MockObject $pages */
        $pages = $this->createMock(Repository::class);
        // No node id -> getPage() short-circuits find() and creates a page.
        $pages->expects($this->never())->method('find');
        $pages->expects($this->once())->method('create')->willReturn($created);
        $pages->expects($this->once())->method('save')->with($created, ['title' => 'Fresh']);

        (new PageListener($pages))->onNodeSave($this->createMock(EventInterface::class), $request);

        $node = $request->request->all()['node'];
        $this->assertIsArray($node);
        $this->assertSame('@page/99', $node['link']);
    }

    public function testOnNodeSaveIgnoresNonPageNodes(): void
    {
        $request = new Request();
        $request->request->set('node', ['id' => 1, 'type' => 'blog']);
        $request->request->set('page', ['title' => 'X']);

        /** @var Repository<Page>&MockObject $pages */
        $pages = $this->createMock(Repository::class);
        $pages->expects($this->never())->method('find');
        $pages->expects($this->never())->method('create');
        $pages->expects($this->never())->method('save');

        (new PageListener($pages))->onNodeSave($this->createMock(EventInterface::class), $request);

        $node = $request->request->all()['node'];
        $this->assertIsArray($node);
        $this->assertArrayNotHasKey('link', $node, 'a non-page node must be left untouched');
    }

    public function testOnNodeDeletedRemovesTheBackingPage(): void
    {
        $node = new Node();
        $node->type = 'page';
        $node->set('defaults.id', 42);

        $page = new Page();
        $page->id = 42;

        /** @var Repository<Page>&MockObject $pages */
        $pages = $this->createMock(Repository::class);
        $pages->expects($this->once())->method('find')->with(42)->willReturn($page);
        $pages->expects($this->once())->method('delete')->with($page);

        (new PageListener($pages))->onNodeDeleted($this->createMock(EventInterface::class), $node);
    }

    public function testOnNodeDeletedDoesNotDeleteWhenNoBackingPageExists(): void
    {
        $node = new Node();
        $node->type = 'page';
        // No defaults.id -> getPage(0) creates a fresh, unsaved page (id null).

        $fresh = new Page();

        /** @var Repository<Page>&MockObject $pages */
        $pages = $this->createMock(Repository::class);
        $pages->expects($this->never())->method('find');
        $pages->expects($this->once())->method('create')->willReturn($fresh);
        $pages->expects($this->never())->method('delete');

        (new PageListener($pages))->onNodeDeleted($this->createMock(EventInterface::class), $node);
    }

    public function testOnNodeDeletedIgnoresNonPageNodes(): void
    {
        $node = new Node();
        $node->type = 'blog';

        /** @var Repository<Page>&MockObject $pages */
        $pages = $this->createMock(Repository::class);
        $pages->expects($this->never())->method('find');
        $pages->expects($this->never())->method('create');
        $pages->expects($this->never())->method('delete');

        (new PageListener($pages))->onNodeDeleted($this->createMock(EventInterface::class), $node);
    }
}
