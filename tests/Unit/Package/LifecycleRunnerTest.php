<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Pagekit\Installer\Package\Lifecycle\LifecycleRunner;
use Pagekit\Installer\Package\Lifecycle\MigrationSet;
use Pagekit\Installer\Package\Lifecycle\PackageLifecycle;
use Pagekit\Installer\Package\Lifecycle\PackageLifecycleInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * What a package gets to run when it is installed, switched on or off, or
 * removed - and what happens when the file it declares does not deliver it.
 *
 * A package names a lifecycle file in its manifest and the runner is what
 * stands between that file and the operations asking things of it. Two
 * properties are what those operations depend on.
 *
 * A package that declares no file, or names one that is not on disk, has to be
 * installable, enable-able and removable all the same: most packages have
 * nothing to say about their own lifecycle, and the system's own file is read
 * from a path that need not exist in every installation. A file that does
 * deliver something else, on the other hand, is a fault to report rather than
 * an absence to work around - the package said it had hooks, so running none of
 * them quietly is how an extension ends up installed with its schema never
 * created.
 *
 * Updates are a schedule rather than a list. They are keyed by the version that
 * introduces them, run in version order, and only where the installation is
 * older than the key, so what an installation has already passed is never run
 * again.
 */
final class LifecycleRunnerTest extends TestCase
{
    private string $workspace;

    /**
     * The lifecycle file a package ships, written per test.
     */
    private string $file;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_lifecycle_runner_' . getmypid() . '_' . uniqid();
        $this->file = $this->workspace . '/scripts.php';

        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    // ------------------------------------------------------------------
    // A package with no lifecycle of its own
    // ------------------------------------------------------------------

    public function testAPackageThatDeclaresNoFileRunsEveryHookAsANoOp(): void
    {
        $app = $this->container();
        $runner = new LifecycleRunner(null, '1.0.0', $app);

        $runner->install();
        $runner->enable();
        $runner->disable();
        $runner->uninstall();
        $runner->update();

        // Most packages are only a module directory and a manifest. Every
        // operation has to run through their hooks without a file to read, and
        // without reaching for anything in the container on their behalf.
        self::assertFalse($runner->hasUpdates());
        self::assertSame([], $app->requested);
    }

    public function testAFileThatIsNotOnDiskRunsEveryHookAsANoOp(): void
    {
        // The system's own lifecycle is read from a path assembled at runtime,
        // and an installation whose layout does not have that file is not a
        // broken installation - it is one with no updates to run.
        $app = $this->container();
        $runner = new LifecycleRunner($this->workspace . '/absent.php', '1.0.0', $app);

        $runner->install();
        $runner->enable();
        $runner->disable();
        $runner->uninstall();
        $runner->update();

        self::assertFalse($runner->hasUpdates());
        self::assertSame([], $app->requested);
    }

    public function testAPackageThatOverridesNothingRunsThroughEveryHook(): void
    {
        $this->write(<<<'PHP'
            <?php

            declare(strict_types=1);

            use Pagekit\Installer\Package\Lifecycle\PackageLifecycle;

            return new class () extends PackageLifecycle {};
            PHP);

        $app = $this->container();
        $runner = new LifecycleRunner($this->file, '1.0.0', $app);

        $runner->install();
        $runner->enable();
        $runner->disable();
        $runner->uninstall();
        $runner->update();

        // A package declaring a lifecycle is not a package declaring every
        // hook: what it leaves alone stays a no-op, so the interface can gain a
        // hook without the packages that ignore it having to grow a method.
        self::assertFalse($runner->hasUpdates());
        self::assertSame([], $app->requested);
    }

    // ------------------------------------------------------------------
    // A file that does not deliver a lifecycle
    // ------------------------------------------------------------------

    public function testAFileReturningAnArrayOfHooksIsReportedAsAFault(): void
    {
        // An array of closures keyed by hook name is the shape a lifecycle file
        // must no longer have: nothing declares the keys, so a name nobody
        // recognises reads as a package with nothing to do rather than as the
        // typo it is.
        $this->write(<<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'install' => function ($app) {},
                'enable' => function ($app) {},
            ];
            PHP);

        $thrown = $this->failureOf(fn (LifecycleRunner $runner) => $runner->install());

        self::assertStringContainsString($this->file, $thrown->getMessage());
        self::assertStringContainsString(PackageLifecycleInterface::class, $thrown->getMessage());
        self::assertStringContainsString('array', $thrown->getMessage(), 'The report has to name what the file did deliver');
    }

    public function testAFileReturningNoLifecycleAtAllIsReportedAsAFault(): void
    {
        // A file that only declares things and returns nothing is the mistake a
        // package makes when its class file was meant to be required and
        // handed back.
        $this->write(<<<'PHP'
            <?php

            declare(strict_types=1);

            $unused = 'this file returns nothing';
            PHP);

        $thrown = $this->failureOf(fn (LifecycleRunner $runner) => $runner->install());

        self::assertStringContainsString($this->file, $thrown->getMessage());
        self::assertStringContainsString(PackageLifecycleInterface::class, $thrown->getMessage());
    }

    public function testTheFaultIsReportedFromWhicheverQuestionIsAskedFirst(): void
    {
        $this->write(<<<'PHP'
            <?php

            declare(strict_types=1);

            return 'not a lifecycle';
            PHP);

        // The file is read on the first question asked of it, so every entry
        // point is a place the fault can surface - which is why the callers
        // that only ask whether there is anything to run guard that call too.
        foreach ([
            'install' => fn (LifecycleRunner $runner) => $runner->install(),
            'enable' => fn (LifecycleRunner $runner) => $runner->enable(),
            'disable' => fn (LifecycleRunner $runner) => $runner->disable(),
            'uninstall' => fn (LifecycleRunner $runner) => $runner->uninstall(),
            'update' => fn (LifecycleRunner $runner) => $runner->update(),
            'hasUpdates' => fn (LifecycleRunner $runner) => $runner->hasUpdates(),
        ] as $entry => $call) {
            self::assertStringContainsString(
                'must return',
                $this->failureOf($call)->getMessage(),
                sprintf('%s() must report a lifecycle file that does not deliver one', $entry),
            );
        }
    }

    public function testTheSameRunnerKeepsReportingTheFaultInsteadOfSettlingForNoHooks(): void
    {
        $this->write(<<<'PHP'
            <?php

            declare(strict_types=1);

            return 'not a lifecycle';
            PHP);

        $runner = new LifecycleRunner($this->file, '1.0.0', $this->container());

        // A package operation calls several hooks. A runner that gave up after
        // the first report would let the rest of them pass as no-ops and finish
        // the operation on a package it already knows is broken.
        self::assertStringContainsString('must return', $this->failureOf(fn (LifecycleRunner $r) => $r->install(), $runner)->getMessage());
        self::assertStringContainsString('must return', $this->failureOf(fn (LifecycleRunner $r) => $r->enable(), $runner)->getMessage());
    }

    // ------------------------------------------------------------------
    // Running the hooks
    // ------------------------------------------------------------------

    public function testEachHookRunsTheMethodOfThatNameAgainstTheGivenContainer(): void
    {
        $this->writeLifecycle();

        $app = $this->container('installer-container');
        $runner = new LifecycleRunner($this->file, '1.0.0', $app);

        $runner->uninstall();
        $runner->install();
        $runner->enable();
        $runner->disable();

        // Which method a hook runs is the whole contract, and the container it
        // is handed is the one the operation is running in: a package is
        // installed from the web installer, the admin panel and the console,
        // and those are not the same container.
        self::assertSame(
            [
                'uninstall 1 installer-container',
                'install 2 installer-container',
                'enable 3 installer-container',
                'disable 4 installer-container',
            ],
            $this->calls(),
        );
    }

    public function testOneOperationSeesOneLifecycleHoweverManyHooksItCalls(): void
    {
        $this->writeLifecycle('2.0.0');

        $runner = new LifecycleRunner($this->file, '1.0.0', $this->container());

        $runner->hasUpdates();
        $runner->update();
        $runner->enable();

        // The counter comes off the lifecycle object, so it counts up only for
        // as long as one object is serving the operation. Reading the file per
        // hook would hand each one a fresh object and lose whatever the package
        // put aside between them.
        self::assertSame(['update 2.0.0 1 container', 'enable 2 container'], $this->calls());
        self::assertSame(1, $this->reads(), 'The file is the package\'s code: reading it again per hook runs it again');
    }

    public function testTheNextOperationGetsALifecycleOfItsOwn(): void
    {
        $this->writeLifecycle();

        (new LifecycleRunner($this->file, '1.0.0', $this->container()))->enable();
        (new LifecycleRunner($this->file, '1.0.0', $this->container()))->disable();

        // Enabling a package and disabling it later are separate operations,
        // possibly in separate requests. Neither may see what the other left on
        // the lifecycle object.
        self::assertSame(['enable 1 container', 'disable 1 container'], $this->calls());
        self::assertSame(2, $this->reads());
    }

    // ------------------------------------------------------------------
    // Updates as a schedule
    // ------------------------------------------------------------------

    public function testOnlyTheUpdatesNewerThanTheInstalledVersionRun(): void
    {
        $this->writeLifecycle('0.9.0', '1.0.0', '1.5.0', '2.0.0');

        $runner = new LifecycleRunner($this->file, '1.0.0', $this->container());

        self::assertTrue($runner->hasUpdates());

        $runner->update();

        // The installation is at 1.0.0: what got it there has already run, and
        // running it again is how a data change that is not idempotent doubles
        // whatever it did the first time.
        self::assertSame(['update 1.5.0 1 container', 'update 2.0.0 2 container'], $this->calls());
    }

    public function testUpdatesRunOldestFirstWhateverOrderTheyWereDeclaredIn(): void
    {
        $this->writeLifecycle('2.0.0', '1.10.0', '1.9.0');

        (new LifecycleRunner($this->file, '1.0.0', $this->container()))->update();

        // Each update is written against the state the one before it left, so
        // declaration order must not decide execution order. 1.10.0 after
        // 1.9.0 is where sorting the keys as text and comparing them as
        // versions part ways.
        self::assertSame(
            ['update 1.9.0 1 container', 'update 1.10.0 2 container', 'update 2.0.0 3 container'],
            $this->calls(),
        );
    }

    public function testAnInstallationWithNoRecordedVersionHasEveryUpdatePending(): void
    {
        $this->writeLifecycle('1.5.0', '2.0.0');

        $runner = new LifecycleRunner($this->file, null, $this->container());

        self::assertTrue($runner->hasUpdates());

        $runner->update();

        self::assertSame(['update 1.5.0 1 container', 'update 2.0.0 2 container'], $this->calls());
    }

    public function testThereIsNothingToRunOnceAnInstallationHasPassedEveryUpdate(): void
    {
        $this->writeLifecycle('0.9.0', '1.0.0');

        $runner = new LifecycleRunner($this->file, '2.0.0', $this->container());

        // Nothing to run is what lets the callers offer an update at all: it is
        // the answer that keeps the console quiet and sends the admin panel
        // past the update screen.
        self::assertFalse($runner->hasUpdates());

        $runner->update();

        self::assertSame([], $this->calls());
    }

    public function testAnUpdateKeyedByABareVersionNumberIsScheduledLikeAnyOther(): void
    {
        // PHP turns a key like '2' into an integer on the way into the array,
        // so a package writing its versions without dots hands over keys that
        // are not strings at all.
        $this->writeLifecycle('2', '3');

        $runner = new LifecycleRunner($this->file, '2', $this->container());

        self::assertTrue($runner->hasUpdates());

        $runner->update();

        self::assertSame(['update 3 1 container'], $this->calls());
    }

    // ------------------------------------------------------------------
    // What a package declares about its migrations
    // ------------------------------------------------------------------

    public function testAPackageDeclaresWhereItsMigrationsLive(): void
    {
        $lifecycle = new class () extends PackageLifecycle {
            public function migrations(): ?MigrationSet
            {
                return new MigrationSet('Pagekit\Blog\Migrations', '/packages/pagekit/blog/src/Migrations');
            }
        };

        $set = $lifecycle->migrations();

        // A namespace and a directory are all it takes to run a package's
        // migrations, which is why declaring them is enough and the package
        // does not have to execute them itself.
        self::assertInstanceOf(MigrationSet::class, $set);
        self::assertSame('Pagekit\Blog\Migrations', $set->namespace);
        self::assertSame('/packages/pagekit/blog/src/Migrations', $set->path);
    }

    public function testAPackageWithNoSchemaOfItsOwnDeclaresNoMigrations(): void
    {
        // Themes and extensions that only add views or routes have no schema,
        // and saying so has to be the default rather than something they opt in
        // to.
        self::assertNull((new class () extends PackageLifecycle {})->migrations());
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * The failure a call on a runner reports, over the current lifecycle file.
     *
     * A failed assertion is itself a RuntimeException, so the call is caught
     * here and asserted on afterwards rather than inside a catch that would
     * swallow the report of it.
     *
     * @param callable(LifecycleRunner): mixed $call
     * @param LifecycleRunner|null             $runner the runner to call, where the same one has to answer twice
     */
    private function failureOf(callable $call, ?LifecycleRunner $runner = null): \RuntimeException
    {
        try {
            $call($runner ?? new LifecycleRunner($this->file, '1.0.0', $this->container()));
        } catch (\RuntimeException $e) {
            return $e;
        }

        self::fail('A lifecycle file that does not deliver a lifecycle must be reported');
    }

    private function container(string $token = 'container'): RecordingContainer
    {
        return new RecordingContainer($token);
    }

    /**
     * A package's lifecycle file, whose every hook notes that it ran: which
     * hook, how many calls the lifecycle object has served, and which container
     * it was handed.
     *
     * @param string ...$updateVersions the versions the package declares an update for, in declaration order
     */
    private function writeLifecycle(string ...$updateVersions): void
    {
        $updates = implode("\n", array_map(
            static fn (string $version): string => sprintf(
                "            '%s' => function (ContainerInterface \$app): void {\n"
                . "                \$this->note('update %s', \$app);\n"
                . '            },',
                $version,
                $version,
            ),
            $updateVersions,
        ));

        $this->write(str_replace('{UPDATES}', $updates, <<<'PHP'
            <?php

            declare(strict_types=1);

            use Pagekit\Installer\Package\Lifecycle\PackageLifecycle;
            use Psr\Container\ContainerInterface;

            // One line per evaluation, which is how often the file was read.
            file_put_contents(__DIR__ . '/reads.log', "read\n", FILE_APPEND);

            return new class () extends PackageLifecycle {
                private int $calls = 0;

                public function install(ContainerInterface $app): void
                {
                    $this->note('install', $app);
                }

                public function enable(ContainerInterface $app): void
                {
                    $this->note('enable', $app);
                }

                public function disable(ContainerInterface $app): void
                {
                    $this->note('disable', $app);
                }

                public function uninstall(ContainerInterface $app): void
                {
                    $this->note('uninstall', $app);
                }

                public function updates(): array
                {
                    return [
            {UPDATES}
                    ];
                }

                private function note(string $hook, ContainerInterface $app): void
                {
                    file_put_contents(
                        __DIR__ . '/calls.log',
                        sprintf("%s %d %s\n", $hook, ++$this->calls, $app->get('token')),
                        FILE_APPEND
                    );
                }
            };
            PHP));
    }

    private function write(string $php): void
    {
        file_put_contents($this->file, $php);
    }

    /**
     * What the lifecycle noted, in the order it ran.
     *
     * @return array<int, string>
     */
    private function calls(): array
    {
        $log = $this->workspace . '/calls.log';

        if (!is_file($log)) {
            return [];
        }

        return explode("\n", trim((string) file_get_contents($log)));
    }

    /**
     * How many times the lifecycle file was read.
     */
    private function reads(): int
    {
        $log = $this->workspace . '/reads.log';

        if (!is_file($log)) {
            return 0;
        }

        return count(explode("\n", trim((string) file_get_contents($log))));
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
 * A container that answers a token naming itself and records what is asked of
 * it, so a hook that never ran shows as a container nothing was asked of.
 */
final class RecordingContainer implements ContainerInterface
{
    /** @var array<int, string> */
    public array $requested = [];

    public function __construct(private readonly string $token)
    {
    }

    public function get(string $id): mixed
    {
        $this->requested[] = $id;

        if ($id !== 'token') {
            throw new class (sprintf('Service "%s" is not registered.', $id)) extends \RuntimeException implements NotFoundExceptionInterface {
            };
        }

        return $this->token;
    }

    public function has(string $id): bool
    {
        return $id === 'token';
    }
}
