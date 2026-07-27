<?php

declare(strict_types=1);

namespace Pagekit\Info\Controller;

use Pagekit\Info\InfoHelper;
use Pagekit\User\Attribute\Access;

#[Access(admin: true)]
class InfoController
{
    public function __construct(
        private readonly InfoHelper $info,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function indexAction(): array
    {
        return [
            '$view' => [
                'title' => __('Info'),
                'name' => 'system:modules/info/views/info.php',
            ],
            '$info' => $this->info->get(),
        ];
    }
}
