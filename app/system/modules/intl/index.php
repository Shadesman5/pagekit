<?php

declare(strict_types=1);

return [

    'name' => 'system/intl',

    'main' => 'Pagekit\\Intl\\IntlModule',

    'autoload' => [

        'Pagekit\\Intl\\' => 'src',

    ],

    'resources' => [

        'system/intl:' => '',

    ],

    'routes' => [
        '/system/intl' => [
            'name' => '@system/intl',
            'controller' => 'Pagekit\\Intl\\Controller\\IntlController',
        ],
        '/api/system/intl' => [
            'name' => '@system/api/intl',
            'controller' => 'Pagekit\\Intl\\Controller\\IntlApiController',
        ],
    ],

    'config' => [

        'locale' => 'en_US',

    ],

    'events' => [

        'boot' => function ($event, $app) {
            $translator = $app->get('translator');
            if (!$translator instanceof \Symfony\Component\Translation\Translator) {
                throw new \RuntimeException('translator service must be an instance of Translator');
            }
            \Pagekit\Intl\IntlServiceLocator::register(
                new \Pagekit\Intl\IntlServiceLocator($translator, $this)
            );
        },

        'view.init' => function ($event, $view) {
            $view->addGlobal('intl', $this);
        },

    ],

];
