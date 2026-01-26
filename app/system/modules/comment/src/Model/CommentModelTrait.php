<?php

declare(strict_types=1);

namespace Pagekit\Comment\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Database\ORM\ModelTrait;

trait CommentModelTrait
{
    use ModelTrait;

    #[ORM\Deleting]
    public static function deleting($event, Comment $comment): void
    {
        self::where(['parent_id = :old_parent'], [':old_parent' => $comment->id])->update(['parent_id' => $comment->parent_id]);
    }
}
