<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Pagekit\Application\UrlProvider;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Filter\FilterManager;
use Pagekit\Intl\Loader\PhpFileLoader;
use Pagekit\Module\ModuleManager;
use Pagekit\Site\Controller\NodeApiController;
use Pagekit\Site\Model\Node;
use Pagekit\Site\Model\NodeRepository;
use Pagekit\Site\NodePresenter;
use Pagekit\Site\SiteModule;
use Pagekit\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Covers NodeApiController after its Step 4 migration onto the injected
 * NodeRepository. The read/presenter actions (index/get/save) return
 * NodePresenter::toArray() output instead of raw entities, and the mutating
 * actions (delete/updateOrder/frontpage) drive their find/create/save/delete and
 * the protected/frontpage type gate through the repository. ORM access is driven
 * through a mocked NodeRepository injected straight into the controller — the
 * listing exercises the injected QueryBuilder<Node> returned by the repository's
 * query()/where() finders, so there is no EntityManager, no static model API and
 * no process isolation. NodePresenter is final — tests inject a real presenter
 * backed by mocked UrlProvider and User.
 */
class NodeApiControllerTest extends TestCase
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

    public function testIndexActionMapsNodesThroughPresenter(): void
    {
        $nodeOne = $this->createPresenterNodeMock(1, 'Home', '@page/home');
        $nodeTwo = $this->createPresenterNodeMock(2, 'Blog', '@blog/index');

        /** @var QueryBuilder<Node>&MockObject $query */
        $query = $this->createMock(QueryBuilder::class);
        $query->method('get')->willReturn([$nodeOne, $nodeTwo]);

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->method('query')->willReturn($query);
        // No menu filter: the plain query() builder is used, never where().
        $nodeRepository->expects($this->never())->method('where');

        $url = $this->createMock(UrlProvider::class);
        $url->method('get')->willReturnMap([
            ['@page/home', [], UrlProvider::BASE_PATH, '/home'],
            ['@blog/index', [], UrlProvider::BASE_PATH, '/blog'],
        ]);

        $result = $this->createController(new Request(), $this->createPresenter($url), $nodeRepository)->indexAction();

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame('Home', $result[0]['title']);
        $this->assertSame('/home', $result[0]['url']);
        $this->assertTrue($result[0]['accessible']);
        $this->assertSame(2, $result[1]['id']);
        $this->assertSame('/blog', $result[1]['url']);
    }

    public function testIndexActionFiltersByMenuQueryParameter(): void
    {
        $node = $this->createPresenterNodeMock(3, 'Footer link', '@page/footer');

        /** @var QueryBuilder<Node>&MockObject $query */
        $query = $this->createMock(QueryBuilder::class);
        $query->method('get')->willReturn([$node]);

        $nodeRepository = $this->createMock(NodeRepository::class);
        // A menu query parameter must scope the listing through where().
        $nodeRepository->expects($this->once())
            ->method('where')
            ->with(['menu' => 'footer'])
            ->willReturn($query);

        $url = $this->createMock(UrlProvider::class);
        $url->expects($this->once())
            ->method('get')
            ->with('@page/footer', [], UrlProvider::BASE_PATH)
            ->willReturn('/footer');

        $request = new Request(['menu' => 'footer']);
        $presented = $this->createController($request, $this->createPresenter($url), $nodeRepository)->indexAction();

        $this->assertCount(1, $presented);
        $this->assertSame(3, $presented[0]['id']);
        $this->assertSame('/footer', $presented[0]['url']);
    }

    public function testGetActionReturnsPresenterArray(): void
    {
        $node = $this->createPresenterNodeMock(5, 'About', '@page/about');

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->expects($this->once())->method('find')->with(5)->willReturn($node);

        $url = $this->createMock(UrlProvider::class);
        $url->expects($this->once())
            ->method('get')
            ->with('@page/about', [], UrlProvider::BASE_PATH)
            ->willReturn('/about');

        $result = $this->createController(new Request(), $this->createPresenter($url), $nodeRepository)->getAction(5);

        $this->assertSame(5, $result['id']);
        $this->assertSame('About', $result['title']);
        $this->assertSame('/about', $result['url']);
        $this->assertTrue($result['accessible']);
    }

    public function testSaveActionReturnsPresenterArrayInResponse(): void
    {
        $node = $this->getMockBuilder(Node::class)
            ->onlyMethods(['toArray', 'hasAccess'])
            ->getMock();
        $node->id = 7;
        $node->title = 'Home';
        $node->slug = 'home';
        $node->type = 'page';
        $node->link = '@page/home';
        $node->status = 1;

        $node->method('hasAccess')->willReturn(true);
        $node->method('toArray')->willReturnCallback(
            static fn (array $data = []): array => $data + ['id' => 7, 'title' => 'Home updated']
        );

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->expects($this->once())->method('find')->with(7)->willReturn($node);
        // Persistence now flows through the repository, not the entity.
        $nodeRepository->expects($this->once())->method('save')->with($node);

        $url = $this->createMock(UrlProvider::class);
        $url->expects($this->once())
            ->method('get')
            ->with('@page/home', [], UrlProvider::BASE_PATH)
            ->willReturn('/home-updated');

        $result = $this->createController(new Request(), $this->createPresenter($url), $nodeRepository)->saveAction(7, [
            'title' => 'Home updated',
            'slug' => 'home-updated',
            'type' => 'page',
            'link' => '@page/home',
        ]);

        $this->assertSame('success', $result['message']);
        $this->assertSame(7, $result['node']['id']);
        $this->assertSame('/home-updated', $result['node']['url']);
        $this->assertTrue($result['node']['accessible']);
    }

    public function testDeleteActionRejectsProtectedNodeType(): void
    {
        $node = new Node();
        $node->id = 3;
        $node->type = 'link';

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->expects($this->once())->method('find')->with(3)->willReturn($node);
        // A protected type must block the delete before it reaches the repository.
        $nodeRepository->expects($this->never())->method('delete');

        $controller = $this->createController(
            new Request(),
            $this->createBarePresenter(),
            $nodeRepository,
            $this->createModuleManager(['protected' => true]),
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid type.');

        $controller->deleteAction(3);
    }

    public function testDeleteActionDeletesUnprotectedNodeThroughRepository(): void
    {
        $node = new Node();
        $node->id = 4;
        $node->type = 'page';

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->expects($this->once())->method('find')->with(4)->willReturn($node);
        $nodeRepository->expects($this->once())->method('delete')->with($node);

        $result = $this->createController(
            new Request(),
            $this->createBarePresenter(),
            $nodeRepository,
            $this->createModuleManager(['protected' => false]),
        )->deleteAction(4);

        $this->assertSame('success', $result['message']);
    }

    public function testDeleteActionIsANoOpWhenNodeIsMissing(): void
    {
        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->expects($this->once())->method('find')->with(999)->willReturn(null);
        $nodeRepository->expects($this->never())->method('delete');

        $result = $this->createController(new Request(), $this->createBarePresenter(), $nodeRepository)->deleteAction(999);

        $this->assertSame('success', $result['message']);
    }

    public function testUpdateOrderActionAppliesOrderMenuAndParentThenSaves(): void
    {
        $node = new Node();
        $node->id = 5;

        $request = new Request();
        $request->request->set('menu', 'main');
        $request->request->set('nodes', [['id' => 5, 'order' => 7, 'parent_id' => 2]]);

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->expects($this->once())->method('find')->with(5)->willReturn($node);
        $nodeRepository->expects($this->once())->method('save')->with($node);

        $result = $this->createController($request, $this->createBarePresenter(), $nodeRepository)->updateOrderAction();

        $this->assertSame('success', $result['message']);
        $this->assertSame(7, $node->priority);
        $this->assertSame('main', $node->menu);
        $this->assertSame(2, $node->parent_id);
    }

    public function testFrontpageActionThrowsWhenNodeMissing(): void
    {
        $request = new Request();
        $request->request->set('id', 0);

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->expects($this->once())->method('find')->with(0)->willReturn(null);

        $controller = $this->createController($request, $this->createBarePresenter(), $nodeRepository);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Node not found.');

        $controller->frontpageAction();
    }

    public function testFrontpageActionRejectsNodeTypeThatCannotBeFrontpage(): void
    {
        $node = new Node();
        $node->id = 6;
        $node->type = 'link';

        $request = new Request();
        $request->request->set('id', 6);

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->method('find')->with(6)->willReturn($node);

        $controller = $this->createController(
            $request,
            $this->createBarePresenter(),
            $nodeRepository,
            $this->createModuleManager(['frontpage' => false]),
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid node type.');

        $controller->frontpageAction();
    }

    public function testFrontpageActionStoresFrontpageIdInConfig(): void
    {
        $node = new Node();
        $node->id = 6;
        $node->type = 'page';

        $request = new Request();
        $request->request->set('id', 6);

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->expects($this->once())->method('find')->with(6)->willReturn($node);

        $siteConfig = new Config();
        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->with('system/site')->willReturn($siteConfig);

        $result = $this->createController(
            $request,
            $this->createBarePresenter(),
            $nodeRepository,
            $this->createModuleManager(['frontpage' => true]),
            $config,
        )->frontpageAction();

        $this->assertSame('success', $result['message']);
        $this->assertSame(6, $siteConfig->get('frontpage'));
    }

    private function createController(
        Request $request,
        NodePresenter $presenter,
        NodeRepository $nodeRepository,
        ?ModuleManager $module = null,
        ?ConfigManager $config = null,
    ): NodeApiController {
        return new NodeApiController(
            $request,
            new FilterManager(),
            $module ?? $this->createMock(ModuleManager::class),
            $config ?? $this->createMock(ConfigManager::class),
            $this->validator,
            $presenter,
            $nodeRepository,
        );
    }

    /**
     * Builds a ModuleManager whose `system/site` module reports the given node
     * type descriptor (or null) — the protected/frontpage gate the mutating
     * actions consult.
     *
     * @param array<string, mixed>|null $type
     */
    private function createModuleManager(?array $type): ModuleManager
    {
        $site = $this->createMock(SiteModule::class);
        $site->method('getType')->willReturn($type);

        $module = $this->createMock(ModuleManager::class);
        $module->method('get')->with('system/site')->willReturn($site);

        return $module;
    }

    /**
     * A presenter is required by the constructor but unused by the mutating
     * actions (they return plain success payloads), so a bare URL stub suffices.
     */
    private function createBarePresenter(): NodePresenter
    {
        return $this->createPresenter($this->createMock(UrlProvider::class));
    }

    private function createPresenter(UrlProvider $url): NodePresenter
    {
        $user = $this->createMock(User::class);

        return new NodePresenter($url, $user);
    }

    /**
     * @return Node&MockObject
     */
    private function createPresenterNodeMock(int $id, string $title, string $link): Node&MockObject
    {
        $node = $this->getMockBuilder(Node::class)
            ->onlyMethods(['toArray', 'hasAccess'])
            ->getMock();
        $node->id = $id;
        $node->title = $title;
        $node->slug = strtolower($title);
        $node->type = 'page';
        $node->link = $link;
        $node->status = 1;
        $node->method('hasAccess')->willReturn(true);
        $node->method('toArray')->willReturnCallback(
            static fn (array $data = []): array => $data + ['id' => $id, 'title' => $title]
        );

        return $node;
    }
}
