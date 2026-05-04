<?php

declare(strict_types=1);

namespace Pagekit\Blog\Event;

use Pagekit\Blog\Model\Post;
use Pagekit\Comment\Model\Comment;
use Pagekit\Event\EventInterface;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\User\Model\Role;

class PostListener implements EventSubscriberInterface
{
    public function onCommentChange(EventInterface $event, Comment $comment): void
    {
        Post::updateCommentInfo($comment->post_id);
    }

    public function onRoleDelete(EventInterface $event, Role|int $role): void
    {
        Post::removeRole($role);
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, string>
     */
    public function subscribe(): array
    {
        return [
            'model.comment.saved' => 'onCommentChange',
            'model.comment.deleted' => 'onCommentChange',
            'model.role.deleted' => 'onRoleDelete',
        ];
    }
}
