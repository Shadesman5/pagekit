<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Tests;

use Composer\Autoload\ClassLoader;
use Pagekit\Application;
use Pagekit\Application\Console\Application as ConsoleApplication;
use Pagekit\Application\Exception as ApplicationException;
use Pagekit\Application\TrustedProxies;
use Pagekit\Container;
use Pagekit\Event\Event;
use Pagekit\Kernel\HttpKernel;
use Pagekit\Module\Loader\AutoLoader;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Module\UnsatisfiedRequirementException;
use Pagekit\Settings\Controller\SettingsController;
use Pagekit\Twig\TwigLoader;
use Pagekit\Util\Arr;
use Pagekit\View\Asset\FileLocatorAsset;
use Pagekit\View\Event\ResponseListener;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * Pagekit\ and Pagekit\Kernel\ both point at kernel src, so the Application class
 * and Application\Exception share src/Application/ and HttpKernel stays src/HttpKernel.php.
 * Twig is a prefix of view, and settings does not take Pagekit\System\.
 */
final class KernelFoundationOwnershipTest extends TestCase
{
    public function testFoundationTypesResolveFromKernelBesideHttpKernel(): void
    {
        $kernel = $this->manifest('app/modules/kernel/index.php');
        $autoload = $this->autoloadMap($kernel);

        self::assertSame('src', $autoload['Pagekit\\'] ?? null);
        self::assertSame('src', $autoload['Pagekit\\Kernel\\'] ?? null);
        self::assertSame(
            ['app/modules/kernel/index.php'],
            $this->manifestsClaiming('Pagekit\\'),
        );
        self::assertSame(
            ['app/modules/kernel/index.php'],
            $this->manifestsClaiming('Pagekit\\Kernel\\'),
        );

        $psr4 = $this->composerPsr4();

        self::assertSame('app/modules/kernel/src', $psr4['Pagekit\\'] ?? null);
        self::assertSame('app/modules/kernel/src', $psr4['Pagekit\\Kernel\\'] ?? null);

        $loader = $this->moduleLoader($kernel, $this->root() . '/app/modules/kernel');
        $composer = $this->composerLoader();

        // Application\Exception must stay under src/Application/. A prefix of
        // Pagekit\Application\ => src would look for src/Exception.php instead,
        // and would not load the Application class at all.
        $expected = [
            Application::class => 'app/modules/kernel/src/Application.php',
            ApplicationException::class => 'app/modules/kernel/src/Application/Exception.php',
            TrustedProxies::class => 'app/modules/kernel/src/Application/TrustedProxies.php',
            ConsoleApplication::class => 'app/modules/kernel/src/Application/Console/Application.php',
            Container::class => 'app/modules/kernel/src/Container.php',
            Event::class => 'app/modules/kernel/src/Event/Event.php',
            ModuleManager::class => 'app/modules/kernel/src/Module/ModuleManager.php',
            UnsatisfiedRequirementException::class => 'app/modules/kernel/src/Module/UnsatisfiedRequirementException.php',
            Arr::class => 'app/modules/kernel/src/Util/Arr.php',
            HttpKernel::class => 'app/modules/kernel/src/HttpKernel.php',
        ];

        foreach ($expected as $class => $relative) {
            $this->assertResolvedFrom($loader, $class, $relative);
            $this->assertResolvedFrom($composer, $class, $relative);
            $this->assertLoadedFrom($loader, $class, $relative);
        }

        $httpKernel = new \ReflectionClass(HttpKernel::class);

        self::assertSame('Pagekit\\Kernel', $httpKernel->getNamespaceName());
        self::assertSame('HttpKernel', $httpKernel->getShortName());
        self::assertDirectoryDoesNotExist($this->root() . '/app/modules/application/src');

        foreach (['Pagekit\\Application', 'Pagekit\\Event', 'Pagekit\\Module', 'Pagekit\\Util', 'Pagekit\\Kernel', 'Pagekit\\Container'] as $namespace) {
            self::assertSame([], $this->declarationsOutside('app/modules/kernel/src', $namespace), $namespace);
        }
    }

    public function testKernelRequiresNoneOfTheStackAndApplicationRequiresKernel(): void
    {
        $kernel = $this->requireList($this->manifest('app/modules/kernel/index.php'));

        foreach (['application', 'routing', 'database', 'auth', 'session', 'view'] as $module) {
            self::assertNotContains($module, $kernel, $module);
        }

        foreach ($kernel as $module) {
            self::assertFalse(str_starts_with($module, 'system'), $module);
        }

        $application = $this->manifest('app/modules/application/index.php');
        $require = $this->requireList($application);

        self::assertContains('kernel', $require);

        foreach (['debug', 'routing', 'auth', 'config', 'cookie', 'database', 'filesystem', 'log', 'session', 'view'] as $module) {
            self::assertContains($module, $require, $module);
        }

        self::assertArrayNotHasKey('Pagekit\\', $this->autoloadMap($application));
    }

    public function testViewOwnsTwigAndThereIsNoTwigModule(): void
    {
        $view = $this->manifest('app/modules/view/index.php');
        $autoload = $this->autoloadMap($view);

        self::assertSame('src', $autoload['Pagekit\\View\\'] ?? null);
        self::assertSame('src/Twig', $autoload['Pagekit\\Twig\\'] ?? null);
        self::assertNotContains('view/twig', $this->requireList($view));
        self::assertSame([], $this->manifestsNamed('view/twig'));
        self::assertDirectoryDoesNotExist($this->root() . '/app/modules/view/twig');
        self::assertSame(
            ['app/modules/view/index.php'],
            $this->manifestsClaiming('Pagekit\\View\\'),
        );
        self::assertSame(
            ['app/modules/view/index.php'],
            $this->manifestsClaiming('Pagekit\\Twig\\'),
        );

        $psr4 = $this->composerPsr4();

        self::assertSame('app/modules/view/src', $psr4['Pagekit\\View\\'] ?? null);
        self::assertSame('app/modules/view/src/Twig', $psr4['Pagekit\\Twig\\'] ?? null);

        $loader = $this->moduleLoader($view, $this->root() . '/app/modules/view');
        $composer = $this->composerLoader();
        $expected = [
            TwigLoader::class => 'app/modules/view/src/Twig/TwigLoader.php',
            FileLocatorAsset::class => 'app/modules/view/src/Asset/FileLocatorAsset.php',
            ResponseListener::class => 'app/modules/view/src/Event/ResponseListener.php',
        ];

        foreach ($expected as $class => $relative) {
            $this->assertResolvedFrom($loader, $class, $relative);
            $this->assertResolvedFrom($composer, $class, $relative);
            $this->assertLoadedFrom($loader, $class, $relative);
        }

        self::assertSame([], $this->declarationsOutside('app/modules/view/src', 'Pagekit\\View'));
        self::assertSame([], $this->declarationsOutside('app/modules/view/src/Twig', 'Pagekit\\Twig'));
        self::assertDirectoryDoesNotExist($this->root() . '/app/system/modules/view/src');

        $systemView = $this->manifest('app/system/modules/view/index.php');
        $systemAutoload = $this->autoloadMap($systemView);

        self::assertContains('view', $this->requireList($systemView));
        self::assertArrayNotHasKey('Pagekit\\View\\', $systemAutoload);
        self::assertArrayNotHasKey('Pagekit\\Twig\\', $systemAutoload);

        $app = new Application();
        $app->set('debug', false);
        $app->set('path.cache', sys_get_temp_dir());

        $name = $view['name'] ?? null;
        $main = $view['main'] ?? null;

        self::assertIsString($name);
        self::assertSame('view', $name);
        self::assertInstanceOf(\Closure::class, $main);

        $module = new Module([
            'name' => $name,
            'path' => $this->root() . '/app/modules/view',
            'config' => [],
            'main' => $main,
        ]);
        $module->main($app);

        $twig = $app->get('twig');

        self::assertInstanceOf(Environment::class, $twig);
        self::assertInstanceOf(TwigLoader::class, $twig->getLoader());
    }

    public function testSettingsControllerUsesTheSettingsPrefix(): void
    {
        $settings = $this->manifest('app/system/modules/settings/index.php');
        $autoload = $this->autoloadMap($settings);

        self::assertSame('src', $autoload['Pagekit\\Settings\\'] ?? null);
        self::assertArrayNotHasKey('Pagekit\\System\\', $autoload);
        self::assertSame(
            ['app/system/modules/settings/index.php'],
            $this->manifestsClaiming('Pagekit\\Settings\\'),
        );
        self::assertSame([], $this->manifestsClaiming('Pagekit\\System\\'));
        self::assertSame(SettingsController::class, $this->routeController($settings, '/system/settings'));

        $psr4 = $this->composerPsr4();

        self::assertSame('app/system/src', $psr4['Pagekit\\System\\'] ?? null);
        self::assertArrayNotHasKey('Pagekit\\Settings\\', $psr4);
        self::assertFalse($this->composerLoader()->findFile(SettingsController::class));

        $loader = $this->moduleLoader($settings, $this->root() . '/app/system/modules/settings');
        $relative = 'app/system/modules/settings/src/Controller/SettingsController.php';

        $this->assertResolvedFrom($loader, SettingsController::class, $relative);
        $this->assertLoadedFrom($loader, SettingsController::class, $relative);
        self::assertSame(
            'Pagekit\\Settings\\Controller',
            (new \ReflectionClass(SettingsController::class))->getNamespaceName(),
        );
        self::assertFalse(class_exists('Pagekit\\System\\Controller\\SettingsController'));
        self::assertSame([], $this->declarationsOutside('app/system/modules/settings/src', 'Pagekit\\Settings'));
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function moduleLoader(array $manifest, string $moduleDirectory): ClassLoader
    {
        $loader = new ClassLoader();
        $module = $manifest;
        $module['path'] = $moduleDirectory;
        $loaded = (new AutoLoader($loader))->load($module);

        self::assertIsArray($loaded);

        return $loader;
    }

    /**
     * @param class-string $class
     */
    private function assertResolvedFrom(ClassLoader $loader, string $class, string $relative): void
    {
        $file = $loader->findFile($class);

        self::assertIsString($file, $class);
        self::assertSame($this->canonical($this->root() . '/' . $relative), $this->canonical($file), $class);
    }

    /**
     * @param class-string $class
     */
    private function assertLoadedFrom(ClassLoader $loader, string $class, string $relative): void
    {
        if (!class_exists($class, false)) {
            self::assertTrue($loader->loadClass($class) === true, $class);
        }

        $file = (new \ReflectionClass($class))->getFileName();

        self::assertIsString($file);
        self::assertSame($this->canonical($this->root() . '/' . $relative), $this->canonical($file), $class);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $relative): array
    {
        // Manifest closures capture $app from the including scope. They are not called.
        $app = new Application();
        $loaded = require $this->root() . '/' . $relative;

        self::assertIsArray($loaded);

        /** @var array<string, mixed> $loaded */
        return $loaded;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array<string, string>
     */
    private function autoloadMap(array $manifest): array
    {
        $autoload = $manifest['autoload'] ?? [];

        self::assertIsArray($autoload);

        $map = [];

        foreach ($autoload as $prefix => $path) {
            self::assertIsString($prefix);
            self::assertIsString($path);
            $map[$prefix] = $path;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<string>
     */
    private function requireList(array $manifest): array
    {
        $require = $manifest['require'] ?? [];

        self::assertIsArray($require);

        $names = [];

        foreach ($require as $name) {
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function routeController(array $manifest, string $path): string
    {
        $routes = $manifest['routes'] ?? null;

        self::assertIsArray($routes);

        $route = $routes[$path] ?? null;

        self::assertIsArray($route);

        $controller = $route['controller'] ?? null;

        self::assertIsString($controller);

        return $controller;
    }

    /**
     * @return array<string, string>
     */
    private function composerPsr4(): array
    {
        $contents = file_get_contents($this->root() . '/composer.json');

        self::assertIsString($contents);

        $decoded = json_decode($contents, true);

        self::assertIsArray($decoded);

        $autoload = $decoded['autoload'] ?? null;

        self::assertIsArray($autoload);

        $psr4 = $autoload['psr-4'] ?? null;

        self::assertIsArray($psr4);

        $map = [];

        foreach ($psr4 as $prefix => $path) {
            self::assertIsString($prefix);
            self::assertIsString($path);
            $map[$prefix] = $path;
        }

        return $map;
    }

    private function composerLoader(): ClassLoader
    {
        foreach (ClassLoader::getRegisteredLoaders() as $vendorDir => $loader) {
            if (str_ends_with(strtr($vendorDir, '\\', '/'), 'app/vendor')) {
                return $loader;
            }
        }

        self::fail('Composer autoload is not registered.');
    }

    /**
     * @return list<string>
     */
    private function manifestsClaiming(string $prefix): array
    {
        $escaped = str_replace('\\', '\\\\', $prefix);
        $single = preg_quote("'" . $escaped . "'", '/');
        $double = preg_quote('"' . $escaped . '"', '/');
        $pattern = '/(?:' . $single . '|' . $double . ')\s*=>/';
        $hits = [];

        foreach ($this->moduleIndexFiles() as $relative) {
            $contents = file_get_contents($this->root() . '/' . $relative);

            self::assertIsString($contents);

            if (preg_match($pattern, $contents) === 1) {
                $hits[] = $relative;
            }
        }

        sort($hits);

        return $hits;
    }

    /**
     * @return list<string>
     */
    private function manifestsNamed(string $name): array
    {
        $pattern = '/[\'"]name[\'"]\s*=>\s*[\'"]' . preg_quote($name, '/') . '[\'"]/';
        $hits = [];

        foreach ($this->moduleIndexFiles() as $relative) {
            $contents = file_get_contents($this->root() . '/' . $relative);

            self::assertIsString($contents);

            if (preg_match($pattern, $contents) === 1) {
                $hits[] = $relative;
            }
        }

        sort($hits);

        return $hits;
    }

    /**
     * @return list<string> paths of production files that declare the namespace outside $home
     */
    private function declarationsOutside(string $home, string $namespace): array
    {
        $pattern = '/^\s*namespace ' . preg_quote($namespace, '/') . '(\\\\|;)/m';
        $hits = [];

        foreach ($this->productionRoots() as $directory) {
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

                if (preg_match('#/(Tests|vendor|node_modules)/#', $path) === 1) {
                    continue;
                }

                $contents = file_get_contents($path);

                if ($contents === false || preg_match($pattern, $contents) !== 1) {
                    continue;
                }

                $relative = $this->relative($path);

                if (!str_starts_with($relative, $home . '/')) {
                    $hits[] = $relative;
                }
            }
        }

        sort($hits);

        return $hits;
    }

    /**
     * @return list<string>
     */
    private function moduleIndexFiles(): array
    {
        $paths = [];

        foreach ([
            $this->root() . '/app/modules/*/index.php',
            $this->root() . '/app/system/modules/*/index.php',
            $this->root() . '/packages/pagekit/*/index.php',
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                $paths[] = $this->relative($path);
            }
        }

        foreach ([
            'app/system/index.php',
            'app/package/index.php',
            'app/installer/index.php',
            'app/console/index.php',
        ] as $relative) {
            if (is_file($this->root() . '/' . $relative)) {
                $paths[] = $relative;
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function productionRoots(): array
    {
        $root = $this->root();

        return [
            $root . '/app/modules',
            $root . '/app/system',
            $root . '/app/installer',
            $root . '/app/package',
            $root . '/app/console',
            $root . '/packages/pagekit',
        ];
    }

    private function relative(string $path): string
    {
        $normalized = strtr($path, '\\', '/');
        $prefix = $this->root() . '/';

        self::assertTrue(str_starts_with($normalized, $prefix), $normalized);

        return substr($normalized, strlen($prefix));
    }

    private function canonical(string $path): string
    {
        $real = realpath($path);

        self::assertIsString($real, $path);

        return strtr($real, '\\', '/');
    }

    private function root(): string
    {
        return dirname(__DIR__, 5);
    }
}
