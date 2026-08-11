<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Pagekit\Application;
use Pagekit\Application\Response as PagekitResponse;
use Pagekit\Application\UrlProvider;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Controller\PackageController;
use Pagekit\Installer\Package\Package;
use Pagekit\Installer\Package\PackageFactory;
use Pagekit\Installer\Package\PackageManager;
use Pagekit\Log\Logger;
use Pagekit\System\Extension\ExtensionFailureStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the extension manager does with the record of a package that failed.
 *
 * The record is what keeps a broken extension out of the next boot and what the
 * admin notice is derived from, so it outlives the request that wrote it by
 * design. That makes clearing it part of every administrator action on the
 * package: left behind after an enable, it would hold off an extension that
 * works again and go on reporting a failure nobody can find; left behind after
 * an uninstall, it would name something that is not installed any more and
 * nothing would ever clear it.
 *
 * An enable that fails again is the case that must not clear it. The manager
 * loads the package explicitly on that path, so a still-broken extension fails
 * inside the enable and the record has to survive to keep doing both its jobs.
 *
 * An enable that cannot clear it is the case that must not report success. The
 * record is read before the configuration on the next boot, so an extension
 * left on it stays out however the configuration reads - "enabled" in the panel
 * and absent from every boot is the one answer the administrator may not get.
 * The theme is the exception on both counts: it is executed whether or not it
 * is on the record, and the boot that loads it takes it off.
 *
 * Until it is cleared, the manager screen is where the difference shows: an
 * extension a failure switched off and one an administrator switched off are
 * both simply not enabled, and only one of the two is waiting for someone to
 * read the log.
 */
final class PackageFailureRecordTest extends TestCase
{
    private string $workspace;

    /**
     * Where the record lives, which is a directory of the system module the
     * installer environment does not have.
     */
    private string $path;

    /**
     * The package on disk, which the manager reads the installed version from.
     */
    private string $packageDir;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_package_failures_' . getmypid() . '_' . uniqid();
        $this->path = $this->workspace . '/system';
        $this->packageDir = $this->workspace . '/packages/pagekit/test-ext';

        mkdir($this->packageDir, 0755, true);

        file_put_contents($this->packageDir . '/composer.json', (string) json_encode([
            'name' => 'pagekit/test-ext',
            'type' => 'pagekit-extension',
            'version' => '1.0.0',
        ]));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    // ------------------------------------------------------------------
    // What the manager reports as failed
    // ------------------------------------------------------------------

    public function testTheModulesOnRecordAreTheOnesTheManagerReports(): void
    {
        $this->record('test-ext');
        $this->record('theme-one');

        $failed = $this->manager($this->container())->getFailedModules();
        sort($failed);

        self::assertSame(['test-ext', 'theme-one'], $failed);
    }

    public function testAnEnvironmentThatKeepsNoRecordReportsNoFailures(): void
    {
        // The record belongs to the system module, which the installer does not
        // load. There is nothing to read there, and the screens that ask still
        // have to render.
        self::assertSame([], $this->manager($this->container(withStore: false))->getFailedModules());
    }

    public function testSomethingOtherThanARecordUnderThatNameReportsNoFailures(): void
    {
        $app = $this->container(withStore: false);
        $app->set('extension.failures', new \stdClass());

        // The container answers what is registered, not what the manager hoped
        // for, and an extension may register anything under any name.
        self::assertSame([], $this->manager($app)->getFailedModules());
    }

    // ------------------------------------------------------------------
    // Clearing the record
    // ------------------------------------------------------------------

    public function testEnablingAPackageAgainTakesItOffTheRecord(): void
    {
        $this->record('test-ext');
        $this->record('theme-one');

        $system = new Config();
        $system->set('packages.test-ext', '1.0.0');

        $this->manager($this->container(config: $system))->enable($this->package());

        // Only the package that was acted on: a second failure still has to be
        // reported, and the extension it names still has to stay out of boot.
        self::assertSame(['theme-one'], array_keys($this->store()->all()));
        self::assertContains('test-ext', (array) $system->get('extensions'));
    }

    public function testSelectingAFailedThemeAgainTakesItOffTheRecord(): void
    {
        $this->record('test-theme');

        $system = new Config();
        $system->set('packages.test-theme', '1.0.0');

        $this->manager($this->container(config: $system))->enable($this->package([
            'name' => 'pagekit/test-theme',
            'type' => 'pagekit-theme',
            'module' => 'test-theme',
        ]));

        // A theme is never switched off on the administrator's behalf - the site
        // only falls back to a blank layout - so selecting it again is the one
        // thing that answers the notice naming it.
        self::assertFalse($this->store()->has('test-theme'));
        self::assertSame('test-theme', $system->get('site.theme'));
    }

    public function testAnEnableThatFailsAgainLeavesTheRecordWhereItWas(): void
    {
        $this->record('test-ext');
        $this->writeScripts('enable', "throw new \\RuntimeException('Still broken');");

        $system = new Config();
        $system->set('packages.test-ext', '1.0.0');

        // AssertionFailedError extends RuntimeException, so a failed assertion
        // inside the try would be swallowed by the catch instead of reported.
        $thrown = null;

        try {
            $this->manager($this->container(config: $system))->enable($this->package(['extra' => ['scripts' => 'scripts.php']]));
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $thrown, 'A package that still fails must report it');
        self::assertStringContainsString('Still broken', $thrown->getMessage());

        // The administrator tried and the extension is still broken. Clearing
        // the record here would let the next boot execute it again and take the
        // failure out of the panel that is the only place naming it.
        self::assertTrue($this->store()->has('test-ext'));
    }

    public function testAnEnableThatCannotTakeAnExtensionOffTheRecordIsRefused(): void
    {
        $this->record('test-ext');

        $log = $this->logService();
        $events = $this->eventService();

        $system = new Config();
        $system->set('packages.test-ext', '1.0.0');

        $app = $this->container(config: $system, writer: new RecordWriterThatFails());
        $app->set('log', $log);
        $app->set('events', $events);

        // AssertionFailedError extends RuntimeException, so a failed assertion
        // inside the try would be swallowed by the catch instead of reported.
        $thrown = null;

        try {
            $this->manager($app)->enable($this->package());
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $thrown, 'An enable that leaves the record standing must say so');
        self::assertStringContainsString('Test Extension', $thrown->getMessage());
        self::assertStringContainsString('test-ext', $thrown->getMessage());
        self::assertTrue($this->store()->has('test-ext'));

        // The record outranks the configuration on the next boot. Enabling in a
        // configuration the boot then overrules is the silent half-state the
        // record exists to prevent, so the configuration goes back to what it
        // said before and the administrator is told the enable did not happen.
        self::assertSame([], (array) $system->get('extensions'));
        self::assertSame('1.0.0', $system->get('packages.test-ext'));
        self::assertNotContains(
            'package.enable',
            $events->fired,
            'listeners restore what a package owns, and nothing was enabled for them to restore it for',
        );

        self::assertCount(1, $log->errors);
        self::assertStringContainsString('could not be cleared', $log->errors[0]);
    }

    public function testSelectingAThemeGoesThroughWhenTheRecordCannotBeCleared(): void
    {
        $this->record('test-theme', ExtensionFailureStore::TYPE_THEME);

        $log = $this->logService();
        $events = $this->eventService();

        $system = new Config();
        $system->set('packages.test-theme', '1.0.0');

        $app = $this->container(config: $system, writer: new RecordWriterThatFails());
        $app->set('log', $log);
        $app->set('events', $events);

        $this->manager($app)->enable($this->package([
            'name' => 'pagekit/test-theme',
            'type' => 'pagekit-theme',
            'module' => 'test-theme',
        ]));

        // A theme is loaded whether or not it is on the record, and the boot
        // that loads it takes it off. Refusing the selection over a file that
        // could not be rewritten would cost the administrator a theme switch
        // for a notice the next request clears by itself.
        self::assertSame('test-theme', $system->get('site.theme'));
        self::assertContains('package.enable', $events->fired);
        self::assertTrue($this->store()->has('test-theme'));
        self::assertCount(1, $log->errors);
        self::assertStringContainsString('test-theme', $log->errors[0]);
    }

    public function testDisablingAPackageTakesItOffTheRecord(): void
    {
        $this->record('test-ext');

        $system = new Config();
        $system->set('extensions', ['test-ext']);

        $this->manager($this->container(config: $system))->disable($this->package());

        // Switching it off is an answer to the failure. Keeping the record would
        // leave a warning standing about a decision that was already made.
        self::assertFalse($this->store()->has('test-ext'));
        self::assertSame([], (array) $system->get('extensions'));
    }

    public function testUninstallingAPackageTakesItOffTheRecord(): void
    {
        $this->record('test-ext');
        $this->record('theme-one');

        $system = new Config();
        $system->set('packages.test-ext', '1.0.0');
        $system->set('extensions', ['test-ext']);

        $files = $this->fileService();

        $app = $this->container(config: $system, paths: true);
        $app->set('package', $this->packages($this->package()));
        $app->set('file', $files);

        $this->manager($app)->uninstall('pagekit/test-ext');

        // A record naming a package that is no longer installed can never be
        // acted on, so nothing would ever clear it again.
        self::assertSame(['theme-one'], array_keys($this->store()->all()));
        self::assertContains($this->packageDir, $files->deleted);
    }

    public function testARecordThatCannotBeClearedIsReportedAndTheOperationGoesThrough(): void
    {
        $this->record('test-ext');

        $log = $this->logService();

        $system = new Config();
        $system->set('extensions', ['test-ext']);

        $app = $this->container(config: $system, writer: new RecordWriterThatFails());
        $app->set('log', $log);

        $this->manager($app)->disable($this->package());

        // The administrator asked for the extension to be off, and a file that
        // could not be rewritten is not a reason to refuse them. What it costs
        // is a notice that stands until someone reads the log line about it.
        self::assertSame([], (array) $system->get('extensions'));
        self::assertTrue($this->store()->has('test-ext'));
        self::assertCount(1, $log->errors);
        self::assertStringContainsString('test-ext', $log->errors[0]);
    }

    public function testAnEnvironmentThatKeepsNoRecordDisablesAsItAlwaysDid(): void
    {
        $system = new Config();
        $system->set('extensions', ['test-ext']);

        $this->manager($this->container(config: $system, withStore: false))->disable($this->package());

        self::assertSame([], (array) $system->get('extensions'));
    }

    // ------------------------------------------------------------------
    // What the extension manager screen is told
    // ------------------------------------------------------------------

    public function testAnExtensionOnRecordIsMarkedInTheExtensionManager(): void
    {
        $this->record('test-ext');

        $app = $this->container();
        $app->get('module')->register([$this->fixture('healthy')]);
        $app->get('module')->load('fixture-healthy');

        $packages = $this->extensions(
            $app,
            $this->package(),
            $this->package(['name' => 'pagekit/healthy', 'module' => 'fixture-healthy']),
        );

        // The failed extension is not enabled and neither is one somebody
        // switched off by hand, which is the whole reason the mark exists.
        self::assertTrue($packages['pagekit/test-ext']->get('failure'));
        self::assertNull($packages['pagekit/test-ext']->get('enabled'));

        self::assertNull($packages['pagekit/healthy']->get('failure'));
        self::assertTrue($packages['pagekit/healthy']->get('enabled'));
    }

    public function testTheMarkIsPartOfWhatTheScreenIsSent(): void
    {
        // The screen is a Vue view that only ever sees the encoded package, so
        // a mark that does not survive the encoding is a mark nobody renders.
        $this->record('test-ext');

        $packages = $this->extensions($this->container(), $this->package());
        $encoded = json_decode((string) json_encode($packages['pagekit/test-ext']), true);

        self::assertIsArray($encoded);
        self::assertTrue($encoded['failure']);
    }

    public function testTheExtensionManagerMarksNothingWhereNoRecordIsKept(): void
    {
        $packages = $this->extensions($this->container(withStore: false), $this->package());

        self::assertNull($packages['pagekit/test-ext']->get('failure'));
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * The container the manager is built against.
     *
     * @param Config|null     $config the site configuration, absent where the environment has none
     * @param Filesystem|null $writer what the record is written through
     * @param bool            $paths  whether the container names the package directories itself
     */
    private function container(
        ?Config $config = null,
        bool $withStore = true,
        ?Filesystem $writer = null,
        bool $paths = false,
    ): Application {
        $app = new Application();

        if ($config !== null) {
            $manager = $this->createMock(ConfigManager::class);
            $manager->method('__invoke')->willReturn($config);

            $app->set('config', $manager);
        }

        if ($withStore) {
            $app->set('extension.failures', new ExtensionFailureStore($this->path, $writer ?? new Filesystem()));
        }

        if ($paths) {
            $root = $this->workspace . '/paths';

            $app->set('path.temp', $root . '/temp');
            $app->set('path.cache', $root . '/cache');
            $app->set('path.vendor', $root . '/vendor');
            $app->set('path.artifact', $root . '/artifact');
            $app->set('path.packages', $root . '/packages');
            $app->set('system.api', 'https://example.test');
        }

        return $app;
    }

    private function manager(Application $app): PackageManager
    {
        return new PackageManager($app, new NullOutput());
    }

    /**
     * The packages the extension manager hands to its view, keyed by name.
     *
     * @return array<string, Package>
     */
    private function extensions(Application $app, Package ...$packages): array
    {
        $factory = new PackageFactory();

        foreach ($packages as $package) {
            $factory[$package->getName()] = $package;
        }

        $controller = new PackageController(
            $this->manager($app),
            $factory,
            $app->get('module'),
            $this->createMock(UrlProvider::class),
            new Request(),
            $this->createMock(PagekitResponse::class),
            $this->workspace,
            false,
            new Logger('test'),
        );

        $indexed = [];

        /** @var array<int, Package> $rendered */
        $rendered = $controller->extensionsAction()['$data']['packages'];

        foreach ($rendered as $package) {
            $indexed[$package->getName()] = $package;
        }

        return $indexed;
    }

    /**
     * Puts a failure on record, the way the boot that ran into it does.
     *
     * @param ExtensionFailureStore::TYPE_* $type
     */
    private function record(string $name, string $type = ExtensionFailureStore::TYPE_EXTENSION): void
    {
        self::assertTrue(
            $this->store()->record($name, $type, new \RuntimeException('The module could not be loaded')),
            'The record has to be on disk before the manager can be asked to clear it',
        );
    }

    private function store(): ExtensionFailureStore
    {
        return new ExtensionFailureStore($this->path, new Filesystem());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function package(array $overrides = []): Package
    {
        return new Package(array_replace([
            'name' => 'pagekit/test-ext',
            'type' => 'pagekit-extension',
            'module' => 'test-ext',
            'title' => 'Test Extension',
            'path' => $this->packageDir,
        ], $overrides));
    }

    private function packages(Package $package): PackageFactory
    {
        $factory = new PackageFactory();
        $factory[$package->getName()] = $package;

        return $factory;
    }

    /**
     * A package lifecycle file whose hook fails, as a package that is still
     * broken when it is enabled again has.
     */
    private function writeScripts(string $hook, string $body): void
    {
        file_put_contents(
            $this->packageDir . '/scripts.php',
            str_replace(['{HOOK}', '{BODY}'], [$hook, $body], <<<'PHP'
                <?php

                declare(strict_types=1);

                use Pagekit\Installer\Package\Lifecycle\PackageLifecycle;
                use Psr\Container\ContainerInterface;

                return new class () extends PackageLifecycle {
                    public function {HOOK}(ContainerInterface $app): void
                    {
                        {BODY}
                    }
                };
                PHP),
        );
    }

    /**
     * The filesystem service the manager removes a package folder through.
     */
    private function fileService(): object
    {
        return new class () {
            /** @var array<int, string> */
            public array $deleted = [];

            public function delete(string $path): bool
            {
                $this->deleted[] = $path;

                return true;
            }
        };
    }

    /**
     * The dispatcher the manager announces a finished package operation on.
     */
    private function eventService(): object
    {
        return new class () {
            /** @var array<int, string> */
            public array $fired = [];

            /** @param array<int, mixed> $params */
            public function trigger(string $event, array $params = []): void
            {
                $this->fired[] = $event;
            }
        };
    }

    /**
     * The logger the manager reports a record it could not clear to.
     */
    private function logService(): object
    {
        return new class () {
            /** @var array<int, string> */
            public array $errors = [];

            /** @param array<string, mixed> $context */
            public function error(string $message, array $context = []): void
            {
                $this->errors[] = $message;
            }
        };
    }

    private function fixture(string $name): string
    {
        return strtr(dirname(__DIR__, 3), '\\', '/') . '/tests/fixtures/modules/' . $name . '/index.php';
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
 * A filesystem whose write never happens, as a full disk or a read-only mount
 * makes it. The record is then left holding a failure nobody acted on.
 */
final class RecordWriterThatFails extends Filesystem
{
    public function dumpAtomic(string $file, string $content, ?int $mode = null): void
    {
        throw new \RuntimeException("Failed to write file ($file).");
    }
}
