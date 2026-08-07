<?php

declare(strict_types=1);

return [

    'name' => 'system/editor',

    'autoload' => [

        'Pagekit\\Editor\\' => 'src',

    ],

    'config' => [

        'editor' => 'html',

    ],

    'resources' => [

        'system/editor:' => '',

    ],

    'events' => [

        // Add editor data via view.data event
        'view.data' => function ($event, $data) use ($app) {
            $data->add('$editor', [
                // The editor's JS appends its asset paths, so this must stay the
                // module's published directory without a trailing slash.
                'root_url' => $app->get('url')->getStatic('system/editor:'),
            ]);
        },

        // Register editor script
        'view.scripts' => function ($event, $scripts) {
            $scripts->register('editor', 'system/editor:app/bundle/editor.js', ['input-link', 'pagekit-config']);
        },

    ],

];
