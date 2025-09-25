<?php

namespace Pagekit\User\Controller;

use Pagekit\Application as App;
use Pagekit\User\Model\Role;
use function Pagekit\__;

/**
 * @Access("user: manage user permissions")
 */
class RoleApiController
{
    /**
     * @Route("/", methods="GET")
     */
    public function indexAction(): array
    {
        return array_values(Role::findAll());
    }

    /**
     * @Route("/{id}", methods="GET", requirements={"id"="\d+"})
     */
    public function getAction($id): Role
    {
        return Role::find($id);
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
                App::abort(404, __('Role not found.'));
            }

            $role = Role::create();
        }

        $role->save($data);

        return ['message' => 'success', 'role' => $role];
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
        
        if ($role = Role::find($id)) {
            $role->delete();
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
     * @Route("/bulk", methods="DELETE")
     */
    public function bulkDeleteAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
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
