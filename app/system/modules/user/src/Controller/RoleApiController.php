<?php

declare(strict_types=1);

namespace Pagekit\User\Controller;

use function Pagekit\__;

use Pagekit\Routing\Attribute\Route;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\Role;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * API Controller for Role management.
 */
#[Access('user: manage user permissions')]
class RoleApiController
{
    use ValidatesRequestTrait;

    public function __construct(
        private readonly Request $request,
        protected readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @return array<int, Role>
     */
    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        $roles = [];
        foreach (Role::findAll() as $role) {
            if (!$role instanceof Role) {
                throw new \LogicException(sprintf(
                    'Model::findAll() returned %s, expected %s',
                    get_class($role),
                    Role::class
                ));
            }
            $roles[] = $role;
        }

        return $roles;
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getAction(int $id): Role
    {
        if (!$role = Role::find($id)) {
            throw new NotFoundHttpException(__('Role not found.'));
        }

        return $role;
    }

    /**
     * Save a role (create or update).
     *
     * @param  array<string, mixed>|null $data
     * @return array{message: string, role: Role}
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

        if (!$id && isset($data['id'])) {
            $id = (int) $data['id'];
        }

        if (!$role = Role::find($id)) {

            if ($id) {
                throw new NotFoundHttpException(__('Role not found.'));
            }

            $role = Role::create();
        }

        foreach ($data as $key => $value) {
            if (property_exists($role, $key)) {
                $role->$key = $value;
            }
        }

        $this->validateOrFail($role);

        $role->save($data);

        return ['message' => 'success', 'role' => $role];
    }

    /**
     * @return array{message: string}
     */
    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteAction(int $id = 0): array
    {
        if (!$id) {
            $id = (int) $this->request->get('id', 0);
        }

        if ($role = Role::find($id)) {
            $role->delete();
        }

        return ['message' => 'success'];
    }

    /**
     * @return array{message: string}
     */
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

    /**
     * @return array{message: string}
     */
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
