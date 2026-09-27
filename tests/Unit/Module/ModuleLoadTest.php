<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Module;

use Composer\Autoload\ClassLoader;
use Pagekit\Application;
use Pagekit\Module\Loader\AutoLoader;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The entry point runs at load, after the registered record is fixed.
 */
final class ModuleLoadTest extends TestCase
{
    private string $workspace;

    private string $token;

    private ?ClassLoader $loader = null;

    protected function setUp(): void
    {
        ModuleLoadProbe::reset();

        $this->token = bin2hex(random_bytes(4));
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_module_load_'.getmypid().'_'.$this->token;

        if (!mkdir($this->workspace, 0755, true) && !is_dir($this->workspace)) {
            self::fail('The fixture workspace could not be created.');
        }
    }

    protected function tearDown(): void
    {
        $this->loader?->unregister();
        $this->loader = null;
        ModuleLoadProbe::reset();
        $this->removeTree($this->workspace);
    }

    public function testDefaultsFillWhatTheEntryPointOmitsAndKeepWhatItSets(): void
    {
        $app = new Application();
        $manager = new ModuleManager($app);
        $manager->register([
            $this->plant('closure-main', ['name' => 'closure-main'], <<<'PHP'
                <?php

                declare(strict_types=1);

                return [
                    'main' => function ($passed) use ($app) {
                        \Pagekit\Tests\Unit\Module\ModuleLoadProbe::$mainRan = true;
                        \Pagekit\Tests\Unit\Module\ModuleLoadProbe::$mainParameter = $passed;
                        \Pagekit\Tests\Unit\Module\ModuleLoadProbe::$includedApp = $app;
                    },
                ];

                PHP),
            $this->plant('events-omitted', ['name' => 'events-omitted'], <<<'PHP'
                <?php

                declare(strict_types=1);

                return [
                    'events' => [
                        'probe.omitted' => function ($event) use ($app) {
                            \Pagekit\Tests\Unit\Module\ModuleLoadProbe::$omittedEventRan = true;
                            \Pagekit\Tests\Unit\Module\ModuleLoadProbe::$eventApp = $app;
                        },
                    ],
                ];

                PHP),
            $this->plant('events-set', ['name' => 'events-set'], <<<'PHP'
                <?php

                declare(strict_types=1);

                return [
                    'config' => ['title' => 'Set'],
                    'type' => 'extension',
                    'events' => [
                        'probe.set' => function ($event) {
                            \Pagekit\Tests\Unit\Module\ModuleLoadProbe::$setEventRan = true;
                        },
                    ],
                ];

                PHP),
            $this->plant('custom-class', ['name' => 'custom-class'], $this->classEntry()),
        ]);

        $manager->load(['closure-main', 'events-omitted', 'events-set', 'custom-class']);

        $closure = $manager->get('closure-main');
        $omitted = $manager->get('events-omitted');
        $set = $manager->get('events-set');
        $custom = $manager->get('custom-class');

        self::assertInstanceOf(Module::class, $closure);
        self::assertSame(Module::class, $closure::class);
        self::assertSame([], $closure->config());
        self::assertSame('module', $closure->get('type'));
        self::assertSame(Module::class, $closure->get('class'));
        self::assertTrue(ModuleLoadProbe::$mainRan);
        self::assertSame($app, ModuleLoadProbe::$mainParameter);
        self::assertSame($app, ModuleLoadProbe::$includedApp);

        self::assertInstanceOf(Module::class, $omitted);
        self::assertSame(Module::class, $omitted::class);
        self::assertSame([], $omitted->config());
        self::assertSame('module', $omitted->get('type'));
        self::assertNull($omitted->get('main'));
        self::assertSame(Module::class, $omitted->get('class'));

        self::assertInstanceOf(Module::class, $set);
        self::assertSame(Module::class, $set::class);
        self::assertSame(['title' => 'Set'], $set->config());
        self::assertSame('extension', $set->get('type'));
        self::assertNull($set->get('main'));

        self::assertInstanceOf(ProbeModule::class, $custom);
        self::assertSame(ProbeModule::class, $custom::class);
        self::assertSame([], $custom->config());
        self::assertSame('module', $custom->get('type'));
        self::assertSame(ProbeModule::class, $custom->get('class'));

        self::assertFalse(ModuleLoadProbe::$omittedEventRan);
        self::assertFalse(ModuleLoadProbe::$setEventRan);

        $app->get('events')->trigger('probe.omitted');
        $app->get('events')->trigger('probe.set');

        self::assertTrue(ModuleLoadProbe::$omittedEventRan);
        self::assertTrue(ModuleLoadProbe::$setEventRan);
        self::assertSame($app, ModuleLoadProbe::$eventApp);
    }

    public function testTheRegisteredRecordWinsOverTheEntryPoint(): void
    {
        $nodes = [
            'post' => [
                'label' => 'Post',
                'on' => true,
                'n' => 2,
                'ratio' => 1.5,
                'extra' => null,
                'tags' => ['a', 'b'],
                'child' => ['deep' => true],
            ],
        ];
        $manager = $this->manager();
        $manager->register([
            $this->plant('alpha', ['name' => 'alpha']),
            $this->plant('recorded', [
                'name' => 'recorded',
                'require' => ['alpha'],
                'include' => 'modules/*/module.json',
                'autoload' => ['Recorded\\' => 'src'],
                'nodes' => $nodes,
                'title' => 'Hidden',
            ], <<<'PHP'
                <?php

                declare(strict_types=1);

                return [
                    'name' => 'hijacked',
                    'require' => ['nope'],
                    'include' => 'hijacked/*.json',
                    'autoload' => [
                        'Hijacked\\' => 'nope',
                    ],
                    'nodes' => [
                        'hijacked' => ['label' => 'Nope'],
                    ],
                    'path' => '/hijacked',
                    'type' => 'extension',
                    'config' => ['kept' => true],
                    'class' => 'Hijacked\\Module',
                    'main' => function ($app) {
                        \Pagekit\Tests\Unit\Module\ModuleLoadProbe::$mainRan = true;
                    },
                ];

                PHP),
        ]);

        self::assertSame(['post'], $manager->nodeTypes('recorded'));

        $manager->load('recorded');

        $loaded = $manager->get('recorded');
        $directory = $this->workspace.'/recorded';

        self::assertInstanceOf(Module::class, $loaded);
        self::assertSame(Module::class, $loaded::class);
        self::assertSame('recorded', $loaded->name);
        self::assertSame('recorded', $loaded->get('name'));
        self::assertSame(['alpha'], $loaded->get('require'));
        self::assertSame('modules/*/module.json', $loaded->get('include'));
        self::assertSame(['Recorded\\' => 'src'], $loaded->get('autoload'));
        self::assertSame($nodes, $loaded->get('nodes'));
        self::assertSame($directory, $loaded->path);
        self::assertSame($directory, $loaded->get('path'));
        self::assertNull($loaded->get('title'));
        self::assertSame('extension', $loaded->get('type'));
        self::assertSame(['kept' => true], $loaded->config());
        self::assertSame('Hijacked\\Module', $loaded->get('class'));
        self::assertTrue(ModuleLoadProbe::$mainRan);
        self::assertFalse($manager->isRegistered('hijacked'));
        self::assertNull($manager->get('hijacked'));
        self::assertInstanceOf(Module::class, $manager->get('alpha'));
        self::assertSame(['post'], $manager->nodeTypes('recorded'));
    }

    public function testALoaderAddedFromMainSeesOnlyALaterEntryPoint(): void
    {
        $manager = $this->manager();
        ModuleLoadProbe::$manager = $manager;
        $manager->register([
            $this->plant('appender', ['name' => 'appender'], <<<'PHP'
                <?php

                declare(strict_types=1);

                return [
                    'onlyOnEntry' => 'appender',
                    'main' => function ($app) {
                        $manager = \Pagekit\Tests\Unit\Module\ModuleLoadProbe::$manager;

                        if (!$manager instanceof \Pagekit\Module\ModuleManager) {
                            throw new \RuntimeException('The manager is not in scope.');
                        }

                        $manager->addLoader(static function (mixed $module): mixed {
                            if (is_array($module) && isset($module['name']) && is_string($module['name'])) {
                                $key = $module['onlyOnEntry'] ?? null;
                                \Pagekit\Tests\Unit\Module\ModuleLoadProbe::$seenEntryKeys[$module['name']] = is_string($key) ? $key : null;
                            }

                            return $module;
                        });
                    },
                ];

                PHP),
            $this->plant('later', ['name' => 'later'], <<<'PHP'
                <?php

                declare(strict_types=1);

                return [
                    'onlyOnEntry' => 'later',
                ];

                PHP),
        ]);

        // The loader is added from the first module's main, so only a module loaded after that runs it.
        $manager->load(['appender', 'later']);

        $appender = $manager->get('appender');
        $later = $manager->get('later');

        self::assertInstanceOf(Module::class, $appender);
        self::assertInstanceOf(Module::class, $later);
        self::assertSame('appender', $appender->get('onlyOnEntry'));
        self::assertSame('later', $later->get('onlyOnEntry'));
        self::assertSame(['later' => 'later'], ModuleLoadProbe::$seenEntryKeys);
    }

    public function testTheAutoloadMapIsAppliedWhenTheModuleIsLoaded(): void
    {
        $loader = $this->classLoader();
        $manager = new ModuleManager(new Application());
        $manager->addLoader(new AutoLoader($loader));

        $probe = $this->plantAutoloaded('probe', 'Fixture\\Probe'.$this->token, $this->fileScopeEntry('Fixture\\Probe'.$this->token.'\\Marker'));
        $idle = $this->plantAutoloaded('idle', 'Fixture\\Idle'.$this->token, null);

        $manager->register([$probe['manifest'], $idle['manifest']]);

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertArrayNotHasKey($probe['prefix'], $loader->getPrefixesPsr4());
        self::assertArrayNotHasKey($idle['prefix'], $loader->getPrefixesPsr4());
        self::assertFalse(class_exists($probe['class'], false));

        // Not enabled, and not a requirement of the boot module.
        $manager->setActivityPolicy(['other'], 'system');

        self::assertFalse($manager->isAlwaysLoaded('probe'));

        $manager->load('probe');

        $loaded = $manager->get('probe');

        self::assertInstanceOf(Module::class, $loaded);
        self::assertSame('file-scope', $loaded->get('seen'));
        self::assertTrue(class_exists($probe['class'], false));
        self::assertSame([$probe['src']], $loader->getPrefixesPsr4()[$probe['prefix']] ?? null);
        self::assertArrayNotHasKey($idle['prefix'], $loader->getPrefixesPsr4());
        self::assertFalse(class_exists($idle['class'], false));
        self::assertNull($manager->get('idle'));
    }

    public function testAStringFromTheEntryPointIsRefusedAndTheAutoloadPrefixStays(): void
    {
        $planted = $this->plantThrowingAutoload('kept', "<?php\n\ndeclare(strict_types=1);\n\nreturn 'no';\n");
        $manager = $planted['manager'];
        $loader = $planted['loader'];

        self::assertArrayNotHasKey($planted['prefix'], $loader->getPrefixesPsr4());

        try {
            $manager->load('kept');
            self::fail('A string from the entry point has to be refused.');
        } catch (\RuntimeException $exception) {
            self::assertSame(\RuntimeException::class, $exception::class);
            self::assertSame('Module "kept" entry point must return an array.', $exception->getMessage());
        }

        self::assertTrue($manager->isRegistered('kept'));
        self::assertNull($manager->get('kept'));
        self::assertSame([], $manager->getRegistrationFailures());
        self::assertSame([$planted['src']], $loader->getPrefixesPsr4()[$planted['prefix']] ?? null);

        $found = $loader->findFile($planted['class']);

        self::assertSame($planted['file'], strtr((string) $found, '\\', '/'));
        self::assertTrue(class_exists($planted['class'], true));
    }

    public function testAThrowingEntryPointIsNotStoredAndTheAutoloadPrefixStays(): void
    {
        $entry = "<?php\n\ndeclare(strict_types=1);\n\nthrow new \\RuntimeException('The entry point ran');\n";
        $planted = $this->plantThrowingAutoload('boom', $entry);
        $manager = $planted['manager'];
        $loader = $planted['loader'];

        try {
            $manager->load('boom');
            self::fail('A throw from the entry point has to leave load().');
        } catch (\RuntimeException $exception) {
            self::assertSame(\RuntimeException::class, $exception::class);
            self::assertSame('The entry point ran', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertTrue($manager->isRegistered('boom'));
        self::assertNull($manager->get('boom'));
        self::assertSame([], $manager->getRegistrationFailures());
        self::assertSame([$planted['src']], $loader->getPrefixesPsr4()[$planted['prefix']] ?? null);

        $found = $loader->findFile($planted['class']);

        self::assertSame($planted['file'], strtr((string) $found, '\\', '/'));
    }

    public function testAMissingEntryPointStillLoadsTheRegisteredRecord(): void
    {
        $manager = $this->manager();
        $manager->register([$this->plant('bare', ['name' => 'bare'])]);

        $manager->load('bare');

        $loaded = $manager->get('bare');

        self::assertInstanceOf(Module::class, $loaded);
        self::assertSame(Module::class, $loaded::class);
        self::assertSame([], $loaded->config());
        self::assertSame('module', $loaded->get('type'));
        self::assertSame(Module::class, $loaded->get('class'));
        self::assertNull($loaded->get('main'));
        self::assertSame($this->workspace.'/bare', $loaded->path);
    }

    public function testLoadDoesNotRegisterAModuleThatAppearsLater(): void
    {
        $manager = $this->manager();
        $manager->register([
            $this->plant('host', [
                'name' => 'host',
                'include' => 'modules/*/module.json',
            ]),
        ]);

        self::assertFalse($manager->isRegistered('child'));

        $this->plant('host/modules/child', ['name' => 'child']);
        $manager->load('host');

        self::assertInstanceOf(Module::class, $manager->get('host'));
        self::assertFalse($manager->isRegistered('child'));
        self::assertSame([], $manager->getRegistrationFailures());
    }

    public function testAModuleRecordWithoutAPathIsNotStored(): void
    {
        $manager = $this->manager();

        // register() always writes a string path. A record without one has to fail the load.
        (new \ReflectionProperty(ModuleManager::class, 'registered'))->setValue($manager, [
            'orphan' => [
                'name' => 'orphan',
                'require' => [],
            ],
        ]);

        try {
            $manager->load('orphan');
            self::fail('A module without a path has to be refused.');
        } catch (\RuntimeException $exception) {
            self::assertSame(\RuntimeException::class, $exception::class);
            self::assertSame('Module "orphan" has no path.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertTrue($manager->isRegistered('orphan'));
        self::assertNull($manager->get('orphan'));
        self::assertSame([], $manager->getRegistrationFailures());
    }

    private function manager(): ModuleManager
    {
        return new ModuleManager(new Application());
    }

    private function classLoader(): ClassLoader
    {
        $loader = new ClassLoader();
        $loader->register();
        $this->loader = $loader;

        return $loader;
    }

    private function classEntry(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'class' => ".var_export(ProbeModule::class, true).",\n];\n";
    }

    private function fileScopeEntry(string $class): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'seen' => ".$class."::TOKEN,\n];\n";
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function plant(string $directory, array $manifest, ?string $entry = null): string
    {
        $path = $this->workspace.'/'.$directory;

        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            self::fail('The fixture module directory could not be created.');
        }

        $file = $path.'/'.ModuleManifest::FILE;
        $json = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if (file_put_contents($file, $json."\n") === false) {
            self::fail('The fixture manifest could not be written.');
        }

        if ($entry !== null && file_put_contents($path.'/'.ModuleManifest::ENTRY, $entry) === false) {
            self::fail('The fixture entry point could not be written.');
        }

        return $file;
    }

    /**
     * @return array{prefix: string, class: string, file: string, manifest: string, src: string}
     */
    private function plantAutoloaded(string $name, string $namespace, ?string $entry): array
    {
        $prefix = $namespace.'\\';
        $class = $prefix.'Marker';
        $src = $this->workspace.'/'.$name.'/src';

        if (!mkdir($src, 0755, true) && !is_dir($src)) {
            self::fail('The fixture class directory could not be created.');
        }

        $classFile = $src.'/Marker.php';
        $classSource = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$namespace.";\n\nfinal class Marker\n{\n    public const TOKEN = 'file-scope';\n}\n";

        if (file_put_contents($classFile, $classSource) === false) {
            self::fail('The fixture class could not be written.');
        }

        return [
            'prefix' => $prefix,
            'class' => $class,
            'file' => $classFile,
            'manifest' => $this->plant($name, [
                'name' => $name,
                'autoload' => [$prefix => 'src'],
            ], $entry),
            'src' => $src,
        ];
    }

    /**
     * @return array{manager: ModuleManager, loader: ClassLoader, prefix: string, class: string, file: string, src: string}
     */
    private function plantThrowingAutoload(string $name, string $entry): array
    {
        $loader = $this->classLoader();
        $manager = new ModuleManager(new Application());
        $manager->addLoader(new AutoLoader($loader));

        $namespace = 'Fixture\\'.ucfirst($name).$this->token;
        $planted = $this->plantAutoloaded($name, $namespace, $entry);

        $manager->register([$planted['manifest']]);

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertTrue($manager->isRegistered($name));

        return [
            'manager' => $manager,
            'loader' => $loader,
            'prefix' => $planted['prefix'],
            'class' => $planted['class'],
            'file' => $planted['file'],
            'src' => $planted['src'],
        ];
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

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeTree($path.'/'.$entry);
        }

        rmdir($path);
    }
}

/**
 * Side channel for entry points included by the manager, which cannot close over the test.
 */
final class ModuleLoadProbe
{
    public static bool $mainRan = false;

    public static bool $omittedEventRan = false;

    public static bool $setEventRan = false;

    public static ?Application $mainParameter = null;

    public static ?Application $includedApp = null;

    public static ?Application $eventApp = null;

    /** @var array<string, string|null> */
    public static array $seenEntryKeys = [];

    public static ?ModuleManager $manager = null;

    public static function reset(): void
    {
        self::$mainRan = false;
        self::$omittedEventRan = false;
        self::$setEventRan = false;
        self::$mainParameter = null;
        self::$includedApp = null;
        self::$eventApp = null;
        self::$seenEntryKeys = [];
        self::$manager = null;
    }
}

/**
 * Stand-in for an entry point that names its own module class.
 */
final class ProbeModule extends Module
{
}
