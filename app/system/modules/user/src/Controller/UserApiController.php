<?php

declare(strict_types=1);

namespace Pagekit\User\Controller;

use function Pagekit\__;

use Pagekit\Application\Exception;
use Pagekit\Auth\Encoder\PasswordEncoderInterface;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Route;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * API Controller for User management.
 */
#[Access('user: manage users')]
class UserApiController
{
    use ValidatesRequestTrait;

    public function __construct(
        private readonly Request $request,
        private readonly User $user,
        private readonly ModuleManager $module,
        private readonly PasswordEncoderInterface $authPassword,
        protected readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @return array{users: array<int, User>, pages: float, count: int}
     */
    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        $request = $this->request;
        $filter = (array) ($request->query->all()['filter'] ?? []);
        $page = (int) $request->query->get('page', 0);
        $limit = (int) $request->query->get('limit', 0);

        $query = User::query();
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
            $query->whereExists(function ($query) use ($access) {
                $query
                    ->select('id')->from('@system_auth as a')
                    ->where('a.user_id = @system_user.id')
                    ->where(['a.access > :access', 'a.status > :status'], ['access' => date('Y-m-d H:i:s', time() - max(0, (int) $access)), 'status' => 0]);
            });
        }

        if (preg_match('/^(username|name|email|registered|login)\s(asc|desc)$/i', $order, $match)) {
            $order = $match;
        } else {
            $order = [1 => 'username', 2 => 'asc'];
        }

        $default = $this->module->get('system/user')->config('users_per_page');
        $limit = min(max(0, $limit), $default) ?: $default;
        $count = $query->count();
        $pages = ceil($count / $limit);
        $page = max(0, min($pages - 1, $page));
        $entities = $query->offset($page * $limit)->limit($limit)->orderBy($order[1], $order[2])->get();

        $users = [];
        foreach ($entities as $entity) {
            if (!$entity instanceof User) {
                throw new \LogicException(sprintf(
                    'QueryBuilder::get() returned %s, expected %s',
                    get_class($entity),
                    User::class
                ));
            }
            $users[] = $entity;
        }

        return compact('users', 'pages', 'count');
    }

    /**
     * @return array{count: int}
     */
    public function countAction(): array
    {
        $request = $this->request;
        $filter = $request->query->all()['filter'] ?? [];

        $query = User::query();
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
            $query->whereExists(function ($query) use ($access) {
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
            throw new NotFoundHttpException('User not found.');
        }

        return $user;
    }

    /**
     * Save a user (create or update).
     *
     * @return array{message: string, user: User}
     */
    #[Route('/', methods: ['POST'])]
    #[Route('/{id}', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function saveAction(int $id = 0): array
    {
        $request = $this->request;

        $data = $request->request->all()['user'] ?? [];
        $password = $request->request->get('password');

        if (empty($data) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $data = $json['user'] ?? [];
            $password = $json['password'] ?? $password;
        }

        if (!$id) {
            $id = (int) ($request->request->get('id') ?? $request->get('id', 0));
        }

        try {

            if (!$user = User::find($id)) {

                if ($id) {
                    throw new NotFoundHttpException(__('User not found.'));
                }

                if (!$password) {
                    throw new BadRequestHttpException(__('Password required.'));
                }

                $user = User::create(['registered' => new \DateTime()]);
            }

            if ($user->isAdministrator() && !$this->user->isAdministrator()) {
                throw new BadRequestHttpException(__('Unable to edit administrator.'));
            }

            $user->name = @$data['name'];
            $user->username = @$data['username'];
            $user->email = @$data['email'];

            $self = $this->user->id == $user->id;
            if ($self && @$data['status'] == User::STATUS_BLOCKED) {
                throw new BadRequestHttpException(__('Unable to block yourself.'));
            }

            if (@$data['email'] != $user->email) {
                $user->set('verified', false);
            }

            if (!empty($password)) {

                if (trim($password) != $password || strlen($password) < 3) {
                    throw new Exception(__('Invalid Password.'));
                }

                $user->password = $this->authPassword->hash($password);
            }

            $key = array_search(Role::ROLE_ADMINISTRATOR, @$data['roles'] ?: []);
            $add = false !== $key && !$user->isAdministrator();
            $remove = false === $key && $user->isAdministrator();

            if (($self && $remove) || !$this->user->isAdministrator() && ($remove || $add)) {
                throw new AccessDeniedHttpException('Cannot add/remove Admin Role.');
            }

            unset($data['login'], $data['registered']);

            $this->validateOrFail($user);

            $user->save($data);

            return ['message' => 'success', 'user' => $user];

        } catch (Exception $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }
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

        if ($this->user->id == $id) {
            throw new BadRequestHttpException(__('Unable to delete yourself.'));
        }

        if ($user = User::find($id)) {
            if ($user->isAdministrator() && !$this->user->isAdministrator()) {
                throw new BadRequestHttpException(__('Unable to delete administrator.'));
            }

            $user->delete();
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

        $users = $request->request->all()['users'] ?? [];
        if (empty($users) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $users = $json['users'] ?? [];
        }

        foreach ($users as $data) {
            $id = isset($data['id']) ? $data['id'] : 0;
            $password = $data['password'] ?? null;

            $request->request->set('user', $data);
            if ($password) {
                $request->request->set('password', $password);
            }

            $this->saveAction($id);
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
