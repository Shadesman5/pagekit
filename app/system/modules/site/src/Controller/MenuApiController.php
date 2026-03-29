<?php

declare(strict_types=1);

namespace Pagekit\Site\Controller;

use function Pagekit\__;

use Pagekit\Kernel\Exception\ConflictException;
use Pagekit\Routing\Attribute\Route;
use Pagekit\Site\Model\Node;
use Pagekit\User\Attribute\Access;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[Access('site: manage site')]
class MenuApiController
{
    private readonly mixed $siteConfig;

    public function __construct(
        private readonly mixed $config,
        private readonly mixed $menu,
        private readonly mixed $request,
        private readonly mixed $filter,
    ) {
        $this->siteConfig = ($this->config)('system/site');
    }

    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        $menus = $this->menu->all();

        $menus['trash'] = ['id' => 'trash', 'label' => __('Trash'), 'fixed' => true];

        foreach ($menus as &$menu) {
            $menu['count'] = Node::where(['menu' => $menu['id']])->count();
        }

        if (!$menus['trash']['count']) {
            unset($menus['trash']);
        }

        return array_values($menus);
    }

    #[Route('/', methods: ['POST'])]
    public function saveAction(): array
    {
        $menu = $this->request->request->all()['menu'] ?? [];
        if (empty($menu) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $menu = $json['menu'] ?? [];
        }

        $oldId = isset($menu['id']) ? trim($menu['id']) : null;
        $label = isset($menu['label']) ? trim($menu['label']) : '';

        if (!$id = ($this->filter)($label, 'slugify')) {
            throw new BadRequestHttpException(__('Invalid id.'));
        }

        if ($id != $oldId) {

            if ($this->siteConfig->has('menus.'.$id)) {
                throw new ConflictException(__('Duplicate Menu Id.'));
            }

            $this->siteConfig->remove('menus.'.$oldId);

            Node::where(['menu = :old'], ['old' => $oldId])->update(['menu' => $id]);
        }

        $this->siteConfig->merge(['menus' => [$id => compact('id', 'label')]]);

        if (isset($menu['positions'])) {
            $this->menu->assign($id, $menu['positions']);
        }

        return ['message' => 'success', 'menu' => $menu];
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function deleteAction($id = null): array
    {
        if (!$id) {
            $id = $this->request->attributes->get('id');
            if (!$id) {
                $id = $this->request->get('id');
            }
        }

        if (!$id) {
            throw new \Exception('Menu ID is required');
        }

        $this->siteConfig->remove('menus.'.$id);
        Node::where(['menu = :id'], ['id' => $id])->update(['menu' => 'trash', 'status' => 0]);

        return ['message' => 'success'];
    }
}
