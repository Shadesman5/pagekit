<?php

declare(strict_types=1);

namespace Pagekit\Installer\Controller;

use Pagekit\Application as App;
use Pagekit\Routing\Attribute\Request;
use Pagekit\User\Attribute\Access;

#[Access('system: manage packages', admin: true)]
class MarketplaceController
{

    #[Request(['page' => 'int'])]
    public function themesAction($page = null): array
    {
        return [
            '$view' => [
                'title' => __('Marketplace'),
                'name'  => 'installer:views/marketplace.php'
            ],
            '$data' => [
                'title' => 'Themes',
                'type' => 'pagekit-theme',
                'api' => App::getInstance() ? App::getInstance()['system.api'] : 'https://pagekit.com',
                'installed' => array_values(App::package()->all('pagekit-theme')),
                'page' => $page
            ]
        ];
    }

    #[Request(['page' => 'int'])]
    public function extensionsAction($page = null): array
    {
        return [
            '$view' => [
                'title' => __('Marketplace'),
                'name'  => 'installer:views/marketplace.php'
            ],
            '$data' => [
                'title' => 'Extensions',
                'type' => 'pagekit-extension',
                'api' => App::getInstance() ? App::getInstance()['system.api'] : 'https://pagekit.com',
                'installed' => array_values(App::package()->all('pagekit-extension')),
                'page' => $page
            ]
        ];
    }
}
