<?php

declare(strict_types=1);

namespace Pagekit\Blog\Controller;

use Pagekit\Application as App;
use Pagekit\Blog\Model\Comment;
use Pagekit\Blog\Model\Post;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\Role;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Access(admin: true)]
class BlogController
{
    protected Module $blog;

    public function __construct(ModuleManager $module)
    {
        $this->blog = $module->get('blog');
    }

    #[Access('blog: manage own posts || blog: manage all posts')]
    #[Request(['filter' => 'array', 'page' => 'int'])]
    public function postAction($filter = null, $page = null): array
    {
        return [
            '$view' => [
                'title' => __('Posts'),
                'name'  => 'blog/admin/post-index.php'
            ],
            '$data' => [
                'statuses' => Post::getStatuses(),
                'authors'  => Post::getAuthors(),
                'canEditAll' => App::user()->hasAccess('blog: manage all posts'), // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
                'config'   => [
                    'filter' => (object) $filter,
                    'page'   => $page
                ]
            ]
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
                    'user_id' => App::user()->id, // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
                    'status' => Post::STATUS_DRAFT,
                    'date' => new \DateTime(),
                    'comment_status' => (bool) $this->blog->config('posts.comments_enabled')
                ]);

                $post->set('title', $this->blog->config('posts.show_title'));
                $post->set('markdown', $this->blog->config('posts.markdown_enabled'));
            }

            $user = App::user(); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
            if(!$user->hasAccess('blog: manage all posts') && $post->user_id !== $user->id) {
                throw new AccessDeniedHttpException(__('Insufficient User Rights.'));
            }

            $roles = App::db()->createQueryBuilder() // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
                ->from('@system_role')
                ->where(['id' => Role::ROLE_ADMINISTRATOR])
                ->whereInSet('permissions', ['blog: manage all posts', 'blog: manage own posts'], false, 'OR')
                ->execute('id')
                ->fetchFirstColumn();

            $authors = App::db()->createQueryBuilder() // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
                ->from('@system_user')
                ->whereInSet('roles', $roles)
                ->execute('id, username')
                ->fetchAllAssociative();

            return [
                '$view' => [
                    'title' => $id ? __('Edit Post') : __('Add Post'),
                    'name'  => 'blog/admin/post-edit.php'
                ],
                '$data' => [
                    'post'     => $post,
                    'statuses' => Post::getStatuses(),
                    'roles'    => array_values(Role::findAll()),
                    'canEditAll' => $user->hasAccess('blog: manage all posts'),
                    'authors'  => $authors
                ],
                'post' => $post
            ];

        } catch (\Exception $e) {

            App::message()->error($e->getMessage()); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)

            return App::redirect('@blog/post'); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
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
                'name'  => 'blog/admin/comment-index.php'
            ],
            '$data'   => [
                'statuses' => Comment::getStatuses(),
                'config'   => [
                    'filter' => (object) $filter,
                    'page'   => $page,
                    'post'   => $post,
                    'limit'  => $this->blog->config('comments.comments_per_page')
                ]
            ]
        ];
    }

    #[Access('system: access settings')]
    public function settingsAction(): array
    {
        return [
            '$view' => [
                'title' => __('Blog Settings'),
                'name'  => 'blog/admin/settings.php'
            ],
            '$data' => [
                'config' => $this->blog->config()
            ]
        ];
    }
}
