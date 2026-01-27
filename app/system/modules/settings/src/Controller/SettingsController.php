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
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
        // Get config and options from POST or JSON body
        $values = $request->request->all()['config'] ?? [];
        $options = $request->request->all()['options'] ?? [];
        
        if ((empty($values) && empty($options)) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $values = $json['config'] ?? [];
            $options = $json['options'] ?? [];
        }
        $config = new Config;
        $config->merge(include $file = App::get('config.file'));

        foreach ($values as $module => $value) {
            $config->set($module, $value);
        }

        file_put_contents($file, $config->dump());

        foreach ($options as $module => $value) {
            App::config()->set($module, array_replace(App::config($module)->toArray(), $value));
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file);
        }

        return ['message' => 'success'];
    }

    #[Route('/config', methods: ['POST'])]
    public function configAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
        // Get name and config from POST or JSON body
        $name = $request->request->get('name', '');
        $config = $request->request->all()['config'] ?? [];
        
        if ($request->getContent()) {
            $json = json_decode($request->getContent(), true);
            if ($json) {
                $name = $json['name'] ?? $name;
                $config = $json['config'] ?? $config;
            }
        }
        App::config()->set($name, array_replace(App::config($name)->toArray(), $config));

        return ['message' => 'success'];
    }
}
