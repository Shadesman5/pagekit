<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Blog;

use Doctrine\DBAL\Result;
use Pagekit\Blog\Model\Comment;
use Pagekit\Blog\Model\Post;
use Pagekit\Blog\Model\PostRepository;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Database\ORM\Repository;
use Pagekit\Database\Query\QueryBuilder as DbalQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PostRepository} — the helpers the Step 3 migration lifted
 * off the static `PostModelTrait`.
 *
 * `updateCommentInfo()` recounts a post's approved comments through the Comment
 * repository and writes the total back onto the post; `getAuthors()` runs a
 * grouped join against the user table. Both are asserted with a mocked
 * EntityManager / connection / DBAL query builder (the generic
 * {@see \Pagekit\Database\Tests\ORM\RepositoryTest} pattern) so no database is
 * needed.
 *
 * The blog package is not on composer's autoload map, so bootstrap.php requires
 * the Post/Comment/PostRepository classes in dependency order.
 */
class PostRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    // -----------------------------------------------------------------------
    // updateCommentInfo(): recount approved comments -> write comment_count.
    // -----------------------------------------------------------------------

    public function testUpdateCommentInfoWritesTheApprovedCommentCountToThePost(): void
    {
        $id = 42;
        $approvedCount = 3;

        // Comment side: count only the post's approved comments.
        $commentQuery = $this->createMock(QueryBuilder::class);
        $commentQuery->expects($this->once())
            ->method('__call')
            ->with('count', [])
            ->willReturn($approvedCount);

        $commentRepository = $this->createMock(Repository::class);
        $commentRepository->expects($this->once())
            ->method('where')
            ->with(['post_id' => $id, 'status' => Comment::STATUS_APPROVED])
            ->willReturn($commentQuery);

        // Post side: write the recount back onto the post row.
        $captured = null;
        $innerQuery = $this->createMock(DbalQueryBuilder::class);
        $innerQuery->method('from')->willReturnSelf();
        $innerQuery->expects($this->once())
            ->method('where')
            ->with(['id' => $id], [])
            ->willReturnSelf();
        $innerQuery->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (array $values) use (&$captured): int {
                $captured = $values;

                return 1;
            });

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($innerQuery);

        $em = $this->createMock(EntityManager::class);
        $em->method('getMetadata')->with(Post::class)->willReturn($this->postMetadata());
        $em->method('getRepository')->with(Comment::class)->willReturn($commentRepository);
        $em->method('getConnection')->willReturn($connection);

        $repository = new PostRepository($em);
        $repository->updateCommentInfo($id);

        $this->assertSame(
            ['comment_count' => $approvedCount],
            $captured,
            'the recounted approved total must be written to comment_count'
        );
    }

    // -----------------------------------------------------------------------
    // getAuthors(): grouped join projecting the distinct article authors.
    // -----------------------------------------------------------------------

    public function testGetAuthorsProjectsDistinctAuthorsJoinedToTheUserTable(): void
    {
        $authors = [
            ['user_id' => 1, 'name' => 'Alice', 'username' => 'alice'],
            ['user_id' => 2, 'name' => 'Bob', 'username' => 'bob'],
        ];

        $result = $this->createMock(Result::class);
        $result->expects($this->once())->method('fetchAllAssociative')->willReturn($authors);

        $innerQuery = $this->createMock(DbalQueryBuilder::class);
        $innerQuery->method('from')->willReturnSelf();
        $innerQuery->expects($this->once())
            ->method('select')
            ->with('user_id', 'name', 'username')
            ->willReturnSelf();
        $innerQuery->expects($this->once())
            ->method('groupBy')
            ->with('user_id', 'name', 'username')
            ->willReturnSelf();
        $innerQuery->expects($this->once())
            ->method('join')
            ->with('@system_user', 'user_id = @system_user.id')
            ->willReturnSelf();
        $innerQuery->expects($this->once())->method('executeQuery')->willReturn($result);

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($innerQuery);

        $em = $this->createMock(EntityManager::class);
        $em->method('getMetadata')->with(Post::class)->willReturn($this->postMetadata());
        $em->method('getConnection')->willReturn($connection);

        $repository = new PostRepository($em);

        $this->assertSame($authors, $repository->getAuthors());
    }

    /**
     * A Post metadata mock exposing the table the query builder reads in its
     * constructor.
     */
    private function postMetadata(): Metadata
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getTable')->willReturn('@blog_post');

        return $metadata;
    }
}
