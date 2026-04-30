<?php

declare(strict_types=1);

use Pagekit\Util\ArrObject;
use Pagekit\View\Asset\FileLocatorAsset;
use Pagekit\View\Event\ResponseListener;
use Twig\TwigFilter;

return [

    'name' => 'system/view',

    'main' => function ($app) {

        $app->extend('twig', function ($twig) use ($app) {

            $twig->addFilter(new TwigFilter('trans', '__'));
            // TODO: Must be refactored in Step 3.4.6 (Translation System Modernization) —
            // Remove transChoice Twig filter when _c() is removed.
            $twig->addFilter(new TwigFilter('transChoice', '_c'));

            return $twig;

        });

        $app->extend('assets', function ($assets) use ($app) {

            $assets->register('file', 'Pagekit\View\Asset\FileLocatorAsset');

            return $assets;
        });

        // TODO: Must be refactored in Step 2.1.6 (PHPStan Level 7→8 — Strict Typing) —
        // FileLocatorAsset uses static service locator pattern (static mixed properties).
        // Replace with proper DI once asset classes support constructor injection.
        FileLocatorAsset::setServices($app->get('file'), $app->get('locator'));

    },

    'autoload' => [

        'Pagekit\\View\\' => 'src',

    ],

    'events' => [

        'boot' => function ($event, $app) {
            $app->get('events')->subscribe(new ResponseListener($app->get('url')));
        },

        'site' => function ($event, $app) {
            $app->get('events')->on('view.meta', function ($event, $meta) use ($app) {
                $meta->add('canonical', $app->get('url')->get($app->get('request')->attributes->get('_route'), $app->get('request')->attributes->get('_route_params', []), 0));
            }, 60);
        },

        'view.init' => [function ($event, $view) {
            $view->defer('head');
            $view->meta(['generator' => 'Pagekit']);
            $view->addGlobal('params', new ArrObject());
        }, 20],

        'view.data' => function ($event, $data) use ($app) {
            // Get base URL from router context
            // - With mod_rewrite: '' (empty string) - URLs like /admin
            // - Without mod_rewrite: '/index.php' - URLs like /index.php/admin
            $baseUrl = $app->get('router')->getContext()->getBaseUrl();

            // Only use fallback in installer context (no config.php yet)
            // In normal operation, empty baseUrl is correct for mod_rewrite
            if (empty($baseUrl) && !file_exists($app->get('path') . '/config.php')) {
                // Installer context: router not fully configured
                // Use /index.php as safe fallback for API calls
                $baseUrl = '/index.php';
            }

            $data->add('$pagekit', [
                'url' => $baseUrl,
                'csrf' => $app->get('csrf')->generate(),
            ]);
        },

        'view.styles' => function ($event, $styles) {
            $styles->register('codemirror-hint', 'app/system/modules/editor/app/assets/codemirror/show-hint.css');
            $styles->register('codemirror', 'app/system/modules/editor/app/assets/codemirror/codemirror.css', ['codemirror-hint']);
        },

        'view.scripts' => function ($event, $scripts) use ($app) {
            // Config loader must be first - reads JSON config and exposes global variables
            // All scripts that might need $pagekit or other globals must depend on this
            $scripts->register('pagekit-config', 'app/system/app/lib/config-loader.js', [], ['defer' => false]);

            $scripts->register('codemirror', 'app/system/modules/editor/app/assets/codemirror/codemirror.min.js', ['pagekit-config']);
            $scripts->register('marked', 'app/system/modules/editor/app/assets/marked/marked.min.js', ['pagekit-config']);
            $scripts->register('lodash', 'app/assets/lodash/dist/'  . ($app->get('debug') ? 'lodash.js' : 'lodash.min.js'), ['pagekit-config']);
            // vue-dist must load AFTER pagekit-config so $pagekit is available
            $scripts->register('vue-dist', 'app/assets/vue/dist/' . ($app->get('debug') ? 'vue.js' : 'vue.min.js'), ['pagekit-config']);
            // locale script returns JS that sets $locale, must load after config
            $scripts->register('locale', $app->get('url')->get('@system/intl', ['locale' => $app->get('module')->get('system/intl')->getLocale(), 'v' => $scripts->getFactory()->getVersion()]), ['pagekit-config'], ['type' => 'url']);
            $scripts->register('uikit', 'app/assets/uikit/dist/js/' . ($app->get('debug') ? 'uikit.js' : 'uikit.min.js'), ['pagekit-config']);
            $scripts->register('uikit-icons', 'app/system/assets/js/' . ($app->get('debug') ? 'uikit-icons.js' : 'uikit-icons.min.js'), 'uikit');
            $scripts->register('vue', 'app/system/app/bundle/vue.js', ['uikit', 'uikit-icons', 'vue-dist', 'lodash', 'locale']);
        },

    ],

];
