<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Package\Package;
use Pagekit\Installer\Package\PackageFactory;
use Pagekit\Installer\Package\PackageManager;
use Pagekit\System\Extension\ExtensionFailureStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Getting out from under a package that misbehaves.
 *
 * Switching a package off and removing it are the two actions an administrator
 * is left with when one of them turns out to be broken, and both of them run
 * the package's own code on the way out. A throw from that code used to end the
 * operation, which left the extension in exactly the state the administrator
 * was trying to leave - still enabled or still installed, still failing on
 * every boot - and made the one way out of it unusable for the same reason the
 * way out was needed.
 *
 * What is asserted here is that the package gets its say and no veto: its hook
 * runs, a throw from it is reported and costs the hook alone, and everything
 * the operation owes the rest of the system still happens - the event other
 * modules archive the extension's content on, the enabled list, the removal of
 * the folder, the record that would otherwise go on naming it.
 *
 * Nothing on that path may throw a second time either. Reporting the failure is
 * the last thing the recovery does, and a logger that is missing or broken is
 * not a reason to refuse an administrator the way out.
 *
 * Enabling is deliberately not like this. A hook that fails there means the
 * package is not ready to run, and the caller has to hear about it.
 */
final class PackageHookBarrierTest extends TestCase
{
    private string $workspace;

    /**
     * Where the failure record lives, which is a directory of the system module.
     */
    private string $path;

    /**
     * The package on disk, whose lifecycle file each test writes.
     */
    private string $packageDir;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_hook_barrier_' . getmypid() . '_' . uniqid();
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
    // Switching a broken package off
    // ------------------------------------------------------------------

    public function testAPackageIsSwitchedOffEvenWhenItsOwnHookThrows(): void
    {
        $this->writeLifecycle('disable', "throw new \\RuntimeException('Broken on the way out');");
        $this->record('test-ext');

        $system = new Config();
        $system->set('extensions', ['test-ext']);

        $events = new RecordedEvents();
        $log = new RecordedLog();

        $app = $this->container($system);
        $app->set('events', $events);
        $app->set('log', $log);

        $this->manager($app)->disable($this->package());

        // The administrator asked for the extension to be off. Everything that
        // makes it off has to happen anyway: the list it boots from, the event
        // other modules archive its content on, and the record that would
        // otherwise keep warning about a decision already taken.
        self::assertSame([], (array) $system->get('extensions'));
        self::assertSame(['package.disable'], $events->fired);
        self::assertFalse($this->store()->has('test-ext'));

        // What the failure costs is a log line, which has to name the hook and
        // the package and carry the throwable so its trace is recoverable.
        self::assertCount(1, $log->records);
        self::assertStringContainsString('disable hook', $log->records[0]['message']);
        self::assertStringContainsString('pagekit/test-ext', $log->records[0]['message']);
        self::assertStringContainsString('Broken on the way out', $log->records[0]['message']);
        self::assertInstanceOf(\RuntimeException::class, $log->records[0]['context']['exception'] ?? null);
        self::assertSame('test-ext', $log->records[0]['context']['package'] ?? null);
    }

    public function testAPackageWhoseHookFailsOnAnErrorIsSwitchedOffTheSameWay(): void
    {
        // A package whose dependency was removed underneath it fails on a class
        // that cannot be loaded, which PHP raises as an Error rather than an
        // exception. That is the everyday case, not the exotic one.
        $this->writeLifecycle('disable', '\Pagekit\Absent\Vanished::gone();');

        $system = new Config();
        $system->set('extensions', ['test-ext']);

        $log = new RecordedLog();

        $app = $this->container($system);
        $app->set('log', $log);

        $this->manager($app)->disable($this->package());

        self::assertSame([], (array) $system->get('extensions'));
        self::assertCount(1, $log->records);
        self::assertInstanceOf(\Error::class, $log->records[0]['context']['exception'] ?? null);
    }

    public function testAPackageWhoseLifecycleFileDeliversNoLifecycleIsStillSwitchedOff(): void
    {
        // The file itself is the fault here: it is read on the first hook the
        // operation asks for, so a package that cannot produce a lifecycle at
        // all fails in the same place one with a throwing hook does.
        file_put_contents(
            $this->packageDir . '/scripts.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn ['disable' => function (\$app) {}];\n",
        );

        $system = new Config();
        $system->set('extensions', ['test-ext']);

        // No log service either: an installation that reports nowhere still has
        // to let go of the package.
        $this->manager($this->container($system))->disable($this->package());

        self::assertSame([], (array) $system->get('extensions'));
    }

    public function testNothingLeftToTakeTheReportIsNoReasonToRefuse(): void
    {
        $this->writeLifecycle('disable', "throw new \\RuntimeException('Broken on the way out');");

        $system = new Config();
        $system->set('extensions', ['test-ext']);

        $app = $this->container($system);
        $app->set('log', new LogThatFails());

        // Reporting is the last thing the recovery does, and what it reports on
        // may be the very thing that broke. A second throw from there would
        // undo the isolation the barrier exists for.
        $this->manager($app)->disable($this->package());

        self::assertSame([], (array) $system->get('extensions'));
    }

    // ------------------------------------------------------------------
    // Removing a broken package
    // ------------------------------------------------------------------

    public function testAPackageIsRemovedEvenWhenItsOwnHookThrows(): void
    {
        $this->writeLifecycle('uninstall', "throw new \\RuntimeException('Broken on the way out');");
        $this->record('test-ext');

        $system = new Config();
        $system->set('packages.test-ext', '1.0.0');
        $system->set('extensions', ['test-ext']);

        $events = new RecordedEvents();
        $log = new RecordedLog();
        $files = new RecordedFiles();

        $app = $this->container($system, paths: true);
        $app->set('package', $this->packages($this->package()));
        $app->set('file', $files);
        $app->set('events', $events);
        $app->set('log', $log);

        $this->manager($app)->uninstall('pagekit/test-ext');

        // A removal that stopped at the hook would leave the package installed,
        // enabled and recorded as failing, with its folder still on disk - and
        // nothing else to try.
        self::assertNull($system->get('packages.test-ext'));
        self::assertSame([], (array) $system->get('extensions'));
        self::assertSame([$this->packageDir], $files->deleted);
        self::assertFalse($this->store()->has('test-ext'));

        // The listeners that soft-delete the content an extension owns are
        // announced after the hook had its chance, whether it took it or not.
        self::assertSame(['package.disable', 'package.uninstall'], $events->fired);

        self::assertCount(1, $log->records);
        self::assertStringContainsString('uninstall hook', $log->records[0]['message']);
        self::assertStringContainsString('pagekit/test-ext', $log->records[0]['message']);
    }

    // ------------------------------------------------------------------
    // The other side of the contract
    // ------------------------------------------------------------------

    public function testEnablingAPackageWhoseLifecycleFileDeliversNoLifecycleIsRefused(): void
    {
        file_put_contents(
            $this->packageDir . '/scripts.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn ['enable' => function (\$app) {}];\n",
        );

        $system = new Config();

        // A failed assertion is itself a RuntimeException, so the failure is
        // captured and asserted on outside the catch.
        $thrown = null;

        try {
            $this->manager($this->container($system))->enable($this->package());
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        // Nothing was switched off here - a package is being taken into use,
        // and one whose lifecycle cannot even be read is not ready to run. The
        // administrator has to be told instead of ending up with an extension
        // whose install hook silently never ran.
        self::assertInstanceOf(\RuntimeException::class, $thrown);
        self::assertStringContainsString('Test Extension', $thrown->getMessage());
        self::assertStringContainsString('must return', $thrown->getMessage());
        self::assertNull($system->get('packages.test-ext'));
        self::assertSame([], (array) $system->get('extensions'));
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * The container the manager is built against.
     *
     * @param bool $paths whether the container names the package directories itself,
     *                    which the removal path reads to find what is installed
     */
    private function container(Config $system, bool $paths = false): Application
    {
        $app = new Application();

        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($system);

        $app->set('config', $config);
        $app->set('extension.failures', new ExtensionFailureStore($this->path, new Filesystem()));

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
            'extra' => ['scripts' => 'scripts.php'],
        ], $overrides));
    }

    private function packages(Package $package): PackageFactory
    {
        $factory = new PackageFactory();
        $factory[$package->getName()] = $package;

        return $factory;
    }

    /**
     * The package's lifecycle file, implementing the one hook a test drives.
     */
    private function writeLifecycle(string $hook, string $body): void
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
     * Puts a failure on record, the way the boot that ran into it does.
     */
    private function record(string $name): void
    {
        self::assertTrue(
            $this->store()->record($name, ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('The module could not be loaded')),
            'The record has to be on disk before an operation can be asked to clear it',
        );
    }

    private function store(): ExtensionFailureStore
    {
        return new ExtensionFailureStore($this->path, new Filesystem());
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
 * The lifecycle events other modules act on, in the order they were announced.
 */
final class RecordedEvents
{
    /** @var array<int, string> */
    public array $fired = [];

    /**
     * @param array<int, mixed> $params
     */
    public function trigger(string $event, array $params = []): void
    {
        $this->fired[] = $event;
    }
}

/**
 * What was reported, with the context that carries the throwable.
 */
final class RecordedLog
{
    /** @var array<int, array{message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->records[] = ['message' => $message, 'context' => $context];
    }
}

/**
 * A logger that is itself broken, as one writing to a full disk or a log
 * directory that went away is.
 */
final class LogThatFails
{
    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        throw new \RuntimeException('The log could not be written.');
    }
}

/**
 * The filesystem service a package folder is removed through.
 */
final class RecordedFiles
{
    /** @var array<int, string> */
    public array $deleted = [];

    public function delete(string $path): bool
    {
        $this->deleted[] = $path;

        return true;
    }
}
