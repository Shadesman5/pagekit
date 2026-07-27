<?php

declare(strict_types=1);

use Pagekit\Auth\Auth;

return [

    'name' => 'system/login',

    'label' => 'Login',

    'events' => [

        'view.scripts' => function ($event, $scripts) use ($app) {
            $scripts->register('widget-login', 'system/user:app/bundle/widget-login.js', ['~widgets', 'input-link']);
        },

    ],

    'render' => function ($widget) use ($app) {

        $user = $app->get('user');
        $redirect = $widget->get($user->isAuthenticated() ? 'redirect_logout' : 'redirect_login') ?: $app->get('url')->current(true);
        $last_username = $app->get('session')->get(Auth::LAST_USERNAME);

        return $app->get('view')('system/user/widget-login.php', compact('widget', 'user', 'last_username', 'redirect'));
    },

];
