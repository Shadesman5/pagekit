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

    'resources' => [

        'package:' => '',

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
