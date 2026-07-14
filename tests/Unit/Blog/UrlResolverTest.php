<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Blog;

use Pagekit\Blog\Model\Post;
use Pagekit\Blog\Model\PostRepository;
use Pagekit\Blog\UrlResolver;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Module\Module;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Covers UrlResolver after its Step 7 bridge swap: the two `Post::where()` static
 * calls are replaced by the {@see PostRepository} carried on the temporary 2.5
 * bridge (`setPostRepository()`). match()/generate() now resolve an unknown
 * slug/id through the bridged repository, guarded by a LogicException when the
 * bridge was never wired during blog boot.
 *
 * The Router instantiates resolvers via `new $class` with no DI, so the bridge is
 * a set of private statics; each test resets them via reflection (there is no
 * public setter that accepts null for the module) and configures the repository
 * with a mocked QueryBuilder, so the delegation is asserted with no database.
 */
class UrlResolverTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        $this->resetResolverStatics();
    }

    protected function tearDown(): void
    {
        $this->resetResolverStatics();
    }

    // -----------------------------------------------------------------------
    // match(): slug -> id resolution through the bridged repository.
    // -----------------------------------------------------------------------

    public function testMatchReturnsParametersUnchangedWhenIdAlreadyPresent(): void
    {
        $parameters = ['id' => 5, 'extra' => 'kept'];

        $this->assertSame($parameters, (new UrlResolver())->match($parameters));
    }

    public function testMatchThrowsNotFoundWhenNeitherIdNorSlugGiven(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new UrlResolver())->match([]);
    }

    public function testMatchResolvesSlugThroughRepositoryAndReturnsItsId(): void
    {
        $post = new Post();
        $post->id = 5;
        $post->slug = 'hello';
        $post->date = new \DateTime('2024-01-02 03:04:05');

        UrlResolver::setPostRepository($this->repositoryReturning(['slug' => 'hello'], $post));

        $result = (new UrlResolver())->match(['slug' => 'hello']);

        $this->assertSame(5, $result['id'], 'the slug must be resolved to the post id via the repository');
        $this->assertSame('hello', $result['slug']);
    }

    public function testMatchThrowsLogicExceptionWhenRepositoryBridgeWasNeverSet(): void
    {
        // The 2.5 bridge was never wired during blog boot: the null-guard fires
        // instead of a fatal on a null repository.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('UrlResolver post repository is not set');

        (new UrlResolver())->match(['slug' => 'ghost']);
    }

    public function testMatchThrowsNotFoundWhenNoPostMatchesTheSlug(): void
    {
        UrlResolver::setPostRepository($this->repositoryReturning(['slug' => 'ghost'], null));

        $this->expectException(NotFoundHttpException::class);

        (new UrlResolver())->match(['slug' => 'ghost']);
    }

    // -----------------------------------------------------------------------
    // generate(): id -> parameters resolution through the bridged repository.
    // -----------------------------------------------------------------------

    public function testGenerateResolvesIdThroughRepositoryWhenNotCached(): void
    {
        $post = new Post();
        $post->id = 9;
        $post->slug = 'world';
        $post->date = new \DateTime('2024-05-06 07:08:09');

        UrlResolver::setPostRepository($this->repositoryReturning(['id' => 9], $post));

        // No module configured -> empty permalink -> no attribute expansion, so
        // the id survives; the point is that the repository loaded the post.
        $result = (new UrlResolver())->generate(['id' => 9]);

        $this->assertSame(9, $result['id']);
    }

    public function testGenerateThrowsLogicExceptionWhenRepositoryBridgeWasNeverSet(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('UrlResolver post repository is not set');

        (new UrlResolver())->generate(['id' => 9]);
    }

    public function testGenerateThrowsRouteNotFoundWhenPostMissing(): void
    {
        UrlResolver::setPostRepository($this->repositoryReturning(['id' => 404], null));

        $this->expectException(RouteNotFoundException::class);

        (new UrlResolver())->generate(['id' => 404]);
    }

    public function testGenerateExpandsPermalinkFromCacheWithoutQueryingRepository(): void
    {
        // A cached entry short-circuits the repository entirely; the custom
        // permalink then expands from the cached meta.
        UrlResolver::setCache($this->cachePoolWithEntries([
            9 => ['id' => 9, 'slug' => 'world', 'year' => '2024'],
        ]));
        UrlResolver::setModule($this->blogModuleWithPermalink('{slug}'));

        $repository = $this->createMock(PostRepository::class);
        $repository->expects($this->never())->method('where');
        UrlResolver::setPostRepository($repository);

        $result = (new UrlResolver())->generate(['id' => 9]);

        $this->assertSame('world', $result['slug'], 'the {slug} permalink token is filled from the cached meta');
        $this->assertArrayNotHasKey('id', $result, 'a matched permalink drops the raw id parameter');
    }

    /**
     * Builds a PostRepository mock whose where($condition)->first() answers with
     * the given post (or null), mirroring the bridged `self::$posts->where(...)
     * ->first()` chain.
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
        return new Module([
            'name' => 'blog',
            'path' => '',
            'config' => ['permalink' => ['type' => 'custom', 'custom' => $custom]],
        ]);
    }

    /**
     * Clears the temporary 2.5 bridge statics between tests. setModule() has no
     * null-accepting signature, so the private statics are reset by reflection.
     */
    private function resetResolverStatics(): void
    {
        $reflection = new \ReflectionClass(UrlResolver::class);

        foreach (['cache', 'module', 'posts'] as $name) {
            $reflection->getProperty($name)->setValue(null, null);
        }
    }
}
