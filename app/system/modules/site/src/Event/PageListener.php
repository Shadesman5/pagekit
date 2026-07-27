<?php

declare(strict_types=1);

namespace Pagekit\Site\Event;

use Pagekit\Database\ORM\Repository;
use Pagekit\Event\EventInterface;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Routing\Route;
use Pagekit\Site\Model\Node;
use Pagekit\Site\Model\Page;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouteCollection;

class PageListener implements EventSubscriberInterface
{
    /**
     * @param Repository<Page> $pages
     */
    public function __construct(
        private readonly Repository $pages,
    ) {
    }

    public function onNodeSave(EventInterface $event, Request $request): void
    {
        if (null === $node = $request->get('node')
            or null === $data = $request->get('page')
            or 'page' !== @$node['type']
        ) {
            return;
        }

        $page = $this->getPage(@$node['id']);
        $this->pages->save($page, $data);

        $node['data']['defaults'] = ['id' => $page->id];
        $node['link'] = '@page/'.$page->id;

        $request->request->set('node', $node);
    }

    public function onNodeDeleted(EventInterface $event, Node $node): void
    {
        if ('page' !== $node->type) {
            return;
        }

        $page = $this->getPage($node->get('defaults.id', 0));

        if ($page->id) {
            $this->pages->delete($page);
        }
    }

    public function onRouteConfigure(EventInterface $event, Route $route, RouteCollection $routes): void
    {
        if ($route->getName() === '@page') {
            $routes->remove('@page');
            $route->setName('@page/'.$route->getDefault('id'));
            $routes->add($route->getName(), $route);
            // Custom Symfony 4
            $route->setOption('utf8', true);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, string>
     */
    public function subscribe(): array
    {
        return [
            'before@site/api/node/save' => 'onNodeSave',
            'before@site/api/node/save_1' => 'onNodeSave',
            'model.node.deleted' => 'onNodeDeleted',
            'route.configure' => 'onRouteConfigure',
        ];
    }

    /**
     * Find page entity by node.
     *
     * @param  int $id
     */
    protected function getPage($id): Page
    {
        if (!$id or !$page = $this->pages->find($id)) {
            $page = $this->pages->create();
        }

        return $page;
    }
}
