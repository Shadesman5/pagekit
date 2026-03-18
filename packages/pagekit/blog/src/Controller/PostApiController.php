<?php

declare(strict_types=1);

namespace Pagekit\Blog\Controller;

use Pagekit\Application as App;
use Pagekit\Blog\Model\Post;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Route;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\User\Attribute\Access;
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

    public function __construct(ModuleManager $module)
    {
        $this->blog = $module->get('blog');
    }

    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        $request = App::request(); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
        $filter = $request->query->all()['filter'] ?? [];
        $page = (int) $request->query->get('page', 0);

        $query  = Post::query();
        $filter = array_merge(array_fill_keys(['status', 'search', 'author', 'order', 'limit'], ''), $filter);

        extract($filter, EXTR_SKIP);

        if(!App::user()->hasAccess('blog: manage all posts')) { // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
            $author = App::user()->id; // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
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
            $request = App::request(); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)

            $data = $request->request->all()['post'] ?? [];
            if (empty($data) && $request->getContent()) {
                $json = json_decode($request->getContent(), true);
                $data = $json['post'] ?? [];
            }
        }

        if (!$id && isset($data['id'])) {
            $id = (int) $data['id'];
        }

        if (!$id || !$post = Post::find($id)) {

            if ($id) {
                App::abort(404, __('Post not found.')); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
            }

            $post = Post::create();
        }

        $data['slug'] = App::filter($data['slug'] ?: $data['title'], 'slugify'); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)

        if(!App::user()->hasAccess('blog: manage all posts')) { // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
            $data['user_id'] = App::user()->id; // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
        }

        if(!App::user()->hasAccess('blog: manage all posts') && !App::user()->hasAccess('blog: manage own posts') && $post->user_id !== App::user()->id) { // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
            App::abort(400, __('Access denied.')); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
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
            $id = (int) App::request()->get('id', 0); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
        }

        if ($post = Post::find($id)) {

            if(!App::user()->hasAccess('blog: manage all posts') && !App::user()->hasAccess('blog: manage own posts') && $post->user_id !== App::user()->id) { // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
                App::abort(400, __('Access denied.')); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
            }

            $post->delete();
        }

        return ['message' => 'success'];
    }

    #[Route('/copy', methods: ['POST'])]
    public function copyAction(): array
    {
        $request = App::request(); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)

        $ids = $request->request->all()['ids'] ?? [];
        if (empty($ids) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $ids = $json['ids'] ?? [];
        }

        foreach ($ids as $id) {
            if ($post = Post::find((int) $id)) {
                if(!App::user()->hasAccess('blog: manage all posts') && !App::user()->hasAccess('blog: manage own posts') && $post->user_id !== App::user()->id) { // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
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
        $request = App::request(); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)

        $posts = $request->request->all()['posts'] ?? [];
        if (empty($posts) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
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
        $request = App::request(); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)

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
