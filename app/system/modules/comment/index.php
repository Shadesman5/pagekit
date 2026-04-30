<?php

declare(strict_types=1);

use Pagekit\Comment\CommentPlugin;

return [

    'name' => 'system/comment',

    'main' => function ($app) {

        $app->get('events')->subscribe(new CommentPlugin());

    },

    'autoload' => [

        'Pagekit\\Comment\\' => 'src',

    ],

];
