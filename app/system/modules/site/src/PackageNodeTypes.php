<?php

declare(strict_types=1);

namespace Pagekit\Site;

use Pagekit\Config\Config;
use Pagekit\Installer\Package\PackageInterface;
use Pagekit\Module\ModuleInterface;
use Pagekit\Module\ModuleManager;

/**
 * Resolves site node type ids declared by an extension package.
 *
 * Never requires the package's index.php — that file often closes over `$app`
 * in event closures, which only ModuleManager::register() provides.
 */
final class PackageNodeTypes
{
    private const CONFIG_KEY = '_extension_nodes';

    /**
     * @return array<int, string>
     */
    public static function fromPackage(PackageInterface $package, ModuleManager $modules, ?Config $systemConfig = null): array
    {
        $moduleName = $package->get('module');
        if (!is_string($moduleName) || $moduleName === '') {
            return [];
        }

        $module = $modules->get($moduleName);
        if ($module instanceof ModuleInterface) {
            /** @var array<string, mixed> $nodes */
            $nodes = (array) $module->get('nodes');

            return array_keys($nodes);
        }

        if ($systemConfig !== null) {
            $stored = $systemConfig->get(self::CONFIG_KEY.'.'.$moduleName);
            if (is_array($stored)) {
                return array_values(array_filter($stored, 'is_string'));
            }
        }

        return [];
    }

    /**
     * Remembers node types while the module is still loaded (disable), so a
     * later uninstall can resolve them without re-including index.php.
     *
     * @param array<int, string> $types
     */
    public static function remember(Config $systemConfig, string $moduleName, array $types): void
    {
        if ($moduleName === '' || $types === []) {
            return;
        }

        $systemConfig->set(self::CONFIG_KEY.'.'.$moduleName, array_values($types));
    }

    public static function forget(Config $systemConfig, string $moduleName): void
    {
        if ($moduleName === '') {
            return;
        }

        $systemConfig->remove(self::CONFIG_KEY.'.'.$moduleName);
    }
}
