<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package;

use Pagekit\Application as App;
use Pagekit\Installer\Helper\Composer;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

class PackageManager
{
    protected OutputInterface $output;

    protected Composer $composer;

    /**
     * @param mixed $output
     */
    public function __construct($output = null)
    {
        $this->output = $output ?: new StreamOutput(fopen('php://output', 'w'));

        $path = realpath(__DIR__ . '/../../..');
        $config = [];

        try {
            $app = App::getInstance(); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
            if ($app && $app->has('path.temp')) {
                $config['path.temp'] = $app->get('path.temp');
                $config['path.cache'] = $app->get('path.cache');
                $config['path.vendor'] = $app->get('path.vendor');
                $config['path.artifact'] = $app->get('path.artifact');
                $config['path.packages'] = $app->get('path.packages');
                $config['system.api'] = $app->has('system.api') ? $app->get('system.api') : 'https://pagekit.com';
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
        $app = App::getInstance(); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
        $packageFactory = $app->get('package');

        $previousPackageConfigs = $packageFactory->all(null, true);

        $this->composer->install($install, $packagist, $preferSource);

        $packages = $packageFactory->all(null, true);
        foreach (array_keys($install) as $name) {
            $moduleAlreadyExisted = isset($previousPackageConfigs[$name]) && $app->get('module')->get($previousPackageConfigs[$name]->get('module'));

            if ($moduleAlreadyExisted == true) {
                $previousPackageConfig = isset($previousPackageConfigs[$name]) ? $previousPackageConfigs[$name] : null;
                $this->enable($packages[$name], $previousPackageConfig);
            } elseif (isset($packages[$name])) {
                $this->doInstall($packages[$name]);
            }
        }
    }

    /**
     * @param  array $uninstall
     */
    public function uninstall($uninstall): void
    {
        $app = App::getInstance(); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
        $packageFactory = $app->get('package');

        foreach ((array) $uninstall as $name) {
            if (!$package = $packageFactory->get($name)) {
                throw new \RuntimeException(__('Unable to find "%name%".', ['%name%' => $name]));
            }

            $this->disable($package);
            $this->getScripts($package)->uninstall();
            $app->get('config')('system')->remove('packages.' . $package->get('module'));

            if ($this->composer->isInstalled($package->getName())) {
                $this->composer->uninstall($package->getName());
            } else {
                if (!$path = $package->get('path')) {
                    throw new \RuntimeException(__('Package path is missing.'));
                }

                $this->output->writeln(__("Removing package folder."));

                $app->get('file')->delete($path);
                @rmdir(dirname($path));
            }
        }
    }

    /**
     * @param $packages
     * @param $previousPackageConfigs
     */
    public function enable($packages, $previousPackageConfigs = []): void
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

                $app = App::getInstance(); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e

                if ($app && $app->has('events')) {
                    $app->get('events')->trigger('package.enable', [$package]);
                }
                if ($app && $app->has('config')) {
                    $sysConfig = $app->get('config')('system');

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

                    // Execute enable scripts BEFORE setting config
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
                if ($originalState !== null) {
                    $this->rollbackEnable($package, $originalState);
                }

                $app = App::getInstance(); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
                if ($app && $app->has('log')) {
                    $app->get('log')->error(
                        sprintf('Failed to enable package "%s": %s',
                            $package->get('name'),
                            $e->getMessage()
                        ),
                        ['exception' => $e, 'package' => $moduleName]
                    );
                }

                throw new \RuntimeException(
                    sprintf('Unable to enable "%s": %s',
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
    protected function rollbackEnable($package, array $originalState): void
    {
        $moduleName = $package->get('module');
        $config = App::getInstance()->get('config')('system'); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e

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
     * @param $packages
     */
    public function disable($packages): void
    {
        if (!is_array($packages)) {
            $packages = [$packages];
        }

        foreach ($packages as $package) {
            $this->getScripts($package)->disable();

            if ($package->getType() == 'pagekit-extension') {
                App::getInstance()->get('config')('system')->pull('extensions', $package->get('module')); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
            }
        }
    }

    /**
     * @param  array $package
     * @param  string $current
     */
    protected function getScripts($package, $current = null): PackageScripts
    {
        if (!$scripts = $package->get('extra.scripts')) {
            return new PackageScripts(null, $current);
        }

        if (!$path = $package->get('path')) {
            throw new \RuntimeException(__('Package path is missing.'));
        }

        return new PackageScripts($path . '/' . $scripts, $current);
    }

    /**
     * @param  $package
     */
    protected function doInstall($package): string
    {
        $this->getScripts($package)->install();
        $version = $this->getVersion($package);

        $app = App::getInstance(); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
        if ($app && $app->has('config')) {
            $app->get('config')('system')->set('packages.' . $package->get('module'), $version);
        }

        return $version;
    }

    /**
     * Tries to obtain package version from 'composer.json' or installation log.
     *
     * @param  $package
     * @return string
     */
    protected function getVersion($package)
    {
        if (!$path = $package->get('path')) {
            throw new \RuntimeException(__('Package path is missing.'));
        }

        if (!file_exists($file = $path . '/composer.json')) {
            throw new \RuntimeException(__('\'composer.json\' is missing.'));
        }

        $package = json_decode(file_get_contents($file), true);
        if (isset($package['version'])) {
            return $package['version'];
        }

        $app = App::getInstance(); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
        $packagesPath = $app ? $app->get('path.packages') : realpath(__DIR__ . '/../../..') . '/packages';
        if (file_exists($packagesPath . '/composer/installed.json')) {
            $installed = json_decode(file_get_contents($file), true);

            foreach ($installed as $package) {
                if ($package['name'] === $package->getName()) {
                    return $package['version'];
                }
            }
        }

        return '0.0.0';
    }
}
