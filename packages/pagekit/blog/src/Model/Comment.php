<?php

declare(strict_types=1);

namespace Pagekit\Blog\Model;

use Pagekit\Comment\Model\Comment as BaseComment;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Blog Comment entity with Symfony Validator integration (Hybrid Mode).
 *
 * Validation: Uses PHP 8 Attributes (#[Assert\...])
 * ORM: Still uses Doctrine Annotations (@Entity, @Column) - TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
 *
 * @Entity(tableClass="@blog_comment")
 */
class Comment extends BaseComment implements \JsonSerializable
{
    /**
     * @Column(type="integer")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'validation.comment.post_required')]
    #[Assert\Positive]
    public int $post_id;

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?string $user_id = null;

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\Email(message: 'validation.comment.email_invalid')]
    public ?string $email = null;

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    // URL is optional. Symfony's Url constraint validates format when provided but accepts null for optional fields.
    // Empty strings are converted to null via __set() magic method since Url constraint rejects empty strings.
    #[Assert\Url(message: 'validation.comment.url_invalid')]
    public ?string $url = null;

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?string $ip = null;

    /**
     * @BelongsTo(targetEntity="Post", keyFrom="post_id")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public mixed $post = null;

    /**
     * @BelongsTo(targetEntity="Pagekit\User\Model\User", keyFrom="user_id")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
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

    /**
     * Magic setter to normalize URL field values.
     *
     * Converts empty strings to null for the URL field. Symfony's Url constraint accepts null
     * (optional field) but rejects empty strings. This normalization ensures consistent behavior
     * when empty strings are assigned from form data or API requests.
     *
     * @param string $name Property name
     * @param mixed $value Property value
     */
    public function __set(string $name, mixed $value): void
    {
        if ($name === 'url' && $value === '') {
            $value = null;
        }

        // Call parent setter if available
        if (method_exists(get_parent_class($this), '__set')) {
            parent::__set($name, $value);
        } else {
            $this->$name = $value;
        }
    }
}
