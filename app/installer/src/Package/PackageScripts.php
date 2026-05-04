<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package;

use Psr\Container\ContainerInterface;

class PackageScripts
{
    protected ?string $file = null;

    protected ?string $current = null;

    protected ?ContainerInterface $app = null;

    public function __construct(?string $file, ?string $current = null, ?ContainerInterface $app = null)
    {
        $this->file = $file;
        $this->current = $current;
        $this->app = $app;
    }

    /**
     * Runs the script's install hook.
     */
    public function install(): void
    {
        $this->run($this->get('install'));
    }

    /**
     * Runs the script's uninstall hook.
     */
    public function uninstall(): void
    {
        $this->run($this->get('uninstall'));
    }

    /**
     * Runs the script's enable hook.
     */
    public function enable(): void
    {
        $this->run($this->get('enable'));
    }

    /**
     * Runs the script's disable hook.
     */
    public function disable(): void
    {
        $this->run($this->get('disable'));
    }

    /**
     * Runs the script's update hooks.
     */
    public function update(): void
    {
        $this->run($this->getUpdates());
    }

    /**
     * Checks for script updates.
     */
    public function hasUpdates(): bool
    {
        return (bool) $this->getUpdates();
    }

    /**
     * @return array<int|string, callable>
     */
    protected function get(string $name): array
    {
        $scripts = $this->load();

        return isset($scripts[$name]) ? (array) $scripts[$name] : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function load(): array
    {
        if (!$this->file || !file_exists($this->file)) {
            return [];
        }

        return require $this->file;
    }

    /**
     * @param array<int|string, callable>|callable $scripts
     */
    protected function run(array|callable $scripts): void
    {
        array_map(function ($script) {

            if (is_callable($script)) {
                call_user_func($script, $this->app);
            }

        }, (array) $scripts);
    }

    /**
     * @return array<int|string, callable>
     */
    protected function getUpdates(): array
    {
        $updates = $this->get('updates');

        $versions = array_filter(array_keys($updates), fn ($version) => version_compare((string) $version, (string) $this->current, '>'));

        $updates = array_intersect_key($updates, array_flip($versions));
        uksort($updates, fn ($a, $b): int => version_compare((string) $a, (string) $b));

        return $updates;
    }
}
