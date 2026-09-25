<?php

declare(strict_types=1);

use Pagekit\Kernel\Event\ExceptionListenerWrapper;
use Pagekit\Kernel\Exception\NotFoundException;

return [

    'name' => 'installer',

    'main' => function ($app) {

        $config = $this->config;

        if ($config['enabled']) {

            $app->extend('assets', function ($factory) use ($app) {

                $factory->setVersion($app->get('version'));

                return $factory;

            });

            $app->get('routes')->add([
                'path' => '/installer',
                'name' => '@installer',
                'controller' => 'Pagekit\Installer\Controller\InstallerController',
            ]);

            $app->get('events')->on('request', function ($event, $request) use ($app) {

                $locale = $request->get('locale') ?: $app->get('request')->getPreferredLanguage();
                $available = $app->get('module')->get('system/intl')->getAvailableLanguages();

                if (is_string($locale) && $locale !== '' && isset($available[$locale])) {
                    $app->get('module')->get('system/intl')->setLocale($locale);
                }

            });

            $app->get('events')->on('exception', new ExceptionListenerWrapper(fn (NotFoundException $e) => $app->get('router')->redirect('@installer')), -8);

        }

    },

    'require' => [

        'application',
        'migration',
        'package',
        'system/cache',
        'system/intl',
        'system/view',

    ],

    'routes' => [

        '/system/update' => [
            'name' => '@system/update',
            'controller' => 'Pagekit\Installer\Controller\UpdateController',
        ],

    ],

    'languages' => '/../system/languages',

    'resources' => [

        'installer:' => '',

    ],

    'permissions' => [

        'system: software updates' => [
            'title' => 'Apply system updates',
            'trusted' => true,
        ],

    ],

    'menu' => [

        'system: update' => [
            'label' => 'Update',
            'parent' => 'system: system',
            'url' => '@system/update',
            'priority' => 25,
        ],

    ],

    'config' => [

        'enabled' => false,
        'release_channel' => 'stable',

    ],

];
