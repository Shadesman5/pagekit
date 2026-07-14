<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Blog;

use Pagekit\Application\UrlProvider;
use Pagekit\Blog\Controller\PostApiController;
use Pagekit\Blog\Model\Post;
use Pagekit\Blog\Model\PostRepository;
use Pagekit\Blog\PostPresenter;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Filter\FilterManager;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Covers PostApiController after its Step 7 migration off the static Post model
 * API onto the injected {@see PostRepository}: read (get), save (create/update),
 * delete and copy now drive find/create/save/delete and the query chain through
 * the repository, and the presenter (final) is fed a repository-loaded post. The
 * repository and query builder are mocked; a real Symfony validator exercises the
 * save path's validateOrFail() gate, so no database is required.
 *
 * The blog package is not on composer's autoload map, so bootstrap.php requires
 * the controller (and the entities/presenter it depends on) in dependency order.
 */
class PostApiControllerTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testGetActionReturnsPresenterArrayForFoundPost(): void
    {
        $post = $this->postMock();
        $post->id = 5;
        $post->comments = null;
        $post->method('isPublished')->willReturn(true);
        $post->method('hasAccess')->willReturn(true);
        $post->method('toArray')->willReturnCallback(static fn (array $data = []): array => $data + ['id' => 5]);

        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->once())
            ->method('where')
            ->with(['id' => 5])
            ->willReturn($this->queryReturningFirst($post));

        $url = $this->createMock(UrlProvider::class);
        $url->method('get')->with('@blog/id', ['id' => 5], UrlProvider::BASE_PATH)->willReturn('/blog/5');

        $result = $this->createController($postRepository, presenter: $this->presenter($url))->getAction(5);

        $this->assertSame('/blog/5', $result['url']);
        $this->assertTrue($result['accessible']);
        $this->assertSame(5, $result['id']);
    }

    public function testGetActionReturnsNullWhenPostMissing(): void
    {
        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->once())
            ->method('where')
            ->with(['id' => 404])
            ->willReturn($this->queryReturningFirst(null));

        $this->assertNull($this->createController($postRepository)->getAction(404));
    }

    public function testSaveActionCreatesValidatesAndPersistsNewPost(): void
    {
        $post = $this->postMock();
        $post->method('isPublished')->willReturn(false);
        $post->method('hasAccess')->willReturn(false);
        $post->method('toArray')->willReturnCallback(static fn (array $data = []): array => $data + ['id' => 0]);

        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->once())->method('create')->willReturn($post);
        $postRepository->expects($this->never())->method('find');
        $postRepository->expects($this->once())
            ->method('save')
            ->with($this->identicalTo($post), $this->callback(static fn (array $data): bool => $data['slug'] === 'hello-world'));

        $url = $this->createMock(UrlProvider::class);
        $url->method('get')->willReturn('/blog/0');

        $user = $this->userWithAccess(true);

        $data = ['title' => 'Hello World', 'slug' => 'hello-world', 'user_id' => 1, 'status' => Post::STATUS_DRAFT];
        $result = $this->createController($postRepository, $user, presenter: $this->presenter($url))->saveAction(0, $data);

        $this->assertSame('success', $result['message']);
        $this->assertSame('/blog/0', $result['post']['url']);
        $this->assertSame('Hello World', $post->title, 'validated data is assigned onto the entity before persistence');
    }

    public function testSaveActionThrowsNotFoundWhenUpdatingMissingPost(): void
    {
        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->once())->method('find')->with(7)->willReturn(null);
        $postRepository->expects($this->never())->method('create');
        $postRepository->expects($this->never())->method('save');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Post not found.');

        $this->createController($postRepository)->saveAction(7, ['title' => 'X', 'slug' => 'x']);
    }

    public function testSaveActionPinsPostToCurrentUserWhenLackingManageAllAccess(): void
    {
        $post = $this->postMock();
        $post->method('isPublished')->willReturn(false);
        $post->method('hasAccess')->willReturn(false);
        $post->method('toArray')->willReturnCallback(static fn (array $data = []): array => $data);

        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->method('create')->willReturn($post);
        // A non-privileged author is pinned to their own id regardless of input.
        $postRepository->expects($this->once())
            ->method('save')
            ->with(
                $this->callback(function (Post $saved): bool {
                    $this->assertSame(7, $saved->user_id, 'the author id must be forced to the current user');

                    return true;
                }),
                $this->anything(),
            );

        $url = $this->createMock(UrlProvider::class);
        $url->method('get')->willReturn('/blog/0');

        // manage own (but not manage all) -> user_id is forced, access check passes.
        $user = $this->createMock(User::class);
        $user->id = 7;
        $user->method('hasAccess')->willReturnMap([
            ['blog: manage all posts', false],
            ['blog: manage own posts', true],
        ]);

        $data = ['title' => 'Mine', 'slug' => 'mine', 'user_id' => 999, 'status' => Post::STATUS_DRAFT];
        $this->createController($postRepository, $user, presenter: $this->presenter($url))->saveAction(0, $data);
    }

    public function testDeleteActionRemovesOwnPostThroughRepository(): void
    {
        $post = new Post();
        $post->id = 5;
        $post->user_id = 7;

        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->once())->method('find')->with(5)->willReturn($post);
        $postRepository->expects($this->once())->method('delete')->with($post);

        $result = $this->createController($postRepository, $this->userWithAccess(false, id: 7))->deleteAction(5);

        $this->assertSame('success', $result['message']);
    }

    public function testDeleteActionDeniesDeletingAnotherUsersPost(): void
    {
        $post = new Post();
        $post->id = 5;
        $post->user_id = 99;

        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->once())->method('find')->with(5)->willReturn($post);
        // The ownership gate must block the delete before it reaches the repository.
        $postRepository->expects($this->never())->method('delete');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Access denied.');

        $this->createController($postRepository, $this->userWithAccess(false, id: 7))->deleteAction(5);
    }

    public function testDeleteActionIsNoOpWhenPostMissing(): void
    {
        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->once())->method('find')->with(404)->willReturn(null);
        $postRepository->expects($this->never())->method('delete');

        $result = $this->createController($postRepository, $this->userWithAccess(true))->deleteAction(404);

        $this->assertSame('success', $result['message']);
    }

    public function testCopyActionClonesFoundPostWithResetIdentityAndSuffix(): void
    {
        $post = new Post();
        $post->id = 5;
        $post->user_id = 7;
        $post->title = 'Original';
        $post->status = Post::STATUS_PUBLISHED;
        $post->comment_count = 12;

        $request = new Request();
        $request->request->set('ids', [5]);

        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->once())->method('find')->with(5)->willReturn($post);
        $postRepository->expects($this->once())->method('save')->with($this->callback(
            function (Post $copy) use ($post): bool {
                $this->assertNotSame($post, $copy, 'the copy is a distinct clone, not the source post');
                $this->assertNull($copy->id, 'the copy is reset to an unsaved identity');
                $this->assertSame(Post::STATUS_DRAFT, $copy->status, 'the copy starts as a draft');
                $this->assertSame('Original - Copy', $copy->title, 'the copy title carries the localized " - Copy" suffix');
                $this->assertSame(0, $copy->comment_count, 'the copy resets its comment count');

                return true;
            }
        ));

        $result = $this->createController($postRepository, $this->userWithAccess(false, id: 7), $request)->copyAction();

        $this->assertSame('success', $result['message']);
    }

    /**
     * @return Post&MockObject
     */
    private function postMock(): Post&MockObject
    {
        return $this->getMockBuilder(Post::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toArray', 'isPublished', 'hasAccess'])
            ->getMock();
    }

    private function userWithAccess(bool $granted, int $id = 1): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->id = $id;
        $user->method('hasAccess')->willReturn($granted);

        return $user;
    }

    private function queryReturningFirst(?object $first): QueryBuilder&MockObject
    {
        $query = $this->createMock(QueryBuilder::class);
        $query->method('__call')->willReturnSelf();
        $query->method('related')->willReturnSelf();
        $query->method('first')->willReturn($first);

        return $query;
    }

    private function presenter(UrlProvider $url): PostPresenter
    {
        return new PostPresenter($url, $this->createMock(User::class), $this->blogModule());
    }

    private function blogModule(): Module
    {
        return new Module([
            'name' => 'blog',
            'path' => '',
            'config' => [
                'posts' => ['posts_per_page' => 20],
                'comments' => ['autoclose' => false, 'autoclose_days' => 14],
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
        ?User $user = null,
        ?Request $request = null,
        ?PostPresenter $presenter = null,
    ): PostApiController {
        $url = $this->createMock(UrlProvider::class);

        return new PostApiController(
            $this->moduleManager(),
            $user ?? $this->createMock(User::class),
            $request ?? new Request(),
            new FilterManager(),
            $this->createMock(Connection::class),
            $this->validator,
            $presenter ?? $this->presenter($url),
            $postRepository,
        );
    }
}
