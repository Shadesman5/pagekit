<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Filesystem;

use Pagekit\Filesystem\Path;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Where boot paths, guards, and the image keep private runtime state.
 */
final class WritablePathPostureTest extends TestCase
{
    public function testBootPathsSitInTheirLifetimeDirectories(): void
    {
        $root = $this->root();
        $paths = $this->bootPaths();

        self::assertSame($root.'/tmp/temp', $paths['path.temp']);
        self::assertSame($root.'/tmp/cache', $paths['path.cache']);
        self::assertSame($root.'/tmp/logs', $paths['path.logs']);
        self::assertSame($root.'/data', $paths['path.data']);
        self::assertSame($root.'/data/snapshots', $paths['path.snapshots']);
        self::assertSame($root.'/data/state', $paths['path.system']);
        self::assertSame($root.'/storage', $paths['path.storage']);

        foreach (['path.temp', 'path.cache', 'path.logs', 'path.data', 'path.snapshots', 'path.system', 'path.storage'] as $key) {
            self::assertTrue(Path::isAbsolute($paths[$key]), $key);
        }

        self::assertTrue($this->isInside($paths['path.temp'], $root.'/tmp'));
        self::assertTrue($this->isInside($paths['path.cache'], $root.'/tmp'));
        self::assertTrue($this->isInside($paths['path.logs'], $root.'/tmp'));
        self::assertTrue($this->isInside($paths['path.snapshots'], $paths['path.data']));
        self::assertTrue($this->isInside($paths['path.system'], $paths['path.data']));
        self::assertTrue($this->isInside($paths['path.storage'], $root.'/storage'));

        foreach (['path.data', 'path.snapshots', 'path.system'] as $key) {
            foreach (['path.temp', 'path.cache', 'path.logs', 'path.public', 'path.storage'] as $neighbor) {
                self::assertFalse($this->isInside($paths[$key], $paths[$neighbor]), $key.' inside '.$neighbor);
            }
        }
    }

    public function testStablePathsSitOutsideTheWritableTrees(): void
    {
        $root = $this->root();
        $paths = $this->bootPaths();

        self::assertSame($root, $paths['path']);
        self::assertSame($root.'/public', $paths['path.public']);
        self::assertSame($root.'/packages', $paths['path.packages']);
        self::assertSame($root.'/vendor', $paths['path.vendor']);
        self::assertStringContainsString("'config.file' => realpath(\$path.'/config.php')", $this->source('public/index.php'));

        foreach (['path', 'path.public', 'path.packages', 'path.vendor'] as $key) {
            foreach (['tmp', 'data', 'storage'] as $tree) {
                self::assertFalse($this->isInside($paths[$key], $root.'/'.$tree), $key.' inside '.$tree);
            }
        }

        foreach (['tmp', 'data', 'storage'] as $tree) {
            self::assertFalse($this->isInside($root.'/config.php', $root.'/'.$tree));
        }
    }

    public function testPackageStagingIsTheTempPackagesDirectory(): void
    {
        $module = $this->source('app/package/src/PackageModule.php');
        $pattern = <<<'REGEX'
            /packageStaging',\s*fn\s*\(\$app\)\s*=>\s*\$app->get\('path\.temp'\)\s*\.\s*'\/packages'/
            REGEX;

        self::assertSame(1, preg_match(trim($pattern), $module));
        self::assertStringNotContainsString('packageStaging', $this->source('public/index.php'));
        self::assertArrayNotHasKey('packageStaging', $this->bootPaths());
        self::assertArrayNotHasKey('path.packageStaging', $this->bootPaths());
    }

    public function testTheUncaughtHandlerLogsUnderPathLogs(): void
    {
        self::assertStringContainsString("\$logs = \$path.'/tmp/logs';", $this->source('public/index.php'));
        self::assertSame($this->root().'/tmp/logs', $this->bootPaths()['path.logs']);
    }

    public function testSessionsAndTheDebugDatabaseStayUnderTmp(): void
    {
        $config = $this->source('app/system/config.php');

        self::assertStringContainsString('\'files\' => "$path/tmp/sessions"', $config);
        self::assertStringContainsString('\'file\' => "sqlite:$path/tmp/temp/debug.db"', $config);
    }

    public function testClearingTheCacheNamesOnlyCacheAndTemp(): void
    {
        $body = $this->braceBlock($this->source('app/system/modules/cache/src/CacheModule.php'), 'function doClearCache');

        self::assertStringContainsString("'path.cache'", $body);
        self::assertStringContainsString("'path.temp'", $body);

        foreach (['path.data', 'path.snapshots', 'path.system', 'path.logs', 'path.storage', 'path.packages'] as $key) {
            self::assertStringNotContainsString("'".$key."'", $body, $key);
        }
    }

    public function testShippedDataGuardsDenyRequestsAndIgnoreContents(): void
    {
        $data = $this->root().'/data';

        self::assertDirectoryExists($data.'/snapshots');
        self::assertDirectoryExists($data.'/state');

        foreach ([$data, $data.'/snapshots', $data.'/state'] as $directory) {
            self::assertFileExists($directory.'/.htaccess');
            self::assertStringContainsString('Require all denied', $this->sourceText($directory.'/.htaccess'));
        }

        self::assertSame(
            ['*', '!.htaccess', '!.gitignore'],
            $this->rules($data.'/snapshots/.gitignore'),
        );
        self::assertSame(
            ['*', '!.htaccess', '!.gitignore'],
            $this->rules($data.'/state/.gitignore'),
        );
        self::assertSame(
            [
                '*',
                '!.htaccess',
                '!.gitignore',
                '!snapshots/',
                '!state/',
                '!snapshots/.htaccess',
                '!snapshots/.gitignore',
                '!state/.htaccess',
                '!state/.gitignore',
            ],
            $this->rules($data.'/.gitignore'),
        );
    }

    public function testTheWebrootDoesNotLinkToData(): void
    {
        $publicData = $this->root().'/public/data';

        self::assertFalse(is_link($publicData));
        self::assertFalse(file_exists($publicData));
    }

    public function testProductionSourcesDoNotNameTheRetiredStores(): void
    {
        $needles = ['tmp/snapshots', 'tmp/system'];
        $own = strtr((string) realpath(__FILE__), '\\', '/');
        $hits = [];

        foreach ($this->productionFiles() as $file) {
            if ($file === $own || str_contains($file, '/migration-docs/')) {
                continue;
            }

            $contents = $this->textOrNull($file);

            if ($contents === null) {
                continue;
            }

            foreach ($needles as $needle) {
                if (str_contains($contents, $needle)) {
                    $hits[] = substr($file, strlen($this->root()) + 1).' contains '.$needle;
                }
            }
        }

        self::assertSame([], $hits);
    }

    public function testTheFrontControllerEnsuresPrivateDirectoriesOnlyForSystemAndConsole(): void
    {
        $source = $this->source('public/index.php');
        $loaded = strpos($source, 'app/modules/filesystem/src/RuntimeDirectories.php');
        $guardAt = strpos($source, "if (\$env === 'system' || \$env === 'console')");
        $environment = strpos($source, 'require_once "$path/app/$env/app.php"');

        self::assertIsInt($loaded);
        self::assertIsInt($guardAt);
        self::assertIsInt($environment);
        self::assertLessThan($guardAt, $loaded);
        self::assertLessThan($environment, $guardAt);
        self::assertSame(1, substr_count($source, 'app/$env/app.php'));

        $block = $this->braceBlock($source, "if (\$env === 'system' || \$env === 'console')");

        self::assertStringNotContainsString('app/$env/app.php', $block);
        self::assertStringNotContainsString('installer', $block);
        self::assertSame(3, substr_count($source, 'RuntimeDirectories::ensure('));
        self::assertSame(3, substr_count($block, 'RuntimeDirectories::ensure('));
        $this->assertEnsureOrder($block);

        self::assertStringNotContainsString('RuntimeDirectories', $this->source('app/system/app.php'));
        self::assertStringNotContainsString('RuntimeDirectories', $this->source('app/console/app.php'));
    }

    public function testTheInstallerEnsuresPrivateDirectoriesAfterRequirementsAndBeforeTheApp(): void
    {
        $source = $this->source('app/installer/app.php');
        $failed = strpos($source, 'getFailedRequirements()');
        $exit = strpos($source, "\n    exit;\n");
        $loaded = strpos($source, 'RuntimeDirectories.php');
        $app = strpos($source, 'new App(');

        self::assertIsInt($failed);
        self::assertIsInt($exit);
        self::assertIsInt($loaded);
        self::assertIsInt($app);

        $failure = $this->braceBlock($source, 'if ($failed = $requirements->getFailedRequirements())');

        self::assertStringContainsString('exit;', $failure);
        self::assertStringNotContainsString('ensure(', $failure);
        self::assertLessThan($exit, $failed);
        self::assertLessThan($loaded, $exit);
        self::assertLessThan($app, $loaded);
        self::assertSame(3, substr_count($source, 'RuntimeDirectories::ensure('));
        $this->assertEnsureOrder($source);
    }

    public function testRequirementsListDataAndDoNotCreateDirectories(): void
    {
        $source = $this->source('app/installer/requirements.php');

        self::assertStringContainsString(
            '["$path/tmp", "$path/tmp/cache", "$path/tmp/logs", "$path/tmp/sessions", "$path/data"]',
            $source,
        );
        self::assertStringNotContainsString('tmp/snapshots', $source);
        self::assertStringNotContainsString('tmp/system', $source);
        self::assertStringNotContainsString('ensure(', $source);
        self::assertStringNotContainsString('RuntimeDirectories', $source);
    }

    public function testTheImageMountsWritableStateOnTheDataDirectory(): void
    {
        $docker = $this->source('Dockerfile');

        self::assertStringContainsString('ENV PAGEKIT_DATA_DIR=/var/www/html/data', $docker);
        self::assertStringContainsString('ENV PAGEKIT_DB_PATH=$PAGEKIT_DATA_DIR/pagekit.db', $docker);
        self::assertStringContainsString('pagekit_data:/var/www/html/data', $this->source('docker-compose.prod.yml'));

        $entrypoint = $this->source('docker/entrypoint.sh');

        self::assertStringContainsString('data_dir=${PAGEKIT_DATA_DIR:-/var/www/html/data}', $entrypoint);
        self::assertStringContainsString('can_write "$data_dir"', $entrypoint);
        self::assertDoesNotMatchRegularExpression('/\bln\b[^\n]*snapshot/i', $entrypoint);

        $workflow = $this->source('.github/workflows/docker-image.yml');

        self::assertStringContainsString('test -d /var/www/html/data', $workflow);
        self::assertStringContainsString('test -w /var/www/html/data', $workflow);
        self::assertStringNotContainsString('tmp/snapshots', $workflow);
        self::assertStringNotContainsString('tmp/system', $workflow);
    }

    public function testTheInstallerBootBaselineMatchesTheInjectedVariables(): void
    {
        $source = $this->source('app/installer/app.php');

        self::assertSame(4, $this->variableReads($source, '$config'));
        self::assertSame(4, $this->variableReads($source, '$path'));
        self::assertSame(
            [
                ['#^Variable \$config might not be defined\.$#', 'variable.undefined', 4],
                ['#^Variable \$path might not be defined\.$#', 'variable.undefined', 4],
            ],
            $this->baselineEntries('app/installer/app.php'),
        );
    }

    private function assertEnsureOrder(string $source): void
    {
        $data = strpos($source, "ensure(\$config['path.data'])");
        $snapshots = strpos($source, "ensure(\$config['path.snapshots'])");
        $state = strpos($source, "ensure(\$config['path.system'])");

        self::assertIsInt($data);
        self::assertIsInt($snapshots);
        self::assertIsInt($state);
        self::assertLessThan($snapshots, $data);
        self::assertLessThan($state, $snapshots);
    }

    private function braceBlock(string $source, string $marker): string
    {
        $from = strpos($source, $marker);
        self::assertNotFalse($from, $marker);

        $open = strpos($source, '{', $from);
        self::assertNotFalse($open, $marker);

        $depth = 0;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            $character = $source[$i];

            if ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $open + 1, $i - $open - 1);
                }
            }
        }

        self::fail('Unclosed block after '.$marker);
    }

    /**
     * @return array<string, string>
     */
    private function bootPaths(): array
    {
        $file = $this->root().'/public/index.php';
        $paths = [
            'path' => dirname($file, 2),
            'path.public' => dirname($file),
        ];
        $pattern = <<<'REGEX'
            /'(path\.[a-z]+)'\s*=>\s*\$path\s*\.\s*'([^']*)'/
            REGEX;

        preg_match_all($pattern, $this->source('public/index.php'), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $paths[$match[1]] = $paths['path'].$match[2];
        }

        return $paths;
    }

    /**
     * A prefix of the segment is not the directory, so data2 does not sit inside data.
     */
    private function isInside(string $path, string $directory): bool
    {
        return str_starts_with(Path::directory($path), Path::directory($directory));
    }

    /**
     * @return list<string>
     */
    private function rules(string $file): array
    {
        $lines = array_map('trim', explode("\n", $this->sourceText($file)));

        return array_values(array_filter(
            $lines,
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));
    }

    private function variableReads(string $source, string $name): int
    {
        $reads = 0;

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === $name) {
                $reads++;
            }
        }

        return $reads;
    }

    /**
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function baselineEntries(string $path): array
    {
        $lines = file($this->root().'/phpstan-baseline.neon', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $entries = [];
        $current = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '-') {
                if ($current !== []) {
                    $entries[] = $current;
                }

                $current = [];

                continue;
            }

            if (preg_match("/^message: '(.*)'$/", $line, $match) === 1) {
                $current['message'] = $match[1];
            } elseif (preg_match('/^identifier: (\S+)$/', $line, $match) === 1) {
                $current['identifier'] = $match[1];
            } elseif (preg_match('/^count: (\d+)$/', $line, $match) === 1) {
                $current['count'] = (int) $match[1];
            } elseif (preg_match('/^path: (\S+)$/', $line, $match) === 1) {
                $current['path'] = $match[1];
            }
        }

        if ($current !== []) {
            $entries[] = $current;
        }

        $matched = [];

        foreach ($entries as $entry) {
            if (($entry['path'] ?? '') !== $path) {
                continue;
            }

            self::assertArrayHasKey('message', $entry);
            self::assertArrayHasKey('identifier', $entry);
            self::assertArrayHasKey('count', $entry);
            $matched[] = [$entry['message'], $entry['identifier'], $entry['count']];
        }

        return $matched;
    }

    /**
     * @return \Generator<int, string>
     */
    private function productionFiles(): \Generator
    {
        foreach (['app', 'public', 'packages', 'docker'] as $directory) {
            yield from $this->filesUnder($this->root().'/'.$directory);
        }

        yield $this->root().'/Dockerfile';
        yield $this->root().'/.github/workflows/docker-image.yml';
    }

    /**
     * @return \Generator<int, string>
     */
    private function filesUnder(string $directory): \Generator
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $current): bool {
                    if ($current->isLink()) {
                        return false;
                    }

                    if ($current->isDir()) {
                        return $current->getFilename() !== 'vendor' && $current->getFilename() !== 'node_modules';
                    }

                    return true;
                },
            ),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                yield strtr($file->getPathname(), '\\', '/');
            }
        }
    }

    private function textOrNull(string $file): ?string
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        $prefix = fread($handle, 512);
        fclose($handle);

        if (!is_string($prefix) || str_contains($prefix, "\0")) {
            return null;
        }

        $contents = file_get_contents($file);

        return is_string($contents) ? $contents : null;
    }

    private function source(string $relative): string
    {
        return $this->sourceText($this->root().'/'.$relative);
    }

    private function sourceText(string $file): string
    {
        $contents = file_get_contents($file);
        self::assertIsString($contents, $file);

        return $contents;
    }

    private function root(): string
    {
        return strtr(dirname(__DIR__, 3), '\\', '/');
    }
}
