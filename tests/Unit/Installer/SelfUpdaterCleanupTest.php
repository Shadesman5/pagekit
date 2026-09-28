<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Installer;

use Pagekit\Installer\SelfUpdater;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use ZipArchive;

/**
 * An update deletes files the new archive no longer ships from the directories it replaces.
 */
final class SelfUpdaterCleanupTest extends TestCase
{
    private const PASSING_REQUIREMENTS = <<<'PHP'
        <?php

        declare(strict_types=1);

        return new class {
            public function getFailedRequirements(): array
            {
                return [];
            }
        };
        PHP;

    private const FAILING_REQUIREMENTS = <<<'PHP'
        <?php

        declare(strict_types=1);

        return new class {
            public function getFailedRequirements(): array
            {
                return [
                    new class {
                        public function getHelpText(): string
                        {
                            return 'PHP is too old';
                        }
                    },
                ];
            }
        };
        PHP;

    private string $workspace = '';

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_self_updater_'.bin2hex(random_bytes(4));
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->workspace !== '') {
            $this->removeTree($this->workspace);
        }
    }

    public function testAnUpdateRemovesFilesTheReleaseNoLongerShips(): void
    {
        $root = $this->install();
        $archive = $this->workspace.'/release.zip';

        $this->archive($archive, [
            'app/installer/requirements.php' => self::PASSING_REQUIREMENTS,
            'app/kept.txt' => 'from-release-app',
            'vendor/kept.txt' => 'from-release-vendor',
        ]);

        (new SelfUpdater($root, new BufferedOutput()))->update($archive);

        self::assertFileDoesNotExist($root.'/vendor/stale.txt');
        self::assertFileDoesNotExist($root.'/vendor/orphan/leaf.txt');
        self::assertDirectoryDoesNotExist($root.'/vendor/orphan');
        self::assertFileDoesNotExist($root.'/app/stale.txt');
        self::assertFileDoesNotExist($root.'/app/old/leaf.txt');
        self::assertDirectoryDoesNotExist($root.'/app/old');
        self::assertSame('from-release-vendor', $this->contents($root.'/vendor/kept.txt'));
        self::assertSame('from-release-app', $this->contents($root.'/app/kept.txt'));
        self::assertSame('stale-package', $this->contents($root.'/packages/stale.txt'));
        // public/ is outside the directories an update replaces.
        self::assertSame('stale-public', $this->contents($root.'/public/stale.txt'));
    }

    public function testAReleaseThatFailsRequirementsLeavesTheInstallationInPlace(): void
    {
        $root = $this->install();
        $archive = $this->workspace.'/release.zip';

        $this->archive($archive, [
            'app/installer/requirements.php' => self::FAILING_REQUIREMENTS,
            'app/kept.txt' => 'from-release-app',
            'vendor/kept.txt' => 'from-release-vendor',
        ]);

        $updater = new SelfUpdater($root, new BufferedOutput());
        $failed = null;

        try {
            $updater->update($archive);
        } catch (\RuntimeException $error) {
            $failed = $error;
        }

        self::assertInstanceOf(\RuntimeException::class, $failed);
        self::assertStringContainsString('PHP is too old', $failed->getMessage());
        self::assertSame('stale-vendor', $this->contents($root.'/vendor/stale.txt'));
        self::assertSame('stale-app', $this->contents($root.'/app/stale.txt'));
        self::assertSame('installed-app', $this->contents($root.'/app/kept.txt'));
        self::assertSame('installed-vendor', $this->contents($root.'/vendor/kept.txt'));
        self::assertSame('stale-package', $this->contents($root.'/packages/stale.txt'));
        self::assertFileDoesNotExist($root.'/app/installer/requirements.php');
    }

    public function testAMissingArchiveIsRefused(): void
    {
        $updater = new SelfUpdater($this->workspace.'/install', new BufferedOutput());
        $failed = null;

        try {
            $updater->update($this->workspace.'/missing.zip');
        } catch (\RuntimeException $error) {
            $failed = $error;
        }

        self::assertInstanceOf(\RuntimeException::class, $failed);
        self::assertSame('File not found.', $failed->getMessage());
    }

    private function install(): string
    {
        $root = $this->workspace.'/install';

        $this->put($root.'/app/kept.txt', 'installed-app');
        $this->put($root.'/app/stale.txt', 'stale-app');
        $this->put($root.'/app/old/leaf.txt', 'stale-nested');
        $this->put($root.'/vendor/kept.txt', 'installed-vendor');
        $this->put($root.'/vendor/stale.txt', 'stale-vendor');
        $this->put($root.'/vendor/orphan/leaf.txt', 'stale-orphan');
        $this->put($root.'/packages/stale.txt', 'stale-package');
        $this->put($root.'/public/stale.txt', 'stale-public');

        return $root;
    }

    /**
     * @param array<string, string> $entries
     */
    private function archive(string $path, array $entries): void
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            self::fail('Could not create the release archive.');
        }

        foreach ($entries as $name => $contents) {
            if ($zip->addFromString($name, $contents) !== true) {
                self::fail('Could not add '.$name.' to the release archive.');
            }
        }

        if ($zip->close() !== true) {
            self::fail('Could not close the release archive.');
        }
    }

    private function put(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail('Could not create '.$directory);
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail('Could not write '.$path);
        }
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($path);

        self::assertIsString($contents, $path);

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

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->removeTree($path.'/'.$entry);
        }

        rmdir($path);
    }
}
