<?php

namespace Pagekit\Blog\Controller;

use Pagekit\Application as App;
use Pagekit\Blog\Model\Post;

/**
 * @Access("blog: manage own posts || blog: manage all posts")
 * @Route("post", name="post")
 */
class PostApiController
{
    /**
     * @Route("/", methods="GET")
     */
    public function indexAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        $filter = $request->query->all()['filter'] ?? [];
        $page = (int) $request->query->get('page', 0);
        
        $query  = Post::query();
        $filter = array_merge(array_fill_keys(['status', 'search', 'author', 'order', 'limit'], ''), $filter);

        extract($filter, EXTR_SKIP);

        if(!App::user()->hasAccess('blog: manage all posts')) {
            $author = App::user()->id;
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

        $limit = (int) $limit ?: App::module('blog')->config('posts.posts_per_page');
        $count = $query->count();
        $pages = ceil($count / $limit);
        $page  = max(0, min($pages - 1, $page));

        $posts = array_values($query->offset($page * $limit)->related('user', 'comments')->limit($limit)->orderBy($order[1], $order[2])->get());

        return compact('posts', 'pages', 'count');
    }

    /**
     * @Route("/{id}", methods="GET", requirements={"id"="\d+"})
     */
    public function getAction($id)
    {
        return Post::where(compact('id'))->related('user', 'comments')->first();
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
            
            $data = $request->request->all()['post'] ?? [];
            if (empty($data) && $request->getContent()) {
                $json = json_decode($request->getContent(), true);
                $data = $json['post'] ?? [];
            }
        }
        
        // Get id from route or data
        if (!$id && isset($data['id'])) {
            $id = (int) $data['id'];
        }
        
        if (!$id || !$post = Post::find($id)) {

            if ($id) {
                App::abort(404, __('Post not found.'));
            }

            $post = Post::create();
        }

        if (!$data['slug'] = App::filter($data['slug'] ?: $data['title'], 'slugify')) {
            App::abort(400, __('Invalid slug.'));
        }

        // user without universal access is not allowed to assign posts to other users
        if(!App::user()->hasAccess('blog: manage all posts')) {
            $data['user_id'] = App::user()->id;
        }

        // user without universal access can only edit their own posts
        if(!App::user()->hasAccess('blog: manage all posts') && !App::user()->hasAccess('blog: manage own posts') && $post->user_id !== App::user()->id) {
            App::abort(400, __('Access denied.'));
        }

        $post->save($data);

        return ['message' => 'success', 'post' => $post];
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
        
        if ($post = Post::find($id)) {

            if(!App::user()->hasAccess('blog: manage all posts') && !App::user()->hasAccess('blog: manage own posts') && $post->user_id !== App::user()->id) {
                App::abort(400, __('Access denied.'));
            }

            $post->delete();
        }

        return ['message' => 'success'];
    }

    /**
     * @Route("/copy", methods="POST")
     */
    public function copyAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
        $ids = $request->request->all()['ids'] ?? [];
        if (empty($ids) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $ids = $json['ids'] ?? [];
        }
        
        foreach ($ids as $id) {
            if ($post = Post::find((int) $id)) {
                if(!App::user()->hasAccess('blog: manage all posts') && !App::user()->hasAccess('blog: manage own posts') && $post->user_id !== App::user()->id) {
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

    /**
     * @Route("/bulk", methods="POST")
     */
    public function bulkSaveAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
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
