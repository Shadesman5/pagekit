<?php

declare(strict_types=1);

namespace Pagekit\Installer\Controller;

use Pagekit\Application as App;
use Pagekit\Routing\Attribute\Request;
use Pagekit\User\Attribute\Access;

#[Access('system: manage packages', admin: true)]
class MarketplaceController
{
    public function __construct(
        private readonly mixed $package,
    ) {}

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
                'api' => App::getInstance() ? App::getInstance()->get('system.api') : 'https://pagekit.com', // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
                'installed' => array_values($this->package->all('pagekit-theme')),
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
                'api' => App::getInstance() ? App::getInstance()->get('system.api') : 'https://pagekit.com', // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
                'installed' => array_values($this->package->all('pagekit-extension')),
                'page' => $page
            ]
        ];
    }
}
