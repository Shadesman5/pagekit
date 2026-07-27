<?php

declare(strict_types=1);

namespace Pagekit\Installer\Controller;

use Pagekit\Installer\Package\PackageFactory;
use Pagekit\Routing\Attribute\Request;
use Pagekit\User\Attribute\Access;
use Psr\Container\ContainerInterface;

#[Access('system: manage packages', admin: true)]
class MarketplaceController
{
    private readonly string $systemApi;

    public function __construct(
        private readonly ContainerInterface $app,
        private readonly PackageFactory $package,
    ) {
        $this->systemApi = $this->app->has('system.api')
            ? $this->app->get('system.api')
            : 'https://pagekit.com';
    }

    /**
     * @return array<string, mixed>
     */
    #[Request(['page' => 'int'])]
    public function themesAction(?int $page = null): array
    {
        return [
            '$view' => [
                'title' => __('Marketplace'),
                'name' => 'installer:views/marketplace.php',
            ],
            '$data' => [
                'title' => 'Themes',
                'type' => 'pagekit-theme',
                'api' => $this->systemApi,
                'installed' => array_values($this->package->all('pagekit-theme')),
                'page' => $page,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Request(['page' => 'int'])]
    public function extensionsAction(?int $page = null): array
    {
        return [
            '$view' => [
                'title' => __('Marketplace'),
                'name' => 'installer:views/marketplace.php',
            ],
            '$data' => [
                'title' => 'Extensions',
                'type' => 'pagekit-extension',
                'api' => $this->systemApi,
                'installed' => array_values($this->package->all('pagekit-extension')),
                'page' => $page,
            ],
        ];
    }
}
