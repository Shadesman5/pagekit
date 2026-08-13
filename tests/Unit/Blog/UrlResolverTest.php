<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Blog;

use Pagekit\Blog\Model\Post;
use Pagekit\Blog\Model\PostRepository;
use Pagekit\Blog\UrlResolver;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Module\Module;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Covers UrlResolver: match()/generate() resolve an unknown slug/id through the
 * injected {@see PostRepository}, a cached entry answers without touching it,
 * and permalinkFor() derives the pattern both this resolver and the blog's
 * route listener build post URLs from.
 *
 * The resolver takes its cache pool, blog module and repository through the
 * constructor, so every test builds its own instance from mocks and the
 * delegation is asserted with no database.
 */
class UrlResolverTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    // -----------------------------------------------------------------------
    // match(): slug -> id resolution through the injected repository.
    // -----------------------------------------------------------------------

    public function testMatchReturnsParametersUnchangedWhenIdAlreadyPresent(): void
    {
        $parameters = ['id' => 5, 'extra' => 'kept'];

        $this->assertSame($parameters, $this->resolver()->match($parameters));
    }

    public function testMatchThrowsNotFoundWhenNeitherIdNorSlugGiven(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->resolver()->match([]);
    }

    public function testMatchResolvesSlugThroughRepositoryAndReturnsItsId(): void
    {
        $post = new Post();
        $post->id = 5;
        $post->slug = 'hello';
        $post->date = new \DateTime('2024-01-02 03:04:05');

        $result = $this->resolver(posts: $this->repositoryReturning(['slug' => 'hello'], $post))
            ->match(['slug' => 'hello']);

        $this->assertSame(5, $result['id'], 'the slug must be resolved to the post id via the repository');
        $this->assertSame('hello', $result['slug']);
    }

    public function testMatchThrowsNotFoundWhenNoPostMatchesTheSlug(): void
    {
        $resolver = $this->resolver(posts: $this->repositoryReturning(['slug' => 'ghost'], null));

        $this->expectException(NotFoundHttpException::class);

        $resolver->match(['slug' => 'ghost']);
    }

    // -----------------------------------------------------------------------
    // generate(): id -> parameters resolution through the injected repository.
    // -----------------------------------------------------------------------

    public function testGenerateResolvesIdThroughRepositoryWhenNotCached(): void
    {
        $post = new Post();
        $post->id = 9;
        $post->slug = 'world';
        $post->date = new \DateTime('2024-05-06 07:08:09');

        // The default permalink type is empty -> no attribute expansion, so the
        // id survives; the point is that the repository loaded the post.
        $result = $this->resolver(posts: $this->repositoryReturning(['id' => 9], $post))
            ->generate(['id' => 9]);

        $this->assertSame(9, $result['id']);
    }

    public function testGenerateThrowsRouteNotFoundWhenPostMissing(): void
    {
        $resolver = $this->resolver(posts: $this->repositoryReturning(['id' => 404], null));

        $this->expectException(RouteNotFoundException::class);

        $resolver->generate(['id' => 404]);
    }

    public function testGenerateExpandsPermalinkFromCacheWithoutQueryingRepository(): void
    {
        // A cached entry short-circuits the repository entirely; the custom
        // permalink then expands from the cached meta.
        $repository = $this->createMock(PostRepository::class);
        $repository->expects($this->never())->method('where');

        $result = $this->resolver(
            cache: $this->cachePoolWithEntries([
                9 => ['id' => 9, 'slug' => 'world', 'year' => '2024'],
            ]),
            module: $this->blogModuleWithPermalink('{slug}'),
            posts: $repository,
        )->generate(['id' => 9]);

        $this->assertSame('world', $result['slug'], 'the {slug} permalink token is filled from the cached meta');
        $this->assertArrayNotHasKey('id', $result, 'a matched permalink drops the raw id parameter');
    }

    // -----------------------------------------------------------------------
    // permalinkFor(): the pattern the resolver and the route listener share.
    // -----------------------------------------------------------------------

    /**
     * The URL a post is generated under and the alias path a post URL is matched
     * on are built from the same pattern, which is derived here from the blog's
     * own configuration and from nothing else. A derivation that read state
     * would let the two sides disagree, leaving posts reachable under URLs the
     * site does not link to (or the other way round).
     *
     * @param array<string, string> $permalink
     */
    #[DataProvider('providePermalinkConfigurations')]
    public function testPermalinkForDerivesThePatternFromTheBlogConfiguration(array $permalink, string $expected): void
    {
        $this->assertSame($expected, UrlResolver::permalinkFor($this->blogModule($permalink)));
    }

    /**
     * The permalink settings screen writes the pattern itself into `type` and
     * only switches to `custom` for a hand-written one, so `type` is either a
     * pattern, the literal `custom`, or empty for numeric post URLs. The last
     * two cases are configuration that was never fully written, which reading a
     * pattern out of must answer rather than fail: this runs on every request.
     *
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function providePermalinkConfigurations(): array
    {
        return [
            'numeric post URLs carry no pattern' => [['type' => '', 'custom' => '{slug}'], ''],
            'a preset pattern is the pattern' => [['type' => '{year}/{month}/{slug}', 'custom' => '{slug}'], '{year}/{month}/{slug}'],
            'the custom type hands over to the custom pattern' => [['type' => 'custom', 'custom' => '{year}/{slug}'], '{year}/{slug}'],
            'a custom type with nothing written for it carries no pattern' => [['type' => 'custom'], ''],
            'a blog without permalink configuration carries no pattern' => [[], ''],
        ];
    }

    /**
     * Builds the resolver with its three dependencies, defaulting to an empty
     * cache, the shipped blog config and an unused repository.
     */
    private function resolver(?CacheItemPoolInterface $cache = null, ?Module $module = null, ?PostRepository $posts = null): UrlResolver
    {
        return new UrlResolver(
            $cache ?? $this->cachePoolWithEntries([]),
            $module ?? $this->blogModule(['type' => '', 'custom' => '{slug}']),
            $posts ?? $this->createMock(PostRepository::class),
        );
    }

    /**
     * Builds a PostRepository mock whose where($condition)->first() answers with
     * the given post (or null), mirroring the `$this->posts->where(...)->first()`
     * chain.
     *
     * @param array<string, mixed> $condition
     */
    private function repositoryReturning(array $condition, ?Post $post): PostRepository
    {
        $query = $this->createMock(QueryBuilder::class);
        $query->method('first')->willReturn($post);

        $repository = $this->createMock(PostRepository::class);
        $repository->method('where')->with($condition)->willReturn($query);

        return $repository;
    }

    /**
     * A PSR-6 pool whose blog.routing item is a hit carrying the given entries,
     * so the resolver constructor pre-populates its cache.
     *
     * @param array<int|string, array<string, mixed>> $entries
     */
    private function cachePoolWithEntries(array $entries): CacheItemPoolInterface
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($entries);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->with(UrlResolver::CACHE_KEY)->willReturn($item);

        return $pool;
    }

    private function blogModuleWithPermalink(string $custom): Module
    {
        return $this->blogModule(['type' => 'custom', 'custom' => $custom]);
    }

    /**
     * @param array<string, string> $permalink
     */
    private function blogModule(array $permalink): Module
    {
        return new Module([
            'name' => 'blog',
            'path' => '',
            'config' => ['permalink' => $permalink],
        ]);
    }
}
