<?php

declare(strict_types=1);

namespace Pagekit\Comment\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Database\ORM\SerializableModelInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Base Comment entity with PHP 8 Attributes for ORM and Validation.
 */
#[ORM\MappedSuperclass]
abstract class Comment implements SerializableModelInterface
{
    use CommentModelTrait;

    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_SPAM = 2;

    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'validation.comment.content_required')]
    public ?string $content = null;

    #[ORM\Column(type: 'string')]
    #[Assert\NotBlank(message: 'validation.comment.author_required')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.comment.author_max_length'
    )]
    public ?string $author = null;

    #[ORM\Column(type: 'datetime')]
    public \DateTime $created;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Choice(
        choices: [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_SPAM],
        message: 'validation.comment.status_invalid'
    )]
    public int $status = 0;

    #[ORM\Column(type: 'integer')]
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
        $this->created = new \DateTime();
    }

    public function __toString(): string
    {
        return 'Comment #'.$this->id;
    }
}
