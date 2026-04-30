<?php

declare(strict_types=1);

namespace Pagekit\Site\Event;

use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Module\Module;
use Pagekit\Site\Model\Node;

class NodesListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly Module $site,
        private readonly mixed $routes,
    ) {
    }

    /**
     * Registers node routes
     */
    public function onRequest(): void
    {
        $frontpage = $this->site->config('frontpage');
        $nodes = Node::findAll(true);

        uasort($nodes, function ($a, $b) {
            return strcmp(substr_count($a->path, '/'), substr_count($b->path, '/')) * -1;
        });

        foreach ($nodes as $node) {
            if ($node->status !== 1 || !$type = $this->site->getType($node->type)) {
                continue;
            }

            $type = array_replace(['alias' => '', 'redirect' => '', 'controller' => ''], $type);
            $type['defaults'] = array_merge(isset($type['defaults']) ? $type['defaults'] : [], $node->get('defaults', []), ['_node' => $node->id]);
            $type['path'] = $node->path;

            $route = null;
            if ($node->get('alias')) {
                $this->routes->alias($node->path, $node->link, $type['defaults']);
            } elseif ($node->get('redirect')) {
                $this->routes->redirect($node->path, $node->get('redirect'), $type['defaults']);
            } elseif ($type['controller']) {
                $this->routes->add($type);
            }

            if (!$frontpage && isset($type['frontpage']) && $type['frontpage']) {
                $frontpage = $node->id;
            }

        }

        if ($frontpage && isset($nodes[$frontpage])) {
            $this->routes->alias('/', $nodes[$frontpage]->link);
        } else {
            $this->routes->get('/', function () {
                return __('No Frontpage assigned.');
            });
        }
    }

    public function onNodeInit($event, $node): void
    {
        if ('link' === $node->type && $node->get('redirect')) {
            $node->link = $node->path;
        }
    }

    public function onRoleDelete($event, $role): void
    {
        Node::removeRole($role);
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(): array
    {
        return [
            'request' => ['onRequest', 110],
            'model.node.init' => 'onNodeInit',
            'model.role.deleted' => 'onRoleDelete',
        ];
    }
}
