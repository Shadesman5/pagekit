<?php

declare(strict_types=1);

namespace Pagekit\Site\Controller;

use function Pagekit\__;

use Pagekit\Application\UrlProvider;
use Pagekit\Database\ORM\Repository;
use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;
use Pagekit\Routing\Router;
use Pagekit\Site\MenuManager;
use Pagekit\Site\Model\NodeRepository;
use Pagekit\Site\SiteModule;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\Role;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class NodeController
{
    /**
     * @param Repository<Role> $roleRepository
     */
    public function __construct(
        private readonly SiteModule $site,
        private readonly MenuManager $menu,
        private readonly UrlProvider $url,
        private readonly Router $router,
        private readonly NodeRepository $nodeRepository,
        private readonly Repository $roleRepository,
    ) {
    }

    /**
     * @return array<string, mixed>|RedirectResponse
     */
    #[Route('site/page', name: 'page')]
    #[Access('site: manage site', admin: true)]
    public function indexAction(): array|RedirectResponse
    {
        if ($test = $this->nodeRepository->fixOrphanedNodes()) {
            return $this->router->redirect('@site/page');
        }

        return [
            '$view' => [
                'title' => __('Pages'),
                'name' => 'system/site/admin/index.php',
            ],
            '$data' => [
                'config' => [
                    'menus' => $this->menu->getPositions(),
                ],
                'types' => array_values($this->site->getTypes() ?? []),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Route('site/page/edit', name: 'page/edit')]
    #[Access('site: manage site', admin: true)]
    #[Request(['id' => 'string', 'menu' => 'string'])]
    public function editAction(string $id = '', string $menu = ''): array
    {
        if (is_numeric($id)) {

            if (!$id or !$node = $this->nodeRepository->find($id)) {
                throw new NotFoundHttpException('Node not found.');
            }

        } else {
            $node = $this->nodeRepository->create(['type' => $id]);

            if ($menu && !($this->menu)($menu)) {
                throw new NotFoundHttpException('Menu not found.');
            }

            $node->menu = $menu;
        }

        if (!$type = $this->site->getType($node->type ?? '')) {
            throw new NotFoundHttpException('Type not found.');
        }

        return [
            '$view' => [
                'title' => __('Pages'),
                'name' => 'system/site/admin/edit.php',
            ],
            '$data' => [
                'node' => $node,
                'type' => $type,
                'roles' => array_values($this->roleRepository->findAll()),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Route('site/settings')]
    #[Access('system: access settings', admin: true)]
    public function settingsAction(): array
    {
        return [
            '$view' => [
                'title' => __('Settings'),
                'name' => 'system/site/admin/settings.php',
            ],
            '$data' => [
                'config' => $this->site->config(['title', 'description', 'maintenance.', 'meta.', 'logo', 'icons.', 'code.', 'view.']),
            ],
        ];
    }

    /**
     * @return array{message: string, url: string}
     */
    #[Route('api/site/link', name: 'api/link')]
    #[Request(['link' => 'string'])]
    #[Access('site: manage site')]
    public function linkAction(string $link): array
    {
        return ['message' => 'success', 'url' => ($this->url)($link, [], 'base') ?: $link];
    }
}
