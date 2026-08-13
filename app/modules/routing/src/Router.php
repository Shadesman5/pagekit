<?php

declare(strict_types=1);

namespace Pagekit\Routing;

use Pagekit\Filesystem\Filesystem;
use Pagekit\Routing\Generator\CompiledUrlGenerator;
use Pagekit\Routing\Generator\LinkReferenceType;
use Pagekit\Routing\Generator\UrlGenerator;
use Pagekit\Routing\Loader\LoaderInterface;
use Pagekit\Routing\RequestContext as Context;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

class Router implements RouterInterface, LinkReferenceType
{
    protected ResourceInterface $resource;

    protected LoaderInterface $loader;

    protected RequestStack $stack;

    protected RequestContext $context;

    protected Filesystem $files;

    protected ?UrlMatcher $matcher = null;

    protected ?UrlGenerator $generator = null;

    protected ?RouteCollection $routes = null;

    /** @var array<string, mixed> */
    protected array $options;

    /** @var array<string, mixed>|null */
    protected ?array $cache = null;

    /**
     * @var ParamsResolverInterface[]
     */
    protected array $resolver = [];

    /**
     * @var array<string, callable(): ParamsResolverInterface>
     */
    protected array $resolverFactories = [];

    /**
     * Constructor.
     *
     * @param ResourceInterface    $resource
     * @param LoaderInterface      $loader
     * @param RequestStack         $stack
     * @param array<string, mixed> $options
     * @param Filesystem           $files    Writer for the dumped matcher/generator cache
     */
    public function __construct(ResourceInterface $resource, LoaderInterface $loader, RequestStack $stack, array $options = [], Filesystem $files = new Filesystem())
    {
        $this->resource = $resource;
        $this->loader = $loader;
        $this->stack = $stack;
        $this->files = $files;
        $this->context = new Context();
        $this->options = array_replace([
            'cache' => null,
            'matcher' => 'Symfony\Component\Routing\Matcher\UrlMatcher',
            'generator' => 'Pagekit\Routing\Generator\UrlGenerator',
        ], $options);
    }

    /**
     * Get the current request.
     */
    public function getRequest(): ?Request
    {
        return $this->stack->getCurrentRequest();
    }

    /**
     * {@inheritdoc}
     */
    public function getContext(): RequestContext
    {
        return $this->context;
    }

    /**
     * {@inheritdoc}
     */
    public function setContext(RequestContext $context): void
    {
        $this->context = $context;
    }

    /**
     * Gets the router's options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Sets router's the options.
     *
     * @param array<string, mixed> $options
     */
    public function setOptions($options): void
    {
        $this->options = $options;
    }

    /**
     * Set an router's option.
     *
     * @param string $name
     * @param mixed  $value
     */
    public function setOption(string $name, mixed $value): void
    {
        $this->options[$name] = $value;
    }

    /**
     * Gets a route.
     *
     * @param  string $name
     */
    public function getRoute(string $name): ?Route
    {
        return $this->getRouteCollection()->get($name);
    }

    /**
     * {@inheritdoc}
     */
    public function getRouteCollection(): RouteCollection
    {
        if (!$this->routes) {
            $this->routes = $this->loader->load($this->resource);
            if ($this->routes === null) {
                throw new \RuntimeException('Router: loader returned null for route collection.');
            }
        }

        return $this->routes;
    }

    /**
     * Gets the URL matcher instance.
     */
    public function getMatcher(): UrlMatcher
    {
        if ($this->matcher) {
            return $this->matcher;
        }

        if (!$cache = $this->getCache('%s/%s.matcher.cache')) {
            return $this->matcher = $this->createMatcher();
        }

        try {
            if (!$cache['fresh']) {
                $this->writeCache($cache['file'], (new CompiledUrlMatcherDumper($this->getRouteCollection()))->dump());
            }

            return $this->matcher = new CompiledUrlMatcher($this->readCache($cache['file']), $this->context);

        } catch (\Throwable $e) {
            // A concurrent request may have left a partial/corrupted cache file (rapid
            // route changes regenerate the dump). Never fail the request over a bad cache
            // file - fall back to the non-cached matcher.
            return $this->matcher = $this->createMatcher();
        }
    }

    /**
     * Gets the UrlGenerator instance associated with this Router.
     */
    public function getGenerator(): UrlGenerator
    {
        if ($this->generator) {
            return $this->generator;
        }

        if (!$cache = $this->getCache('%s/%s.generator.cache')) {
            return $this->generator = $this->createGenerator();
        }

        try {
            if (!$cache['fresh']) {
                $this->writeCache($cache['file'], CompiledUrlGenerator::dump($this->getRouteCollection()));
            }

            /** @var array<string, array<int, mixed>> $compiledRoutes Shape written by dump() */
            $compiledRoutes = $this->readCache($cache['file']);

            return $this->generator = new CompiledUrlGenerator($compiledRoutes, $this->context);

        } catch (\Throwable $e) {
            // A concurrent request may have left a partial/corrupted cache file (rapid
            // route changes regenerate the dump). Never fail the request over a bad cache
            // file - fall back to the non-cached generator.
            return $this->generator = $this->createGenerator();
        }
    }

    /**
     * Builds the matcher that reads the route collection directly, used
     * whenever the cache is off or unusable.
     */
    protected function createMatcher(): UrlMatcher
    {
        $class = $this->resolveMatcherClass();

        return new $class($this->getRouteCollection(), $this->context);
    }

    /**
     * Builds the generator that reads the route collection directly, used
     * whenever the cache is off or unusable.
     */
    protected function createGenerator(): UrlGenerator
    {
        $class = $this->resolveGeneratorClass();

        return new $class($this->getRouteCollection(), $this->context);
    }

    /**
     * Reads dumped route data back from a cache file.
     *
     * A file that cannot serve as a cache leaves the caller a throwable to
     * degrade on: a missing one would otherwise be an uncatchable fatal from
     * require, and an empty or replaced one returns something that is not route
     * data. A truncated file raises a catchable ParseError by itself.
     *
     * @return array<array-key, mixed>
     */
    private function readCache(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('Route cache file "%s" is missing.', $file));
        }

        // Use require (not require_once): the file is read for what it returns,
        // and require_once returns true for a path it has already evaluated.
        $data = require $file;

        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('Route cache file "%s" does not hold dumped route data.', $file));
        }

        return $data;
    }

    /**
     * Resolves the configured collection-backed matcher class.
     *
     * @return class-string<UrlMatcher>
     */
    protected function resolveMatcherClass(): string
    {
        $class = $this->options['matcher'];

        if (!is_string($class) || !is_subclass_of($class, UrlMatcher::class) && $class !== UrlMatcher::class) {
            throw new \LogicException(sprintf('Router option "matcher" must be a class-string of %s.', UrlMatcher::class));
        }

        return $class;
    }

    /**
     * Resolves the configured collection-backed generator class.
     *
     * @return class-string<UrlGenerator>
     */
    protected function resolveGeneratorClass(): string
    {
        $class = $this->options['generator'];

        if (!is_string($class) || !is_subclass_of($class, UrlGenerator::class) && $class !== UrlGenerator::class) {
            throw new \LogicException(sprintf('Router option "generator" must be a class-string of %s.', UrlGenerator::class));
        }

        return $class;
    }

    /**
     * Returns a redirect response.
     *
     * @param string                $url
     * @param array<string, mixed>  $parameters
     * @param int                   $status
     * @param array<string, string> $headers
     */
    public function redirect($url = '', $parameters = [], $status = 302, $headers = []): RedirectResponse
    {
        try {

            $url = $this->generate($url, $parameters);

        } catch (RouteNotFoundException $e) {

            if (filter_var($url, FILTER_VALIDATE_URL) === false && strpos($url, '/') !== 0) {
                $request = $this->getRequest();
                $url = $request !== null ? "{$request->getBaseUrl()}/$url" : "/$url";
            }
        }

        return new RedirectResponse($url, $status, $headers);
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function match(string $pathinfo): array
    {
        $request = $this->getRequest();
        if ($request !== null) {
            $this->context->fromRequest($request);
        }

        $params = $this->getMatcher()->match($pathinfo);

        if ($resolver = $this->getResolver($params)) {
            $params = $resolver->match($params);
        }

        if (false !== $pos = strpos($params['_route'], '?')) {
            $params['_route'] = substr($params['_route'], 0, $pos);
        }

        return $params;
    }

    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $parameters
     */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        $generator = $this->getGenerator();

        $fragment = '';
        if (false !== ($hashPos = strpos($name, '#'))) {
            $fragment = substr($name, $hashPos);
            $name = substr($name, 0, $hashPos);
        }

        if (false !== ($queryPos = strpos($name, '?'))) {
            $query = substr($name, $queryPos + 1);
            $name = substr($name, 0, $queryPos);
            if ($query !== '') {
                parse_str($query, $params);
                $stringKeyed = [];
                foreach ($params as $key => $value) {
                    $stringKeyed[(string) $key] = $value;
                }
                $parameters = array_replace($parameters, $stringKeyed);
            }
        }

        if ($referenceType !== self::LINK_URL) {
            // Try to get route properties directly first (without generating)
            // This allows resolvers to transform parameters before URL generation
            if ($props = $generator->getRouteProperties($name)) {
                if ($resolver = $this->getResolver($props[1])) {
                    $parameters = $resolver->generate($parameters);
                }
            }
        }

        return $generator->generate($name, $parameters, $referenceType).$fragment;
    }

    /**
     * Gets cache info.
     *
     * @param  string $file
     * @return array<string, mixed>|null
     */
    protected function getCache(string $file): ?array
    {
        if (!$this->options['cache']) {
            return null;
        }

        $currentKey = $this->cacheKey();

        if ($currentKey === null) {
            // No key means no way to tell a dump apart from a stale one, so
            // there is nothing safe to read or write. The request runs off the
            // route collection, the same degradation an unusable cache file
            // gets.
            return null;
        }

        if (!$this->cache || $this->cache['key'] !== $currentKey) {
            $this->cache = ['key' => $currentKey];
            // Invalidate cached matcher/generator/routes when cache key changes
            $this->matcher = null;
            $this->generator = null;
            $this->routes = null;
        }

        // A file under the current key was written for exactly these routes, so
        // its existence is its freshness. Files under superseded keys are left
        // for the cache clear to sweep.
        $file = sprintf($file, $this->options['cache'], $this->cache['key']);
        $fresh = file_exists($file);

        return array_merge(compact('fresh', 'file'), $this->cache);
    }

    /**
     * Identity of the routes a dump would hold, or null where it cannot be taken.
     *
     * Everything that can change the dumped routes goes into the key, so a
     * change produces a different file instead of a file whose age has to be
     * compared to something:
     *
     * - the resource carries the declared routes,
     * - the options carry route-affecting module state (e.g. the blog module
     *   sets "blog.permalink", which adds permalink alias routes during
     *   route.configure - a stale dump would keep serving the routes of the
     *   previous permalink type and break every post URL),
     * - the resource's modified marker is the only signal for routes derived
     *   from controller attributes, which the loader expands from files this
     *   key never sees.
     *
     * Dating the dump by its own mtime instead would tie the cache to clocks:
     * a deployment that resets file times, or a production opcache that does
     * not revalidate timestamps, leaves a dump that is stale but looks
     * current. A key cannot be wrong about that.
     *
     * Reading the two of them can fail, though - a closure or a connection
     * among the options or on the resource has no serialized form - and
     * routing is not something a router may refuse to do. An unreadable
     * identity turns the cache off for the request instead.
     */
    private function cacheKey(): ?string
    {
        try {
            return sha1(serialize($this->resource).serialize($this->options).$this->resource->getModified());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Writes cache file.
     *
     * @param  string $file
     * @param  string $content
     * @throws \RuntimeException
     */
    protected function writeCache(string $file, string $content): void
    {
        // The write has to land in one step: concurrent requests (e.g. rapid page drag &
        // drop, where every reorder changes the route collection and regenerates the
        // dump) would otherwise read a cache file that is not the route data it is
        // supposed to be yet. Where the platform cannot deliver that, getMatcher()/
        // getGenerator() still degrade safely to the non-cached path.
        $this->files->dumpAtomic($file, $content);
    }

    /**
     * Registers how to build a resolver class.
     *
     * Routes name their resolver as a class string, so a resolver with
     * constructor dependencies cannot be built from that name alone. The module
     * that owns the resolver registers a factory for it here; resolvers without
     * dependencies need none.
     *
     * @param class-string<ParamsResolverInterface> $class
     * @param callable(): ParamsResolverInterface   $factory
     */
    public function addResolver(string $class, callable $factory): void
    {
        $this->resolverFactories[$class] = $factory;
    }

    /**
     * Gets resolver instance from parameters.
     *
     * @param array<string, mixed> $parameters
     */
    protected function getResolver(array $parameters = []): ?ParamsResolverInterface
    {
        $resolver = $parameters['_resolver'] ?? null;

        if (!is_string($resolver) || !is_subclass_of($resolver, ParamsResolverInterface::class)) {
            return null;
        }

        if (!isset($this->resolver[$resolver])) {
            $factory = $this->resolverFactories[$resolver] ?? null;
            $this->resolver[$resolver] = $factory !== null ? $factory() : new $resolver();
        }

        return $this->resolver[$resolver];
    }
}
