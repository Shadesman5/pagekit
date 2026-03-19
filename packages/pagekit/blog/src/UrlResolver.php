<?php

declare(strict_types=1);

namespace Pagekit\Blog;

use Pagekit\Blog\Model\Post;
use Pagekit\Module\Module;
use Pagekit\Routing\ParamsResolverInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

class UrlResolver implements ParamsResolverInterface
{
    const CACHE_KEY = 'blog.routing';

    protected bool $cacheDirty = false;

    protected array $cacheEntries;

    // Static service references set during blog module boot,
    // required because Router instantiates resolvers via `new $class` (no DI).
    // TODO: Step 2.1 (Static Analysis) — replace with proper DI once Router supports it
    private static mixed $cache = null;
    private static ?Module $module = null;

    public static function setCache(mixed $cache): void
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
        $this->cacheEntries = self::$cache?->fetch(self::CACHE_KEY) ?: [];
    }

    /**
     * {@inheritdoc}
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
            foreach($matches[1] as $attribute) {
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
            self::$cache->save(self::CACHE_KEY, $this->cacheEntries);
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

    protected function addCache($post): void
    {
        $this->cacheEntries[$post->id] = [
            'id'     => $post->id,
            'slug'   => $post->slug,
            'year'   => $post->date->format('Y'),
            'month'  => $post->date->format('m'),
            'day'    => $post->date->format('d'),
            'hour'   => $post->date->format('H'),
            'minute' => $post->date->format('i'),
            'second' => $post->date->format('s'),
        ];

        $this->cacheDirty = true;
    }
}
