<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\DriverManager;
use Pagekit\Application;
use Pagekit\Database\Connection;
use Pagekit\Module\Module;
use PHPUnit\Framework\TestCase;

/**
 * Container-held DBAL middlewares are appended after a connection's own.
 * An absent list adds nothing, and an entry that is not a middleware is not applied.
 */
final class ConnectionMiddlewareTest extends TestCase
{
    private string $rawDriver;

    /** @var list<Connection> */
    private array $open = [];

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->rawDriver = $connection->getDriver()::class;
        $connection->close();
    }

    protected function tearDown(): void
    {
        foreach ($this->open as $connection) {
            $connection->close();
        }

        $this->open = [];
    }

    public function testAnAbsentMiddlewareServiceLeavesEachConnectionUnwrapped(): void
    {
        $own = new RecordingMiddleware('connection');
        $connections = $this->boot([
            'plain' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ],
            'withOwn' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
                'middlewares' => [$own],
            ],
        ]);

        $this->assertSame($this->rawDriver, $connections['plain']->getDriver()::class);
        $this->assertCount(1, $own->wrapped);
        $this->assertSame($this->rawDriver, $own->wrapped[0]::class);

        $driver = $connections['withOwn']->getDriver();
        $this->assertInstanceOf(NamedDriver::class, $driver);
        $this->assertSame('connection', $driver->name);
    }

    public function testHeldMiddlewaresAreAppendedAfterTheConnectionsOwnAndOtherEntriesAreSkipped(): void
    {
        $own = new RecordingMiddleware('connection');
        $held = new RecordingMiddleware('held');
        $decoy = new MiddlewareDecoy();
        $connections = $this->boot([
            'withOwn' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
                'middlewares' => [$own],
            ],
            'withoutOwn' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ],
        ], ['skip-me', $decoy, $held]);

        $this->assertSame(0, $decoy->calls);
        $this->assertCount(1, $own->wrapped);
        $this->assertSame($this->rawDriver, $own->wrapped[0]::class);
        $this->assertCount(2, $held->wrapped);

        $inner = $held->wrapped[0];
        $this->assertInstanceOf(NamedDriver::class, $inner);
        $this->assertSame('connection', $inner->name);
        $this->assertSame($this->rawDriver, $held->wrapped[1]::class);

        $withOwn = $connections['withOwn']->getDriver();
        $withoutOwn = $connections['withoutOwn']->getDriver();
        $this->assertInstanceOf(NamedDriver::class, $withOwn);
        $this->assertInstanceOf(NamedDriver::class, $withoutOwn);
        $this->assertSame('held', $withOwn->name);
        $this->assertSame('held', $withoutOwn->name);
    }

    public function testTheDatabaseModuleDoesNotNameDebug(): void
    {
        $this->assertSame([], $this->references(dirname(__DIR__, 2), ['Pagekit\\Debug']));
    }

    /**
     * @param array<string, array<string, mixed>> $connections
     * @param list<mixed>|null                    $held         null leaves the service unregistered
     *
     * @return array<string, Connection>
     */
    private function boot(array $connections, ?array $held = null): array
    {
        $app = new Application();

        if ($held !== null) {
            $app->set('db.middlewares', $held);
        }

        $definition = require dirname(__DIR__, 2).'/index.php';
        $this->assertIsArray($definition);
        $main = $definition['main'] ?? null;
        $this->assertInstanceOf(\Closure::class, $main);

        $default = array_key_first($connections);
        $this->assertIsString($default);

        (new Module([
            'name' => 'database',
            'path' => dirname(__DIR__, 2),
            'config' => [
                'default' => $default,
                'connections' => $connections,
            ],
            'main' => $main,
        ]))->main($app);

        $dbs = $app->get('dbs');
        $this->assertIsArray($dbs);

        $opened = [];

        foreach ($dbs as $name => $connection) {
            $this->assertIsString($name);
            $this->assertInstanceOf(Connection::class, $connection);
            $this->open[] = $connection;
            $opened[$name] = $connection;
        }

        return $opened;
    }

    /**
     * @param list<string> $needles
     *
     * @return list<string>
     */
    private function references(string $directory, array $needles): array
    {
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = strtr($file->getPathname(), '\\', '/');

            if (preg_match('#/(Tests|vendor|node_modules)/#', $path) === 1) {
                continue;
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                continue;
            }

            foreach ($needles as $needle) {
                if (str_contains($contents, $needle)) {
                    $hits[] = $path.': '.$needle;
                }
            }
        }

        sort($hits);

        return $hits;
    }
}

/**
 * Records the driver it wrapped and returns a driver that names itself.
 */
final class RecordingMiddleware implements Middleware
{
    /** @var list<Driver> */
    public array $wrapped = [];

    public function __construct(private readonly string $name)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $this->wrapped[] = $driver;

        return new NamedDriver($driver, $this->name);
    }
}

/**
 * The outermost wrapper a connection's driver chain ends in.
 */
final class NamedDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, public readonly string $name)
    {
        parent::__construct($driver);
    }
}

/**
 * Has wrap(), but is not a DBAL middleware, so the connection must ignore it.
 */
final class MiddlewareDecoy
{
    public int $calls = 0;

    public function wrap(Driver $driver): Driver
    {
        $this->calls++;

        return $driver;
    }
}
