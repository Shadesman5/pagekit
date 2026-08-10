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
 * and leaves no temp file behind, and a target no move can reach - one the write
 * cannot be staged beside, one behind a link that leads nowhere, one that is no
 * plain local path - is refused rather than written some other way.
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
            $this->markTestSkipped('This platform refuses to move a file that is held open, where the write fails instead of replacing it');
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

    public function testALinkToAFileThatIsNotThereYetIsFollowedRatherThanReplaced(): void
    {
        // Before its first write, an installation whose configuration lives on a
        // data volume has the link in place and nothing at the end of it. A write
        // that took the missing file for a reason to replace the link would put a
        // regular file in the application root and leave the volume empty.
        $data = $this->workspace.'/data';
        mkdir($data);

        $target = $data.'/config.php';
        $link = $this->workspace.'/config.php';

        if (!@symlink($target, $link)) {
            $this->markTestSkipped('This host does not link to a file that is not there yet');
        }

        try {
            $this->file->dumpAtomic($link, self::NEW_CONFIG);

            $this->assertTrue(is_link($link));
            $this->assertSame(self::NEW_CONFIG, file_get_contents($target));
            $this->assertSame(['config.php'], $this->entries($data));
        } finally {
            unlink($link);
        }
    }

    public function testALinkThatLeadsToNoFileAtAllFailsTheWrite(): void
    {
        // A link pointing back at itself has no file at the end of it to write.
        // Falling back to the link's own path would replace the link with a
        // regular file, which is the one outcome a write through a link must not
        // have, so it fails instead.
        $link = $this->workspace.'/config.php';

        if (!@symlink($link, $link)) {
            $this->markTestSkipped('This host does not link a path to itself');
        }

        try {
            $error = $this->failedWrite(\RuntimeException::class, $link);

            $this->assertStringContainsString($link, $error->getMessage());
            $this->assertTrue(is_link($link));
        } finally {
            unlink($link);
        }
    }

    #[DataProvider('provideCreateModes')]
    public function testACreatedFileGetsTheModeAPlainWriteWouldGiveIt(?int $mode, int $expected): void
    {
        $this->requirePermissionBits();

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
        $this->requirePermissionBits();

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

        $staged = $this->stagedFiles();

        $error = $this->failedWrite(\RuntimeException::class, $file);

        $this->assertStringContainsString($file, $error->getMessage());
        $this->assertSame([], $this->entries($this->workspace));
        // There is no directory beside the target to stage in, so this is the
        // failure that puts the staging file in the system temp directory.
        $this->assertSame($staged, $this->stagedFiles());
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
            $this->requireStagingRefused($dir);

            $staged = $this->stagedFiles();

            $error = $this->failedWrite(\RuntimeException::class, $dir.'/config.php');

            $this->assertStringContainsString($dir, $error->getMessage());
            $this->assertSame([], $this->entries($dir));
            $this->assertSame($staged, $this->stagedFiles());
        } finally {
            chmod($dir, 0755);
        }
    }

    public function testAWriteThatCannotBeStagedLeavesTheFileThatIsAlreadyThereAsItIs(): void
    {
        // A hardened installation can leave the directory itself unwritable while
        // the configuration inside it stays writable. No move can be staged there,
        // and rewriting the file in place instead is the very half-written state a
        // reader running require cannot survive - so the write fails and the
        // configuration the site is serving from stays whole.
        $dir = $this->workspace.'/readonly';
        mkdir($dir);

        $file = $dir.'/config.php';
        file_put_contents($file, self::OLD_CONFIG);
        chmod($dir, 0555);

        try {
            $this->requireStagingRefused($dir);

            $staged = $this->stagedFiles();

            $error = $this->failedWrite(\RuntimeException::class, $file);

            $this->assertStringContainsString($file, $error->getMessage());
            $this->assertSame(self::OLD_CONFIG, file_get_contents($file));
            $this->assertSame(['config.php'], $this->entries($dir));
            $this->assertSame($staged, $this->stagedFiles());
        } finally {
            chmod($dir, 0755);
        }
    }

    public function testADirectoryThatAcceptsNoStagingFileFailsTheWrite(): void
    {
        // With nowhere to stage the content there is no second way to write that
        // is still a single step - rewriting the target in place is the very
        // half-written state this write exists to prevent - so the write is
        // refused and nothing is left in the directory.
        $file = $this->workspace.'/config.php';

        $error = $this->failedWrite(\RuntimeException::class, $file, $this->filesystemThatStagesNothing());

        $this->assertStringContainsString($file, $error->getMessage());
        $this->assertSame([], $this->entries($this->workspace));
    }

    public function testADirectoryThatAcceptsNoStagingFileLeavesAnExistingTargetAsItIs(): void
    {
        // A write that never gets off the ground leaves the configuration the
        // site is serving from where it was, whole and with no temp file beside it.
        $file = $this->workspace.'/config.php';
        file_put_contents($file, self::OLD_CONFIG);

        $error = $this->failedWrite(\RuntimeException::class, $file, $this->filesystemThatStagesNothing());

        $this->assertStringContainsString($file, $error->getMessage());
        $this->assertSame(self::OLD_CONFIG, file_get_contents($file));
        $this->assertSame(['config.php'], $this->entries($this->workspace));
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
     * @param Filesystem|null          $filesystem the filesystem to write through, where it is not the default one
     */
    private function failedWrite(string $expected, string $file, ?Filesystem $filesystem = null): \Throwable
    {
        try {
            ($filesystem ?? $this->file)->dumpAtomic($file, self::NEW_CONFIG);
        } catch (\Throwable $error) {
            $this->assertInstanceOf($expected, $error);

            return $error;
        }

        $this->fail("Writing to '$file' must not succeed.");
    }

    /**
     * A filesystem whose staging never produces a file, which is how a directory
     * that accepts none at all reaches the write. A real one cannot be talked
     * into that portably - root and Windows create a file in a read-only
     * directory all the same - so the call that stages is stood in for instead.
     */
    private function filesystemThatStagesNothing(): Filesystem
    {
        return new class () extends Filesystem {
            protected function createStagingFile(string $directory): string|false
            {
                return false;
            }
        };
    }

    /**
     * Skips where a file can still be created in the directory, which is the
     * only thing that keeps a write from being staged there. Root creates one
     * regardless of the permission bits, and a platform that answers a
     * read-only directory with a flag rather than with a refusal (Windows) lets
     * the write happen as well - so a failure over staging cannot be provoked
     * there, and pretending otherwise would test the guard instead of the write.
     */
    private function requireStagingRefused(string $dir): void
    {
        $probe = $dir.'/probe-staging';

        if (@file_put_contents($probe, '') !== false) {
            unlink($probe);

            $this->markTestSkipped('This host creates files in a read-only directory, where a write can be staged after all');
        }
    }

    /**
     * Skips where the platform does not keep the permission bits of a file -
     * Windows carries a read-only flag and reports the rest as a mode nobody
     * set, so there is nothing there for a write to preserve or to narrow.
     */
    private function requirePermissionBits(): void
    {
        $probe = $this->workspace.'/probe-permissions';

        file_put_contents($probe, '');
        $kept = @chmod($probe, 0640) && $this->permissions($probe) === 0640;
        unlink($probe);

        if (!$kept) {
            $this->markTestSkipped('This platform does not keep the permission bits of a file');
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
     * The name is shortened to the first three letters of the prefix where the
     * platform does that (Windows), so both spellings are collected.
     *
     * @return array<int, string>
     */
    private function stagedFiles(): array
    {
        $files = glob(sys_get_temp_dir().'/dum*') ?: [];
        sort($files);

        return $files;
    }
}
