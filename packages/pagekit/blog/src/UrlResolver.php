<?php

declare(strict_types=1);

namespace Pagekit\Blog;

use Pagekit\Blog\Model\Post;
use Pagekit\Blog\Model\PostRepository;
use Pagekit\Module\Module;
use Pagekit\Routing\ParamsResolverInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

class UrlResolver implements ParamsResolverInterface
{
    public const CACHE_KEY = 'blog.routing';

    protected bool $cacheDirty = false;

    /** @var array<int|string, array<string, mixed>> */
    protected array $cacheEntries;

    /**
     * Constructor.
     *
     * The router builds this resolver through the factory the blog module
     * registers during boot ({@see \Pagekit\Routing\Router::addResolver()}).
     */
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly Module $module,
        private readonly PostRepository $posts,
    ) {
        $item = $this->cache->getItem(self::CACHE_KEY);
        $this->cacheEntries = $item->isHit() ? ($item->get() ?: []) : [];
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function match(array $parameters = []): array
    {
        if (isset($parameters['id'])) {
            return $parameters;
        }

        if (!isset($parameters['slug'])) {
            throw new NotFoundHttpException('Post not found.');
        }

        $slug = $parameters['slug'];

        $id = false;
        foreach ($this->cacheEntries as $entry) {
            if ($entry['slug'] === $slug) {
                $id = $entry['id'];
            }
        }

        if (!$id) {

            if (!$post = $this->posts->where(compact('slug'))->first()) {
                throw new NotFoundHttpException('Post not found.');
            }

            $this->addCache($post);
            $id = $post->id;
        }

        $parameters['id'] = $id;

        return $parameters;
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function generate(array $parameters = []): array
    {
        $id = $parameters['id'];

        if (!isset($this->cacheEntries[$id])) {

            if (!$post = $this->posts->where(compact('id'))->first()) {
                throw new RouteNotFoundException('Post not found!');
            }

            $this->addCache($post);
        }

        $meta = $this->cacheEntries[$id];

        $permalink = self::permalinkFor($this->module);

        $matchCount = preg_match_all('#{([a-z]+)}#i', $permalink, $matches);

        if ($matchCount > 0 && !empty($matches[1])) {
            foreach ($matches[1] as $attribute) {
                if (isset($meta[$attribute])) {
                    $parameters[$attribute] = $meta[$attribute];
                }
            }
            unset($parameters['id']);
        }

        return $parameters;
    }

    public function __destruct()
    {
        if ($this->cacheDirty) {
            $item = $this->cache->getItem(self::CACHE_KEY);
            $item->set($this->cacheEntries);
            $this->cache->save($item);
        }
    }

    /**
     * Derives the blog's permalink pattern from its configuration.
     *
     * Pure by design: the resolver and the route listener both read the pattern,
     * the listener before any resolver exists, so the derivation must not depend
     * on either one's state or the two would be able to disagree.
     */
    public static function permalinkFor(Module $module): string
    {
        $permalink = $module->config('permalink.type');

        if ($permalink === 'custom') {
            $permalink = $module->config('permalink.custom');
        }

        return is_string($permalink) ? $permalink : '';
    }

    protected function addCache(Post $post): void
    {
        $date = $post->date;
        $this->cacheEntries[$post->id] = [
            'id' => $post->id,
            'slug' => $post->slug,
            'year' => $date?->format('Y') ?? '',
            'month' => $date?->format('m') ?? '',
            'day' => $date?->format('d') ?? '',
            'hour' => $date?->format('H') ?? '',
            'minute' => $date?->format('i') ?? '',
            'second' => $date?->format('s') ?? '',
        ];

        $this->cacheDirty = true;
    }
}
