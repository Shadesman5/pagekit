<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Doctrine\DBAL\Result;
use Pagekit\Application\UrlProvider;
use Pagekit\Config\ConfigManager;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Database\Query\QueryBuilder as DbalQueryBuilder;
use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Filter\FilterManager;
use Pagekit\Intl\Loader\PhpFileLoader;
use Pagekit\Module\ModuleManager;
use Pagekit\Site\Controller\NodeApiController;
use Pagekit\Site\Model\Node;
use Pagekit\Site\NodePresenter;
use Pagekit\User\Model\User;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Covers NodeApiController presenter wiring: index/get/save actions return
 * NodePresenter::toArray() output instead of raw entities. ORM access is
 * driven through a mock-backed EntityManager singleton (isolated process),
 * mirroring {@see UserProviderTest}. NodePresenter is final — tests inject a
 * real presenter backed by mocked UrlProvider and User.
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

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIndexActionMapsNodesThroughPresenter(): void
    {
        $nodeOne = $this->createPresenterNodeMock(1, 'Home', '@page/home');
        $nodeTwo = $this->createPresenterNodeMock(2, 'Blog', '@blog/index');

        $this->primeEntityManagerForQueryGet([$nodeOne, $nodeTwo]);

        $url = $this->createMock(UrlProvider::class);
        $url->method('get')->willReturnMap([
            ['@page/home', [], UrlProvider::BASE_PATH, '/home'],
            ['@blog/index', [], UrlProvider::BASE_PATH, '/blog'],
        ]);

        $result = $this->createController(new Request(), $this->createPresenter($url))->indexAction();

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame('Home', $result[0]['title']);
        $this->assertSame('/home', $result[0]['url']);
        $this->assertTrue($result[0]['accessible']);
        $this->assertSame(2, $result[1]['id']);
        $this->assertSame('/blog', $result[1]['url']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIndexActionFiltersByMenuQueryParameter(): void
    {
        $node = $this->createPresenterNodeMock(3, 'Footer link', '@page/footer');

        $query = $this->createMock(DbalQueryBuilder::class);
        $query->method('from')->willReturnSelf();
        $query->expects($this->once())
            ->method('where')
            ->with(['menu' => 'footer'])
            ->willReturnSelf();

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(['id' => $node->id], false);
        $query->method('executeQuery')->willReturn($result);

        $this->primeEntityManager($query, [$node]);

        $url = $this->createMock(UrlProvider::class);
        $url->expects($this->once())
            ->method('get')
            ->with('@page/footer', [], UrlProvider::BASE_PATH)
            ->willReturn('/footer');

        $request = new Request(['menu' => 'footer']);
        $presented = $this->createController($request, $this->createPresenter($url))->indexAction();

        $this->assertCount(1, $presented);
        $this->assertSame(3, $presented[0]['id']);
        $this->assertSame('/footer', $presented[0]['url']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetActionReturnsPresenterArray(): void
    {
        $node = $this->createPresenterNodeMock(5, 'About', '@page/about');

        $this->primeEntityManagerForFind($node, $node);

        $url = $this->createMock(UrlProvider::class);
        $url->expects($this->once())
            ->method('get')
            ->with('@page/about', [], UrlProvider::BASE_PATH)
            ->willReturn('/about');

        $result = $this->createController(new Request(), $this->createPresenter($url))->getAction(5);

        $this->assertSame(5, $result['id']);
        $this->assertSame('About', $result['title']);
        $this->assertSame('/about', $result['url']);
        $this->assertTrue($result['accessible']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSaveActionReturnsPresenterArrayInResponse(): void
    {
        $node = $this->getMockBuilder(Node::class)
            ->onlyMethods(['save', 'toArray', 'hasAccess'])
            ->getMock();
        $node->id = 7;
        $node->title = 'Home';
        $node->slug = 'home';
        $node->type = 'page';
        $node->link = '@page/home';
        $node->status = 1;

        $node->expects($this->once())->method('save');
        $node->method('hasAccess')->willReturn(true);
        $node->method('toArray')->willReturnCallback(
            static fn (array $data = []): array => $data + ['id' => 7, 'title' => 'Home updated']
        );

        $this->primeEntityManagerForFind($node, $node);

        $url = $this->createMock(UrlProvider::class);
        $url->expects($this->once())
            ->method('get')
            ->with('@page/home', [], UrlProvider::BASE_PATH)
            ->willReturn('/home-updated');

        $result = $this->createController(new Request(), $this->createPresenter($url))->saveAction(7, [
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

    private function createController(Request $request, NodePresenter $presenter): NodeApiController
    {
        return new NodeApiController(
            $request,
            new FilterManager(),
            $this->createMock(ModuleManager::class),
            $this->createMock(ConfigManager::class),
            $this->validator,
            $presenter,
        );
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

    /**
     * @param array<int, Node&MockObject> $nodes
     */
    private function primeEntityManagerForQueryGet(array $nodes): void
    {
        $rows = array_map(
            static fn (Node $node): array => [
                'id' => $node->id,
                'title' => $node->title,
                'slug' => $node->slug,
                'type' => $node->type,
                'link' => $node->link,
                'status' => $node->status,
            ],
            $nodes
        );

        $query = $this->createMock(DbalQueryBuilder::class);
        $query->method('from')->willReturnSelf();
        $query->method('where')->willReturnSelf();

        $fetchSequence = [...$rows, false];
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturnOnConsecutiveCalls(...$fetchSequence);
        $query->method('executeQuery')->willReturn($result);

        $this->primeEntityManager($query, $nodes);
    }

    private function primeEntityManagerForFind(Node $node, ?Node $hydratedInstance = null): void
    {
        $query = $this->createMock(DbalQueryBuilder::class);
        $query->method('from')->willReturnSelf();
        $query->method('where')->willReturnSelf();
        $query->method('limit')->willReturnSelf();

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(['id' => $node->id], false);
        $query->method('executeQuery')->willReturn($result);

        $this->primeEntityManager($query, $hydratedInstance !== null ? [$hydratedInstance] : []);
    }

    /**
     * @param array<int, Node> $hydratedInstances
     */
    private function primeEntityManager(DbalQueryBuilder $query, array $hydratedInstances = []): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($query);

        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getTable')->willReturn('@system_node');
        $metadata->method('getEventPrefix')->willReturn('node');
        $metadata->method('getIdentifier')->willReturn('id');
        $metadata->method('getClass')->willReturn(Node::class);

        $queue = $hydratedInstances;
        if ($queue !== []) {
            $metadata->method('newInstance')->willReturnCallback(
                static function () use (&$queue): Node {
                    if ($queue === []) {
                        return new Node();
                    }

                    return array_shift($queue);
                }
            );
        } else {
            $metadata->method('newInstance')->willReturnCallback(static fn (): Node => new Node());
        }

        $metadata->method('setValues')->willReturnCallback(
            static function (object $entity, array $data): void {
                if (!$entity instanceof Node) {
                    return;
                }

                foreach ($data as $key => $value) {
                    if (property_exists($entity, (string) $key)) {
                        $entity->{$key} = $value;
                    }
                }
            }
        );
        $metadata->method('getValue')->willReturnCallback(
            static fn (object $entity, string $identifier): mixed => $entity instanceof Node ? $entity->{$identifier} : null
        );

        $metadataManager = $this->createMock(MetadataManager::class);
        $metadataManager->method('get')->willReturn($metadata);

        new EntityManager($connection, $metadataManager, $this->createMock(EventDispatcherInterface::class));
    }
}
