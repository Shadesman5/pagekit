<?php

namespace Pagekit\Hello\Controller;

use Pagekit\Application as App;

/**
 * @Access(admin=true)
 */
class HelloController
{
    public function indexAction(): array
    {
        return [
            '$view' => [
                'title' => __('Hello'),
                'name'  => 'hello:views/admin/index.php'
            ],
            '$data' => []
        ];
    }

    /**
     * @Access("hello: manage settings")
     */
    public function settingsAction(): array
    {
        return [
            '$view' => [
                'title' => __('Hello Settings'),
                'name'  => 'hello:views/admin/settings.php'
            ],
            '$data' => [
                'config' => App::module('hello')->config()
            ]
        ];
    }
}
