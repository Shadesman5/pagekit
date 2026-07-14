<?php

declare(strict_types=1);

namespace Pagekit\Site\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Database\ORM\EntityEvent;

trait NodeModelTrait
{
    #[ORM\Saving]
    public static function saving(EntityEvent $event, Node $node): void
    {
        $em = $event->getEntityManager();
        $db = $em->getConnection();
        $nodes = $em->getRepository(Node::class);

        $i = 2;
        $id = $node->id;

        if (!$node->slug) {
            $node->slug = $node->title;
        }

        // Ensure link is set (database has NOT NULL constraint)
        // This is a safety fallback for cases where link is not provided
        if (empty($node->link)) {
            // Generate a default link based on node type or path
            if ($node->type && $node->type !== 'link') {
                // For typed nodes (page, blog, etc.), use type-based route
                $node->link = '@' . $node->type . '/id';
            } else {
                // For generic links or unknown types, create a safe default
                $node->link = '#';
            }
        }

        // A node cannot have itself as a parent
        if ($node->parent_id === $node->id) {
            $node->parent_id = 0;
        }

        // Ensure unique slug
        while ($nodes->where(['slug = ?', 'parent_id= ?'], [$node->slug, $node->parent_id])->where(function ($query) use ($id) {
            if ($id) {
                $query->where('id <> ?', [$id]);
            }
        })->first()) {
            $node->slug = preg_replace('/-\d+$/', '', $node->slug ?? '').'-'.$i++;
        }

        // Update own path
        $path = '/'.$node->slug;
        if ($node->parent_id && $parent = $nodes->find($node->parent_id) and $parent->menu == $node->menu) {
            $path = $parent->path.$path;
        } else {
            // set Parent to 0, if old parent is not found
            $node->parent_id = 0;
        }

        // Update children's paths
        if ($id && $path != $node->path) {
            $db->executeStatement(
                'UPDATE '.$em->getMetadata(Node::class)->getTable()
                .' SET path = REPLACE ('.$db->getDatabasePlatform()->getConcatExpression($db->quote('//'), 'path').", {$db->quote('//' . $node->path)}, {$db->quote($path)})"
                .' WHERE path LIKE '.$db->quote($node->path.'//%')
            );
        }

        $node->path = $path;

        // Set priority
        if (!$id) {
            $node->priority = 1 + $db->createQueryBuilder()
                    ->select($db->getDatabasePlatform()->getMaxExpression('priority'))
                    ->from('@system_node')
                    ->where(['parent_id' => $node->parent_id])
                    ->executeQuery()
                    ->fetchOne();
        }
    }

    #[ORM\Deleting]
    public static function deleting(EntityEvent $event, Node $node): void
    {
        $em = $event->getEntityManager();

        // Update children's parents
        foreach ($em->getRepository(Node::class)->where('parent_id = ?', [$node->id])->get() as $child) {
            $child->parent_id = $node->parent_id;
            $em->save($child);
        }
    }
}
