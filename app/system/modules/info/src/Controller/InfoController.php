<?php

declare(strict_types=1);

namespace Pagekit\Info\Controller;

use Pagekit\Application as App;
use Pagekit\User\Attribute\Access;

#[Access(admin: true)]
class InfoController
{
    public function indexAction(): array
    {
        return [
            '$view' => [
                'title' => __('Info'),
                'name'  => 'system:modules/info/views/info.php'
            ],
            '$info' => App::info()->get()
        ];
    }
}
