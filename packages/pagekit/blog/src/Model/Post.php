<?php

declare(strict_types=1);

namespace Pagekit\Blog\Model;

use Pagekit\Application as App;
use Pagekit\System\Model\DataModelTrait;
use Pagekit\User\Model\AccessModelTrait;
use Pagekit\User\Model\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Blog Post entity with Symfony Validator integration (Hybrid Mode).
 *
 * Validation: Uses PHP 8 Attributes (#[Assert\...])
 * ORM: Still uses Doctrine Annotations (@Entity, @Column) - TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
 *
 * @Entity(tableClass="@blog_post")
 */
class Post implements \JsonSerializable
{
    use AccessModelTrait, DataModelTrait, PostModelTrait;

    /* Post draft status. */
    public const STATUS_DRAFT = 0;

    /* Post pending review status. */
    public const STATUS_PENDING_REVIEW = 1;

    /* Post published. */
    public const STATUS_PUBLISHED = 2;

    /* Post unpublished. */
    public const STATUS_UNPUBLISHED = 3;

    /**
     * @Column(type="integer") @Id
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?int $id = null;

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'validation.post.title_required')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.post.title_max_length'
    )]
    public ?string $title = null;

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'validation.post.slug_required')]
    #[Assert\Regex(
        pattern: '/^[a-z0-9\-_]+$/',
        message: 'validation.post.slug_invalid'
    )]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.post.slug_max_length'
    )]
    public ?string $slug = null;

    /**
     * @Column(type="integer")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'validation.post.user_required')]
    #[Assert\Positive]
    public ?int $user_id = null;

    /**
     * @Column(type="datetime")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?\DateTime $date = null;

    /**
     * @Column(type="text")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?string $content = '';

    /**
     * @Column(type="text")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?string $excerpt = '';

    /**
     * @Column(type="smallint")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\Choice(
        choices: [self::STATUS_DRAFT, self::STATUS_PENDING_REVIEW, self::STATUS_PUBLISHED, self::STATUS_UNPUBLISHED],
        message: 'validation.post.status_invalid'
    )]
    public ?int $status = null;

    /**
     * @Column(type="datetime")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?\DateTime $modified = null;

    /**
     * @Column(type="boolean")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?bool $comment_status = null;

    /**
     * @Column(type="integer")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\PositiveOrZero]
    public int $comment_count = 0;

    /**
     * @BelongsTo(targetEntity="Pagekit\User\Model\User", keyFrom="user_id")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public mixed $user = null;

    /**
     * @HasMany(targetEntity="Comment", keyFrom="id", keyTo="post_id")
     * @OrderBy({"created" = "DESC"})
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
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
