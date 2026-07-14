<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Pagekit\Application;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\Repository;
use Pagekit\Filter\FilterManager;
use Pagekit\Site\Model\Node;
use Pagekit\Site\Model\NodeRepository;
use Pagekit\Site\Model\Page;
use Pagekit\Site\SiteModule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers SiteModule after its Step 4 wiring change: main() registers the
 * `nodeRepository` (custom, RC-2 request-cache home) and `pageRepository`
 * services, and registerType() auto-creates a protected type's node through the
 * injected `nodeRepository` (`$nodes->save($nodes->create([...]))`) instead of
 * the former static create()/save() model API, reading the existing set via
 * findAll(true).
 *
 * Mirrors tests/Unit/Container/DiWiringTest: a lightweight `new Application()`
 * is booted through main() (no kernel), then the db.em-backed `nodeRepository`
 * factory is overridden with a mock and the `filter` service supplied, so
 * registerType() runs against mocked collaborators with no database.
 */
class SiteModuleTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testMainRegistersRepositoryServicesInTheInjectedApplication(): void
    {
        [, $app] = $this->bootModule();

        // main() registers the repository services on the injected container
        // (and only there, never on an ambient global one).
        $this->assertTrue($app->has('nodeRepository'), 'main() must register the nodeRepository service');
        $this->assertTrue($app->has('pageRepository'), 'main() must register the pageRepository service');
        $this->assertFalse((new Application())->has('nodeRepository'), 'services must land in the injected container only');

        // Resolving nodeRepository builds the custom NodeRepository (the RC-2
        // request-cache home) from db.em plus its own ArrayAdapter; stub db.em so
        // the factory runs without a database.
        $em = $this->createMock(EntityManager::class);
        $em->method('getMetadata')->willReturn($this->createMock(Metadata::class));
        $app->set('db.em', $em);

        $this->assertInstanceOf(NodeRepository::class, $app->get('nodeRepository'));
    }

    public function testRegisterTypeCreatesProtectedNodeThroughRepositoryWhenAbsent(): void
    {
        [$module, $app] = $this->bootModule();

        $created = new Node();

        $nodes = $this->createMock(NodeRepository::class);
        $nodes->method('findAll')->with(true)->willReturn([]);
        $nodes->expects($this->once())
            ->method('create')
            ->with([
                'title' => 'Events',
                'slug' => 'events',
                'type' => 'events',
                'status' => 1,
                'link' => '@events',
            ])
            ->willReturn($created);
        $nodes->expects($this->once())->method('save')->with($created);

        $app->set('nodeRepository', $nodes);
        $app->set('filter', new FilterManager());

        $module->registerType('events', ['label' => 'Events', 'name' => '@events', 'protected' => true]);

        $type = $module->getType('events');
        $this->assertNotNull($type);
        $this->assertSame('events', $type['id'], 'the type must be registered under its id');
    }

    public function testRegisterTypeSkipsCreationWhenProtectedNodeAlreadyExists(): void
    {
        [$module, $app] = $this->bootModule();

        $existing = new Node();
        $existing->type = 'events';

        $nodes = $this->createMock(NodeRepository::class);
        $nodes->method('findAll')->with(true)->willReturn([$existing]);
        $nodes->expects($this->never())->method('create');
        $nodes->expects($this->never())->method('save');

        $app->set('nodeRepository', $nodes);

        $module->registerType('events', ['label' => 'Events', 'name' => '@events', 'protected' => true]);

        $this->assertNotNull($module->getType('events'));
    }

    public function testRegisterTypeDoesNotCreateNodesForUnprotectedTypes(): void
    {
        [$module, $app] = $this->bootModule();

        $nodes = $this->createMock(NodeRepository::class);
        $nodes->expects($this->never())->method('create');
        $nodes->expects($this->never())->method('save');

        $app->set('nodeRepository', $nodes);

        $module->registerType('link', ['label' => 'Link', 'frontpage' => false]);

        $this->assertNotNull($module->getType('link'));
    }

    public function testPageRepositoryFactoryDelegatesToEntityManager(): void
    {
        [, $app] = $this->bootModule();

        $pages = $this->createMock(Repository::class);

        $em = $this->createMock(EntityManager::class);
        $em->expects($this->once())->method('getRepository')->with(Page::class)->willReturn($pages);
        $app->set('db.em', $em);

        $this->assertSame($pages, $app->get('pageRepository'));
    }

    public function testNodeServiceReturnsCachedNodeFromRepositoryWhenRouteNodeExists(): void
    {
        [, $app] = $this->bootModule();

        $node = new Node();
        $node->id = 9;

        $nodes = $this->createMock(NodeRepository::class);
        $nodes->expects($this->once())->method('find')->with(9, true)->willReturn($node);
        $nodes->expects($this->never())->method('create');
        $app->set('nodeRepository', $nodes);

        $request = new Request();
        $request->attributes->set('_node', 9);
        $app->set('request', $request);

        $this->assertSame($node, $app->get('node'));
    }

    public function testNodeServiceCreatesPlaceholderWhenRouteNodeIsMissing(): void
    {
        [, $app] = $this->bootModule();

        $created = new Node();

        $nodes = $this->createMock(NodeRepository::class);
        $nodes->expects($this->never())->method('find');
        $nodes->expects($this->once())->method('create')->willReturn($created);
        $app->set('nodeRepository', $nodes);
        $app->set('request', new Request());

        $this->assertSame($created, $app->get('node'));
    }

    /**
     * Boots a lightweight Application through SiteModule::main() (no kernel).
     *
     * @return array{SiteModule, Application}
     */
    private function bootModule(): array
    {
        $app = new Application();

        $module = new SiteModule(['name' => 'system/site', 'path' => '', 'config' => ['menus' => [], 'frontpage' => 0]]);
        $module->main($app);

        return [$module, $app];
    }
}
