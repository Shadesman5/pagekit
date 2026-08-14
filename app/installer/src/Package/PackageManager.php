<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package;

use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Helper\Composer;
use Pagekit\Installer\Package\Lifecycle\LifecycleRunner;
use Pagekit\Installer\Package\Lifecycle\MigrationSet;
use Pagekit\Installer\Package\Snapshot\PackageSnapshotter;
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

    /**
     * What went wrong on a package's way out without stopping it, waiting to be
     * passed on.
     *
     * @var list<string>
     */
    private array $hookWarnings = [];

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
                $config['path.vendor'] = $path . '/app/vendor';
                $config['path.artifact'] = $path . '/tmp/packages';
                $config['path.packages'] = $path . '/packages';
                $config['system.api'] = 'https://pagekit.com';
            }
        } catch (\Exception $e) {
            $config['path.temp'] = $path . '/tmp/temp';
            $config['path.cache'] = $path . '/tmp/cache';
            $config['path.vendor'] = $path . '/app/vendor';
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
     * Takes a package out of the installation, retaining it in a snapshot.
     *
     * Removing a package is a move rather than a deletion: the snapshot is
     * taken first, and what it holds - the package's files, a dump of the
     * database and a description of both - is the package from then on. The
     * live tree goes, so the factory stops globbing it up and the panel stops
     * listing it, and the snapshot is what an administrator restores from until
     * it is purged. Purging is the only step that destroys anything for good -
     * except in an installation that keeps no snapshots at all, where the
     * removal is exactly as final as it always was ({@see snapshot()}).
     *
     * The database keeps its tables. A package that wants its rows gone says so
     * in its own uninstall hook; dropping them here would make the snapshot the
     * only copy of content the site may well still want, and reinstalling the
     * package would come back to an empty extension.
     *
     * @param string|array<int, string> $uninstall
     *
     * @throws \RuntimeException where no snapshot could be taken, in which case
     *                          nothing was removed, or where a package's files
     *                          could not be taken out of the live tree
     */
    public function uninstall(string|array $uninstall): void
    {
        $packageFactory = $this->app->get('package');

        foreach ((array) $uninstall as $name) {
            if (!$package = $packageFactory->get($name)) {
                throw new \RuntimeException(__('Unable to find "%name%".', ['%name%' => $name]));
            }

            // Before the package is switched off and long before its folder is
            // touched: everything below this line is what the snapshot exists to
            // reverse.
            $this->snapshot($package);

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

            $this->removeFiles($package);

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
     * What failed on the way out without failing the operation.
     *
     * A package has no say in whether it is switched off or removed, so a hook
     * of its own that throws costs the hook and nothing else. What it used to
     * cost as well was any word of it reaching the administrator: the operation
     * reported plain success and the reason sat in a log nobody had been sent
     * to. These lines are that word - which step of which package did not
     * finish, and where the rest of it is. What the hook threw stays in the log,
     * because it is a package's own text and this is read in a panel.
     *
     * Drained by the call: whoever asks has taken them on, and the next
     * operation through this manager starts with none of its own.
     *
     * @return list<string> ready to be shown, in the order the hooks failed
     */
    public function takeHookWarnings(): array
    {
        $warnings = $this->hookWarnings;
        $this->hookWarnings = [];

        return $warnings;
    }

    /**
     * Puts the installation aside before a package is taken out of it.
     *
     * A removal cannot be undone by running it again, so the snapshot is the
     * whole of the way back: the package's files, the database as it stands, and
     * a description of both. It is taken first, before anything is switched off,
     * because a snapshot that could not be taken means an administrator would
     * otherwise be told a package is restorable when it is not.
     *
     * One environment removes a package unsnapshotted: the one that has no
     * snapshot store at all. The store is defined where there is somewhere to
     * keep a snapshot and a database to dump into it, and a container with
     * neither never had a way back to offer - refusing there would leave such an
     * installation unable to remove a package at all. That costs a line in the
     * log and nothing else. Everything else - a store that cannot be written, a
     * database that cannot be read, a snapshotter that is not one - aborts the
     * removal with nothing removed.
     *
     * @throws \RuntimeException where a snapshot was to be taken and could not be
     */
    private function snapshot(PackageInterface $package): void
    {
        if (!$this->app->has('snapshotter')) {
            $this->reportUnsnapshotted($package);

            return;
        }

        try {
            $snapshotter = $this->app->get('snapshotter');

            if (!$snapshotter instanceof PackageSnapshotter) {
                throw new \RuntimeException('The registered snapshotter cannot take a snapshot.');
            }

            $id = $snapshotter->create($package, PackageSnapshotter::REASON_UNINSTALL);
        } catch (\Throwable $e) {
            $this->reportFailedSnapshot($package, $e);

            // What went wrong is in the log with the throwable that carries it.
            // This message is streamed to a browser, so it says what happened to
            // the operation rather than which path on the disk refused a write.
            throw new \RuntimeException(
                __(
                    'No snapshot of "%name%" could be taken, so nothing was removed. See error log for details.',
                    ['%name%' => $this->label($package)]
                ),
                0,
                $e
            );
        }

        $this->output->writeln(__('Snapshot %id% taken.', ['%id%' => $id]));
    }

    /**
     * Takes the package's files out of the live tree.
     *
     * The copy in the snapshot is what the package is retained as from here on,
     * so this completes a move rather than deleting the last copy of anything -
     * and it has to leave nothing behind. A tree still under packages/ is one the
     * factory goes on globbing up and the panel goes on offering, as a package
     * that merely is not installed, while its hooks have run and its nodes are in
     * the trash. So the outcome is checked rather than assumed: files that will
     * not go are a removal an administrator has to hear about, not one that can
     * be reported as done.
     *
     * Composer is told last, for a package it installed, so that what it takes
     * off the disk is the tree the snapshot was already archived from.
     *
     * @throws \RuntimeException where the package names no path, or its files
     *                          could not be taken out of the live tree
     */
    private function removeFiles(PackageInterface $package): void
    {
        $path = $package->get('path');

        if (!is_string($path) || $path === '') {
            throw new \RuntimeException(__('Package path is missing.'));
        }

        if ($this->composer->isInstalled($package->getName())) {
            $this->composer->uninstall($package->getName());

            // Composer takes the tree off the disk itself and reports nothing
            // about it that can be read back, so the disk is all there is to
            // go on for a package it installed.
            $removed = !is_dir($path);
        } else {
            $this->output->writeln(__('Removing package folder.'));

            // The file service both removes the tree and answers whether it
            // could: it stops at the first entry that will not go. Stat'ing the
            // path instead would take it for a plain local one, which the
            // service does not promise - a path it maps through an adapter is
            // wherever that adapter puts it.
            $removed = $this->app->get('file')->delete($path) === true;
        }

        // The vendor directory goes too where this package was the last thing in
        // it, and stays where it holds another.
        @rmdir(dirname($path));

        if ($removed) {
            return;
        }

        $this->reportUnremovedFiles($package);

        // Streamed to a browser, so the path that would not go stays in the log.
        throw new \RuntimeException(__(
            '"%name%" was removed, but its files could not be taken off the disk. The snapshot holds the whole package, so it can be restored, or the folder removed by hand.',
            ['%name%' => $this->label($package)]
        ));
    }

    /**
     * What a package is called where an administrator is being told about it.
     *
     * The title an extension gives itself, which is what the panel lists it as,
     * and its package name where it gives none.
     */
    private function label(PackageInterface $package): string
    {
        $title = $package->get('title');

        return is_string($title) && $title !== '' ? $title : $package->getName();
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
     * A record that cannot be written back is a lost record, and for an
     * extension it was doing two jobs: keeping the package out of the boot and
     * naming it as broken in the panel. The configuration takes the first one
     * over, so a package this attempt already watched fail is not handed back to
     * the next boot. The second is gone with the file and is reported to the log
     * and nothing more: raising a second failure from the recovery path would
     * take the place of the one that made the rollback necessary.
     *
     * @param ExtensionFailure $entry
     */
    private function restoreFailure(PackageInterface $package, array $entry): void
    {
        if ($this->failures === null || $this->failures->restore($entry)) {
            return;
        }

        $withheld = $this->withholdExtension($package);

        try {
            if ($this->app->has('log')) {
                $this->app->get('log')->error(
                    sprintf(
                        'Failed to restore the failure record of "%s" after a failed enable: %s',
                        $package->get('module'),
                        $withheld
                            ? 'it is switched off in the configuration instead, and nothing names it as broken any more.'
                            : 'nothing names it as broken any more.'
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
     * Takes an extension out of the configured extensions.
     *
     * The boot reads the record before the configuration, so an extension the
     * record was holding off is one the configuration may well still list - that
     * is the state a load failure leaves behind when the database it tried to
     * write to is what broke. With the record lost, that configuration is all
     * the next boot has to go on, and it would execute the package again. So the
     * configuration says what the record no longer can: the extension is off
     * until an administrator turns it back on. A theme needs none of this, as it
     * is executed whether or not it is on the record.
     *
     * Written to the database here rather than left to the terminate event that
     * normally persists the configuration: a console run never fires one, and a
     * request that got this far has already failed once. Off in memory is not
     * off on the next boot, so the write goes out on both branches - the one
     * where this takes the extension out of the enabled list and the one where
     * the rollback already left it unlisted. Only a write that happened may
     * report that the configuration is holding the extension off now.
     *
     * @return bool whether the configuration the next boot reads leaves the
     *              extension out
     */
    private function withholdExtension(PackageInterface $package): bool
    {
        $module = $package->get('module');

        if ($package->getType() !== 'pagekit-extension' || !is_string($module) || $module === '' || !$this->app->has('config')) {
            return false;
        }

        try {
            $configs = $this->app->get('config');
            $config = $configs('system');

            // Pulling a name the list does not carry is not a no-op but a type
            // error: a site that never enabled an extension has no list at all.
            if (in_array($module, (array) $config->get('extensions', []))) {
                $config->pull('extensions', $module);
            }

            $configs->set('system', $config);

            return true;
        } catch (\Throwable) {
            // Writing the configuration can fail for the same reason recording
            // the failure did. There is nothing left to fall back on and nothing
            // to report that the line about the lost record does not say.
            return false;
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
     * Reports a removal that has nothing to fall back on.
     *
     * An environment without a snapshot store is one that never had a way back
     * to offer - an installer run before there is a database, for one - and the
     * removal goes ahead: refusing it would leave that installation unable to
     * remove a package at all. This line is then the only thing that will later
     * say the package was not put anywhere first.
     */
    private function reportUnsnapshotted(PackageInterface $package): void
    {
        try {
            if ($this->app->has('log')) {
                $this->app->get('log')->warning(
                    sprintf(
                        'Package "%s" is being removed without a snapshot: this installation keeps no snapshot store, so the removal cannot be undone.',
                        $package->get('name')
                    ),
                    ['package' => $package->get('module')]
                );
            }
        } catch (\Throwable) {
            // Nothing is left that could take the report, and an administrator
            // who asked for the package to go is not refused over the log.
        }
    }

    /**
     * Reports a snapshot that was not taken, which is a removal that did not
     * happen.
     *
     * The full reason belongs here rather than in the exception: the caller
     * streams that message straight to a browser, and what refused the snapshot
     * is usually a path on the disk.
     */
    private function reportFailedSnapshot(PackageInterface $package, \Throwable $e): void
    {
        try {
            if ($this->app->has('log')) {
                $this->app->get('log')->error(
                    sprintf(
                        'No snapshot of package "%s" could be taken, so nothing was removed: %s',
                        $package->get('name'),
                        $e->getMessage()
                    ),
                    ['exception' => $e, 'package' => $package->get('module')]
                );
            }
        } catch (\Throwable) {
            // The failure still has to reach the caller, which is where the
            // administrator hears that the package is untouched.
        }
    }

    /**
     * Reports a package tree that stayed in the live installation.
     *
     * Everything else about the package is undone by then, so this is the half
     * of a removal that has to be finished by hand - and the path is what
     * whoever finishes it needs, which is why it goes here rather than into the
     * message the caller streams back. What is left of the tree can be anything
     * from all of it to the entry the deletion stopped at.
     */
    private function reportUnremovedFiles(PackageInterface $package): void
    {
        try {
            if ($this->app->has('log')) {
                $this->app->get('log')->error(
                    sprintf(
                        'Package "%s" was removed, but its files at "%s" could not be: the snapshot holds the package, so it can be restored or the folder removed by hand.',
                        $package->get('name'),
                        $package->get('path')
                    ),
                    ['package' => $package->get('module')]
                );
            }
        } catch (\Throwable) {
            // The refusal still has to reach the caller, which is where the
            // administrator hears that the removal did not finish.
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
     *
     * The line for the caller is taken first, so that a log this cannot be
     * written to still leaves the administrator with something that says a step
     * was skipped ({@see takeHookWarnings()}).
     */
    private function reportHookFailure(PackageInterface $package, string $hook, \Throwable $e): void
    {
        $this->hookWarnings[] = __(
            'The %hook% step of "%name%" did not finish. See the error log for details.',
            ['%hook%' => $hook, '%name%' => $this->label($package)]
        );

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
     * A note that could not be taken stops the run before it starts. The
     * alternative is a run whose failure has nowhere to unwind to: an
     * unreadable version is indistinguishable from an empty schema by value
     * alone, and unwinding to an empty schema means dropping every table the
     * package has in production. An attempt that never ran costs a retry.
     *
     * @param array{set: MigrationSet, version: string}|null $applied
     *
     * @param-out array{set: MigrationSet, version: string}|null $applied
     *
     * @throws \RuntimeException where a migration fails or cannot be unwound, so the caller unwinds the attempt
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

        if ($applied === null) {
            $version = $migration->getExtensionCurrentVersion($set->namespace, $set->path);

            if ($version === null) {
                throw new \RuntimeException(sprintf(
                    'Migrating "%s" was not attempted: the schema version it starts from could not be read, so a failed migration could not be rolled back.',
                    $package->get('name')
                ));
            }

            $applied = ['set' => $set, 'version' => $version];
        }

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
