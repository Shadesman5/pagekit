<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Blog;

use Pagekit\Application\Response;
use Pagekit\Application\UrlProvider;
use Pagekit\Blog\Controller\SiteController;
use Pagekit\Blog\Model\Post;
use Pagekit\Blog\Model\PostRepository;
use Pagekit\Blog\PostPresenter;
use Pagekit\Content\ContentHelper;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Feed\FeedFactory;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Covers SiteController::postAction against the injected {@see PostRepository}: the
 * single published post is loaded via where(...)->related('user')->first(), then
 * gated on the post's access. The repository/query chain is mocked directly, so
 * the not-found and access-denied guards are asserted with no database.
 *
 * The blog/content classes are runtime-loaded (not in composer's autoload map);
 * bootstrap.php requires them in dependency order.
 */
class SiteControllerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testPostActionThrowsNotFoundWhenNoPublishedPostMatches(): void
    {
        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->once())
            ->method('where')
            ->willReturn($this->queryReturningFirst(null));

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Post not found!');

        $this->createController($postRepository)->postAction(404);
    }

    public function testPostActionDeniesAccessWhenUserCannotSeeThePost(): void
    {
        $post = $this->getMockBuilder(Post::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasAccess'])
            ->getMock();
        $post->method('hasAccess')->willReturn(false);

        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->once())
            ->method('where')
            ->willReturn($this->queryReturningFirst($post));

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Insufficient User Rights.');

        $this->createController($postRepository)->postAction(5);
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
            'config' => ['posts' => ['posts_per_page' => 10], 'comments' => ['require_email' => true, 'max_depth' => 5]],
        ]);
    }

    private function moduleManager(): ModuleManager
    {
        $module = $this->createMock(ModuleManager::class);
        $module->method('get')->with('blog')->willReturn($this->blogModule());

        return $module;
    }

    private function createController(PostRepository $postRepository): SiteController
    {
        return new SiteController(
            $this->moduleManager(),
            $this->createMock(User::class),
            $this->createMock(ContentHelper::class),
            $this->createMock(FeedFactory::class),
            $this->createMock(UrlProvider::class),
            $this->createMock(Response::class),
            new PostPresenter($this->createMock(UrlProvider::class), $this->createMock(User::class), $this->blogModule()),
            $postRepository,
        );
    }
}
