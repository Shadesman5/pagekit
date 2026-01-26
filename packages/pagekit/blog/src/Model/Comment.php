<?php

declare(strict_types=1);

namespace Pagekit\Blog\Model;

use Pagekit\Comment\Model\Comment as BaseComment;
use Pagekit\Database\ORM\Attribute as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Blog Comment entity with PHP 8 Attributes for ORM and Validation.
 */
#[ORM\Entity(tableClass: '@blog_comment')]
class Comment extends BaseComment implements \JsonSerializable
{
    #[ORM\Column(type: 'integer')]
    #[Assert\NotBlank(message: 'validation.comment.post_required')]
    #[Assert\Positive]
    public int $post_id;

    #[ORM\Column(type: 'string')]
    public ?string $user_id = null;

    #[ORM\Column(type: 'string')]
    #[Assert\Email(message: 'validation.comment.email_invalid')]
    public ?string $email = null;

    #[ORM\Column(type: 'string')]
    #[Assert\Url(message: 'validation.comment.url_invalid')]
    public ?string $url = '';

    #[ORM\Column(type: 'string')]
    public ?string $ip = null;

    #[ORM\BelongsTo(targetEntity: 'Post', keyFrom: 'post_id')]
    public mixed $post = null;

    #[ORM\BelongsTo(targetEntity: 'Pagekit\User\Model\User', keyFrom: 'user_id')]
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
