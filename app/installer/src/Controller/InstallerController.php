<?php

declare(strict_types=1);

namespace Pagekit\Installer\Controller;

use Pagekit\Installer\Installer;

class InstallerController
{
    protected Installer $installer;

    public function __construct(
        private readonly mixed $app, // TODO: Must be refactored in Step 2.1.4 (PHPStan Level 5→6)
        private readonly mixed $request, // TODO: Must be refactored in Step 2.1.4 (PHPStan Level 5→6)
        private readonly mixed $module, // TODO: Must be refactored in Step 2.1.4 (PHPStan Level 5→6)
    ) {
        $this->installer = new Installer($this->app);
    }

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
