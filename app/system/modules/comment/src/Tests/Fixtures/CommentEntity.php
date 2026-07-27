<?php

declare(strict_types=1);

namespace Pagekit\Comment\Tests\Fixtures;

use Pagekit\Comment\Model\Comment;

/**
 * Concrete stand-in for the abstract mapped-superclass {@see Comment}, so the
 * shared {@see \Pagekit\Comment\Model\CommentModelTrait::deleting()} handler can
 * be invoked against a real instance. The base class is a MappedSuperclass and
 * the module is not on composer's autoload map, so bootstrap.php requires this
 * after the parent (mirrors the blog Tests bootstrap).
 */
class CommentEntity extends Comment
{
}
