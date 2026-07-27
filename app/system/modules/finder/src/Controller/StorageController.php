<?php

declare(strict_types=1);

namespace Pagekit\Finder\Controller;

use function Pagekit\__;

use Pagekit\Module\ModuleManager;
use Pagekit\User\Attribute\Access;

#[Access('system: manage storage', admin: true)]
class StorageController
{
    public function __construct(
        private readonly ModuleManager $module,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function indexAction(): array
    {
        return [
            '$view' => [
                'title' => __('Storage'),
                'name' => 'system:modules/finder/views/storage.php',
            ],
            'root' => $this->module->get('system/finder')->config('storage'),
            'mode' => 'write',
        ];
    }
}
