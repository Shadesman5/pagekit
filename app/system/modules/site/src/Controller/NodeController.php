<?php

declare(strict_types=1);

namespace Pagekit\Site\Controller;

use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Pagekit\Site\Model\Node;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\Role;
use function Pagekit\__;

class NodeController
{
    protected mixed $site;

    public function __construct(
        private readonly mixed $module,
        private readonly mixed $menu,
        private readonly mixed $url,
        private readonly mixed $router,
    ) {
        $this->site = $this->module->get('system/site');
    }

    #[Route('site/page', name: 'page')]
    #[Access('site: manage site', admin: true)]
    public function indexAction()
    {
        if ($test = Node::fixOrphanedNodes()) {
            return $this->router->redirect('@site/page');
        }

        return [
            '$view' => [
                'title' => __('Pages'),
                'name'  => 'system/site/admin/index.php'
            ],
            '$data' => [
                'config' => [
                    'menus' => $this->menu->getPositions()
                ],
                'types' => array_values($this->site->getTypes())
            ]
        ];
    }

    #[Route('site/page/edit', name: 'page/edit')]
    #[Access('site: manage site', admin: true)]
    #[Request(['id' => 'string', 'menu' => 'string'])]
    public function editAction($id = '', $menu = ''): array
    {
        if (is_numeric($id)) {

            if (!$id or !$node = Node::find($id)) {
                throw new NotFoundHttpException('Node not found.');
            }

        } else {
            $node = Node::create(['type' => $id]);

            if ($menu && !($this->menu)($menu)) {
                throw new NotFoundHttpException('Menu not found.');
            }

            $node->menu = $menu;
        }

        if (!$type = $this->site->getType($node->type)) {
            throw new NotFoundHttpException('Type not found.');
        }

        return [
            '$view' => [
                'title' => __('Pages'),
                'name'  => 'system/site/admin/edit.php'
            ],
            '$data' => [
                'node' => $node,
                'type' => $type,
                'roles' => array_values(Role::findAll())
            ]
        ];
    }

    #[Route('site/settings')]
    #[Access('system: access settings', admin: true)]
    public function settingsAction(): array
    {
        return [
            '$view' => [
                'title' => __('Settings'),
                'name'  => 'system/site/admin/settings.php'
            ],
            '$data' => [
                'config' => $this->site->config(['title', 'description', 'maintenance.', 'meta.', 'logo', 'icons.', 'code.', 'view.'])
            ]
        ];
    }

    #[Route('api/site/link', name: 'api/link')]
    #[Request(['link' => 'string'])]
    #[Access('site: manage site')]
    public function linkAction($link): array
    {
        return ['message' => 'success', 'url' => ($this->url)($link, [], 'base') ?: $link];
    }
}
