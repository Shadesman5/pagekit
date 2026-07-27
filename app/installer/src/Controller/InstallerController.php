<?php

declare(strict_types=1);

namespace Pagekit\Installer\Controller;

use Pagekit\Application;
use Pagekit\Installer\Installer;
use Pagekit\Module\ModuleManager;
use Symfony\Component\HttpFoundation\Request;

class InstallerController
{
    protected Installer $installer;

    public function __construct(
        private readonly Application $app,
        private readonly Request $request,
        private readonly ModuleManager $module,
    ) {
        $this->installer = new Installer($this->app);
    }

    /**
     * @return array<string, mixed>
     */
    public function indexAction(): array
    {
        $intl = $this->module->get('system/intl');

        return [
            '$view' => [
                'title' => __('Pagekit Installer'),
                'name' => 'app/installer/views/installer.php',
            ],
            '$installer' => [
                'locale' => $intl->getLocale(),
                'locales' => $intl->getAvailableLanguages(),
                'sqlite' => class_exists('SQLite3') || (class_exists('PDO') && in_array('sqlite', \PDO::getAvailableDrivers(), true)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkAction(): array
    {
        try {
            $data = json_decode($this->request->getContent(), true);

            if (isset($data['config'])) {
                $config = $data['config'];
            } elseif (isset($data['database'])) {
                $database = $data['database'] ?? 'mysql';
                unset($data['database']);

                $config = [
                    'database' => [
                        'default' => $database,
                        'connections' => [
                            $database => $data,
                        ],
                    ],
                ];

                if (isset($data['locale'])) {
                    $config['locale'] = $data['locale'];
                }
            } else {
                $config = [];
            }

            return $this->installer->check($config);
        } catch (\Throwable $e) {
            return [
                'error' => true,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function installAction(): array
    {
        $data = json_decode($this->request->getContent(), true) ?: [];

        $config = $data['config'] ?? [];
        $option = $data['option'] ?? [];
        $user = $data['user'] ?? [];

        if (isset($data['locale']) && !isset($config['locale'])) {
            $config['locale'] = $data['locale'];
        }

        return $this->installer->install($config, $option, $user);
    }
}
