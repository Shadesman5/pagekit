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
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * What an administrator is told about a step of a package's own that did not
 * finish.
 *
 * Switching a package off and removing it run the package's own hooks, and a
 * throw from one of them costs the hook and nothing else - the operation goes
 * through, because it is the way out from under a broken package. What that used
 * to cost as well was any word of it: the panel said the package was disabled or
 * removed and the reason sat in a log nobody had been sent to, so an
 * administrator was left with an extension whose own cleanup had silently not
 * run.
 *
 * These are the words. Which step of which package did not finish, and where the
 * rest of it is - and never what the hook threw, because that is a package's own
 * text arriving in a panel: a query, a path, the credentials of a database. It
 * goes to the log, where it is read deliberately, and the two are asserted
 * against each other here.
 *
 * A removal reports through a stream the page takes apart line by line, so what
 * is asserted for it is the protocol rather than the wording: a warning is a line
 * of its own, the status is the last line, and neither is something a package can
 * write by choosing its own name.
 *
 * The package is a real package with a real lifecycle file, because the throw
 * being asserted on has to come out of code the installation loaded rather than
 * out of a stubbed collaborator.
 */
final class PackageHookWarningTest extends TestCase
{
    /**
     * What a broken hook throws. The everyday shape of it: a message with a host
     * and a user in it, which is exactly what may not be shown to whoever is
     * clicking "disable" in a browser.
     */
    private const THROWN = 'Access denied for user pagekit at db.internal:3306';

    private string $workspace;

    /**
     * The package whose own step fails, and one that has nothing to report.
     */
    private string $broken;

    private string $quiet;

    /**
     * What the installation had cached, and whether an operation asked for it to
     * be rebuilt.
     */
    private RecordedCacheRebuilds $cache;

    /**
     * What the administrator is not told, as the log receives it.
     */
    private HookWarningLog $log;

    protected function setUp(): void
    {
        require_once __DIR__.'/bootstrap.php';

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_hook_warning_'.getmypid().'_'.uniqid();
        $this->broken = $this->workspace.'/packages/pagekit/test-ext';
        $this->quiet = $this->workspace.'/packages/pagekit/other-ext';
        $this->cache = new RecordedCacheRebuilds();
        $this->log = new HookWarningLog();

        mkdir($this->broken, 0755, true);
        mkdir($this->quiet, 0755, true);

        foreach ([$this->broken, $this->quiet] as $tree) {
            file_put_contents($tree.'/composer.json', (string) json_encode([
                'name' => 'pagekit/'.basename($tree),
                'type' => 'pagekit-extension',
                'version' => '1.0.0',
            ]));
        }

        // Both ship a lifecycle that does what it is asked, which is what a
        // package looks like before one of its steps breaks: a hook that ran and
        // did not throw reports nothing, and that is not the same thing as a
        // package that never had one to run. The tests below replace the file of
        // the one whose step is the failure under test.
        $this->writeLifecycle($this->broken, 'disable', '');
        $this->writeLifecycle($this->quiet, 'disable', '');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    // ------------------------------------------------------------------
    // Switching a package off
    // ------------------------------------------------------------------

    public function testSwitchingOffAPackageWhoseOwnStepFailedSaysWhichStepAndWhereTheRestIs(): void
    {
        $this->writeLifecycle($this->broken, 'disable', sprintf("throw new \\RuntimeException('%s');", self::THROWN));

        $answer = $this->controller()->disableAction('pagekit/test-ext');

        // Still a success: the package is off, which is what was asked for. The
        // warning is beside that answer rather than instead of it, or the page
        // would report a failed operation that actually happened.
        self::assertSame('success', $answer['message']);
        self::assertIsArray($answer['warnings']);
        self::assertCount(1, $answer['warnings']);

        $warning = (string) $answer['warnings'][0];

        // Which step, of which package, and where to read the rest. The title is
        // what the panel lists the package as, so it is what an administrator
        // recognises it by.
        self::assertStringContainsString('disable', $warning);
        self::assertStringContainsString('Test Extension', $warning);
        self::assertStringContainsString('error log', $warning);

        // And not a word of what the hook threw. It is a package's own text and
        // this one names a host and a user; the log is where it belongs, with
        // the throwable that carries the trace.
        self::assertStringNotContainsString(self::THROWN, $warning);
        self::assertTrue($this->reported('disable hook', self::THROWN));
    }

    public function testSwitchingOffAPackageThatHadNothingToReportSaysNothing(): void
    {
        // The page shows a notification per warning and reloads the panel when
        // there are none, so an operation that went through cleanly has to hand
        // back the empty list rather than nothing at all.
        $answer = $this->controller()->disableAction('pagekit/other-ext');

        self::assertSame('success', $answer['message']);
        self::assertSame([], $answer['warnings']);
    }

    public function testTheWarningsOfOneOperationAreNotShownAgainByTheNext(): void
    {
        $this->writeLifecycle($this->broken, 'disable', sprintf("throw new \\RuntimeException('%s');", self::THROWN));

        $controller = $this->controller();

        self::assertCount(1, (array) $controller->disableAction('pagekit/test-ext')['warnings']);

        // The manager lives as long as the request and can be asked for more
        // than one thing. A warning that stayed behind would be shown against a
        // package that never had it - and against every package after that.
        self::assertSame([], $controller->disableAction('pagekit/other-ext')['warnings']);
    }

    // ------------------------------------------------------------------
    // Removing a package
    // ------------------------------------------------------------------

    public function testRemovingAPackageWhoseOwnStepFailedShowsItOnTheStreamThePageReads(): void
    {
        $this->writeLifecycle($this->broken, 'uninstall', sprintf("throw new \\RuntimeException('%s');", self::THROWN));

        $stream = $this->streamed($this->controller(), 'pagekit/test-ext');

        // The reader takes the last line for the status and every line that
        // opens with the marker for a warning, so a warning that shared a line
        // with the progress output would not be one.
        self::assertSame('status=success', self::lastLine($stream));
        self::assertCount(1, self::warnings($stream));

        $warning = self::warnings($stream)[0];

        self::assertStringContainsString('uninstall', $warning);
        self::assertStringContainsString('Test Extension', $warning);
        self::assertStringContainsString('error log', $warning);

        // Nothing of what the hook threw, anywhere in what is streamed to the
        // browser - not on the warning line and not among the progress output.
        self::assertStringNotContainsString(self::THROWN, $stream);
        self::assertTrue($this->reported('uninstall hook', self::THROWN));

        // The package is out of the installation, and what the panel and the
        // site load was built from a configuration that named it.
        self::assertSame(1, $this->cache->rebuilds);
    }

    public function testAPackageCannotWriteTheEndOfTheStreamIntoItsOwnName(): void
    {
        // The title comes out of the package's own manifest and the warning is
        // built around it, so a package that puts the status marker on a line of
        // its own would be telling the page how its own removal went.
        $this->writeLifecycle($this->broken, 'uninstall', sprintf("throw new \\RuntimeException('%s');", self::THROWN));

        $stream = $this->streamed(
            $this->controller(['title' => "Test Extension\nstatus=error"]),
            'pagekit/test-ext',
        );

        self::assertSame('status=success', self::lastLine($stream));
        self::assertNotContains('status=error', explode("\n", $stream));

        // Folded onto the one line rather than dropped: what the package called
        // itself is still what the administrator is shown it as.
        self::assertCount(1, self::warnings($stream));
        self::assertStringContainsString('status=error', self::warnings($stream)[0]);
    }

    public function testARemovalThatDidNotFinishEndsAsAnErrorAndLeavesWhatTheInstallationLoadsAlone(): void
    {
        // Files that will not go: a folder held open, a permission the process
        // does not have. Everything else about the removal is done by then, and
        // the administrator has to hear that this half is not.
        $stream = $this->streamed($this->controller(files: new AFolderThatWillNotGo()), 'pagekit/test-ext');

        self::assertSame('status=error', self::lastLine($stream));

        // The clear comes after the removal, so an operation that raised is one
        // that never asked for it. Rebuilding the cache here would be rebuilding
        // it from a configuration the failure left half-written.
        self::assertSame(0, $this->cache->rebuilds);
    }

    // ------------------------------------------------------------------
    // What reads all this
    // ------------------------------------------------------------------

    public function testTheStreamIsTakenApartByTheMarkersItIsWrittenWith(): void
    {
        $this->writeLifecycle($this->broken, 'uninstall', sprintf("throw new \\RuntimeException('%s');", self::THROWN));

        $lines = explode("\n", $this->streamed($this->controller(), 'pagekit/test-ext'));
        $reader = self::patternsThePageReadsWith();

        // The patterns are the shipped page's own, run against what a removal
        // actually wrote. Renaming a marker on one side of that leaves two
        // halves that are each correct and an administrator who is shown
        // neither the outcome nor the step that did not finish.
        self::assertSame(1, preg_match('/'.$reader['status'].'/', (string) end($lines), $status));
        self::assertSame('success', $status[1]);

        $warnings = array_values(array_filter(
            $lines,
            static fn (string $line): bool => preg_match('/'.$reader['warning'].'/', $line) === 1,
        ));

        self::assertCount(1, $warnings);
        self::assertStringContainsString('uninstall', $warnings[0]);
    }

    public function testTheKeyTheAnswerCarriesTheWarningsUnderIsTheOneThePageReads(): void
    {
        $answer = $this->controller()->disableAction('pagekit/other-ext');

        // Switching a package off answers in JSON rather than on a stream, and
        // the page reads the warnings out of it by name: it shows one
        // notification per warning instead of reloading, so a key renamed on
        // one side of this is a reload where a warning was due.
        self::assertArrayHasKey('warnings', $answer);
        self::assertStringContainsString(
            'data.warnings',
            (string) file_get_contents(self::installerPath().'/app/lib/package.js'),
            'The page reads the warnings out of the answer',
        );
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * The controller as the panel builds it, over a manager that streams the way
     * the request path streams.
     *
     * @param array<string, mixed> $overrides what the broken package's manifest says
     * @param Filesystem|null      $files     the filesystem as the test needs it to
     *                                        behave, where that is what is under test
     */
    private function controller(array $overrides = [], ?Filesystem $files = null): PackageController
    {
        $system = new Config();
        $system->set('packages.test-ext', '1.0.0');
        $system->set('packages.other-ext', '1.0.0');
        $system->set('extensions', ['test-ext', 'other-ext']);

        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($system);

        $broken = $this->package('test-ext', $this->broken, $overrides);
        $quiet = $this->package('other-ext', $this->quiet);

        $factory = new PackageFactory();
        $factory[$broken->getName()] = $broken;
        $factory[$quiet->getName()] = $quiet;

        $app = new Application();
        $app->set('config', $config);
        $app->set('package', $factory);
        $app->set('file', $files ?? new Filesystem());
        $app->set('log', $this->log);
        $app->set('path.temp', $this->workspace.'/temp');
        $app->set('path.cache', $this->workspace.'/cache');
        $app->set('path.vendor', $this->workspace.'/vendor');
        $app->set('path.artifact', $this->workspace.'/artifact');
        $app->set('path.packages', $this->workspace.'/packages');

        $url = $this->createMock(UrlProvider::class);

        return new PackageController(
            // No output of its own: the manager the request path builds writes
            // its progress to the stream the response is sent on, which is what
            // the warning lines have to arrive among.
            new PackageManager($app),
            $factory,
            $this->modules(),
            $url,
            new Request(),
            new PagekitResponse($url),
            $this->workspace,
            false,
            new Logger('test'),
        );
    }

    /**
     * What a removal writes to the browser, as the page receives it.
     */
    private function streamed(PackageController $controller, string $name): string
    {
        $response = $controller->uninstallAction($name);

        ob_start();

        try {
            $response->sendContent();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * The modules of the installation the controller runs in: the cache it
     * clears, and the module a package has to be loaded as to be switched off.
     */
    private function modules(): ModuleManager
    {
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('get')->willReturnCallback(fn (string $name): mixed => match ($name) {
            'system/cache' => $this->cache,
            'test-ext', 'other-ext' => new Module([
                'name' => $name,
                'path' => $this->workspace,
                'config' => [],
            ]),
            default => null,
        });

        return $modules;
    }

    /**
     * The package as the factory reads it out of a manifest on disk.
     *
     * @param array<string, mixed> $overrides
     */
    private function package(string $module, string $tree, array $overrides = []): Package
    {
        return new Package(array_replace([
            'name' => 'pagekit/'.$module,
            'type' => 'pagekit-extension',
            'module' => $module,
            'title' => 'Test Extension',
            'version' => '1.0.0',
            'path' => $tree,
            'extra' => ['scripts' => 'scripts.php'],
        ], $overrides));
    }

    /**
     * The package's lifecycle file, implementing the one hook a test drives.
     */
    private function writeLifecycle(string $tree, string $hook, string $body): void
    {
        file_put_contents(
            $tree.'/scripts.php',
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
     * Whether what the answer withheld reached the log, with the throwable that
     * carries the rest of it.
     */
    private function reported(string $context, string $thrown): bool
    {
        foreach ($this->log->records as $record) {
            if (str_contains($record['message'], $context) && str_contains($record['message'], $thrown)) {
                return ($record['context']['exception'] ?? null) instanceof \Throwable;
            }
        }

        return false;
    }

    /**
     * What the page reads the outcome of the operation off.
     */
    private static function lastLine(string $stream): string
    {
        $lines = explode("\n", $stream);

        return (string) end($lines);
    }

    /**
     * The patterns the page takes a streamed removal apart with, read off the
     * page itself.
     *
     * @return array<string, string>
     */
    private static function patternsThePageReadsWith(): array
    {
        $reader = (string) file_get_contents(self::installerPath().'/app/lib/output.js');
        $patterns = [];

        foreach (['status', 'warning'] as $marker) {
            self::assertSame(
                1,
                preg_match(sprintf('/\.match\(\/(\^%s=[^\/]+)\//', $marker), $reader, $match),
                sprintf('The page picks the "%s" lines out of the stream', $marker),
            );

            $patterns[$marker] = $match[1];
        }

        return $patterns;
    }

    private static function installerPath(): string
    {
        return strtr(dirname(__DIR__, 3), '\\', '/').'/app/installer';
    }

    /**
     * The steps that did not finish, as the page picks them out of the stream.
     *
     * @return array<int, string>
     */
    private static function warnings(string $stream): array
    {
        $warnings = [];

        foreach (explode("\n", $stream) as $line) {
            if (preg_match('/^warning=(.+)$/', $line, $match) === 1) {
                $warnings[] = $match[1];
            }
        }

        return $warnings;
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
            $this->removeTree($path.'/'.$entry);
        }

        rmdir($path);
    }
}

/**
 * What the installation had cached, and whether an operation asked for it to be
 * rebuilt.
 *
 * A recorder rather than a mock: the operation under test runs inside a catch for
 * anything it raises, and a mock reporting a violated expectation would throw
 * where that catch reads it as the operation's own failure.
 */
final class RecordedCacheRebuilds
{
    public int $rebuilds = 0;

    /**
     * @param array<string, mixed> $options
     */
    public function clearCache(array $options = []): void
    {
        $this->rebuilds += 1;
    }
}

/**
 * What was reported, with the context that carries the throwable.
 */
final class HookWarningLog extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}

/**
 * A package tree nothing can take off the disk: a file held open, a permission
 * the process does not have, a mount that has gone read-only.
 */
final class AFolderThatWillNotGo extends Filesystem
{
    /**
     * @param string|array<int, string> $files
     */
    public function delete($files): bool
    {
        return false;
    }
}
