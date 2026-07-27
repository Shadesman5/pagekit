<?php

declare(strict_types=1);

use Pagekit\Session\Csrf\Event\CsrfListener;
use Pagekit\Session\Csrf\Provider\SessionCsrfProvider;
use Pagekit\Session\Handler\DatabaseSessionHandler;
use Pagekit\Session\MessageBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

return [

    'name' => 'session',

    'main' => function ($app) {

        $app->set('session', function ($app) {
            $session = new Session($app->get('session.storage'));
            $session->registerBag($app->get('message'));

            return $session;
        });

        $app->set('message', fn () => new MessageBag());

        $app->set('session.storage', function ($app) {

            switch ($this->config['storage']) {

                case 'database':

                    $handler = new DatabaseSessionHandler($app->get('db'), $this->config['table']);
                    $storage = new NativeSessionStorage($app->get('session.options'), $handler);

                    break;

                default:

                    $handler = new NativeFileSessionHandler($this->config['files']);
                    $storage = new NativeSessionStorage($app->get('session.options'), $handler);

                    break;
            }

            return $storage;
        });

        $app->set('session.options', function () {

            $options = $this->config(['cookie', 'lifetime']);

            if (isset($options['cookie'])) {

                foreach ($options['cookie'] as $name => $value) {
                    $options[$name == 'name' ? 'name' : 'cookie_' . $name] = $value;
                }

                unset($options['cookie']);
            }

            if (isset($options['lifetime']) && !isset($options['gc_maxlifetime'])) {
                $options['gc_maxlifetime'] = $options['lifetime'];
            }

            return $options;
        });

        $app->set('csrf', function ($app) {
            return new SessionCsrfProvider($app->get('session'));
        });

    },

    'events' => [

        'boot' => function ($event, $app) {

            $app->get('events')->subscribe(new CsrfListener($app->get('csrf')));

        },

        'request' => [function ($event, $request) use ($app) {

            // Skip session initialization in CLI context
            if ($app->inConsole()) {
                return;
            }

            if (!$app->has('session.options') || !isset($app->get('session.options')['cookie_path'])) {
                $app->get('session.storage')->setOptions(['cookie_path' => $request->getBasePath() ?: '/']);
            }

            $request->setSession($app->get('session'));

            $app->get('session')->start();

        }, 100],

    ],

    'autoload' => [

        'Pagekit\\Session\\' => 'src',

    ],

    'config' => [

        'storage' => null,
        'lifetime' => 900,
        'files' => null,
        'table' => 'sessions',
        'cookie' => [
            'name' => '',
        ],

    ],

];
