<?php

declare(strict_types=1);

use Pagekit\Feed\FeedFactory;

return [

    'main' => function ($app) {

        $app->set('feed', fn () => new FeedFactory());

    },

];
