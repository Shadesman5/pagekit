<?php

use Pagekit\Installer\Package\PackageFactory;
use Pagekit\Kernel\Exception\NotFoundException;

return [

    'name' => 'installer',

    'main' => function ($app) {

        $app->set('package', fn($app) => (new PackageFactory())->addPath($app->get('path').'/packages/*/*/composer.json'));

        if ($this->config['enabled']) {

            $app->extend('assets', function ($factory) use ($app) {

                $factory->setVersion($app->get('version'));

                return $factory;

            });

            $app->get('routes')->add([
                'path' => '/installer',
                'name' => '@installer',
                'controller' => 'Pagekit\Installer\Controller\InstallerController'
            ]);

            $app->get('events')->on('request', function ($event, $request) use ($app) {

                $locale = $request->get('locale') ?: $app->get('request')->getPreferredLanguage();
                $available = $app->get('module')->get('system/intl')->getAvailableLanguages();

                if (isset($available[$locale])) {
                    $app->get('module')->get('system/intl')->setLocale($locale);
                }

            });

            $app->error(fn(NotFoundException $e) => $app->get('response')->redirect('@installer')); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal) — App::error()

        }

    },

    'require' => [

        'application',
        'migration',
        'system/cache',
        'system/intl',
        'system/view'

    ],

    'routes' => [

        '/system/package' => [
            'name' => '@system/package',
            'controller' => 'Pagekit\Installer\Controller\PackageController'
        ],
        '/system/marketplace' => [
            'name' => '@system/marketplace',
            'controller' => 'Pagekit\Installer\Controller\MarketplaceController'
        ],
        '/system/update' => [
            'name' => '@system/update',
            'controller' => 'Pagekit\Installer\Controller\UpdateController'
        ]

    ],

    'languages' => '/../system/languages',

    'resources' => [

        'installer:' => ''

    ],

    'permissions' => [

        'system: manage packages' => [
            'title' => 'Manage extensions and themes',
            'description' => 'Manage extensions and themes'
        ],
        'system: software updates' => [
            'title' => 'Apply system updates',
            'trusted' => true
        ]

    ],

    'menu' => [

        'system: marketplace' => [
            'label' => 'Marketplace',
            'icon' => 'installer:assets/images/icon-marketplace.svg',
            'url' => '@system/marketplace/extensions',
            'access' => 'system: manage packages',
            'priority' => 125
        ],

        'system: marketplace extensions' => [
            'label' => 'Extensions',
            'parent' => 'system: marketplace',
            'url' => '@system/marketplace/extensions'
        ],

        'system: marketplace themes' => [
            'label' => 'Themes',
            'parent' => 'system: marketplace',
            'url' => '@system/marketplace/themes'
        ],

        'system: extensions' => [
            'label' => 'Extensions',
            'parent' => 'system: system',
            'url' => '@system/package/extensions',
            'access' => 'system: manage packages',
            'priority' => 5
        ],

        'system: themes' => [
            'label' => 'Themes',
            'parent' => 'system: system',
            'url' => '@system/package/themes',
            'access' => 'system: manage packages',
            'priority' => 10
        ],

        'system: update' => [
            'label' => 'Update',
            'parent' => 'system: system',
            'url' => '@system/update',
            'priority' => 25
        ]

    ],

    'events' => [

        'view.data' => function ($event, $view) use ($app) {
            // Set $pagekit variable for installer JavaScript
            $installer = $app->get('module')->get('installer');
            if ($installer && $installer->config('enabled')) {
                $request = $app->get('request');
                
                // Build URL - always include index.php for installer
                $url = '/index.php';
                
                $view->data('$pagekit', [
                    'url' => $url,
                    'csrf' => $app->get('csrf')->generate()
                ]);
            }
        }

    ],

    'config' => [

        'enabled' => false,
        'release_channel' => 'stable'

    ]

];
