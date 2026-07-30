<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Pagekit\Config\Config;
use Pagekit\Installer\Package\Package;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Site\PackageNodeTypes;
use PHPUnit\Framework\TestCase;
use stdClass;

class PackageNodeTypesTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testFromPackageReadsNodesFromLoadedModule(): void
    {
        $package = new Package(['name' => 'pagekit/blog', 'module' => 'blog', 'type' => 'pagekit-extension']);

        $module = $this->createMock(Module::class);
        $module->method('get')->with('nodes')->willReturn([
            'blog' => ['label' => 'Blog'],
        ]);

        $modules = $this->createMock(ModuleManager::class);
        $modules->method('get')->with('blog')->willReturn($module);

        $this->assertSame(['blog'], PackageNodeTypes::fromPackage($package, $modules));
    }

    public function testFromPackageIgnoresNonModuleManagerEntries(): void
    {
        $package = new Package(['name' => 'pagekit/blog', 'module' => 'blog', 'type' => 'pagekit-extension']);

        $modules = $this->createMock(ModuleManager::class);
        $modules->method('get')->with('blog')->willReturn(new stdClass());

        $this->assertSame([], PackageNodeTypes::fromPackage($package, $modules));
    }

    public function testFromPackageFallsBackToRememberedTypesWhenModuleIsNotLoaded(): void
    {
        $package = new Package(['name' => 'pagekit/blog', 'module' => 'blog', 'type' => 'pagekit-extension']);
        $config = new Config([]);
        PackageNodeTypes::remember($config, 'blog', ['blog']);

        $modules = $this->createMock(ModuleManager::class);
        $modules->method('get')->with('blog')->willReturn(null);

        $this->assertSame(['blog'], PackageNodeTypes::fromPackage($package, $modules, $config));
    }

    public function testFromPackageReturnsEmptyWhenNoNodesAreDeclared(): void
    {
        $package = new Package(['name' => 'pagekit/misc', 'module' => 'misc', 'type' => 'pagekit-extension']);

        $module = $this->createMock(Module::class);
        $module->method('get')->with('nodes')->willReturn(null);

        $modules = $this->createMock(ModuleManager::class);
        $modules->method('get')->with('misc')->willReturn($module);

        $this->assertSame([], PackageNodeTypes::fromPackage($package, $modules));
    }

    public function testFromPackageReturnsEmptyWhenModuleAndMemoryAreMissing(): void
    {
        $package = new Package(['name' => 'pagekit/ghost', 'module' => 'ghost', 'type' => 'pagekit-extension']);

        $modules = $this->createMock(ModuleManager::class);
        $modules->method('get')->with('ghost')->willReturn(null);

        $this->assertSame([], PackageNodeTypes::fromPackage($package, $modules, new Config([])));
    }

    public function testForgetRemovesRememberedTypes(): void
    {
        $config = new Config([]);
        PackageNodeTypes::remember($config, 'blog', ['blog']);
        PackageNodeTypes::forget($config, 'blog');

        $package = new Package(['name' => 'pagekit/blog', 'module' => 'blog', 'type' => 'pagekit-extension']);
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('get')->with('blog')->willReturn(null);

        $this->assertSame([], PackageNodeTypes::fromPackage($package, $modules, $config));
    }

    public function testFromPackagePrefersTheLoadedModuleOverRememberedTypes(): void
    {
        $package = new Package(['name' => 'pagekit/blog', 'module' => 'blog', 'type' => 'pagekit-extension']);
        $config = new Config([]);
        PackageNodeTypes::remember($config, 'blog', ['stale']);

        $module = $this->createMock(Module::class);
        $module->method('get')->with('nodes')->willReturn([
            'blog' => ['label' => 'Blog'],
            'blog_category' => ['label' => 'Category'],
        ]);

        $modules = $this->createMock(ModuleManager::class);
        $modules->method('get')->with('blog')->willReturn($module);

        $this->assertSame(
            ['blog', 'blog_category'],
            PackageNodeTypes::fromPackage($package, $modules, $config),
            'a loaded module is authoritative; remembered types are only a fallback after unload',
        );
    }

    public function testFromPackageFiltersNonStringRememberedEntries(): void
    {
        $package = new Package(['name' => 'pagekit/blog', 'module' => 'blog', 'type' => 'pagekit-extension']);
        $config = new Config([]);
        $config->set('_extension_nodes.blog', ['blog', 42, 'page', null]);

        $modules = $this->createMock(ModuleManager::class);
        $modules->method('get')->with('blog')->willReturn(null);

        $this->assertSame(['blog', 'page'], PackageNodeTypes::fromPackage($package, $modules, $config));
    }

    public function testFromPackageReturnsEmptyWhenThePackageHasNoModuleName(): void
    {
        $package = new Package(['name' => 'pagekit/ghost', 'type' => 'pagekit-extension']);
        $modules = $this->createMock(ModuleManager::class);
        $modules->expects($this->never())->method('get');

        $this->assertSame([], PackageNodeTypes::fromPackage($package, $modules, new Config([])));
    }

    public function testRememberIgnoresEmptyTypeLists(): void
    {
        $config = new Config([]);
        PackageNodeTypes::remember($config, 'misc', []);

        $this->assertNull($config->get('_extension_nodes.misc'), 'remembering nothing must not write an empty config entry');
    }
}
