<?php

declare(strict_types=1);

namespace Pagekit\System\Controller;

use Pagekit\Application as App;
use Pagekit\Config\Config;
use Pagekit\Routing\Attribute\Route;
use Pagekit\User\Attribute\Access;
use function Pagekit\__;

#[Access('system: access settings', admin: true)]
class SettingsController
{
    public function __construct(
        private readonly mixed $request,
        private readonly mixed $config,
    ) {}

    public function indexAction(): array
    {
        return [
            '$view' => [
                'title' => __('Settings'),
                'name'  => 'system:modules/settings/views/settings.php'
            ]
        ];
    }

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
        $fileConfig = new Config;
        $fileConfig->merge(include $file = App::getInstance()->get('config.file')); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e

        foreach ($values as $module => $value) {
            $fileConfig->set($module, $value);
        }

        file_put_contents($file, $fileConfig->dump());

        foreach ($options as $module => $value) {
            $this->config->set($module, array_replace(($this->config)($module)->toArray(), $value));
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file);
        }

        return ['message' => 'success'];
    }

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
        $this->config->set($name, array_replace(($this->config)($name)->toArray(), $configData));

        return ['message' => 'success'];
    }
}
