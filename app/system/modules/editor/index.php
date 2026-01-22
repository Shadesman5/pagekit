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

        'view.scripts' => function ($event, $scripts) use ($app) {
            $presets = $this->config('presets');
            $editorScripts = [];
            $editor = [
                'root_url' => $app['url']->getStatic(__DIR__),
                'locale' => $app->module('system/intl')->getLocale()
            ];
            if ($css = $app['url']->getStatic('theme:css/theme.css')) {
                $editor['content_css'] = [ $css ];
            }
            if (isset($presets['tinymce_uikit']) && $presets['tinymce_uikit'] && ($uikit = $scripts->get('uikit')->getSource())) {
                $editorScripts[] = $uikit;
                if ($uikitIcons = $scripts->get('uikit-icons')->getSource()) {
                    $editorScripts[] = $uikitIcons;
                }
            }
            if (isset($presets['tinymce_body_class']) && $presets['tinymce_body_class']) {
                $editor['body_class'] = 'uk-container';
            }
            $editor['content_js'] = $editorScripts;

            // Use DataHelper for CSP-compliant config delivery (no inline scripts)
            $app['view']->data('$editor', $editor);
            
            $scripts->register('editor', 'system/editor:app/bundle/editor.js', ['input-link', 'pagekit-config']);
        }

    ]

];
