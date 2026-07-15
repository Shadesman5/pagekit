<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Blog;

use Pagekit\Application\UrlProvider;
use Pagekit\Blog\Controller\CommentApiController;
use Pagekit\Blog\Model\Comment;
use Pagekit\Blog\Model\PostRepository;
use Pagekit\Blog\PostPresenter;
use Pagekit\Content\ContentHelper;
use Pagekit\Database\ORM\Repository;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Covers CommentApiController against the injected
 * Repository<Comment>/PostRepository: delete and bulk-delete drive find/delete
 * through the repository, and the save action's access gates precede any
 * repository lookup. The repositories are mocked directly, so the delegation and
 * the security guards are asserted with no database.
 *
 * The blog/base-comment/content classes are runtime-loaded (not in composer's
 * autoload map); bootstrap.php requires them in dependency order.
 */
class CommentApiControllerTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testDeleteActionRemovesCommentThroughRepository(): void
    {
        $comment = new Comment();

        $commentRepository = $this->createMock(Repository::class);
        $commentRepository->expects($this->once())->method('find')->with(5)->willReturn($comment);
        $commentRepository->expects($this->once())->method('delete')->with($comment);

        $result = $this->createController($commentRepository)->deleteAction(5);

        $this->assertSame('success', $result['message']);
    }

    public function testDeleteActionIsNoOpWhenCommentMissing(): void
    {
        $commentRepository = $this->createMock(Repository::class);
        $commentRepository->expects($this->once())->method('find')->with(404)->willReturn(null);
        $commentRepository->expects($this->never())->method('delete');

        $result = $this->createController($commentRepository)->deleteAction(404);

        $this->assertSame('success', $result['message']);
    }

    public function testBulkDeleteActionDeletesEveryFilteredId(): void
    {
        $first = new Comment();
        $second = new Comment();

        $commentRepository = $this->createMock(Repository::class);
        $commentRepository->method('find')->willReturnMap([[1, $first], [2, $second]]);
        // The zero is filtered out; only the two real ids reach delete().
        $commentRepository->expects($this->exactly(2))->method('delete');

        $result = $this->createController($commentRepository)->bulkDeleteAction([1, 0, 2]);

        $this->assertSame('success', $result['message']);
    }

    public function testSaveActionDeniesCreatingCommentWithoutPostCommentsAccess(): void
    {
        $commentRepository = $this->createMock(Repository::class);
        $commentRepository->expects($this->never())->method('create');
        $commentRepository->expects($this->never())->method('find');

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Insufficient User Rights.');

        $this->createController($commentRepository, user: $this->userWithAccess(false))->saveAction([], 0);
    }

    public function testSaveActionDeniesEditingCommentWithoutManageCommentsAccess(): void
    {
        $commentRepository = $this->createMock(Repository::class);
        $commentRepository->expects($this->never())->method('find');

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Insufficient User Rights.');

        $this->createController($commentRepository, user: $this->userWithAccess(false))->saveAction([], 5);
    }

    public function testSaveActionThrowsNotFoundWhenEditingMissingComment(): void
    {
        $commentRepository = $this->createMock(Repository::class);
        $commentRepository->expects($this->once())->method('find')->with(5)->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Comment not found.');

        $this->createController($commentRepository, user: $this->userWithAccess(true))->saveAction([], 5);
    }

    private function userWithAccess(bool $granted): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('hasAccess')->willReturn($granted);

        return $user;
    }

    private function blogModule(): Module
    {
        return new Module([
            'name' => 'blog',
            'path' => '',
            'config' => ['comments' => ['comments_per_page' => 20, 'require_email' => true, 'order' => 'ASC']],
        ]);
    }

    private function moduleManager(): ModuleManager
    {
        $module = $this->createMock(ModuleManager::class);
        $module->method('get')->with('blog')->willReturn($this->blogModule());

        return $module;
    }

    private function presenter(): PostPresenter
    {
        return new PostPresenter($this->createMock(UrlProvider::class), $this->createMock(User::class), $this->blogModule());
    }

    /**
     * @param Repository<Comment> $commentRepository
     */
    private function createController(
        Repository $commentRepository,
        ?PostRepository $postRepository = null,
        ?User $user = null,
        ?Request $request = null,
    ): CommentApiController {
        return new CommentApiController(
            $this->moduleManager(),
            $user ?? $this->createMock(User::class),
            $request ?? new Request(),
            $this->createMock(ContentHelper::class),
            $this->validator,
            $this->presenter(),
            $commentRepository,
            $postRepository ?? $this->createMock(PostRepository::class),
        );
    }
}
