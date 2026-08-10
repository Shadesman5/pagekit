<?php

declare(strict_types=1);

namespace Pagekit\Routing;

use Pagekit\Filesystem\Filesystem;
use Pagekit\Routing\Generator\LinkReferenceType;
use Pagekit\Routing\Generator\UrlGenerator;
use Pagekit\Routing\Generator\UrlGeneratorDumper;
use Pagekit\Routing\Loader\LoaderInterface;
use Pagekit\Routing\Matcher\Dumper\PhpMatcherDumper;
use Pagekit\Routing\RequestContext as Context;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
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

        $baseClass = $this->resolveMatcherClass();

        if (!$cache = $this->getCache('%s/%s.matcher.cache')) {
            return $this->matcher = new $baseClass($this->getRouteCollection(), $this->context);
        }

        $class = sprintf('UrlMatcher%s', $cache['key']);

        try {
            if (!class_exists($class, false)) {
                if (!$cache['fresh'] || !is_file($cache['file'])) {
                    $this->writeCache($cache['file'], (new PhpMatcherDumper($this->getRouteCollection()))->dump([
                        'class' => $class,
                        'base_class' => $baseClass,
                    ]));
                }

                // Use require (not require_once): a freshly written file must always be
                // evaluated, even if a previous attempt this request loaded a stale path.
                require $cache['file'];
            }

            return $this->matcher = $this->instantiateMatcher($class, $this->context);

        } catch (\Throwable $e) {
            // A concurrent request may have left a partial/corrupted cache file (rapid
            // route changes regenerate the dump). Never fail the request over a bad cache
            // file - fall back to the non-cached matcher.
            return $this->matcher = new $baseClass($this->getRouteCollection(), $this->context);
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

        $baseClass = $this->resolveGeneratorClass();

        if (!$cache = $this->getCache('%s/%s.generator.cache')) {
            return $this->generator = new $baseClass($this->getRouteCollection(), $this->context);
        }

        $class = sprintf('UrlGenerator%s', $cache['key']);

        try {
            if (!class_exists($class, false)) {
                if (!$cache['fresh'] || !is_file($cache['file'])) {
                    $this->writeCache($cache['file'], (new UrlGeneratorDumper($this->getRouteCollection()))->dump([
                        'class' => $class,
                        'base_class' => $baseClass,
                    ]));
                }

                // Use require (not require_once): a freshly written file must always be
                // evaluated, even if a previous attempt this request loaded a stale path.
                require $cache['file'];
            }

            return $this->generator = $this->instantiateGenerator($class, $this->context);

        } catch (\Throwable $e) {
            // A concurrent request may have left a partial/corrupted cache file (rapid
            // route changes regenerate the dump). Never fail the request over a bad cache
            // file - fall back to the non-cached generator.
            return $this->generator = new $baseClass($this->getRouteCollection(), $this->context);
        }
    }

    /**
     * Instantiates a dumped matcher subclass. The dumped class overrides the
     * Symfony UrlMatcher constructor to take only the RequestContext (routes
     * are inlined), so we go through reflection to avoid PHPStan asserting the
     * parent signature.
     */
    protected function instantiateMatcher(string $class, RequestContext $context): UrlMatcher
    {
        if (!class_exists($class)) {
            throw new \LogicException(sprintf('Cached matcher class "%s" does not exist.', $class));
        }

        $instance = (new \ReflectionClass($class))->newInstance($context);

        if (!$instance instanceof UrlMatcher) {
            throw new \LogicException(sprintf('Cached matcher class "%s" must extend %s.', $class, UrlMatcher::class));
        }

        return $instance;
    }

    /**
     * Instantiates a dumped generator subclass. The dumped class overrides the
     * Pagekit UrlGenerator constructor to take only the RequestContext (routes
     * are inlined), so we go through reflection to avoid PHPStan asserting the
     * parent signature.
     */
    protected function instantiateGenerator(string $class, RequestContext $context): UrlGenerator
    {
        if (!class_exists($class)) {
            throw new \LogicException(sprintf('Cached generator class "%s" does not exist.', $class));
        }

        $instance = (new \ReflectionClass($class))->newInstance($context);

        if (!$instance instanceof UrlGenerator) {
            throw new \LogicException(sprintf('Cached generator class "%s" must extend %s.', $class, UrlGenerator::class));
        }

        return $instance;
    }

    /**
     * Resolves the configured base matcher class.
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
     * Resolves the configured base generator class.
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

        // All router options participate in the cache key. Modules influence the
        // generated route collection through options (e.g. the blog module sets
        // "blog.permalink", which adds permalink alias routes during route.configure).
        // Such options MUST invalidate the dumped matcher/generator, otherwise the
        // router keeps serving routes built for a different permalink type and URL
        // generation fails (e.g. switching the blog permalink to "Numeric" left the
        // stale "{slug}" route cached, breaking every post URL).
        $currentKey = sha1(serialize($this->resource).serialize($this->options));
        $currentModified = $this->resource->getModified();

        // Reset cache if key or modified time has changed (routes were updated)
        if (!$this->cache || $this->cache['key'] !== $currentKey || $this->cache['modified'] !== $currentModified) {
            $this->cache = ['key' => $currentKey, 'modified' => $currentModified];
            // Invalidate cached matcher/generator/routes when cache key changes
            $this->matcher = null;
            $this->generator = null;
            $this->routes = null;
        }

        $file = sprintf($file, $this->options['cache'], $this->cache['key']);
        $fresh = file_exists($file) && (!$this->cache['modified'] || filemtime($file) >= $this->cache['modified']);

        return array_merge(compact('fresh', 'file'), $this->cache);
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
        // dump) would otherwise read a half-written cache file and crash on a missing
        // dumped class. Where the platform cannot deliver that, getMatcher()/
        // getGenerator() still degrade safely to the non-cached path.
        $this->files->dumpAtomic($file, $content);
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
            $this->resolver[$resolver] = new $resolver();
        }

        return $this->resolver[$resolver];
    }
}
