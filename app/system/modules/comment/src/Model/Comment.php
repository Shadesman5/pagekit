<?php

declare(strict_types=1);

namespace Pagekit\Comment\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Base Comment entity with Symfony Validator integration (Hybrid Mode).
 *
 * Validation: Uses PHP 8 Attributes (#[Assert\...])
 * ORM: Still uses Doctrine Annotations (@MappedSuperclass, @Column) - TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
 *
 * @MappedSuperclass
 */
abstract class Comment
{
    use CommentModelTrait;

    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_SPAM = 2;

    /**
     * @Column(type="integer") @Id
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?int $id = null;

    /**
     * @Column(type="text")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'validation.comment.content_required')]
    public ?string $content = null;

    /**
     * @Column(type="string")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'validation.comment.author_required')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.comment.author_max_length'
    )]
    public ?string $author = null;

    /**
     * @Column(type="datetime")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public \DateTime $created;

    /**
     * @Column(type="smallint")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\Choice(
        choices: [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_SPAM],
        message: 'validation.comment.status_invalid'
    )]
    public int $status = 0;

    /**
     * @Column(type="integer")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?int $parent_id = null;

    /**
     * Should be mapped by the end developer.
     */
    public \Pagekit\Comment\Model\Comment $parent;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->created = new \DateTime;
    }

    public function __toString(): string
    {
        return 'Comment #'.$this->id;
    }
}
