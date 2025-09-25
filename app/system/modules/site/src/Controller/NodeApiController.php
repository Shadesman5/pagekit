<?php

namespace Pagekit\Site\Controller;

use Pagekit\Application as App;
use Pagekit\Site\Model\Node;

/**
 * @Access("site: manage site")
 */
class NodeApiController
{
    /**
     * @Route("/", methods="GET")
     */
    public function indexAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $menu = App::request()->query->get('menu', false);
        
        $query = Node::query();

        if (is_string($menu)) {
            $query->where(['menu' => $menu]);
        }

        return array_values($query->get());
    }

    /**
     * @Route("/{id}", methods="GET", requirements={"id"="\d+"})
     */
    public function getAction($id): Node
    {
        if (!$node = Node::find($id)) {
            App::abort(404, __('Node not found.'));
        }

        return $node;
    }

    /**
     * @Route("/", methods="POST")
     * @Route("/{id}", methods="POST", requirements={"id"="\d+"})
     */
    public function saveAction($id = 0, $data = null): array
    {
        // Get parameters from request if not provided (Symfony 6.4 compatibility)
        if ($data === null) {
            $request = App::request();
            
            // Get node data from POST or JSON body
            $data = $request->request->all()['node'] ?? [];
            if (empty($data) && $request->getContent()) {
                $json = json_decode($request->getContent(), true);
                $data = $json['node'] ?? $json ?? [];
            }
        }
        
        // Get id from route or data
        if (!$id && isset($data['id'])) {
            $id = (int) $data['id'];
        }
        
        if (!$node = Node::find($id)) {
            $node = Node::create();
            unset($data['id']);
        }

        // Generate slug from title if not provided
        $slug = isset($data['slug']) ? $data['slug'] : '';
        $title = isset($data['title']) ? $data['title'] : '';
        
        if (!$data['slug'] = App::filter($slug ?: $title, 'slugify')) {
            App::abort(400, __('Invalid slug.'));
        }

        $node->save($data);

        return ['message' => 'success', 'node' => $node];
    }

    /**
     * @Route("/{id}", methods="DELETE", requirements={"id"="\d+"})
     */
    public function deleteAction($id = 0): array
    {
        // Get id from route if not provided (Symfony 6.4 compatibility)
        if (!$id) {
            $id = (int) App::request()->get('id', 0);
        }
        
        if ($node = Node::find($id)) {

            if ($type = App::module('system/site')->getType($node->type) and isset($type['protected']) and $type['protected']) {
                App::abort(400, __('Invalid type.'));
            }

            $node->delete();
        }

        return ['message' => 'success'];
    }

    /**
     * @Route("/bulk", methods="POST")
     */
    public function bulkSaveAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
        // Get nodes data from POST or JSON body
        $nodes = $request->request->all()['nodes'] ?? [];
        if (empty($nodes) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $nodes = $json['nodes'] ?? [];
        }
        
        foreach ($nodes as $data) {
            // Call saveAction with each node's id and data
            $id = isset($data['id']) ? $data['id'] : 0;
            $this->saveAction($id, $data);
        }

        return ['message' => 'success'];
    }

    /**
     * @Route("/bulk", methods="DELETE")
     */
    public function bulkDeleteAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
        // Get ids from POST/DELETE body or JSON
        $ids = $request->request->all()['ids'] ?? [];
        if (empty($ids) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $ids = $json['ids'] ?? [];
        }
        
        foreach (array_filter($ids) as $id) {
            $this->deleteAction($id);
        }

        return ['message' => 'success'];
    }

    /**
     * @Route("/updateOrder", methods="POST")
     */
    public function updateOrderAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
        $menu = $request->request->get('menu', '');
        $nodes = $request->request->all()['nodes'] ?? [];
        
        if ($request->getContent()) {
            $json = json_decode($request->getContent(), true);
            if ($json) {
                $menu = $json['menu'] ?? $menu;
                $nodes = $json['nodes'] ?? $nodes;
            }
        }
        
        foreach ($nodes as $data) {

            if ($node = Node::find($data['id'])) {

                $node->priority  = $data['order'];
                $node->menu      = $menu;
                $node->parent_id = $data['parent_id'] ?: 0;

                $node->save();
            }
        }

        return ['message' => 'success'];
    }

    /**
     * @Route("/frontpage", methods="POST")
     */
    public function frontpageAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
        $id = (int) $request->request->get('id', 0);
        if (!$id && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $id = (int) ($json['id'] ?? 0);
        }
        
        if (!$node = Node::find($id) or !$type = App::module('system/site')->getType($node->type)) {
            App::abort(404, __('Node not found.'));
        }

        if (isset($type['frontpage']) and !$type['frontpage']) {
            App::abort(400, __('Invalid node type.'));
        }

        App::config('system/site')->set('frontpage', $id);
        return ['message' => 'success'];
    }
}
