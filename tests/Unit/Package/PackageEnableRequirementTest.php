<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Module\UnsatisfiedRequirementException;
use Pagekit\Package\Package;
use Pagekit\Package\PackageManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Enabling a package refuses an unsatisfied requirement before the package changes.
 */
final class PackageEnableRequirementTest extends TestCase
{
    private string $workspace;

    private string $packageDir;

    private string $marker;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_enable_requirement_' . getmypid() . '_' . uniqid();
        $this->packageDir = $this->workspace . '/packages/pagekit/test-ext';
        $this->marker = $this->workspace . '/lifecycle-ran';

        if (!mkdir($this->packageDir, 0755, true) && !is_dir($this->packageDir)) {
            self::fail('The fixture package directory could not be created.');
        }

        file_put_contents($this->packageDir . '/composer.json', (string) json_encode([
            'name' => 'pagekit/test-ext',
            'type' => 'pagekit-extension',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));

        $this->writeLifecycle();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testAnUnregisteredRequirementIsRefusedBeforeThePackageChanges(): void
    {
        [$app, $system, $events, $modules] = $this->openPackage();
        $modules->register([
            $this->declareModule('alpha', ['missing']),
        ]);
        $modules->setActivityPolicy(['alpha'], 'system');

        $thrown = $this->enable($app);

        self::assertInstanceOf(UnsatisfiedRequirementException::class, $thrown);
        self::assertFalse($thrown->registered);
        self::assertSame('alpha', $thrown->depender);
        self::assertSame('missing', $thrown->requirement);
        self::assertSame(
            'Module "alpha" requires "missing", which is not registered.',
            $thrown->getMessage(),
        );
        $this->assertUntouched($system, $events, 'alpha');
    }

    public function testADisabledRequirementIsRefusedBeforeThePackageChanges(): void
    {
        [$app, $system, $events, $modules] = $this->openPackage();
        $modules->register([
            $this->declareModule('alpha', ['beta']),
            $this->declareModule('beta'),
        ]);
        $modules->setActivityPolicy(['alpha'], 'system');

        $thrown = $this->enable($app);

        self::assertInstanceOf(UnsatisfiedRequirementException::class, $thrown);
        self::assertTrue($thrown->registered);
        self::assertSame('alpha', $thrown->depender);
        self::assertSame('beta', $thrown->requirement);
        self::assertSame(
            'Module "alpha" requires "beta", which is registered but disabled.',
            $thrown->getMessage(),
        );
        $this->assertUntouched($system, $events, 'alpha');
        self::assertNull($modules->get('beta'));
    }

    public function testARequirementThatBecomesDisabledAfterTheModuleLoadedIsStillRefused(): void
    {
        [$app, $system, $events, $modules] = $this->openPackage();
        $modules->register([
            $this->declareModule('alpha', ['beta']),
            $this->declareModule('beta'),
        ]);

        // Every registered module is active until the policy exists, so this
        // load is the one an update does not repeat.
        $modules->load('alpha');
        self::assertInstanceOf(Module::class, $modules->get('alpha'));
        self::assertInstanceOf(Module::class, $modules->get('beta'));

        $modules->setActivityPolicy(['alpha'], 'system');

        $thrown = $this->enable($app);

        self::assertInstanceOf(UnsatisfiedRequirementException::class, $thrown);
        self::assertTrue($thrown->registered);
        self::assertSame('beta', $thrown->requirement);
        self::assertSame(
            'Module "alpha" requires "beta", which is registered but disabled.',
            $thrown->getMessage(),
        );
        $this->assertUntouched($system, $events, 'alpha');
        self::assertInstanceOf(Module::class, $modules->get('alpha'));
        self::assertInstanceOf(Module::class, $modules->get('beta'));
    }

    public function testACycleIsRefusedBeforeThePackageChanges(): void
    {
        [$app, $system, $events, $modules] = $this->openPackage();
        $modules->register([
            $this->declareModule('alpha', ['beta']),
            $this->declareModule('beta', ['alpha']),
        ]);
        $modules->setActivityPolicy(['alpha', 'beta'], 'system');

        $thrown = $this->enable($app);

        self::assertInstanceOf(\RuntimeException::class, $thrown);
        self::assertSame(\RuntimeException::class, $thrown::class);
        self::assertSame('Circular requirement "beta > alpha" detected.', $thrown->getMessage());
        $this->assertUntouched($system, $events, 'alpha');
    }

    public function testACycleBehindADisabledModuleIsRefusedAsDisabled(): void
    {
        [$app, $system, $events, $modules] = $this->openPackage();
        $modules->register([
            $this->declareModule('alpha', ['beta']),
            $this->declareModule('beta', ['gamma']),
            $this->declareModule('gamma', ['beta']),
        ]);
        $modules->setActivityPolicy(['alpha'], 'system');

        $thrown = $this->enable($app);

        self::assertInstanceOf(UnsatisfiedRequirementException::class, $thrown);
        self::assertTrue($thrown->registered);
        self::assertSame('beta', $thrown->requirement);
        self::assertSame(
            'Module "alpha" requires "beta", which is registered but disabled.',
            $thrown->getMessage(),
        );
        $this->assertUntouched($system, $events, 'alpha');
        self::assertNull($modules->get('beta'));
        self::assertNull($modules->get('gamma'));
    }

    public function testEnableSkipsTheRequirementWalkWhenTheModuleNameIsNotAString(): void
    {
        [$app, $system, $events, $modules] = $this->openPackage();
        // The string name would fail the walk. The package does not carry that name.
        $modules->register([$this->declareModule('7', ['missing'])]);
        $modules->setActivityPolicy([], 'system');

        (new PackageManager($app, new NullOutput()))->enable($this->package(7));

        self::assertSame(['kept', 7], $system->get('extensions'));
        self::assertSame('1.0.0', $system->get('packages.7'));
        self::assertSame('other', $system->get('site.theme'));
        self::assertSame(['package.enable'], $events->fired);
        self::assertStringContainsString('enable', (string) file_get_contents($this->marker));
        self::assertNull($modules->get('7'));
    }

    public function testEnableOfAModuleThatIsNotRegisteredDoesNotThrowUndefinedModule(): void
    {
        [$app, $system, $events, $modules] = $this->openPackage();
        $modules->setActivityPolicy([], 'system');

        (new PackageManager($app, new NullOutput()))->enable($this->package('ghost'));

        self::assertSame(['kept', 'ghost'], $system->get('extensions'));
        self::assertSame('1.0.0', $system->get('packages.ghost'));
        self::assertSame('other', $system->get('site.theme'));
        self::assertSame(['package.enable'], $events->fired);
        self::assertStringContainsString('enable', (string) file_get_contents($this->marker));
        self::assertNull($modules->get('ghost'));
    }

    /**
     * @return array{Application, Config, PackageEnableEvents, ModuleManager}
     */
    private function openPackage(): array
    {
        $app = new Application();
        $system = new Config([
            'extensions' => ['kept'],
            'site' => ['theme' => 'other'],
        ]);

        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($system);
        $app->set('config', $config);

        $events = new PackageEnableEvents();
        $app->set('events', $events);

        $modules = $app->get('module');
        self::assertInstanceOf(ModuleManager::class, $modules);

        return [$app, $system, $events, $modules];
    }

    private function enable(Application $app): ?\Throwable
    {
        try {
            (new PackageManager($app, new NullOutput()))->enable($this->package('alpha'));
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function assertUntouched(Config $system, PackageEnableEvents $events, string $moduleName): void
    {
        self::assertSame(['kept'], $system->get('extensions'));
        self::assertSame('other', $system->get('site.theme'));
        self::assertNull($system->get('packages.' . $moduleName));
        self::assertSame([], $events->fired);
        self::assertFileDoesNotExist($this->marker);
    }

    /**
     * @param mixed $module
     */
    private function package(mixed $module): Package
    {
        return new Package([
            'name' => 'pagekit/test-ext',
            'type' => 'pagekit-extension',
            'title' => 'Test Extension',
            'module' => $module,
            'path' => $this->packageDir,
            'extra' => ['scripts' => 'scripts.php'],
        ]);
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

    private function writeLifecycle(): void
    {
        $marker = var_export($this->marker, true);

        file_put_contents(
            $this->packageDir . '/scripts.php',
            str_replace('{MARKER}', $marker, <<<'PHP'
                <?php

                declare(strict_types=1);

                use Pagekit\Package\Lifecycle\PackageLifecycle;
                use Psr\Container\ContainerInterface;

                return new class () extends PackageLifecycle {
                    public function install(ContainerInterface $app): void
                    {
                        file_put_contents({MARKER}, 'install', FILE_APPEND);
                    }

                    public function enable(ContainerInterface $app): void
                    {
                        file_put_contents({MARKER}, 'enable', FILE_APPEND);
                    }
                };
                PHP),
        );
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

/**
 * The lifecycle events enable() announces, in order.
 */
final class PackageEnableEvents
{
    /** @var list<string> */
    public array $fired = [];

    public function subscribe(object $subscriber): void
    {
    }

    /**
     * @param array<int, mixed> $params
     */
    public function trigger(string $event, array $params = []): void
    {
        $this->fired[] = $event;
    }
}
