<?php

declare(strict_types=1);

use Pagekit\Filter\FilterManager;

return [

    'name' => 'filter',

    'main' => function ($app) {

        $app->set('filter', fn () => new FilterManager($this->config['defaults']));

    },

    'autoload' => [

        'Pagekit\\Filter\\' => 'src',

    ],

    'config' => [

        'defaults' => null,

    ],
];
