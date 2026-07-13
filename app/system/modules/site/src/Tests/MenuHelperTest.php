<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Pagekit\Application\UrlProvider;
use Pagekit\Site\MenuHelper;
use Pagekit\Site\MenuManager;
use Pagekit\Site\Model\Node;
use Pagekit\Site\NodePresenter;
use Pagekit\User\Model\User;
use PHPUnit\Framework\TestCase;

/**
 * Covers MenuHelper presenter wiring: getRoot() resolves the active path via
 * NodePresenter::getUrl(BASE_PATH) and assigns per-node URLs through getUrl().
 * Node menu data is seeded through the request-scoped static cache so no
 * database or kernel boot is required. NodePresenter is final — tests inject a
 * real presenter backed by mocked UrlProvider and User.
 */
class MenuHelperTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        $this->resetNodeCache();
    }

    protected function tearDown(): void
    {
        $this->resetNodeCache();
    }

    public function testGetRootResolvesCurrentPathViaPresenterGetUrlWithBasePath(): void
    {
        $menuNode = $this->createMenuNode(2, '/blog', 'main', '@blog');
        $this->seedNodeCache([2 => $menuNode]);

        $currentNode = new Node();
        $currentNode->id = 99;
        $currentNode->path = '/incoming';

        $url = $this->createMock(UrlProvider::class);
        $basePathResolved = false;
        $url->method('get')->willReturnCallback(
            static function (?string $link, array $params, int|string $refType) use (&$basePathResolved): string {
                if ($link === null || $link === '') {
                    return '/';
                }

                if ($link === '@blog' && $refType === UrlProvider::BASE_PATH) {
                    $basePathResolved = true;

                    return '/incoming';
                }

                return '/blog-url';
            }
        );

        $helper = new MenuHelper(
            $this->createMock(MenuManager::class),
            $this->createAccessibleUser(),
            $currentNode,
            new NodePresenter($url, $this->createMock(User::class)),
        );

        $root = $helper->getRoot('main', ['start_level' => 2]);

        $this->assertTrue($basePathResolved, 'Presenter must resolve the incoming path via UrlProvider::BASE_PATH');
        $this->assertTrue($menuNode->get('active'), 'Path resolution via BASE_PATH must mark the matched node active');
        $this->assertNotNull($root);
        $this->assertSame($menuNode, $root);
    }

    public function testGetRootAssignsPresenterUrlsToMenuNodes(): void
    {
        $home = $this->createMenuNode(1, '/home', 'main', '/home');
        $about = $this->createMenuNode(2, '/about', 'main', '/about');
        $this->seedNodeCache([1 => $home, 2 => $about]);

        $currentNode = new Node();
        $currentNode->id = 1;
        $currentNode->path = '/home';

        $url = $this->createMock(UrlProvider::class);
        $url->method('get')->willReturnCallback(
            static function (?string $link, array $params, int|string $refType): string {
                if ($link === null || $link === '') {
                    return '/';
                }

                return match ($link) {
                    '/home' => '/home-url',
                    '/about' => '/about-url',
                    default => '/',
                };
            }
        );

        $helper = new MenuHelper(
            $this->createMock(MenuManager::class),
            $this->createAccessibleUser(),
            $currentNode,
            new NodePresenter($url, $this->createMock(User::class)),
        );

        $helper->getRoot('main');

        $this->assertSame('/home-url', $home->get('url'));
        $this->assertSame('/about-url', $about->get('url'));
    }

    private function createMenuNode(int $id, string $path, string $menu, string $link): Node
    {
        $node = new Node();
        $node->id = $id;
        $node->path = $path;
        $node->menu = $menu;
        $node->status = 1;
        $node->parent_id = 0;
        $node->link = $link;
        $node->slug = trim($path, '/');
        $node->title = ucfirst($node->slug);
        $node->type = 'page';

        return $node;
    }

    private function createAccessibleUser(): User
    {
        $user = new User();
        $user->roles = [];

        return $user;
    }

    /**
     * @param array<int, Node> $nodes
     */
    private function seedNodeCache(array $nodes): void
    {
        $reflection = new \ReflectionClass(Node::class);
        $property = $reflection->getProperty('nodes');
        $property->setAccessible(true);
        $property->setValue(null, $nodes);
    }

    private function resetNodeCache(): void
    {
        $reflection = new \ReflectionClass(Node::class);
        $property = $reflection->getProperty('nodes');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
