<?php

namespace Pagekit\Finder\Controller;

use Pagekit\Application as App;
use function Pagekit\__;

/**
 * @Access("system: manage storage", admin=true)
 */
class StorageController
{
    public function indexAction(): array
    {
        return [
            '$view' => [
                'title' => __('Storage'),
                'name'  => 'system:modules/finder/views/storage.php'
            ],
            'root' => App::module('system/finder')->config('storage'),
            'mode' => 'write'
        ];
    }
}
