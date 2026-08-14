<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Pagekit\Application;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Package\Package;
use Pagekit\Installer\Package\Snapshot\PackageSnapshotter;
use Pagekit\Installer\Package\Snapshot\SnapshotStore;
use Pagekit\Module\Module;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Whether an installation has a way back to offer is a question about its
 * container: the snapshotter is defined where there is somewhere to keep a
 * snapshot and a database to dump into it, and nowhere else.
 *
 * That makes the presence of the service the whole of the answer a removal gets.
 * A service defined against an installation that cannot actually produce a
 * snapshot would turn every removal in it into a refusal; one left undefined
 * where a snapshot could have been taken would let a package be destroyed with
 * nothing put aside first. Both are decided here rather than by whoever removes
 * a package, so this is asserted against the module definition the installation
 * boots and not against a container assembled to suit.
 */
final class SnapshotServiceWiringTest extends TestCase
{
    use SnapshotDatabase;

    /**
     * Composer's record as it lies under packages/. Captured into a snapshot for
     * a package Composer installed, which is what says the service was handed
     * the directory the packages actually live in.
     */
    private const BOOKKEEPING = '[{"name":"pagekit/test-ext","version":"1.4.2","type":"pagekit-extension"}]';

    private string $workspace;

    private string $snapshots;

    private string $packages;

    private string $tree;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_snapshot_wiring_'.getmypid().'_'.uniqid();
        $this->snapshots = $this->workspace.'/tmp/snapshots';
        $this->packages = $this->workspace.'/packages';
        $this->tree = $this->packages.'/pagekit/test-ext';

        mkdir($this->tree, 0755, true);
        mkdir($this->packages.'/composer', 0755, true);

        file_put_contents($this->tree.'/composer.json', (string) json_encode([
            'name' => 'pagekit/test-ext',
            'type' => 'pagekit-extension',
            'version' => '1.4.2',
        ]));
        file_put_contents($this->packages.'/composer/installed.json', self::BOOKKEEPING);
    }

    protected function tearDown(): void
    {
        $this->closeDatabases();
        $this->removeTree($this->workspace);
    }

    public function testAnInstallationWithSomewhereToKeepASnapshotHasOneToOffer(): void
    {
        $app = $this->boot($this->services());

        self::assertTrue($app->has('snapshotter'));
        self::assertInstanceOf(PackageSnapshotter::class, $app->get('snapshotter'));
    }

    public function testASnapshotLandsWhereTheBootSaysSnapshotsGo(): void
    {
        $app = $this->boot($this->services());

        $snapshotter = $app->get('snapshotter');

        self::assertInstanceOf(PackageSnapshotter::class, $snapshotter);

        $id = $snapshotter->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        // Every collaborator the service is built from shows up here: the
        // directory the snapshots are kept in, the database that was dumped into
        // this one, the filesystem that archived the package tree, and the
        // packages directory Composer's record was read out of.
        self::assertSame([$id], $this->entries($this->snapshots));
        self::assertFileExists($this->file($id, SnapshotStore::METADATA_FILE));
        self::assertFileExists($this->file($id, SnapshotStore::DUMP_FILE));
        self::assertFileExists($this->file($id, SnapshotStore::FILES_DIR).'/pagekit/test-ext/composer.json');
        self::assertSame(self::BOOKKEEPING, (string) file_get_contents($this->file($id, SnapshotStore::INSTALLED_FILE)));
    }

    /**
     * @param array<int, string> $absent
     */
    #[DataProvider('provideInstallationsWithNoWayBack')]
    public function testAnInstallationThatCannotKeepASnapshotOffersNone(array $absent): void
    {
        // The installer runs before there is a database to dump, and a removal
        // asked for there has to go ahead rather than be refused for the whole
        // life of that environment. Leaving the service undefined is how it is
        // told apart from an installation whose store merely failed.
        $services = $this->services();

        foreach ($absent as $id) {
            unset($services[$id]);
        }

        self::assertFalse($this->boot($services)->has('snapshotter'));
    }

    /**
     * @return array<string, array{0: array<int, string>}>
     */
    public static function provideInstallationsWithNoWayBack(): array
    {
        return [
            'nowhere to keep a snapshot' => [['path.snapshots']],
            'no database to dump' => [['db']],
            'neither' => [['path.snapshots', 'db']],
        ];
    }

    /**
     * Boots the installer module the way the framework boots it, against a
     * container holding the given services.
     *
     * @param array<string, mixed> $services
     */
    private function boot(array $services): Application
    {
        $app = new Application();

        foreach ($services as $id => $service) {
            $app->set($id, $service);
        }

        $installer = strtr(dirname(__DIR__, 3), '\\', '/').'/app/installer';
        $definition = require $installer.'/index.php';

        // Not enabled: what registers the snapshotter runs in every environment
        // the module is loaded in, and the installer's own routes and assets are
        // another concern entirely.
        (new Module([
            'name' => 'installer',
            'path' => $installer,
            'config' => ['enabled' => false],
            'main' => $definition['main'],
        ]))->main($app);

        return $app;
    }

    /**
     * What the container of an installation that can be snapshotted holds.
     *
     * @return array<string, mixed>
     */
    private function services(): array
    {
        return [
            'path.snapshots' => $this->snapshots,
            'path.packages' => $this->packages,
            'file' => new Filesystem(),
            'log' => new NullLogger(),
            'db' => $this->openDatabase(),
        ];
    }

    private function package(): Package
    {
        return new Package([
            'name' => 'pagekit/test-ext',
            'type' => 'pagekit-extension',
            'module' => 'test-ext',
            'title' => 'Test Extension',
            'version' => '1.4.2',
            'path' => $this->tree,
        ]);
    }

    private function file(string $id, string $name): string
    {
        return $this->snapshots.'/'.$id.'/'.$name;
    }

    /**
     * @return array<int, string>
     */
    private function entries(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

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
