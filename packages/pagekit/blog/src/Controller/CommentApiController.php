<?php

declare(strict_types=1);

namespace Pagekit\Blog\Controller;

use function Pagekit\__;

use Pagekit\Blog\Model\Comment;
use Pagekit\Blog\Model\Post;
use Pagekit\Captcha\Attribute\Captcha;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\User;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * API Controller for Blog Comment management.
 */
#[Route('comment', name: 'comment')]
class CommentApiController
{
    use ValidatesRequestTrait;

    protected Module $blog;

    public function __construct(
        ModuleManager $module,
        private readonly User $user,
        private readonly HttpRequest $request,
        private readonly mixed $content,
        private readonly mixed $validator,
    ) {
        $this->blog = $module->get('blog');
    }

    #[Route('/', methods: ['GET'])]
    #[Request(['filter' => 'array', 'post' => 'int', 'page' => 'int', 'limit' => 'int'])]
    public function indexAction(array $filter = [], int $post = 0, int $page = 0, int $limit = 0): array
    {
        $query = Comment::query();
        $filter = array_merge(array_fill_keys(['status', 'search', 'order'], ''), $filter);

        extract($filter, EXTR_SKIP);

        if ($post) {
            $query->where(['post_id = ?'], [$post]);
        } elseif (!$this->user->hasAccess('blog: manage comments')) {
            throw new AccessDeniedHttpException(__('Insufficient user rights.'));
        }

        if (!$this->user->hasAccess('blog: manage comments')) {

            $query->where(['status = ?'], [Comment::STATUS_APPROVED]);

            if ($this->user->isAuthenticated()) {
                $query->orWhere(function ($query) {
                    $query->where(['status = ?', 'user_id = ?'], [Comment::STATUS_PENDING, $this->user->id]);
                });
            }

        } elseif (is_numeric($status)) {
            $query->where(['status = ?'], [(int) $status]);
        } else {
            $query->where(function ($query) {
                $query->orWhere(['status = ?', 'status = ?'], [Comment::STATUS_APPROVED, Comment::STATUS_PENDING]);
            });
        }

        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->orWhere(['author LIKE ?', 'email LIKE ?', 'url LIKE ?', 'ip LIKE ?', 'content LIKE ?'], array_fill(0, 5, "%{$search}%"));
            });
        }

        $count = $query->count();
        $pages = ceil($count / ($limit ?: PHP_INT_MAX));
        $page = max(0, min($pages - 1, $page));

        if ($limit) {
            $query->offset($page * $limit)->limit($limit);
        }

        if (preg_match('/^(created)\s(asc|desc)$/i', $order, $match)) {
            $order = $match;
        } else {
            $order = [1 => 'created', 2 => $this->blog->config('comments.order')];
        }

        $comments = $query->related(['post' => function ($query) {
            return $query->related('comments');
        }])->related('user')->orderBy($order[1], $order[2])->get();

        $posts = [];

        foreach ($comments as $i => $comment) {

            $p = $comment->post;

            if ($post && (!$p || !$p->hasAccess($this->user) || !$p->isPublished() && !$this->user->hasAccess('blog: manage comments'))) {
                throw new AccessDeniedHttpException(__('Post not found.'));
            }

            $comment->content = $this->content->applyPlugins($comment->content, ['comment' => true]);

            $comment->special = count(array_diff($comment->user ? $comment->user->roles : [], [0, 1, 2]));
            $comment->post = null;
            $comment->user = null;

            if ($this->user->hasAccess('blog: manage comments')) {
                $posts[$p->id] = $p;
            } else {
                unset($comment->ip, $comment->user_id);
                $comment->email = md5(strtolower($comment->email));
            }
        }

        $comments = array_values($comments);
        $posts = array_values($posts);

        return compact('comments', 'posts', 'pages', 'count');
    }

    /**
     * Save a comment (create or update).
     */
    #[Route('/', methods: ['POST'])]
    #[Route('/{id}', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[Request(['comment' => 'array', 'id' => 'int'])]
    #[Captcha(verify: true)]
    public function saveAction(array $comment = [], int $id = 0): array
    {
        // Use $data internally for backwards compatibility with the rest of the code
        $data = $comment;

        if (!$id) {

            if (!$this->user->hasAccess('blog: post comments')) {
                throw new AccessDeniedHttpException(__('Insufficient User Rights.'));
            }

            $commentEntity = Comment::create();

            if ($this->user->isAuthenticated()) {
                $data['author'] = $this->user->name;
                $data['email'] = $this->user->email;
                $data['url'] = $this->user->url;
            } elseif ($this->blog->config('comments.require_email') && (!@$data['author'] || !@$data['email'])) {
                throw new BadRequestHttpException(__('Please provide valid name and email.'));
            }

            // user_id stored as string in database (legacy), use '0' for anonymous users
            $commentEntity->user_id = $this->user->isAuthenticated() ? (string) $this->user->id : '0';
            $commentEntity->ip = $this->request->getClientIp();
            $commentEntity->created = new \DateTime();

        } else {

            if (!$this->user->hasAccess('blog: manage comments')) {
                throw new AccessDeniedHttpException(__('Insufficient User Rights.'));
            }

            $commentEntity = Comment::find($id);

            if (!$commentEntity) {
                throw new NotFoundHttpException(__('Comment not found.'));
            }

        }

        // Security: Remove server-controlled fields from client data to prevent spoofing
        // These fields are set by the server (user_id, ip, created) and must not be overwritten by client
        unset($data['created'], $data['user_id'], $data['ip']);

        // check minimum idle time in between user comments (business logic)
        if (!$this->user->hasAccess('blog: skip comment min idle')
            and $minidle = $this->blog->config('comments.minidle')
            and $commentIdle = Comment::where($this->user->isAuthenticated() ? ['user_id' => $this->user->id] : ['ip' => $this->request->getClientIp()])->orderBy('created', 'DESC')->first()
        ) {

            $diff = $commentIdle->created->diff(new \DateTime("- {$minidle} sec"));

            if ($diff->invert) {
                throw new AccessDeniedHttpException(__('Please wait another %seconds% seconds before commenting again.', ['%seconds%' => $diff->s + $diff->i * 60 + $diff->h * 3600]));
            }
        }

        if (@$data['parent_id'] && !$parent = Comment::find((int) $data['parent_id'])) {
            throw new NotFoundHttpException(__('Parent not found.'));
        }

        if (!@$data['post_id'] || !$post = Post::where(['id' => $data['post_id']])->first() or !$this->user->hasAccess('blog: manage comments') && !($post->isCommentable() && $post->isPublished())) {
            throw new NotFoundHttpException(__('Post not found.'));
        }

        $approved_once = (bool) Comment::where(['user_id' => $this->user->id, 'status' => Comment::STATUS_APPROVED])->first();
        $commentEntity->status = $this->user->hasAccess('blog: skip comment approval') ? Comment::STATUS_APPROVED : ($this->user->hasAccess('blog: comment approval required once') && $approved_once ? Comment::STATUS_APPROVED : Comment::STATUS_PENDING);

        // check the max links rule (business logic)
        if ($commentEntity->status == Comment::STATUS_APPROVED && $this->blog->config('comments.maxlinks') <= preg_match_all('/<a [^>]*href/i', @$data['content'])) {
            $commentEntity->status = Comment::STATUS_PENDING;
        }

        // Assign data to entity for validation (without saving yet)
        foreach ($data as $key => $value) {
            if (property_exists($commentEntity, $key)) {
                $commentEntity->$key = $value;
            }
        }

        // Validate using Symfony Validator
        // Note: Some validations remain as business logic above (require_email for anonymous users)
        $this->validateOrFail($commentEntity);

        $commentEntity->save($data);

        return ['message' => 'success', 'comment' => $commentEntity];
    }

    #[Access('blog: manage comments')]
    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[Request(['id' => 'int'])]
    public function deleteAction(int $id): array
    {
        if ($comment = Comment::find($id)) {
            $comment->delete();
        }

        return ['message' => 'success'];
    }

    #[Access('blog: manage comments')]
    #[Route('/bulk', methods: ['POST'])]
    #[Request(['comments' => 'array'])]
    public function bulkSaveAction(array $comments = []): array
    {

        foreach ($comments as $data) {
            $this->saveAction($data, isset($data['id']) ? (int) $data['id'] : 0);
        }

        return ['message' => 'success'];
    }

    #[Access('blog: manage comments')]
    #[Route('/bulk', methods: ['DELETE'])]
    #[Request(['ids' => 'array'])]
    public function bulkDeleteAction(array $ids = []): array
    {
        foreach (array_filter($ids) as $id) {
            $this->deleteAction($id);
        }

        return ['message' => 'success'];
    }
}
