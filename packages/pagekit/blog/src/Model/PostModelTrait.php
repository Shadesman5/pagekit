<?php

declare(strict_types=1);

namespace Pagekit\Blog\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Database\ORM\ModelTrait;
use Pagekit\Event\EventInterface;

trait PostModelTrait
{
    use ModelTrait;

    /**
     * Updates the comments info on post.
     */
    public static function updateCommentInfo(int $id): void
    {
        $query = Comment::where(['post_id' => $id, 'status' => Comment::STATUS_APPROVED]);

        self::where(compact('id'))->update(['comment_count' => $query->count()]);
    }

    /**
     * Get all users who have written an article.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getAuthors(): array
    {
        return self::query()->select('user_id', 'name', 'username')->groupBy('user_id', 'name', 'username')->join('@system_user', 'user_id = @system_user.id')->execute()->fetchAllAssociative();
    }

    #[ORM\Saving]
    public static function saving(EventInterface $event, Post $post): void
    {
        $post->modified = new \DateTime();

        $i = 2;
        $id = $post->id;

        while (self::where('slug = ?', [$post->slug])->where(function ($query) use ($id) {
            if ($id) {
                $query->where('id <> ?', [$id]);
            }
        })->first()) {
            $post->slug = preg_replace('/-\d+$/', '', $post->slug ?? '').'-'.$i++;
        }
    }

    #[ORM\Deleting]
    public static function deleting(EventInterface $event, Post $post): void
    {
        self::getConnection()->delete('@blog_comment', ['post_id' => $post->id]);
    }
}
