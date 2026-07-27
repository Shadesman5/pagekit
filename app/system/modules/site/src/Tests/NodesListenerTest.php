<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Pagekit\Event\EventInterface;
use Pagekit\Routing\Routes;
use Pagekit\Site\Event\NodesListener;
use Pagekit\Site\Model\Node;
use Pagekit\Site\Model\NodeRepository;
use Pagekit\Site\SiteModule;
use Pagekit\User\Model\Role;
use PHPUnit\Framework\TestCase;

/**
 * Covers NodesListener against an injected {@see NodeRepository}: route
 * registration reads the cached node set via findAll(true) and role cleanup
 * strips the role by its integer id through the
 * repository. The Module (SiteModule) and Routes collaborators are mocked, so
 * the routing outcomes (frontpage alias vs. the "no frontpage" fallback,
 * unpublished nodes skipped) are asserted without a kernel boot or a database.
 */
class NodesListenerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testOnRoleDeleteStripsRoleByIntegerIdThroughRepository(): void
    {
        $role = new Role();
        $role->id = 5;

        $nodes = $this->createMock(NodeRepository::class);
        // The role is narrowed to its integer id (Repository::removeRole is
        // int-only); the whole Role object is never handed to the repository.
        $nodes->expects($this->once())->method('removeRole')->with($this->identicalTo(5));

        $listener = new NodesListener($this->createMock(SiteModule::class), $this->createMock(Routes::class), $nodes);

        $listener->onRoleDelete($this->createMock(EventInterface::class), $role);
    }

    public function testOnNodeInitCopiesPathIntoLinkForRedirectingLinkNode(): void
    {
        $node = new Node();
        $node->type = 'link';
        $node->path = '/campaign';
        $node->set('redirect', 'https://example.com');

        $listener = new NodesListener($this->createMock(SiteModule::class), $this->createMock(Routes::class), $this->createMock(NodeRepository::class));

        $listener->onNodeInit($this->createMock(EventInterface::class), $node);

        $this->assertSame('/campaign', $node->link, 'a redirecting link node must resolve its link to its own path');
    }

    public function testOnNodeInitLeavesNonRedirectingNodeUntouched(): void
    {
        $node = new Node();
        $node->type = 'page';
        $node->path = '/about';
        $node->link = '@page/1';

        $listener = new NodesListener($this->createMock(SiteModule::class), $this->createMock(Routes::class), $this->createMock(NodeRepository::class));

        $listener->onNodeInit($this->createMock(EventInterface::class), $node);

        $this->assertSame('@page/1', $node->link, 'a non-link node keeps its configured link');
    }

    public function testOnRequestAliasesRootToTheConfiguredFrontpageNode(): void
    {
        $node = $this->createNode(5, 'page', '/home', '@page/home');

        $site = $this->createMock(SiteModule::class);
        $site->method('config')->with('frontpage')->willReturn(5);
        $site->method('getType')->with('page')->willReturn(['controller' => 'HomeController']);

        $nodes = $this->createMock(NodeRepository::class);
        // Route building reads the request-cached set, not a fresh query.
        $nodes->expects($this->once())->method('findAll')->with(true)->willReturn([5 => $node]);

        $routes = $this->createMock(Routes::class);
        $routes->expects($this->once())->method('add');
        $routes->expects($this->once())->method('alias')->with('/', '@page/home');
        $routes->expects($this->never())->method('get');
        $routes->expects($this->never())->method('redirect');

        (new NodesListener($site, $routes, $nodes))->onRequest();
    }

    public function testOnRequestFallsBackToPlaceholderRootAndSkipsUnpublishedNodes(): void
    {
        $published = $this->createNode(1, 'page', '/a', '@page/a');
        $unpublished = $this->createNode(2, 'page', '/b', '@page/b');
        $unpublished->status = 0;

        $site = $this->createMock(SiteModule::class);
        $site->method('config')->with('frontpage')->willReturn(0);
        $site->method('getType')->with('page')->willReturn(['controller' => 'PageController']);

        $nodes = $this->createMock(NodeRepository::class);
        $nodes->method('findAll')->with(true)->willReturn([1 => $published, 2 => $unpublished]);

        $routes = $this->createMock(Routes::class);
        // Only the single published node yields a route; the unpublished one is
        // skipped before its type is ever resolved.
        $routes->expects($this->once())->method('add');
        $routes->expects($this->never())->method('alias');
        $routes->expects($this->once())
            ->method('get')
            ->with('/', $this->callback(static fn ($callback): bool => is_callable($callback)));

        (new NodesListener($site, $routes, $nodes))->onRequest();
    }

    private function createNode(int $id, string $type, string $path, string $link): Node
    {
        $node = new Node();
        $node->id = $id;
        $node->type = $type;
        $node->path = $path;
        $node->link = $link;
        $node->status = 1;

        return $node;
    }
}
