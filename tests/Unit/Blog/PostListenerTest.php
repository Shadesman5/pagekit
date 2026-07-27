<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Blog;

use Pagekit\Blog\Event\PostListener;
use Pagekit\Blog\Model\Comment;
use Pagekit\Blog\Model\PostRepository;
use Pagekit\Event\EventInterface;
use Pagekit\User\Model\Role;
use PHPUnit\Framework\TestCase;

/**
 * Covers PostListener against the injected {@see PostRepository}. The
 * comment-change handlers recount a post's comments via `updateCommentInfo()` and
 * the role-delete handler strips the role from every post via `removeRole()`, both
 * delegated to the repository. The repository is mocked directly, so the
 * delegation (and the Role id int cast the database module requires) is asserted
 * with no database.
 *
 * The blog and base-comment classes are runtime-loaded (not in composer's
 * autoload map); bootstrap.php requires them in dependency order.
 */
class PostListenerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testOnCommentChangeRecountsTheOwningPostsComments(): void
    {
        $comment = new Comment();
        $comment->post_id = 42;

        $posts = $this->createMock(PostRepository::class);
        $posts->expects($this->once())
            ->method('updateCommentInfo')
            ->with(42);
        $posts->expects($this->never())->method('removeRole');

        (new PostListener($posts))->onCommentChange($this->createMock(EventInterface::class), $comment);
    }

    public function testOnRoleDeleteStripsTheDeletedRoleFromEveryPost(): void
    {
        $role = new Role();
        $role->id = 7;

        $posts = $this->createMock(PostRepository::class);
        // removeRole() is int-only (the database module never imports Role), so
        // the listener must narrow the Role to its id.
        $posts->expects($this->once())
            ->method('removeRole')
            ->with($this->identicalTo(7))
            ->willReturn(1);
        $posts->expects($this->never())->method('updateCommentInfo');

        (new PostListener($posts))->onRoleDelete($this->createMock(EventInterface::class), $role);
    }

    public function testSubscribeMapsCommentAndRoleEventsToTheirHandlers(): void
    {
        $subscriptions = (new PostListener($this->createMock(PostRepository::class)))->subscribe();

        $this->assertSame([
            'model.comment.saved' => 'onCommentChange',
            'model.comment.deleted' => 'onCommentChange',
            'model.role.deleted' => 'onRoleDelete',
        ], $subscriptions);
    }
}
