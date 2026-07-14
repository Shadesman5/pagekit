<?php

declare(strict_types=1);

namespace Pagekit\Blog\Controller;

use Pagekit\Application\Response;
use Pagekit\Application\UrlProvider;
use Pagekit\Blog\Model\Post;
use Pagekit\Blog\Model\PostRepository;
use Pagekit\Blog\PostPresenter;
use Pagekit\Captcha\Attribute\Captcha;
use Pagekit\Content\ContentHelper;
use Pagekit\Feed\FeedFactory;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Route;
use Pagekit\User\Model\User;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SiteController
{
    protected Module $blog;

    public function __construct(
        private readonly ModuleManager $module,
        private readonly User $user,
        private readonly ContentHelper $content,
        private readonly FeedFactory $feed,
        private readonly UrlProvider $url,
        private readonly Response $response,
        private readonly PostPresenter $postPresenter,
        private readonly PostRepository $postRepository,
    ) {
        $this->blog = $module->get('blog');
    }

    /**
     * @return array<string, mixed>
     */
    #[Route('/')]
    #[Route('/page/{page}', name: 'page', requirements: ['page' => '\d+'])]
    public function indexAction(int $page = 1): array
    {
        $query = $this->postRepository->where(['status = ?', 'date < ?'], [Post::STATUS_PUBLISHED, new \DateTime()])->where(function ($query) {
            return $query->where('roles IS NULL')->whereInSet('roles', $this->user->roles, false, 'OR');
        })->related('user');

        if (!$limit = $this->blog->config('posts.posts_per_page')) {
            $limit = 10;
        }
        $count = $query->count('id');
        $total = ceil($count / $limit);
        $page = max(1, min($total, $page));

        $query->offset((int) (($page - 1) * $limit))->limit($limit)->orderBy('date', 'DESC');

        $posts = [];
        foreach ($query->get() as $post) {
            $post->set('commentable', $this->postPresenter->isCommentable($post));
            $post->excerpt = $this->content->applyPlugins($post->excerpt ?? '', ['post' => $post, 'markdown' => $post->get('markdown')]);
            $post->content = $this->content->applyPlugins($post->content ?? '', ['post' => $post, 'markdown' => $post->get('markdown'), 'readmore' => true]);
            $posts[] = $post;
        }

        return [
            '$view' => [
                'title' => __('Blog'),
                'name' => 'blog/posts.php',
                'link:feed' => [
                    'rel' => 'alternate',
                    'href' => $this->url->get('@blog/feed'),
                    'title' => $this->module->get('system/site')->config('title'),
                    'type' => $this->feed->create($this->blog->config('feed.type'))->getMIMEType(),
                ],
            ],
            'blog' => $this->blog,
            'posts' => $posts,
            'total' => $total,
            'page' => $page,
        ];
    }

    #[Route('/feed')]
    #[Route('/feed/{type}')]
    public function feedAction(string $type = ''): HttpResponse
    {
        // fetch locale and convert to ISO-639 (en_US -> en-us)
        $locale = $this->module->get('system')->config('site.locale');
        $locale = str_replace('_', '-', strtolower($locale));

        $site = $this->module->get('system/site');
        $feed = $this->feed->create($type ?: $this->blog->config('feed.type'), [
            'title' => $site->config('title'),
            'link' => $this->url->get('@blog', [], 0),
            'description' => $site->config('description'),
            'element' => ['language', $locale],
            'selfLink' => $this->url->get('@blog/feed', [], 0),
        ]);

        if ($last = $this->postRepository->where(['status = ?', 'date < ?'], [Post::STATUS_PUBLISHED, new \DateTime()])->limit(1)->orderBy('modified', 'DESC')->first()) {
            if ($last->modified !== null) {
                $feed->setDate($last->modified);
            }
        }

        foreach ($this->postRepository->where(['status = ?', 'date < ?'], [Post::STATUS_PUBLISHED, new \DateTime()])->where(function ($query) {
            return $query->where('roles IS NULL')->whereInSet('roles', $this->user->roles, false, 'OR');
        })->related('user')->limit($this->blog->config('feed.limit'))->orderBy('date', 'DESC')->get() as $post) {
            $url = $this->url->get('@blog/id', ['id' => $post->id], 0);
            $feed->addItem(
                $feed->createItem([
                    'title' => $post->title,
                    'link' => $url,
                    'description' => $this->content->applyPlugins($post->content ?? '', ['post' => $post, 'markdown' => $post->get('markdown'), 'readmore' => true]),
                    'date' => $post->date,
                    'author' => [$post->user?->name, $post->user?->email],
                    'id' => $url,
                ])
            );
        }

        return $this->response->create($feed->generate(), 200, ['Content-Type' => $feed->getMIMEType().'; charset='.$feed->getEncoding()]);
    }

    /**
     * @return array<string, mixed>
     */
    #[Route('/{id}', name: 'id')]
    #[Captcha(route: '@blog/api/comment/save')]
    #[Captcha(route: '@blog/api/comment/save_1')]
    public function postAction(int $id = 0): array
    {
        $post = $this->postRepository->where(['id = ?', 'status = ?', 'date < ?'], [$id, Post::STATUS_PUBLISHED, new \DateTime()])->related('user')->first();

        if ($post === null) {
            throw new NotFoundHttpException(__('Post not found!'));
        }

        if (!$post->hasAccess($this->user)) {
            throw new AccessDeniedHttpException(__('Insufficient User Rights.'));
        }

        $post->excerpt = $this->content->applyPlugins($post->excerpt ?? '', ['post' => $post, 'markdown' => $post->get('markdown')]);
        $post->content = $this->content->applyPlugins($post->content ?? '', ['post' => $post, 'markdown' => $post->get('markdown')]);

        $description = $post->get('meta.og:description');
        if (!$description) {
            $description = strip_tags($post->excerpt ?: ($post->content ?? ''));
            $description = rtrim(mb_substr($description, 0, 150), " \t\n\r\0\x0B.,") . '...';
        }

        return [
            '$view' => [
                'title' => __($post->title ?? ''),
                'name' => 'blog/post.php',
                'og:type' => 'article',
                'article:published_time' => $post->date?->format(\DateTime::ATOM),
                'article:modified_time' => $post->modified?->format(\DateTime::ATOM),
                'article:author' => $post->user?->name,
                'og:title' => $post->get('meta.og:title') ?: $post->title,
                'og:description' => $description,
                'og:image' => $post->get('image.src') ? $this->url->getStatic($post->get('image.src'), [], 0) : false,
            ],
            '$comments' => [
                'config' => [
                    'post' => $post->id,
                    'enabled' => $this->postPresenter->isCommentable($post),
                    'requireinfo' => $this->blog->config('comments.require_email'),
                    'max_depth' => $this->blog->config('comments.max_depth'),
                    'user' => [
                        'name' => $this->user->name,
                        'isAuthenticated' => $this->user->isAuthenticated(),
                        'canComment' => $this->user->hasAccess('blog: post comments'),
                        'skipApproval' => $this->user->hasAccess('blog: skip comment approval'),
                    ],
                ],
            ],
            'blog' => $this->blog,
            'post' => $post,
        ];
    }
}
