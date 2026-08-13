<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Module;

use Pagekit\Application;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use PHPUnit\Framework\TestCase;

/**
 * Discovery finds out what a module declares by executing its file, and it does
 * that for every package on disk on every request - enabled or not, before a
 * single module has been loaded. A package throwing at top level therefore threw
 * out of the boot itself and cost the whole site, including the admin panel
 * needed to disable it.
 *
 * The isolation asserted here ends that: the file that fails registers nothing
 * and its throwable is kept for the boot to report, every other package
 * registers as it did before, and a module that is merely broken at runtime
 * still registers - a failure that reaches the load window can be attributed to
 * a module name, one in here cannot.
 */
final class ModuleRegistrationTest extends TestCase
{
    private ?string $workspace = null;

    protected function tearDown(): void
    {
        if ($this->workspace === null) {
            return;
        }

        foreach (glob($this->workspace.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->workspace);
        $this->workspace = null;
    }

    public function testAPackageThatThrowsWhileBeingExecutedCostsItsOwnModuleAndNoOther(): void
    {
        $manager = $this->manager();

        // The broken package is swept first, so an isolation that ended the sweep
        // rather than the file would show as the intact packages behind it
        // disappearing.
        $manager->register($this->fixtures('throwing', 'healthy', 'second'));

        $failures = $manager->getRegistrationFailures();

        self::assertSame([$this->fixture('throwing')], array_keys($failures));

        // The throwable is handed on untouched: its trace is all an administrator
        // has to find the fault in a package they did not write.
        $failure = $failures[$this->fixture('throwing')];

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('The module file could not be executed', $failure->getMessage());
        self::assertSame($this->fixture('throwing'), $failure->getFile());

        // What was registered is only observable through what can be loaded.
        $manager->load(['fixture-healthy', 'fixture-second']);

        self::assertInstanceOf(Module::class, $manager->get('fixture-healthy'));
        self::assertInstanceOf(Module::class, $manager->get('fixture-second'));
    }

    public function testAPackageWhoseDependencyIsMissingIsIsolatedLikeAnyOtherFailure(): void
    {
        $manager = $this->manager();

        $manager->register($this->fixtures('missing-class', 'healthy'));

        $failure = $manager->getRegistrationFailures()[$this->fixture('missing-class')] ?? null;

        // An extension left behind by an uninstalled dependency is the everyday
        // case, and PHP raises an Error for it. Isolating exceptions only would
        // still take the site down over it.
        self::assertInstanceOf(\Error::class, $failure);
        self::assertStringContainsString('Vendor\NotInstalled\Extension', $failure->getMessage());

        $manager->load('fixture-healthy');

        self::assertInstanceOf(Module::class, $manager->get('fixture-healthy'));
    }

    public function testAPackageThatCannotBeParsedIsIsolatedInsteadOfEndingTheRequest(): void
    {
        $manager = $this->manager();

        // A half-written file is what an interrupted upload or a bad merge leaves
        // behind, and PHP raises a ParseError over it, which is a throwable like
        // any other. The file is written here rather than committed: a broken one
        // in the tree would be a syntax error in the repository.
        $broken = $this->unparsableModule();

        $manager->register([$broken, $this->fixture('healthy')]);

        self::assertInstanceOf(\ParseError::class, $manager->getRegistrationFailures()[$broken] ?? null);

        $manager->load('fixture-healthy');

        self::assertInstanceOf(Module::class, $manager->get('fixture-healthy'));
    }

    public function testAPackageThatFailedToRegisterIsUndefinedRatherThanHalfKnown(): void
    {
        $manager = $this->manager();

        $manager->register($this->fixtures('throwing'));

        // The site configuration still lists the extension under the name its
        // package declares - a name discovery could only have read from the file
        // that failed. Nothing was registered under it, so the boot runs into the
        // failure a second time, in the window that knows the module by name.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Undefined module: fixture-throwing');

        $manager->load('fixture-throwing');
    }

    public function testAFileDeclaringNoModuleIsPassedOverWithoutCountingAsAFailure(): void
    {
        $manager = $this->manager();

        $manager->register($this->fixtures('unnamed', 'no-return', 'healthy'));

        // Neither file failed. Reporting them as broken packages would put a line
        // in the log and a notice in the panel for something that is working as
        // intended.
        self::assertSame([], $manager->getRegistrationFailures());

        $manager->load('fixture-healthy');

        self::assertInstanceOf(Module::class, $manager->get('fixture-healthy'));
    }

    public function testAPackageThatOnlyFailsWhenItIsLoadedStillRegisters(): void
    {
        $manager = $this->manager();

        $manager->register($this->fixtures('main-throwing'));

        self::assertSame([], $manager->getRegistrationFailures());

        // Only the execution of the file is isolated here. A failure that happens
        // once the module is loaded already has a module name to be reported
        // under, so it stays in the window that can name the extension an
        // administrator has to act on.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The module could not be loaded');

        $manager->load('fixture-main-throwing');
    }

    public function testAPackageBrokenBelowAnotherPackagesDeclarationIsIsolatedToo(): void
    {
        $manager = $this->manager();

        // This is how the system's own modules are found, so the failure has to
        // be caught one level below the sweep as well - and the modules beside it
        // have to survive, or the panel goes down with them.
        $manager->register($this->fixtures('host'));

        self::assertSame([$this->modules().'/host/modules/broken/index.php'], array_keys($manager->getRegistrationFailures()));

        $manager->load(['fixture-host', 'fixture-host-child']);

        self::assertInstanceOf(Module::class, $manager->get('fixture-host'));
        self::assertInstanceOf(Module::class, $manager->get('fixture-host-child'));
    }

    public function testAnInstallationWhereEveryPackageRanReportsNothing(): void
    {
        $manager = $this->manager();

        $manager->register($this->fixtures('healthy', 'second'));

        // Every boot reads this. An installation with nothing broken has to hand
        // back nothing at all.
        self::assertSame([], $manager->getRegistrationFailures());
    }

    public function testTheFailuresOfEveryRegisteredPathAreHandedOverTogether(): void
    {
        $manager = $this->manager();

        // The boot registers packages, modules, the installer and the system in
        // separate sweeps and asks for the failures once, after the last of them.
        $manager->register($this->fixtures('throwing'));
        $manager->register($this->fixtures('missing-class'));

        self::assertSame(
            [$this->fixture('throwing'), $this->fixture('missing-class')],
            array_keys($manager->getRegistrationFailures())
        );
    }

    /**
     * A manager as the boot builds it, against an application that has nothing
     * registered in it yet.
     */
    private function manager(): ModuleManager
    {
        return new ModuleManager(new Application());
    }

    /**
     * Writes a module file that cannot be compiled. It has to be produced at
     * runtime, so no unparsable PHP is committed to the tree.
     */
    private function unparsableModule(): string
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_module_registration_'.getmypid().'_'.uniqid();

        mkdir($this->workspace, 0755, true);

        $file = $this->workspace.'/index.php';
        file_put_contents($file, "<?php\n\nreturn ['name' => 'fixture-unparsable'\n");

        return $file;
    }

    /**
     * @return array<int, string>
     */
    private function fixtures(string ...$names): array
    {
        return array_map($this->fixture(...), $names);
    }

    private function fixture(string $name): string
    {
        return $this->modules().'/'.$name.'/index.php';
    }

    private function modules(): string
    {
        return strtr(dirname(__DIR__, 2), '\\', '/').'/fixtures/modules';
    }
}
