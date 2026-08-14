<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Package\Snapshot\SnapshotStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The store holds what a removal took away, which puts two demands on it that
 * weigh more than the round trip.
 *
 * A snapshot that cannot be written must leave nothing behind that a later
 * restore would read as one. Its directory is the only copy of a package that
 * is no longer on disk and of the database as it stood before, so the moment
 * anything about writing it fails, the caller has to be told plainly enough to
 * call off the removal - and what the failed attempt made has to be gone.
 *
 * And an id is the one part of a snapshot's address that comes from outside:
 * every restore and every purge is a request naming one. The store resolves an
 * id directly under its own directory, so an id that is a traversal, an
 * absolute path or a separator has to be refused before it is ever a path,
 * whatever the caller does with the answer.
 */
final class SnapshotStoreTest extends TestCase
{
    /**
     * What a snapshot's own description is called on disk. Tests putting a
     * damaged or foreign one in place have to write the file the store reads.
     */
    private const METADATA = 'metadata.json';

    /**
     * The shape a caller reads a snapshot in. The admin list renders every
     * field, and a restore reads the database section to decide whether the
     * dump can be replayed at all.
     */
    private const FIELDS = [
        'id', 'created', 'expires', 'size', 'package', 'module', 'title',
        'type', 'version', 'composer', 'reason', 'format', 'database',
    ];

    private const DAY = 86400;

    /**
     * The window the store keeps a snapshot for where nothing configures one.
     */
    private const DEFAULT_DAYS = 30;

    private string $workspace;

    /**
     * The store's own directory. Deliberately not created in setUp(): an
     * installation that never removed a package has none, and the first
     * snapshot has to land all the same.
     */
    private string $path;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_snapshots_'.getmypid().'_'.uniqid();
        $this->path = $this->workspace.'/snapshots';

        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        // A test that provoked a directory nobody can write has to hand it back
        // before the workspace can be removed.
        $this->restoreModes($this->workspace);
        $this->removeTree($this->workspace);
    }

    public function testASnapshotIsADirectoryOfItsOwnUnderTheIdItIsHandedBackAs(): void
    {
        $store = $this->store();

        $id = $store->create($this->details());

        self::assertTrue($store->isValidId($id), 'The store has to accept back the id it handed out');
        self::assertSame($this->path.'/'.$id, $store->directory($id));
        self::assertDirectoryExists($store->directory($id));
        // Opening a snapshot writes its description and nothing else: the dump
        // and the archived files are put in by whoever produces them.
        self::assertSame([self::METADATA], $this->entries($store->directory($id)));
    }

    public function testTheStoreCreatesItsOwnDirectoryForTheFirstSnapshotTakenInIt(): void
    {
        // An installation that never removed a package has no such directory,
        // and the removal that needs one cannot wait for an administrator.
        self::assertDirectoryDoesNotExist($this->path);

        $id = $this->store()->create($this->details());

        self::assertSame([$id], $this->entries($this->path));
    }

    public function testAnIdSaysWhenASnapshotWasTakenAndWhatOf(): void
    {
        // The id is what an administrator picks a snapshot out by, and it is
        // the directory name. A UTC timestamp in front puts the list in the
        // order the snapshots were taken wherever the installation thinks it
        // is; the module follows so the row can be recognised; the random tail
        // keeps two removals in the same second apart.
        $before = gmdate('Ymd-His');
        $id = $this->store()->create($this->details('blog'));
        $after = gmdate('Ymd-His');

        self::assertMatchesRegularExpression('/^\d{8}-\d{6}-blog-[0-9a-f]{8}\z/', $id);
        self::assertGreaterThanOrEqual($before, substr($id, 0, 15));
        self::assertLessThanOrEqual($after, substr($id, 0, 15));
    }

    public function testTwoSnapshotsOfTheSameModuleDoNotShareADirectory(): void
    {
        // Two removals can land in the same second - a dependency cleanup
        // removing several packages does exactly that - and a second snapshot
        // written into the first one's directory would destroy it.
        $store = $this->store();

        $first = $store->create($this->details('blog'));
        $second = $store->create($this->details('blog'));

        self::assertNotSame($first, $second);
        self::assertCount(2, $store->list());
    }

    #[DataProvider('provideModuleNames')]
    public function testANameThatIsNoIdIsReducedToOneRatherThanCostingTheSnapshot(string $module, string $label): void
    {
        // The name comes out of a package manifest, so the store has no say in
        // it. A snapshot may not fail to be taken over a character in a label.
        $store = $this->store();

        $id = $store->create($this->details($module));

        self::assertTrue($store->isValidId($id));
        self::assertStringContainsString('-'.$label.'-', $id);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideModuleNames(): array
    {
        return [
            'a vendor and a name' => ['pagekit/blog', 'pagekit-blog'],
            'characters no path should carry' => ['Acme\\Weird Thing!', 'Acme-Weird-Thing'],
            'a name that is nothing but separators' => ['///', 'package'],
            'no name at all' => ['', 'package'],
            // Cut to what keeps the path short on every filesystem, and cut
            // without leaving the dash the removed half began with.
            'a name longer than an id carries' => [str_repeat('a', 31).'/tail', str_repeat('a', 31)],
        ];
    }

    public function testASnapshotDescribesWhatItIsOfAndWhichDatabaseItCameFrom(): void
    {
        $store = $this->store();
        $taken = time();

        $id = $store->create($this->details('blog'));
        $snapshot = $store->get($id);

        self::assertNotNull($snapshot);
        self::assertSame(self::FIELDS, array_keys($snapshot));
        self::assertSame($id, $snapshot['id']);
        self::assertSame('pagekit/blog', $snapshot['package']);
        self::assertSame('blog', $snapshot['module']);
        self::assertSame('Blog', $snapshot['title']);
        self::assertSame('pagekit-extension', $snapshot['type']);
        self::assertSame('1.4.2', $snapshot['version']);
        self::assertTrue($snapshot['composer']);
        self::assertSame('uninstall', $snapshot['reason']);
        self::assertSame(1, $snapshot['format']);
        // A dump can only be replayed into the driver it was taken from, so
        // what took it is recorded with it rather than guessed at restore time.
        self::assertSame(['driver' => 'pdo_sqlite', 'platform' => 'sqlite', 'prefix' => 'pk_'], $snapshot['database']);
        self::assertGreaterThanOrEqual($taken, $snapshot['created']);
        self::assertGreaterThan(0, $snapshot['size']);
    }

    public function testTheDescriptionOfASnapshotIsDataThatNoBootCanRunAsCode(): void
    {
        $store = $this->store();

        $id = $store->create($this->details());

        $raw = (string) file_get_contents($store->directory($id).'/'.self::METADATA);

        // The file is read back on an admin page and by a restore. As PHP it
        // would be executed by whoever read it, which makes a tampered or
        // half-written snapshot a way into the installation.
        self::assertStringNotContainsString('<?php', $raw);
        self::assertIsArray(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testTheDescriptionIsWrittenInOneStepAndNothingIsStagedBesideIt(): void
    {
        // A snapshot directory is inventoried the moment it exists, so a
        // description that could be read half-written would show up as a
        // snapshot describing nothing - and a file staged for the write and
        // left behind would be counted as part of what the snapshot holds.
        $writer = new MetadataWriteRecorder();
        $store = new SnapshotStore($this->path, $writer);

        $id = $store->create($this->details());

        self::assertSame([$this->path.'/'.$id.'/'.self::METADATA], $writer->written);
        self::assertSame([self::METADATA], $this->entries($store->directory($id)));
    }

    public function testNothingInASnapshotIsReadableToAnyoneButTheAccountThatTookIt(): void
    {
        $this->requirePermissionBits();

        $store = $this->store();

        $id = $store->create($this->details());

        // The dump that lands beside this description holds every password
        // hash on the site, and the snapshot is kept for weeks. On a shared
        // host the account next door is the one this keeps out.
        self::assertSame(0, fileperms($store->directory($id)) & 0077);
        self::assertSame(0, fileperms($store->directory($id).'/'.self::METADATA) & 0077);
    }

    public function testATitleThatIsNoValidUtf8IsKeptRatherThanCostingTheSnapshot(): void
    {
        // A title comes out of a composer.json, which nothing guarantees is
        // encoded the way JSON needs. Refusing the snapshot over its decoration
        // would call off a removal for a reason nobody can act on.
        $store = $this->store();

        $id = $store->create($this->details('blog', ['title' => "Weird \xB1\x31 Blog"]));
        $snapshot = $store->get($id);

        self::assertNotNull($snapshot);
        self::assertStringContainsString('Weird', $snapshot['title']);
        self::assertStringContainsString('Blog', $snapshot['title']);
    }

    public function testASnapshotWhoseDescriptionCannotBeWrittenLeavesNothingBehind(): void
    {
        // A full disk or a read-only mount is how this happens, and it happens
        // before anything was taken away. What the attempt made is taken back
        // with it: an empty directory would be inventoried as a snapshot with
        // no dump, which is a restore that fails when it is needed.
        $failure = new \RuntimeException('Failed to write file');
        $store = new SnapshotStore($this->path, new WriteThatNeverHappens($failure));

        try {
            $store->create($this->details());

            self::fail('A snapshot whose description could not be written must not be reported as taken');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('snapshot metadata', $e->getMessage());
            self::assertSame($failure, $e->getPrevious(), 'The caller logs what actually went wrong');
        }

        self::assertSame([], $this->entries($this->path));
        self::assertSame([], $store->list());
    }

    public function testADescriptionThatCannotBeEncodedIsReportedRatherThanWrittenAsNothing(): void
    {
        // Encoding reports what it cannot represent by handing back no string
        // rather than by raising, and a store writing that out would leave a
        // directory whose description is the word "false". No caller can build
        // a description that carries itself, so the value is passed in here to
        // reach the guard against one.
        $store = $this->store();

        $details = $this->details();
        $details['database']['self'] = &$details;

        try {
            $store->create($details);

            self::fail('A description that has no JSON must not be reported as written');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('could not be encoded', (string) $e->getPrevious()?->getMessage());
        }

        self::assertSame([], $this->entries($this->path));
    }

    public function testAStoreDirectoryThatCannotBeWrittenCostsTheSnapshotAndNothingElse(): void
    {
        mkdir($this->path, 0755, true);
        chmod($this->path, 0555);

        $this->requireUnwritable($this->path);

        $store = $this->store();

        try {
            $store->create($this->details());

            self::fail('A snapshot that could not be opened must not be reported as taken');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Failed to create the snapshot directory', $e->getMessage());
        }

        self::assertSame([], $this->entries($this->path));
        self::assertSame([], $store->list());
    }

    public function testAStoreDirectoryThatCannotExistCostsTheSnapshotAndNothingElse(): void
    {
        // The directory is not always creatable: the path can be occupied by a
        // file, or sit on a volume that is not mounted. The removal this was
        // taken for is called off, which is the point of failing loudly here.
        file_put_contents($this->path, '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create the snapshot directory');

        $this->store()->create($this->details());
    }

    public function testAStoreThatWasNeverWrittenToReadsAsEmptyWithoutCreatingItself(): void
    {
        $store = $this->store();

        self::assertSame([], $store->list());
        self::assertSame([], $store->expired());
        self::assertNull($store->get('20260101-000000-blog-a1b2c3d4'));
        self::assertFalse($store->delete('20260101-000000-blog-a1b2c3d4'));
        // Reading the inventory happens on an admin page and on every removal.
        // Neither is a reason to grow a directory on disk.
        self::assertDirectoryDoesNotExist($this->path);
    }

    public function testTheGuardsThatKeepTheStoreOutOfReachAreNotSnapshots(): void
    {
        // A fresh installation ships this directory with nothing in it but the
        // two files that deny requests and keep its contents out of the
        // repository. Counted as snapshots they would be listed as rows
        // describing nothing - and the first retention purge would delete the
        // denial along with them.
        mkdir($this->path, 0755, true);
        file_put_contents($this->path.'/.htaccess', "Require all denied\n");
        file_put_contents($this->path.'/.gitignore', "*\n");

        $store = $this->store();

        self::assertSame([], $store->list());
        self::assertSame([], $store->expired());
        self::assertNull($store->get('.htaccess'));
        self::assertFalse($store->delete('.htaccess'));
        self::assertFileExists($this->path.'/.htaccess');
        self::assertFileExists($this->path.'/.gitignore');
    }

    public function testSomethingInTheStoreThatIsNoDirectoryIsNoSnapshot(): void
    {
        mkdir($this->path, 0755, true);
        file_put_contents($this->path.'/20260101-000000-blog-a1b2c3d4', 'not a snapshot');

        $store = $this->store();

        self::assertSame([], $store->list());
        self::assertNull($store->get('20260101-000000-blog-a1b2c3d4'));
    }

    public function testEverySnapshotInTheStoreIsListedNewestFirst(): void
    {
        // The list is what an administrator restores from, and the one that
        // matters after a removal that went wrong is the one just taken.
        $now = time();

        $this->place('20260101-000000-blog-a1b2c3d4', $this->description(['created' => $now - 2 * self::DAY]));
        $this->place('20260103-000000-blog-b2c3d4e5', $this->description(['created' => $now]));
        $this->place('20260102-000000-pages-c3d4e5f6', $this->description(['created' => $now - self::DAY]));

        $snapshots = $this->store()->list();

        self::assertSame([
            '20260103-000000-blog-b2c3d4e5',
            '20260102-000000-pages-c3d4e5f6',
            '20260101-000000-blog-a1b2c3d4',
        ], array_keys($snapshots));
        self::assertSame('20260103-000000-blog-b2c3d4e5', $snapshots['20260103-000000-blog-b2c3d4e5']['id']);
    }

    public function testASnapshotIsTheDirectoryItIsInAndNotTheOneItsDescriptionClaims(): void
    {
        // The description is a file inside the snapshot, so it is as trustworthy
        // as the disk it sits on. A restore or a purge following an id out of it
        // would act on a snapshot nobody named.
        $this->place('20260101-000000-blog-a1b2c3d4', $this->description(['id' => '20991231-235959-other-ffffffff']));

        $store = $this->store();
        $snapshot = $store->get('20260101-000000-blog-a1b2c3d4');

        self::assertNotNull($snapshot);
        self::assertSame('20260101-000000-blog-a1b2c3d4', $snapshot['id']);
        self::assertNull($store->get('20991231-235959-other-ffffffff'));
    }

    public function testASnapshotWithNoDescriptionLeftIsStillOnTheInventory(): void
    {
        // A removal interrupted between making the directory and describing it
        // leaves exactly this. Dropped from the inventory it would be disk
        // nothing ever reclaims and nothing ever shows.
        $taken = time() - 3 * self::DAY;
        $directory = $this->place('20260101-000000-blog-a1b2c3d4');
        touch($directory, $taken);

        $snapshot = $this->store()->get('20260101-000000-blog-a1b2c3d4');

        self::assertNotNull($snapshot);
        self::assertSame(self::FIELDS, array_keys($snapshot));
        self::assertSame($taken, $snapshot['created']);
        self::assertSame('', $snapshot['package']);
        self::assertSame(['driver' => '', 'platform' => '', 'prefix' => ''], $snapshot['database']);
        self::assertArrayHasKey('20260101-000000-blog-a1b2c3d4', $this->store()->list());
    }

    #[DataProvider('provideDescriptionsThatCannotBeUnderstood')]
    public function testADescriptionThatCannotBeUnderstoodLeavesTheSnapshotOnTheInventory(string $content): void
    {
        $this->place('20260101-000000-blog-a1b2c3d4', $content);

        $snapshot = $this->store()->get('20260101-000000-blog-a1b2c3d4');

        self::assertNotNull($snapshot, 'A snapshot that cannot describe itself can still be purged');
        self::assertSame('20260101-000000-blog-a1b2c3d4', $snapshot['id']);
        self::assertSame('', $snapshot['package']);
        self::assertGreaterThan(0, $snapshot['created'], 'When it was taken falls back to the directory itself');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideDescriptionsThatCannotBeUnderstood(): array
    {
        return [
            'a file cut off mid-write' => ['{"package": "pagekit/bl'],
            'an empty file' => [''],
            'nothing but whitespace' => ["\n \t\n"],
            'no json at all' => ['<?php return [];'],
            'a json list, where a description is a set of fields' => ['[{"package": "pagekit/blog"}]'],
            'a json value that is no description' => ['42'],
            'a json null' => ['null'],
        ];
    }

    public function testValuesOfAnotherTypeThanTheirOwnDoNotReachACallerAsOne(): void
    {
        // Whoever wrote this file is not necessarily this store, and the admin
        // list renders every field it hands back. Arriving in the declared
        // shape is what keeps each reader from guarding each field.
        $this->place('20260101-000000-blog-a1b2c3d4', '{"package": ["pagekit/blog"], "title": null, "version": 14, "format": "1", "database": "sqlite"}');

        $snapshot = $this->store()->get('20260101-000000-blog-a1b2c3d4');

        self::assertNotNull($snapshot);
        self::assertSame(self::FIELDS, array_keys($snapshot));
        self::assertSame('', $snapshot['package']);
        self::assertSame('', $snapshot['title']);
        self::assertSame('', $snapshot['version']);
        self::assertSame(0, $snapshot['format']);
        self::assertSame(['driver' => '', 'platform' => '', 'prefix' => ''], $snapshot['database']);
    }

    #[DataProvider('provideComposerClaims')]
    public function testASnapshotCountsAsComposerInstalledOnlyWhereItSaysSoOutright(string $claim, bool $composer): void
    {
        // This decides whether a restore has Composer's own bookkeeping to put
        // back. A string that merely reads as true would send a hand-installed
        // package down that path.
        $this->place('20260101-000000-blog-a1b2c3d4', $this->description(['composer' => null], $claim));

        $snapshot = $this->store()->get('20260101-000000-blog-a1b2c3d4');

        self::assertNotNull($snapshot);
        self::assertSame($composer, $snapshot['composer']);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function provideComposerClaims(): array
    {
        return [
            'installed by composer' => ['true', true],
            'not installed by composer' => ['false', false],
            'a string that reads as true' => ['"yes"', false],
            'a number that reads as true' => ['1', false],
            'nothing said either way' => ['null', false],
        ];
    }

    public function testTheWindowASnapshotIsKeptForIsCountedFromWhenItWasTaken(): void
    {
        $taken = time() - 3 * self::DAY;
        $this->place('20260101-000000-blog-a1b2c3d4', $this->description(['created' => $taken]));

        $snapshot = $this->storeKeepingFor(14)->get('20260101-000000-blog-a1b2c3d4');

        self::assertNotNull($snapshot);
        // The list shows this so an operator can see what is holding disk and
        // when it will stop.
        self::assertSame($taken + 14 * self::DAY, $snapshot['expires']);
    }

    public function testASnapshotIsExpiredOnceTheWindowItWasKeptForHasRunOut(): void
    {
        $now = time();

        $this->place('20260101-000000-blog-a1b2c3d4', $this->description(['created' => $now - 15 * self::DAY]));
        $this->place('20260102-000000-pages-b2c3d4e5', $this->description(['created' => $now - 13 * self::DAY]));

        $expired = $this->storeKeepingFor(14)->expired();

        self::assertSame(['20260101-000000-blog-a1b2c3d4'], $expired);
    }

    public function testAnInstallationThatKeepsEverySnapshotExpiresNone(): void
    {
        // Retention turned off is an installation that will not have restorable
        // data reclaimed behind its back. A purge stays available by hand.
        $this->place('20200101-000000-blog-a1b2c3d4', $this->description(['created' => time() - 3650 * self::DAY]));

        $store = $this->storeKeepingFor(0);
        $snapshot = $store->get('20200101-000000-blog-a1b2c3d4');

        self::assertSame([], $store->expired());
        self::assertNotNull($snapshot);
        self::assertNull($snapshot['expires'], 'A snapshot with no window has no date to show');
    }

    public function testASnapshotIsKeptForThirtyDaysWhereTheInstallationConfiguresNoWindow(): void
    {
        $now = time();

        $this->place('20260101-000000-blog-a1b2c3d4', $this->description(['created' => $now - (self::DEFAULT_DAYS + 1) * self::DAY]));
        $this->place('20260102-000000-pages-b2c3d4e5', $this->description(['created' => $now - (self::DEFAULT_DAYS - 1) * self::DAY]));

        self::assertSame(['20260101-000000-blog-a1b2c3d4'], $this->store()->expired());
    }

    public function testAnExpiredSnapshotIsFoundWhetherOrNotItCanDescribeItself(): void
    {
        // Retention has to reach the leftovers of an interrupted removal as
        // well, or a store nobody reads grows without bound.
        $directory = $this->place('20260101-000000-blog-a1b2c3d4');
        touch($directory, time() - 15 * self::DAY);

        self::assertSame(['20260101-000000-blog-a1b2c3d4'], $this->storeKeepingFor(14)->expired());
    }

    public function testPurgingASnapshotTakesEverythingInItWithIt(): void
    {
        $store = $this->store();

        $id = $store->create($this->details());
        file_put_contents($store->dumpFile($id), 'the database as it stood');
        mkdir($store->filesDirectory($id).'/pagekit/blog', 0700, true);
        file_put_contents($store->filesDirectory($id).'/pagekit/blog/index.php', '<?php return [];');

        self::assertTrue($store->delete($id));

        self::assertDirectoryDoesNotExist($this->path.'/'.$id);
        self::assertNull($store->get($id));
        self::assertSame([], $store->list());
    }

    public function testAPurgeReportsNothingRemovedWhereThereWasNoSnapshotToRemove(): void
    {
        // A purge is irreversible and audited. Reporting success for a snapshot
        // that was never there would write that line about nothing.
        mkdir($this->path, 0755, true);

        self::assertFalse($this->store()->delete('20260101-000000-blog-a1b2c3d4'));
    }

    public function testAPurgeThatOnlyGotPartOfTheWayIsNotReportedAsDone(): void
    {
        $store = $this->store();

        $id = $store->create($this->details());
        mkdir($store->filesDirectory($id), 0700, true);
        file_put_contents($store->filesDirectory($id).'/index.php', '<?php return [];');
        chmod($store->filesDirectory($id), 0555);

        // Removing a file needs the same permission as creating one, so a
        // directory that accepts no write is one nothing can be removed from.
        $this->requireUnwritable($store->filesDirectory($id));

        // What is left is a directory that is no longer a snapshot anybody can
        // restore from, and the caller has to hear that rather than log a purge
        // that happened.
        self::assertFalse($store->delete($id));
        self::assertDirectoryExists($this->path.'/'.$id);
    }

    #[DataProvider('provideIdsThatAreNoIds')]
    public function testANameThatIsNoIdIsRefusedBeforeItIsEverAPath(string $id): void
    {
        $store = $this->store();

        self::assertFalse($store->isValidId($id));
        // Everything that takes an id from a request goes through these, and a
        // refusal reads as "no such snapshot" rather than as a path to try.
        self::assertNull($store->get($id));
        self::assertFalse($store->delete($id));
        self::assertSame('Not a snapshot id.', $this->refusal(fn () => $store->directory($id))->getMessage());
        self::assertSame('Not a snapshot id.', $this->refusal(fn () => $store->dumpFile($id))->getMessage());
        self::assertSame('Not a snapshot id.', $this->refusal(fn () => $store->filesDirectory($id))->getMessage());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideIdsThatAreNoIds(): array
    {
        return [
            'a way out of the store' => ['../victim'],
            'a way out of the store, spelled for windows' => ['..\\victim'],
            'the parent directory itself' => ['..'],
            'the store itself' => ['.'],
            'a path of its own' => ['/etc/passwd'],
            'a path of its own, spelled for windows' => ['C:/Windows'],
            'a subdirectory of a snapshot' => ['20260101-000000-blog-a1b2c3d4/files'],
            'a name cut short by a null byte' => ["20260101-000000-blog-a1b2c3d4\0.txt"],
            'a name a trailing newline hides behind' => ["20260101-000000-blog-a1b2c3d4\n"],
            'a hidden name, which the guard files carry' => ['.htaccess'],
            'a name with a space in it' => ['20260101 000000'],
            'nothing at all' => [''],
            'more name than any filesystem takes' => [str_repeat('a', 129)],
        ];
    }

    public function testARefusedNameIsNotHandedBackInTheRefusal(): void
    {
        // The value came in on a request and the refusal goes into a log, a
        // response or an admin notice. Carrying it along is how a rejected
        // input ends up rendered somewhere it counts.
        $store = $this->store();

        $message = $this->refusal(fn () => $store->directory('../../etc/passwd'))->getMessage();

        self::assertStringNotContainsString('passwd', $message);
        self::assertStringNotContainsString('..', $message);
    }

    public function testNothingOutsideTheStoreCanBeReachedThroughAnId(): void
    {
        // A purge is a request naming a snapshot. Resolved as a path it names
        // whatever the request wants deleted instead.
        mkdir($this->workspace.'/victim', 0755, true);
        file_put_contents($this->workspace.'/victim/keep.txt', 'not the store to lose');
        mkdir($this->path, 0755, true);

        $store = $this->store();

        self::assertFalse($store->delete('../victim'));
        self::assertNull($store->get('../victim'));
        self::assertFileExists($this->workspace.'/victim/keep.txt');
    }

    public function testASnapshotKeepsItsDumpAndItsArchiveUnderTheNamesARestoreReadsBack(): void
    {
        // Where these two live is the contract between whoever writes a
        // snapshot and whoever replays it. The archive keeps the shape the
        // package has in packages/, so restoring it is a copy back.
        $store = $this->store();

        $id = $store->create($this->details());

        self::assertSame($this->path.'/'.$id.'/db.dump', $store->dumpFile($id));
        self::assertSame($this->path.'/'.$id.'/files', $store->filesDirectory($id));
    }

    public function testTheDumpAndArchiveOfASnapshotThatIsNotThereAreRefusedRatherThanNamed(): void
    {
        // Handing back a path inside a directory that does not exist would let
        // a dumper write a snapshot nothing opened and nothing inventoried.
        $store = $this->store();
        $id = '20260101-000000-blog-a1b2c3d4';

        self::assertSame('No snapshot goes by this id.', $this->refusal(fn () => $store->directory($id))->getMessage());
        self::assertSame('No snapshot goes by this id.', $this->refusal(fn () => $store->dumpFile($id))->getMessage());
        self::assertSame('No snapshot goes by this id.', $this->refusal(fn () => $store->filesDirectory($id))->getMessage());
    }

    public function testHowMuchDiskASnapshotHoldsIsWhatIsInIt(): void
    {
        // An operator watching the store grow is the only guard against a
        // retention window that is too long for the disk, so the number has to
        // count the dump and the archived tree rather than the description
        // alone.
        $description = $this->description();
        $directory = $this->place('20260101-000000-blog-a1b2c3d4', $description);

        file_put_contents($directory.'/db.dump', str_repeat('x', 1024));
        mkdir($directory.'/files/pagekit/blog', 0700, true);
        file_put_contents($directory.'/files/pagekit/blog/index.php', str_repeat('y', 64));

        $snapshot = $this->store()->get('20260101-000000-blog-a1b2c3d4');

        self::assertNotNull($snapshot);
        self::assertSame(strlen($description) + 1024 + 64, $snapshot['size']);
    }

    public function testWhatALinkInASnapshotPointsAtIsNotCountedAsItsDisk(): void
    {
        $this->requireSymlink();

        $description = $this->description();
        $directory = $this->place('20260101-000000-blog-a1b2c3d4', $description);

        file_put_contents($this->workspace.'/elsewhere.bin', str_repeat('z', 4096));
        symlink($this->workspace.'/elsewhere.bin', $directory.'/linked.bin');

        $snapshot = $this->store()->get('20260101-000000-blog-a1b2c3d4');

        self::assertNotNull($snapshot);
        // Those bytes are somebody else's: purging the snapshot would not
        // reclaim them, so counting them would overstate what it holds.
        self::assertSame(strlen($description), $snapshot['size']);
    }

    /**
     * The store as the application builds it: the directory the snapshots live
     * in, the filesystem service that writes them, and the retention window an
     * installation gets where it configures none of its own.
     */
    private function store(): SnapshotStore
    {
        return new SnapshotStore($this->path, new Filesystem());
    }

    private function storeKeepingFor(int $days): SnapshotStore
    {
        return new SnapshotStore($this->path, new Filesystem(), $days);
    }

    /**
     * What a snapshot is taken of, as the caller describing one hands it over.
     *
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function details(string $module = 'blog', array $overrides = []): array
    {
        return $overrides + [
            'package' => 'pagekit/'.($module !== '' ? $module : 'package'),
            'module' => $module,
            'title' => 'Blog',
            'type' => 'pagekit-extension',
            'version' => '1.4.2',
            'composer' => true,
            'reason' => 'uninstall',
            'format' => 1,
            'database' => ['driver' => 'pdo_sqlite', 'platform' => 'sqlite', 'prefix' => 'pk_'],
        ];
    }

    /**
     * A description as it lies in a snapshot taken by an earlier request, which
     * is the only way one is ever read.
     *
     * @param array<string, mixed> $overrides a null value drops the field, so a
     *                                        description missing one can be
     *                                        written as well
     */
    private function description(array $overrides = [], ?string $composer = null): string
    {
        $data = array_filter(
            $overrides + ['created' => time()] + $this->details(),
            static fn (mixed $value): bool => $value !== null
        );

        $json = (string) json_encode($data);

        // The one field a caller is asked to place verbatim: whether a snapshot
        // is Composer-installed is read strictly, which takes a value JSON can
        // carry but the details array cannot.
        return $composer !== null
            ? substr($json, 0, -1).',"composer":'.$composer.'}'
            : $json;
    }

    /**
     * Puts a snapshot in the store that this run did not take. Every snapshot
     * an administrator acts on arrives that way: written by the request that
     * removed a package, read back by a later one.
     *
     * @return string the snapshot's directory
     */
    private function place(string $id, ?string $description = null): string
    {
        $directory = $this->path.'/'.$id;

        mkdir($directory, 0700, true);

        if ($description !== null) {
            file_put_contents($directory.'/'.self::METADATA, $description);
        }

        return $directory;
    }

    /**
     * Runs a call that has to refuse an id, and hands back what it refused with.
     */
    private function refusal(callable $call): \InvalidArgumentException
    {
        try {
            $call();
        } catch (\InvalidArgumentException $e) {
            return $e;
        }

        self::fail('The call was expected to refuse the id.');
    }

    /**
     * Skips where a file can still be created in a directory whose permission
     * bits refuse one: root ignores them, and a platform answering a read-only
     * directory with a flag rather than a refusal (Windows) lets the write
     * happen as well.
     */
    private function requireUnwritable(string $dir): void
    {
        if ($this->isRoot()) {
            self::markTestSkipped('Root writes into a read-only directory regardless of its permission bits');
        }

        $probe = $dir.'/probe-writable';

        if (@file_put_contents($probe, '') !== false) {
            unlink($probe);

            self::markTestSkipped('This host writes into a read-only directory, where a failed write cannot be provoked');
        }
    }

    /**
     * Skips where the filesystem carries no POSIX permission bits to assert on
     * (Windows reports the same mode for everything).
     */
    private function requirePermissionBits(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('This platform keeps no POSIX permission bits');
        }
    }

    /**
     * Skips when the host cannot create symlinks (common on Windows without
     * Developer Mode / elevated privileges).
     */
    private function requireSymlink(): void
    {
        $target = $this->workspace.'/symlink-probe-target';
        $link = $this->workspace.'/symlink-probe-link';

        file_put_contents($target, '');

        $available = @symlink($target, $link) && is_link($link);

        if (is_link($link)) {
            unlink($link);
        }

        unlink($target);

        if (!$available) {
            self::markTestSkipped('symlink() is unavailable on this host');
        }
    }

    private function isRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * Lists what a directory holds, so a file staged for a write and left
     * behind shows up as an unexpected entry.
     *
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

    /**
     * Hands back the directories a test made read-only, so the workspace can be
     * removed again.
     */
    private function restoreModes(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            return;
        }

        @chmod($path, 0755);

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->restoreModes($path.'/'.$entry);
        }
    }

    /**
     * Deletes a tree, unlinking links rather than following them: a snapshot
     * under test holds one pointing out of the workspace.
     */
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
 * Notes down the file it is asked to replace, and then replaces it.
 */
final class MetadataWriteRecorder extends Filesystem
{
    /** @var array<int, string> */
    public array $written = [];

    public function dumpAtomic(string $file, string $content, ?int $mode = null): void
    {
        $this->written[] = $file;

        parent::dumpAtomic($file, $content, $mode);
    }
}

/**
 * A filesystem whose write never happens, as a full disk, a read-only mount or
 * a target no move can reach makes it.
 */
final class WriteThatNeverHappens extends Filesystem
{
    public function __construct(private readonly \Throwable $error)
    {
    }

    public function dumpAtomic(string $file, string $content, ?int $mode = null): void
    {
        throw $this->error;
    }
}
