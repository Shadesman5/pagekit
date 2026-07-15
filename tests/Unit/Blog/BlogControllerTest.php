<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Blog;

use Pagekit\Blog\Controller\BlogController;
use Pagekit\Blog\Model\Comment;
use Pagekit\Blog\Model\Post;
use Pagekit\Blog\Model\PostRepository;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Database\ORM\Repository;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Router;
use Pagekit\Session\MessageBag;
use Pagekit\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Covers BlogController (admin) against the injected {@see PostRepository} and
 * Repository<Role>: the listing/comment/edit actions resolve authors and posts
 * through the repository, and editAction keeps redirecting to the index when the
 * repository lookup fails or the ownership gate rejects the user. The
 * repositories, router and message bag are mocked directly, so no database is
 * required.
 *
 * The blog package is not on composer's autoload map, so bootstrap.php requires
 * the controller and its entities in dependency order.
 */
class BlogControllerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testSettingsActionReturnsTheBlogConfig(): void
    {
        $result = $this->createController($this->createMock(PostRepository::class))->settingsAction();

        $this->assertSame('blog/admin/settings.php', $result['$view']['name']);
        $this->assertEquals($this->blogModule()->config(), $result['$data']['config']);
    }

    public function testPostActionReturnsStatusesAuthorsAndTheEditAllFlag(): void
    {
        $authors = [['user_id' => 1, 'name' => 'Alice', 'username' => 'alice']];

        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->once())->method('getAuthors')->willReturn($authors);

        $user = $this->createMock(User::class);
        $user->method('hasAccess')->with('blog: manage all posts')->willReturn(true);

        $result = $this->createController($postRepository, user: $user)->postAction();

        $this->assertSame('blog/admin/post-index.php', $result['$view']['name']);
        $this->assertSame($authors, $result['$data']['authors']);
        $this->assertTrue($result['$data']['canEditAll']);
        $this->assertArrayHasKey(Post::STATUS_PUBLISHED, $result['$data']['statuses']);
    }

    public function testCommentActionResolvesThePostAndReturnsCommentStatuses(): void
    {
        $postRepository = $this->createMock(PostRepository::class);
        // A zero post id resolves to no post (the unfiltered comment index).
        $postRepository->expects($this->once())->method('find')->with(0)->willReturn(null);

        $result = $this->createController($postRepository)->commentAction([], 0);

        $this->assertSame('blog/admin/comment-index.php', $result['$view']['name']);
        $this->assertNull($result['$data']['config']['post']);
        $this->assertSame(20, $result['$data']['config']['limit']);
        $this->assertArrayHasKey(Comment::STATUS_APPROVED, $result['$data']['statuses']);
    }

    public function testEditActionRedirectsToIndexWhenPostIdIsInvalid(): void
    {
        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->once())
            ->method('where')
            ->with(['id' => 5])
            ->willReturn($this->queryReturningFirst(null));

        $redirect = new RedirectResponse('/admin/blog/post');
        $router = $this->createMock(Router::class);
        $router->expects($this->once())->method('redirect')->with('@blog/post')->willReturn($redirect);

        $message = $this->createMock(MessageBag::class);
        $message->expects($this->once())->method('error');

        $result = $this->createController($postRepository, router: $router, message: $message)->editAction(5);

        $this->assertSame($redirect, $result, 'an invalid post id is swallowed into a redirect to the index');
    }

    public function testEditActionRedirectsWhenUserCannotManageAnotherUsersPost(): void
    {
        $post = new Post();
        $post->id = 5;
        $post->user_id = 99;

        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->once())
            ->method('where')
            ->with(['id' => 5])
            ->willReturn($this->queryReturningFirst($post));

        $redirect = new RedirectResponse('/admin/blog/post');
        $router = $this->createMock(Router::class);
        $router->expects($this->once())->method('redirect')->with('@blog/post')->willReturn($redirect);

        $message = $this->createMock(MessageBag::class);
        $message->expects($this->once())->method('error');

        // Lacks "manage all posts" and does not own the post -> access denied -> redirect.
        $user = $this->createMock(User::class);
        $user->id = 7;
        $user->method('hasAccess')->willReturn(false);

        $result = $this->createController($postRepository, user: $user, router: $router, message: $message)->editAction(5);

        $this->assertSame($redirect, $result);
    }

    private function queryReturningFirst(?object $first): QueryBuilder&MockObject
    {
        $query = $this->createMock(QueryBuilder::class);
        $query->method('__call')->willReturnSelf();
        $query->method('related')->willReturnSelf();
        $query->method('first')->willReturn($first);

        return $query;
    }

    private function blogModule(): Module
    {
        return new Module([
            'name' => 'blog',
            'path' => '',
            'config' => [
                'posts' => ['posts_per_page' => 20, 'comments_enabled' => true],
                'comments' => ['comments_per_page' => 20],
                'permalink' => ['type' => '', 'custom' => '{slug}'],
            ],
        ]);
    }

    private function moduleManager(): ModuleManager
    {
        $module = $this->createMock(ModuleManager::class);
        $module->method('get')->with('blog')->willReturn($this->blogModule());

        return $module;
    }

    private function createController(
        PostRepository $postRepository,
        ?Repository $roleRepository = null,
        ?User $user = null,
        ?Router $router = null,
        ?MessageBag $message = null,
    ): BlogController {
        return new BlogController(
            $this->moduleManager(),
            $router ?? $this->createMock(Router::class),
            $message ?? $this->createMock(MessageBag::class),
            $user ?? $this->createMock(User::class),
            $this->createMock(Connection::class),
            $postRepository,
            $roleRepository ?? $this->createMock(Repository::class),
        );
    }
}
