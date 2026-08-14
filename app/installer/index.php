<?php

declare(strict_types=1);

use Pagekit\Installer\Package\PackageFactory;
use Pagekit\Installer\Package\PackageManager;
use Pagekit\Installer\Package\Snapshot\DatabaseDumper;
use Pagekit\Installer\Package\Snapshot\DatabaseRestorer;
use Pagekit\Installer\Package\Snapshot\PackageSnapshotter;
use Pagekit\Installer\Package\Snapshot\SnapshotStore;
use Pagekit\Kernel\Event\ExceptionListenerWrapper;
use Pagekit\Kernel\Exception\NotFoundException;

return [

    'name' => 'installer',

    'main' => function ($app) {

        $app->set('package', fn ($app) => (new PackageFactory($app->get('url'), $app->get('path')))->addPath($app->get('path').'/packages/*/*/composer.json'));
        $app->set('manager', fn ($app) => new PackageManager($app));
        $app->set('systemApi', fn ($app) => $app->has('system.api') ? $app->get('system.api') : 'https://pagekit.com');

        // What a removed package can be restored from. Defined only where there
        // is somewhere to keep a snapshot and a database to dump into it, so
        // that the one question a caller can ask the container - whether the id
        // is there - is the same question as whether this installation has a
        // way back to offer.
        if ($app->has('path.snapshots') && $app->has('db')) {
            $app->set('snapshotter', fn ($app) => new PackageSnapshotter(
                new SnapshotStore($app->get('path.snapshots'), $app->get('file')),
                new DatabaseDumper($app->get('db')),
                new DatabaseRestorer($app->get('db')),
                $app->get('file'),
                $app->get('log'),
                $app->get('path.packages'),
            ));
        }

        if ($this->config['enabled']) {

            $app->extend('assets', function ($factory) use ($app) {

                $factory->setVersion($app->get('version'));

                return $factory;

            });

            $app->get('routes')->add([
                'path' => '/installer',
                'name' => '@installer',
                'controller' => 'Pagekit\Installer\Controller\InstallerController',
            ]);

            $app->get('events')->on('request', function ($event, $request) use ($app) {

                $locale = $request->get('locale') ?: $app->get('request')->getPreferredLanguage();
                $available = $app->get('module')->get('system/intl')->getAvailableLanguages();

                if (is_string($locale) && $locale !== '' && isset($available[$locale])) {
                    $app->get('module')->get('system/intl')->setLocale($locale);
                }

            });

            $app->get('events')->on('exception', new ExceptionListenerWrapper(fn (NotFoundException $e) => $app->get('router')->redirect('@installer')), -8);

        }

    },

    'require' => [

        'application',
        'migration',
        'system/cache',
        'system/intl',
        'system/view',

    ],

    'routes' => [

        '/system/package' => [
            'name' => '@system/package',
            'controller' => 'Pagekit\Installer\Controller\PackageController',
        ],
        '/system/marketplace' => [
            'name' => '@system/marketplace',
            'controller' => 'Pagekit\Installer\Controller\MarketplaceController',
        ],
        '/system/update' => [
            'name' => '@system/update',
            'controller' => 'Pagekit\Installer\Controller\UpdateController',
        ],

    ],

    'languages' => '/../system/languages',

    'resources' => [

        'installer:' => '',

    ],

    'permissions' => [

        'system: manage packages' => [
            'title' => 'Manage extensions and themes',
            'description' => 'Manage extensions and themes',
        ],
        'system: software updates' => [
            'title' => 'Apply system updates',
            'trusted' => true,
        ],

    ],

    'menu' => [

        'system: marketplace' => [
            'label' => 'Marketplace',
            'icon' => 'installer:assets/images/icon-marketplace.svg',
            'url' => '@system/marketplace/extensions',
            'access' => 'system: manage packages',
            'priority' => 125,
        ],

        'system: marketplace extensions' => [
            'label' => 'Extensions',
            'parent' => 'system: marketplace',
            'url' => '@system/marketplace/extensions',
        ],

        'system: marketplace themes' => [
            'label' => 'Themes',
            'parent' => 'system: marketplace',
            'url' => '@system/marketplace/themes',
        ],

        'system: extensions' => [
            'label' => 'Extensions',
            'parent' => 'system: system',
            'url' => '@system/package/extensions',
            'access' => 'system: manage packages',
            'priority' => 5,
        ],

        'system: themes' => [
            'label' => 'Themes',
            'parent' => 'system: system',
            'url' => '@system/package/themes',
            'access' => 'system: manage packages',
            'priority' => 10,
        ],

        'system: update' => [
            'label' => 'Update',
            'parent' => 'system: system',
            'url' => '@system/update',
            'priority' => 25,
        ],

    ],

    'config' => [

        'enabled' => false,
        'release_channel' => 'stable',

    ],

];
