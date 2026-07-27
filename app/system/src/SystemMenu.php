<?php

declare(strict_types=1);

namespace Pagekit\System;

use Pagekit\Application\UrlProvider;
use Pagekit\User\Model\User;
use Pagekit\Util\ArrObject;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements \IteratorAggregate<string, ArrObject>
 */
class SystemMenu implements \IteratorAggregate, \JsonSerializable
{
    /** @var array<string, ArrObject> */
    protected array $items = [];

    public function __construct(
        private readonly User $user,
        private readonly Request $request,
        private readonly UrlProvider $url,
    ) {
    }

    /**
     * Gets all menu items.
     *
     * @return array<string, ArrObject>
     */
    public function getItems(): array
    {
        foreach ($this->items as $item) {

            if ($item['active'] !== true) {
                continue;
            }

            while ($item = $this->getItem((string) $item['parent'])) {
                $item['active'] = true;
            }
        }

        return $this->items;
    }

    /**
     * Gets a menu item.
     */
    public function getItem(string $id): ?ArrObject
    {
        return isset($this->items[$id]) ? $this->items[$id] : null;
    }

    /**
     * Adds a menu item.
     *
     * @param array<string, mixed> $item
     */
    public function addItem(string $id, array $item): void
    {
        $meta = $this->user->get('admin.menu', []);
        $route = $this->request->attributes->get('_route');

        $item = new ArrObject($item, [
            'id' => $id,
            'label' => $id,
            'parent' => 'root',
            'priority' => 0,
        ]);

        if (!$this->user->hasAccess($item['access'])) {
            return;
        }

        if (isset($meta[$id])) {
            $item['priority'] = $meta[$id];
        }

        if ($item['icon']) {
            $item['icon'] = $this->url->getStatic($item['icon']);
        }

        $item['active'] = (bool) preg_match('#^'.str_replace('*', '.*', $item['active'] ?: $item['url']).'$#', $route);
        $item['url'] = ($this->url)($item['url']);

        $this->items[$id] = $item;
    }

    /**
     * Implements the IteratorAggregate.
     *
     * @return \ArrayIterator<string, ArrObject>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->getItems());
    }

    /**
     * Implements JsonSerializable interface.
     *
     * @return array<string, ArrObject>
     */
    public function jsonSerialize(): array
    {
        return $this->getItems();
    }
}
