<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Console;

use Pagekit\Application;
use Pagekit\Console\Commands\BuildCommand;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;
use ZipArchive;

/**
 * A release omits dependency junk and live data, and still packs the data-directory guards.
 */
final class BuildCommandExcludeTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = strtr(sys_get_temp_dir(), '\\', '/').'/pk_build_cmd_'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            $this->removeTree($this->root);
        }
    }

    public function testJunkUnderTheRootVendorDirectoryIsLeftOutOfTheRelease(): void
    {
        $patterns = $this->excludes();

        self::assertContains('^vendor\\/lusitanian\\/oauth\\/examples', $patterns);
        self::assertContains('^vendor\\/maximebf\\/debugbar\\/src\\/DebugBar\\/Resources', $patterns);
        self::assertContains('^vendor\\/nickic\\/php-parser\\/(grammar|test_old)', $patterns);
        self::assertContains('^vendor\\/(phpdocumentor|phpspec|sebastian|symfony\\/yaml)', $patterns);
        self::assertContains(
            '^vendor\\/[^\\/]+\\/[^\\/]+\\/(build|docs?|tests?|changelog|phpunit|upgrade?)',
            $patterns,
        );

        foreach ($patterns as $pattern) {
            self::assertStringNotContainsString('app\\/vendor', $pattern);
            self::assertStringNotContainsString('app/vendor', $pattern);
        }

        $vendorPatterns = array_values(array_filter(
            $patterns,
            static fn (string $pattern): bool => str_contains($pattern, 'vendor'),
        ));

        self::assertNotEmpty($vendorPatterns);

        foreach ($vendorPatterns as $pattern) {
            self::assertStringStartsWith('^vendor\\/', $pattern);
        }

        // execute() builds this expression beside the node build, which this test does not run.
        $source = file_get_contents(dirname(__DIR__, 3).'/app/console/src/Commands/BuildCommand.php');

        self::assertIsString($source);
        self::assertStringContainsString("\$filter = '/' . implode('|', \$this->excludes) . '/i';", $source);

        $filter = '/'.implode('|', $patterns).'/i';

        self::assertSame(1, preg_match($filter, 'vendor/acme/widget/tests/Foo.php'));
        self::assertSame(0, preg_match($filter, 'vendor/acme/widget/src/Foo.php'));
        self::assertSame(0, preg_match($filter, 'app/vendor/acme/widget/tests/Foo.php'));
    }

    public function testADatabaseFileUnderDataIsLeftOutOfTheRelease(): void
    {
        $patterns = $this->excludes();

        self::assertContains(
            '^data\\/(?!(?:\\.htaccess|\\.gitignore|snapshots\\/\\.htaccess|snapshots\\/\\.gitignore|state\\/\\.htaccess|state\\/\\.gitignore)$)',
            $patterns,
        );
        self::assertContains('(^|\\/)db\\.dump$', $patterns);

        $filter = '/'.implode('|', $patterns).'/i';

        $omitted = [
            'data/pagekit.db' => "sqlite-live\n",
            'data/other.db' => "other-live\n",
            'data/pagekit.db-wal' => "wal\n",
            'data/pagekit.db-shm' => "shm\n",
            'data/config.php' => "<?php return [];\n",
            'data/snapshots/db.dump' => "snapshot-dump\n",
            'data/snapshots/20260101-blog-deadbeef/db.dump' => "named-snapshot-dump\n",
            'data/snapshots/20260101-blog-deadbeef/metadata.json' => "{}\n",
            'data/state/failures.php' => "<?php return [];\n",
            'db.dump' => "root-dump\n",
            'packages/pagekit/blog/db.dump' => "package-dump\n",
        ];

        foreach (array_keys($omitted) as $path) {
            self::assertSame(1, preg_match($filter, $path), $path);
        }

        self::assertSame(0, preg_match($filter, 'nested/data/pagekit.db'));

        $guards = (new \ReflectionClassConstant(BuildCommand::class, 'DATA_GUARDS'))->getValue();

        self::assertSame([
            'data/.htaccess',
            'data/.gitignore',
            'data/snapshots/.htaccess',
            'data/snapshots/.gitignore',
            'data/state/.htaccess',
            'data/state/.gitignore',
        ], $guards);

        $guardFiles = [];

        foreach ($guards as $guard) {
            self::assertIsString($guard);
            self::assertSame(0, preg_match($filter, $guard), $guard);
            $guardFiles[$guard] = $guard."\n";
        }

        // Finder never yields a name that starts with a dot, so a guard is packed only when execute() adds it.
        $this->plant([
            'scripts/build.mjs' => "process.exit(0);\n",
            '.bowerrc' => "{}\n",
            '.htaccess' => "RewriteEngine On\n",
            'kept.txt' => "ship-me\n",
            'nested/data/pagekit.db' => "nested-db\n",
        ] + $guardFiles + $omitted);

        $tester = $this->runBuild();

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode(), $tester->getDisplay(true));
        self::assertStringContainsString('Build: pagekit-1.0.0.zip', $tester->getDisplay(true));

        $zipFile = $this->root.'/pagekit-1.0.0.zip';
        self::assertFileExists($zipFile);

        $entries = $this->entries($zipFile);

        foreach (array_keys($omitted) as $name) {
            self::assertNotContains($name, $entries, $name);
        }

        foreach ($guardFiles as $name => $contents) {
            self::assertSame(1, count(array_keys($entries, $name, true)), $name);
            self::assertSame($contents, $this->entry($zipFile, $name));
        }

        self::assertSame("ship-me\n", $this->entry($zipFile, 'kept.txt'));
        self::assertSame("nested-db\n", $this->entry($zipFile, 'nested/data/pagekit.db'));
        self::assertNotContains('pagekit-1.0.0.zip', $entries);
    }

    public function testAFailedAssetBuildWritesNoRelease(): void
    {
        $this->plant([
            'scripts/build.mjs' => "console.error('asset build failed');\nprocess.exit(1);\n",
            'data/pagekit.db' => "sqlite-live\n",
            'data/.htaccess' => "deny-data\n",
        ]);

        $tester = $this->runBuild();

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode(), $tester->getDisplay(true));
        self::assertStringContainsString('Build failed:', $tester->getDisplay(true));
        self::assertStringContainsString('asset build failed', $tester->getDisplay(true));
        self::assertStringNotContainsString('Building Package.', $tester->getDisplay(true));
        self::assertFileDoesNotExist($this->root.'/pagekit-1.0.0.zip');
    }

    /**
     * @return list<string>
     */
    private function excludes(): array
    {
        $property = new ReflectionProperty(BuildCommand::class, 'excludes');
        $patterns = $property->getValue(new BuildCommand(new Application()));

        self::assertIsArray($patterns);

        $values = [];

        foreach ($patterns as $pattern) {
            self::assertIsString($pattern);
            $values[] = $pattern;
        }

        return $values;
    }

    private function runBuild(): CommandTester
    {
        $app = new Application();
        $app->set('path', $this->root);
        $app->set('version', '1.0.0');

        $tester = new CommandTester(new BuildCommand($app));
        $tester->execute([]);

        return $tester;
    }

    /**
     * @param array<string, string> $files
     */
    private function plant(array $files): void
    {
        foreach ($files as $relative => $contents) {
            $path = $this->root.'/'.$relative;
            $directory = dirname($path);

            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($path, $contents);
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

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path.'/'.$entry);
        }

        rmdir($path);
    }
}
