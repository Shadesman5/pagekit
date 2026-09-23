<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Pagekit\Package\Archive\ArchiveRefusedException;
use Pagekit\Package\Archive\PackageArchive;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * A package ZIP is accepted only after every check the install relies on, and a refusal writes nothing.
 */
final class PackageArchiveTest extends TestCase
{
    private const EXTENSION_INDEX = <<<'PHP'
        <?php

        return [
            'name' => 'demo',
            'autoload' => [
                'Pagekit\\Demo\\' => 'src',
            ],
        ];

        PHP;

    private const BARE_INDEX = <<<'PHP'
        <?php

        return [
            'name' => 'demo',
            'autoload' => [],
        ];

        PHP;

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/pk_archive_'.bin2hex(random_bytes(8));
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testAValidExtensionExposesTheManifestAndWritesNothing(): void
    {
        $archive = $this->openPackage(
            [
                'description' => 'A demo package.',
                'extra' => ['scripts' => 'scripts.php'],
            ],
            null,
            ['scripts.php' => "<?php\n"],
        );

        self::assertSame('pagekit/demo', $archive->name());
        self::assertSame('demo', $archive->module());
        self::assertSame('1.2.3', $archive->version());
        self::assertSame('pagekit-extension', $archive->type());
        self::assertSame('Demo', $archive->title());
        self::assertSame(['Pagekit\\Demo\\' => 'src'], $archive->autoload());
        self::assertSame('A demo package.', $archive->composer()['description']);
        self::assertSame('scripts.php', $archive->composer()['extra']['scripts']);
        self::assertSame($this->workspace.'/package.zip', $archive->path());
    }

    public function testAValidThemeMayAutoloadNothing(): void
    {
        $index = <<<'PHP'
            <?php

            return [
                'name' => 'theme-one',
                'autoload' => [],
            ];

            PHP;

        $archive = $this->openPackage([
            'name' => 'pagekit/theme-one',
            'type' => 'pagekit-theme',
            'version' => '1.0.1',
            'title' => 'One',
        ], $index);

        self::assertSame('pagekit/theme-one', $archive->name());
        self::assertSame('theme-one', $archive->module());
        self::assertSame('1.0.1', $archive->version());
        self::assertSame('pagekit-theme', $archive->type());
        self::assertSame('One', $archive->title());
        self::assertSame([], $archive->autoload());
    }

    public function testATitleKeepsSurroundingSpace(): void
    {
        $archive = $this->openPackage(['title' => ' Demo '], self::BARE_INDEX);

        self::assertSame(' Demo ', $archive->title());
    }

    public function testComposerJsonMayStartWithWhitespace(): void
    {
        $files = $this->package([], self::BARE_INDEX);
        $files['composer.json'] = "\n\n".$files['composer.json'];

        $archive = $this->openFiles($files);

        self::assertSame('pagekit/demo', $archive->name());
    }

    public function testTheShippedBlogArchiveOpens(): void
    {
        $path = $this->workspace.'/pagekit-blog.zip';
        $this->zipDirectory($this->projectPath('packages/pagekit/blog'), $path);

        $archive = PackageArchive::open($path);

        // Closures in the shipped manifest return early; those returns are not the module record.
        self::assertSame('pagekit/blog', $archive->name());
        self::assertSame('blog', $archive->module());
        self::assertSame('1.0.7', $archive->version());
        self::assertSame('pagekit-extension', $archive->type());
        self::assertSame('Blog', $archive->title());
        self::assertSame(['Pagekit\\Blog\\' => 'src'], $archive->autoload());
        self::assertSame('scripts.php', $archive->composer()['extra']['scripts']);
        self::assertSame($path, $archive->path());
        $this->assertWorkspaceHoldsOnly($path);
    }

    public function testTheShippedThemeArchiveOpens(): void
    {
        $path = $this->workspace.'/pagekit-theme-one.zip';
        $this->zipDirectory($this->projectPath('packages/pagekit/theme-one'), $path);

        $archive = PackageArchive::open($path);

        self::assertSame('pagekit/theme-one', $archive->name());
        self::assertSame('theme-one', $archive->module());
        self::assertSame('1.0.1', $archive->version());
        self::assertSame('pagekit-theme', $archive->type());
        self::assertSame('One', $archive->title());
        self::assertSame([], $archive->autoload());
        self::assertSame('image.jpg', $archive->composer()['extra']['image']);
        self::assertSame($path, $archive->path());
        $this->assertWorkspaceHoldsOnly($path);
    }

    public function testExtractToWritesExactlyTheEntriesWithUmaskModes(): void
    {
        $demo = "<?php\n\x00\xFF";
        $files = $this->package();
        $files['src/Demo.php'] = $demo;
        $files['docs/'] = '';
        $path = $this->archive($files);

        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($path));
        // The archive asks for an executable file. Unpacking still takes the umask, not that mode.
        self::assertTrue($zip->setExternalAttributesName('src/Demo.php', ZipArchive::OPSYS_UNIX, (0100000 | 0755) << 16));
        self::assertTrue($zip->close());

        $archive = PackageArchive::open($path);
        $destination = $this->workspace.'/out';
        $saved = umask(0077);

        try {
            $archive->extractTo($destination);

            self::assertSame(
                [
                    'out',
                    'out/composer.json',
                    'out/docs',
                    'out/index.php',
                    'out/src',
                    'out/src/Demo.php',
                    'package.zip',
                ],
                $this->workspaceEntries(),
            );
            self::assertSame($files['composer.json'], file_get_contents($destination.'/composer.json'));
            self::assertSame($files['index.php'], file_get_contents($destination.'/index.php'));
            self::assertSame($demo, file_get_contents($destination.'/src/Demo.php'));
            self::assertSame(0700, $this->mode($destination));
            self::assertSame(0700, $this->mode($destination.'/src'));
            self::assertSame(0700, $this->mode($destination.'/docs'));
            self::assertSame(0600, $this->mode($destination.'/composer.json'));
            self::assertSame(0600, $this->mode($destination.'/index.php'));
            self::assertSame(0600, $this->mode($destination.'/src/Demo.php'));
        } finally {
            umask($saved);
        }
    }

    public function testAMissingFileIsRefused(): void
    {
        $path = $this->workspace.'/missing.zip';

        try {
            PackageArchive::open($path);
            self::fail('Expected the archive to be refused.');
        } catch (ArchiveRefusedException $exception) {
            self::assertStringContainsString('not a readable ZIP archive', $exception->getMessage());
        }

        self::assertSame([], $this->workspaceEntries());
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $path = $this->workspace.'/package.zip';
        file_put_contents($path, '');

        $this->assertRefused($path, 'not a readable ZIP archive');
    }

    public function testANonZipFileIsRefused(): void
    {
        $path = $this->workspace.'/package.zip';
        file_put_contents($path, 'not a zip');

        $this->assertRefused($path, 'not a readable ZIP archive');
    }

    public function testAnArchiveWithNoEntriesIsRefused(): void
    {
        // ZipArchive will not save an archive that has no entries.
        $path = $this->workspace.'/package.zip';
        file_put_contents($path, "PK\x05\x06".str_repeat("\0", 18));

        $message = $this->assertRefused($path, 'The archive is empty.');
        self::assertStringNotContainsString('not a readable ZIP archive', $message);
    }

    #[DataProvider('pathsThatLeaveThePackage')]
    public function testAPathThatLeavesThePackageIsRefused(string $name, string $reason): void
    {
        // The entry name has to be these bytes. ZipArchive rewrites a leading slash and a backslash.
        $message = $this->assertRawRefused([
            ['name' => $name, 'data' => '<?php'],
        ], $reason);
        self::assertStringNotContainsString('backslash', $message);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pathsThatLeaveThePackage(): iterable
    {
        yield 'parent segment' => ['../escape.php', 'not a relative path'];
        yield 'absolute' => ['/abs.php', 'not a relative path'];
        yield 'drive prefix' => ['C:/win.php', 'not a relative path'];
    }

    public function testABackslashIsRefusedBeforeTheDriveCheck(): void
    {
        $message = $this->assertRawRefused([
            ['name' => 'C:\\win.php', 'data' => '<?php'],
        ], 'backslash');
        self::assertStringNotContainsString('not a relative path', $message);

        $slash = $this->assertRawRefused([
            ['name' => 'a\\b.php', 'data' => '<?php'],
        ], 'backslash');
        self::assertStringContainsString('a\\b.php', $slash);
    }

    public function testADotSegmentIsRefused(): void
    {
        $this->assertRawRefused([
            ['name' => 'foo/./bar.php', 'data' => '<?php'],
        ], 'not a relative path');
    }

    public function testAnEmptyPathSegmentIsRefused(): void
    {
        $this->assertRawRefused([
            ['name' => 'a//b.php', 'data' => '<?php'],
        ], 'not a relative path');
    }

    public function testANulInAQuotedVersionIsReplaced(): void
    {
        $message = $this->assertPackageRefused(
            ['version' => "1.0+\x00build"],
            'build metadata',
            self::BARE_INDEX,
        );

        self::assertStringContainsString('1.0+?build', $message);
        self::assertStringNotContainsString("\0", $message);
    }

    public function testASymlinkIsRefusedWhateverTheHostByte(): void
    {
        $path = $this->workspace.'/package.zip';
        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL));
        self::assertTrue($zip->addFromString('link.php', ''));
        // S_IFLNK sits in the high half of the attributes. The host byte here is DOS, not Unix.
        self::assertTrue($zip->setExternalAttributesName('link.php', ZipArchive::OPSYS_DOS, 0120000 << 16));
        self::assertTrue($zip->close());

        [$system, $attributes] = $this->externalAttributes($path, 'link.php');
        self::assertSame(0120000, ($attributes >> 16) & 0170000);
        self::assertNotSame(ZipArchive::OPSYS_UNIX, $system);

        $this->assertRefused($path, 'symbolic link');
    }

    public function testARegularFileModeIsNotASymlink(): void
    {
        $path = $this->archive($this->package());
        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($path));
        self::assertTrue($zip->setExternalAttributesName('src/Demo.php', ZipArchive::OPSYS_DOS, 0100644 << 16));
        self::assertTrue($zip->close());

        [, $attributes] = $this->externalAttributes($path, 'src/Demo.php');
        self::assertSame(0100644, ($attributes >> 16) & 0177777);

        $archive = PackageArchive::open($path);

        self::assertSame('pagekit/demo', $archive->name());
        $this->assertWorkspaceHoldsOnly($path);
    }

    public function testARepeatedEntryNameIsRefused(): void
    {
        $this->assertRawRefused([
            ['name' => 'index.php', 'data' => '<?php'],
            ['name' => 'index.php', 'data' => '<?php return [];'],
        ], 'more than once');
    }

    public function testEntryNamesThatCollideIgnoringCaseAreRefused(): void
    {
        $this->assertRefused($this->archive([
            'Index.php' => '<?php',
            'index.php' => '<?php return [];',
        ]), 'more than once');
    }

    public function testAFileThatIsAlsoAFolderIsRefused(): void
    {
        $message = $this->assertRefused($this->archive([
            'a' => 'file',
            'a/b.php' => '<?php',
        ]), 'both as a file and as a folder');
        self::assertStringContainsString('"a"', $message);
    }

    public function testAFileAndFolderThatCollideIgnoringCaseAreRefused(): void
    {
        $this->assertRefused($this->archive([
            'A' => 'file',
            'a/b.php' => '<?php',
        ]), 'both as a file and as a folder');
    }

    public function testOneDeclaredSizeAboveTheLimitIsRefused(): void
    {
        $path = $this->archive(['a.bin' => 'a']);
        $this->declareUncompressedSize($path, 0, PackageArchive::MAX_UNCOMPRESSED_BYTES + 1);

        $message = $this->assertRefused($path, 'MiB');
        self::assertStringContainsString((string) intdiv(PackageArchive::MAX_UNCOMPRESSED_BYTES, 1024 * 1024), $message);
    }

    public function testDeclaredSizesAreSummedAgainstTheLimit(): void
    {
        $path = $this->archive(['a.bin' => 'a', 'b.bin' => 'b']);
        $half = intdiv(PackageArchive::MAX_UNCOMPRESSED_BYTES, 2) + 1;
        $this->declareUncompressedSize($path, 0, $half);
        $this->declareUncompressedSize($path, 1, $half);

        $this->assertRefused($path, 'MiB');
    }

    public function testTheSizeLimitAllowsAnArchiveAtTheLimit(): void
    {
        $path = $this->archive(['a.bin' => 'a']);
        $this->declareUncompressedSize($path, 0, PackageArchive::MAX_UNCOMPRESSED_BYTES);

        $message = $this->assertRefused($path, 'top level');
        self::assertStringNotContainsString('MiB', $message);
    }

    public function testAWrappingDirectoryIsRefused(): void
    {
        $wrapped = [];

        foreach ($this->package() as $name => $contents) {
            $wrapped['pagekit-demo/'.$name] = $contents;
        }

        $message = $this->assertRefused($this->archive($wrapped), 'top level');
        self::assertStringContainsString('composer.json', $message);
    }

    public function testAMissingComposerManifestIsRefused(): void
    {
        $files = $this->package();
        unset($files['composer.json']);

        $message = $this->assertRefused($this->archive($files), 'top level');
        self::assertStringContainsString('composer.json', $message);
    }

    public function testMalformedComposerJsonIsRefused(): void
    {
        $this->assertRefused($this->archive($this->package(files: ['composer.json' => '{'])), 'not a JSON object');
    }

    public function testAJsonListIsNotAComposerObject(): void
    {
        $files = $this->package([], self::BARE_INDEX);
        $files['composer.json'] = '['.$files['composer.json'].']';

        $this->assertRefused($this->archive($files), 'not a JSON object');
    }

    #[DataProvider('invalidPackageNames')]
    public function testAnInvalidPackageNameIsRefused(string $name): void
    {
        $this->assertPackageRefused(['name' => $name], 'no valid "name"', self::BARE_INDEX);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPackageNames(): iterable
    {
        yield 'uppercase' => ['Pagekit/demo'];
        yield 'one segment' => ['pagekit'];
        yield 'empty vendor' => ['/demo'];
        yield 'empty package' => ['pagekit/'];
        yield 'three segments' => ['pagekit/demo/extra'];
        yield 'space' => ['pagekit/demo package'];
    }

    #[DataProvider('invalidPackageTypes')]
    public function testAnInvalidPackageTypeIsRefused(string $type): void
    {
        $this->assertPackageRefused(['type' => $type], 'pagekit-extension', self::BARE_INDEX);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPackageTypes(): iterable
    {
        yield 'library' => ['library'];
        yield 'wrong case' => ['pagekit-Extension'];
        yield 'empty' => [''];
    }

    public function testBuildMetadataInTheVersionIsRefused(): void
    {
        $message = $this->assertPackageRefused(['version' => '1.0+build'], 'build metadata', self::BARE_INDEX);

        self::assertStringContainsString('1.0+build', $message);
        self::assertStringContainsString('+', $message);
        self::assertStringNotContainsString('no valid "version"', $message);
    }

    public function testAMissingVersionIsRefused(): void
    {
        $files = $this->package([], self::BARE_INDEX);
        $document = json_decode($files['composer.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        unset($document['version']);
        $files['composer.json'] = json_encode($document, JSON_THROW_ON_ERROR);

        $this->assertRefused($this->archive($files), 'no valid "version"');
    }

    #[DataProvider('versionsOutsideTheSnapshotAlphabet')]
    public function testAVersionOutsideTheSnapshotAlphabetIsRefused(mixed $version): void
    {
        $message = $this->assertPackageRefused(['version' => $version], 'no valid "version"', self::BARE_INDEX);
        self::assertStringNotContainsString('build metadata', $message);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function versionsOutsideTheSnapshotAlphabet(): iterable
    {
        yield 'leading dot' => ['.1'];
        yield 'space' => ['1.0.0 '];
        yield 'empty' => [''];
        yield 'number' => [1.2];
    }

    public function testAMissingTitleIsRefused(): void
    {
        $files = $this->package([], self::BARE_INDEX);
        $document = json_decode($files['composer.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        unset($document['title']);
        $files['composer.json'] = json_encode($document, JSON_THROW_ON_ERROR);

        $this->assertRefused($this->archive($files), '"title"');
    }

    #[DataProvider('blankTitles')]
    public function testABlankTitleIsRefused(string $title): void
    {
        $this->assertPackageRefused(['title' => $title], '"title"', self::BARE_INDEX);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankTitles(): iterable
    {
        yield 'spaces' => ['   '];
        yield 'newline' => ["\n"];
    }

    public function testLifecycleScriptsMustNameAFileInTheArchive(): void
    {
        $this->assertPackageRefused(
            ['extra' => ['scripts' => 'scripts.php']],
            'extra.scripts',
            self::BARE_INDEX,
        );
    }

    public function testLifecycleScriptsMustNameAFileNotAFolder(): void
    {
        $this->assertPackageRefused(
            ['extra' => ['scripts' => 'src']],
            'extra.scripts',
            self::BARE_INDEX,
            ['src/' => '', 'src/Demo.php' => "<?php\n"],
        );
    }

    public function testAnEmptyScriptsStringIsIgnored(): void
    {
        $archive = $this->openPackage(['extra' => ['scripts' => '']], self::BARE_INDEX);

        self::assertSame('', $archive->composer()['extra']['scripts']);
    }

    public function testAnEmptyScriptsListIsIgnored(): void
    {
        $archive = $this->openPackage(['extra' => ['scripts' => []]], self::BARE_INDEX);

        self::assertSame([], $archive->composer()['extra']['scripts']);
    }

    #[DataProvider('nonStringScripts')]
    public function testANonStringScriptsValueIsRefused(mixed $scripts): void
    {
        $this->assertPackageRefused(
            ['extra' => ['scripts' => $scripts]],
            'extra.scripts',
            self::BARE_INDEX,
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringScripts(): iterable
    {
        yield 'list' => [['scripts.php']];
        yield 'number' => [1];
        yield 'true' => [true];
    }

    public function testAStringExtraBlockIsNotAScriptsList(): void
    {
        $archive = $this->openPackage(['extra' => 'scripts.php'], self::BARE_INDEX);

        self::assertSame('scripts.php', $archive->composer()['extra']);
    }

    public function testScriptsPathsDropDotAndEmptySegments(): void
    {
        $archive = $this->openPackage(
            ['extra' => ['scripts' => 'dir//./scripts.php']],
            self::BARE_INDEX,
            ['dir/scripts.php' => "<?php\n"],
        );

        self::assertSame('dir//./scripts.php', $archive->composer()['extra']['scripts']);
    }

    public function testScriptsPathsDoNotCollapseParentSegments(): void
    {
        $this->assertPackageRefused(
            ['extra' => ['scripts' => 'nested/../scripts.php']],
            'extra.scripts',
            self::BARE_INDEX,
            ['scripts.php' => "<?php\n"],
        );
    }

    public function testScriptsPathsRefuseALeadingSlash(): void
    {
        $this->assertPackageRefused(
            ['extra' => ['scripts' => '/scripts.php']],
            'extra.scripts',
            self::BARE_INDEX,
            ['scripts.php' => "<?php\n"],
        );
    }

    public function testScriptsPathsDoNotTreatBackslashAsASeparator(): void
    {
        $this->assertPackageRefused(
            ['extra' => ['scripts' => 'src\\file.php']],
            'extra.scripts',
            self::BARE_INDEX,
            ['src/file.php' => "<?php\n"],
        );
    }

    public function testComposerJsonAboveAMegabyteIsRefused(): void
    {
        $files = $this->package(files: ['composer.json' => str_repeat('x', (1024 * 1024) + 1)]);

        $message = $this->assertRefused($this->archive($files), 'too large');
        self::assertStringContainsString('composer.json', $message);
    }

    public function testComposerJsonAtAMegabyteIsStillRead(): void
    {
        $files = $this->package([], self::BARE_INDEX, ['composer.json' => str_repeat('x', 1024 * 1024)]);

        $message = $this->assertRefused($this->archive($files), 'not a JSON object');
        self::assertStringNotContainsString('too large', $message);
    }

    public function testAMissingIndexIsRefused(): void
    {
        $files = $this->package();
        unset($files['index.php']);

        $message = $this->assertRefused($this->archive($files), 'top level');
        self::assertStringContainsString('index.php', $message);
    }

    public function testIndexPhpAboveAMegabyteIsRefused(): void
    {
        $message = $this->assertPackageRefused([], 'too large', str_repeat('x', (1024 * 1024) + 1));
        self::assertStringContainsString('index.php', $message);
    }

    public function testIndexPhpAtAMegabyteIsStillRead(): void
    {
        $index = rtrim(self::BARE_INDEX)."\n";
        $index .= str_repeat(' ', (1024 * 1024) - strlen($index));
        self::assertSame(1024 * 1024, strlen($index));

        $archive = $this->openPackage([], $index);

        self::assertSame('demo', $archive->module());
    }

    public function testAnUnparseableIndexIsRefused(): void
    {
        $this->assertPackageRefused([], 'cannot be parsed', "<?php\nreturn [\n");
    }

    public function testAnIndexWithNoTopLevelReturnIsRefused(): void
    {
        $this->assertPackageRefused([], 'array literal', "<?php\n\$value = 1;\n");
    }

    public function testAReturnOnlyInsideAnIfIsRefused(): void
    {
        $source = <<<'PHP'
            <?php

            if (true) {
                return [
                    'name' => 'demo',
                    'autoload' => [],
                ];
            }

            PHP;

        $message = $this->assertPackageRefused([], 'array literal', $source);
        self::assertStringNotContainsString('more than one return', $message);
    }

    public function testAReturnOnlyInsideADeclareBlockIsRefused(): void
    {
        $source = <<<'PHP'
            <?php

            declare(ticks=1) {
                return [
                    'name' => 'demo',
                    'autoload' => [],
                ];
            }

            PHP;

        $message = $this->assertPackageRefused([], 'array literal', $source);
        self::assertStringNotContainsString('more than one return', $message);
    }

    public function testAReturnOnlyInsideATryIsRefused(): void
    {
        $source = <<<'PHP'
            <?php

            try {
                return [
                    'name' => 'demo',
                    'autoload' => [],
                ];
            } catch (\Throwable $error) {
            }

            PHP;

        $message = $this->assertPackageRefused([], 'array literal', $source);
        self::assertStringNotContainsString('more than one return', $message);
    }

    public function testASecondTopLevelReturnIsRefused(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'demo',
                'autoload' => [],
            ];

            return [
                'name' => 'demo',
                'autoload' => [],
            ];

            PHP;

        $this->assertPackageRefused([], 'more than one return', $source);
    }

    public function testReturnsInsideFunctionsAndClassesDoNotCount(): void
    {
        $source = <<<'PHP'
            <?php

            $fn = function () {
                return 1;
            };

            class Demo
            {
                public function run(): int
                {
                    return 2;
                }
            }

            $object = new class {
                public function run(): int
                {
                    return 3;
                }
            };

            return [
                'name' => 'demo',
                'autoload' => [],
            ];

            PHP;

        $archive = $this->openPackage([], $source);

        self::assertSame('demo', $archive->module());
        self::assertSame([], $archive->autoload());
    }

    public function testTheManifestReturnIsReadAfterASemicolonNamespace(): void
    {
        $source = <<<'PHP'
            <?php

            namespace Pagekit\Demo;

            return [
                'name' => 'demo',
                'autoload' => [],
            ];

            PHP;

        // A semicolon namespace keeps the return as a sibling of the namespace node.
        $archive = $this->openPackage([], $source);

        self::assertSame('demo', $archive->module());
    }

    public function testTheManifestReturnIsReadUnderANamespaceBlock(): void
    {
        $source = <<<'PHP'
            <?php

            namespace Pagekit\Demo {
                return [
                    'name' => 'demo',
                    'autoload' => [],
                ];
            }

            PHP;

        $archive = $this->openPackage([], $source);

        self::assertSame('demo', $archive->module());
    }

    public function testTheLastLiteralNameWins(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'other',
                0 => 'skipped',
                'name' => 'demo',
                'autoload' => [],
            ];

            PHP;

        $archive = $this->openPackage([], $source);

        self::assertSame('demo', $archive->module());
    }

    public function testAnEarlierNameDoesNotWin(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'demo',
                'name' => 'other',
                'autoload' => [],
            ];

            PHP;

        $this->assertPackageRefused([], "'name'", $source);
    }

    public function testAComputedNameIsRefused(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'de' . 'mo',
                'autoload' => [],
            ];

            PHP;

        $this->assertPackageRefused([], "'name'", $source);
    }

    public function testAVariableNameIsRefused(): void
    {
        $source = <<<'PHP'
            <?php

            $name = 'demo';

            return [
                'name' => $name,
                'autoload' => [],
            ];

            PHP;

        $this->assertPackageRefused([], "'name'", $source);
    }

    public function testAComputedKeyClearsLiteralKeysBeforeIt(): void
    {
        $source = <<<'PHP'
            <?php

            $key = 'extra';

            return [
                'name' => 'demo',
                'autoload' => [],
                $key => 1,
            ];

            PHP;

        $this->assertPackageRefused([], "'name'", $source);
    }

    public function testLiteralsAfterAComputedKeyStillCount(): void
    {
        $source = <<<'PHP'
            <?php

            $key = 'extra';

            return [
                $key => 1,
                'name' => 'demo',
                'autoload' => [],
            ];

            PHP;

        $archive = $this->openPackage([], $source);

        self::assertSame('demo', $archive->module());
    }

    public function testASpreadClearsLiteralKeysBeforeIt(): void
    {
        $source = <<<'PHP'
            <?php

            $extra = ['title' => 'x'];

            return [
                'name' => 'demo',
                'autoload' => [],
                ...$extra,
            ];

            PHP;

        $this->assertPackageRefused([], "'name'", $source);
    }

    public function testLiteralsAfterASpreadStillCount(): void
    {
        $source = <<<'PHP'
            <?php

            $extra = ['title' => 'x'];

            return [
                ...$extra,
                'name' => 'demo',
                'autoload' => [],
            ];

            PHP;

        $archive = $this->openPackage([], $source);

        self::assertSame('demo', $archive->module());
    }

    public function testAnArrayFunctionLiteralIsAManifest(): void
    {
        $source = <<<'PHP'
            <?php

            return array(
                'name' => 'demo',
                'autoload' => array(),
            );

            PHP;

        $archive = $this->openPackage([], $source);

        self::assertSame([], $archive->autoload());
    }

    public function testTheModuleNameMustBeThePackageBasename(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'other',
                'autoload' => [],
            ];

            PHP;

        $message = $this->assertPackageRefused([], "'name'", $source);
        self::assertStringContainsString('demo', $message);
    }

    public function testAMissingAutoloadMapIsRefused(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'demo',
            ];

            PHP;

        $this->assertPackageRefused([], "'autoload'", $source);
    }

    public function testAnAutoloadValueThatIsNotAnArrayIsRefused(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'demo',
                'autoload' => 'src',
            ];

            PHP;

        $this->assertPackageRefused([], "'autoload'", $source);
    }

    public function testAComputedAutoloadValueIsRefused(): void
    {
        $source = <<<'PHP'
            <?php

            $src = 'src';

            return [
                'name' => 'demo',
                'autoload' => [
                    'Pagekit\\Demo\\' => $src,
                ],
            ];

            PHP;

        $this->assertPackageRefused([], "'autoload'", $source, ['src/Demo.php' => "<?php\n"]);
    }

    public function testASpreadInTheAutoloadMapIsRefused(): void
    {
        $source = <<<'PHP'
            <?php

            $more = [];

            return [
                'name' => 'demo',
                'autoload' => [
                    'Pagekit\\Demo\\' => 'src',
                    ...$more,
                ],
            ];

            PHP;

        $this->assertPackageRefused([], "'autoload'", $source, ['src/Demo.php' => "<?php\n"]);
    }

    public function testAnAutoloadPathOutsideTheArchiveIsRefused(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'demo',
                'autoload' => [
                    'Pagekit\\Demo\\' => 'missing',
                ],
            ];

            PHP;

        $message = $this->assertPackageRefused([], 'not a folder', $source);
        self::assertStringContainsString('missing', $message);
    }

    public function testAnOverriddenAutoloadPathIsStillChecked(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'demo',
                'autoload' => [
                    'Pagekit\\Demo\\' => 'missing',
                    'Pagekit\\Demo\\' => 'src',
                ],
            ];

            PHP;

        $message = $this->assertPackageRefused([], 'not a folder', $source, ['src/Demo.php' => "<?php\n"]);
        self::assertStringContainsString('missing', $message);
    }

    public function testTheLastAutoloadPathIsKeptWhenBothFoldersExist(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'demo',
                'autoload' => [
                    'Pagekit\\Demo\\' => 'src',
                    'Pagekit\\Demo\\' => 'lib',
                ],
            ];

            PHP;

        $archive = $this->openPackage([], $source, [
            'src/Demo.php' => "<?php\n",
            'lib/Demo.php' => "<?php\n",
        ]);

        self::assertSame(['Pagekit\\Demo\\' => 'lib'], $archive->autoload());
    }

    public function testAutoloadDotMeansThePackageRoot(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'demo',
                'autoload' => [
                    'Pagekit\\Demo\\' => '.',
                ],
            ];

            PHP;

        $archive = $this->openPackage([], $source);

        self::assertSame(['Pagekit\\Demo\\' => '.'], $archive->autoload());
    }

    public function testAnEmptyAutoloadPathDoesNotMeanThePackageRoot(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'demo',
                'autoload' => [
                    'Pagekit\\Demo\\' => '',
                ],
            ];

            PHP;

        $this->assertPackageRefused([], 'not a folder', $source);
    }

    public function testAutoloadPathsDropDotAndEmptySegments(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'demo',
                'autoload' => [
                    'Pagekit\\Demo\\' => './src//Sub',
                ],
            ];

            PHP;

        $archive = $this->openPackage([], $source, ['src/Sub/A.php' => "<?php\n"]);

        self::assertSame(['Pagekit\\Demo\\' => './src//Sub'], $archive->autoload());
    }

    public function testAutoloadPathsTreatBackslashAsASeparator(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'demo',
                'autoload' => [
                    'Pagekit\\Demo\\' => 'src\\Sub',
                ],
            ];

            PHP;

        $archive = $this->openPackage([], $source, ['src/Sub/A.php' => "<?php\n"]);

        self::assertSame(['Pagekit\\Demo\\' => 'src\\Sub'], $archive->autoload());
    }

    public function testAutoloadPathsDoNotCollapseParentSegments(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'demo',
                'autoload' => [
                    'Pagekit\\Demo\\' => 'src/../src',
                ],
            ];

            PHP;

        $this->assertPackageRefused([], 'not a folder', $source, ['src/A.php' => "<?php\n"]);
    }

    public function testAutoloadPathsRefuseALeadingSlash(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'demo',
                'autoload' => [
                    'Pagekit\\Demo\\' => '/src',
                ],
            ];

            PHP;

        $this->assertPackageRefused([], 'not a folder', $source, ['src/A.php' => "<?php\n"]);
    }

    public function testAControlCharacterInAQuotedVersionIsReplaced(): void
    {
        $version = $this->assertPackageRefused(
            ['version' => "1.0+\x01build"],
            'build metadata',
            self::BARE_INDEX,
        );
        self::assertStringContainsString('1.0+?build', $version);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $version);
    }

    public function testInvalidUtf8InAQuotedPathIsReplaced(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'name' => 'demo',
                'autoload' => [
                    "A\x01" => "no\xFFpe",
                ],
            ];

            PHP;

        $message = $this->assertPackageRefused([], 'not a folder', $source);
        self::assertStringContainsString('A?', $message);
        self::assertStringContainsString('no?pe', $message);
        self::assertStringNotContainsString("\x01", $message);
        self::assertStringNotContainsString("\xFF", $message);
        self::assertSame(1, preg_match('//u', $message));
    }

    public function testAHandBuiltArchiveOpens(): void
    {
        $files = $this->package([], self::BARE_INDEX);
        $path = $this->rawArchive([
            ['name' => 'composer.json', 'data' => $files['composer.json']],
            ['name' => 'index.php', 'data' => $files['index.php']],
        ]);
        $archive = PackageArchive::open($path);

        self::assertSame('pagekit/demo', $archive->name());
        self::assertSame('1.2.3', $archive->version());
        self::assertSame([], $archive->autoload());
        $this->assertWorkspaceHoldsOnly($path);
    }

    public function testExtractToRejectsAnArchiveWhoseEntryChanged(): void
    {
        $path = $this->archive($this->package());
        $archive = PackageArchive::open($path);
        $files = $this->package();

        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($path, ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('composer.json', $files['composer.json']));
        self::assertTrue($zip->addFromString('index.php', $files['index.php']));
        self::assertTrue($zip->addFromString('src/Demo.php', "<?php\n// changed\n"));
        self::assertTrue($zip->close());

        $destination = $this->workspace.'/out';
        $this->assertUnpackFailed($archive, $destination, 'changed after it was checked');
        self::assertFileDoesNotExist($destination);
    }

    public function testExtractToRejectsAnArchiveThatGainedAnEntry(): void
    {
        $path = $this->archive($this->package());
        $archive = PackageArchive::open($path);
        $files = $this->package();

        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($path, ZipArchive::OVERWRITE));

        foreach ($files as $name => $contents) {
            self::assertTrue($zip->addFromString($name, $contents));
        }

        self::assertTrue($zip->addFromString('readme.txt', 'extra'));
        self::assertTrue($zip->close());

        $destination = $this->workspace.'/out';
        $this->assertUnpackFailed($archive, $destination, 'changed after it was checked');
        self::assertFileDoesNotExist($destination);
    }

    public function testExtractToRejectsAnArchiveThatCanNoLongerBeRead(): void
    {
        $path = $this->archive($this->package());
        $archive = PackageArchive::open($path);
        unlink($path);

        $destination = $this->workspace.'/out';
        $this->assertUnpackFailed($archive, $destination, 'can no longer be read');
        self::assertFileDoesNotExist($destination);
    }

    public function testExtractToDoesNotReplaceAnExistingPath(): void
    {
        $path = $this->archive($this->package());
        $archive = PackageArchive::open($path);
        $destination = $this->workspace.'/out';
        mkdir($destination);
        file_put_contents($destination.'/composer.json', 'kept');

        $message = $this->assertUnpackFailed($archive, $destination, 'could not be unpacked');
        self::assertStringContainsString('composer.json', $message);
        self::assertSame('kept', file_get_contents($destination.'/composer.json'));
        self::assertFileDoesNotExist($destination.'/index.php');
    }

    public function testExtractToDoesNotFollowALinkPlantedAtTheTarget(): void
    {
        $path = $this->archive($this->package());
        $archive = PackageArchive::open($path);
        $outside = $this->workspace.'/secret.txt';
        file_put_contents($outside, 'secret');
        $destination = $this->workspace.'/out';
        mkdir($destination);
        symlink($outside, $destination.'/composer.json');

        $this->assertUnpackFailed($archive, $destination, 'could not be unpacked');
        self::assertSame('secret', file_get_contents($outside));
        self::assertTrue(is_link($destination.'/composer.json'));
    }

    public function testExtractToFailsWhenTheDestinationCannotBeCreated(): void
    {
        $path = $this->archive($this->package([], self::BARE_INDEX));
        $archive = PackageArchive::open($path);
        file_put_contents($this->workspace.'/blocked', 'x');

        $this->assertUnpackFailed($archive, $this->workspace.'/blocked/child', 'could not be created');
        self::assertSame('x', file_get_contents($this->workspace.'/blocked'));
        self::assertFileDoesNotExist($this->workspace.'/blocked/child');
    }

    public function testABlockedEntryFailsAndLeavesWhatWasAlreadyUnpacked(): void
    {
        $files = $this->package();
        $path = $this->archive($files);
        $archive = PackageArchive::open($path);
        $destination = $this->workspace.'/out';
        mkdir($destination);
        file_put_contents($destination.'/src', 'blocked');

        $message = $this->assertUnpackFailed($archive, $destination, 'could not be unpacked');
        self::assertStringContainsString('src/Demo.php', $message);
        self::assertSame($files['composer.json'], file_get_contents($destination.'/composer.json'));
        self::assertSame($files['index.php'], file_get_contents($destination.'/index.php'));
        self::assertSame('blocked', file_get_contents($destination.'/src'));
    }

    public function testADamagedEntryFailsAndLeavesWhatWasAlreadyUnpacked(): void
    {
        $files = $this->package([], self::BARE_INDEX, ['readme.txt' => 'hello']);
        $path = $this->archive($files);
        $this->declareCrc($path, 2, 0);
        $archive = PackageArchive::open($path);
        $destination = $this->workspace.'/out';

        $message = $this->assertUnpackFailed($archive, $destination, 'is damaged');
        self::assertStringContainsString('readme.txt', $message);
        self::assertSame($files['composer.json'], file_get_contents($destination.'/composer.json'));
        self::assertSame($files['index.php'], file_get_contents($destination.'/index.php'));
    }

    /**
     * @param array<string, mixed>  $composer
     * @param array<string, string> $files
     */
    private function openPackage(array $composer = [], ?string $index = null, array $files = []): PackageArchive
    {
        return $this->openFiles($this->package($composer, $index, $files));
    }

    /**
     * @param array<string, string> $files
     */
    private function openFiles(array $files): PackageArchive
    {
        $path = $this->archive($files);
        $archive = PackageArchive::open($path);
        $this->assertWorkspaceHoldsOnly($path);

        return $archive;
    }

    /**
     * @param array<string, mixed>  $composer
     * @param array<string, string> $files
     */
    private function assertPackageRefused(array $composer, string $reason, ?string $index = null, array $files = []): string
    {
        return $this->assertRefused($this->archive($this->package($composer, $index, $files)), $reason);
    }

    /**
     * @param list<array{name: string, data?: string, size?: int, crc?: int, attributes?: int, opsys?: int}> $entries
     */
    private function assertRawRefused(array $entries, string $reason): string
    {
        return $this->assertRefused($this->rawArchive($entries), $reason);
    }

    private function assertRefused(string $path, string $reason): string
    {
        $message = $this->refusedMessage($path);
        self::assertStringContainsString($reason, $message);

        return $message;
    }

    private function refusedMessage(string $path): string
    {
        try {
            PackageArchive::open($path);
        } catch (ArchiveRefusedException $exception) {
            $this->assertWorkspaceHoldsOnly($path);

            return $exception->getMessage();
        }

        self::fail('Expected the archive to be refused.');
    }

    private function assertUnpackFailed(PackageArchive $archive, string $directory, string $reason): string
    {
        $failed = null;

        try {
            $archive->extractTo($directory);
        } catch (ArchiveRefusedException $exception) {
            $failed = $exception;
        } catch (\RuntimeException $exception) {
            $failed = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failed);
        self::assertNotInstanceOf(ArchiveRefusedException::class, $failed);
        self::assertStringContainsString($reason, $failed->getMessage());

        return $failed->getMessage();
    }

    /**
     * @param array<string, mixed>  $composer
     * @param array<string, string> $files
     *
     * @return array<string, string>
     */
    private function package(array $composer = [], ?string $index = null, array $files = []): array
    {
        $document = array_merge([
            'name' => 'pagekit/demo',
            'type' => 'pagekit-extension',
            'version' => '1.2.3',
            'title' => 'Demo',
        ], $composer);

        $entries = [
            'composer.json' => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'index.php' => $index ?? self::EXTENSION_INDEX,
        ];

        if ($index === null) {
            $entries['src/Demo.php'] = "<?php\n";
        }

        return array_merge($entries, $files);
    }

    /**
     * @param array<string, string> $files
     */
    private function archive(array $files, string $name = 'package.zip'): string
    {
        $path = $this->workspace.'/'.$name;
        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL));

        foreach ($files as $entry => $contents) {
            if (str_ends_with($entry, '/')) {
                self::assertTrue($zip->addEmptyDir(substr($entry, 0, -1)));

                continue;
            }

            self::assertTrue($zip->addFromString($entry, $contents));
        }

        self::assertTrue($zip->close());

        return $path;
    }

    /**
     * A stored ZIP. Entry names ZipArchive rewrites, and a repeated name, have to be the bytes on record.
     *
     * @param list<array{name: string, data?: string, size?: int, crc?: int, attributes?: int, opsys?: int}> $entries
     */
    private function rawArchive(array $entries, string $name = 'package.zip'): string
    {
        $path = $this->workspace.'/'.$name;
        $locals = '';
        $central = '';

        foreach ($entries as $entry) {
            $entryName = $entry['name'];
            $data = $entry['data'] ?? '';
            $compressed = strlen($data);
            $uncompressed = $entry['size'] ?? $compressed;
            $crc = $entry['crc'] ?? crc32($data);
            $nameLength = strlen($entryName);
            $attributes = $entry['attributes'] ?? 0;
            $opsys = $entry['opsys'] ?? 0;
            $offset = strlen($locals);

            $locals .= pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                0,
                0,
                0,
                $crc,
                $compressed,
                $uncompressed,
                $nameLength,
                0,
            ).$entryName.$data;

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                ($opsys << 8) | 20,
                20,
                0,
                0,
                0,
                0,
                $crc,
                $compressed,
                $uncompressed,
                $nameLength,
                0,
                0,
                0,
                0,
                $attributes,
                $offset,
            ).$entryName;
        }

        $count = count($entries);
        $file = $locals.$central.pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $count,
            $count,
            strlen($central),
            strlen($locals),
            0,
        );
        self::assertNotFalse(file_put_contents($path, $file));

        return $path;
    }

    /**
     * The size check reads the size the central directory declares, not the bytes stored for the entry.
     */
    private function declareUncompressedSize(string $path, int $entryIndex, int $size): void
    {
        $this->rewriteEntryField($path, $entryIndex, 'size', $size);
        $this->assertListingField($path, $entryIndex, 'size', $size);
    }

    /**
     * The checksum check compares the bytes that were unpacked with the checksum the listing declares.
     */
    private function declareCrc(string $path, int $entryIndex, int $crc): void
    {
        $this->rewriteEntryField($path, $entryIndex, 'crc', $crc);
        $this->assertListingField($path, $entryIndex, 'crc', $crc);
    }

    /**
     * @return array{int, int} host system, then the external attributes
     */
    private function externalAttributes(string $path, string $entry): array
    {
        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($path, ZipArchive::RDONLY));
        $system = 0;
        $attributes = 0;
        self::assertTrue($zip->getExternalAttributesName($entry, $system, $attributes));
        self::assertTrue($zip->close());

        return [$system, $attributes];
    }

    private function assertListingField(string $path, int $entryIndex, string $field, int $value): void
    {
        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($path, ZipArchive::RDONLY));
        $stat = $zip->statIndex($entryIndex);
        self::assertIsArray($stat);
        self::assertSame($value, $stat[$field]);
        self::assertTrue($zip->close());
    }

    private function rewriteEntryField(string $path, int $entryIndex, string $field, int $value): void
    {
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);

        $eocd = strrpos($bytes, "PK\x05\x06");
        self::assertNotFalse($eocd);

        $count = $this->leShort($bytes, $eocd + 10);
        $cursor = $this->leLong($bytes, $eocd + 16);

        for ($index = 0; $index < $count; ++$index) {
            self::assertSame("PK\x01\x02", substr($bytes, $cursor, 4));

            $nameLength = $this->leShort($bytes, $cursor + 28);
            $extraLength = $this->leShort($bytes, $cursor + 30);
            $commentLength = $this->leShort($bytes, $cursor + 32);
            $compressed = $this->leLong($bytes, $cursor + 20);
            $localOffset = $this->leLong($bytes, $cursor + 42);

            if ($index === $entryIndex) {
                $bytes = $this->rewriteDeclared($bytes, $cursor, $localOffset, $compressed, $field, $value);
            }

            $cursor += 46 + $nameLength + $extraLength + $commentLength;
        }

        self::assertNotFalse(file_put_contents($path, $bytes));
    }

    private function rewriteDeclared(string $bytes, int $central, int $localOffset, int $compressedSize, string $field, int $value): string
    {
        $centralField = $field === 'crc' ? 16 : 24;
        $bytes = substr_replace($bytes, pack('V', $value), $central + $centralField, 4);

        self::assertSame("PK\x03\x04", substr($bytes, $localOffset, 4));
        $flags = $this->leShort($bytes, $localOffset + 6);
        $nameLength = $this->leShort($bytes, $localOffset + 26);
        $extraLength = $this->leShort($bytes, $localOffset + 28);
        $localField = $field === 'crc' ? 14 : 22;
        $bytes = substr_replace($bytes, pack('V', $value), $localOffset + $localField, 4);

        if (($flags & 8) === 0) {
            return $bytes;
        }

        $at = $localOffset + 30 + $nameLength + $extraLength + $compressedSize;

        if (substr($bytes, $at, 4) === "PK\x07\x08") {
            $at += 4;
        }

        $descriptorField = $field === 'crc' ? 0 : 8;

        return substr_replace($bytes, pack('V', $value), $at + $descriptorField, 4);
    }

    private function leShort(string $bytes, int $offset): int
    {
        $unpacked = unpack('v', substr($bytes, $offset, 2));
        self::assertIsArray($unpacked);

        return $unpacked[1];
    }

    private function leLong(string $bytes, int $offset): int
    {
        $unpacked = unpack('V', substr($bytes, $offset, 4));
        self::assertIsArray($unpacked);

        return $unpacked[1];
    }

    private function zipDirectory(string $source, string $destination): void
    {
        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($destination, ZipArchive::CREATE | ZipArchive::EXCL));

        $root = rtrim(strtr($source, '\\', '/'), '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }

            $relative = strtr(substr($file->getPathname(), strlen($root) + 1), '\\', '/');

            if ($this->excluded($relative)) {
                continue;
            }

            self::assertTrue($zip->addFile($file->getPathname(), $relative), $relative);
        }

        self::assertTrue($zip->close());
    }

    private function excluded(string $relative): bool
    {
        foreach (explode('/', $relative) as $segment) {
            if ($segment === 'node_modules') {
                return true;
            }
        }

        return false;
    }

    private function projectPath(string $relative): string
    {
        return dirname(__DIR__, 3).'/'.$relative;
    }

    private function mode(string $path): int
    {
        clearstatcache(true, $path);

        return fileperms($path) & 0777;
    }

    /**
     * @return list<string>
     */
    private function workspaceEntries(): array
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $entries[] = strtr(substr($item->getPathname(), strlen($this->workspace) + 1), '\\', '/');
        }

        sort($entries);

        return $entries;
    }

    private function assertWorkspaceHoldsOnly(string $fixture): void
    {
        $relative = strtr(substr($fixture, strlen($this->workspace) + 1), '\\', '/');
        self::assertSame([$relative], $this->workspaceEntries());
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

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->removeTree($path.'/'.$item);
        }

        rmdir($path);
    }
}
