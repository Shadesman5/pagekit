<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package;

use Pagekit\Installer\Helper\Composer;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

class PackageManager
{
    protected OutputInterface $output;

    protected Composer $composer;

    public function __construct(
        private readonly ContainerInterface $app,
        ?OutputInterface $output = null,
    ) {
        $this->output = $output ?: new StreamOutput(fopen('php://output', 'w'));

        $path = realpath(__DIR__ . '/../../..');
        $config = [];

        try {
            if ($this->app->has('path.temp')) {
                $config['path.temp'] = $this->app->get('path.temp');
                $config['path.cache'] = $this->app->get('path.cache');
                $config['path.vendor'] = $this->app->get('path.vendor');
                $config['path.artifact'] = $this->app->get('path.artifact');
                $config['path.packages'] = $this->app->get('path.packages');
                $config['system.api'] = $this->app->has('system.api') ? $this->app->get('system.api') : 'https://pagekit.com';
            } else {
                $config['path.temp'] = $path . '/tmp/temp';
                $config['path.cache'] = $path . '/tmp/cache';
                $config['path.vendor'] = $path . '/vendor';
                $config['path.artifact'] = $path . '/tmp/packages';
                $config['path.packages'] = $path . '/packages';
                $config['system.api'] = 'https://pagekit.com';
            }
        } catch (\Exception $e) {
            $config['path.temp'] = $path . '/tmp/temp';
            $config['path.cache'] = $path . '/tmp/cache';
            $config['path.vendor'] = $path . '/vendor';
            $config['path.artifact'] = $path . '/tmp/packages';
            $config['path.packages'] = $path . '/packages';
            $config['system.api'] = 'https://pagekit.com';
        }

        $this->composer = new Composer($config, $output);
    }

    /**
     * @param  array $install
     * @param bool $packagist
     * @param bool $preferSource
     */
    public function install(array $install = [], $packagist = false, $preferSource = false): void
    {
        $packageFactory = $this->app->get('package');

        $previousPackageConfigs = $packageFactory->all(null, true);

        $this->composer->install($install, $packagist, $preferSource);

        $packages = $packageFactory->all(null, true);
        foreach (array_keys($install) as $name) {
            $moduleAlreadyExisted = isset($previousPackageConfigs[$name]) && $this->app->get('module')->get($previousPackageConfigs[$name]->get('module'));

            if ($moduleAlreadyExisted == true) {
                $previousPackageConfig = isset($previousPackageConfigs[$name]) ? $previousPackageConfigs[$name] : null;
                $this->enable($packages[$name], $previousPackageConfig);
            } elseif (isset($packages[$name])) {
                $this->doInstall($packages[$name]);
            }
        }
    }

    public function uninstall(string|array $uninstall): void
    {
        $packageFactory = $this->app->get('package');

        foreach ((array) $uninstall as $name) {
            if (!$package = $packageFactory->get($name)) {
                throw new \RuntimeException(__('Unable to find "%name%".', ['%name%' => $name]));
            }

            $this->disable($package);
            $this->getScripts($package)->uninstall();
            $this->app->get('config')('system')->remove('packages.' . $package->get('module'));

            $packagePath = $package->get('path');
            if ($packagePath !== null
                && is_dir($packagePath . '/src/Migrations')
                && $this->app->has('migration')
            ) {
                try {
                    $migrationNamespace = $this->resolveExtensionMigrationNamespace($package);
                    if ($migrationNamespace !== null) {
                        $rollbackResult = $this->app->get('migration')->rollbackExtension(
                            $migrationNamespace,
                            $packagePath . '/src/Migrations',
                            '0'
                        );
                        if (!$rollbackResult['success'] && $this->app->has('log')) {
                            $this->app->get('log')->warning(
                                sprintf(
                                    'Failed to rollback migrations for package "%s": %s',
                                    $package->get('name'),
                                    $rollbackResult['error'] ?? 'unknown error'
                                )
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    if ($this->app->has('log')) {
                        $this->app->get('log')->warning(
                            sprintf(
                                'Failed to rollback migrations for package "%s": %s',
                                $package->get('name'),
                                $e->getMessage()
                            ),
                            ['exception' => $e]
                        );
                    }
                }
            }

            if ($this->composer->isInstalled($package->getName())) {
                $this->composer->uninstall($package->getName());
            } else {
                if (!$path = $package->get('path')) {
                    throw new \RuntimeException(__('Package path is missing.'));
                }

                $this->output->writeln(__("Removing package folder."));

                $this->app->get('file')->delete($path);
                @rmdir(dirname($path));
            }
        }
    }

    public function enable(object|array $packages, object|array $previousPackageConfigs = []): void
    {
        if (!is_array($packages)) {
            $packages = [$packages];
        }

        if (!is_array($previousPackageConfigs)) {
            $previousPackageConfigs = [$previousPackageConfigs];
        }

        foreach ($packages as $package) {
            $originalState = null;
            $moduleName = $package->get('module');
            /** @var array{ns: string, path: string}|null */
            $appliedMigration = null;

            try {
                $previousPackageConfig = $package;
                foreach ($previousPackageConfigs as $packageConfig) {
                    if ($packageConfig->get('name') == $package->get('name')) {
                        $previousPackageConfig = $packageConfig;

                        break;
                    }
                }

                if ($this->app->has('events')) {
                    $this->app->get('events')->trigger('package.enable', [$package]);
                }
                if ($this->app->has('config')) {
                    $sysConfig = $this->app->get('config')('system');

                    $originalState = [
                        'version' => $sysConfig->get('packages.' . $moduleName),
                        'enabled' => in_array($moduleName, (array) $sysConfig->get('extensions', [])),
                        'theme' => $sysConfig->get('site.theme') === $moduleName,
                    ];

                    if (!$current = $sysConfig->get('packages.' . $previousPackageConfig->get('module'))) {
                        $current = $this->doInstall($package);
                    }

                    $scripts = $this->getScripts($package, $current);
                    if ($scripts->hasUpdates()) {
                        $scripts->update();
                    }

                    $packagePath = $package->get('path');
                    if ($packagePath !== null
                        && is_dir($packagePath . '/src/Migrations')
                        && $this->app->has('migration')
                    ) {
                        $migrationNamespace = $this->resolveExtensionMigrationNamespace($package);
                        if ($migrationNamespace === null) {
                            throw new \RuntimeException(sprintf(
                                'Cannot resolve migration namespace for package "%s" — src/Migrations/ exists but no autoload config found',
                                $package->get('name') ?? $moduleName
                            ));
                        }
                        $appliedMigration = ['ns' => $migrationNamespace, 'path' => $packagePath . '/src/Migrations'];

                        $migrationResult = $this->app->get('migration')->migrateExtension(
                            $migrationNamespace,
                            $packagePath . '/src/Migrations'
                        );
                        if (!$migrationResult['success']) {
                            throw new \RuntimeException(
                                'Extension migration failed: ' . ($migrationResult['error'] ?? 'unknown error')
                            );
                        }
                    }

                    $scripts->enable();

                    $version = $this->getVersion($package);
                    $sysConfig->set('packages.' . $moduleName, $version);

                    if ($package->getType() == 'pagekit-theme') {
                        $sysConfig->set('site.theme', $moduleName);
                    } elseif ($package->getType() == 'pagekit-extension') {
                        if (!$originalState['enabled']) {
                            $sysConfig->push('extensions', $moduleName);
                        }
                    }
                } else {
                    $current = $this->doInstall($package);
                    $scripts = $this->getScripts($package, $current);
                    $scripts->enable();
                }
            } catch (\Throwable $e) {
                if ($appliedMigration !== null && $this->app->has('migration')) {
                    try {
                        $rollbackResult = $this->app->get('migration')->rollbackExtension(
                            $appliedMigration['ns'],
                            $appliedMigration['path'],
                            '0'
                        );
                        if (!$rollbackResult['success'] && $this->app->has('log')) {
                            $this->app->get('log')->warning(sprintf(
                                'Failed to rollback migrations for package "%s" during enable recovery: %s',
                                $package->get('name'),
                                $rollbackResult['error'] ?? 'unknown error'
                            ));
                        }
                    } catch (\Throwable $rollbackError) {
                        if ($this->app->has('log')) {
                            $this->app->get('log')->warning(sprintf(
                                'Failed to rollback migrations for package "%s" during enable recovery: %s',
                                $package->get('name'),
                                $rollbackError->getMessage()
                            ), ['exception' => $rollbackError]);
                        }
                    }
                }

                if ($originalState !== null) {
                    $this->rollbackEnable($package, $originalState);
                }

                if ($this->app->has('log')) {
                    $this->app->get('log')->error(
                        sprintf(
                            'Failed to enable package "%s": %s',
                            $package->get('name'),
                            $e->getMessage()
                        ),
                        ['exception' => $e, 'package' => $moduleName]
                    );
                }

                throw new \RuntimeException(
                    sprintf(
                        'Unable to enable "%s": %s',
                        $package->get('title') ?? $package->get('name'),
                        $e->getMessage()
                    ),
                    0,
                    $e
                );
            }
        }
    }

    /**
     * Rollback package enable on error.
     */
    protected function rollbackEnable(object $package, array $originalState): void
    {
        $moduleName = $package->get('module');
        $config = $this->app->get('config')('system');

        if ($originalState['version'] !== null) {
            $config->set('packages.' . $moduleName, $originalState['version']);
        } else {
            $config->remove('packages.' . $moduleName);
        }

        $currentlyEnabled = in_array($moduleName, (array) $config->get('extensions', []));
        if ($originalState['enabled'] && !$currentlyEnabled) {
            $config->push('extensions', $moduleName);
        } elseif (!$originalState['enabled'] && $currentlyEnabled) {
            $config->pull('extensions', $moduleName);
        }

        if ($package->getType() == 'pagekit-theme') {
            if ($originalState['theme']) {
                $config->set('site.theme', $moduleName);
            } elseif ($config->get('site.theme') === $moduleName) {
                $config->remove('site.theme');
            }
        }
    }

    public function disable(object|array $packages): void
    {
        if (!is_array($packages)) {
            $packages = [$packages];
        }

        foreach ($packages as $package) {
            $this->getScripts($package)->disable();

            if ($package->getType() == 'pagekit-extension') {
                $this->app->get('config')('system')->pull('extensions', $package->get('module'));
            }
        }
    }

    protected function getScripts(object $package, ?string $current = null): PackageScripts
    {
        if (!$scripts = $package->get('extra.scripts')) {
            return new PackageScripts(null, $current, $this->app);
        }

        if (!$path = $package->get('path')) {
            throw new \RuntimeException(__('Package path is missing.'));
        }

        return new PackageScripts($path . '/' . $scripts, $current, $this->app);
    }

    protected function doInstall(object $package): string
    {
        $this->getScripts($package)->install();
        $version = $this->getVersion($package);

        if ($this->app->has('config')) {
            $this->app->get('config')('system')->set('packages.' . $package->get('module'), $version);
        }

        return $version;
    }

    /**
     * Derive the PSR-4 migration namespace for an extension package.
     *
     * Resolution order:
     *  1. Module manager autoload config (runtime)
     *  2. Package index.php autoload config (file)
     *  3. Package composer.json PSR-4 autoload (file)
     *  4. Package name converted to StudlyCaps namespace
     */
    protected function resolveExtensionMigrationNamespace(object $package): ?string
    {
        $moduleName = $package->get('module');

        if ($moduleName !== null && $this->app->has('module')) {
            $module = $this->app->get('module')->get($moduleName);
            if ($module !== null) {
                $autoload = $module->get('autoload') ?? ($module->config['autoload'] ?? null);
                if (is_array($autoload)) {
                    foreach ($autoload as $ns => $dir) {
                        return rtrim((string) $ns, '\\') . '\\Migrations';
                    }
                }
            }
        }

        $packagePath = $package->get('path');

        if ($packagePath !== null && file_exists($packagePath . '/index.php')) {
            $autoload = $this->parseAutoloadFromIndexFile($packagePath . '/index.php');
            if ($autoload !== null) {
                foreach ($autoload as $ns => $dir) {
                    return rtrim((string) $ns, '\\') . '\\Migrations';
                }
            }
        }

        if ($packagePath !== null && file_exists($packagePath . '/composer.json')) {
            $composerData = json_decode((string) file_get_contents($packagePath . '/composer.json'), true);
            $psr4 = $composerData['autoload']['psr-4'] ?? [];
            foreach ($psr4 as $ns => $dir) {
                return rtrim((string) $ns, '\\') . '\\Migrations';
            }
        }

        $packageName = $package->get('name') ?? $package->getName();
        if ($packageName !== null) {
            $segments = explode('/', (string) $packageName);
            $studly = implode('\\', array_map(
                fn (string $s): string => str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $s))),
                $segments,
            ));
            return $studly . '\\Migrations';
        }

        return null;
    }

    /**
     * Extract the 'autoload' array from a package index.php without executing closures.
     *
     * Extension index.php files contain closures that reference variables like $app
     * which are undefined in this scope. Using token-based extraction avoids executing
     * the file and risking undefined-variable errors or duplicate side effects.
     *
     * @return array<string, string>|null The autoload map, or null if not found
     */
    private function parseAutoloadFromIndexFile(string $filePath): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        if (!preg_match("/['\"]autoload['\"]\s*=>\s*\[/s", $content, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $bracketBody = $this->extractBracketBody($content, (int) $match[0][1] + strlen($match[0][0]) - 1);
        if ($bracketBody === null) {
            return null;
        }

        $result = [];
        if (preg_match_all("/['\"]([^'\"]+)['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $bracketBody, $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as $pair) {
                $result[$this->unescapePhpString($pair[1])] = $this->unescapePhpString($pair[2]);
            }
        }

        return $result !== [] ? $result : null;
    }

    /**
     * Extract content between balanced [ ] brackets, handling quoted strings.
     *
     * Properly skips escaped characters inside quotes (e.g. \\\\ and \\')
     * so that strings like 'Pagekit\\\\Blog\\\\' don't prevent bracket matching.
     */
    private function extractBracketBody(string $content, int $openPos): ?string
    {
        $len = strlen($content);
        if ($openPos >= $len || $content[$openPos] !== '[') {
            return null;
        }

        $depth = 0;

        for ($i = $openPos; $i < $len; $i++) {
            $ch = $content[$i];

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $i++;
                while ($i < $len) {
                    if ($content[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($content[$i] === $quote) {
                        break;
                    }
                    $i++;
                }
            } elseif ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $openPos + 1, $i - $openPos - 1);
                }
            }
        }

        return null;
    }

    /**
     * Unescape a PHP single-quoted string literal captured from source code.
     *
     * In PHP source, single-quoted strings only have two escape sequences:
     * \\\\ → \\ and \\' → '. This converts raw source text to the runtime value.
     */
    private function unescapePhpString(string $raw): string
    {
        return strtr($raw, ['\\\\' => '\\', "\\'" => "'"]);
    }

    /**
     * Tries to obtain package version from 'composer.json' or installation log.
     */
    protected function getVersion(object $package): string
    {
        if (!$path = $package->get('path')) {
            throw new \RuntimeException(__('Package path is missing.'));
        }

        if (!file_exists($file = $path . '/composer.json')) {
            throw new \RuntimeException(__('\'composer.json\' is missing.'));
        }

        $composerData = json_decode(file_get_contents($file), true);
        if (isset($composerData['version'])) {
            return $composerData['version'];
        }

        $packagesPath = $this->app->has('path.packages')
            ? $this->app->get('path.packages')
            : realpath(__DIR__ . '/../../..') . '/packages';
        $installedFile = $packagesPath . '/composer/installed.json';
        if (file_exists($installedFile)) {
            $installed = json_decode(file_get_contents($installedFile), true);
            $packageName = $package->getName();

            foreach ($installed as $entry) {
                if (($entry['name'] ?? null) === $packageName) {
                    return $entry['version'];
                }
            }
        }

        return '0.0.0';
    }
}
