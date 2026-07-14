<?php

declare(strict_types=1);

namespace Pagekit\Comment\Tests;

use Pagekit\Comment\Tests\Fixtures\CommentEntity;
use Pagekit\Database\ORM\EntityEvent;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Database\ORM\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the {@see \Pagekit\Comment\Model\CommentModelTrait::deleting()}
 * lifecycle handler after its Step 2 migration onto the injected
 * {@see EntityManager} carried by an {@see EntityEvent}.
 *
 * Deleting a comment re-parents its direct replies onto the deleted comment's
 * own parent, resolving the concrete mapped entity from `$comment::class` so the
 * repository targets the real table. The repository/query chain is mocked so the
 * re-parent UPDATE is asserted without a database.
 */
class CommentModelTraitTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testDeletingReparentsRepliesOntoTheDeletedCommentsParent(): void
    {
        $comment = new CommentEntity();
        $comment->id = 5;
        $comment->parent_id = 2;

        $capturedUpdate = null;
        $query = $this->createMock(QueryBuilder::class);
        $query->expects($this->once())
            ->method('__call')
            ->willReturnCallback(function (string $method, array $args) use (&$capturedUpdate): int {
                $capturedUpdate = [$method, $args];

                return 3;
            });

        $repository = $this->createMock(Repository::class);
        $repository->expects($this->once())
            ->method('where')
            ->with(['parent_id = :old_parent'], [':old_parent' => 5])
            ->willReturn($query);

        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->once())
            ->method('getRepository')
            ->with(CommentEntity::class)
            ->willReturn($repository);

        CommentEntity::deleting(new EntityEvent('comment.deleting', $manager), $comment);

        $this->assertSame(
            ['update', [['parent_id' => 2]]],
            $capturedUpdate,
            "orphaned replies must inherit the deleted comment's parent_id"
        );
    }
}
