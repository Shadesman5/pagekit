<?php

declare(strict_types=1);

use Pagekit\Event\PrefixEventDispatcher;
use Pagekit\View\Asset\AssetFactory;
use Pagekit\View\Asset\AssetManager;
use Pagekit\View\Engine\DelegatingEngine;
use Pagekit\View\Engine\PhpEngineAdapter;
use Pagekit\View\Engine\TwigEngineAdapter;
use Pagekit\View\Helper\DataHelper;
use Pagekit\View\Helper\DeferredHelper;
use Pagekit\View\Helper\GravatarHelper;
use Pagekit\View\Helper\MapHelper;
use Pagekit\View\Helper\MarkdownHelper;
use Pagekit\View\Helper\MetaHelper;
use Pagekit\View\Helper\ScriptHelper;
use Pagekit\View\Helper\SectionHelper;
use Pagekit\View\Helper\StyleHelper;
use Pagekit\View\Helper\TokenHelper;
use Pagekit\View\Helper\UrlHelper;
use Pagekit\View\Loader\FilesystemLoader;
use Pagekit\View\PhpEngine;
use Pagekit\View\View;
use Symfony\Component\HttpFoundation\Response;

return [

    'name' => 'view',

    'include' => 'modules/*/index.php',

    'require' => [

        'view/twig',

    ],

    'main' => function ($app) {

        $app->set('view', fn ($app) => new View(new PrefixEventDispatcher('view.', $app->get('events'))));

        $app->set('assets', fn () => new AssetFactory());

        $app->set('styles', fn ($app) => new AssetManager($app->get('assets')));

        $app->set('scripts', fn ($app) => new AssetManager($app->get('assets')));

        $app->get('module')->addLoader(function ($module) use ($app) {

            if (isset($module['views'])) {
                $app->extend('view', function ($view) use ($module) {
                    foreach ((array) $module['views'] as $name => $path) {
                        $view->map($name, $path);
                    }

                    return $view;
                });
            }

            return $module;
        });

    },

    'events' => [

        'controller' => [function ($event) use ($app) {

            $view = $app->get('view');
            $layout = true;
            $result = $event->getControllerResult();

            if (is_array($result) && isset($result['$view'])) {

                foreach ($result as $key => $value) {
                    if ($key === '$view') {

                        if (isset($value['name'])) {
                            $name = $value['name'];
                            unset($value['name']);
                        }

                        if (isset($value['layout'])) {
                            $layout = $value['layout'];
                            unset($value['layout']);
                        }

                        $app->get('events')->on('view.meta', function ($event, $meta) use ($value) {
                            $meta($value);
                        });

                    } elseif ($key[0] === '$') {

                        $view->data($key, $value);

                    }
                }

                if (isset($name)) {
                    $response = $result = $view->render($name, $result);
                }
            }

            if (!is_string($result)) {
                return;
            }

            if (is_string($layout)) {
                $view->map('layout', $layout);
            }

            if ($layout) {

                $view->section('content', (string) $result);

                if (null !== $result = $view->render('layout')) {
                    $response = $result;
                }
            }

            if (isset($response)) {
                $event->setResponse(new Response($response));
            }

        }, 50],

        'view.init' => [function ($event, $view) use ($app) {

            $delegatingEngine = new DelegatingEngine();

            $phpEngine = new PhpEngine(null, $app->has('locator') ? new FilesystemLoader($app->get('locator')) : null);
            $delegatingEngine->addEngine(new PhpEngineAdapter($phpEngine));

            if ($app->has('twig')) {
                $delegatingEngine->addEngine(new TwigEngineAdapter($app->get('twig')));
            }

            $view->addEngine($delegatingEngine);

            $view->addGlobal('app', $app);
            $view->addGlobal('view', $view);

            $view->addHelpers([
                new DataHelper(),
                new DeferredHelper($app->get('events')),
                new GravatarHelper(),
                new MapHelper(),
                new MetaHelper(),
                new ScriptHelper($app->get('scripts')),
                new SectionHelper(),
                new StyleHelper($app->get('styles')),
                new UrlHelper($app->get('url')),
            ]);

            if ($app->has('csrf')) {
                $view->addHelper(new TokenHelper($app->get('csrf')));
            }

            if ($app->has('markdown')) {
                $view->addHelper(new MarkdownHelper($app->get('markdown')));
            }

        }, 50],

    ],

    'autoload' => [

        'Pagekit\\View\\' => 'src',

    ],

];
