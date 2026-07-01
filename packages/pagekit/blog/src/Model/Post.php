<?php

declare(strict_types=1);

namespace Pagekit\Blog\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Site\ModelServiceLocator;
use Pagekit\System\Model\DataModelTrait;
use Pagekit\User\Model\AccessModelTrait;
use Pagekit\User\Model\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Blog Post entity with PHP 8 Attributes for ORM and Validation.
 */
#[ORM\Entity(tableClass: '@blog_post')]
class Post implements \JsonSerializable
{
    use AccessModelTrait;
    use DataModelTrait;
    use PostModelTrait;

    /* Post draft status. */
    public const STATUS_DRAFT = 0;

    /* Post pending review status. */
    public const STATUS_PENDING_REVIEW = 1;

    /* Post published. */
    public const STATUS_PUBLISHED = 2;

    /* Post unpublished. */
    public const STATUS_UNPUBLISHED = 3;

    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    #[Assert\NotBlank(message: 'validation.post.title_required')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.post.title_max_length'
    )]
    public ?string $title = null;

    #[ORM\Column(type: 'string')]
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

    #[ORM\Column(type: 'integer')]
    #[Assert\NotBlank(message: 'validation.post.user_required')]
    #[Assert\Positive]
    public ?int $user_id = null;

    #[ORM\Column(type: 'datetime')]
    public ?\DateTime $date = null;

    #[ORM\Column(type: 'text')]
    public ?string $content = '';

    #[ORM\Column(type: 'text')]
    public ?string $excerpt = '';

    #[ORM\Column(type: 'smallint')]
    #[Assert\Choice(
        choices: [self::STATUS_DRAFT, self::STATUS_PENDING_REVIEW, self::STATUS_PUBLISHED, self::STATUS_UNPUBLISHED],
        message: 'validation.post.status_invalid'
    )]
    public ?int $status = null;

    #[ORM\Column(type: 'datetime')]
    public ?\DateTime $modified = null;

    #[ORM\Column(type: 'boolean')]
    public ?bool $comment_status = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero]
    public int $comment_count = 0;

    #[ORM\BelongsTo(targetEntity: 'Pagekit\User\Model\User', keyFrom: 'user_id')]
    public ?User $user = null;

    /** @var array<int, Comment>|null */
    #[ORM\HasMany(targetEntity: 'Comment', keyFrom: 'id', keyTo: 'post_id')]
    #[ORM\OrderBy(value: 'created DESC')]
    public ?array $comments = null;

    public bool $readmore = false;

    /** @var array<string, string> */
    protected static array $properties = [
        'author' => 'getAuthor',
        'published' => 'isPublished',
        'accessible' => 'isAccessible',
    ];

    /**
     * @return array<int, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PUBLISHED => __('Published'),
            self::STATUS_UNPUBLISHED => __('Unpublished'),
            self::STATUS_DRAFT => __('Draft'),
            self::STATUS_PENDING_REVIEW => __('Pending Review'),
        ];
    }

    public function getStatusText(): string
    {
        $statuses = self::getStatuses();

        return $statuses[$this->status] ?? __('Unknown');
    }

    public function isCommentable(): bool
    {
        $blog = ModelServiceLocator::getModule('blog');
        if ($blog === null) {
            return (bool) $this->comment_status;
        }
        $autoclose = $blog->config('comments.autoclose') ? $blog->config('comments.autoclose_days') : 0;

        return $this->comment_status && (!$autoclose or $this->date >= new \DateTime("-{$autoclose} day"));
    }

    public function getAuthor(): ?string
    {
        return $this->user ? $this->user->username : null;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->date < new \DateTime();
    }

    public function isAccessible(?User $user = null): bool
    {
        return $this->isPublished() && $this->hasAccess($user ?: ModelServiceLocator::getUser());
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'url' => ModelServiceLocator::getUrl()->get('@blog/id', ['id' => $this->id ?: 0], 'base'),
        ];

        if ($this->comments) {
            $data['comments_pending'] = count(array_filter($this->comments, function ($comment) {
                return $comment->status == Comment::STATUS_PENDING;
            }));
        }

        return $this->toArray($data);
    }
}
