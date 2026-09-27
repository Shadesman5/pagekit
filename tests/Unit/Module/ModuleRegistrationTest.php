<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Module;

use Pagekit\Application;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Module\ModuleManifest;
use Pagekit\Module\ModuleManifestException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    public function testAManifestWhoseSizeCannotBeReadIsRefused(): void
    {
        $exception = $this->readFailure('pkmanifest://nostat');

        self::assertSame('The module manifest could not be read.', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    public function testAManifestThatCannotBeOpenedIsRefused(): void
    {
        $exception = $this->readFailure('pkmanifest://closed');

        self::assertSame('The module manifest could not be read.', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    public function testAManifestReadPastTheClaimedSizeIsRefusedBeforeItIsDecoded(): void
    {
        $exception = $this->readFailure('pkmanifest://huge');

        self::assertSame(
            sprintf('The module manifest exceeds %d bytes.', ModuleManifest::MAX_BYTES),
            $exception->getMessage(),
        );
        self::assertNull($exception->getPrevious());
        self::assertStringNotContainsString('Syntax error', $exception->getMessage());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testADecodedNameThatIsNotAStringIsSkipped(): void
    {
        if (class_exists(ModuleManifest::class, false)) {
            self::fail('The manifest decoder is already loaded.');
        }

        // decode() refuses a non-string or blank name itself. This stand-in returns one so the sweep's skip is what leaves it unregistered.
        $this->installNonStringNameDecoder();

        $manager = $this->manager();
        $number = $this->plant('number', "{\"name\":\"counted\",\"probe\":\"number\"}\n");
        $blank = $this->plant('blank', "{\"name\":\"counted\",\"probe\":\"blank\"}\n");
        $neighbor = $this->plant('neighbor', "{\"name\":\"neighbor\"}\n");

        $manager->register([$number, $blank, $neighbor]);

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertFalse($manager->isRegistered('42'));
        self::assertFalse($manager->isRegistered(''));
        self::assertFalse($manager->isRegistered('counted'));
        self::assertTrue($manager->isRegistered('neighbor'));

        $manager->load('neighbor');

        self::assertInstanceOf(Module::class, $manager->get('neighbor'));
    }

    public function testTheLastCallerSuppliedManifestOwnsTheNameAndItsInclude(): void
    {
        $manager = $this->manager();
        $early = $this->plant('early', $this->manifest([
            'name' => 'system',
            'require' => ['from-early'],
            'include' => 'modules/*/module.json',
        ]));
        $this->plant('early/modules/only', $this->manifest([
            'name' => 'early-only',
            'require' => ['from-early-child'],
        ]));
        $other = $this->plant('other', $this->manifest([
            'name' => 'other',
            'include' => 'modules/*/module.json',
        ]));
        $this->plant('other/modules/system', $this->manifest([
            'name' => 'system',
            'require' => ['hijack'],
        ]));
        $this->plant('other/modules/view', $this->manifest([
            'name' => 'system/view',
            'require' => ['from-other'],
        ]));
        $later = $this->plant('later', $this->manifest([
            'name' => 'system',
            'require' => ['from-later'],
            'include' => 'modules/*/module.json',
        ]));
        $this->plant('later/modules/view', $this->manifest([
            'name' => 'system/view',
            'require' => ['from-later-child'],
            'include' => 'modules/*/module.json',
        ]));
        $this->plant('later/modules/view/modules/again', $this->manifest([
            'name' => 'system/view',
            'require' => ['from-nested'],
        ]));

        $manager->register([$early, $other, $later]);

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertTrue($manager->isRegistered('system'));
        self::assertTrue($manager->isRegistered('other'));
        self::assertTrue($manager->isRegistered('system/view'));
        // The replaced manifest's include is not followed.
        self::assertFalse($manager->isRegistered('early-only'));
        self::assertSame(['from-later'], $manager->requires('system'));
        // A free name in this include pass keeps the last manifest; the next pass does not replace it.
        self::assertSame(['from-later-child'], $manager->requires('system/view'));
    }

    public function testAPathThatLeavesTheBaseIsNotGlobbed(): void
    {
        $manager = $this->manager();
        $root = $this->workspace().'/root';
        $this->plant('x', $this->manifest(['name' => 'escaped']));
        $absolute = $this->plant('absolute', $this->manifest(['name' => 'absolute-leak']));
        $this->plant('root/good', $this->manifest(['name' => 'good']));

        $this->inDirectory($root, function () use ($manager, $root, $absolute): void {
            $parent = glob('../x/'.ModuleManifest::FILE, GLOB_NOSORT);
            $leaked = glob($absolute, GLOB_NOSORT);

            self::assertIsArray($parent);
            self::assertNotSame([], $parent);
            self::assertIsArray($leaked);
            self::assertNotSame([], $leaked);

            $manager->register([
                '../x/'.ModuleManifest::FILE,
                $absolute,
                "good/\0".ModuleManifest::FILE,
                'good/'.ModuleManifest::FILE,
            ], $root);
        });

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertTrue($manager->isRegistered('good'));
        self::assertFalse($manager->isRegistered('escaped'));
        self::assertFalse($manager->isRegistered('absolute-leak'));
    }

    public function testAnEmptyBaseDoesNotRegisterTheWorkingDirectory(): void
    {
        $manager = $this->manager();
        $directory = $this->workspace();
        $file = $directory.'/'.ModuleManifest::FILE;

        if (file_put_contents($file, $this->manifest(['name' => 'from-cwd'])) === false) {
            self::fail('The fixture manifest could not be written.');
        }

        $this->inDirectory($directory, function () use ($manager): void {
            $seen = glob(ModuleManifest::FILE, GLOB_NOSORT);

            self::assertIsArray($seen);
            self::assertNotSame([], $seen);

            $manager->register([ModuleManifest::FILE], '');
        });

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertFalse($manager->isRegistered('from-cwd'));
    }

    public function testAnIncludeThatLeavesTheModuleIsNotGlobbed(): void
    {
        $manager = $this->manager();
        $this->plant('x', $this->manifest(['name' => 'escaped']));
        $absolute = $this->plant('absolute', $this->manifest(['name' => 'absolute-leak']));
        $host = $this->plant('host', $this->manifest([
            'name' => 'host',
            'include' => [
                '../x/'.ModuleManifest::FILE,
                $absolute,
                "leak\0/".ModuleManifest::FILE,
            ],
        ]));
        $good = $this->plant('good', $this->manifest(['name' => 'good']));

        $this->inDirectory(dirname($host), function () use ($manager, $host, $good, $absolute): void {
            $parent = glob('../x/'.ModuleManifest::FILE, GLOB_NOSORT);
            $leaked = glob($absolute, GLOB_NOSORT);

            self::assertIsArray($parent);
            self::assertNotSame([], $parent);
            self::assertIsArray($leaked);
            self::assertNotSame([], $leaked);

            $manager->register([$host, $good]);
        });

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertTrue($manager->isRegistered('host'));
        self::assertTrue($manager->isRegistered('good'));
        self::assertFalse($manager->isRegistered('escaped'));
        self::assertFalse($manager->isRegistered('absolute-leak'));
    }

    public function testASymlinkOutsideTheModuleOccupiesNoName(): void
    {
        $manager = $this->manager();
        $outside = $this->plant('outside', $this->manifest([
            'name' => 'leaked',
            'require' => ['leaked'],
        ]));
        $real = $this->plant('host/modules/real', $this->manifest([
            'name' => 'real-child',
            'require' => ['kept'],
        ]));
        $host = $this->plant('host', $this->manifest([
            'name' => 'host',
            'include' => 'modules/*/module.json',
        ]));
        $link = dirname($host).'/modules/linked';

        if (!@symlink(dirname($outside), $link)) {
            self::markTestSkipped('symlink() is unavailable on this host');
        }

        $hostReal = realpath(dirname($host));
        $leakReal = realpath($link.'/'.ModuleManifest::FILE);
        $seen = glob(dirname($host).'/modules/*/'.ModuleManifest::FILE, GLOB_NOSORT);

        self::assertIsString($hostReal);
        self::assertIsString($leakReal);
        self::assertFalse(str_starts_with(
            strtr($leakReal, '\\', '/'),
            rtrim(strtr($hostReal, '\\', '/'), '/').'/',
        ));
        self::assertIsArray($seen);
        $paths = array_map(static fn (string $file): string => strtr($file, '\\', '/'), $seen);
        self::assertContains(strtr($link.'/'.ModuleManifest::FILE, '\\', '/'), $paths);
        self::assertContains(strtr($real, '\\', '/'), $paths);

        $manager->register([$host]);

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertTrue($manager->isRegistered('host'));
        self::assertTrue($manager->isRegistered('real-child'));
        self::assertSame(['kept'], $manager->requires('real-child'));
        self::assertFalse($manager->isRegistered('leaked'));
    }

    public function testAnIncludeListRegistersEachChild(): void
    {
        $manager = $this->manager();
        $host = $this->plant('host', $this->manifest([
            'name' => 'host',
            'include' => [
                'modules/*/'.ModuleManifest::FILE,
                'themes/*/'.ModuleManifest::FILE,
            ],
        ]));
        $this->plant('host/modules/view', $this->manifest([
            'name' => 'system/view',
            'require' => ['from-module'],
        ]));
        $this->plant('host/themes/one', $this->manifest([
            'name' => 'theme-one',
            'require' => ['from-theme'],
        ]));

        $manager->register([$host]);

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertTrue($manager->isRegistered('host'));
        self::assertSame(['from-module'], $manager->requires('system/view'));
        self::assertSame(['from-theme'], $manager->requires('theme-one'));
    }

    public function testAHostileIncludeDoesNotStopTheNextPattern(): void
    {
        $manager = $this->manager();
        $this->plant('x', $this->manifest(['name' => 'escaped']));
        $host = $this->plant('host', $this->manifest([
            'name' => 'host',
            'include' => [
                '../x/'.ModuleManifest::FILE,
                'modules/*/'.ModuleManifest::FILE,
            ],
        ]));
        $this->plant('host/modules/view', $this->manifest([
            'name' => 'system/view',
            'require' => ['kept'],
        ]));

        $this->inDirectory(dirname($host), function () use ($manager, $host): void {
            $parent = glob('../x/'.ModuleManifest::FILE, GLOB_NOSORT);

            self::assertIsArray($parent);
            self::assertNotSame([], $parent);

            // One manifest path is a string. A list is the other accepted shape.
            $manager->register($host);
        });

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertTrue($manager->isRegistered('host'));
        self::assertFalse($manager->isRegistered('escaped'));
        self::assertSame(['kept'], $manager->requires('system/view'));
    }

    public function testADirectoryPathIsNotAModule(): void
    {
        $manager = $this->manager();
        $good = $this->plant('good', $this->manifest(['name' => 'good', 'require' => ['kernel']]));
        $directory = dirname($good);
        $listed = array_map(
            static fn (string $path): string => strtr($path, '\\', '/'),
            glob($directory, GLOB_NOSORT) ?: [],
        );

        self::assertContains($directory, $listed);

        $manager->register([$directory, $good]);

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertSame(['kernel'], $manager->requires('good'));
    }

    public function testABrokenSymlinkIncludeOccupiesNoName(): void
    {
        $manager = $this->manager();
        $real = $this->plant('host/modules/real', $this->manifest([
            'name' => 'real-child',
            'require' => ['kept'],
        ]));
        $host = $this->plant('host', $this->manifest([
            'name' => 'host',
            'include' => 'modules/*/'.ModuleManifest::FILE,
        ]));
        $link = dirname($host).'/modules/broken/'.ModuleManifest::FILE;

        if (!is_dir(dirname($link)) && !mkdir(dirname($link), 0755, true) && !is_dir(dirname($link))) {
            self::fail('The dangling-link directory could not be created.');
        }

        if (!@symlink($this->workspace().'/missing-target', $link)) {
            self::markTestSkipped('symlink() is unavailable on this host');
        }

        $matched = array_map(
            static fn (string $path): string => strtr($path, '\\', '/'),
            glob(dirname($host).'/modules/*/'.ModuleManifest::FILE, GLOB_NOSORT) ?: [],
        );

        self::assertContains(strtr($link, '\\', '/'), $matched);
        self::assertContains(strtr($real, '\\', '/'), $matched);
        self::assertFalse(realpath($link));

        $manager->register([$host]);

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertTrue($manager->isRegistered('host'));
        self::assertTrue($manager->isRegistered('real-child'));
        self::assertSame(['kept'], $manager->requires('real-child'));
        self::assertFalse($manager->isRegistered('broken'));
    }

    public function testABackslashBeforeAHiddenSegmentIsNotRegistered(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('A backslash is a directory separator on this host.');
        }

        $manager = $this->manager();
        $hidden = $this->plant('pkg\\.hidden-demo', "{\"name\":\"hidden-demo\"}\n");
        $visible = $this->plant('pkg/visible', "{\"name\":\"visible-demo\"}\n");

        self::assertStringContainsString('\\', $hidden);
        self::assertFileExists($hidden);

        $manager->register([$hidden, $visible]);

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertFalse($manager->isRegistered('hidden-demo'));
        self::assertTrue($manager->isRegistered('visible-demo'));
    }

    public function testNodeTypesSkipABlankKeyAndAnIntegerKey(): void
    {
        $manager = $this->manager();
        $typed = $this->plant('typed', <<<'JSON'
            {
                "name": "typed",
                "nodes": {
                    "": {"label": "Blank"},
                    "0": {"label": "Zero"},
                    "post": {"label": "Post"}
                }
            }

            JSON);
        $plain = $this->plant('plain', "{\"name\":\"plain\"}\n");
        $empty = $this->plant('empty-nodes', "{\"name\":\"empty-nodes\",\"nodes\":{}}\n");

        $manager->register([$typed, $plain, $empty]);

        self::assertSame([], $manager->getRegistrationFailures());
        self::assertSame(['post'], $manager->nodeTypes('typed'));
        self::assertSame([], $manager->nodeTypes('plain'));
        self::assertSame([], $manager->nodeTypes('empty-nodes'));
        self::assertSame([], $manager->nodeTypes('missing'));

        $manager->load('typed');

        $loaded = $manager->get('typed');

        self::assertInstanceOf(Module::class, $loaded);
        self::assertSame([
            '' => ['label' => 'Blank'],
            0 => ['label' => 'Zero'],
            'post' => ['label' => 'Post'],
        ], $loaded->get('nodes'));
        self::assertSame(['post'], $manager->nodeTypes('typed'));
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

    /**
     * @param array<string, mixed> $fields
     */
    private function manifest(array $fields): string
    {
        return json_encode($fields, JSON_THROW_ON_ERROR)."\n";
    }

    private function inDirectory(string $directory, callable $run): void
    {
        $cwd = getcwd();

        if ($cwd === false || !chdir($directory)) {
            self::fail('The working directory could not be changed.');
        }

        try {
            $run();
        } finally {
            chdir($cwd);
        }
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

    private function readFailure(string $path): ModuleManifestException
    {
        $manager = $this->manager();
        $method = new \ReflectionMethod(ModuleManager::class, 'readManifest');

        try {
            self::assertTrue(stream_wrapper_register('pkmanifest', ManifestByteStream::class));
            $this->withoutStreamWarning(static function () use ($method, $manager, $path): void {
                $method->invoke($manager, $path);
            });
        } catch (ModuleManifestException $exception) {
            return $exception;
        } finally {
            if (in_array('pkmanifest', stream_get_wrappers(), true)) {
                stream_wrapper_unregister('pkmanifest');
            }
        }

        self::fail('The manifest has to be refused.');
    }

    /**
     * fopen and filesize warn on the failure the reader turns into an exception.
     */
    private function withoutStreamWarning(callable $run): void
    {
        $previous = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$previous): bool {
            if ($severity === E_WARNING && (str_contains($message, 'stat failed') || str_contains($message, 'Failed to open stream'))) {
                return true;
            }

            if (is_callable($previous)) {
                return (bool) $previous($severity, $message, $file, $line);
            }

            return false;
        });

        try {
            $run();
        } finally {
            restore_error_handler();
        }
    }

    private function installNonStringNameDecoder(): void
    {
        eval(<<<'PHP'
            namespace Pagekit\Module;

            final class ModuleManifest
            {
                public const FILE = 'module.json';

                public const ENTRY = 'index.php';

                public const MAX_BYTES = 1048576;

                public static function decode(string $json): ?array
                {
                    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

                    if (!is_array($data)) {
                        throw new ModuleManifestException('Module manifest must be a JSON object.');
                    }

                    if (($data['probe'] ?? null) === 'number') {
                        return ['name' => 42, 'require' => []];
                    }

                    if (($data['probe'] ?? null) === 'blank') {
                        return ['name' => '', 'require' => []];
                    }

                    $name = $data['name'] ?? null;

                    if (!is_string($name) || $name === '') {
                        return null;
                    }

                    return ['name' => $name, 'require' => []];
                }
            }
            PHP);
    }
}

/**
 * A manifest path whose stat, open, or read a real file will not stage.
 */
final class ManifestByteStream
{
    public $context;

    private string $rest = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        if (str_contains($path, 'closed')) {
            return false;
        }

        $this->rest = str_repeat('x', ModuleManifest::MAX_BYTES + 1);

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->rest, 0, $count);
        $this->rest = substr($this->rest, strlen($chunk));

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->rest === '';
    }

    /**
     * @return array{dev: int, ino: int, mode: int, nlink: int, uid: int, gid: int, rdev: int, size: int, atime: int, mtime: int, ctime: int, blksize: int, blocks: int}
     */
    public function stream_stat(): array
    {
        return self::statArray();
    }

    /**
     * @return array{dev: int, ino: int, mode: int, nlink: int, uid: int, gid: int, rdev: int, size: int, atime: int, mtime: int, ctime: int, blksize: int, blocks: int}|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        if (str_contains($path, 'nostat')) {
            return false;
        }

        return self::statArray();
    }

    /**
     * @return array{dev: int, ino: int, mode: int, nlink: int, uid: int, gid: int, rdev: int, size: int, atime: int, mtime: int, ctime: int, blksize: int, blocks: int}
     */
    private static function statArray(): array
    {
        return [
            'dev' => 0,
            'ino' => 0,
            'mode' => 0100644,
            'nlink' => 1,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => 1,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => 0,
            'blocks' => 0,
        ];
    }
}
