<?php

declare(strict_types=1);

namespace Pagekit\System;

use Pagekit\Application as App;
use Pagekit\Module\Module;
use Pagekit\System\Extension\ExtensionFailureStore;
use Pagekit\System\Extension\ExtensionLoader;
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

        // Where a failure outlives the request that hit it: the next boot and the
        // extension manager both read which packages are broken from here. The
        // service is defined only where the record has a directory to live in, so
        // that the one question a caller can ask the container - whether the id is
        // there - is the same question as whether it resolves.
        if ($app->has('path.system')) {
            $app->set('extension.failures', fn () => new ExtensionFailureStore($app->get('path.system'), $app->get('file')));
        }

        // A container that names no place for the record still gets the barrier;
        // what it loses is what the next boot would otherwise have known.
        $failures = $app->has('extension.failures') ? $app->get('extension.failures') : null;

        $loader = new ExtensionLoader(
            $app->get('module'),
            $app->get('log'),
            $failures,
            $this->extensionDisabler($app),
        );

        $loader->load((array) $this->config['extensions'], $theme);

        // A fresh or partial install may have no site.theme yet — ModuleManager
        // requires a string name, so fall back before asking for the module.
        $themeModule = is_string($theme) && $theme !== ''
            ? $app->get('module')->get($theme)
            : null;

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
     * How an extension is taken out of service when it fails.
     *
     * The enabled list is written back to the database straight away rather than
     * left to the terminate event that normally persists configuration: the
     * request that just failed is not one to trust with reaching its own end.
     */
    private function extensionDisabler(App $app): \Closure
    {
        return function (string $name) use ($app): void {

            // A container assembled without a configuration service has no
            // enabled list to take the extension out of.
            if (!$app->has('config')) {
                return;
            }

            $config = $app->get('config')('system');
            $config->pull('extensions', $name);

            $app->get('config')->set('system', $config);

        };
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
