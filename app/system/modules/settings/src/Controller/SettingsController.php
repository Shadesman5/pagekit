<?php

declare(strict_types=1);

namespace Pagekit\System\Controller;

use function Pagekit\__;

use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Routing\Attribute\Route;
use Pagekit\User\Attribute\Access;
use Symfony\Component\HttpFoundation\Request;

#[Access('system: access settings', admin: true)]
class SettingsController
{
    public function __construct(
        private readonly Request $request,
        private readonly ConfigManager $config,
        private readonly string $configFile,
        private readonly Filesystem $file,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function indexAction(): array
    {
        return [
            '$view' => [
                'title' => __('Settings'),
                'name' => 'system:modules/settings/views/settings.php',
            ],
        ];
    }

    /**
     * Persists the file configuration and the database-backed options.
     *
     * A write that fails leaves the settings screen with an error rather than a
     * success it never earned, so the exception is left to propagate.
     *
     * @return array{message: string}
     * @throws \RuntimeException if config.php could not be written
     */
    #[Route('/save', methods: ['POST'])]
    public function saveAction(): array
    {
        $values = $this->request->request->all()['config'] ?? [];
        $options = $this->request->request->all()['options'] ?? [];

        if ((empty($values) && empty($options)) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $values = $json['config'] ?? [];
            $options = $json['options'] ?? [];
        }
        $fileConfig = new Config();
        $fileConfig->merge(include $file = $this->configFile);

        foreach ($values as $module => $value) {
            $fileConfig->set($module, $value);
        }

        $this->file->dumpAtomic($file, $fileConfig->dump());

        foreach ($options as $module => $value) {
            $this->config->set($module, array_replace((($this->config)($module) ?? new Config())->toArray(), $value));
        }

        return ['message' => 'success'];
    }

    /**
     * @return array{message: string}
     */
    #[Route('/config', methods: ['POST'])]
    public function configAction(): array
    {
        $name = $this->request->request->get('name', '');
        $configData = $this->request->request->all()['config'] ?? [];

        if ($this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            if ($json) {
                $name = $json['name'] ?? $name;
                $configData = $json['config'] ?? $configData;
            }
        }
        $this->config->set($name, array_replace((($this->config)($name) ?? new Config())->toArray(), $configData));

        return ['message' => 'success'];
    }
}
