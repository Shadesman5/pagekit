<?php

use Twig\Environment;
use Twig\Extension\DebugExtension;
use Pagekit\Twig\TwigCache;
use Pagekit\Twig\TwigLoader;
use Pagekit\View\Loader\FilesystemLoader;
return [

    'name' => 'view/twig',

    'main' => function ($app) {

        $app['twig'] = function ($app) {

            $twig = new Environment(new TwigLoader($app->has('locator') ? new FilesystemLoader($app->get('locator')) : null), [
                'cache' => new TwigCache($app->get('path.cache')),
                'auto_reload' => true,
                'debug' => $app->get('debug'),
            ]);

            if ($app->has('debug') && $app->get('debug')) {
                $twig->addExtension(new DebugExtension());
            }

            return $twig;

         };

    },

    'autoload' => [

        'Pagekit\\Twig\\' => 'src'

    ]

];
