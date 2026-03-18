<?php

use Pagekit\Content\ContentHelper;
use Pagekit\Content\Plugin\MarkdownPlugin;
use Pagekit\Content\Plugin\SimplePlugin;
use Pagekit\Content\Plugin\VideoPlugin;

return [

    'name' => 'system/content',

    'main' => function ($app) {

        $app->subscribe(
            new MarkdownPlugin,
            new SimplePlugin,
            new VideoPlugin
        );

        $app['content'] = fn() => new ContentHelper; // TODO: Must be refactored in Step 2.0.1d (Packages + ArrayAccess Removal)

    },

    'autoload' => [

        'Pagekit\\Content\\' => 'src'

    ]

];
