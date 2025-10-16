<?php

declare(strict_types=1);

namespace Pagekit\Blog\Model;

use Pagekit\Application as App;
use Pagekit\System\Model\DataModelTrait;
use Pagekit\User\Model\AccessModelTrait;
use Pagekit\User\Model\User;

/**
 * @Entity(tableClass="@blog_post")
 */
class Post implements \JsonSerializable
{
    use AccessModelTrait, DataModelTrait, PostModelTrait;

    /* Post draft status. */
    const STATUS_DRAFT = 0;

    /* Post pending review status. */
    const STATUS_PENDING_REVIEW = 1;

    /* Post published. */
    const STATUS_PUBLISHED = 2;

    /* Post unpublished. */
    const STATUS_UNPUBLISHED = 3;

    /** @Column(type="integer") @Id */
    public ?int $id = null;

    /** @Column(type="string") */
    public ?string $title = null;

    /** @Column(type="string") */
    public ?string $slug = null;

    /** @Column(type="integer") */
    public ?int $user_id = null;

    /** @Column(type="datetime") */
    public ?\DateTime $date = null;

    /** @Column(type="text") */
    public string $content = '';

    /** @Column(type="text") */
    public string $excerpt = '';

    /** @Column(type="smallint") */
    public ?int $status = null;

    /** @Column(type="datetime") */
    public ?\DateTime $modified = null;

    /** @Column(type="boolean") */
    public ?bool $comment_status = null;

    /** @Column(type="integer") */
    public int $comment_count = 0;

    /**
     * @BelongsTo(targetEntity="Pagekit\User\Model\User", keyFrom="user_id")
     */
    public mixed $user = null;

    /**
     * @HasMany(targetEntity="Comment", keyFrom="id", keyTo="post_id")
     * @OrderBy({"created" = "DESC"})
     */
    public mixed $comments = null;

    /** @var bool */
    public bool $readmore = false;

    protected static array $properties = [
        'author' => 'getAuthor',
        'published' => 'isPublished',
        'accessible' => 'isAccessible'
    ];

    public static function getStatuses(): array
    {
        return [
            self::STATUS_PUBLISHED => __('Published'),
            self::STATUS_UNPUBLISHED => __('Unpublished'),
            self::STATUS_DRAFT => __('Draft'),
            self::STATUS_PENDING_REVIEW => __('Pending Review')
        ];
    }

    public function getStatusText(): string
    {
        $statuses = self::getStatuses();

        return $statuses[$this->status] ?? __('Unknown');
    }

    public function isCommentable(): bool
    {
        $blog      = App::module('blog');
        $autoclose = $blog->config('comments.autoclose') ? $blog->config('comments.autoclose_days') : 0;

        return $this->comment_status && (!$autoclose or $this->date >= new \DateTime("-{$autoclose} day"));
    }

    public function getAuthor(): ?string
    {
        return $this->user ? $this->user->username : null;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->date < new \DateTime;
    }

    public function isAccessible(?User $user = null): bool
    {
        return $this->isPublished() && $this->hasAccess($user ?: App::user());
    }

    /**
     * {@inheritdoc}
     */
    public function jsonSerialize(): array
    {
        $data = [
            'url' => App::url('@blog/id', ['id' => $this->id ?: 0], 'base')
        ];

        if ($this->comments) {
            $data['comments_pending'] = count(array_filter($this->comments, function($comment) { 
                return $comment->status == Comment::STATUS_PENDING; 
            }));
        }

        return $this->toArray($data);
    }
}
