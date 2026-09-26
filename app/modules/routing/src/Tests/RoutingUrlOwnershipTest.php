<?php

declare(strict_types=1);

namespace Pagekit\Routing\Tests;

use Pagekit\Application;
use Pagekit\Event\EventDispatcher;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\Locator;
use Pagekit\Module\Module;
use Pagekit\Routing\Loader\RoutesLoader;
use Pagekit\Routing\Response;
use Pagekit\Routing\Router;
use Pagekit\Routing\Routes;
use Pagekit\Routing\UrlProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The url and response services are the routing classes, and routing does not require application.
 */
class RoutingUrlOwnershipTest extends TestCase
{
    public function testApplicationModuleRegistersTheRoutingUrlServices(): void
    {
        $app = new Application();
        $app->set('router', new Router(new Routes(), new RoutesLoader(new EventDispatcher()), new RequestStack()));
        $app->set('file', new Filesystem());
        $app->set('locator', new Locator($this->root()));

        $this->bootApplicationModule($app);

        $url = $app->get('url');
        $response = $app->get('response');

        $this->assertInstanceOf(UrlProvider::class, $url);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($url, $app->get('url'));
        $this->assertSame($response, $app->get('response'));
        $this->assertSame($url, (new \ReflectionProperty(Response::class, 'url'))->getValue($response));
        $this->assertSame('/site', $response->redirect('site')->getTargetUrl());
        $this->assertFalse(class_exists($this->removedUrlProvider()));
        $this->assertFalse(class_exists($this->removedResponse()));

        $urlFile = (new \ReflectionClass(UrlProvider::class))->getFileName();
        $responseFile = (new \ReflectionClass(Response::class))->getFileName();

        $this->assertIsString($urlFile);
        $this->assertIsString($responseFile);
        $this->assertSame($this->root().'/app/modules/routing/src/UrlProvider.php', strtr($urlFile, '\\', '/'));
        $this->assertSame($this->root().'/app/modules/routing/src/Response.php', strtr($responseFile, '\\', '/'));
    }

    public function testTheOldUrlClassesAreGone(): void
    {
        $this->assertFalse(class_exists($this->removedUrlProvider()));
        $this->assertFalse(class_exists($this->removedResponse()));
        $this->assertFileDoesNotExist($this->root().'/app/modules/application/src/Application/UrlProvider.php');
        $this->assertFileDoesNotExist($this->root().'/app/modules/application/src/Application/Response.php');
        $this->assertFileExists($this->root().'/app/modules/routing/src/UrlProvider.php');
        $this->assertFileExists($this->root().'/app/modules/routing/src/Response.php');
    }

    public function testRoutingDoesNotDependOnApplication(): void
    {
        $routing = $this->manifest('app/modules/routing/index.php');
        $application = $this->manifest('app/modules/application/index.php');
        $routingRequire = $routing['require'];
        $applicationRequire = $application['require'];

        $this->assertIsArray($routingRequire);
        $this->assertContains('kernel', $routingRequire);
        $this->assertContains('filter', $routingRequire);
        $this->assertNotContains('application', $routingRequire);

        $this->assertIsArray($applicationRequire);
        $this->assertContains('routing', $applicationRequire);

        $this->assertSame([], $this->references(
            [$this->root().'/app/modules/routing'],
            ['Pagekit\\Application\\'],
        ));
    }

    public function testProductionCodeDoesNotNameTheOldUrlClasses(): void
    {
        $this->assertSame([], $this->references(
            [$this->root().'/app', $this->root().'/packages'],
            [$this->removedUrlProvider(), $this->removedResponse()],
        ));
    }

    private function bootApplicationModule(Application $app): void
    {
        $display = ini_get('display_errors');
        $errorHandler = $this->peekErrorHandler();
        $exceptionHandler = $this->peekExceptionHandler();
        $reserved = new \ReflectionProperty(ErrorHandler::class, 'reservedMemory');
        $previousMemory = $reserved->getValue(null);

        // Wiring the module registers Symfony's error handler. Seeding the
        // reserved-memory flag skips the process-lifetime fatal handler, and
        // the handlers below are put back before the assertion runs.
        if ($previousMemory === null) {
            $reserved->setValue(null, '');
        }

        try {
            $manifest = $this->manifest('app/modules/application/index.php');
            $name = $manifest['name'];
            $config = $manifest['config'];
            $main = $manifest['main'];

            $this->assertIsString($name);
            $this->assertIsArray($config);
            $this->assertInstanceOf(\Closure::class, $main);

            $module = new Module([
                'name' => $name,
                'path' => $this->root().'/app/modules/application',
                'config' => $config,
                'main' => $main,
            ]);
            $module->main($app);
        } finally {
            if ($previousMemory === null) {
                $reserved->setValue(null, null);
            }

            if (is_string($display)) {
                ini_set('display_errors', $display);
            }

            $this->rewindErrorHandler($errorHandler);
            $this->rewindExceptionHandler($exceptionHandler);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $relative): array
    {
        // ModuleManager::register() binds $app before including a manifest so
        // event closures can capture it. Nothing here calls those closures.
        $app = new Application();
        $loaded = require $this->root().'/'.$relative;

        $this->assertIsArray($loaded);

        /** @var array<string, mixed> $loaded */
        return $loaded;
    }

    private function removedUrlProvider(): string
    {
        return 'Pagekit\\Application\\'.'UrlProvider';
    }

    private function removedResponse(): string
    {
        return 'Pagekit\\Application\\'.'Response';
    }

    /**
     * @param list<string> $directories
     * @param list<string> $needles
     *
     * @return list<string>
     */
    private function references(array $directories, array $needles): array
    {
        $hits = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = strtr($file->getPathname(), '\\', '/');

                // Test sources name the symbols they assert are absent.
                if (preg_match('#/(Tests|vendor|node_modules)/#', $path) === 1) {
                    continue;
                }

                $contents = file_get_contents($path);

                if ($contents === false) {
                    continue;
                }

                foreach ($needles as $needle) {
                    if (str_contains($contents, $needle)) {
                        $hits[] = substr($path, strlen($this->root()) + 1).': '.$needle;
                    }
                }
            }
        }

        sort($hits);

        return $hits;
    }

    private function peekErrorHandler(): ?callable
    {
        $handler = set_error_handler(static fn (int $severity, string $message, string $file, int $line): bool => false);
        restore_error_handler();

        return $handler;
    }

    private function peekExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(static function (\Throwable $exception): void {
        });
        restore_exception_handler();

        return $handler;
    }

    private function rewindErrorHandler(?callable $expected): void
    {
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            if ($this->peekErrorHandler() === $expected) {
                return;
            }

            restore_error_handler();
        }

        $this->fail('The application module left a different error handler installed.');
    }

    private function rewindExceptionHandler(?callable $expected): void
    {
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            if ($this->peekExceptionHandler() === $expected) {
                return;
            }

            restore_exception_handler();
        }

        $this->fail('The application module left a different exception handler installed.');
    }

    private function root(): string
    {
        return dirname(__DIR__, 5);
    }
}
