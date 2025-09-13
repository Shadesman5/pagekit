<?php

namespace Pagekit\Hello\Controller;

use Pagekit\Application as App;

class SiteController
{
    /**
     * @Route("/")
     * @Route("/{name}", name="name")
     */
    public function indexAction($name = ''): array
    {
        $names = explode(',', $name ?: App::module('hello')->config('default'));

        return [
            '$view' => [
                'title' => __('Hello %name%', ['%name%' => $names[0]]),
                'name' => 'hello:views/index.php'
            ],
            'names' => $names
        ];
    }

    /**
     * @Route("/greet")
     * @Route("/greet/{name}", name="greet/name")
     */
    public function greetAction($name = ''): array
    {
        $names = explode(',', $name ?: App::module('hello')->config('default'));

        return [
            '$view' => [
                'title' => __('Hello %name%', ['%name%' => $names[0]]),
                'name' => 'hello:views/index.php'
            ],
            'names' => $names
        ];
    }

    public function redirectAction(): array
    {
        return App::response()->redirect('@hello/greet', ['name' => 'Someone']);
    }

    public function jsonAction(): array
    {
        return ['message' => 'There is nothing here. Move along.'];
    }

    public function downloadAction(): array
    {
        return App::response()->download(App::locator()->get('hello:icon.svg'));
    }

    public function forbiddenAction(): array
    {
        App::abort(401, __('Permission denied.'));
        return [];
    }
}
