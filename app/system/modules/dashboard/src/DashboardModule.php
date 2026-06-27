<?php

declare(strict_types=1);

namespace Pagekit\Dashboard;

use Pagekit\Application as App;
use Pagekit\Module\Module;

class DashboardModule extends Module
{
    protected ?App $app = null;

    /**
     * @return mixed Genuinely unknown type — overrides Module::main(); the return value is not consumed by the framework (inherited contract from ModuleInterface).
     */
    public function main(App $app): mixed
    {
        $this->app = $app;
        $app->set('systemApi', fn ($app) => $app->has('system.api') ? $app->get('system.api') : 'https://pagekit.com');

        return null;
    }

    /**
     * Gets a widget.
     *
     * @return array<string, mixed>|null
     */
    public function getWidget(string $id): ?array
    {
        $widgets = $this->getWidgets();

        return $widgets[$id] ?? null;
    }

    /**
     * Gets all user widgets.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getWidgets(): array
    {
        $app = $this->assertBooted();

        $config = $app->get('config')->get('system/dashboard')->toArray();

        return $config ?: ($this->config('defaults') ?? []);
    }

    /**
     * Save widgets on user.
     *
     * @param array<string, array<string, mixed>> $widgets
     */
    public function saveWidgets(array $widgets): void
    {
        $app = $this->assertBooted();

        $app->get('config')->set('system/dashboard', $widgets);
    }

    private function assertBooted(): App
    {
        if ($this->app === null) {
            throw new \LogicException('DashboardModule::main() has not been called yet.');
        }
        return $this->app;
    }
}
