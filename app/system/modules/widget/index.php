<?php

use Pagekit\Widget\Model\Widget;
use Pagekit\Widget\PositionHelper;
use Pagekit\Widget\PositionManager;
use Pagekit\Widget\WidgetManager;

return [

    'name' => 'system/widget',

    'main' => function ($app) {

        $app->set('widget', fn($app) => new WidgetManager($app));

        $app->set('position', function ($app) {

            $positions = new PositionManager($app->get('config')($app->get('theme')->name));

            foreach ($app->get('theme')->get('positions', []) as $name => $label) {
                $positions->register($name, $label);
            }

            return $positions;
        });

        $app->get('module')->addLoader(function ($module) use ($app) {

            if (isset($module['widgets'])) {
                $app->get('widget')->register($module['widgets'], $module['path']);
            }

            return $module;
        });

    },

    'autoload' => [

        'Pagekit\\Widget\\' => 'src'

    ],

    'routes' => [

        '/site/widget' => [
            'name' => '@site/widget',
            'controller' => 'Pagekit\\Widget\\Controller\\WidgetController'
        ],
        '/api/site/widget' => [
            'name' => '@site/api/widget',
            'controller' => 'Pagekit\\Widget\\Controller\\WidgetApiController'
        ]

    ],

    'resources' => [

        'system/widget:' => '',
        'views:system/widget' => 'views'

    ],

    'permissions' => [

        'system: manage widgets' => [
            'title' => 'Manage widgets'
        ]

    ],

    'menu' => [

        'site: widgets' => [
            'label' => 'Widgets',
            'parent' => 'site',
            'url' => '@site/widget',
            'access' => 'system: manage widgets',
            'active' => '@site/widget(/edit)?',
            'priority' => 20
        ]

    ],

    'config' => [

        'widget' => [

            'positions' => [],
            'config' => [],
            'defaults' => []

        ]

    ],

    'events' => [

        'boot' => function ($event, $app) {

            Widget::defineProperty('position', fn() => $app->get('position')->find($this->id), true);

            Widget::defineProperty('theme', function () use ($app) {

                $config  = $app->get('theme')->config('_widgets.'.$this->id, []);
                $default = $app->get('theme')->get('widget', []);

                return array_replace_recursive($default, $config);
            }, true);
        },

        'package.enable' => function ($event, $package) use ($app) {
            if ($package->getType() === 'pagekit-theme') {
                $new = $app->get('config')($package->get('module'));
                $old = $app->get('config')($app->get('theme')->name);
                $assigned = [];

                foreach ((array) $new->get('_positions') as $position => $modules) {
                    $assigned = array_merge($assigned, $modules);
                }

                foreach ((array) $old->get('_positions') as $position => $modules) {
                    foreach ((array) $modules as $module) {
                        if (!in_array($module, $assigned)) {
                            $new->push('_positions.' . $position, $module);
                        }
                    }
                }
            }
        },

        'view.init' => function ($event, $view) use ($app) {
            $view->addHelper(new PositionHelper(
                $app->get('position'),
                $app->get('user'),
                $app->get('node'),
                $app->get('widget'),
            ));
        },

        'view.scripts' => function ($event, $scripts) {
            $scripts->register('widgets', 'system/widget:app/bundle/widgets.js', 'vue');
        },

        'model.widget.init' => function ($event, $widget) use ($app) {
            if ($type = $app->get('widget')->get($widget->type)) {
                $widget->data = array_replace_recursive($type->get('defaults', []), $widget->data ?: []);
            }
        },

        'model.widget.saved' => function ($event, $widget) use ($app) {
            $app->get('position')->assign($widget->position, $widget->id);
            $app->get('config')($app->get('theme')->name)->set('_widgets.'.$widget->id, $widget->theme);
        },

        'model.role.deleted' => function ($event, $role) {
            Widget::removeRole($role);
        }

    ]

];
