<?php

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
     * Constructor.
     *
     * @param mixed $output
     */
    public function __construct($output = null)
    {
        $this->output = $output ?: new StreamOutput(fopen('php://output', 'w'));

        // Get config from App if available, otherwise use defaults
        $path = realpath(__DIR__ . '/../../..');
        $config = [];
        
        // Try to get from Application instance if available
        try {
            $app = App::getInstance();
            if ($app && isset($app['path.temp'])) {
                $config['path.temp'] = $app['path.temp'];
                $config['path.cache'] = $app['path.cache'];
                $config['path.vendor'] = $app['path.vendor'];
                $config['path.artifact'] = $app['path.artifact'];
                $config['path.packages'] = $app['path.packages'];
                $config['system.api'] = $app['system.api'] ?? 'https://pagekit.com';
            } else {
                // Use default paths
                $config['path.temp'] = $path . '/tmp/temp';
                $config['path.cache'] = $path . '/tmp/cache';
                $config['path.vendor'] = $path . '/vendor';
                $config['path.artifact'] = $path . '/tmp/packages';
                $config['path.packages'] = $path . '/packages';
                $config['system.api'] = 'https://pagekit.com';
            }
        } catch (\Exception $e) {
            // Use default paths on any error
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
        $previousPackageConfigs = App::package()->all(null, true);

        $this->composer->install($install, $packagist, $preferSource);

        $packages = App::package()->all(null, true);
        foreach (array_keys($install) as $name) {
            $moduleAlreadyExisted = isset($previousPackageConfigs[$name]) && App::module($previousPackageConfigs[$name]->get('module'));

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
        foreach ((array) $uninstall as $name) {
            if (!$package = App::package($name)) {
                throw new \RuntimeException(__('Unable to find "%name%".', ['%name%' => $name]));
            }

            $this->disable($package);
            $this->getScripts($package)->uninstall();
            App::config('system')->remove('packages.' . $package->get('module'));

            if ($this->composer->isInstalled($package->getName())) {
                $this->composer->uninstall($package->getName());
            } else {
                if (!$path = $package->get('path')) {
                    throw new \RuntimeException(__('Package path is missing.'));
                }

                $this->output->writeln(__("Removing package folder."));

                App::file()->delete($path);
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
            // Store original state for rollback on error
            $originalState = null;
            $moduleName = $package->get('module');
            
            try {
                // Get the old package config if provided. If there is no old config available, then use the new config (usually fist installation).
                $previousPackageConfig = $package;
                foreach ($previousPackageConfigs as $packageConfig) {
                    if ($packageConfig->get('name') == $package->get('name')) {
                        $previousPackageConfig = $packageConfig;
                        break;
                    }
                }

                App::trigger('package.enable', [$package]);

                // During installation, config service might not be available
                $app = App::getInstance();
                if ($app && isset($app['config'])) {
                    // Capture original state for potential rollback
                    $originalState = [
                        'version' => App::config('system')->get('packages.' . $moduleName),
                        'enabled' => in_array($moduleName, (array) App::config('system')->get('extensions', [])),
                        'theme' => App::config('system')->get('site.theme') === $moduleName,
                    ];
                    
                    if (!$current = App::config('system')->get('packages.' . $previousPackageConfig->get('module'))) {
                        $current = $this->doInstall($package);
                    }

                    $scripts = $this->getScripts($package, $current);
                    if ($scripts->hasUpdates()) {
                        $scripts->update();
                    }

                    // CRITICAL FIX: Execute enable scripts BEFORE setting config
                    // This way, if scripts fail, config is not yet modified
                    $scripts->enable();
                    
                    // Only persist config changes if enable() succeeded
                    $version = $this->getVersion($package);
                    App::config('system')->set('packages.' . $moduleName, $version);

                    if ($package->getType() == 'pagekit-theme') {
                        App::config('system')->set('site.theme', $moduleName);
                    } elseif ($package->getType() == 'pagekit-extension') {
                        // Only add to extensions list if not already there
                        if (!$originalState['enabled']) {
                            App::config('system')->push('extensions', $moduleName);
                        }
                    }
                } else {
                    // During installation, just run basic enable without config updates
                    $current = $this->doInstall($package);
                    $scripts = $this->getScripts($package, $current);
                    $scripts->enable();
                }
            } catch (\Throwable $e) {
                // Rollback: Restore original state on any error
                if ($originalState !== null) {
                    $this->rollbackEnable($package, $originalState);
                }
                
                // Log the error
                $app = App::getInstance();
                if ($app && isset($app['log'])) {
                    $app['log']->error(
                        sprintf('Failed to enable package "%s": %s', 
                            $package->get('name'), 
                            $e->getMessage()
                        ),
                        ['exception' => $e, 'package' => $moduleName]
                    );
                }
                
                // Re-throw with context for caller to handle
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
     * Rollback package enable on error
     *
     * @param  $package
     * @param  array $originalState
     */
    protected function rollbackEnable($package, array $originalState): void
    {
        $moduleName = $package->get('module');
        $config = App::config('system');
        
        // Restore original version
        if ($originalState['version'] !== null) {
            $config->set('packages.' . $moduleName, $originalState['version']);
        } else {
            $config->remove('packages.' . $moduleName);
        }
        
        // Restore original enabled state
        $currentlyEnabled = in_array($moduleName, (array) $config->get('extensions', []));
        if ($originalState['enabled'] && !$currentlyEnabled) {
            // Was enabled, restore it
            $config->push('extensions', $moduleName);
        } elseif (!$originalState['enabled'] && $currentlyEnabled) {
            // Was not enabled, remove it
            $config->pull('extensions', $moduleName);
        }
        
        // Restore theme setting
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
                App::config('system')->pull('extensions', $package->get('module'));
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

        // Only update config if available (not during initial installation)
        $app = App::getInstance();
        if ($app && isset($app['config'])) {
            App::config('system')->set('packages.' . $package->get('module'), $version);
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

        $packagesPath = App::getInstance() ? App::getInstance()['path.packages'] : realpath(__DIR__ . '/../../..') . '/packages';
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
