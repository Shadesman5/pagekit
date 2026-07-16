<?php

declare(strict_types=1);

namespace Pagekit\Blog\Controller;

use function Pagekit\__;

use Pagekit\Blog\Model\Post;
use Pagekit\Blog\Model\PostRepository;
use Pagekit\Blog\PostPresenter;
use Pagekit\Database\Connection;
use Pagekit\Filter\FilterManager;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Route;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * API Controller for Blog Post management.
 */
#[Access('blog: manage own posts || blog: manage all posts')]
#[Route('post', name: 'post')]
class PostApiController
{
    use ValidatesRequestTrait;

    protected Module $blog;

    public function __construct(
        ModuleManager $module,
        private readonly User $user,
        private readonly Request $request,
        private readonly FilterManager $filter,
        private readonly Connection $db,
        protected readonly ValidatorInterface $validator,
        private readonly PostPresenter $postPresenter,
        private readonly PostRepository $postRepository,
    ) {
        $this->blog = $module->get('blog');
    }

    /**
     * @return array<string, mixed>
     */
    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        $filter = (array) ($this->request->query->all()['filter'] ?? []);
        $page = (int) $this->request->query->get('page', 0);

        $query = $this->postRepository->query();
        $filter = array_merge(array_fill_keys(['status', 'search', 'author', 'order', 'limit'], ''), $filter);

        extract($filter, EXTR_SKIP);

        if (!$this->user->hasAccess('blog: manage all posts')) {
            $author = $this->user->id;
        }

        if (is_numeric($status)) {
            $query->where(['status' => (int) $status]);
        }

        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->orWhere(['title LIKE :search', 'slug LIKE :search'], ['search' => "%{$search}%"]);
            });
        }

        if ($author) {
            $query->where(function ($query) use ($author) {
                $query->orWhere(['user_id' => (int) $author]);
            });
        }

        if (!preg_match('/^(date|title|comment_count)\s(asc|desc)$/i', $order, $order)) {
            $order = [1 => 'date', 2 => 'desc'];
        }

        $limit = (int) $limit ?: $this->blog->config('posts.posts_per_page');
        $count = $query->count();
        $pages = ceil($count / $limit);
        $page = max(0, min($pages - 1, $page));

        $posts = [];
        foreach ($query->offset($page * $limit)->related('user', 'comments')->limit($limit)->orderBy($order[1], $order[2])->get() as $post) {
            $posts[] = $this->postPresenter->toArray($post);
        }

        return compact('posts', 'pages', 'count');
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getAction(int $id): ?array
    {
        $post = $this->postRepository->where(compact('id'))->related('user', 'comments')->first();

        return $post ? $this->postPresenter->toArray($post) : null;
    }

    /**
     * Save a post (create or update).
     *
     * @param  array<string, mixed>|null $data
     * @return array{message: string, post: array<string, mixed>}
     */
    #[Route('/', methods: ['POST'])]
    #[Route('/{id}', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function saveAction(int $id = 0, ?array $data = null): array
    {
        if ($data === null) {
            $data = $this->request->request->all()['post'] ?? [];
            if (empty($data) && $this->request->getContent()) {
                $json = json_decode($this->request->getContent(), true);
                $data = $json['post'] ?? [];
            }
        }

        if (!$id && isset($data['id'])) {
            $id = (int) $data['id'];
        }

        if (!$id || !$post = $this->postRepository->find($id)) {

            if ($id) {
                throw new NotFoundHttpException(__('Post not found.'));
            }

            $post = $this->postRepository->create();
        }

        $data['slug'] = $this->filter->apply($data['slug'] ?: $data['title'], 'slugify');

        if (!$this->user->hasAccess('blog: manage all posts')) {
            $data['user_id'] = $this->user->id;
        }

        if (!$this->user->hasAccess('blog: manage all posts') && !$this->user->hasAccess('blog: manage own posts') && $post->user_id !== $this->user->id) {
            throw new BadRequestHttpException(__('Access denied.'));
        }

        $skipFields = ['date', 'modified', 'created'];
        foreach ($data as $key => $value) {
            if (property_exists($post, $key) && !in_array($key, $skipFields, true)) {
                $post->$key = $value;
            }
        }

        $this->validateOrFail($post);

        $this->postRepository->save($post, $data);

        return ['message' => 'success', 'post' => $this->postPresenter->toArray($post)];
    }

    /**
     * @return array<string, string>
     */
    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteAction(int $id = 0): array
    {
        if (!$id) {
            $id = (int) $this->request->get('id', 0);
        }

        if ($post = $this->postRepository->find($id)) {

            if (!$this->user->hasAccess('blog: manage all posts') && !$this->user->hasAccess('blog: manage own posts') && $post->user_id !== $this->user->id) {
                throw new BadRequestHttpException(__('Access denied.'));
            }

            $this->postRepository->delete($post);
        }

        return ['message' => 'success'];
    }

    /**
     * @return array<string, string>
     */
    #[Route('/copy', methods: ['POST'])]
    public function copyAction(): array
    {
        $ids = $this->request->request->all()['ids'] ?? [];
        if (empty($ids) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $ids = $json['ids'] ?? [];
        }

        foreach ($ids as $id) {
            if ($post = $this->postRepository->find((int) $id)) {
                if (!$this->user->hasAccess('blog: manage all posts') && !$this->user->hasAccess('blog: manage own posts') && $post->user_id !== $this->user->id) {
                    continue;
                }

                $post = clone $post;
                $post->id = null;
                $post->status = Post::STATUS_DRAFT;
                $post->title = $post->title.' - '.__('Copy');
                $post->comment_count = 0;
                $post->date = new \DateTime();
                $this->postRepository->save($post);
            }
        }

        return ['message' => 'success'];
    }

    /**
     * @return array<string, string>
     */
    #[Route('/bulk', methods: ['POST'])]
    public function bulkSaveAction(): array
    {
        $posts = $this->request->request->all()['posts'] ?? [];
        if (empty($posts) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $posts = $json['posts'] ?? [];
        }

        foreach ($posts as $data) {
            $id = isset($data['id']) ? (int) $data['id'] : 0;
            $this->saveAction($id, $data);
        }

        return ['message' => 'success'];
    }

    /**
     * @return array<string, string>
     */
    #[Route('/bulk', methods: ['DELETE'])]
    public function bulkDeleteAction(): array
    {
        $ids = $this->request->request->all()['ids'] ?? [];
        if (empty($ids) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $ids = $json['ids'] ?? [];
        }

        foreach (array_filter($ids) as $id) {
            $this->deleteAction((int) $id);
        }

        return ['message' => 'success'];
    }
}
