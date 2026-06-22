<?php

declare(strict_types=1);

namespace Pagekit\Site\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Database\ORM\ModelTrait;
use Pagekit\Event\EventInterface;

trait NodeModelTrait
{
    use ModelTrait {
        find as modelFind;
    }

    /** @var array<int, Node>|null */
    protected static ?array $nodes = null;

    /**
     * Retrieves an entity by its identifier.
     */
    public static function find(mixed $id, bool $cached = false): ?Node
    {
        if (!$cached || !isset(self::$nodes[$id])) {
            self::$nodes[$id] = self::modelFind($id);
        }

        return self::$nodes[$id];
    }

    /**
     * Retrieves all entities.
     *
     * @return array<int, Node>
     */
    public static function findAll(bool $cached = false): array
    {
        if (!$cached || null === self::$nodes) {
            $nodes = [];
            foreach (self::query()->orderBy('priority')->get() as $key => $entity) {
                if (!$entity instanceof Node) {
                    throw new \LogicException(sprintf(
                        'QueryBuilder::get() returned %s, expected %s',
                        get_class($entity),
                        Node::class
                    ));
                }
                $nodes[(int) $key] = $entity;
            }
            self::$nodes = $nodes;
        }

        return self::$nodes;
    }

    /**
     * Retrieves all nodes by menu.
     *
     * @return array<int, Node>
     */
    public static function findByMenu(string $menu, bool $cached = false): array
    {
        return array_filter(self::findAll($cached), fn ($node) => $menu == $node->menu);
    }

    /**
     * Sets parent_id of orphaned nodes to zero.
     *
     * @return int
     */
    public static function fixOrphanedNodes(): int
    {
        if ($orphaned = self::getConnection()
            ->createQueryBuilder()
            ->from('@system_node n')
            ->leftJoin('@system_node c', 'c.id = n.parent_id AND c.menu = n.menu')
            ->where(['n.parent_id <> 0', 'c.id IS NULL'])
            ->execute('n.id')->fetchFirstColumn()
        ) {
            return self::query()
                ->whereIn('id', $orphaned)
                ->update(['parent_id' => 0]);
        }

        return 0;
    }

    #[ORM\Saving]
    public static function saving(EventInterface $event, Node $node): void
    {
        $db = self::getConnection();

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
        while (self::where(['slug = ?', 'parent_id= ?'], [$node->slug, $node->parent_id])->where(function ($query) use ($id) {
            if ($id) {
                $query->where('id <> ?', [$id]);
            }
        })->first()) {
            $node->slug = preg_replace('/-\d+$/', '', $node->slug).'-'.$i++;
        }

        // Update own path
        $path = '/'.$node->slug;
        if ($node->parent_id && $parent = Node::find($node->parent_id) and $parent->menu == $node->menu) {
            $path = $parent->path.$path;
        } else {
            // set Parent to 0, if old parent is not found
            $node->parent_id = 0;
        }

        // Update children's paths
        if ($id && $path != $node->path) {
            $db->executeStatement(
                'UPDATE '.self::getMetadata()->getTable()
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
                    ->execute()
                    ->fetchOne();
        }
    }

    #[ORM\Deleting]
    public static function deleting(EventInterface $event, Node $node): void
    {
        // Update children's parents
        foreach (self::where('parent_id = ?', [$node->id])->get() as $child) {
            if (!$child instanceof Node) {
                throw new \LogicException(sprintf(
                    'QueryBuilder::get() returned %s, expected %s',
                    get_class($child),
                    Node::class
                ));
            }
            $child->parent_id = $node->parent_id;
            $child->save();
        }
    }
}
