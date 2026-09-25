<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Console;

use Pagekit\Application;
use Pagekit\Console\Commands\ArchiveCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;
use ZipArchive;

/**
 * The archive command keeps a file when the last matching rule says so, and a refusal writes no zip.
 */
final class ArchiveCommandTest extends TestCase
{
    private string $workspace = '';

    private string $packages = '';

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_archive_cmd_' . bin2hex(random_bytes(4));
        $this->packages = $this->workspace . '/packages';
        mkdir($this->packages, 0777, true);
    }

    protected function tearDown(): void
    {
        ArchiveRandom::$bytes = null;

        if ($this->workspace !== '') {
            $this->removeTree($this->workspace);
        }
    }

    public function testTheLastMatchingRuleDecidesWhichFilesTheArchiveKeeps(): void
    {
        $marker = $this->workspace . '/archive-script-ran';
        $root = $this->packages . '/pagekit/blog';
        $this->plant('pagekit/blog', [
            '.git/HEAD' => "ref: refs/heads/develop\n",
            '.gitignore' => implode("\r\n", [
                '# comment',
                '',
                '/secret.txt',
                '*.log',
                '**/cache/**',
                'data/*.tmp',
                'logs/',
                '/app',
                '/css',
                '/vendor/kept.txt',
                'hidden/*.txt',
                '/only-gitignore.txt',
            ]),
            '.htaccess' => "deny\n",
            'app/assets/app.js' => "asset\n",
            'app/assets/sub/deep.js' => "deep\n",
            'app/widget.vue' => "widget\n",
            'cache/a.txt' => "cache\n",
            'cached.txt' => "cached\n",
            'composer.json' => json_encode([
                'name' => 'pagekit/blog',
                'archive' => [
                    // A package-supplied command: archiving must not run it.
                    'scripts' => 'touch ' . escapeshellarg($marker),
                    'exclude' => [
                        'node_modules',
                        "/app\n!/app\n!/css\n/app/assets\n# comment\n\ncss/drop.css",
                        "!/vendor/kept.txt\r\n# comment\r\n",
                        "notes/*.txt\n!notes/keep.txt\nnotes/keep.txt",
                        '!hidden/back.txt',
                    ],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'css/drop.css' => "drop\n",
            'css/theme.css' => "theme\n",
            'data/a.tmp' => "tmp\n",
            'data/a.tmp.txt' => "not-tmp\n",
            'data/sub/a.tmp' => "nested-tmp\n",
            'debug.log' => "log\n",
            'debug.log.txt' => "not-log\n",
            'hidden/back.txt' => "back\n",
            'hidden/gone.txt' => "gone\n",
            'index.php' => "<?php\n",
            'logs.txt' => "not-a-directory\n",
            'logs/a.txt' => "log-a\n",
            'logs/sub/b.txt' => "log-b\n",
            'nested/cache/b.txt' => "nested-cache\n",
            'nested/debug.log' => "nested-log\n",
            'nested/secret.txt' => "nested-secret\n",
            'node_modules/pkg/index.js' => "dep\n",
            'notes/drop.txt' => "drop\n",
            'notes/keep.txt' => "keep\n",
            'notes/stay.md' => "stay\n",
            'only-gitignore.txt' => "ignored\n",
            'readme.md' => "readme\n",
            'secret.txt' => "secret\n",
            'vendor/kept.txt' => "kept\n",
        ]);

        $dir = $this->workspace . '/out';
        $tester = $this->archive(['name' => 'pagekit/blog', '--dir' => $dir]);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode(), $tester->getDisplay(true));
        self::assertStringContainsString("Archiving 'pagekit/blog'", $tester->getDisplay(true));
        self::assertFileDoesNotExist($marker);
        self::assertFileExists($root . '/.git/HEAD');
        self::assertFileExists($dir . '/pagekit-blog.zip');
        self::assertSame(['pagekit-blog.zip'], $this->directoryEntries($dir));

        // gitignore drops app/, css/ and vendor/kept.txt; archive.exclude puts them back, then drops app/assets/ and css/drop.css.
        self::assertSame([
            '.gitignore',
            '.htaccess',
            'app/widget.vue',
            'cached.txt',
            'composer.json',
            'css/theme.css',
            'data/a.tmp.txt',
            'data/sub/a.tmp',
            'debug.log.txt',
            'hidden/back.txt',
            'index.php',
            'logs.txt',
            'nested/secret.txt',
            'notes/stay.md',
            'readme.md',
            'vendor/kept.txt',
        ], $this->entries($dir . '/pagekit-blog.zip'));
    }

    public function testALinkOutsideThePackageIsAbsentAndALinkInsideIsStoredAsThatFile(): void
    {
        $outside = $this->workspace . '/outside.txt';
        $escaped = $this->workspace . '/escaped';
        file_put_contents($outside, 'from-outside');
        mkdir($escaped);
        file_put_contents($escaped . '/secret.txt', 'escaped');

        $root = $this->plant('pagekit/demo', [
            'index.php' => "<?php\n",
            'inside.txt' => 'from-inside',
        ]);
        self::assertTrue(symlink($outside, $root . '/leak.txt'));
        self::assertTrue(symlink($root . '/inside.txt', $root . '/alias.txt'));
        self::assertTrue(symlink($escaped, $root . '/escaped-link'));

        $dir = $this->workspace . '/out';
        $tester = $this->archive(['name' => 'pagekit/demo', '--dir' => $dir]);
        $zip = $dir . '/pagekit-demo.zip';

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode(), $tester->getDisplay(true));
        $entries = $this->entries($zip);
        sort($entries);
        self::assertSame(['alias.txt', 'index.php', 'inside.txt'], $entries);
        self::assertSame('from-inside', $this->entry($zip, 'alias.txt'));
        self::assertSame('from-inside', $this->entry($zip, 'inside.txt'));
        self::assertSame('from-outside', file_get_contents($outside));
        self::assertSame('escaped', file_get_contents($escaped . '/secret.txt'));
    }

    #[DataProvider('traversingNames')]
    public function testATraversingNameFailsBeforeAnyPathIsRead(string $name): void
    {
        $dir = $this->workspace . '/out';
        mkdir($dir);
        $zip = $dir . '/pagekit-blog.zip';
        file_put_contents($zip, 'earlier');

        $tester = new CommandTester(new ArchiveCommand(new ArchivePathsMustNotBeRead()));

        try {
            $status = $tester->execute(['name' => $name, '--dir' => $dir]);
        } catch (\Throwable $exception) {
            self::fail('The name is refused before a path is read: ' . $exception->getMessage());
        }

        self::assertSame(SymfonyCommand::FAILURE, $status);
        self::assertSame("The package name has to be \"vendor/name\" in lower case.\n", $tester->getDisplay(true));
        self::assertSame('earlier', file_get_contents($zip));
        self::assertSame([$zip], $this->zipFiles($this->workspace));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function traversingNames(): array
    {
        return [
            'parent segment' => ['../x'],
            'parent segment inside the name' => ['pagekit/../blog'],
        ];
    }

    public function testAMissingPackageFailsAndCreatesNoOutputDirectory(): void
    {
        $dir = $this->workspace . '/out';
        $tester = $this->archive(['name' => 'pagekit/missing', '--dir' => $dir]);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertSame("Package 'pagekit/missing' doesn't exist.\n", $tester->getDisplay(true));
        self::assertFileDoesNotExist($dir);
        self::assertSame([], $this->zipFiles($this->workspace));
    }

    #[DataProvider('unusableComposerJson')]
    public function testComposerJsonIsRefusedWhenItCannotSupplyRules(string $json, string $message): void
    {
        $this->plant('pagekit/blog', [
            'index.php' => "<?php\n",
            'composer.json' => $json,
        ]);
        $dir = $this->workspace . '/out';
        $tester = $this->archive(['name' => 'pagekit/blog', '--dir' => $dir]);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($message, $tester->getDisplay(true));
        self::assertFileDoesNotExist($dir);
        self::assertSame([], $this->zipFiles($this->workspace));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unusableComposerJson(): array
    {
        $package = "package 'pagekit/blog'";

        return [
            'invalid json' => ['{', "The composer.json of {$package} is not valid JSON:"],
            'json list' => ['[1, 2]', "The composer.json of {$package} is not a JSON object.\n"],
            'exclude null' => ['{"archive":{"exclude":null}}', "gives an \"archive.exclude\" that is not a list of strings.\n"],
            'exclude map' => ['{"archive":{"exclude":{"pattern":"*"}}}', "gives an \"archive.exclude\" that is not a list of strings.\n"],
            'exclude string' => ['{"archive":{"exclude":"node_modules"}}', "gives an \"archive.exclude\" that is not a list of strings.\n"],
            'exclude number' => ['{"archive":{"exclude":[1]}}', "gives an \"archive.exclude\" that is not a list of strings.\n"],
        ];
    }

    #[DataProvider('ruleFiles')]
    public function testAnUnreadableRuleFileFailsAndWritesNothing(string $filename): void
    {
        $root = $this->plant('pagekit/blog', [
            'index.php' => "<?php\n",
            'composer.json' => '{}\n',
            '.gitignore' => "#\n",
        ]);
        chmod($root . '/' . $filename, 0000);
        clearstatcache(true, $root . '/' . $filename);

        if (is_readable($root . '/' . $filename)) {
            chmod($root . '/' . $filename, 0644);
            self::markTestSkipped('The process can read a mode-000 file, so an unreadable rule file cannot be planted.');
        }

        $dir = $this->workspace . '/out';
        $tester = $this->archive(['name' => 'pagekit/blog', '--dir' => $dir]);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertSame("The .gitignore or composer.json of package 'pagekit/blog' cannot be read.\n", $tester->getDisplay(true));
        self::assertFileDoesNotExist($dir);
        self::assertSame([], $this->zipFiles($this->workspace));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ruleFiles(): array
    {
        return [
            'composer.json' => ['composer.json'],
            '.gitignore' => ['.gitignore'],
        ];
    }

    public function testARuleThatCannotBeMatchedFailsAndLeavesTheEarlierArchive(): void
    {
        $this->plant('pagekit/blog', [
            'index.php' => "<?php\n",
            'composer.json' => "{}\n",
        ]);
        $dir = $this->workspace . '/out';
        $first = $this->archive(['name' => 'pagekit/blog', '--dir' => $dir]);
        $zip = $dir . '/pagekit-blog.zip';
        $bytes = file_get_contents($zip);

        self::assertSame(SymfonyCommand::SUCCESS, $first->getStatusCode(), $first->getDisplay(true));
        self::assertIsString($bytes);

        file_put_contents($this->packages . '/pagekit/blog/composer.json', '{"archive":{"exclude":["[]]"]}}');
        $second = $this->archive(['name' => 'pagekit/blog', '--dir' => $dir]);

        self::assertSame(SymfonyCommand::FAILURE, $second->getStatusCode());
        self::assertStringContainsString("'[]]'", $second->getDisplay(true));
        self::assertStringContainsString('cannot be matched', $second->getDisplay(true));
        self::assertSame($bytes, file_get_contents($zip));
        self::assertSame(['pagekit-blog.zip'], $this->directoryEntries($dir));
    }

    public function testAPackageWhoseEveryFileIsExcludedFailsAndCreatesNoOutputDirectory(): void
    {
        $this->plant('pagekit/blog', [
            'index.php' => "<?php\n",
            '.gitignore' => "#\n",
            'composer.json' => '{"archive":{"exclude":["*"]}}',
        ]);
        $dir = $this->workspace . '/out';
        $tester = $this->archive(['name' => 'pagekit/blog', '--dir' => $dir]);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertSame("Archiving 'pagekit/blog'\nPackage 'pagekit/blog' has no file to archive.\n", $tester->getDisplay(true));
        self::assertFileDoesNotExist($dir);
        self::assertSame([], $this->zipFiles($this->workspace));
    }

    #[DataProvider('archivesThatAddNoRules')]
    public function testAnArchiveThatIsNotAnObjectAddsNoExcludeRules(string $json): void
    {
        $this->plant('pagekit/blog', [
            'index.php' => "<?php\n",
            'secret.txt' => "secret\n",
            'node_modules/pkg.js' => "pkg\n",
            'composer.json' => $json,
        ]);
        $dir = $this->workspace . '/out';
        $tester = $this->archive(['name' => 'pagekit/blog', '--dir' => $dir]);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode(), $tester->getDisplay(true));
        self::assertSame([
            'composer.json',
            'index.php',
            'node_modules/pkg.js',
            'secret.txt',
        ], $this->entries($dir . '/pagekit-blog.zip'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function archivesThatAddNoRules(): array
    {
        return [
            'archive string' => ['{"archive":"node_modules"}'],
            'archive list' => ['{"archive":[]}'],
            'empty exclude object' => ['{"archive":{"exclude":{}}}'],
        ];
    }

    public function testAPackageWithoutComposerJsonIsArchivedAtTheDefaultPath(): void
    {
        $this->plant('pagekit/blog', [
            'index.php' => "<?php\n",
            '.htaccess' => "deny\n",
        ]);
        $tester = $this->archive(['name' => 'pagekit/blog']);
        $zip = $this->workspace . '/default-out/pagekit-blog.zip';

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode(), $tester->getDisplay(true));
        self::assertFileExists($zip);
        self::assertSame(['.htaccess', 'index.php'], $this->entries($zip));
    }

    public function testAnOutputDirectoryThatCannotBeCreatedFailsWithoutAZip(): void
    {
        $this->plant('pagekit/blog', [
            'index.php' => "<?php\n",
        ]);
        $blocker = $this->workspace . '/blocked';
        file_put_contents($blocker, 'not-a-directory');
        $tester = $this->archive(['name' => 'pagekit/blog', '--dir' => $blocker . '/out']);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('cannot be created', $tester->getDisplay(true));
        self::assertSame('not-a-directory', file_get_contents($blocker));
        self::assertSame([], $this->zipFiles($this->workspace));
    }

    public function testAnArchiveThatCannotBeOpenedLeavesTheEarlierZipUntouched(): void
    {
        $this->plant('pagekit/demo', [
            'index.php' => "<?php\n",
        ]);
        $dir = $this->workspace . '/out';
        $first = $this->archive(['name' => 'pagekit/demo', '--dir' => $dir]);
        $target = $dir . '/pagekit-demo.zip';
        $bytes = file_get_contents($target);

        self::assertSame(SymfonyCommand::SUCCESS, $first->getStatusCode(), $first->getDisplay(true));
        self::assertIsString($bytes);

        $suffix = "\x00\x00\x00\x00";
        ArchiveRandom::$bytes = $suffix;
        $temp = $target . '.' . bin2hex($suffix);
        file_put_contents($temp, 'sentinel');

        $second = $this->archive(['name' => 'pagekit/demo', '--dir' => $dir]);

        self::assertSame(SymfonyCommand::FAILURE, $second->getStatusCode());
        self::assertStringContainsString('cannot be written', $second->getDisplay(true));
        self::assertStringContainsString($target, $second->getDisplay(true));
        self::assertSame($bytes, file_get_contents($target));
        self::assertSame('sentinel', file_get_contents($temp));
        self::assertSame(['pagekit-demo.zip', 'pagekit-demo.zip.00000000'], $this->directoryEntries($dir));
    }

    public function testAWriteFailureRemovesTheTemporaryZipAndLeavesTheTarget(): void
    {
        $this->plant('pagekit/demo', [
            'index.php' => "<?php\n",
        ]);
        $dir = $this->workspace . '/out';
        $target = $dir . '/pagekit-demo.zip';
        mkdir($dir);
        mkdir($target);
        file_put_contents($target . '/sentinel.txt', 'keep');

        $tester = $this->archive(['name' => 'pagekit/demo', '--dir' => $dir]);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('cannot be written', $tester->getDisplay(true));
        self::assertStringContainsString($target, $tester->getDisplay(true));
        self::assertSame(['pagekit-demo.zip'], $this->directoryEntries($dir));
        self::assertSame('keep', file_get_contents($target . '/sentinel.txt'));
        self::assertSame([], $this->zipFiles($this->workspace));
    }

    public function testANonStringNameIsRefusedBeforeAnythingIsRead(): void
    {
        try {
            (new CommandTester(new ArchiveCommandWithANonStringName(new ArchivePathsMustNotBeRead())))->execute([
                'name' => 'pagekit/blog',
                '--dir' => $this->workspace . '/out',
            ]);
            self::fail('A non-string name must be refused.');
        } catch (\LogicException $exception) {
            self::assertSame('Argument "name" must be a string.', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->workspace . '/out');
        self::assertSame([], $this->zipFiles($this->workspace));
    }

    public function testANonStringOutputDirectoryIsRefusedBeforeAZipIsWritten(): void
    {
        $this->plant('pagekit/blog', [
            'index.php' => "<?php\n",
            'composer.json' => "{}\n",
        ]);

        try {
            (new CommandTester(new ArchiveCommandWithANonStringDir($this->container())))->execute([
                'name' => 'pagekit/blog',
                '--dir' => $this->workspace . '/out',
            ]);
            self::fail('A non-string output directory must be refused.');
        } catch (\LogicException $exception) {
            self::assertSame('Option "dir" must be a string.', $exception->getMessage());
        }

        self::assertSame([], $this->zipFiles($this->workspace));
    }

    public function testTheShippedThemeDropsAssetsAndDependenciesAndKeepsItsSources(): void
    {
        $entries = $this->archiveShipped('pagekit/theme-one');

        self::assertContains('index.php', $entries);
        self::assertContains('composer.json', $entries);
        self::assertContains('.gitignore', $entries);
        self::assertContains('app/components/widget-theme.vue', $entries);

        foreach ($entries as $entry) {
            self::assertStringStartsNotWith('app/assets/', $entry, $entry);
            self::assertNotSame('app/assets', $entry);
        }

        $this->assertArchiveEntries($entries);
    }

    public function testTheShippedBlogKeepsItsPhpSources(): void
    {
        $entries = $this->archiveShipped('pagekit/blog');

        self::assertContains('index.php', $entries);
        self::assertContains('composer.json', $entries);
        self::assertContains('scripts.php', $entries);
        self::assertNotEmpty(array_filter(
            $entries,
            static fn (string $entry): bool => str_starts_with($entry, 'src/'),
        ));
        $this->assertArchiveEntries($entries);
    }

    /**
     * @param array<string, string> $files
     */
    private function plant(string $name, array $files): string
    {
        $root = $this->packages . '/' . $name;

        foreach ($files as $relative => $contents) {
            $path = $root . '/' . $relative;
            $directory = dirname($path);

            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($path, $contents);
        }

        return $root;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function archive(array $input, ?Application $app = null): CommandTester
    {
        $tester = new CommandTester(new ArchiveCommand($app ?? $this->container()));
        $tester->execute($input);

        return $tester;
    }

    private function container(?string $packages = null): Application
    {
        $app = new Application();
        $app->set('path.packages', $packages ?? $this->packages);
        $app->set('path', $this->workspace . '/default-out');

        return $app;
    }

    /**
     * @return list<string>
     */
    private function archiveShipped(string $name): array
    {
        $dir = $this->workspace . '/shipped';
        $tester = $this->archive(
            ['name' => $name, '--dir' => $dir],
            $this->container(dirname(__DIR__, 3) . '/packages'),
        );
        $zip = $dir . '/' . strtr($name, '/', '-') . '.zip';

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode(), $tester->getDisplay(true));
        self::assertFileExists($zip);

        return $this->entries($zip);
    }

    /**
     * @param list<string> $entries
     */
    private function assertArchiveEntries(array $entries): void
    {
        $sorted = $entries;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $entries);

        foreach ($entries as $entry) {
            self::assertStringEndsNotWith('/', $entry, $entry);
            self::assertStringNotContainsString('\\', $entry, $entry);
            self::assertStringStartsNotWith('.git/', $entry, $entry);
            self::assertNotContains('node_modules', explode('/', $entry), $entry);
            self::assertNotContains('.git', explode('/', $entry), $entry);
        }
    }

    /**
     * @return list<string>
     */
    private function entries(string $archive): array
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive));
        $names = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            self::assertIsString($name);
            $names[] = $name;
        }

        $zip->close();

        return $names;
    }

    private function entry(string $archive, string $name): string
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive));
        $contents = $zip->getFromName($name);
        $zip->close();
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * @return list<string>
     */
    private function directoryEntries(string $dir): array
    {
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function zipFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $found = [];

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $root . '/' . $entry;

            if (is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                $found = array_merge($found, $this->zipFiles($path));

                continue;
            }

            if (is_file($path) && str_ends_with($entry, '.zip')) {
                $found[] = $path;
            }
        }

        sort($found);

        return $found;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0777);
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        @chmod($path, 0777);

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }

        rmdir($path);
    }
}

/**
 * Refuses the read of either path the command joins the package name into.
 */
final class ArchivePathsMustNotBeRead extends Application
{
    public function get(string $id): mixed
    {
        if ($id === 'path.packages' || $id === 'path') {
            throw new \LogicException('The command read ' . $id . '.');
        }

        return parent::get($id);
    }
}

/**
 * Forces the package name off the string the console would have passed.
 */
final class ArchiveCommandWithANonStringName extends ArchiveCommand
{
    public function argument(?string $key = null): string|array|null
    {
        return ['pagekit/blog'];
    }
}

/**
 * Forces the output directory off the string the console would have passed.
 */
final class ArchiveCommandWithANonStringDir extends ArchiveCommand
{
    public function option(?string $key = null): string|array|bool|null
    {
        if ($key === 'dir') {
            return ['not-a-dir'];
        }

        return parent::option($key);
    }
}

/**
 * Pins the suffix of the temporary archive when a test sets $bytes.
 */
final class ArchiveRandom
{
    public static ?string $bytes = null;
}

namespace Pagekit\Console\Commands;

function random_bytes(int $length): string
{
    $bytes = \Pagekit\Tests\Unit\Console\ArchiveRandom::$bytes;

    if ($bytes !== null) {
        \Pagekit\Tests\Unit\Console\ArchiveRandom::$bytes = null;

        if (strlen($bytes) !== $length) {
            throw new \LengthException('The archive probe was given the wrong number of bytes.');
        }

        return $bytes;
    }

    return \random_bytes($length);
}
