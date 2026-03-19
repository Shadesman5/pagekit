<?php

declare(strict_types=1);

namespace Pagekit\User\Controller;

use Pagekit\Routing\Attribute\Route;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use function Pagekit\__;

/**
 * API Controller for Role management.
 */
#[Access('user: manage user permissions')]
class RoleApiController
{
    use ValidatesRequestTrait;

    public function __construct(
        private readonly mixed $request,
    ) {}

    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        return array_values(Role::findAll());
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getAction(int $id): Role
    {
        return Role::find($id);
    }

    /**
     * Save a role (create or update).
     */
    #[Route('/', methods: ['POST'])]
    #[Route('/{id}', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function saveAction(int $id = 0, ?array $data = null): array
    {
        if ($data === null) {
            $request = $this->request;

            $data = $request->request->all()['role'] ?? [];
            if (empty($data) && $request->getContent()) {
                $json = json_decode($request->getContent(), true);
                $data = $json['role'] ?? [];
            }
        }

        // Get id from route or data
        if (!$id && isset($data['id'])) {
            $id = (int) $data['id'];
        }

        // is new ?
        if (!$role = Role::find($id)) {

            if ($id) {
                throw new NotFoundHttpException(__('Role not found.'));
            }

            $role = Role::create();
        }

        // Assign data to entity for validation (without saving yet)
        foreach ($data as $key => $value) {
            if (property_exists($role, $key)) {
                $role->$key = $value;
            }
        }

        // Validate using Symfony Validator
        $this->validateOrFail($role);

        $role->save($data);

        return ['message' => 'success', 'role' => $role];
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteAction(int $id = 0): array
    {
        // Get id from route if not provided (Symfony 6.4 compatibility)
        if (!$id) {
            $id = (int) $this->request->get('id', 0);
        }

        if ($role = Role::find($id)) {
            $role->delete();
        }

        return ['message' => 'success'];
    }

    #[Route('/bulk', methods: ['POST'])]
    public function bulkSaveAction(): array
    {
        $request = $this->request;

        $roles = $request->request->all()['roles'] ?? [];
        if (empty($roles) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $roles = $json['roles'] ?? [];
        }

        foreach ($roles as $data) {
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
}
