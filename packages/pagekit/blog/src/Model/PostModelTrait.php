<?php

declare(strict_types=1);

namespace Pagekit\Blog\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Database\ORM\EntityEvent;
use Pagekit\Database\ORM\ModelTrait;

trait PostModelTrait
{
    use ModelTrait;

    #[ORM\Saving]
    public static function saving(EntityEvent $event, Post $post): void
    {
        $em = $event->getEntityManager();

        $post->modified = new \DateTime();

        $i = 2;
        $id = $post->id;

        while ($em->getRepository(Post::class)->where('slug = ?', [$post->slug])->where(function ($query) use ($id) {
            if ($id) {
                $query->where('id <> ?', [$id]);
            }
        })->first()) {
            $post->slug = preg_replace('/-\d+$/', '', $post->slug ?? '').'-'.$i++;
        }
    }

    #[ORM\Deleting]
    public static function deleting(EntityEvent $event, Post $post): void
    {
        $event->getEntityManager()->getConnection()->delete('@blog_comment', ['post_id' => $post->id]);
    }
}
