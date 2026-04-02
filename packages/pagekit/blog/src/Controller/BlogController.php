<?php

declare(strict_types=1);

namespace Pagekit\Blog\Controller;

use Pagekit\Blog\Model\Comment;
use Pagekit\Blog\Model\Post;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Access(admin: true)]
class BlogController
{
    protected Module $blog;

    public function __construct(
        ModuleManager $module,
        private readonly mixed $router,
        private readonly mixed $message,
        private readonly User $user,
        private readonly mixed $db,
    ) {
        $this->blog = $module->get('blog');
    }

    #[Access('blog: manage own posts || blog: manage all posts')]
    #[Request(['filter' => 'array', 'page' => 'int'])]
    public function postAction($filter = null, $page = null): array
    {
        return [
            '$view' => [
                'title' => __('Posts'),
                'name' => 'blog/admin/post-index.php',
            ],
            '$data' => [
                'statuses' => Post::getStatuses(),
                'authors' => Post::getAuthors(),
                'canEditAll' => $this->user->hasAccess('blog: manage all posts'),
                'config' => [
                    'filter' => (object) $filter,
                    'page' => $page,
                ],
            ],
        ];
    }

    #[Route('/post/edit', name: 'post/edit')]
    #[Access('blog: manage own posts || blog: manage all posts')]
    #[Request(['id' => 'int'])]
    public function editAction($id = 0)
    {
        try {

            if (!$post = Post::where(compact('id'))->related('user')->first()) {

                if ($id) {
                    throw new NotFoundHttpException(__('Invalid post id.'));
                }

                $post = Post::create([
                    'user_id' => $this->user->id,
                    'status' => Post::STATUS_DRAFT,
                    'date' => new \DateTime(),
                    'comment_status' => (bool) $this->blog->config('posts.comments_enabled'),
                ]);

                $post->set('title', $this->blog->config('posts.show_title'));
                $post->set('markdown', $this->blog->config('posts.markdown_enabled'));
            }

            if (!$this->user->hasAccess('blog: manage all posts') && $post->user_id !== $this->user->id) {
                throw new AccessDeniedHttpException(__('Insufficient User Rights.'));
            }

            $roles = $this->db->createQueryBuilder()
                ->from('@system_role')
                ->where(['id' => Role::ROLE_ADMINISTRATOR])
                ->whereInSet('permissions', ['blog: manage all posts', 'blog: manage own posts'], false, 'OR')
                ->execute('id')
                ->fetchFirstColumn();

            $authors = $this->db->createQueryBuilder()
                ->from('@system_user')
                ->whereInSet('roles', $roles)
                ->execute('id, username')
                ->fetchAllAssociative();

            return [
                '$view' => [
                    'title' => $id ? __('Edit Post') : __('Add Post'),
                    'name' => 'blog/admin/post-edit.php',
                ],
                '$data' => [
                    'post' => $post,
                    'statuses' => Post::getStatuses(),
                    'roles' => array_values(Role::findAll()),
                    'canEditAll' => $this->user->hasAccess('blog: manage all posts'),
                    'authors' => $authors,
                ],
                'post' => $post,
            ];

        } catch (\Exception $e) {

            $this->message->error($e->getMessage());

            return $this->router->redirect('@blog/post');
        }
    }

    #[Access('blog: manage comments')]
    #[Request(['filter' => 'array', 'post' => 'int', 'page' => 'int'])]
    public function commentAction($filter = [], $post = 0, $page = null): array
    {
        $post = Post::find($post);
        $filter['order'] = 'created DESC';

        return [
            '$view' => [
                'title' => $post ? __('Comments on %title%', ['%title%' => $post->title]) : __('Comments'),
                'name' => 'blog/admin/comment-index.php',
            ],
            '$data' => [
                'statuses' => Comment::getStatuses(),
                'config' => [
                    'filter' => (object) $filter,
                    'page' => $page,
                    'post' => $post,
                    'limit' => $this->blog->config('comments.comments_per_page'),
                ],
            ],
        ];
    }

    #[Access('system: access settings')]
    public function settingsAction(): array
    {
        return [
            '$view' => [
                'title' => __('Blog Settings'),
                'name' => 'blog/admin/settings.php',
            ],
            '$data' => [
                'config' => $this->blog->config(),
            ],
        ];
    }
}
