<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package;

use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Helper\Composer;
use Pagekit\Installer\Package\Lifecycle\LifecycleRunner;
use Pagekit\Installer\Package\Lifecycle\MigrationSet;
use Pagekit\Migration\MigrationService;
use Pagekit\System\Extension\ExtensionFailureStore;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * @phpstan-import-type ExtensionFailure from ExtensionFailureStore
 */
class PackageManager
{
    protected OutputInterface $output;

    protected Composer $composer;

    /**
     * Where a package that failed to load is on record, or null in an
     * environment that keeps no record.
     */
    private readonly ?ExtensionFailureStore $failures;

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

        // The failure record belongs to the system module, which the installer
        // environment does not load. Without it there is nothing to read and
        // nothing to clear; every other operation is unaffected.
        $failures = $this->app->has('extension.failures') ? $this->app->get('extension.failures') : null;
        $this->failures = $failures instanceof ExtensionFailureStore ? $failures : null;

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

            $lifecycle = $this->getLifecycle($package);

            try {
                $lifecycle->uninstall();
            } catch (\Throwable $e) {
                $this->reportHookFailure($package, 'uninstall', $e);
            }

            // After the hook, while the package folder still exists: site
            // listeners soft-delete nodes for types declared in the extension's
            // index.php.
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

            // The package is gone, so a record of it would go on naming
            // something that is no longer installed.
            if (!$this->clearFailure($package)) {
                $this->reportUnclearedFailure($package);
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
            $applied = null;
            $cleared = null;
            $moduleName = $package->get('module');

            try {
                $previousPackageConfig = $package;
                foreach ($previousPackageConfigs as $packageConfig) {
                    if ($packageConfig->get('name') == $package->get('name')) {
                        $previousPackageConfig = $packageConfig;

                        break;
                    }
                }

                // Fire package.enable only after the hooks succeed so node
                // restore / type forget cannot leave partial state when the
                // enable hook throws.
                if ($this->app->has('config')) {
                    $sysConfig = $this->app->get('config')('system');

                    $originalState = [
                        'version' => $sysConfig->get('packages.' . $moduleName),
                        'enabled' => in_array($moduleName, (array) $sysConfig->get('extensions', [])),
                        'theme' => $sysConfig->get('site.theme') === $moduleName,
                    ];

                    if (!$current = $sysConfig->get('packages.' . $previousPackageConfig->get('module'))) {
                        $current = $this->doInstall($package, $applied);
                    }

                    $lifecycle = $this->getLifecycle($package, $current);
                    $this->migrateSchema($package, $lifecycle, $applied);

                    if ($lifecycle->hasUpdates()) {
                        $lifecycle->update();
                    }

                    $lifecycle->enable();

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
                    $current = $this->doInstall($package, $applied);

                    $lifecycle = $this->getLifecycle($package, $current);
                    $this->migrateSchema($package, $lifecycle, $applied);
                    $lifecycle->enable();
                }

                // The next boot reads the record before the configuration, so
                // an extension that cannot be taken off it is one this enable
                // cannot deliver - reporting success would put "enabled" in the
                // panel for something no boot loads. A theme is executed
                // whether or not it is on the record, and taken off it by the
                // boot that loads it, so there the same failed write costs a
                // notice that clears itself. Settled before the event either
                // way, so that a refusal leaves nothing to unwind.
                $recorded = $this->recordedFailure($package);

                if ($this->clearFailure($package)) {
                    // Held for the rollback: everything from here on can still
                    // fail, and an enable that did not happen may not take the
                    // record of the failure that came before it with it.
                    $cleared = $recorded;
                } elseif ($package->getType() === 'pagekit-extension') {
                    throw new \RuntimeException(sprintf(
                        'The failure record of "%s" could not be cleared, so the next boot would leave it disabled.',
                        $moduleName
                    ));
                } else {
                    $this->reportUnclearedFailure($package);
                }

                if ($this->app->has('events')) {
                    $this->app->get('events')->trigger('package.enable', [$package]);
                }
            } catch (\Throwable $e) {
                // The schema this attempt applied goes first, and the
                // configuration rollback runs after it whatever it ran into:
                // what is recorded as installed and enabled is what the next
                // boot reads, so that is the step that may not be skipped.
                if ($applied !== null) {
                    $this->rollbackSchema($package, $applied, $e);
                }

                if ($originalState !== null) {
                    $this->rollbackEnable($package, $originalState);
                }

                // The configuration is back to a package that is not enabled,
                // and the record is the other half of that state: it is what
                // keeps the extension out of the next boot and the only thing
                // that names it as broken in the panel. Dropping it here would
                // leave a package switched off with nothing saying why.
                if ($cleared !== null) {
                    $this->restoreFailure($package, $cleared);
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
            $lifecycle = $this->getLifecycle($package);

            try {
                $lifecycle->disable();
            } catch (\Throwable $e) {
                $this->reportHookFailure($package, 'disable', $e);
            }

            if ($this->app->has('events')) {
                $this->app->get('events')->trigger('package.disable', [$package]);
            }

            if ($package->getType() == 'pagekit-extension') {
                $this->app->get('config')('system')->pull('extensions', $package->get('module'));
            }

            if (!$this->clearFailure($package)) {
                $this->reportUnclearedFailure($package);
            }
        }
    }

    /**
     * The modules on record as having failed to load.
     *
     * @return array<int, string> module names, in no particular order
     */
    public function getFailedModules(): array
    {
        return array_keys($this->failures?->all() ?? []);
    }

    /**
     * Takes a package off the failure record.
     *
     * Enabling, disabling or uninstalling a package is an administrator acting
     * on the failure, and the record is what keeps a failed extension out of
     * the boot and named in the admin notice. Left behind, it would go on doing
     * both against the decision that was just made.
     *
     * @return bool whether the package is off the record, which a package that
     *              was never on one - or that runs where no record is kept -
     *              already is
     */
    private function clearFailure(PackageInterface $package): bool
    {
        $module = $package->get('module');

        if ($this->failures === null || !is_string($module) || $module === '') {
            return true;
        }

        return $this->failures->clear($module);
    }

    /**
     * What the record says about a package.
     *
     * @return ExtensionFailure|null null where the package is not on record, or
     *                               where no record is kept
     */
    private function recordedFailure(PackageInterface $package): ?array
    {
        $module = $package->get('module');

        if ($this->failures === null || !is_string($module) || $module === '') {
            return null;
        }

        return $this->failures->all()[$module] ?? null;
    }

    /**
     * Puts back a record that an operation cleared before it failed.
     *
     * A record that cannot be written back is reported and nothing more: the
     * failure the caller ran into is the one it has to hear about, and raising
     * a second one from the recovery path would take its place.
     *
     * @param ExtensionFailure $entry
     */
    private function restoreFailure(PackageInterface $package, array $entry): void
    {
        if ($this->failures === null || $this->failures->restore($entry)) {
            return;
        }

        try {
            if ($this->app->has('log')) {
                $this->app->get('log')->error(
                    sprintf(
                        'Failed to restore the failure record of "%s" after a failed enable, so nothing names it as broken any more.',
                        $package->get('module')
                    ),
                    ['package' => $package->get('module')]
                );
            }
        } catch (\Throwable) {
            // A record that could neither be restored nor reported is not worth
            // replacing the failure that made the rollback necessary.
        }
    }

    /**
     * Reports a record that stayed behind.
     *
     * Where the record does not decide whether the package runs, a file that
     * could not be rewritten does not get to refuse the operation: disabling
     * and uninstalling are how an administrator gets out from under a broken
     * package, and a theme is loaded whether or not it is on the record. What
     * it costs is a notice standing until someone reads this line.
     */
    private function reportUnclearedFailure(PackageInterface $package): void
    {
        try {
            if ($this->app->has('log')) {
                $this->app->get('log')->error(
                    sprintf(
                        'Failed to clear the failure record of "%s", which is therefore still named as broken.',
                        $package->get('module')
                    ),
                    ['package' => $package->get('module')]
                );
            }
        } catch (\Throwable) {
            // A record that could neither be cleared nor reported is not worth
            // failing the operation that was meant to clear it.
        }
    }

    /**
     * Reports a lifecycle hook that threw on the package's way out.
     *
     * Disabling and uninstalling are how an administrator gets out from under a
     * broken package, so the package gets no say in whether they happen: its
     * hook is given its chance, and a throw costs the hook rather than the
     * operation. Enabling and installing keep propagating - there the failure
     * means the package is not ready to run, which is the caller's business.
     */
    private function reportHookFailure(PackageInterface $package, string $hook, \Throwable $e): void
    {
        try {
            if ($this->app->has('log')) {
                $this->app->get('log')->error(
                    sprintf(
                        'The %s hook of package "%s" failed: %s',
                        $hook,
                        $package->get('name'),
                        $e->getMessage()
                    ),
                    ['exception' => $e, 'package' => $package->get('module')]
                );
            }
        } catch (\Throwable) {
            // Nothing is left that could take the report, and the operation the
            // barrier keeps alive still has to finish.
        }
    }

    /**
     * Brings a package's schema up to date, noting where it stood before.
     *
     * A package declares its migrations and the installation runs them, so an
     * extension no longer needs a hook that reaches for the migration service -
     * and what a run applied can be unwound by whoever failed halfway through
     * it. The note is taken once per attempt: an attempt that installs and then
     * enables migrates twice, and the second run starts from a schema the first
     * one produced, so rolling back to that would leave the first run standing.
     *
     * Where the container has no migration service there is nothing to run
     * against; a container that can reach a database has one.
     *
     * @param array{set: MigrationSet, version: string}|null $applied
     *
     * @param-out array{set: MigrationSet, version: string}|null $applied
     *
     * @throws \RuntimeException where a migration fails, so the caller unwinds the attempt
     */
    private function migrateSchema(PackageInterface $package, LifecycleRunner $lifecycle, ?array &$applied): void
    {
        $set = $lifecycle->migrations();

        if ($set === null) {
            return;
        }

        $migration = $this->app->has('migration') ? $this->app->get('migration') : null;

        if (!$migration instanceof MigrationService) {
            return;
        }

        $applied ??= [
            'set' => $set,
            'version' => $migration->getExtensionCurrentVersion($set->namespace, $set->path),
        ];

        $result = $migration->migrateExtension($set->namespace, $set->path);

        if (empty($result['success'])) {
            $error = $result['error'] ?? null;

            throw new \RuntimeException(sprintf(
                'Migrating "%s" failed: %s',
                $package->get('name'),
                is_string($error) ? $error : 'unknown error'
            ));
        }
    }

    /**
     * Unwinds the migrations one failed attempt applied.
     *
     * Recovery may not throw. The failure that started it is the one the
     * administrator has to hear about, and a second one raised here would take
     * its place and leave the configuration unrolled as well. So both outcomes
     * the rollback can have - the service reporting what it caught, and an
     * Error out of a broken migration class passing through it - are reported
     * together with the original failure and swallowed.
     *
     * @param array{set: MigrationSet, version: string} $applied
     */
    private function rollbackSchema(PackageInterface $package, array $applied, \Throwable $cause): void
    {
        $set = $applied['set'];
        $error = null;
        $thrown = null;

        try {
            $migration = $this->app->has('migration') ? $this->app->get('migration') : null;

            if (!$migration instanceof MigrationService) {
                return;
            }

            $result = $migration->rollbackExtension($set->namespace, $set->path, $applied['version']);

            if (empty($result['success'])) {
                $reported = $result['error'] ?? null;
                $error = is_string($reported) ? $reported : 'unknown error';
            }
        } catch (\Throwable $e) {
            $thrown = $e;
            $error = $e->getMessage();
        }

        if ($error === null) {
            return;
        }

        try {
            if ($this->app->has('log')) {
                $this->app->get('log')->error(
                    sprintf(
                        'Failed to roll the schema of package "%s" back to "%s" after a failed enable: %s',
                        $package->get('name'),
                        $applied['version'],
                        $error
                    ),
                    ['exception' => $cause, 'rollback' => $thrown, 'package' => $package->get('module')]
                );
            }
        } catch (\Throwable) {
            // Nothing is left that could take the report, and the failure that
            // started the recovery still has to reach the caller.
        }
    }

    protected function getLifecycle(PackageInterface $package, ?string $current = null): LifecycleRunner
    {
        if (!$scripts = $package->get('extra.scripts')) {
            return new LifecycleRunner(null, $current, $this->app);
        }

        if (!$path = $package->get('path')) {
            throw new \RuntimeException(__('Package path is missing.'));
        }

        return new LifecycleRunner($path . '/' . $scripts, $current, $this->app);
    }

    /**
     * @param array{set: MigrationSet, version: string}|null $applied where the schema stood before this attempt, once it has migrated
     *
     * @param-out array{set: MigrationSet, version: string}|null $applied
     */
    protected function doInstall(PackageInterface $package, ?array &$applied = null): string
    {
        $lifecycle = $this->getLifecycle($package);

        // The schema before the hook: an install hook that seeds rows needs the
        // tables it seeds them into.
        $this->migrateSchema($package, $lifecycle, $applied);
        $lifecycle->install();

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
