<?php

use Pagekit\Feed\FeedFactory;

return [

    'name' => 'feed',

    'main' => function ($app) {

        $app->set('feed', fn() => new FeedFactory);

    },

    'autoload' => [

        'Pagekit\\Feed\\' => 'src'

    ]

];
