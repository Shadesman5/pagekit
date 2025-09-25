<?php

namespace Pagekit\Site\Controller;

use Pagekit\Application as App;
use Pagekit\Config\Config;
use Pagekit\Kernel\Exception\ConflictException;
use Pagekit\Site\Model\Node;
use function Pagekit\__;

/**
 * @Access("site: manage site")
 */
class MenuApiController
{
    protected $config;

    public function __construct()
    {
        $this->config = App::config('system/site');
    }

    /**
     * @Route("/", methods="GET")
     */
    public function indexAction(): array
    {
        $menus = App::menu()->all();

        $menus['trash'] = ['id' => 'trash', 'label' => __('Trash'), 'fixed' => true];

        foreach ($menus as &$menu) {
            $menu['count'] = Node::where(['menu' => $menu['id']])->count();
        }

        if (!$menus['trash']['count']) {
            unset($menus['trash']);
        }

        return array_values($menus);
    }

    /**
     * @Route("/", methods="POST")
     */
    public function saveAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
        $menu = $request->request->all()['menu'] ?? [];
        if (empty($menu) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $menu = $json['menu'] ?? [];
        }
        
        $oldId = isset($menu['id']) ? trim($menu['id']) : null;
        $label = isset($menu['label']) ? trim($menu['label']) : '';

        if (!$id = App::filter($label, 'slugify')) {
            App::abort(400, __('Invalid id.'));
        }

        if ($id != $oldId) {

            if ($this->config->has('menus.'.$id)) {
                throw new ConflictException(__('Duplicate Menu Id.'));
            }

            $this->config->remove('menus.'.$oldId);

            Node::where(['menu = :old'], ['old' => $oldId])->update(['menu' => $id]);
        }

        $this->config->merge(['menus' => [$id => compact('id', 'label')]]);

        // Assign positions if provided
        if (isset($menu['positions'])) {
            App::menu()->assign($id, $menu['positions']);
        }

        return ['message' => 'success', 'menu' => $menu];
    }

    /**
     * @Route("/{id}", methods="DELETE")
     */
    public function deleteAction($id = null): array
    {
        // Get id from route if not provided (Symfony 6.4 compatibility)
        if (!$id) {
            $id = App::request()->attributes->get('id');
            if (!$id) {
                $id = App::request()->get('id');
            }
        }
        
        if (!$id) {
            throw new \Exception('Menu ID is required');
        }
        
        App::config('system/site')->remove('menus.'.$id);
        Node::where(['menu = :id'], ['id' => $id])->update(['menu' => 'trash', 'status' => 0]);

        return ['message' => 'success'];
    }
}
