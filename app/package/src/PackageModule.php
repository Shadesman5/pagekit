<?php

declare(strict_types=1);

namespace Pagekit\Package;

use Pagekit\Application as App;
use Pagekit\Module\Module;
use Pagekit\Package\Extension\ExtensionFailureStore;
use Pagekit\Package\Snapshot\DatabaseDumper;
use Pagekit\Package\Snapshot\DatabaseRestorer;
use Pagekit\Package\Snapshot\PackageSnapshotter;
use Pagekit\Package\Snapshot\SnapshotStore;

final class PackageModule extends Module
{
    /**
     * @return mixed Genuinely unknown type — overrides Module::main(); the return value is not consumed by the framework (inherited contract from ModuleInterface).
     */
    public function main(App $app): mixed
    {
        // Where a failure outlives the request that hit it: the next boot and the
        // extension manager both read which packages are broken from here. The
        // service is defined only where the record has a directory to live in, so
        // that the one question a caller can ask the container - whether the id is
        // there - is the same question as whether it resolves.
        if ($app->has('path.system')) {
            $app->set('extension.failures', fn () => new ExtensionFailureStore($app->get('path.system'), $app->get('file')));
        }

        $app->set('package', fn ($app) => (new PackageFactory($app->get('url'), $app->get('path')))->addPath($app->get('path').'/packages/*/*/composer.json'));
        $app->set('manager', fn ($app) => new PackageManager($app));

        // Where an uploaded archive waits for the request that installs it. No dot in the id:
        // the controller's constructor is filled by parameter name.
        $app->set('packageStaging', fn ($app) => $app->get('path.temp') . '/packages');

        // What a removed package can be restored from. Defined only where there
        // is somewhere to keep a snapshot and a database to dump into it, so
        // that the one question a caller can ask the container - whether the id
        // is there - is the same question as whether this installation has a
        // way back to offer.
        if ($app->has('path.snapshots') && $app->has('db')) {
            // How long a removal stays undoable, which is the one thing about
            // snapshots an installation gets to decide.
            $retention = SnapshotStore::retentionDays($this->config('snapshots.retention_days'));

            $app->set('snapshotter', fn ($app) => new PackageSnapshotter(
                new SnapshotStore($app->get('path.snapshots'), $app->get('file'), $retention),
                new DatabaseDumper($app->get('db')),
                new DatabaseRestorer($app->get('db'), $app->get('log')),
                $app->get('file'),
                $app->get('log'),
                $app->get('path.packages'),
                self::applicationVersion($app),
            ));
        }

        return null;
    }

    /**
     * The running application version, or `''` where none is registered.
     *
     * `''` is the application module's own default, and a non-string is not guessed into one.
     */
    private static function applicationVersion(App $app): string
    {
        if (!$app->has('version')) {
            return '';
        }

        $version = $app->get('version');

        return is_string($version) ? $version : '';
    }
}
