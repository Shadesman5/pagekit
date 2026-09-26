<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\System;

use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\Locator;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Module\UnsatisfiedRequirementException;
use Pagekit\Package\Extension\ExtensionFailureStore;
use Pagekit\System\SystemModule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The activity policy is fixed when the system module starts, before extensions load.
 */
final class SystemModuleActivityTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_system_activity_' . getmypid() . '_' . uniqid();

        if (!mkdir($this->workspace, 0755, true) && !is_dir($this->workspace)) {
            self::fail('The fixture workspace could not be created.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testTheActiveThemeSatisfiesARequirementItIsNotListedAsAnExtension(): void
    {
        $system = new Config(['extensions' => ['needs-theme']]);
        [$app, $store] = $this->boot($system, ['needs-theme'], 'site-theme', [
            $this->declareModule('needs-theme', ['site-theme']),
            $this->declareModule('site-theme'),
            $this->declareModule('needs-extra', ['extra']),
            $this->declareModule('extra'),
        ]);

        $modules = $app->get('module');
        self::assertInstanceOf(ModuleManager::class, $modules);
        self::assertInstanceOf(Module::class, $modules->get('needs-theme'));
        self::assertInstanceOf(Module::class, $modules->get('site-theme'));
        self::assertSame('site-theme', $app->get('theme')->name);
        self::assertSame(['needs-theme'], $system->get('extensions'));
        self::assertSame([], $store->all());

        try {
            $modules->load('needs-extra');
            self::fail('A module that was not enabled has to stay inactive.');
        } catch (UnsatisfiedRequirementException $e) {
            self::assertTrue($e->registered);
            self::assertSame('extra', $e->requirement);
            self::assertSame(
                'Module "needs-extra" requires "extra", which is registered but disabled.',
                $e->getMessage(),
            );
        }

        self::assertNull($modules->get('extra'));
        self::assertNull($modules->get('needs-extra'));
    }

    public function testANamePushedOntoTheConfigServiceAfterStartupDoesNotBecomeActive(): void
    {
        $system = new Config(['extensions' => ['needs-theme']]);
        [$app] = $this->boot($system, ['needs-theme'], 'site-theme', [
            $this->declareModule('needs-theme', ['site-theme']),
            $this->declareModule('site-theme'),
            $this->declareModule('needs-late', ['late']),
            $this->declareModule('late'),
        ]);

        $system->push('extensions', 'late');
        self::assertContains('late', (array) $system->get('extensions'));

        $modules = $app->get('module');
        self::assertInstanceOf(ModuleManager::class, $modules);
        self::assertInstanceOf(Module::class, $modules->get('needs-theme'));

        try {
            $modules->load('needs-late');
            self::fail('A name added to the config service after startup has to stay inactive.');
        } catch (UnsatisfiedRequirementException $e) {
            self::assertTrue($e->registered);
            self::assertSame('needs-late', $e->depender);
            self::assertSame('late', $e->requirement);
        }

        self::assertNull($modules->get('late'));
        self::assertInstanceOf(Module::class, $modules->get('needs-theme'));
    }

    public function testAModuleTheBootModuleRequiresIsActiveWithoutBeingEnabled(): void
    {
        $system = new Config(['extensions' => ['needs-user']]);
        [$app, $store] = $this->boot($system, ['needs-user'], null, [
            $this->declareModule('system', ['user']),
            $this->declareModule('user'),
            $this->declareModule('needs-user', ['user']),
        ]);

        $modules = $app->get('module');
        self::assertInstanceOf(ModuleManager::class, $modules);
        self::assertInstanceOf(Module::class, $modules->get('needs-user'));
        self::assertInstanceOf(Module::class, $modules->get('user'));
        self::assertNull($modules->get('system'));
        self::assertSame(['needs-user'], $system->get('extensions'));
        self::assertSame([], $store->all());
        self::assertSame('theme-default', $app->get('theme')->name);
    }

    public function testAnEnabledExtensionWhoseRequirementIsDisabledIsRecordedAndNotLoaded(): void
    {
        $system = new Config(['extensions' => ['needs-off', 'pages']]);
        [$app, $store] = $this->boot($system, ['needs-off', 'pages'], null, [
            $this->declareModule('needs-off', ['switched-off']),
            $this->declareModule('switched-off'),
            $this->declareModule('pages'),
        ]);

        $modules = $app->get('module');
        self::assertInstanceOf(ModuleManager::class, $modules);
        self::assertNull($modules->get('needs-off'));
        self::assertNull($modules->get('switched-off'));
        self::assertInstanceOf(Module::class, $modules->get('pages'));
        self::assertSame(['pages'], $system->get('extensions'));
        self::assertSame('theme-default', $app->get('theme')->name);

        $entry = $store->all()['needs-off'] ?? null;

        self::assertNotNull($entry);
        self::assertSame(ExtensionFailureStore::TYPE_EXTENSION, $entry['type']);
        self::assertSame(UnsatisfiedRequirementException::class, $entry['class']);
        self::assertSame(
            'Module "needs-off" requires "switched-off", which is registered but disabled.',
            $entry['message'],
        );
    }

    /**
     * @param list<string> $extensions
     * @param list<string> $files
     * @return array{Application, ExtensionFailureStore}
     */
    private function boot(Config $system, array $extensions, ?string $theme, array $files): array
    {
        $app = new Application();
        $app->set('log', new NullLogger());
        $app->set('locator', new Locator($this->workspace));
        $app->set('assets', fn () => new \stdClass());

        $store = new ExtensionFailureStore($this->workspace . '/system', new Filesystem());
        $app->set('extension.failures', $store);

        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($system);
        $app->set('config', $config);

        $moduleConfig = [
            'extensions' => $extensions,
            'secret' => 'test-secret',
        ];

        if (is_string($theme) && $theme !== '') {
            $moduleConfig['site'] = ['theme' => $theme];
        }

        $app->get('module')->register($files);

        $systemModule = new SystemModule([
            'name' => 'system',
            'path' => $this->workspace,
            'config' => $moduleConfig,
        ]);
        $systemModule->main($app);

        return [$app, $store];
    }

    /**
     * @param list<string> $require
     */
    private function declareModule(string $name, array $require = []): string
    {
        $directory = $this->workspace . '/modules/' . $name;

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            self::fail('The fixture module directory could not be created.');
        }

        $file = $directory . '/index.php';
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'name' => " . var_export($name, true) . ",\n    'require' => " . var_export(array_values($require), true) . ",\n];\n";

        if (file_put_contents($file, $contents) === false) {
            self::fail('The fixture module could not be written.');
        }

        return $file;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }

        rmdir($path);
    }
}
