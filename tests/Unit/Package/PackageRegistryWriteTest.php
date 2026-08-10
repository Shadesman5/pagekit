<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Helper\Composer;
use PHPUnit\Framework\TestCase;

/**
 * packages.php is the list of everything an installation has taken from the
 * marketplace, and it is read back with require - by the next install, and by
 * the Composer run behind it. The list is rewritten at the very end of an
 * install or an uninstall, when the packages it names are already on disk, so a
 * file that arrives half-written is a fatal error on the next request, and a
 * write that quietly does not happen leaves the installation with a list that no
 * longer matches its own vendor directory.
 */
final class PackageRegistryWriteTest extends TestCase
{
    /**
     * The set of installed packages on its way into packages.php.
     */
    private const INSTALLED = [
        'pagekit/blog' => '~1.0',
        'pagekit/hello' => '1.2.3',
    ];

    private string $workspace;

    /**
     * The vendor directory the registry lives in (path.packages).
     */
    private string $vendor;

    private string $registry;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_package_registry_'.getmypid().'_'.uniqid();
        $this->vendor = $this->workspace.'/packages';
        $this->registry = $this->vendor.'/packages.php';

        mkdir($this->vendor, 0755, true);
    }

    protected function tearDown(): void
    {
        // A test that provoked a vendor directory nobody can write has to hand it
        // back before the workspace can be removed.
        if (is_dir($this->vendor)) {
            chmod($this->vendor, 0755);
        }

        if (is_file($this->registry)) {
            chmod($this->registry, 0644);
        }

        $this->removeTree($this->workspace);
    }

    public function testTheRegistryReadsBackAsTheListOfPackagesThatWasWritten(): void
    {
        $this->helper(new Filesystem())->writeRegistry(self::INSTALLED);

        $written = require $this->registry;

        self::assertSame(self::INSTALLED, $written);
        self::assertSame(
            self::INSTALLED,
            $this->helper()->readRegistry(),
            'The next package operation reads the list off the file, not out of the helper that wrote it',
        );
    }

    public function testTheRegistryIsWrittenThroughTheWriterTheHelperWasGiven(): void
    {
        // A booted application hands the helper its own filesystem service. A
        // helper writing the registry some other way would lose what that service
        // does with a file of PHP - dropping the compiled copy of the previous
        // list out of the opcode cache, above all.
        $writer = new RecordingFilesystem();

        $this->helper($writer)->writeRegistry(self::INSTALLED);

        self::assertSame([$this->registry], $writer->written);
        self::assertSame(self::INSTALLED, $this->helper()->readRegistry());
    }

    public function testAnExistingRegistryIsReplacedWholeAndLeavesNoTempFileBehind(): void
    {
        $this->writeRegistryFile(['pagekit/blog' => '~0.9']);

        $this->helper(new Filesystem())->writeRegistry(self::INSTALLED);

        self::assertSame(self::INSTALLED, $this->helper()->readRegistry());
        self::assertSame(
            ['packages.php'],
            $this->entries($this->vendor),
            'The file the new list is staged in is moved into place, never left behind in the vendor directory',
        );
    }

    /**
     * A vendor directory the web server may read and not write is an ordinary
     * deployment, and the packages named in the registry are already installed by
     * the time it is written. Losing that write without a word would leave the
     * installation running a list of packages it no longer has.
     */
    public function testARegistryThatCannotBeWrittenIsReportedInsteadOfSilentlyLost(): void
    {
        $this->writeRegistryFile(self::INSTALLED);

        chmod($this->registry, 0444);
        chmod($this->vendor, 0555);

        if (is_writable($this->vendor) || is_writable($this->registry)) {
            self::markTestSkipped('The test user writes into a read-only directory on this host');
        }

        $failed = null;

        try {
            $this->helper(new Filesystem())->writeRegistry(['pagekit/blog' => '~2.0']);
        } catch (\RuntimeException $error) {
            $failed = $error;
        }

        self::assertInstanceOf(\RuntimeException::class, $failed, 'A registry that could not be written must not pass for a written one');
        self::assertStringContainsString($this->registry, $failed->getMessage(), 'The report has to name the file that could not be written');
        self::assertSame(
            self::INSTALLED,
            $this->helper()->readRegistry(),
            'The list installations read back survives a write that could not happen',
        );
        self::assertSame(['packages.php'], $this->entries($this->vendor));
    }

    public function testAHelperBuiltWithoutAWriterWritesTheRegistryItself(): void
    {
        // The helper is also built straight from a path configuration, by console
        // commands that have no application to take a filesystem service from.
        $this->helper()->writeRegistry(self::INSTALLED);

        self::assertSame(self::INSTALLED, $this->helper()->readRegistry());
        self::assertSame(['packages.php'], $this->entries($this->vendor));
    }

    /**
     * The helper as the package manager builds it: the paths it works in and, when
     * the application has one, the filesystem service that performs the write.
     */
    private function helper(?Filesystem $writer = null): RegistryComposer
    {
        return new RegistryComposer([
            'path.packages' => $this->vendor,
            'path.artifact' => $this->workspace.'/artifact',
            'system.api' => 'https://example.test',
        ], null, $writer);
    }

    /**
     * @param array<string, string> $packages
     */
    private function writeRegistryFile(array $packages): void
    {
        file_put_contents($this->registry, '<?php return '.var_export($packages, true).';');
    }

    /**
     * Lists what a directory holds, so a temp file left behind by a write shows up
     * as an unexpected entry.
     *
     * @return array<int, string>
     */
    private function entries(string $dir): array
    {
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
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

/**
 * The registry write on its own. install() and uninstall() reach it only behind a
 * Composer run that resolves and downloads packages from the marketplace.
 */
final class RegistryComposer extends Composer
{
    /**
     * @param array<string, string> $packages
     */
    public function writeRegistry(array $packages): void
    {
        $this->packages = $packages;

        $this->writeConfig();
    }

    /**
     * @return array<string, string>
     */
    public function readRegistry(): array
    {
        return $this->readConfig();
    }
}

/**
 * Notes down what it is asked to write, and then writes it.
 */
final class RecordingFilesystem extends Filesystem
{
    /** @var array<int, string> */
    public array $written = [];

    public function dumpAtomic(string $file, string $content, ?int $mode = null): void
    {
        $this->written[] = $file;

        parent::dumpAtomic($file, $content, $mode);
    }
}
