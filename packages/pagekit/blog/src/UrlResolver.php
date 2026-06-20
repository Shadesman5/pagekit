<?php

declare(strict_types=1);

namespace Pagekit\Blog;

use Pagekit\Blog\Model\Post;
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

    // Static service references set during blog module boot,
    // required because Router instantiates resolvers via `new $class` (no DI).
    // TODO: Must be refactored in Step 2.1.6 (PHPStan Level 7→8 — Strict Typing) —
    // replace static setters with proper DI; blocked until the Router stops instantiating resolvers via `new $class`.
    private static ?CacheItemPoolInterface $cache = null;
    private static ?Module $module = null;

    public static function setCache(?CacheItemPoolInterface $cache): void
    {
        self::$cache = $cache;
    }

    public static function setModule(Module $module): void
    {
        self::$module = $module;
    }

    /**
     * Constructor.
     */
    public function __construct()
    {
        if (self::$cache !== null) {
            $item = self::$cache->getItem(self::CACHE_KEY);
            $this->cacheEntries = $item->isHit() ? ($item->get() ?: []) : [];
        } else {
            $this->cacheEntries = [];
        }
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

            if (!$post = Post::where(compact('slug'))->first()) {
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

            if (!$post = Post::where(compact('id'))->first()) {
                throw new RouteNotFoundException('Post not found!');
            }

            $this->addCache($post);
        }

        $meta = $this->cacheEntries[$id];

        $permalink = self::getPermalink();

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
        if ($this->cacheDirty && self::$cache !== null) {
            $item = self::$cache->getItem(self::CACHE_KEY);
            $item->set($this->cacheEntries);
            self::$cache->save($item);
        }
    }

    /**
     * Gets the blog's permalink setting.
     */
    public static function getPermalink(): string
    {
        if (self::$module === null) {
            return '';
        }

        $permalink = self::$module->config('permalink.type');

        if ($permalink == 'custom') {
            $permalink = self::$module->config('permalink.custom');
        }

        return $permalink;
    }

    protected function addCache(Post $post): void
    {
        $this->cacheEntries[$post->id] = [
            'id' => $post->id,
            'slug' => $post->slug,
            'year' => $post->date->format('Y'),
            'month' => $post->date->format('m'),
            'day' => $post->date->format('d'),
            'hour' => $post->date->format('H'),
            'minute' => $post->date->format('i'),
            'second' => $post->date->format('s'),
        ];

        $this->cacheDirty = true;
    }
}
