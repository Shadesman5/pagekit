<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests;

use Pagekit\Application;
use Pagekit\Container\ContainerException;
use Pagekit\Database\Connection;
use Pagekit\Module\Module;
use PHPUnit\Framework\TestCase;

/**
 * SQLite file paths must resolve against the application root (`path` on the
 * container), never against getcwd(). A docroot CWD such as `public/` must not
 * place a world-readable database under the webroot.
 */
final class SqlitePathResolutionTest extends TestCase
{
    private string $appRoot;

    private string $previousCwd;

    protected function setUp(): void
    {
        $this->previousCwd = getcwd() ?: '.';
        $this->appRoot = sys_get_temp_dir().'/pagekit-sqlite-path-'.uniqid('', true);
        mkdir($this->appRoot.'/public', 0777, true);
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);

        foreach (glob($this->appRoot.'/*.db') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->appRoot.'/public/*.db') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->appRoot.'/www/*.db') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->appRoot.'/public');
        @rmdir($this->appRoot.'/www');
        @rmdir($this->appRoot);
    }

    public function testRelativeSqlitePathResolvesAgainstApplicationRootEvenWhenCwdIsPublic(): void
    {
        chdir($this->appRoot.'/public');

        $connection = $this->bootSqliteConnection('pagekit.db');

        self::assertSame(
            $this->appRoot.'/pagekit.db',
            $connection->getParams()['path'] ?? null,
            'relative sqlite paths must join the container path, not the public/ CWD',
        );
        self::assertFileDoesNotExist(
            $this->appRoot.'/public/pagekit.db',
            'resolving against CWD would leave the database under the webroot',
        );
    }

    public function testAbsoluteSqlitePathIsLeftUnchanged(): void
    {
        chdir($this->appRoot.'/public');

        // Fixture under the writable temp app root — only pins "path unchanged",
        // not machine-specific layouts like /var/lib or C:\data.
        $absolutePath = $this->appRoot.'/absolute-custom.db';

        $connection = $this->bootSqliteConnection($absolutePath);

        self::assertSame(
            $absolutePath,
            $connection->getParams()['path'] ?? null,
            'absolute sqlite paths must not be prefixed with the application root',
        );
    }

    public function testRelativeSqlitePathUnderPublicIsRejected(): void
    {
        chdir($this->appRoot.'/public');

        $this->assertSqlitePathRejectedUnderPublic(
            fn () => $this->bootSqliteConnection('public/pagekit.db'),
        );
    }

    public function testAbsoluteSqlitePathUnderPublicIsRejected(): void
    {
        chdir($this->appRoot.'/public');

        $this->assertSqlitePathRejectedUnderPublic(
            fn () => $this->bootSqliteConnection($this->appRoot.'/public/evil.db'),
        );
    }

    public function testPathPublicOverrideRejectsAbsolutePathUnderThatRoot(): void
    {
        $altPublic = $this->appRoot.'/www';
        mkdir($altPublic, 0777, true);

        $this->assertSqlitePathRejectedUnderPublic(
            fn () => $this->bootSqliteConnection($altPublic.'/site.db', $altPublic),
        );
    }

    /**
     * Resolving `db` wraps connection setup in nested ContainerExceptions
     * (`db` → `dbs` factory); the public-webroot guard still throws
     * InvalidArgumentException deeper in the previous-chain.
     *
     * @param callable(): mixed $boot
     */
    private function assertSqlitePathRejectedUnderPublic(callable $boot): void
    {
        try {
            $boot();
            $this->fail('Expected ContainerException was not thrown');
        } catch (ContainerException $e) {
            self::assertStringContainsString('Error while retrieving "db"', $e->getMessage());

            $cause = $this->unwrapInvalidArgumentException($e);
            self::assertStringContainsString('must not be under the public webroot', $cause->getMessage());
        }
    }

    /**
     * Walks getPrevious() until InvalidArgumentException is found.
     *
     * @throws \PHPUnit\Framework\AssertionFailedError
     */
    private function unwrapInvalidArgumentException(\Throwable $e): \InvalidArgumentException
    {
        $chain = [];
        $current = $e;

        while ($current !== null) {
            $chain[] = $current::class.': '.$current->getMessage();

            if ($current instanceof \InvalidArgumentException) {
                return $current;
            }

            $current = $current->getPrevious();
        }

        $this->fail(
            'Expected InvalidArgumentException in previous-chain, got: '.implode(' → ', $chain),
        );
    }

    /**
     * Boots the database module with a single SQLite connection and returns it.
     */
    private function bootSqliteConnection(string $path, ?string $pathPublic = null): Connection
    {
        $app = new Application();
        $app->set('path', $this->appRoot);
        if ($pathPublic !== null) {
            $app->set('path.public', $pathPublic);
        }

        $definition = require dirname(__DIR__, 2).'/index.php';

        (new Module([
            'name' => 'database',
            'path' => dirname(__DIR__, 2),
            'config' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => [
                        'driver' => 'pdo_sqlite',
                        'path' => $path,
                        'prefix' => 'pk_',
                    ],
                ],
            ],
            'main' => $definition['main'],
        ]))->main($app);

        $connection = $app->get('db');

        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
