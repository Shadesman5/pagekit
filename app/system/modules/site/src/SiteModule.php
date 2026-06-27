<?php

declare(strict_types=1);

namespace Pagekit\Site;

use Pagekit\Application as App;
use Pagekit\Module\Module;
use Pagekit\Site\Model\Node;

class SiteModule extends Module
{
    protected ?App $app = null;
    /** @var array<string, array<string, mixed>>|null */
    protected ?array $types = null;

    public function main(App $app): mixed
    {
        $this->app = $app;

        ModelServiceLocator::init($app);

        $app->set('node', function ($app) {

            if ($id = $app->get('request')->attributes->get('_node') and $node = Node::find($id, true)) {
                return $node;
            }

            return Node::create();
        });

        $app->set('menu', function ($app) {

            $menus = new MenuManager($app->get('config')($app->get('theme')->name), $this->config('menus'));

            foreach ($app->get('theme')->get('menus', []) as $name => $label) {
                $menus->register($name, $label);
            }

            return $menus;
        });

        return null;
    }

    /**
     * @param  string $type
     * @return array<string, mixed>|null
     */
    public function getType($type): ?array
    {
        $types = $this->getTypes();

        return isset($types[$type]) ? $types[$type] : null;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    public function getTypes(): ?array
    {
        if (!$this->types) {
            $this->assertBooted();

            foreach ($this->getApp()->get('module') as $module) {
                foreach ((array) $module->get('nodes') as $type => $route) {
                    $this->registerType($type, $route);
                }
            }

            $this->registerType('link', ['label' => 'Link', 'frontpage' => false]);

            $this->getApp()->get('events')->trigger('site.types', [$this]);
        }

        return $this->types;
    }

    /**
     * @throws \LogicException when main() has not been called yet
     */
    private function assertBooted(): void
    {
        if ($this->app === null) {
            throw new \LogicException('SiteModule::main() has not been called yet.');
        }
    }

    /**
     * Returns the application instance, asserting it is not null.
     */
    private function getApp(): App
    {
        if ($this->app === null) {
            throw new \LogicException('SiteModule::main() has not been called yet.');
        }

        return $this->app;
    }

    /**
     * @param string                $type
     * @param array<string, mixed>  $route
     */
    public function registerType($type, array $route): void
    {
        $this->assertBooted();

        if (isset($route['protected']) and $route['protected'] and !array_filter(Node::findAll(true), fn ($node) => $type === $node->type)) {
            Node::create([
                'title' => $route['label'],
                'slug' => ($this->getApp()->get('filter'))($route['label'], 'slugify'),
                'type' => $type,
                'status' => 1,
                'link' => $route['name'],
            ])->save();
        }

        $route['id'] = $type;
        $this->types[$type] = $route;
    }
}
