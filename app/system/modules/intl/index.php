<?php

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
            \Pagekit\Intl\IntlServiceLocator::setTranslator($app->get('translator'));
            // $this is the IntlModule instance — it IS the intl service (provides
            // locale, date/number formatting, territory data). No separate 'intl'
            // service is registered; the module object is the API surface.
            \Pagekit\Intl\IntlServiceLocator::setIntl($this);
        },

        'view.init' => function ($event, $view) {
            $view->addGlobal('intl', $this);
        },

    ],

];
