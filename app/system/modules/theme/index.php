<?php

return [

    'name' => 'system/theme',

    'type' => 'theme',

    'layout' => 'views:system/template.php',

    'events' => [

       'view.data' => function ($event, $data) use ($app) {
            if (!$app->get('isAdmin')) {
                return;
            }
            $data->add('Theme', [
                'SidebarItems' => [
                    'additem' => [
                        'addpage' => [
                            'caption' => 'Add Page',
                            'attrs' => [
                                'href' => $app->get('url')->get('admin/site/page/edit?id=page&menu=')
                            ],
                            'priority' => 0
                        ]
                    ],
                    'menuitem' => [
                        'site' => [
                            'caption' => 'Visit Site',
                            'attrs' => [
                                'href' => $app->get('url')->get(''),
                                'target' => '_blank'
                            ],
                            'priority' => 0
                        ],
                        'pagekitdocs' => [
                            'caption' => 'Documentation',
                            'attrs' => [
                                'href' => $app->get('url')->get('https://pagekit.com/docs'),
                                'target' => '_blank'
                            ],
                            'priority' => 1
                        ],
                        'pagekit' => [
                            'caption' => '<span class="uk-text-middle">pagekit.com</span>',
                            'attrs' => [
                                'href' => $app->get('url')->get('https://pagekit.com'),
                                'target' => '_blank'
                            ],
                            'priority' => 2
                        ],
                        'logout' => [
                            'caption' => 'Logout',
                            'attrs' => [
                                'href' => $app->get('url')->get('@user/logout', ['redirect' => 'admin/login'])
                            ],
                            'priority' => 99
                        ]
                    ]
                ]
            ]);
        },

        'view.meta' => [function($event, $meta) use ($app) {
            $meta([
                'link:favicon' => [
                    'href' => $app->get('url')->getStatic('system/theme:favicon.ico'),
                    'rel' => 'shortcut icon',
                    'type' => 'image/x-icon'
                ],
                'link:appicon' => [
                    'href' => $app->get('url')->getStatic('system/theme:apple_touch_icon.png'),
                    'rel' => 'apple-touch-icon-precomposed'
                ]
            ]);
        }, 10],

        'view.layout' => function ($event, $view) use ($app) {

            if (!$app->get('isAdmin')) {
                return;
            }

            $user = $app->get('user');

            $view->data('$pagekit', [
                'editor' => $app->get('module')->get('system/editor')->config(),
                'storage' => $app->get('module')->get('system/finder')->config('storage'),
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username
                ],
                'menu' => array_values($app->get('system')->getMenu()->getItems())
            ]);

            $subsets = 'latin,latin-ext';
            $subset  = __('_subset');

            if ('cyrillic' == $subset) {
    			$subsets .= ',cyrillic,cyrillic-ext';
    		} elseif ('greek' == $subset) {
    			$subsets .= ',greek,greek-ext';
    		} elseif ('vietnamese' == $subset) {
    			$subsets .= ',vietnamese';
    		}

            $event['subset'] = $subsets;
            $event['pageClass'] = implode('-', explode('/', preg_replace('/^\/admin\//','', $app->get('request')->getPathInfo())));
        }

    ],

    'resources' => [

        'system/theme:' => '',
        'views:system' => 'views'

    ]

];
