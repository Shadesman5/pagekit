<?php

namespace Pagekit\Blog\Model;

use Pagekit\Comment\Model\Comment as BaseComment;

/**
 * @Entity(tableClass="@blog_comment")
 */
class Comment extends BaseComment implements \JsonSerializable
{
    /** @Column(type="integer") */
    public int $post_id;

    /** @Column(type="string") */
    public ?string $user_id = null;

    /** @Column(type="string") */
    public ?string $email = null;

    /** @Column(type="string") */
    public ?string $url = '';

    /** @Column(type="string") */
    public ?string $ip = null;

    /** @BelongsTo(targetEntity="Post", keyFrom="post_id") */
    public mixed $post = null;

    /** @BelongsTo(targetEntity="Pagekit\User\Model\User", keyFrom="user_id") */
    public mixed $user = null;

    /** @var int */
    public int $special = 0;

    public function setPost($post): void
    {
        $this->post = $post;

        if ($post) {
            $this->post_id = $post->id;
        }
    }

    public function getStatusText(): string
    {
        $statuses = self::getStatuses();

        return $statuses[$this->status] ?? __('Unknown');
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_APPROVED => __('Approved'),
            self::STATUS_PENDING  => __('Pending'),
            self::STATUS_SPAM     => __('Spam')
        ];
    }
}
