<?php

declare(strict_types=1);

namespace Pagekit\Site\Controller;

use Pagekit\Routing\Attribute\Route;
use Pagekit\Site\Model\Node;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\User\Attribute\Access;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use function Pagekit\__;

/**
 * API Controller for Node management.
 */
#[Access('site: manage site')]
class NodeApiController
{
    use ValidatesRequestTrait;

    public function __construct(
        private readonly mixed $request,
        private readonly mixed $filter,
        private readonly mixed $module,
        private readonly mixed $config,
    ) {}

    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        $menu = $this->request->query->get('menu', false);

        $query = Node::query();

        if (is_string($menu)) {
            $query->where(['menu' => $menu]);
        }

        return array_values($query->get());
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getAction(int $id): Node
    {
        if (!$node = Node::find($id)) {
            throw new NotFoundHttpException(__('Node not found.'));
        }

        return $node;
    }

    /**
     * Save a node (create or update).
     */
    #[Route('/', methods: ['POST'])]
    #[Route('/{id}', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function saveAction(int $id = 0, ?array $data = null): array
    {
        if ($data === null) {
            $request = $this->request;

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

        // Generate slug from title if not provided (business logic, not validation)
        $slug = isset($data['slug']) ? $data['slug'] : '';
        $title = isset($data['title']) ? $data['title'] : '';

        // Apply slug filter - this is business logic that generates a valid slug
        $data['slug'] = ($this->filter)($slug ?: $title, 'slugify');

        // Assign data to entity for validation (without saving yet)
        foreach ($data as $key => $value) {
            if (property_exists($node, $key)) {
                $node->$key = $value;
            }
        }

        // Validate using Symfony Validator
        $this->validateOrFail($node);

        $node->save($data);

        return ['message' => 'success', 'node' => $node];
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteAction(int $id = 0): array
    {
        // Get id from route if not provided (Symfony 6.4 compatibility)
        if (!$id) {
            $id = (int) $this->request->get('id', 0);
        }

        if ($node = Node::find($id)) {

            // Business logic: Check if node type is protected (NOT entity validation)
            if ($type = $this->module->get('system/site')->getType($node->type) and isset($type['protected']) and $type['protected']) {
                throw new BadRequestHttpException(__('Invalid type.'));
            }

            $node->delete();
        }

        return ['message' => 'success'];
    }

    #[Route('/bulk', methods: ['POST'])]
    public function bulkSaveAction(): array
    {
        $request = $this->request;

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

    #[Route('/bulk', methods: ['DELETE'])]
    public function bulkDeleteAction(): array
    {
        $request = $this->request;

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

    #[Route('/updateOrder', methods: ['POST'])]
    public function updateOrderAction(): array
    {
        $request = $this->request;

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

    #[Route('/frontpage', methods: ['POST'])]
    public function frontpageAction(): array
    {
        $request = $this->request;

        $id = (int) $request->request->get('id', 0);
        if (!$id && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $id = (int) ($json['id'] ?? 0);
        }

        if (!$node = Node::find($id) or !$type = $this->module->get('system/site')->getType($node->type)) {
            throw new NotFoundHttpException(__('Node not found.'));
        }

        if (isset($type['frontpage']) and !$type['frontpage']) {
            throw new BadRequestHttpException(__('Invalid node type.'));
        }

        ($this->config)('system/site')->set('frontpage', $id);
        return ['message' => 'success'];
    }
}
