<?php

declare(strict_types=1);

namespace Pagekit\System;

use Pagekit\Application as App;
use Pagekit\Module\Module;
use Symfony\Component\Finder\Finder;

class SystemModule extends Module
{
    protected ?App $app = null;

    /**
     * @return mixed Genuinely unknown type — overrides Module::main(); the return value is not consumed by the framework (inherited contract from ModuleInterface).
     */
    public function main(App $app): mixed
    {
        $this->app = $app;
        $app->set('system', $this);
        $app->set('isAdmin', false);

        $app->factory('finder', fn () => Finder::create());

        $app->extend('assets', function ($factory) use ($app) {

            $secret = $this->config['secret'];
            $version = substr(sha1($app->get('version') . $secret), 0, 4);
            $factory->setVersion($version);

            return $factory;

        });

        $theme = $this->config('site.theme');

        $app->get('module')->addLoader(function ($module) use ($app, $theme) {

            if (in_array($module['name'], $this->config['extensions'])) {
                $module['type'] = 'extension';
                $app->get('locator')->add("{$module['name']}:", $module['path']);
                $app->get('locator')->add("views:{$module['name']}", "{$module['path']}/views");
            } elseif ($module['name'] == $theme) {
                $module['type'] = 'theme';
                $app->get('locator')->add('theme:', $module['path']);
                $app->get('locator')->add('views:', "{$module['path']}/views");
            }

            return $module;
        });

        // Discovery ran before any module was loaded, so a package that threw
        // while being registered has had nowhere to be reported until now.
        foreach ($app->get('module')->getRegistrationFailures() as $file => $error) {
            $app->get('log')->error(
                sprintf('Extension failure [%s] during registration: %s', $file, $error->getMessage()),
                ['exception' => $error]
            );
        }

        foreach (array_merge($this->config['extensions'], (array) $theme) as $module) {
            try {
                $app->get('module')->load($module);
            } catch (\RuntimeException $e) {
                $module = ucfirst($module);
                $app->get('log')->error("[$module exception]: {$e->getMessage()}");
            }
        }

        $themeModule = $app->get('module')->get($theme);
        if (!$themeModule) {
            $themeModule = new Module([
                'name' => 'theme-default',
                'type' => 'theme',
                'path' => '',
                'config' => [],
                'layout' => 'views:system/blank.php',
            ]);
        }
        $app->set('theme', $themeModule);

        return null;
    }

    /**
     * Gets the system menu.
     */
    private function assertBooted(): App
    {
        if ($this->app === null) {
            throw new \LogicException('SystemModule::main() has not been called yet.');
        }

        return $this->app;
    }

    public function getMenu(): object
    {
        static $menu;

        if (!$menu) {
            $app = $this->assertBooted();

            $menu = new SystemMenu(
                $app->get('user'),
                $app->get('request'),
                $app->get('url'),
            );

            foreach ($app->get('module') as $module) {
                foreach ((array) $module->get('menu') as $id => $item) {
                    $menu->addItem($id, $item);
                }
            }
        }

        return $menu;
    }
}
