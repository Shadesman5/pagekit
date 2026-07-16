<?php

declare(strict_types=1);

namespace Pagekit\Blog\Model;

use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Repository;

/**
 * Data-mapper repository for {@see Post}, hosting the helpers that used to live
 * as statics on `PostModelTrait`.
 *
 * @extends Repository<Post>
 */
class PostRepository extends Repository
{
    public function __construct(EntityManager $em)
    {
        parent::__construct($em, $em->getMetadata(Post::class));
    }

    /**
     * Recounts the approved comments of a post and stores it on the post.
     */
    public function updateCommentInfo(int $id): void
    {
        $count = $this->em->getRepository(Comment::class)
            ->where(['post_id' => $id, 'status' => Comment::STATUS_APPROVED])
            ->count();

        $this->where(compact('id'))->update(['comment_count' => $count]);
    }

    /**
     * Gets all users who have written an article.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAuthors(): array
    {
        return $this->query()
            ->select('user_id', 'name', 'username')
            ->groupBy('user_id', 'name', 'username')
            ->join('@system_user', 'user_id = @system_user.id')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
