<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package\Lifecycle;

use Psr\Container\ContainerInterface;

/**
 * Runs one package's lifecycle file.
 *
 * The file is code the package ships, so it is loaded at the moment a hook is
 * first needed and kept afterwards: one lifecycle object per package operation,
 * whatever number of hooks that operation calls. Loading it again per hook would
 * hand each one a different object and lose anything the package put aside
 * between them.
 *
 * A file that returns something other than a lifecycle is a broken package, and
 * saying so is the point: the package declared a lifecycle file in its manifest,
 * so a file that does not deliver one is a fault to report, not an absence to
 * work around. Where a package declares no file at all there is nothing to run,
 * and every hook is a no-op.
 *
 * Updates are filtered against the version the installation currently records,
 * so an update is run when it is newer than what is installed and the callers
 * can ask whether there is anything to run at all before offering it.
 */
final class LifecycleRunner
{
    private ?PackageLifecycleInterface $resolved = null;

    /**
     * @param string|null $file    the package's lifecycle file, or null where it declares none
     * @param string|null $current the installed version, against which updates are scheduled
     */
    public function __construct(
        private readonly ?string $file,
        private readonly ?string $current,
        private readonly ContainerInterface $app,
    ) {
    }

    public function install(): void
    {
        $this->lifecycle()?->install($this->app);
    }

    public function enable(): void
    {
        $this->lifecycle()?->enable($this->app);
    }

    public function disable(): void
    {
        $this->lifecycle()?->disable($this->app);
    }

    public function uninstall(): void
    {
        $this->lifecycle()?->uninstall($this->app);
    }

    /**
     * Runs the pending updates, oldest version first.
     */
    public function update(): void
    {
        foreach ($this->updates() as $update) {
            $update($this->app);
        }
    }

    /**
     * Whether the package declares an update newer than the installed version.
     */
    public function hasUpdates(): bool
    {
        return $this->updates() !== [];
    }

    /**
     * The updates newer than the installed version, oldest version first.
     *
     * @return array<string, callable(ContainerInterface): void>
     */
    private function updates(): array
    {
        $updates = array_filter(
            $this->lifecycle()?->updates() ?? [],
            fn (int|string $version): bool => version_compare((string) $version, (string) $this->current, '>'),
            ARRAY_FILTER_USE_KEY
        );

        uksort($updates, fn (int|string $a, int|string $b): int => version_compare((string) $a, (string) $b));

        return $updates;
    }

    /**
     * The package's lifecycle, loaded on first use.
     *
     * @throws \RuntimeException where the file does not return a lifecycle
     */
    private function lifecycle(): ?PackageLifecycleInterface
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        if ($this->file === null || !file_exists($this->file)) {
            return null;
        }

        $lifecycle = require $this->file;

        if (!$lifecycle instanceof PackageLifecycleInterface) {
            throw new \RuntimeException(sprintf(
                'The lifecycle file "%s" must return a %s, got %s.',
                $this->file,
                PackageLifecycleInterface::class,
                get_debug_type($lifecycle)
            ));
        }

        return $this->resolved = $lifecycle;
    }
}
