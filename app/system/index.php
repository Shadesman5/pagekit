<?php

declare(strict_types=1);

use Pagekit\Installer\Package\Lifecycle\LifecycleRunner;
use Pagekit\Kernel\Event\ExceptionListener;
use Pagekit\System\Extension\ExtensionFailureStore;

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
        'system/user',

    ],

    'routes' => [

        '/' => [
            'name' => '@system',
            'controller' => 'Pagekit\\System\\Controller\\AdminController',
        ],
        '/system/migration' => [
            'name' => '@system/migration',
            'controller' => 'Pagekit\\System\\Controller\\MigrationController',
        ],

    ],

    'resources' => [

        'system:' => '',

    ],

    'config' => [

        'site' => [

            'theme' => null,
            'locale' => 'en_US',

        ],

        'admin' => [

            'locale' => 'en_US',

        ],

        'extensions' => [],

        'packages' => [],

    ],

    'events' => [

        'boot' => function ($event, $app) {

            // Symfony Validator with Translator integration ('validators' domain).
            // Translator is registered in IntlModule::main() (container phase); validator resolves it lazily.
            \Pagekit\System\ValidatorServiceProvider::register($app);

            if (!$app->get('debug')) {
                $app->get('events')->subscribe(new ExceptionListener('Pagekit\System\Controller\ExceptionController::showAction'));
            }

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

                $app->get('events')->trigger($app->get('isAdmin') ? 'admin' : 'site', [$app]);

            }],

        ],

        'auth.login' => [function ($event) use ($app) {
            if ($event->getUser()->hasAccess('system: software updates') && version_compare($this->config('version'), $app->get('version'), '<')) {

                // What the installation still owes is read from a file it ships
                // and from a service that talks to the database, and the
                // half-finished upgrade that leaves an update outstanding is
                // exactly what breaks either of them. Answering a login with a
                // stack trace helps nobody, and recording the new version
                // regardless would be worse: that declares the update done and
                // never offers it again. So a failed check is reported and the
                // recorded version left where it is - it is asked again on the
                // next login, and the administrator reaches the panel the
                // repair is made from in the meantime.
                try {
                    $lifecycle = new LifecycleRunner($this->path . '/scripts.php', $this->config('version'), $app);
                    $migrationStatus = $app->has('migration') ? $app->get('migration')->status() : ['success' => true, 'has_pending' => false];
                    $hasPendingMigrations = !($migrationStatus['success'] ?? false) || ($migrationStatus['has_pending'] ?? false);

                    if ($lifecycle->hasUpdates() || $hasPendingMigrations) {
                        $event->setResponse($app->get('response')->redirect('@system/migration', ['redirect' => $app->get('url')->getRoute('@system')]));
                    } else {
                        $app->get('config')('system')->set('version', $app->get('version'));
                    }
                } catch (\Throwable $e) {
                    try {
                        $app->get('log')->error(sprintf('The update check on login failed: %s', $e->getMessage()), ['exception' => $e]);
                        $app->get('message')->error(__('Pagekit could not determine whether this installation needs an update. See the error log for details.'));
                    } catch (\Throwable) {
                        // Reporting it is the last thing tried and the last
                        // thing allowed to raise anything of its own: a log or a
                        // session that cannot be written to costs the report,
                        // not the login.
                    }
                }
            }
        }, 8],

        'view.init' => function ($event, $view) use ($app) {
            $theme = $app->get('isAdmin') ? $app->get('module')->get('system/theme') : $app->get('theme');
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

            // A failed package is named from the durable record on every admin
            // render, not queued as a message when it fails: the request that
            // hit the failure usually belongs to a visitor, and a per-session
            // message would be spent on whoever was there instead of reaching
            // someone who can act on it. Read from the record, the notice stands
            // until the record is cleared, with no session state to expire.
            // Only the name is shown; what it failed with stays in the log.
            try {
                $store = $app->get('isAdmin') && $app->has('extension.failures') ? $app->get('extension.failures') : null;
                $failures = $store instanceof ExtensionFailureStore ? $store->all() : [];

                // Who is asking comes last: answering it takes the database the
                // site may just have lost, and with nothing to report it is a
                // question nobody needs answered.
                if ($failures !== [] && $app->get('user')->hasAccess('system: manage packages')) {
                    foreach ($failures as $failure) {
                        $name = htmlspecialchars($failure['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                        $notice = $failure['type'] === ExtensionFailureStore::TYPE_THEME
                            ? __('The theme "%name%" could not be loaded. See the error log for details.', ['%name%' => $name])
                            : __('The extension "%name%" failed and was disabled. See the error log for details.', ['%name%' => $name]);

                        $result .= sprintf('<div class="uk-alert uk-alert-warning" data-status="warning">%s</div>', $notice);
                    }
                }
            } catch (\Throwable) {
                // Reporting a failure must not become one. This renders in the
                // panel the site is put back together from, which has to come
                // up even when deriving the notice is what breaks.
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
        }, -50],

    ],

];
