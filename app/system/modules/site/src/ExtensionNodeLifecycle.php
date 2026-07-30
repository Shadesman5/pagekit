<?php

declare(strict_types=1);

namespace Pagekit\Site;

use Pagekit\Config\Config;
use Pagekit\Site\Model\Node;
use Pagekit\Site\Model\NodeRepository;

/**
 * Snapshots and restores site nodes owned by an extension across disable/enable.
 *
 * Disable stores menu/parent/neighbor anchors then parks the node unpublished
 * under "Not Linked" (Trash stays Trash). Enable restores placement best-effort
 * but leaves the node unpublished. Uninstall soft-deletes into Trash while
 * keeping the snapshot for a later reinstall.
 */
final class ExtensionNodeLifecycle
{
    public const RESTORE_KEY = '_extension_restore';

    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly Config $siteConfig,
    ) {
    }

    /**
     * @param array<int, string> $types
     */
    public function disable(array $types): void
    {
        if ($types === []) {
            return;
        }

        $frontpageId = (int) $this->siteConfig->get('frontpage');
        $all = array_values($this->nodes->findAll());

        foreach ($this->nodesOfTypes($types) as $node) {
            $this->nodes->save($this->park($node, $all));
        }

        if ($frontpageId && ($frontpage = $this->nodes->find($frontpageId)) && in_array($frontpage->type, $types, true)) {
            $this->siteConfig->set('frontpage', 0);
        }

        $this->nodes->clearCache();
    }

    /**
     * @param array<int, string> $types
     */
    public function enable(array $types): void
    {
        if ($types === []) {
            return;
        }

        foreach ($this->nodesOfTypes($types) as $node) {
            $snapshot = $node->get(self::RESTORE_KEY);
            if (!is_array($snapshot)) {
                continue;
            }

            $this->restorePlacement($node, $snapshot);
            $node->status = 0;
            $data = $node->data ?? [];
            unset($data[self::RESTORE_KEY]);
            $node->data = $data === [] ? null : $data;

            $this->nodes->save($node);
        }

        $this->nodes->clearCache();
    }

    /**
     * @param array<int, string> $types
     */
    public function uninstall(array $types): void
    {
        if ($types === []) {
            return;
        }

        $frontpageId = (int) $this->siteConfig->get('frontpage');
        if ($frontpageId && ($frontpage = $this->nodes->find($frontpageId)) && in_array($frontpage->type, $types, true)) {
            $this->siteConfig->set('frontpage', 0);
        }

        $all = array_values($this->nodes->findAll());
        foreach ($this->nodesOfTypes($types) as $node) {
            if (!is_array($node->get(self::RESTORE_KEY))) {
                $this->ensureSnapshot($node, $all);
            }

            $node->status = 0;
            $node->menu = 'trash';
            $node->parent_id = 0;
            $this->nodes->save($node);
        }

        $this->nodes->clearCache();
    }

    /**
     * @param  array<int, string> $types
     * @return list<Node>
     */
    private function nodesOfTypes(array $types): array
    {
        return array_values(array_filter(
            $this->nodes->findAll(),
            static fn (Node $node) => in_array($node->type, $types, true)
        ));
    }

    /**
     * @param list<Node> $all
     */
    private function park(Node $node, array $all): Node
    {
        $this->ensureSnapshot($node, $all);

        $node->status = 0;
        // Already in Trash: keep it there so disable does not resurrect trash items.
        if ($node->menu !== 'trash') {
            $node->menu = '';
            $node->parent_id = 0;
        }

        return $node;
    }

    /**
     * @param list<Node> $all
     */
    private function ensureSnapshot(Node $node, array $all): void
    {
        if (is_array($node->get(self::RESTORE_KEY))) {
            return;
        }

        [$prevId, $nextId] = $this->neighbors($node, $all);

        $node->set(self::RESTORE_KEY, [
            'menu' => (string) ($node->menu ?? ''),
            'parent_id' => (int) ($node->parent_id ?? 0),
            'prev_id' => $prevId,
            'next_id' => $nextId,
        ]);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function restorePlacement(Node $node, array $snapshot): void
    {
        $menu = is_string($snapshot['menu'] ?? null) ? $snapshot['menu'] : '';
        if ($menu === '' || $menu === 'trash') {
            return;
        }

        $parentId = (int) ($snapshot['parent_id'] ?? 0);
        if ($parentId !== 0) {
            $parent = $this->nodes->find($parentId);
            if ($parent === null || $parent->menu !== $menu) {
                $parentId = 0;
            }
        }

        $priority = $this->resolvePriority(
            $menu,
            $parentId,
            isset($snapshot['prev_id']) ? (int) $snapshot['prev_id'] : null,
            isset($snapshot['next_id']) ? (int) $snapshot['next_id'] : null,
            (int) $node->id
        );

        $node->menu = $menu;
        $node->parent_id = $parentId;
        $node->priority = $priority;
    }

    /**
     * Best-effort insert: after/before a surviving neighbor, else append.
     */
    private function resolvePriority(string $menu, int $parentId, ?int $prevId, ?int $nextId, int $selfId): int
    {
        $prev = $prevId ? $this->nodes->find($prevId) : null;
        $next = $nextId ? $this->nodes->find($nextId) : null;

        $prevOk = $prev && $prev->menu === $menu && (int) ($prev->parent_id ?? 0) === $parentId;
        $nextOk = $next && $next->menu === $menu && (int) ($next->parent_id ?? 0) === $parentId;

        if ($prevOk) {
            $this->shiftPriorities($menu, $parentId, (int) $prev->priority + 1, $selfId);

            return (int) $prev->priority + 1;
        }

        if ($nextOk) {
            $this->shiftPriorities($menu, $parentId, (int) $next->priority, $selfId);

            return (int) $next->priority;
        }

        $max = 0;
        foreach ($this->nodes->findAll() as $sibling) {
            if ($sibling->id === $selfId || $sibling->menu !== $menu || (int) ($sibling->parent_id ?? 0) !== $parentId) {
                continue;
            }
            $max = max($max, (int) $sibling->priority);
        }

        return $max + 1;
    }

    private function shiftPriorities(string $menu, int $parentId, int $fromPriority, int $selfId): void
    {
        foreach ($this->nodes->findAll() as $sibling) {
            if ($sibling->id === $selfId || $sibling->menu !== $menu || (int) ($sibling->parent_id ?? 0) !== $parentId) {
                continue;
            }
            if ((int) $sibling->priority < $fromPriority) {
                continue;
            }
            $sibling->priority = (int) $sibling->priority + 1;
            $this->nodes->save($sibling);
        }
    }

    /**
     * @param  list<Node> $all
     * @return array{0: ?int, 1: ?int}
     */
    private function neighbors(Node $node, array $all): array
    {
        $menu = (string) ($node->menu ?? '');
        if ($menu === '' || $menu === 'trash') {
            return [null, null];
        }

        $siblings = array_values(array_filter(
            $all,
            static fn (Node $other) => $other->menu === $menu
                && (int) ($other->parent_id ?? 0) === (int) ($node->parent_id ?? 0)
        ));

        usort($siblings, static fn (Node $a, Node $b) => $a->priority <=> $b->priority);

        $index = null;
        foreach ($siblings as $i => $sibling) {
            if ($sibling->id === $node->id) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return [null, null];
        }

        $prev = $siblings[$index - 1] ?? null;
        $next = $siblings[$index + 1] ?? null;

        return [$prev?->id, $next?->id];
    }
}
