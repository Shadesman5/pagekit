<?php

declare(strict_types=1);

use Pagekit\Comment\CommentPlugin;

return [

    'main' => function ($app) {

        $app->get('events')->subscribe(new CommentPlugin());

    },

];
