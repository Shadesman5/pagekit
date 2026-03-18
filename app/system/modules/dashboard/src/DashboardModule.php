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
        $app = $this->app ?? App::getInstance(); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
        $config = $app->get('config')->get('system/dashboard')->toArray();
        return $config ?: ($this->config('defaults') ?? []);
    }

    /**
     * Save widgets on user.
     *
     * @param array $widgets
     */
    public function saveWidgets(array $widgets): void
    {
        $app = $this->app ?? App::getInstance(); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
        $app->get('config')->set('system/dashboard', $widgets);
    }
}
