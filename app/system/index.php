<?php

use Pagekit\Installer\Package\PackageScripts;
use Pagekit\Kernel\Event\ExceptionListener;

return [

    'name' => 'system',

    'main' => 'Pagekit\\System\\SystemModule',

    'include' => 'modules/*/index.php',

    'require' => [

        'application',
        'feed',
        'markdown',
        'installer',
        'migration',
        'system/captcha',
        'system/view',
        'system/widget',
        'system/cache',
        'system/comment',
        'system/content',
        'system/dashboard',
        'system/editor',
        'system/finder',
        'system/info',
        'system/intl',
        'system/mail',
        'system/settings',
        'system/site',
        'system/theme',
        'system/user'

    ],

    'routes' => [

        '/' => [
            'name' => '@system',
            'controller' => 'Pagekit\\System\\Controller\\AdminController'
        ],
        '/system/migration' => [
            'name' => '@system/migration',
            'controller' => 'Pagekit\\System\\Controller\\MigrationController'
        ]

    ],

    'resources' => [

        'system:' => ''

    ],

    'config' => [

        'site' => [

            'theme' => null,
            'locale' => 'en_US'

        ],

        'admin' => [

            'locale' => 'en_US'

        ],

        'extensions' => [],

        'packages' => []

    ],

    'events' => [

        'boot' => function ($event, $app) {

            // Register Symfony Validator service (Step 1.13 - Hybrid Mode)
            // Uses PHP 8 Attributes for validation while ORM still uses Doctrine Annotations
            \Pagekit\System\ValidatorServiceProvider::register($app);

            if (!$app->get('debug')) {
                $app->subscribe(new ExceptionListener('Pagekit\System\Controller\ExceptionController::showAction'));
            }

            $app->get('db.em'); // -TODO- fix me

        },

        'request' => [

            [function ($event, $request) use ($app) {

                if (!$event->isMasterRequest()) {
                    return;
                }

                $app->set('isAdmin', $admin = (bool) preg_match('#^/admin(/?$|/.+)#', $request->getPathInfo()));
                $app->get('module')->get('system/intl')->setLocale($this->config($admin ? 'admin.locale' : 'site.locale'));

            }, 150],

            [function ($event) use ($app) {

                if (!$event->isMasterRequest()) {
                    return;
                }

                $app->trigger($app->isAdmin() ? 'admin' : 'site', [$app]);

            }]

        ],

        'auth.login' => [function ($event) use ($app) {
            if ($event->getUser()->hasAccess('system: software updates') && version_compare($this->config('version'), $app->version(), '<')) {

                $scripts = new PackageScripts($this->path . '/scripts.php', $this->config('version'));

                if ($scripts->hasUpdates()) {
                    $event->setResponse($app->get('response')->redirect('@system/migration', ['redirect' => $app->get('url')->getRoute('@system')]));
                } else {
                    $app->get('config')('system')->set('version', $app->version());
                }
            }
        }, 8],

        'view.init' => function ($event, $view) use ($app) {
            $theme = $app->isAdmin() ? $app->get('module')->get('system/theme') : $app->get('theme');
            $view->map('layout', $theme->get('layout', 'views:template.php'));
            $view->addGlobal('theme', $app->get('theme'));
        },

        'view.messages' => function ($event) use ($app) {

            $result = '';

            if ($app->get('message')->peekAll()) {
                foreach ($app->get('message')->levels() as $level) {
                    if ($messages = $app->get('message')->get($level)) {
                        foreach ($messages as $message) {
                            $result .= sprintf('<div class="uk-alert uk-alert-%1$s" data-status="%1$s">%2$s</div>', $level == 'error' ? 'danger' : $level, $message);
                        }
                    }
                }
            }

            $event->setResult(sprintf('<div class="pk-system-messages">%s</div>', $result));
        },

        'view.meta' => [function ($event, $meta) use ($app) {

            if ($meta->get('title')) {
                $title[] = $meta->get('title');
            }
            $title[] = $app->get('config')('system/site')->get('title');
            if ($app->get('request')->getPathInfo() === '/') {
                $title = array_reverse($title);
            }

            $meta->add('title', implode(' | ', $title));
        }, -50]

    ]

];
