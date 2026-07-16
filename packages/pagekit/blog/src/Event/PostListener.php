<?php

declare(strict_types=1);

namespace Pagekit\Blog\Event;

use Pagekit\Blog\Model\PostRepository;
use Pagekit\Comment\Model\Comment;
use Pagekit\Event\EventInterface;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\User\Model\Role;

class PostListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly PostRepository $posts,
    ) {
    }

    public function onCommentChange(EventInterface $event, Comment $comment): void
    {
        $this->posts->updateCommentInfo($comment->post_id);
    }

    public function onRoleDelete(EventInterface $event, Role $role): void
    {
        $this->posts->removeRole((int) $role->id);
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
