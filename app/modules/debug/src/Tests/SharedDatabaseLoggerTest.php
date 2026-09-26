<?php

declare(strict_types=1);

namespace Pagekit\Debug\Tests;

use Pagekit\Application;
use Pagekit\Database\Connection;
use Pagekit\Debug\DataCollector\DatabaseDataCollector;
use Pagekit\Debug\DebugBar;
use Pagekit\Debug\Middleware\DebugDriver;
use Pagekit\Debug\Middleware\DebugLogger;
use Pagekit\Debug\Middleware\DebugMiddleware;
use Pagekit\Event\EventDispatcher;
use Pagekit\Module\Loader\ModuleLoader;
use Pagekit\Module\Module;
use Pagekit\Routing\Loader\RoutesLoader;
use Pagekit\Routing\Router;
use Pagekit\Routing\Routes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * One debug middleware is registered before the bar exists, and every
 * connection writes to the logger the bar later reads.
 */
final class SharedDatabaseLoggerTest extends TestCase
{
    private const ONE = 'SELECT 11';

    private const TWO = 'SELECT 22';

    private string $workspace = '';

    private ?Application $app = null;

    private ?Connection $one = null;

    private ?Connection $two = null;

    private ?DebugLogger $logger = null;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/pagekit-debug-logger-'.uniqid('', true);
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->one?->close();
        $this->two?->close();
        $this->one = null;
        $this->two = null;

        if (!is_dir($this->workspace)) {
            return;
        }

        foreach (scandir($this->workspace) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->workspace.'/'.$entry;

            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($this->workspace);
    }

    public function testEveryConnectionSharesTheLoggerWhenTheBarIsOff(): void
    {
        $this->boot(false);

        $this->assertFalse($this->app()->has('debugbar'));
        $this->assertConnectionsShareTheRegisteredLogger();
    }

    public function testTheDebugBarReadsThatSameLogger(): void
    {
        $this->boot(true);

        $bar = $this->app()->get('debugbar');
        $this->assertInstanceOf(DebugBar::class, $bar);
        $this->assertTrue($bar->hasCollector('database'));

        $collector = $bar->getCollector('database');
        $this->assertInstanceOf(DatabaseDataCollector::class, $collector);

        $held = (new \ReflectionProperty(DatabaseDataCollector::class, 'logger'))->getValue($collector);
        $this->assertSame($this->logger(), $held);

        $this->assertConnectionsShareTheRegisteredLogger();
        $this->assertStringContainsString(self::ONE, $this->collectedSql($collector));
        $this->assertStringContainsString(self::TWO, $this->collectedSql($collector));
    }

    private function assertConnectionsShareTheRegisteredLogger(): void
    {
        $app = $this->app();
        $middlewares = $app->get('db.middlewares');
        $this->assertIsArray($middlewares);
        $this->assertCount(1, $middlewares);

        $middleware = $middlewares[0];
        $this->assertInstanceOf(DebugMiddleware::class, $middleware);
        $this->assertSame($middleware, $app->get('db.debug_middleware'));
        $this->assertSame($this->logger(), $this->loggerOf($middleware));
        $this->assertSame($this->logger(), $this->loggerOn($this->connectionOne()));
        $this->assertSame($this->logger(), $this->loggerOn($this->connectionTwo()));

        $this->connectionOne()->executeStatement(self::ONE);
        $this->connectionTwo()->executeStatement(self::TWO);

        $sql = $this->loggedSql();
        $this->assertStringContainsString(self::ONE, $sql);
        $this->assertStringContainsString(self::TWO, $sql);
    }

    private function boot(bool $bar): void
    {
        $this->app = new Application();
        $this->loadDebug($bar);
        $this->loadDatabase();

        if ($bar) {
            $app = $this->app();
            $app->set('path', $this->workspace);
            $app->set('path.cache', $this->workspace);
            $app->set('routes', new Routes());
            $app->set('router', new Router(
                new Routes(),
                new RoutesLoader(new EventDispatcher()),
                new RequestStack(),
            ));
            $app->boot();
        }

        $dbs = $this->app()->get('dbs');
        $this->assertIsArray($dbs);

        $one = $dbs['one'] ?? null;
        $two = $dbs['two'] ?? null;
        $this->assertInstanceOf(Connection::class, $one);
        $this->assertInstanceOf(Connection::class, $two);
        $this->one = $one;
        $this->two = $two;

        $logger = $this->app()->get('db.debug_logger');
        $this->assertInstanceOf(DebugLogger::class, $logger);
        $this->logger = $logger;
    }

    private function loadDebug(bool $bar): void
    {
        $directory = dirname(__DIR__, 2);
        $definition = require $directory.'/index.php';
        $this->assertIsArray($definition);

        $main = $definition['main'] ?? null;
        $name = $definition['name'] ?? null;
        $events = $definition['events'] ?? [];
        $this->assertInstanceOf(\Closure::class, $main);
        $this->assertIsString($name);
        $this->assertIsArray($events);

        (new ModuleLoader($this->app()))->load([
            'name' => $name,
            'path' => $directory,
            'config' => [
                'enabled' => $bar,
                'file' => $bar ? 'sqlite:'.$this->workspace.'/debugbar.db' : null,
            ],
            'main' => $main,
            'events' => $events,
        ]);
    }

    private function loadDatabase(): void
    {
        $directory = dirname(__DIR__, 3).'/database';
        $definition = require $directory.'/index.php';
        $this->assertIsArray($definition);
        $main = $definition['main'] ?? null;
        $this->assertInstanceOf(\Closure::class, $main);

        (new Module([
            'name' => 'database',
            'path' => $directory,
            'config' => [
                'default' => 'one',
                'connections' => [
                    'one' => [
                        'driver' => 'pdo_sqlite',
                        'memory' => true,
                    ],
                    'two' => [
                        'driver' => 'pdo_sqlite',
                        'memory' => true,
                    ],
                ],
            ],
            'main' => $main,
        ]))->main($this->app());
    }

    private function loggerOf(DebugMiddleware $middleware): DebugLogger
    {
        $logger = (new \ReflectionProperty(DebugMiddleware::class, 'logger'))->getValue($middleware);
        $this->assertInstanceOf(DebugLogger::class, $logger);

        return $logger;
    }

    private function loggerOn(Connection $connection): DebugLogger
    {
        $driver = $connection->getDriver();
        $this->assertInstanceOf(DebugDriver::class, $driver);

        $logger = (new \ReflectionProperty(DebugDriver::class, 'logger'))->getValue($driver);
        $this->assertInstanceOf(DebugLogger::class, $logger);

        return $logger;
    }

    private function loggedSql(): string
    {
        $sql = [];

        foreach ($this->logger()->queries as $query) {
            $statement = $query['sql'] ?? null;

            if (is_string($statement)) {
                $sql[] = $statement;
            }
        }

        return implode("\n", $sql);
    }

    private function collectedSql(DatabaseDataCollector $collector): string
    {
        $collected = $collector->collect();
        $statements = $collected['statements'] ?? null;
        $this->assertIsArray($statements);

        $sql = [];

        foreach ($statements as $statement) {
            $this->assertIsArray($statement);
            $query = $statement['sql'] ?? null;

            if (is_string($query)) {
                $sql[] = $query;
            }
        }

        return implode("\n", $sql);
    }

    private function app(): Application
    {
        $this->assertInstanceOf(Application::class, $this->app);

        return $this->app;
    }

    private function connectionOne(): Connection
    {
        $this->assertInstanceOf(Connection::class, $this->one);

        return $this->one;
    }

    private function connectionTwo(): Connection
    {
        $this->assertInstanceOf(Connection::class, $this->two);

        return $this->two;
    }

    private function logger(): DebugLogger
    {
        $this->assertInstanceOf(DebugLogger::class, $this->logger);

        return $this->logger;
    }
}
