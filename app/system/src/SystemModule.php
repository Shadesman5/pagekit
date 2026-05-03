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
     * {@inheritdoc}
     */
    public function main(App $app): void
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

    }

    /**
     * Gets the system menu.
     */
    private function assertBooted(): void
    {
        if ($this->app === null) {
            throw new \LogicException('SystemModule::main() has not been called yet.');
        }
    }

    public function getMenu(): object
    {
        static $menu;

        if (!$menu) {
            $this->assertBooted();

            $menu = new SystemMenu(
                $this->app->get('user'),
                $this->app->get('request'),
                $this->app->get('url'),
            );

            foreach ($this->app->get('module') as $module) {
                foreach ((array) $module->get('menu') as $id => $item) {
                    $menu->addItem($id, $item);
                }
            }
        }

        return $menu;
    }
}
