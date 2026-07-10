<?php

declare(strict_types=1);

namespace Pagekit\Blog;

use Pagekit\Application\UrlProvider;
use Pagekit\Blog\Model\Comment;
use Pagekit\Blog\Model\Post;
use Pagekit\Module\Module;
use Pagekit\User\Model\User;

/**
 * Presentation layer for {@see Post} entities.
 *
 * Constructor-injects the URL provider, the current user and the blog module so
 * the enriched `url`/`comments_pending`/`accessible` output no longer depends on
 * a static service-locator reach-through. The entity stays a plain ORM
 * model; presentation concerns live here.
 */
final class PostPresenter
{
    public function __construct(
        private readonly UrlProvider $url,
        private readonly User $user,
        private readonly Module $blog,
    ) {
    }

    public function isCommentable(Post $post): bool
    {
        $autoclose = $this->blog->config('comments.autoclose') ? $this->blog->config('comments.autoclose_days') : 0;

        return (bool) ($post->comment_status && (!$autoclose || $post->date >= new \DateTime("-{$autoclose} day")));
    }

    public function isAccessible(Post $post, ?User $user = null): bool
    {
        return $post->isPublished() && $post->hasAccess($user ?? $this->user);
    }

    /**
     * Serializes the post with the enriched `url`, optional `comments_pending`
     * and `accessible` fields, reproducing the shape the entity's former
     * jsonSerialize() emitted (the `accessible` value formerly flowed through
     * the entity's `$properties` map).
     *
     * @return array<string, mixed>
     */
    public function toArray(Post $post): array
    {
        $data = [
            'url' => $this->url->get('@blog/id', ['id' => $post->id ?: 0], UrlProvider::BASE_PATH),
        ];

        if ($post->comments) {
            $data['comments_pending'] = count(array_filter(
                $post->comments,
                static fn (Comment $comment): bool => $comment->status === Comment::STATUS_PENDING,
            ));
        }

        $data['accessible'] = $this->isAccessible($post);

        return $post->toArray($data);
    }
}
