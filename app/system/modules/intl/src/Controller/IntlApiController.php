<?php

declare(strict_types=1);

namespace Pagekit\Intl\Controller;

use Pagekit\Application as App;
use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;

class IntlApiController
{
    #[Route('/{locales}', requirements: ['locale' => '[a-zA-Z0-9_-]+'], defaults: ['_maintenance' => true], methods: ['GET'])]
    #[Request(['locale' => 'string'])]
    public function localesAction($locale = null): array
    {
        $intl = App::module('system/intl');
        
        return ['locales' => $intl->getAvailableLanguages($locale)];
    }
}
