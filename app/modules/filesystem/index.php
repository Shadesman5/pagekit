<?php

declare(strict_types=1);

use Pagekit\Filesystem\Adapter\FileAdapter;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\Locator;
use Pagekit\Filesystem\Path;
use Pagekit\Filesystem\StreamWrapper;

return [

    'name' => 'filesystem',

    'main' => function ($app) {

        $app->set('file', fn () => new Filesystem());

        $app->set('locator', fn () => new Locator($this->config['path'], $app->get('path.public')));

        $app->get('module')->addLoader(function ($module) use ($app) {

            if (isset($module['resources'])) {
                foreach ($module['resources'] as $prefix => $path) {
                    $app->get('locator')->add($prefix, "{$module['path']}/$path");
                }
            }

            return $module;
        });

    },

    'events' => [

        'boot' => function ($event, $app) {

            StreamWrapper::setFilesystem($app->get('file'));

        },

        'request' => [function ($event, $request) use ($app) {

            $baseUrl = $request->getSchemeAndHttpHost().$request->getBasePath();
            $root = Path::directory($this->config['path']);
            $storage = Path::directory($app->get('path.storage'));

            // The media library is reached through a link into the webroot that the
            // application never resolves paths through, so its URL comes from where
            // it sits relative to the application root. A storage configured outside
            // that root cannot be linked and keeps the URL-less state every
            // unpublished directory has.
            $relative = strpos($storage, $root) === 0 ? trim(substr($storage, strlen($root)), '/') : '';
            $mounts = $relative !== '' ? [$storage => "$baseUrl/$relative"] : [];

            $app->get('file')->registerAdapter('file', new FileAdapter($app->get('path.public'), $baseUrl, $mounts));

        }, 100],
    ],

    'autoload' => [

        'Pagekit\\Filesystem\\' => 'src',

    ],

    'config' => [

        'path' => getcwd(),

    ],

];
