<?php

use Pagekit\Content\ContentHelper;
use Pagekit\Content\Plugin\MarkdownPlugin;
use Pagekit\Content\Plugin\SimplePlugin;
use Pagekit\Content\Plugin\VideoPlugin;

return [

    'name' => 'system/content',

    'main' => function ($app) {

        $app->get('events')->subscribe(new MarkdownPlugin($app->get('markdown')));
        $app->get('events')->subscribe(new SimplePlugin);
        $app->get('events')->subscribe(new VideoPlugin);

        $app->set('content', fn() => new ContentHelper($app->get('events')));

    },

    'autoload' => [

        'Pagekit\\Content\\' => 'src'

    ]

];
