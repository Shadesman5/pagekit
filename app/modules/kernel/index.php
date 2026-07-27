<?php

declare(strict_types=1);

use Pagekit\Kernel\Controller\ControllerListener;
use Pagekit\Kernel\Controller\ControllerResolver;
use Pagekit\Kernel\Event\JsonResponseListener;
use Pagekit\Kernel\Event\ResponseListener;
use Pagekit\Kernel\Event\StringResponseListener;
use Pagekit\Kernel\HttpKernel;
use Symfony\Component\HttpFoundation\RequestStack;

return [

    'name' => 'kernel',

    'main' => function ($app) {

        $app->set('kernel', function ($app) {

            $app->get('events')->subscribe(new ControllerListener($app->get('resolver')));
            $app->get('events')->subscribe(new ResponseListener());
            $app->get('events')->subscribe(new JsonResponseListener());
            $app->get('events')->subscribe(new StringResponseListener());

            return new HttpKernel($app->get('events'), $app->get('request.stack'));
        });

        $app->set('resolver', fn ($app) => new ControllerResolver($app));

        $app->factory('request', fn ($app) => $app->get('request.stack')->getCurrentRequest());

        $app->set('request.stack', fn () => new RequestStack());

    },

    'events' => [

        'request' => [function ($event, $request) use ($app) {

            if ($app->inConsole()) {
                return;
            }

            $path = $request->getPathInfo();

            // redirect the request if it has a trailing slash
            if ('/' != $path && '/' == substr($path, -1) && '//' != substr($path, -2)) {
                $event->setResponse($app->get('router')->redirect(rtrim($request->getUriForPath($path), '/'), [], 301));
            }

        }, 200],

    ],

    'autoload' => [

        'Pagekit\\Kernel\\' => 'src',

    ],

];
