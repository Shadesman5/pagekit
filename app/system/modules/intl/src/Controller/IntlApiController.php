<?php

declare(strict_types=1);

namespace Pagekit\Intl\Controller;

use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;

class IntlApiController
{
    public function __construct(
        private readonly mixed $module,
    ) {
    }

    #[Route('/{locales}', requirements: ['locale' => '[a-zA-Z0-9_-]+'], defaults: ['_maintenance' => true], methods: ['GET'])]
    #[Request(['locale' => 'string'])]
    public function localesAction($locale = null): array
    {
        $intl = $this->module->get('system/intl');

        return ['locales' => $intl->getAvailableLanguages($locale)];
    }
}
