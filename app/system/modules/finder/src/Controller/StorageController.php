<?php

declare(strict_types=1);

namespace Pagekit\Finder\Controller;

use Pagekit\Application as App;
use Pagekit\User\Attribute\Access;
use function Pagekit\__;

#[Access('system: manage storage', admin: true)]
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
