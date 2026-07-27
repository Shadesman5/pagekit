<?php

declare(strict_types=1);

namespace Pagekit\Site\Model;

use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Repository;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Data-mapper repository for {@see Node}, carrying the request-scoped caching
 * that used to live on the static `NodeModelTrait`.
 *
 * The injected {@see CacheItemPoolInterface} (wired to an `ArrayAdapter(0, false)`
 * by the composition root) preserves the shared-object semantics of the former
 * static `$nodes` cache: with `storeSerialized: false` the very same {@see Node}
 * instances are handed back, so `NodesListener` / `MenuHelper` / the `node`
 * service keep mutating one shared node graph.
 *
 * @extends Repository<Node>
 */
class NodeRepository extends Repository
{
    private const CACHE_KEY = 'nodes';

    public function __construct(EntityManager $em, private readonly CacheItemPoolInterface $cache)
    {
        parent::__construct($em, $em->getMetadata(Node::class));
    }

    /**
     * Retrieves a node by its identifier, optionally serving it from (and
     * populating) the request cache.
     */
    public function find(int|string $id, bool $cached = false): ?Node
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        /** @var array<int, Node> $nodes */
        $nodes = $item->isHit() ? $item->get() : [];

        if ($cached && isset($nodes[$id])) {
            return $nodes[$id];
        }

        $node = parent::find($id);

        if ($node !== null) {
            $nodes[(int) $id] = $node;
            $item->set($nodes);
            $this->cache->save($item);
        }

        return $node;
    }

    /**
     * Retrieves all nodes ordered by priority, optionally serving the memoized
     * full set from the request cache.
     *
     * @return array<int, Node>
     */
    public function findAll(bool $cached = false): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);

        if ($cached && $item->isHit()) {
            /** @var array<int, Node> $cachedNodes */
            $cachedNodes = $item->get();

            return $cachedNodes;
        }

        $nodes = [];
        foreach ($this->query()->orderBy('priority')->get() as $key => $entity) {
            $nodes[(int) $key] = $entity;
        }

        $item->set($nodes);
        $this->cache->save($item);

        return $nodes;
    }

    /**
     * Retrieves all nodes assigned to a menu, optionally from the request cache.
     *
     * @return array<int, Node>
     */
    public function findByMenu(string $menu, bool $cached = false): array
    {
        return array_filter($this->findAll($cached), fn (Node $node) => $menu == $node->menu);
    }

    /**
     * Sets parent_id of orphaned nodes to zero.
     */
    public function fixOrphanedNodes(): int
    {
        $db = $this->em->getConnection();

        if ($orphaned = $db
            ->createQueryBuilder()
            ->from('@system_node n')
            ->leftJoin('@system_node c', 'c.id = n.parent_id AND c.menu = n.menu')
            ->where(['n.parent_id <> 0', 'c.id IS NULL'])
            ->select('n.id')->executeQuery()->fetchFirstColumn()
        ) {
            return $this->query()
                ->whereIn('id', $orphaned)
                ->update(['parent_id' => 0]);
        }

        return 0;
    }
}
