<?php

declare(strict_types=1);

namespace Pagekit\Intl\Controller;

use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;

class IntlApiController
{
    public function __construct(
        private readonly ModuleManager $module,
    ) {
    }

    /**
     * @return array{locales: array<string, string>}
     */
    #[Route('/{locales}', requirements: ['locale' => '[a-zA-Z0-9_-]+'], defaults: ['_maintenance' => true], methods: ['GET'])]
    #[Request(['locale' => 'string'])]
    public function localesAction(?string $locale = null): array
    {
        $intl = $this->module->get('system/intl');

        return ['locales' => $intl->getAvailableLanguages($locale)];
    }
}
