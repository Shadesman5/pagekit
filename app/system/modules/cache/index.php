<?php

return [

    'name' => 'system/cache',

    'main' => 'Pagekit\\Cache\\CacheModule',

    'autoload' => [

        'Pagekit\\Cache\\' => 'src',

    ],

    'routes' => [

        '/system/cache' => [
            'name' => '@system/cache',
            'controller' => 'Pagekit\\Cache\\Controller\\CacheController',
        ],

    ],

    'config' => [

        'caches' => [],
        'nocache' => false,

    ],

    'events' => [

        'view.system:modules/settings/views/settings' => function ($event, $view) use ($app) {

            $supported = $this->supports();

            // Modern cache options only
            $caches = [
                'auto' => ['name' => '', 'supported' => true],
                'file' => ['name' => 'File', 'supported' => in_array('file', $supported)],
                'phpfile' => ['name' => 'PHP File', 'supported' => in_array('phpfile', $supported)],
            ];

            // Add APCu only if available (modern PHP opcode cache)
            if (in_array('apcu', $supported)) {
                $caches['apcu'] = ['name' => 'APCu Memory', 'supported' => true];
            }

            // Set auto name based on best available option
            $bestOption = 'file';
            if (in_array('apcu', $supported)) {
                $bestOption = 'apcu';
            } elseif (in_array('phpfile', $supported)) {
                $bestOption = 'phpfile';
            }

            $caches['auto']['name'] = "Auto ({$caches[$bestOption]['name']})";

            $view->data('$caches', $caches);
            $view->data('$settings', ['config' => [$this->name => $this->config(['caches.cache.storage', 'nocache'])]]);
            $view->script('settings-cache', 'app/system/modules/cache/app/bundle/settings.js', 'settings');

        },

        'after@system/settings/save' => function () {
            $this->clearCache();
        },

    ],

];
