<?php

declare(strict_types=1);

namespace Pagekit\Comment\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Database\ORM\EntityEvent;
use Pagekit\Database\ORM\ModelTrait;

trait CommentModelTrait
{
    use ModelTrait;

    #[ORM\Deleting]
    public static function deleting(EntityEvent $event, Comment $comment): void
    {
        // The base Comment is an abstract mapped superclass; resolve the concrete
        // mapped entity from the instance so the repository targets its real table.
        $event->getEntityManager()->getRepository($comment::class)->where(['parent_id = :old_parent'], [':old_parent' => $comment->id])->update(['parent_id' => $comment->parent_id]);
    }
}
