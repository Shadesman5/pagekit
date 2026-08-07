<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package;

use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Helper\Composer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
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
        if ($output === null) {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open php://output stream.');
            }
            $output = new StreamOutput($stream);
        }
        $this->output = $output;

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

        // ContainerInterface guarantees neither that these ids are registered nor
        // what they resolve to — has() answers presence, get() returns mixed. Both
        // collaborators therefore stay optional, and anything that is not the
        // expected type leaves the helper on its own defaults.
        $files = $this->app->has('file') ? $this->app->get('file') : null;
        $logger = $this->app->has('log') ? $this->app->get('log') : null;

        $this->composer = new Composer(
            $config,
            $output,
            $files instanceof Filesystem ? $files : null,
            $logger instanceof LoggerInterface ? $logger : null
        );
    }

    /**
     * @param array<string, string> $install
     */
    public function install(array $install = [], bool $packagist = false, bool $preferSource = false): void
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

    /**
     * @param string|array<int, string> $uninstall
     */
    public function uninstall(string|array $uninstall): void
    {
        $packageFactory = $this->app->get('package');

        foreach ((array) $uninstall as $name) {
            if (!$package = $packageFactory->get($name)) {
                throw new \RuntimeException(__('Unable to find "%name%".', ['%name%' => $name]));
            }

            $this->disable($package);
            $this->getScripts($package)->uninstall();

            // After scripts, while the package folder still exists: site listeners
            // soft-delete nodes for types declared in the extension's index.php.
            if ($this->app->has('events')) {
                $this->app->get('events')->trigger('package.uninstall', [$package]);
            }

            $this->app->get('config')('system')->remove('packages.' . $package->get('module'));

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

    /**
     * @param PackageInterface|array<int, PackageInterface> $packages
     * @param PackageInterface|array<int, PackageInterface> $previousPackageConfigs
     */
    public function enable(PackageInterface|array $packages, PackageInterface|array $previousPackageConfigs = []): void
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

            try {
                $previousPackageConfig = $package;
                foreach ($previousPackageConfigs as $packageConfig) {
                    if ($packageConfig->get('name') == $package->get('name')) {
                        $previousPackageConfig = $packageConfig;

                        break;
                    }
                }

                // Fire package.enable only after scripts succeed so node restore /
                // type forget cannot leave partial state when enable scripts throw.
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

                if ($this->app->has('events')) {
                    $this->app->get('events')->trigger('package.enable', [$package]);
                }
            } catch (\Throwable $e) {
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
     *
     * @param array<string, mixed> $originalState
     */
    protected function rollbackEnable(PackageInterface $package, array $originalState): void
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

    /**
     * @param PackageInterface|array<int, PackageInterface> $packages
     */
    public function disable(PackageInterface|array $packages): void
    {
        if (!is_array($packages)) {
            $packages = [$packages];
        }

        foreach ($packages as $package) {
            $this->getScripts($package)->disable();

            if ($this->app->has('events')) {
                $this->app->get('events')->trigger('package.disable', [$package]);
            }

            if ($package->getType() == 'pagekit-extension') {
                $this->app->get('config')('system')->pull('extensions', $package->get('module'));
            }
        }
    }

    protected function getScripts(PackageInterface $package, ?string $current = null): PackageScripts
    {
        if (!$scripts = $package->get('extra.scripts')) {
            return new PackageScripts(null, $current, $this->app);
        }

        if (!$path = $package->get('path')) {
            throw new \RuntimeException(__('Package path is missing.'));
        }

        return new PackageScripts($path . '/' . $scripts, $current, $this->app);
    }

    protected function doInstall(PackageInterface $package): string
    {
        $this->getScripts($package)->install();
        $version = $this->getVersion($package);

        if ($this->app->has('config')) {
            $this->app->get('config')('system')->set('packages.' . $package->get('module'), $version);
        }

        return $version;
    }

    /**
     * Tries to obtain package version from 'composer.json' or installation log.
     */
    protected function getVersion(PackageInterface $package): string
    {
        if (!$path = $package->get('path')) {
            throw new \RuntimeException(__('Package path is missing.'));
        }

        if (!file_exists($file = $path . '/composer.json')) {
            throw new \RuntimeException(__('\'composer.json\' is missing.'));
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException(__('\'composer.json\' is not readable.'));
        }

        $composerData = json_decode($contents, true);
        if (is_array($composerData) && isset($composerData['version']) && is_string($composerData['version'])) {
            return $composerData['version'];
        }

        $packagesPath = $this->app->has('path.packages')
            ? $this->app->get('path.packages')
            : realpath(__DIR__ . '/../../..') . '/packages';
        $installedFile = $packagesPath . '/composer/installed.json';
        if (file_exists($installedFile)) {
            $installedContents = file_get_contents($installedFile);
            if ($installedContents !== false) {
                $installed = json_decode($installedContents, true);
                $packageName = $package->getName();

                if (is_array($installed)) {
                    foreach ($installed as $entry) {
                        if (is_array($entry) && ($entry['name'] ?? null) === $packageName && isset($entry['version']) && is_string($entry['version'])) {
                            return $entry['version'];
                        }
                    }
                }
            }
        }

        return '0.0.0';
    }
}
