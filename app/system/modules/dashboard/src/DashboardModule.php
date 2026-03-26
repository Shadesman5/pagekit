<?php

namespace Pagekit\Dashboard;

use Pagekit\Application as App;
use Pagekit\Module\Module;

class DashboardModule extends Module
{
    protected ?App $app = null;

    /**
     * {@inheritdoc}
     */
    public function main(App $app): void
    {
        $this->app = $app;
        $app->set('systemApi', fn($app) => $app->has('system.api') ? $app->get('system.api') : 'https://pagekit.com');
    }

    /**
     * Gets a widget.
     *
     * @param  string $id
     */
    public function getWidget(string $id): array
    {
        $widgets = $this->getWidgets();

        if (!isset($widgets[$id])) {
            throw new \LogicException(sprintf('Dashboard widget "%s" not found.', $id));
        }

        return $widgets[$id];
    }

    /**
     * Gets all user widgets.
     */
    public function getWidgets(): array
    {
        $this->assertBooted();

        $config = $this->app->get('config')->get('system/dashboard')->toArray();
        return $config ?: ($this->config('defaults') ?? []);
    }

    /**
     * Save widgets on user.
     *
     * @param array $widgets
     */
    public function saveWidgets(array $widgets): void
    {
        $this->assertBooted();

        $this->app->get('config')->set('system/dashboard', $widgets);
    }

    private function assertBooted(): void
    {
        if ($this->app === null) {
            throw new \LogicException('DashboardModule::main() has not been called yet.');
        }
    }
}
