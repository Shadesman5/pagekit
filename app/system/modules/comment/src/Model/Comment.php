<?php

declare(strict_types=1);

namespace Pagekit\Comment\Model;

/**
 * @MappedSuperclass
 */
abstract class Comment
{
    use CommentModelTrait;

    const STATUS_PENDING = 0;
    const STATUS_APPROVED = 1;
    const STATUS_SPAM = 2;

    /** @Column(type="integer") @Id */
    public ?int $id = null;

    /** @Column(type="text") */
    public ?string $content = null;

    /** @Column(type="string") */
    public ?string $author = null;

    /** @Column(type="datetime") */
    public \DateTime $created;

    /** @Column(type="smallint") */
    public int $status = 0;

    /** @Column(type="integer") */
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
