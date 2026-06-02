<?php

declare(strict_types=1);

return [

    'name' => 'system/text',

    'label' => 'Text',

    'render' => fn ($widget) => $app->get('view')->render('system/site/widget-text.php', compact('widget')),

    'events' => [

        'view.scripts' => function ($event, $scripts) {
            $scripts->register('widget-text', 'system/site:app/bundle/widget-text.js', ['~widgets']);
        },

    ],

];
