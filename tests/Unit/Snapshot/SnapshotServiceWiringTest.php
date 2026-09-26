<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Pagekit\Application;
use Pagekit\Database\Connection;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Package\Package;
use Pagekit\Package\PackageModule;
use Pagekit\Package\Snapshot\PackageSnapshotter;
use Pagekit\Package\Snapshot\RestoreRefusedException;
use Pagekit\Package\Snapshot\SnapshotStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The snapshotter the package module registers, and the window it keeps snapshots for.
 */
final class SnapshotServiceWiringTest extends TestCase
{
    use SnapshotDatabase;

    private const DAY = 86400;

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

        file_put_contents($this->tree.'/composer.json', (string) json_encode([
            'name' => 'pagekit/test-ext',
            'type' => 'pagekit-extension',
            'version' => '1.4.2',
        ]));
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
        // this one and the filesystem that archived the package tree.
        self::assertSame([$id], $this->entries($this->snapshots));
        self::assertFileExists($this->file($id, SnapshotStore::METADATA_FILE));
        self::assertFileExists($this->file($id, SnapshotStore::DUMP_FILE));
        self::assertFileExists($this->file($id, SnapshotStore::FILES_DIR).'/pagekit/test-ext/composer.json');
    }

    public function testTheSnapshotterTheInstallationBuildsPutsASnapshotBackAsWell(): void
    {
        // Taking a snapshot and putting one back read and write the same database
        // through two different collaborators, so a service built with only the
        // first of them wired up would keep taking snapshots that nothing can be
        // restored from - and it would go on doing that until the day one was
        // needed.
        $services = $this->services();
        $connection = $services['db'];

        self::assertInstanceOf(Connection::class, $connection);

        $this->plantATable($connection);

        $app = $this->boot($services);
        $snapshotter = $app->get('snapshotter');

        self::assertInstanceOf(PackageSnapshotter::class, $snapshotter);

        $id = $snapshotter->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        self::assertSame('', (new SnapshotStore($this->snapshots, new Filesystem()))->application($id));

        // The removal the snapshot was taken for: the files are out of the live
        // tree and the configuration no longer says the extension is installed.
        (new Filesystem())->delete($this->tree);
        $connection->update('pk_system_config', ['value' => '{"packages":{}}'], ['name' => 'system']);

        $snapshotter->restore($id);

        self::assertFileExists($this->tree.'/composer.json');
        self::assertSame(
            '{"packages":{"test-ext":"1.4.2"}}',
            (string) $connection->fetchOne('SELECT value FROM pk_system_config WHERE name = ?', ['system']),
        );
    }

    #[DataProvider('provideVersionsThatAreNotText')]
    public function testAVersionThatIsNotTextIsRecordedAsEmptyAndStillRestores(mixed $version): void
    {
        $services = $this->services();
        $connection = $services['db'];

        self::assertInstanceOf(Connection::class, $connection);

        $this->plantATable($connection);

        $services['version'] = $version;

        $snapshotter = $this->boot($services)->get('snapshotter');

        self::assertInstanceOf(PackageSnapshotter::class, $snapshotter);

        $id = $snapshotter->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        self::assertSame('', (new SnapshotStore($this->snapshots, new Filesystem()))->application($id));

        (new Filesystem())->delete($this->tree);
        $connection->update('pk_system_config', ['value' => '{"packages":{}}'], ['name' => 'system']);

        unset($services['version']);

        $later = $this->boot($services)->get('snapshotter');

        self::assertInstanceOf(PackageSnapshotter::class, $later);

        $later->restore($id);

        self::assertFileExists($this->tree.'/composer.json');
        self::assertSame(
            '{"packages":{"test-ext":"1.4.2"}}',
            (string) $connection->fetchOne('SELECT value FROM pk_system_config WHERE name = ?', ['system']),
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function provideVersionsThatAreNotText(): array
    {
        return [
            'a number' => [12],
            'a list' => [['1.2.43']],
            'nothing in the slot' => [null],
        ];
    }

    public function testADifferentApplicationVersionRefusesBeforeThePackageFilesReturn(): void
    {
        $services = $this->services();
        $connection = $services['db'];

        self::assertInstanceOf(Connection::class, $connection);

        $this->plantATable($connection);

        $withVersion = $services;
        $withVersion['version'] = '1.2.43';

        $snapshotter = $this->boot($withVersion)->get('snapshotter');

        self::assertInstanceOf(PackageSnapshotter::class, $snapshotter);

        $id = $snapshotter->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        self::assertSame('1.2.43', (new SnapshotStore($this->snapshots, new Filesystem()))->application($id));

        (new Filesystem())->delete($this->tree);
        $connection->update('pk_system_config', ['value' => '{"packages":{}}'], ['name' => 'system']);

        $later = $this->boot($services)->get('snapshotter');

        self::assertInstanceOf(PackageSnapshotter::class, $later);

        $thrown = null;

        try {
            $later->restore($id);
        } catch (RestoreRefusedException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(RestoreRefusedException::class, $thrown);
        self::assertInstanceOf(\RuntimeException::class, $thrown);
        self::assertStringContainsString($id, $thrown->getMessage());
        self::assertStringContainsString(
            'It was taken on application "1.2.43" and this installation runs "".',
            $thrown->getMessage(),
        );
        self::assertFileDoesNotExist($this->tree.'/composer.json');
        self::assertSame(
            '{"packages":{}}',
            (string) $connection->fetchOne('SELECT value FROM pk_system_config WHERE name = ?', ['system']),
        );
    }

    public function testTheWindowAnInstallationConfiguresIsTheOneItsSnapshotsAreKeptFor(): void
    {
        // The configured window is read where the service is built and handed to
        // the store, so a service built without it would keep every snapshot for
        // the shipped window whatever the administrator asked for.
        $snapshot = $this->takeOne($this->boot($this->services(), ['snapshots' => ['retention_days' => 7]]));

        self::assertSame($snapshot['created'] + 7 * self::DAY, $snapshot['expires']);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('provideInstallationsWithNoWindowOfTheirOwn')]
    public function testAnInstallationWithNoWindowOfItsOwnKeepsItsSnapshotsForTheShippedOne(array $config): void
    {
        // Module configuration comes out of the database, so the section can be
        // missing, not an array, or hold something no number can be read out of.
        // The window every installation ships with is the only safe reading of
        // that: no window at all would keep every snapshot forever, and zero
        // days would reclaim them at the next removal.
        $snapshot = $this->takeOne($this->boot($this->services(), $config));

        self::assertSame(
            $snapshot['created'] + SnapshotStore::DEFAULT_RETENTION_DAYS * self::DAY,
            $snapshot['expires'],
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function provideInstallationsWithNoWindowOfTheirOwn(): array
    {
        return [
            'nothing configured about snapshots' => [[]],
            'a section with no window in it' => [['snapshots' => []]],
            'a section that is not a list of settings' => [['snapshots' => 'kept forever']],
            'a window nothing can be read as a number' => [['snapshots' => ['retention_days' => 'a fortnight']]],
        ];
    }

    public function testTheWindowTheModuleShipsIsTheOneAStoreFallsBackTo(): void
    {
        // The shipped value is what a settings screen starts from and what a
        // database row is written over. A module shipping a different number
        // from the one the store falls back to would make the window an
        // installation is on depend on whether that row was ever written.
        $config = self::definition()['config'];

        self::assertIsArray($config);
        self::assertSame(SnapshotStore::DEFAULT_RETENTION_DAYS, $config['snapshots']['retention_days'] ?? null);
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
     * Boots the package module the way the framework boots it, against a
     * container holding the given services.
     *
     * @param array<string, mixed> $services
     * @param array<string, mixed> $config   what this installation configures
     *                                       about the module, which is what its
     *                                       database holds over the shipped
     *                                       defaults
     */
    private function boot(array $services, array $config = []): Application
    {
        $app = new Application();

        foreach ($services as $id => $service) {
            $app->set($id, $service);
        }

        (new PackageModule([
            'name' => 'package',
            'path' => self::packagePath(),
            'config' => $config,
        ]))->main($app);

        return $app;
    }

    /**
     * The module as the installation ships it, read out of the definition every
     * boot loads.
     *
     * @return array<string, mixed>
     */
    private static function definition(): array
    {
        return require self::packagePath().'/index.php';
    }

    private static function packagePath(): string
    {
        return strtr(dirname(__DIR__, 3), '\\', '/').'/app/package';
    }

    /**
     * Takes a snapshot through the service the installation built, and hands
     * back what that snapshot says about itself.
     *
     * @return array<string, mixed>
     */
    private function takeOne(Application $app): array
    {
        $snapshotter = $app->get('snapshotter');

        self::assertInstanceOf(PackageSnapshotter::class, $snapshotter);

        $id = $snapshotter->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);
        $snapshots = $snapshotter->list();

        self::assertArrayHasKey($id, $snapshots);

        return $snapshots[$id];
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

    /**
     * A table with something in it worth getting back, so the dump the service
     * writes is one a restore has something to put back out of.
     */
    private function plantATable(Connection $connection): void
    {
        $config = new Table('pk_system_config');
        $config->addColumn('name', Types::STRING, ['length' => 64]);
        $config->addColumn('value', Types::TEXT, ['notnull' => false]);
        $config->setPrimaryKey(['name']);

        $connection->createSchemaManager()->createTable($config);
        $connection->insert('pk_system_config', ['name' => 'system', 'value' => '{"packages":{"test-ext":"1.4.2"}}']);
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
