<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM;

use Doctrine\DBAL\DriverManager;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Loader\AttributeLoader;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Database\Tests\ORM\Fixtures\RelationCommentFixture;
use Pagekit\Database\Tests\ORM\Fixtures\RelationPostFixture;
use Pagekit\Event\EventDispatcherInterface;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for eager-loading relations through
 * {@see \Pagekit\Database\ORM\QueryBuilder::getRelations()}.
 *
 * getRelations() used to build each relation's query via a *static*
 * `$targetEntity::query()`. The EntityManager-DI refactor removed the static
 * Active-Record API from ModelTrait, so every `related(...)->get()` eager-load
 * threw `LogicException: … does not expose a static query() method` — e.g. the
 * admin blog post list (`GET /api/blog/post`) returned HTTP 500. getRelations()
 * now builds the related query via the injected EntityManager
 * (`getRepository($targetEntity)->query()`).
 *
 * This test pins that behavior end-to-end on in-memory SQLite for both a
 * BelongsTo and a HasMany relation, using plain fixtures that expose no static
 * `query()` — so a regression to the static call fails here.
 */
class QueryBuilderEagerLoadTest extends TestCase
{
    /** @var Connection[] */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];
    }

    public function testRelatedEagerLoadsBelongsToAndHasMany(): void
    {
        [$manager, $connection] = $this->bootSqliteManager();

        $connection->executeStatement("INSERT INTO rel_author (id, name) VALUES (1, 'Ada')");
        $connection->executeStatement("INSERT INTO rel_post (id, title, author_id) VALUES (1, 'Hello', 1)");
        $connection->executeStatement("INSERT INTO rel_comment (id, post_id, body) VALUES (1, 1, 'first'), (2, 1, 'second')");

        $posts = $manager->getRepository(RelationPostFixture::class)
            ->query()
            ->related('author', 'comments')
            ->get();

        $this->assertCount(1, $posts);

        // assertCount above guarantees the single inserted row, so reset() yields it.
        $post = reset($posts);

        // BelongsTo eager-load populated the otherwise-null `author` relation via
        // getRepository()->query() (not a removed static ::query()).
        $this->assertNotNull($post->author);
        $this->assertSame('Ada', $post->author->name);

        // HasMany eager-load populated the otherwise-null `comments` relation the
        // same way.
        $this->assertNotNull($post->comments);
        $bodies = array_map(
            static fn (RelationCommentFixture $comment): ?string => $comment->body,
            array_values($post->comments),
        );
        sort($bodies);
        $this->assertSame(['first', 'second'], $bodies);
    }

    /**
     * Boots a real EntityManager backed by in-memory SQLite and creates the
     * fixture tables (post + author + comment).
     *
     * @return array{EntityManager, Connection}
     */
    private function bootSqliteManager(): array
    {
        $driverConnection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection = new Connection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $driverConnection->getDriver(),
            $driverConnection->getConfiguration()
        );
        $driverConnection->close();

        $this->connections[] = $connection;

        $events = $this->createMock(EventDispatcherInterface::class);

        $metadataManager = new MetadataManager($connection, $events);
        $metadataManager->setLoader(new AttributeLoader());

        $manager = new EntityManager($connection, $metadataManager, $events);

        $connection->executeStatement('CREATE TABLE rel_author (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->executeStatement('CREATE TABLE rel_post (id INTEGER PRIMARY KEY, title TEXT, author_id INTEGER)');
        $connection->executeStatement('CREATE TABLE rel_comment (id INTEGER PRIMARY KEY, post_id INTEGER, body TEXT)');

        return [$manager, $connection];
    }
}
