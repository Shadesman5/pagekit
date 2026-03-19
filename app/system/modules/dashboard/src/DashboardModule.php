<?php

namespace Pagekit\Dashboard;

use Pagekit\Application as App;
use Pagekit\Module\Module;

class DashboardModule extends Module
{
    protected App $app;

    /**
     * {@inheritdoc}
     */
    public function main(App $app): void
    {
        $this->app = $app;
        $app->set('systemApi', fn($app) => $app->get('system.api'));
    }

    /**
     * Gets a widget.
     *
     * @param  string $id
     */
    public function getWidget($id): array
    {
        $widgets = $this->getWidgets();

        return isset($widgets[$id]) ? $widgets[$id] : null;
    }

    /**
     * Gets all user widgets.
     */
    public function getWidgets(): array
    {
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
        $this->app->get('config')->set('system/dashboard', $widgets);
    }
}
