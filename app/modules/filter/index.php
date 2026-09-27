<?php

declare(strict_types=1);

use Pagekit\Filter\FilterManager;

return [

    'main' => function ($app) {

        $app->set('filter', fn () => new FilterManager($this->config['defaults']));

    },

    'config' => [

        'defaults' => null,

    ],
];
