<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Blog;

use Pagekit\Blog\Model\Post;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityEvent;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Database\ORM\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the {@see \Pagekit\Blog\Model\PostModelTrait} lifecycle handlers
 * after their Step 2 migration onto the injected {@see EntityManager} carried by
 * an {@see EntityEvent}.
 *
 * `saving()` stamps the modified timestamp and keeps the slug unique; `deleting()`
 * cascades the post's comments away. Both reach the database only through the
 * event's manager, so the repository/query chain and the connection are mocked —
 * no kernel, no database. The blog package is not on composer's autoload map, so
 * bootstrap.php requires the Post/Comment classes.
 */
class PostModelTraitTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testSavingStampsModifiedAndKeepsAUniqueSlugUnchanged(): void
    {
        $post = new Post();
        $post->id = null;
        $post->slug = 'unique-post';
        $post->modified = null;

        $manager = $this->managerReturningFirstResults(Post::class, [null]);

        Post::saving(new EntityEvent('post.saving', $manager), $post);

        $this->assertInstanceOf(\DateTime::class, $post->modified, 'saving must always stamp the modified timestamp');
        $this->assertSame('unique-post', $post->slug, 'a slug with no collision must be left unchanged');
    }

    public function testSavingSuffixesTheSlugUntilItIsUnique(): void
    {
        $post = new Post();
        $post->id = null;
        $post->slug = 'hello';

        // One collision then a clear slot: 'hello' -> 'hello-2'.
        $manager = $this->managerReturningFirstResults(Post::class, [new Post(), null]);

        Post::saving(new EntityEvent('post.saving', $manager), $post);

        $this->assertSame('hello-2', $post->slug, 'a colliding slug must be suffixed until unique');
    }

    public function testSavingClimbsPastEveryCollision(): void
    {
        $post = new Post();
        $post->id = null;
        $post->slug = 'news';

        // Two collisions then a clear slot: 'news' -> 'news-2' -> 'news-3'.
        $manager = $this->managerReturningFirstResults(Post::class, [new Post(), new Post(), null]);

        Post::saving(new EntityEvent('post.saving', $manager), $post);

        $this->assertSame('news-3', $post->slug);
    }

    public function testDeletingRemovesEveryCommentOfThePost(): void
    {
        $post = new Post();
        $post->id = 42;

        $captured = null;
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('delete')
            ->willReturnCallback(function (string $table, array $criteria) use (&$captured): int {
                $captured = [$table, $criteria];

                return 4;
            });

        $manager = $this->createMock(EntityManager::class);
        $manager->method('getConnection')->willReturn($connection);

        Post::deleting(new EntityEvent('post.deleting', $manager), $post);

        $this->assertSame(
            ['@blog_comment', ['post_id' => 42]],
            $captured,
            "deleting a post must purge exactly that post's comments"
        );
    }

    /**
     * Builds a mock EntityManager whose repository for $entity returns a query
     * builder answering `first()` with the given sequence (the `->where(closure)`
     * refinement is a self-returning no-op via `__call`).
     *
     * @param class-string        $entity
     * @param array<int, ?object> $firstResults consecutive `first()` return values
     */
    private function managerReturningFirstResults(string $entity, array $firstResults): EntityManager
    {
        $query = $this->createMock(QueryBuilder::class);
        $query->method('__call')->willReturnSelf();
        $query->method('first')->willReturnOnConsecutiveCalls(...$firstResults);

        $repository = $this->createMock(Repository::class);
        $repository->method('where')->willReturn($query);

        $manager = $this->createMock(EntityManager::class);
        $manager->method('getRepository')->with($entity)->willReturn($repository);

        return $manager;
    }
}
