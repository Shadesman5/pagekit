<?php

declare(strict_types=1);

use Pagekit\Markdown\Markdown;

return [

    'name' => 'markdown',

    'main' => function ($app) {

        $app->set('markdown', fn () => new Markdown());

    },

    'autoload' => [

        'Pagekit\\Markdown\\' => 'src',

    ],

];
