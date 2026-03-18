<?php

declare(strict_types=1);

namespace Pagekit\User\Controller;

use Pagekit\Application as App;
use Pagekit\Application\Exception;
use Pagekit\Routing\Attribute\Route;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use function Pagekit\__;

/**
 * API Controller for User management.
 */
#[Access('user: manage users')]
class UserApiController
{
    use ValidatesRequestTrait;

    public function __construct(
        private readonly mixed $request,
        private readonly mixed $user,
        private readonly mixed $module,
    ) {}

    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        $request = $this->request;
        $filter = $request->query->all()['filter'] ?? [];
        $page = (int) $request->query->get('page', 0);
        $limit = (int) $request->query->get('limit', 0);

        $query  = User::query();
        $filter = array_merge(array_fill_keys(['status', 'search', 'role', 'order', 'access'], ''), $filter);
        extract($filter, EXTR_SKIP);

        if (is_numeric($status)) {

            $query->where(['status' => (int) $status]);

            if ($status) {
                $query->where('login IS NOT NULL');
            }

        } elseif ('new' == $status) {
            $query->where(['status' => User::STATUS_ACTIVE, 'login IS NULL']);
        }

        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->orWhere(['username LIKE :search', 'name LIKE :search', 'email LIKE :search'], ['search' => "%{$search}%"]);
            });
        }

        if ($role) {
            $query->whereInSet('roles', $role);
        }

        if ($access) {
            $query->whereExists(function($query) use ($access) {
                $query
                    ->select('id')->from('@system_auth as a')
                    ->where('a.user_id = @system_user.id')
                    ->where(['a.access > :access', 'a.status > :status'], ['access' => date('Y-m-d H:i:s', time() - max(0, (int) $access)), 'status' => 0]);
            });
        }

        if (preg_match('/^(username|name|email|registered|login)\s(asc|desc)$/i', $order, $match)) {
            $order = $match;
        } else {
            $order = [1=>'username', 2=>'asc'];
        }

        $default = $this->module->get('system/user')->config('users_per_page');
        $limit   = min(max(0, $limit), $default) ?: $default;
        $count   = $query->count();
        $pages   = ceil($count / $limit);
        $page    = max(0, min($pages - 1, $page));
        $users   = array_values($query->offset($page * $limit)->limit($limit)->orderBy($order[1], $order[2])->get());

        return compact('users', 'pages', 'count');
    }

    public function countAction(): array
    {
        $request = $this->request;
        $filter = $request->query->all()['filter'] ?? [];

        $query  = User::query();
        $filter = array_merge(array_fill_keys(['status', 'search', 'role', 'order', 'access'], ''), (array)$filter);
        extract($filter, EXTR_SKIP);

        if (is_numeric($status)) {

            $query->where(['status' => (int) $status]);

            if ($status) {
                $query->where('login IS NOT NULL');
            }

        } elseif ('new' == $status) {
            $query->where(['status' => User::STATUS_ACTIVE, 'login IS NULL']);
        }

        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->orWhere(['username LIKE :search', 'name LIKE :search', 'email LIKE :search'], ['search' => "%{$search}%"]);
            });
        }

        if ($role) {
            $query->whereInSet('roles', $role);
        }

        if ($access) {
            $query->whereExists(function($query) use ($access) {
                $query
                    ->select('id')->from('@system_auth as a')
                    ->where('a.user_id = @system_user.id')
                    ->where(['a.access > :access', 'a.status > :status'], ['access' => date('Y-m-d H:i:s', time() - max(0, (int) $access)), 'status' => 0]);
            });
        }

        $count = $query->count();

        return compact('count');
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getAction(int $id): User
    {
        if (!$user = User::find($id)) {
            App::abort(404, 'User not found.'); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
        }

        return $user;
    }

    /**
     * Save a user (create or update).
     */
    #[Route('/', methods: ['POST'])]
    #[Route('/{id}', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function saveAction(int $id = 0)
    {
        $request = $this->request;

        // Get user data from POST or JSON body
        $data = $request->request->all()['user'] ?? [];
        $password = $request->request->get('password');

        if (empty($data) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $data = $json['user'] ?? [];
            $password = $json['password'] ?? $password;
        }

        // Get id from route if not provided
        if (!$id) {
            $id = (int) ($request->request->get('id') ?? $request->get('id', 0));
        }

        try {

            // is new ?
            if (!$user = User::find($id)) {

                if ($id) {
                    App::abort(404, __('User not found.')); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
                }

                if (!$password) {
                    App::abort(400, __('Password required.')); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
                }

                $user = User::create(['registered' => new \DateTime]);
            }

            if ($user->isAdministrator() && !$this->user->isAdministrator()) {
                App::abort(400, __('Unable to edit administrator.')); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
            }

            $user->name = @$data['name'];
            $user->username = @$data['username'];
            $user->email = @$data['email'];

            $self = $this->user->id == $user->id;
            if ($self && @$data['status'] == User::STATUS_BLOCKED) {
                App::abort(400, __('Unable to block yourself.')); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
            }

            if (@$data['email'] != $user->email) {
                $user->set('verified', false);
            }

            if (!empty($password)) {

                if (trim($password) != $password || strlen($password) < 3) {
                    throw new Exception(__('Invalid Password.'));
                }

                $user->password = App::getInstance()->get('auth.password')->hash($password); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
            }

            $key    = array_search(Role::ROLE_ADMINISTRATOR, @$data['roles'] ?: []);
            $add    = false !== $key && !$user->isAdministrator();
            $remove = false === $key && $user->isAdministrator();

            if (($self && $remove) || !$this->user->isAdministrator() && ($remove || $add)) {
                App::abort(403, 'Cannot add/remove Admin Role.'); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
            }

            unset($data['login'], $data['registered']);

            // Validate using Symfony Validator
            $this->validateOrFail($user);

            $user->save($data);

            return ['message' => 'success', 'user' => $user];

        } catch (Exception $e) {
            App::abort(400, $e->getMessage()); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
        }
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteAction(int $id = 0): array
    {
        // Get id from route if not provided (Symfony 6.4 compatibility)
        if (!$id) {
            $id = (int) $this->request->get('id', 0);
        }

        if ($this->user->id == $id) {
            App::abort(400, __('Unable to delete yourself.')); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
        }

        if ($user = User::find($id)) {
            if ($user->isAdministrator() && !$this->user->isAdministrator()) {
                App::abort(400, __('Unable to delete administrator.')); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
            }

            $user->delete();
        }

        return ['message' => 'success'];
    }

    #[Route('/bulk', methods: ['POST'])]
    public function bulkSaveAction(): array
    {
        $request = $this->request;

        // Get users data from POST or JSON body
        $users = $request->request->all()['users'] ?? [];
        if (empty($users) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $users = $json['users'] ?? [];
        }

        foreach ($users as $data) {
            // Temporarily set the data in request for saveAction
            $id = isset($data['id']) ? $data['id'] : 0;
            $password = $data['password'] ?? null;

            // Create a new request with the user data
            $request->request->set('user', $data);
            if ($password) {
                $request->request->set('password', $password);
            }

            $this->saveAction($id);
        }

        return ['message' => 'success'];
    }

    #[Route('/bulk', methods: ['DELETE'])]
    public function bulkDeleteAction(): array
    {
        $request = $this->request;

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
}
