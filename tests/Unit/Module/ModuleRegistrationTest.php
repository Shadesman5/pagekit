<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Module;

use Pagekit\Application;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Module\ModuleManifest;
use Pagekit\Module\ModuleManifestException;
use PHPUnit\Framework\TestCase;

final class ModuleRegistrationTest extends TestCase
{
    private ?string $workspace = null;

    protected function tearDown(): void
    {
        if ($this->workspace === null) {
            return;
        }

        $this->removeTree($this->workspace);
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

        $failure = $failures[$this->fixture('throwing')];

        self::assertInstanceOf(ModuleManifestException::class, $failure);
        self::assertSame('Syntax error', $failure->getMessage());

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

        self::assertInstanceOf(ModuleManifestException::class, $failure);
        self::assertStringContainsString('"require"', $failure->getMessage());

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

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertTrue($manager->isRegistered('fixture-unparsable'));

        $manager->load('fixture-healthy');

        self::assertInstanceOf(Module::class, $manager->get('fixture-healthy'));

        try {
            $manager->load('fixture-unparsable');
            self::fail('A parse error in the entry point has to leave load().');
        } catch (\ParseError) {
            self::assertNull($manager->get('fixture-unparsable'));
        }

        self::assertSame([], $manager->getRegistrationFailures());
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

        self::assertSame([$this->modules().'/host/modules/broken/module.json'], array_keys($manager->getRegistrationFailures()));

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

    public function testRegisterDoesNotExecuteTheEntryPoint(): void
    {
        $manager = $this->manager();
        $marker = $this->workspace().'/marker/ran.marker';
        $file = $this->plant('marker', "{\n    \"name\": \"fixture-marker\"\n}\n", <<<'PHP'
            <?php

            declare(strict_types=1);

            file_put_contents(__DIR__.'/ran.marker', '1');

            throw new \RuntimeException('The entry point ran');

            PHP);

        $manager->register([$file]);

        // The marker is written by the entry point, which register() must not include.
        self::assertFileDoesNotExist($marker);
        self::assertSame([], $manager->getRegistrationFailures());
        self::assertTrue($manager->isRegistered('fixture-marker'));

        try {
            $manager->load('fixture-marker');
            self::fail('The entry point has to run when the module is loaded.');
        } catch (\RuntimeException $exception) {
            self::assertSame(\RuntimeException::class, $exception::class);
            self::assertSame('The entry point ran', $exception->getMessage());
        }

        self::assertFileExists($marker);
        self::assertNull($manager->get('fixture-marker'));
        self::assertTrue($manager->isRegistered('fixture-marker'));
        self::assertSame([], $manager->getRegistrationFailures());
    }

    public function testAHiddenSegmentIsNotRegistered(): void
    {
        $manager = $this->manager();
        $hidden = $this->plant('pagekit/.demo-deadbeef', "{\"name\":\"hidden-demo\"}\n");
        $visible = $this->plant('pagekit/demo', "{\"name\":\"visible-demo\"}\n", "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");
        $listed = array_map(
            static fn (string $path): string => strtr($path, '\\', '/'),
            glob($hidden) ?: [],
        );

        // The path is a file glob can see; discovery itself has to ignore the hidden segment.
        self::assertCount(1, $listed);
        self::assertStringEndsWith('/pagekit/.demo-deadbeef/'.ModuleManifest::FILE, $listed[0]);

        $manager->register([$hidden, $visible]);

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertFalse($manager->isRegistered('hidden-demo'));
        self::assertTrue($manager->isRegistered('visible-demo'));

        $manager->load('visible-demo');

        self::assertInstanceOf(Module::class, $manager->get('visible-demo'));
        self::assertNull($manager->get('hidden-demo'));
    }

    public function testAManifestLargerThanTheLimitIsRefusedBeforeItIsDecoded(): void
    {
        $manager = $this->manager();
        $over = $this->plant('over-size', $this->jsonOfSize('over-size', ModuleManifest::MAX_BYTES + 1));
        $exact = $this->plant('exact-size', $this->jsonOfSize('exact-size', ModuleManifest::MAX_BYTES));
        $neighbor = $this->plant('neighbor', "{\"name\":\"neighbor\"}\n", "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");

        self::assertSame(ModuleManifest::MAX_BYTES + 1, filesize($over));
        self::assertSame(ModuleManifest::MAX_BYTES, filesize($exact));

        $manager->register([$over, $exact, $neighbor]);

        $failure = $manager->getRegistrationFailures()[$over] ?? null;

        self::assertInstanceOf(ModuleManifestException::class, $failure);
        self::assertSame(
            sprintf('The module manifest exceeds %d bytes.', ModuleManifest::MAX_BYTES),
            $failure->getMessage(),
        );
        self::assertNull($failure->getPrevious());
        self::assertSame([$over], array_keys($manager->getRegistrationFailures()));
        self::assertFalse($manager->isRegistered('over-size'));
        self::assertTrue($manager->isRegistered('exact-size'));
        self::assertTrue($manager->isRegistered('neighbor'));

        $manager->load(['exact-size', 'neighbor']);

        self::assertInstanceOf(Module::class, $manager->get('exact-size'));
        self::assertInstanceOf(Module::class, $manager->get('neighbor'));
    }

    public function testADocumentWithoutAModuleNameIsSkipped(): void
    {
        $manager = $this->manager();
        $empty = $this->plant('empty', "{}\n");
        $blank = $this->plant('blank', "{\"name\":\"\"}\n");

        $manager->register([$empty, $blank]);

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertFalse($manager->isRegistered(''));

        $numeric = $this->plant('numeric', "{\"name\":42}\n");

        $manager->register([$numeric]);

        $failure = $manager->getRegistrationFailures()[$numeric] ?? null;

        self::assertInstanceOf(ModuleManifestException::class, $failure);
        self::assertStringContainsString('"name"', $failure->getMessage());
        self::assertSame([$numeric], array_keys($manager->getRegistrationFailures()));
        self::assertFalse($manager->isRegistered('42'));
        self::assertFalse($manager->isRegistered(''));
    }

    public function testAWrongFieldTypeIsRecordedAndTheNextModuleStillRegisters(): void
    {
        $manager = $this->manager();
        $documents = [
            'not-json' => ["{\n", null],
            'bad-require' => ["{\"name\":\"bad-require\",\"require\":{}}\n", 'require'],
            'bad-include' => ["{\"name\":\"bad-include\",\"include\":1}\n", 'include'],
            'bad-autoload' => ["{\"name\":\"bad-autoload\",\"autoload\":[]}\n", 'autoload'],
            'bad-nodes' => ["{\"name\":\"bad-nodes\",\"nodes\":[]}\n", 'nodes'],
        ];
        $paths = [];

        foreach ($documents as $directory => [$json]) {
            $paths[$directory] = $this->plant($directory, $json);
        }

        $next = $this->plant('fixture-next', "{\"name\":\"fixture-next\"}\n", "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");

        $manager->register([...array_values($paths), $next]);

        self::assertSame(array_values($paths), array_keys($manager->getRegistrationFailures()));

        $syntax = $manager->getRegistrationFailures()[$paths['not-json']] ?? null;

        self::assertInstanceOf(ModuleManifestException::class, $syntax);
        self::assertSame('Syntax error', $syntax->getMessage());
        self::assertInstanceOf(\JsonException::class, $syntax->getPrevious());

        foreach (['bad-require' => 'require', 'bad-include' => 'include', 'bad-autoload' => 'autoload', 'bad-nodes' => 'nodes'] as $directory => $field) {
            $failure = $manager->getRegistrationFailures()[$paths[$directory]] ?? null;

            self::assertInstanceOf(ModuleManifestException::class, $failure);
            self::assertStringContainsString('"'.$field.'"', $failure->getMessage());
            self::assertFalse($manager->isRegistered($directory));
        }

        self::assertTrue($manager->isRegistered('fixture-next'));

        $manager->load('fixture-next');

        self::assertInstanceOf(Module::class, $manager->get('fixture-next'));
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

        file_put_contents($this->workspace.'/module.json', "{\n    \"name\": \"fixture-unparsable\"\n}\n");
        file_put_contents($this->workspace.'/index.php', "<?php\n\nreturn ['main' =>\n");

        return $this->workspace.'/module.json';
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
        return $this->modules().'/'.$name.'/module.json';
    }

    private function modules(): string
    {
        return strtr(dirname(__DIR__, 2), '\\', '/').'/fixtures/modules';
    }

    private function workspace(): string
    {
        if ($this->workspace !== null) {
            return $this->workspace;
        }

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_module_registration_'.getmypid().'_'.uniqid();

        if (!mkdir($this->workspace, 0755, true) && !is_dir($this->workspace)) {
            self::fail('The fixture workspace could not be created.');
        }

        return $this->workspace;
    }

    private function plant(string $directory, string $json, ?string $entry = null): string
    {
        $path = $this->workspace().'/'.$directory;

        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            self::fail('The fixture module directory could not be created.');
        }

        $file = $path.'/'.ModuleManifest::FILE;

        if (file_put_contents($file, $json) === false) {
            self::fail('The fixture manifest could not be written.');
        }

        if ($entry !== null && file_put_contents($path.'/'.ModuleManifest::ENTRY, $entry) === false) {
            self::fail('The fixture entry point could not be written.');
        }

        return $file;
    }

    private function jsonOfSize(string $name, int $bytes): string
    {
        $prefix = '{"name":"'.$name.'"';
        $suffix = '}';
        $spaces = $bytes - strlen($prefix) - strlen($suffix);

        self::assertGreaterThan(0, $spaces);

        $json = $prefix.str_repeat(' ', $spaces).$suffix;

        self::assertSame($bytes, strlen($json));

        return $json;
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
