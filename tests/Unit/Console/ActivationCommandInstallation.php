<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Console;

use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Module\ModuleManager;
use Pagekit\Package\Package;
use Pagekit\Package\PackageFactory;

/**
 * Packages and modules an enable or disable command can resolve.
 */
final class ActivationCommandInstallation
{
    public readonly string $workspace;

    public readonly string $mainLog;

    public readonly Config $system;

    public readonly Application $app;

    public readonly ModuleManager $modules;

    public readonly PackageFactory $packages;

    /** @var list<string> */
    private array $moduleFiles = [];

    /**
     * @param list<string> $extensions
     */
    public function __construct(array $extensions = ['kept'])
    {
        require_once dirname(__DIR__) . '/Package/bootstrap.php';
        require_once __DIR__ . '/bootstrap.php';

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_activation_cmd_' . bin2hex(random_bytes(4));
        $this->mainLog = $this->workspace . '/main.log';

        if (!mkdir($this->workspace, 0755, true) && !is_dir($this->workspace)) {
            throw new \RuntimeException('The fixture workspace could not be created.');
        }

        $this->system = new Config([
            'extensions' => $extensions,
            'site' => ['theme' => 'other'],
        ]);

        $this->app = new Application();
        $this->modules = new ModuleManager($this->app);
        $this->app->set('module', $this->modules);
        $this->app->set('config', new SystemConfigDouble($this->system));
        $this->packages = new PackageFactory();
        $this->app->set('package', $this->packages);
    }

    /**
     * @param list<string> $require
     */
    public function module(string $name, array $require = [], bool $runsMain = false): void
    {
        $directory = $this->workspace . '/modules/' . $name;

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('The fixture module directory could not be created.');
        }

        $main = '';

        if ($runsMain) {
            $marker = var_export($this->mainLog, true);
            $line = var_export($name . "\n", true);
            $main = "    'main' => function (): void {\n        file_put_contents({$marker}, {$line}, FILE_APPEND);\n    },\n";
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'name' => " . var_export($name, true) . ",\n    'require' => " . var_export(array_values($require), true) . ",\n" . $main . "];\n";
        $file = $directory . '/index.php';

        if (file_put_contents($file, $contents) === false) {
            throw new \RuntimeException('The fixture module could not be written.');
        }

        $this->moduleFiles[] = $file;
    }

    public function register(): void
    {
        if ($this->moduleFiles !== []) {
            $this->modules->register($this->moduleFiles);
        }
    }

    /**
     * @param list<string> $enabled
     */
    public function activate(array $enabled): void
    {
        $this->modules->setActivityPolicy($enabled, 'system');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function package(string $module, array $overrides = []): void
    {
        $tree = $this->workspace . '/packages/pagekit/' . $module;

        if (!is_dir($tree) && !mkdir($tree, 0755, true) && !is_dir($tree)) {
            throw new \RuntimeException('The fixture package directory could not be created.');
        }

        $data = array_replace([
            'name' => 'pagekit/' . $module,
            'type' => 'pagekit-extension',
            'module' => $module,
            'title' => ucfirst($module),
            'version' => '1.0.0',
            'path' => $tree,
        ], $overrides);

        $type = $data['type'] ?? 'pagekit-extension';

        file_put_contents($tree . '/composer.json', json_encode([
            'name' => 'pagekit/' . $module,
            'type' => is_string($type) ? $type : 'pagekit-extension',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));

        $package = new Package($data);
        $this->packages[$package->getName()] = $package;
    }

    public function ran(string $module): bool
    {
        if (!is_file($this->mainLog)) {
            return false;
        }

        $logged = file_get_contents($this->mainLog);

        return is_string($logged) && str_contains($logged, $module . "\n");
    }

    public function remove(): void
    {
        $this->removeTree($this->workspace);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }

        rmdir($path);
    }
}

/**
 * Serves one system config without a database connection.
 */
final class SystemConfigDouble extends ConfigManager
{
    public function __construct(private readonly Config $system)
    {
    }

    public function __invoke(string $name): ?Config
    {
        return $name === 'system' ? $this->system : null;
    }

    public function has(string $name): bool
    {
        return false;
    }
}
