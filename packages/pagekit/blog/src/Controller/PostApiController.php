<?php

declare(strict_types=1);

namespace Pagekit\Blog\Controller;

use Pagekit\Blog\Model\Post;
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
use function Pagekit\__;

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
        private readonly mixed $db,
    ) {
        $this->blog = $module->get('blog');
    }

    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        $filter = $this->request->query->all()['filter'] ?? [];
        $page = (int) $this->request->query->get('page', 0);

        $query  = Post::query();
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
        $page  = max(0, min($pages - 1, $page));

        $posts = array_values($query->offset($page * $limit)->related('user', 'comments')->limit($limit)->orderBy($order[1], $order[2])->get());

        return compact('posts', 'pages', 'count');
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getAction(int $id)
    {
        return Post::where(compact('id'))->related('user', 'comments')->first();
    }

    /**
     * Save a post (create or update).
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

        if (!$id || !$post = Post::find($id)) {

            if ($id) {
                throw new NotFoundHttpException(__('Post not found.'));
            }

            $post = Post::create();
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

        $post->save($data);

        return ['message' => 'success', 'post' => $post];
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteAction(int $id = 0): array
    {
        if (!$id) {
            $id = (int) $this->request->get('id', 0);
        }

        if ($post = Post::find($id)) {

            if (!$this->user->hasAccess('blog: manage all posts') && !$this->user->hasAccess('blog: manage own posts') && $post->user_id !== $this->user->id) {
                throw new BadRequestHttpException(__('Access denied.'));
            }

            $post->delete();
        }

        return ['message' => 'success'];
    }

    #[Route('/copy', methods: ['POST'])]
    public function copyAction(): array
    {
        $ids = $this->request->request->all()['ids'] ?? [];
        if (empty($ids) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $ids = $json['ids'] ?? [];
        }

        foreach ($ids as $id) {
            if ($post = Post::find((int) $id)) {
                if (!$this->user->hasAccess('blog: manage all posts') && !$this->user->hasAccess('blog: manage own posts') && $post->user_id !== $this->user->id) {
                    continue;
                }

                $post = clone $post;
                $post->id = null;
                $post->status = Post::STATUS_DRAFT;
                $post->title = $post->title.' - '.__('Copy');
                $post->comment_count = 0;
                $post->date = new \DateTime();
                $post->save();
            }
        }

        return ['message' => 'success'];
    }

    #[Route('/bulk', methods: ['POST'])]
    public function bulkSaveAction(): array
    {
        $posts = $this->request->request->all()['posts'] ?? [];
        if (empty($posts) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $posts = $json['posts'] ?? [];
        }

        foreach ($posts as $data) {
            $id = isset($data['id']) ? $data['id'] : 0;
            $this->saveAction($id, $data);
        }

        return ['message' => 'success'];
    }

    #[Route('/bulk', methods: ['DELETE'])]
    public function bulkDeleteAction(): array
    {
        $ids = $this->request->request->all()['ids'] ?? [];
        if (empty($ids) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $ids = $json['ids'] ?? [];
        }

        foreach (array_filter($ids) as $id) {
            $this->deleteAction($id);
        }

        return ['message' => 'success'];
    }
}
