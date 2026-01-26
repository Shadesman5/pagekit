<?php

return [

    'name' => 'system/editor',

    'autoload' => [

        'Pagekit\\Editor\\' => 'src'

    ],

    'config' => [

        'editor' => 'html',
        'mode'   => ''

    ],

    'resources' => [

        'system/editor:' => ''

    ],

    'events' => [

        // Add editor data via view.data event
        'view.data' => function ($event, $data) use ($app) {
            $presets = $this->config('presets');
            $editor = [
                'root_url' => $app['url']->getStatic(__DIR__),
                'locale' => $app->module('system/intl')->getLocale(),
                'content_js' => []
            ];
            
            if ($css = $app['url']->getStatic('theme:css/theme.css')) {
                $editor['content_css'] = [ $css ];
            }
            
            if (isset($presets['tinymce_body_class']) && $presets['tinymce_body_class']) {
                $editor['body_class'] = 'uk-container';
            }
            
            // Add UIkit scripts if configured
            // Respect debug mode: use non-minified versions when debugging
            if (isset($presets['tinymce_uikit']) && $presets['tinymce_uikit']) {
                $editor['content_js'] = [
                    $app['url']->getStatic('app/assets/uikit/dist/js/' . ($app->debug() ? 'uikit.js' : 'uikit.min.js')),
                    $app['url']->getStatic('app/system/assets/js/' . ($app->debug() ? 'uikit-icons.js' : 'uikit-icons.min.js'))
                ];
            }

            $data->add('$editor', $editor);
        },

        // Register editor script
        'view.scripts' => function ($event, $scripts) {
            $scripts->register('editor', 'system/editor:app/bundle/editor.js', ['input-link', 'pagekit-config']);
        }

    ]

];
