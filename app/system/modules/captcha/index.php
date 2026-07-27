<?php

declare(strict_types=1);

use Pagekit\Captcha\CaptchaListener;

return [

    'name' => 'system/captcha',

    'autoload' => [

        'Pagekit\\Captcha\\' => 'src',

    ],

    'resources' => [

        'system/captcha:' => '',

    ],

    'events' => [

        'boot' => function ($event, $app) {
            $app->get('events')->subscribe(
                new CaptchaListener(
                    $this,
                    $app->get('auth'),
                    $app->get('request.stack'),
                    $app->get('router'),
                )
            );
        },

        'view.system:modules/settings/views/settings' => function ($event, $view) use ($app) {
            $view->data('$settings', [
                'options' => [
                    $this->name => $this->config,
                ],
            ]);
        },

    ],

    'config' => [

        'recaptcha_enable' => false,
        'recaptcha_sitekey' => '',
        'recaptcha_secret' => '',

    ],

];
