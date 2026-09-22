<?php

declare(strict_types=1);

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

];
