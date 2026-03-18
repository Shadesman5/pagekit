<?php

use Pagekit\Config\ConfigManager;

return [

    'name' => 'config',

    'main' => function ($app) {

        $app->set('config', fn($app) => new ConfigManager($app->get('db'), $this->config));

        if ($app->get('config.file') && file_exists($app->get('config.file'))) {
            $app->get('module')->addLoader(function ($module) use ($app) {

                if ($app->get('config')->has($module['name'])) {
                    $module['config'] = array_replace($module['config'],
                        $app->get('config')->get($module['name'])->toArray()
                    );
                }

                return $module;
            });
        }

    },

    'require' => [

        'database'

    ],

    'autoload' => [

        'Pagekit\\Config\\' => 'src'

    ],

    'config' => [

        'table'  => '@system_config'

    ],

    'events' => [

        'terminate' => [function () use ($app) {
            foreach ($app->get('config') as $name => $config) {
                $app->get('config')->set($name, $config);
            }
        }, 100]

    ]

];
