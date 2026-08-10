<?php

declare(strict_types=1);

namespace Pagekit\Filesystem\Tests;

use Pagekit\Filesystem\Adapter\StreamAdapter;
use Pagekit\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The configuration, the route cache and the package registry are PHP files that
 * one request reads back with require while another one rewrites them. Writing
 * them means: a reader sees either the whole old file or the whole new one, a
 * write that cannot be completed damages neither the target nor its permissions
 * and leaves no temp file behind, a target that cannot be replaced by a move is
 * refused rather than written some other way, and where the move cannot be
 * staged at all the content still reaches a target that is already there.
 */
class DumpAtomicTest extends TestCase
{
    use FileUtil;

    private const OLD_CONFIG = "<?php\n\nreturn ['debug' => true];\n";
    private const NEW_CONFIG = "<?php\n\nreturn ['debug' => false];\n";

    private Filesystem $file;
    private string $workspace;
    private int $umask;

    protected function setUp(): void
    {
        $this->file = new Filesystem();
        $this->workspace = strtr($this->getTempDir('filesystem_'), '\\', '/');

        // Pinned so the permissions expected below are literal values instead of
        // a second copy of the production formula.
        $this->umask = umask(0027);
    }

    protected function tearDown(): void
    {
        umask($this->umask);

        if (in_array('store', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('store');
        }

        $this->removeDir($this->workspace);
    }

    public function testAFreshFileIsWrittenAndReadsBackAsWritten(): void
    {
        $file = $this->workspace.'/config.php';

        $this->file->dumpAtomic($file, self::NEW_CONFIG);

        $this->assertSame(self::NEW_CONFIG, file_get_contents($file));
        $this->assertSame(['config.php'], $this->entries($this->workspace));

        $config = require $file;
        $this->assertSame(['debug' => false], $config);
    }

    public function testAnExistingFileIsReplacedWithoutLeavingATempFileBehind(): void
    {
        $file = $this->workspace.'/config.php';
        file_put_contents($file, self::OLD_CONFIG);

        $this->file->dumpAtomic($file, self::NEW_CONFIG);

        $this->assertSame(self::NEW_CONFIG, file_get_contents($file));
        $this->assertSame(['config.php'], $this->entries($this->workspace));
    }

    public function testAReaderHoldingTheFileOpenNeverSeesAHalfWrittenReplacement(): void
    {
        if (!$this->canRenameOverAnOpenFile()) {
            $this->markTestSkipped('This platform refuses to move a file that is held open, which is the documented non-atomic fallback');
        }

        $file = $this->workspace.'/config.php';
        file_put_contents($file, self::OLD_CONFIG);

        $reader = fopen($file, 'r');
        $this->assertNotFalse($reader);

        $this->file->dumpAtomic($file, self::NEW_CONFIG);

        // The replacement moves a finished file over the directory entry rather
        // than rewriting the file the reader is holding, so that reader keeps the
        // configuration it started with instead of running into a truncated one.
        $this->assertSame(self::OLD_CONFIG, stream_get_contents($reader));
        fclose($reader);

        $this->assertSame(self::NEW_CONFIG, file_get_contents($file));
    }

    public function testAWriteThroughALinkLandsOnTheFileTheLinkPointsAt(): void
    {
        // An installation can keep its configuration on a data volume and reach it
        // through a link in the application root. Replacing the link itself would
        // orphan the file on the volume and lose the next write with it.
        $data = $this->workspace.'/data';
        mkdir($data);

        $target = $data.'/config.php';
        file_put_contents($target, self::OLD_CONFIG);

        $link = $this->workspace.'/config.php';

        if (!@symlink($target, $link)) {
            $this->markTestSkipped('symlink() is unavailable on this host');
        }

        try {
            $this->file->dumpAtomic($link, self::NEW_CONFIG);

            $this->assertTrue(is_link($link));
            $this->assertSame(self::NEW_CONFIG, file_get_contents($target));
            $this->assertSame(['config.php', 'data'], $this->entries($this->workspace));
        } finally {
            // The link outlives the tree walk that cleans the workspace up.
            unlink($link);
        }
    }

    #[DataProvider('provideCreateModes')]
    public function testACreatedFileGetsTheModeAPlainWriteWouldGiveIt(?int $mode, int $expected): void
    {
        $file = $this->workspace.'/config.php';

        $this->file->dumpAtomic($file, self::NEW_CONFIG, $mode);

        $this->assertSame($expected, $this->permissions($file));
    }

    /**
     * The umask is pinned to 0027 for the duration of a test.
     *
     * @return array<string, array{0: int|null, 1: int}>
     */
    public static function provideCreateModes(): array
    {
        return [
            'no mode given, as a plain write would create it' => [null, 0640],
            'a mode the umask narrows' => [0666, 0640],
            'a mode stricter than the umask' => [0600, 0600],
        ];
    }

    public function testReplacingAHardenedFileKeepsItsPermissions(): void
    {
        // An operator who tightened the configuration must not have it widened
        // again by the next write from the settings screen.
        $file = $this->workspace.'/config.php';
        file_put_contents($file, self::OLD_CONFIG);
        chmod($file, 0600);

        $this->file->dumpAtomic($file, self::NEW_CONFIG);

        $this->assertSame(0600, $this->permissions($file));
        $this->assertSame(self::NEW_CONFIG, file_get_contents($file));
    }

    public function testAMissingTargetDirectoryFailsInsteadOfBeingCreated(): void
    {
        $file = $this->workspace.'/nested/config.php';

        $error = $this->failedWrite(\RuntimeException::class, $file);

        $this->assertStringContainsString($file, $error->getMessage());
        $this->assertSame([], $this->entries($this->workspace));
    }

    public function testATargetOccupiedByADirectoryFailsWithoutLeavingATempFileBehind(): void
    {
        $file = $this->workspace.'/config.php';
        mkdir($file);

        $error = $this->failedWrite(\RuntimeException::class, $file);

        $this->assertStringContainsString($file, $error->getMessage());
        // Here the temp file really is created next to the target, so this is the
        // failure that would leave one lying around.
        $this->assertSame(['config.php'], $this->entries($this->workspace));
        $this->assertSame([], $this->entries($file));
    }

    public function testAnUnwritableTargetDirectoryFails(): void
    {
        $dir = $this->workspace.'/readonly';
        mkdir($dir);
        chmod($dir, 0555);

        try {
            $this->requireUnwritable($dir);

            $error = $this->failedWrite(\RuntimeException::class, $dir.'/config.php');

            $this->assertStringContainsString($dir, $error->getMessage());
            $this->assertSame([], $this->entries($dir));
        } finally {
            chmod($dir, 0755);
        }
    }

    public function testAnUnwritableTargetDirectoryStillRewritesAFileThatIsAlreadyThere(): void
    {
        // A hardened installation can leave the directory itself unwritable while
        // the configuration inside it stays writable. No move can be staged there,
        // so the write degrades to the plain one it replaced - not atomic, and
        // documented as such, but the settings screen still saves rather than
        // failing over a permission the write never needed before.
        $dir = $this->workspace.'/readonly';
        mkdir($dir);

        $file = $dir.'/config.php';
        file_put_contents($file, self::OLD_CONFIG);
        chmod($file, 0600);
        chmod($dir, 0555);

        try {
            $this->requireUnwritable($dir);

            $staged = $this->stagedFiles();

            $this->file->dumpAtomic($file, self::NEW_CONFIG);

            $this->assertSame(self::NEW_CONFIG, file_get_contents($file));
            $this->assertSame(0600, $this->permissions($file));
            $this->assertSame(['config.php'], $this->entries($dir));
            $this->assertSame($staged, $this->stagedFiles());
        } finally {
            chmod($dir, 0755);
        }
    }

    #[DataProvider('provideNonLocalPaths')]
    public function testAPathThatIsNotALocalFileIsRefused(string $path): void
    {
        $this->failedWrite(\InvalidArgumentException::class, $path);

        $this->assertSame([], $this->entries($this->workspace));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideNonLocalPaths(): array
    {
        return [
            'a remote url' => ['ftp://example.com/config.php'],
            'a stream wrapper' => ['php://temp'],
            'no path at all' => [''],
        ];
    }

    public function testAnAdapterBackedPathIsNeverDegradedToALocalWrite(): void
    {
        // The adapter resolves store://config.php to a file in the workspace, but
        // a write addressed through the adapter cannot be moved into place, so it
        // is refused instead of being performed non-atomically behind the caller.
        $this->file->registerAdapter('store', new StreamAdapter($this->workspace));

        $this->failedWrite(\InvalidArgumentException::class, 'store://config.php');

        $this->assertSame([], $this->entries($this->workspace));
    }

    /**
     * Runs a write that must not succeed and returns the error it raised.
     *
     * @param class-string<\Throwable> $expected
     */
    private function failedWrite(string $expected, string $file): \Throwable
    {
        try {
            $this->file->dumpAtomic($file, self::NEW_CONFIG);
        } catch (\Throwable $error) {
            $this->assertInstanceOf($expected, $error);

            return $error;
        }

        $this->fail("Writing to '$file' must not succeed.");
    }

    /**
     * Skips when the test user writes into the directory regardless of its
     * permissions - root does, and so does a platform that does not carry the
     * permission bits in the first place.
     */
    private function requireUnwritable(string $dir): void
    {
        if (is_writable($dir)) {
            $this->markTestSkipped('The test user writes into a read-only directory on this host');
        }
    }

    /**
     * Probes whether the platform lets a file that is held open be replaced by a
     * move, which is what makes the write observable as a single step.
     */
    private function canRenameOverAnOpenFile(): bool
    {
        $source = $this->workspace.'/probe-source';
        $target = $this->workspace.'/probe-target';

        file_put_contents($source, '');
        file_put_contents($target, '');

        $reader = fopen($target, 'r');
        $renamed = $reader !== false && @rename($source, $target);

        if ($reader !== false) {
            fclose($reader);
        }

        @unlink($source);
        @unlink($target);

        return $renamed;
    }

    private function permissions(string $file): int
    {
        clearstatcache(true, $file);

        $permissions = fileperms($file);
        $this->assertNotFalse($permissions, "Permissions of $file could not be read.");

        return $permissions & 0777;
    }

    /**
     * Lists what a directory holds, so that a temp file left behind by a failed
     * write shows up as an unexpected entry.
     *
     * @return array<int, string>
     */
    private function entries(string $dir): array
    {
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }

    /**
     * Lists the staging files of a write, which the platform puts in the system
     * temp directory when the target's own directory refuses to hold one, so a
     * file left behind there shows up as an entry that was not there before.
     *
     * @return array<int, string>
     */
    private function stagedFiles(): array
    {
        $files = glob(sys_get_temp_dir().'/dump*') ?: [];
        sort($files);

        return $files;
    }
}
