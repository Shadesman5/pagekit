<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Blog;

use Pagekit\Application\UrlProvider;
use Pagekit\Blog\Model\Comment;
use Pagekit\Blog\Model\Post;
use Pagekit\Blog\PostPresenter;
use Pagekit\Module\Module;
use Pagekit\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers PostPresenter — the constructor-DI replacement for the Post entity's
 * former ModelServiceLocator reach-through. Exercises the autoclose comment
 * gate, the published/access gate and the enriched toArray() shape while staying
 * clear of the ORM: the Post's kernel-bound methods (toArray/isPublished/
 * hasAccess) are stubbed and the plain public columns are set directly, so no
 * database is required. The blog Module is a real value object built from a
 * config array (its config() accessor is pure).
 *
 * The blog and base-comment classes are runtime-loaded (not in composer's
 * autoload map); bootstrap.php requires them in dependency order.
 */
class PostPresenterTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testIsCommentableReturnsFalseWhenCommentsClosed(): void
    {
        $post = $this->createPostMock();
        $post->comment_status = false;

        $presenter = new PostPresenter(
            $this->createMock(UrlProvider::class),
            $this->createMock(User::class),
            $this->createBlogModule(),
        );

        $this->assertFalse($presenter->isCommentable($post));
    }

    public function testIsCommentableWithAutocloseDisabledIgnoresPostAge(): void
    {
        $post = $this->createPostMock();
        $post->comment_status = true;
        $post->date = new \DateTime('-100 day');

        $presenter = new PostPresenter(
            $this->createMock(UrlProvider::class),
            $this->createMock(User::class),
            $this->createBlogModule(autoclose: false),
        );

        $this->assertTrue($presenter->isCommentable($post));
    }

    public function testIsCommentableWithAutocloseEnabledAllowsRecentPost(): void
    {
        $post = $this->createPostMock();
        $post->comment_status = true;
        $post->date = new \DateTime('-1 day');

        $presenter = new PostPresenter(
            $this->createMock(UrlProvider::class),
            $this->createMock(User::class),
            $this->createBlogModule(autoclose: true, autocloseDays: 14),
        );

        $this->assertTrue($presenter->isCommentable($post));
    }

    public function testIsCommentableWithAutocloseEnabledBlocksExpiredPost(): void
    {
        $post = $this->createPostMock();
        $post->comment_status = true;
        $post->date = new \DateTime('-30 day');

        $presenter = new PostPresenter(
            $this->createMock(UrlProvider::class),
            $this->createMock(User::class),
            $this->createBlogModule(autoclose: true, autocloseDays: 14),
        );

        $this->assertFalse($presenter->isCommentable($post));
    }

    public function testIsAccessibleReturnsTrueForPublishedPostWhenUserHasAccess(): void
    {
        $post = $this->createPostMock();
        $post->method('isPublished')->willReturn(true);
        $post->method('hasAccess')->willReturn(true);

        $presenter = new PostPresenter(
            $this->createMock(UrlProvider::class),
            $this->createMock(User::class),
            $this->createBlogModule(),
        );

        $this->assertTrue($presenter->isAccessible($post));
    }

    public function testIsAccessibleReturnsFalseForUnpublishedPostWithoutCheckingAccess(): void
    {
        $post = $this->createPostMock();
        $post->method('isPublished')->willReturn(false);
        $post->expects($this->never())->method('hasAccess');

        $presenter = new PostPresenter(
            $this->createMock(UrlProvider::class),
            $this->createMock(User::class),
            $this->createBlogModule(),
        );

        $this->assertFalse($presenter->isAccessible($post));
    }

    public function testIsAccessibleReturnsFalseForPublishedPostWhenUserLacksAccess(): void
    {
        $post = $this->createPostMock();
        $post->method('isPublished')->willReturn(true);
        $post->method('hasAccess')->willReturn(false);

        $presenter = new PostPresenter(
            $this->createMock(UrlProvider::class),
            $this->createMock(User::class),
            $this->createBlogModule(),
        );

        $this->assertFalse($presenter->isAccessible($post));
    }

    public function testIsAccessibleFallsBackToInjectedCurrentUser(): void
    {
        $currentUser = $this->createMock(User::class);

        $post = $this->createPostMock();
        $post->method('isPublished')->willReturn(true);
        $post->expects($this->once())
            ->method('hasAccess')
            ->with($currentUser)
            ->willReturn(true);

        $presenter = new PostPresenter(
            $this->createMock(UrlProvider::class),
            $currentUser,
            $this->createBlogModule(),
        );

        $this->assertTrue($presenter->isAccessible($post));
    }

    public function testToArrayBuildsUrlFromBlogIdRouteAndAddsAccessibleKey(): void
    {
        $post = $this->createPostMock();
        $post->id = 5;
        $post->comments = null;
        $post->method('isPublished')->willReturn(true);
        $post->method('hasAccess')->willReturn(true);
        $post->method('toArray')->willReturnCallback(
            static fn (array $data = []): array => $data + ['id' => 5, 'title' => 'Hello World']
        );

        $url = $this->createMock(UrlProvider::class);
        $url->expects($this->once())
            ->method('get')
            ->with('@blog/id', ['id' => 5], UrlProvider::BASE_PATH)
            ->willReturn('/blog/5');

        $presenter = new PostPresenter($url, $this->createMock(User::class), $this->createBlogModule());

        $result = $presenter->toArray($post);

        $this->assertSame('/blog/5', $result['url']);
        $this->assertArrayHasKey('accessible', $result);
        $this->assertTrue($result['accessible']);
        $this->assertArrayNotHasKey('comments_pending', $result);
        $this->assertSame(5, $result['id']);
    }

    public function testToArrayFallsBackToZeroIdForUnsavedPost(): void
    {
        $post = $this->createPostMock();
        $post->id = null;
        $post->comments = null;
        $post->method('isPublished')->willReturn(false);
        $post->method('toArray')->willReturnCallback(static fn (array $data = []): array => $data);

        $url = $this->createMock(UrlProvider::class);
        $url->expects($this->once())
            ->method('get')
            ->with('@blog/id', ['id' => 0], UrlProvider::BASE_PATH)
            ->willReturn('/blog/0');

        $presenter = new PostPresenter($url, $this->createMock(User::class), $this->createBlogModule());

        $result = $presenter->toArray($post);

        $this->assertSame('/blog/0', $result['url']);
        $this->assertFalse($result['accessible']);
    }

    public function testToArrayCountsPendingCommentsOnlyWhenCommentsLoaded(): void
    {
        $post = $this->createPostMock();
        $post->id = 9;
        $post->comments = [
            $this->makeComment(Comment::STATUS_PENDING),
            $this->makeComment(Comment::STATUS_APPROVED),
            $this->makeComment(Comment::STATUS_PENDING),
        ];
        $post->method('isPublished')->willReturn(true);
        $post->method('hasAccess')->willReturn(true);
        $post->method('toArray')->willReturnCallback(static fn (array $data = []): array => $data);

        $url = $this->createMock(UrlProvider::class);
        $url->method('get')->willReturn('/blog/9');

        $presenter = new PostPresenter($url, $this->createMock(User::class), $this->createBlogModule());

        $result = $presenter->toArray($post);

        $this->assertArrayHasKey('comments_pending', $result);
        $this->assertSame(2, $result['comments_pending']);
    }

    /**
     * Builds a kernel-free Post double with only its ORM/access methods stubbed;
     * plain columns (id/comment_status/date/comments) are assigned directly on
     * the returned instance.
     *
     * @return Post&MockObject
     */
    private function createPostMock(): Post&MockObject
    {
        return $this->getMockBuilder(Post::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toArray', 'isPublished', 'hasAccess'])
            ->getMock();
    }

    /**
     * Builds a real blog Module value object; config() reads the supplied
     * comments settings through the pure Arr accessor (no kernel needed).
     */
    private function createBlogModule(bool $autoclose = false, int $autocloseDays = 14): Module
    {
        return new Module([
            'name' => 'blog',
            'path' => '',
            'config' => [
                'comments' => [
                    'autoclose' => $autoclose,
                    'autoclose_days' => $autocloseDays,
                ],
            ],
        ]);
    }

    private function makeComment(int $status): Comment
    {
        $comment = new Comment();
        $comment->status = $status;

        return $comment;
    }
}
