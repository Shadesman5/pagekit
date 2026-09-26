<?php

declare(strict_types=1);

namespace Pagekit\Package;

use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Database\Connection;
use Pagekit\Module\ModuleManager;
use Pagekit\Package\Lifecycle\MigrationSet;
use Pagekit\Package\Snapshot\TableNameFold;
use Psr\Container\ContainerInterface;

/**
 * What switching packages off would block, leave behind, or put at risk.
 *
 * @phpstan-type Impact array{blockers: list<string>, orphans: list<string>, dataRisk: array{migrations: bool, config: bool, nodes: list<string>, tables: list<string>}}
 */
final class PackageImpact
{
    /**
     * @param \Closure(PackageInterface): ?MigrationSet $migrations
     */
    public function __construct(
        private readonly ContainerInterface $app,
        private readonly ?ModuleManager $modules,
        private readonly \Closure $migrations,
    ) {
    }

    /**
     * @param list<PackageInterface> $packages
     * @return Impact
     */
    public function query(array $packages): array
    {
        $targets = $this->targetNames($packages);
        $enabled = $this->enabled();

        return [
            'blockers' => $this->blockers($targets, $enabled),
            'orphans' => $this->orphans($targets, $enabled),
            'dataRisk' => $this->dataRisk($packages, $targets),
        ];
    }

    /**
     * @param list<PackageInterface> $packages
     * @return list<string>
     */
    private function targetNames(array $packages): array
    {
        $names = [];

        foreach ($packages as $package) {
            $name = $package->get('module');

            if (!is_string($name) || $name === '' || isset($names[$name])) {
                continue;
            }

            $names[$name] = true;
        }

        return array_keys($names);
    }

    /**
     * Enabled modules that require a target.
     *
     * A sibling in this same call still counts, because it is enabled until its own turn.
     * The target does not count against itself.
     *
     * @param list<string>        $targets
     * @param array<string, true> $enabled
     * @return list<string>
     */
    private function blockers(array $targets, array $enabled): array
    {
        $modules = $this->modules;

        if (!$modules instanceof ModuleManager) {
            return [];
        }

        $blockers = [];

        foreach ($targets as $target) {
            foreach ($modules->requiredBy($target) as $depender) {
                if ($depender === $target || isset($blockers[$depender]) || !isset($enabled[$depender])) {
                    continue;
                }

                $blockers[$depender] = true;
            }
        }

        return array_keys($blockers);
    }

    /**
     * Requirements that this call would leave with nothing enabled still requiring them.
     *
     * Names in this call are not left behind. The always-loaded closure is the
     * boot module's requirements, which stay loadable without being enabled.
     *
     * @param list<string>        $targets
     * @param array<string, true> $enabled
     * @return list<string>
     */
    private function orphans(array $targets, array $enabled): array
    {
        $modules = $this->modules;

        if (!$modules instanceof ModuleManager) {
            return [];
        }

        $leaving = array_fill_keys($targets, true);
        $remaining = array_diff_key($enabled, $leaving);
        $orphans = [];

        foreach ($targets as $target) {
            foreach ($modules->requires($target) as $required) {
                if (
                    isset($leaving[$required])
                    || isset($orphans[$required])
                    || $modules->isAlwaysLoaded($required)
                    || $this->stillRequired($modules, $required, $remaining)
                ) {
                    continue;
                }

                $orphans[$required] = true;
            }
        }

        return array_keys($orphans);
    }

    /**
     * @param array<string, true> $remaining
     */
    private function stillRequired(ModuleManager $modules, string $name, array $remaining): bool
    {
        foreach ($modules->requiredBy($name) as $depender) {
            if (isset($remaining[$depender])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function enabled(): array
    {
        if (!$this->app->has('config')) {
            return [];
        }

        $configs = $this->app->get('config');

        if (!$configs instanceof ConfigManager) {
            return [];
        }

        $system = $configs('system');

        if (!$system instanceof Config) {
            return [];
        }

        $enabled = [];

        foreach ((array) $system->get('extensions', []) as $name) {
            if (is_string($name) && $name !== '') {
                $enabled[$name] = true;
            }
        }

        $theme = $system->get('site.theme');

        if (is_string($theme) && $theme !== '') {
            $enabled[$theme] = true;
        }

        return $enabled;
    }

    /**
     * @param list<PackageInterface> $packages
     * @param list<string>           $targets
     * @return array{migrations: bool, config: bool, nodes: list<string>, tables: list<string>}
     */
    private function dataRisk(array $packages, array $targets): array
    {
        $migrations = false;

        foreach ($packages as $package) {
            if (($this->migrations)($package) instanceof MigrationSet) {
                $migrations = true;

                break;
            }
        }

        $config = false;
        $nodes = [];
        $modules = $this->modules;

        foreach ($targets as $name) {
            if (!$config && $this->hasConfigRow($name)) {
                $config = true;
            }

            if (!$modules instanceof ModuleManager) {
                continue;
            }

            foreach ($modules->nodeTypes($name) as $id) {
                $nodes[$id] = true;
            }
        }

        return [
            'migrations' => $migrations,
            'config' => $config,
            'nodes' => array_keys($nodes),
            'tables' => $this->tables($targets),
        ];
    }

    /**
     * A row named for the module. The version key inside the system row is not one.
     */
    private function hasConfigRow(string $module): bool
    {
        if ($module === '' || !$this->app->has('config')) {
            return false;
        }

        $config = $this->app->get('config');

        return $config instanceof ConfigManager && $config->has($module);
    }

    /**
     * Live tables named with the installation prefix, the module name, and an underscore.
     *
     * @param list<string> $targets
     * @return list<string>
     */
    private function tables(array $targets): array
    {
        if ($targets === [] || !$this->app->has('db')) {
            return [];
        }

        $db = $this->app->get('db');

        if (!$db instanceof Connection) {
            return [];
        }

        $prefix = $db->getPrefix() ?? '';
        $fold = new TableNameFold($db);
        $names = [];

        foreach ($db->createSchemaManager()->listTableNames() as $name) {
            foreach ($targets as $module) {
                if ($fold->prefixed($name, $prefix . $module . '_')) {
                    $names[$name] = true;

                    break;
                }
            }
        }

        $tables = array_keys($names);
        sort($tables, SORT_STRING);

        return $tables;
    }
}
