<?php

declare(strict_types=1);

use Pagekit\Package\Snapshot\SnapshotStore;

return [

    'name' => 'package',

    'main' => 'Pagekit\\Package\\PackageModule',

    'require' => [

        'application',
        'migration',
        'system/intl',
        'system/view',

    ],

    'routes' => [

        '/system/package' => [
            'name' => '@system/package',
            'controller' => 'Pagekit\Package\Controller\PackageController',
        ],
        '/system/snapshot' => [
            'name' => '@system/snapshot',
            'controller' => 'Pagekit\Package\Controller\SnapshotController',
        ],

    ],

    'resources' => [

        'package:' => '',

    ],

    'permissions' => [

        'system: manage packages' => [
            'title' => 'Manage extensions and themes',
            'description' => 'Manage extensions and themes',
        ],

    ],

    'menu' => [

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

        'system: snapshots' => [
            'label' => 'Snapshots',
            'parent' => 'system: system',
            'url' => '@system/snapshot',
            'access' => 'system: manage packages',
            'priority' => 15,
        ],

    ],

    'config' => [

        'snapshots' => [

            // Days a snapshot of a removed package is kept, after which a purge
            // may reclaim the disk it holds. Nothing runs on a timer: the window
            // is enforced when the next snapshot is taken and when an
            // administrator asks for it. Zero or less keeps every snapshot until
            // somebody purges it by hand.
            'retention_days' => SnapshotStore::DEFAULT_RETENTION_DAYS,

        ],

    ],

];
