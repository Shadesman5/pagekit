<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Extension;

use Pagekit\Filesystem\Adapter\StreamAdapter;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\StreamWrapper;
use Pagekit\System\Extension\ExtensionFailureStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The record of failed extensions is written while a failure is already in
 * progress and read again on the boot path of every request after it. That puts
 * two demands on it that weigh more than the round trip.
 *
 * Nothing it does may throw: it is called from the recovery path of a fault that
 * is already under way, where a second error would replace the one an
 * administrator needs to see and take down the boot the record exists to keep
 * alive. A write that cannot happen costs one degraded extension nobody was told
 * about; a throw from here costs the site.
 *
 * And a record it cannot read has to read as no failures. Anything else would
 * turn one unreadable file into a boot that fails for every request, which is
 * the outcome this whole mechanism is there to prevent.
 *
 * The third demand comes from what an entry now carries. A count of the failures
 * a module had in a row is what decides whether it is executed again, and every
 * change to the record is a read, a change and a replace: two requests running
 * that sequence at once would drop one another's entries and merge two failures
 * into one count. So the sequence is serialized - and a lock that cannot be
 * taken still costs nothing but the serialization, because this is the recovery
 * path of a failure that is already under way.
 */
final class ExtensionFailureStoreTest extends TestCase
{
    /**
     * The name the record has on disk. Tests that put a damaged or foreign
     * record in place have to write the file the store reads back.
     */
    private const FILE = 'extension-failures.json';

    /**
     * The name of the file a writer holds while it replaces the record. It sits
     * beside the record and outlives the write, so a directory the store has
     * written in holds it as well as the record itself.
     */
    private const LOCK = 'extension-failures.lock';

    /**
     * The shape callers read an entry in. The notice names the module and points
     * at the log, so the origin of the failure is kept here and its trace is
     * not; and it says how often the module failed in a row, which is what
     * separates a module that broke once from one that breaks every time.
     */
    private const FIELDS = ['name', 'type', 'class', 'message', 'file', 'line', 'time', 'count'];

    /**
     * The protocol the record is addressed under where it lives on a mount
     * instead of on a plain path: the files it holds are then opened through a
     * stream, which is where a refused lock can be arranged.
     */
    private const MOUNT = 'nolocks';

    private string $workspace;

    /**
     * Where the record lives. Deliberately not created in setUp(): a fresh
     * installation has no such directory, and the first failure has to land all
     * the same.
     */
    private string $path;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_extension_failures_'.getmypid().'_'.uniqid();
        $this->path = $this->workspace.'/system';

        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        // A protocol stays registered for the whole process, so a test that
        // addressed the record through one takes it back out again.
        if (in_array(self::MOUNT, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::MOUNT);
        }

        // A test that provoked a directory nobody can write has to hand it back
        // before the workspace can be removed.
        if (is_dir($this->path)) {
            chmod($this->path, 0755);
        }

        $this->removeTree($this->workspace);
    }

    public function testAFailureOutlivesTheRequestThatRanIntoIt(): void
    {
        $recorded = time();
        $failure = new \RuntimeException('Call to undefined method');

        self::assertTrue($this->store()->record('blog', ExtensionFailureStore::TYPE_EXTENSION, $failure));

        // A second store stands in for the next request: what can be acted on is
        // what reached the disk, not what the instance that recorded it holds.
        $entries = $this->store()->all();

        self::assertSame(['blog'], array_keys($entries));
        self::assertTrue($this->store()->has('blog'));
        self::assertSame('blog', $entries['blog']['name']);
        self::assertSame(ExtensionFailureStore::TYPE_EXTENSION, $entries['blog']['type']);
        self::assertSame(\RuntimeException::class, $entries['blog']['class']);
        self::assertSame('Call to undefined method', $entries['blog']['message']);
        self::assertSame($failure->getFile(), $entries['blog']['file']);
        self::assertSame($failure->getLine(), $entries['blog']['line']);
        self::assertGreaterThanOrEqual($recorded, $entries['blog']['time']);
    }

    public function testTheDirectoryTheRecordLivesInIsCreatedByTheFirstFailure(): void
    {
        // An installation that never had a broken extension has no such
        // directory, and the failure that needs it cannot wait for one.
        self::assertDirectoryDoesNotExist($this->path);

        self::assertTrue($this->store()->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom')));

        self::assertSame([self::FILE], $this->entries($this->path));
    }

    public function testTheRecordIsDataThatNoBootCanRunAsCode(): void
    {
        $this->store()->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom'));

        $raw = (string) file_get_contents($this->path.'/'.self::FILE);

        // A record that was damaged or tampered with is read as data and dropped.
        // In a PHP file the same content would be executed by the boot reading it.
        self::assertStringNotContainsString('<?php', $raw);
        self::assertIsArray(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testTheOriginOfAFailureIsKeptAndItsTraceIsNot(): void
    {
        // The trace is written to the log with the same failure. A second copy
        // here would put whatever its frames carry into the file the admin
        // notice is rendered from.
        $this->store()->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \LogicException('boom'));

        self::assertSame(self::FIELDS, array_keys($this->store()->all()['blog']));
    }

    public function testTheFailureAModuleHasNowReplacesTheOneItHadBefore(): void
    {
        $store = $this->store();

        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('the first fault'));
        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \LogicException('the fault it fails with now'));

        $entries = $this->store()->all();

        // One module is one record: what an administrator can still act on is
        // the current failure, not a history of them.
        self::assertSame(['blog'], array_keys($entries));
        self::assertSame(\LogicException::class, $entries['blog']['class']);
        self::assertSame('the fault it fails with now', $entries['blog']['message']);
    }

    public function testEveryFailureOfAModuleInARowIsCountedOnItsEntry(): void
    {
        $store = $this->store();

        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom'));

        self::assertSame(1, $this->store()->all()['blog']['count']);

        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom'));
        $store->record('theme-one', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('bang'));

        $entries = $this->store()->all();

        // A module on its first failure may work again on the next request, and
        // one that has failed on every request since is not going to. The
        // difference is only readable if the entry that keeps the current
        // failure also keeps how many came before it - per module, because a
        // failure of one is not a failure of the other.
        self::assertSame(2, $entries['blog']['count']);
        self::assertSame(1, $entries['theme-one']['count']);
    }

    public function testAModuleTakenOffTheRecordIsCountedFromTheStartAgain(): void
    {
        $store = $this->store();

        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom'));
        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom'));

        self::assertTrue($store->clear('blog'));

        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom'));

        // What the count stands for is failures in a row. Being taken off the
        // record is what an administrator acting on the package does, and it
        // ends the row: whatever happens next is a module failing again, not a
        // module that never stopped.
        self::assertSame(1, $this->store()->all()['blog']['count']);
    }

    public function testAnEntryFromBeforeTheCountExistedStandsForOneFailure(): void
    {
        // An installation upgrading into the count has entries on record that
        // never carried one, and each of them was written by a module that
        // failed. Reading them as no failures at all is the one wrong answer.
        $this->writeRecord('{"blog": {"name": "blog", "type": "extension", "message": "boom"}}');

        self::assertSame(1, $this->store()->all()['blog']['count']);

        $this->store()->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom'));

        self::assertSame(2, $this->store()->all()['blog']['count']);
    }

    #[DataProvider('provideCountsThatAreNoCount')]
    public function testAnEntryWhoseCountIsNoCountStandsForOneFailure(string $count): void
    {
        // Callers read the count to decide whether a module is tried again, so
        // a damaged or foreign entry has to arrive as the failure it is rather
        // than as a number that means nothing.
        $this->writeRecord(sprintf('{"blog": {"name": "blog", "type": "extension", "count": %s}}', $count));

        self::assertSame(1, $this->store()->all()['blog']['count']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideCountsThatAreNoCount(): array
    {
        return [
            'no failures, which no entry on the record stands for' => ['0'],
            'a count below zero' => ['-3'],
            'a count as text' => ['"2"'],
            'a count with a fraction' => ['1.5'],
            'a count that is null' => ['null'],
            'something that is no count at all' => ['{"failures": 2}'],
        ];
    }

    public function testEveryFailedModuleIsOnRecordBesideTheOthers(): void
    {
        $store = $this->store();

        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom'));
        $store->record('theme-one', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('bang'));

        $entries = $this->store()->all();

        // An extension and the theme degrade differently, so the record has to
        // say which of the two a failure was.
        self::assertSame(['blog', 'theme-one'], array_keys($entries));
        self::assertSame(ExtensionFailureStore::TYPE_EXTENSION, $entries['blog']['type']);
        self::assertSame(ExtensionFailureStore::TYPE_THEME, $entries['theme-one']['type']);
    }

    public function testAFailureWithoutAModuleNameIsRefusedInsteadOfFiledUnderNothing(): void
    {
        // An entry under an empty key names no module, so it could never be
        // matched against a load list, rendered in a notice or cleared again.
        self::assertFalse($this->store()->record('', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom')));
        self::assertDirectoryDoesNotExist($this->path);
    }

    public function testAnInstallationWithoutFailuresReadsAsNoneAndWritesNothing(): void
    {
        $store = $this->store();

        self::assertSame([], $store->all());
        self::assertFalse($store->has('blog'));
        // Every boot reads this record. With nothing on it there is nothing to
        // clear either, and no reason to touch the disk on the way past.
        self::assertTrue($store->clear('blog'));
        self::assertDirectoryDoesNotExist($this->path);
    }

    public function testClearingOneModuleLeavesTheFailuresOfTheOthersOnRecord(): void
    {
        $store = $this->store();

        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom'));
        $store->record('theme-one', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('bang'));

        self::assertTrue($store->clear('blog'));

        // An administrator who dealt with one extension has not dealt with the
        // rest, and the notice for those has to survive the rewrite.
        self::assertSame(['theme-one'], array_keys($this->store()->all()));
        self::assertFalse($this->store()->has('blog'));
    }

    public function testClearingTheLastFailureLeavesARecordTheNextBootCanStillRead(): void
    {
        $store = $this->store();
        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom'));

        self::assertTrue($store->clear('blog'));

        self::assertSame([], $this->store()->all());
        self::assertIsArray(json_decode((string) file_get_contents($this->path.'/'.self::FILE), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testAFailurePutBackOnTheRecordIsTheOneThatWasTakenOff(): void
    {
        // Clearing a record is part of an operation that can still fail after
        // it, and the operation then has to undo its own clear. Recording the
        // module again is not the same thing: the failure an administrator acts
        // on is the one that happened, at the time it happened.
        $store = $this->store();

        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom'));
        $store->record('theme-one', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('bang'));

        $entry = $store->all()['blog'];

        self::assertTrue($store->clear('blog'));
        self::assertTrue($store->restore($entry));

        $entries = $this->store()->all();

        self::assertSame(['theme-one', 'blog'], array_keys($entries));
        self::assertSame($entry, $entries['blog']);
    }

    public function testAFailurePutBackWhereTheModuleFailedAgainIsTheFailureItHasNow(): void
    {
        $store = $this->store();

        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('the fault it had'));

        $entry = $store->all()['blog'];

        $store->clear('blog');
        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \LogicException('the fault it has now'));

        self::assertTrue($store->restore($entry));

        // One module is one record here as everywhere else. A caller putting
        // back what it took off is the only one that knows its entry is still
        // the current one; a boot that recorded a newer failure in between has
        // written the record this replaces.
        self::assertSame('the fault it had', $this->store()->all()['blog']['message']);
    }

    public function testACountPutBackOnTheRecordIsTheOneThatWasTakenOff(): void
    {
        $store = $this->store();

        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom'));
        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom'));

        $entry = $store->all()['blog'];

        self::assertTrue($store->clear('blog'));
        self::assertTrue($store->restore($entry));

        // The operation that cleared the record did not happen, so neither did
        // the fresh start it would have been: a module that had failed twice is
        // two failures in, not one attempt away from being given up on and not
        // back at the beginning either.
        self::assertSame(2, $this->store()->all()['blog']['count']);
    }

    public function testAnEntryWithoutAModuleNameIsRefusedInsteadOfFiledUnderNothing(): void
    {
        $entry = [
            'name' => '',
            'type' => ExtensionFailureStore::TYPE_EXTENSION,
            'class' => \RuntimeException::class,
            'message' => 'boom',
            'file' => '/app/packages/pagekit/blog/index.php',
            'line' => 7,
            'time' => 1700000000,
            'count' => 1,
        ];

        self::assertFalse($this->store()->restore($entry));
        self::assertDirectoryDoesNotExist($this->path);
    }

    public function testTheRecordIsReplacedInOneStepSoNoBootReadsItHalfWritten(): void
    {
        // One request rewrites this file while another one reads it on its way
        // through boot. Rewritten in place it could be read truncated, and a
        // reader discarding a truncated record drops every failure on it -
        // including the one keeping a broken extension from being executed.
        $writer = new AtomicWriteRecorder();
        $store = new ExtensionFailureStore($this->path, $writer);

        $store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom'));
        $store->record('theme-one', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('bang'));
        $store->clear('blog');

        $file = $this->path.'/'.self::FILE;

        self::assertSame([$file, $file, $file], $writer->written);

        // The record and the lock the writers took, and nothing besides: a file
        // staged for a write and left behind would be a third entry here.
        self::assertSame([self::FILE, self::LOCK], $this->entries($this->path), 'A file staged for the record is moved into place, never left beside it');
    }

    public function testEveryChangeToTheRecordIsMadeWhereNoOtherWriterCanBe(): void
    {
        // Reading the record, changing it and replacing it is one sequence, and
        // the atomic replace at the end of it only keeps a reader from seeing a
        // torn file. Two workers running the sequence at once both read the same
        // entries, and the one that writes second writes over what the first
        // recorded: a failure nobody is told about, or two failures that arrive
        // as one count and leave a module short of being given up on.
        $this->writeRecord($this->recordOf('blog'));

        $writer = new LockProbe($this->path.'/'.self::LOCK);
        $store = new ExtensionFailureStore($this->path, $writer);

        $store->record('theme-one', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('bang'));
        $store->restore($store->all()['blog']);
        $store->clear('theme-one');

        // Asked from the write itself, which is inside the sequence: a second
        // worker could not have taken the lock there, so it could not have been
        // between this one's read and its replace.
        self::assertSame([false, false, false], $writer->free, 'Every write that changes the record holds the lock while it does');

        // And it is handed back afterwards. A lock held past the write would
        // leave the next failure of the next request waiting on a boot that is
        // over, which is the one thing worse than a lost entry.
        self::assertTrue(LockProbe::canLock($this->path.'/'.self::LOCK), 'The lock is released when the change is done');
    }

    public function testAWriteThatCannotTakeTheLockIsMadeWithoutOne(): void
    {
        // Taking the lock can fail on its own - an open file limit, a filesystem
        // that has no locks, a path something else occupies. This is the
        // recovery path of a fault already in progress, so losing the failure
        // over the lock meant to protect it would be the worst of both: writes
        // go unserialized here, exactly as every write did before there was a
        // lock at all.
        $this->writeRecord($this->recordOf('blog'));

        mkdir($this->path.'/'.self::LOCK);

        $store = $this->store();

        self::assertTrue($store->record('theme-one', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('bang')));
        self::assertSame(['blog', 'theme-one'], array_keys($this->store()->all()));
    }

    public function testAWriteOnAFilesystemThatRefusesLocksIsMadeWithoutOne(): void
    {
        // The other half of that: the file opens and the lock is refused
        // anyway, which is what a network share or a bind mount from a host
        // that hands out no locks answers. A record can live on one, and being
        // unable to lock it is not being unable to write it - so every change
        // still lands, unserialized, and none of them is lost on the way.
        $this->writeRecord($this->recordOf('blog'));

        $store = $this->storeOnAMountWithoutLocks();

        self::assertTrue($store->record('theme-one', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('bang')));
        self::assertTrue($store->record('theme-one', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('bang')));

        $entry = $store->all()['blog'];

        self::assertTrue($store->clear('blog'));
        self::assertTrue($store->restore($entry));

        // Read back off the plain directory the mount leads to: what an
        // administrator is told is what reached the disk.
        $entries = $this->store()->all();

        self::assertSame(['theme-one', 'blog'], array_keys($entries));
        self::assertSame($entry, $entries['blog']);
        self::assertSame(2, $entries['theme-one']['count'], 'A change made without the lock is still the whole read, change and replace');

        // And the writers got as far as opening the lock file, which is what
        // separates this from a lock that could not even be opened: what was
        // refused here is the lock on a file that is there.
        self::assertFileExists($this->path.'/'.self::LOCK);
    }

    public function testTheRecordIsReadWithoutTakingTheLockAtAll(): void
    {
        // Every boot reads this file, and a read that queued behind a writer
        // would put the boot path behind whatever a failing request is doing.
        // It does not have to: the record is replaced in one step, so a reader
        // sees the failures from before the write or the ones after it.
        $this->writeRecord($this->recordOf('blog'));

        $store = $this->store();

        self::assertSame(['blog'], array_keys($store->all()));
        self::assertTrue($store->has('blog'));

        // The lock is taken by opening the file, so a read that took one would
        // have left it here.
        self::assertSame([self::FILE], $this->entries($this->path), 'A read neither takes the lock nor creates it');
    }

    #[DataProvider('provideUnusableRecords')]
    public function testARecordThatCannotBeUnderstoodReadsAsNoFailures(string $content): void
    {
        $this->writeRecord($content);

        $store = $this->store();

        self::assertSame([], $store->all());
        self::assertFalse($store->has('blog'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideUnusableRecords(): array
    {
        return [
            'a file cut off mid-write' => ['{"blog": {"name": "blog", "typ'],
            'an empty file' => [''],
            'nothing but whitespace' => ["\n \t\n"],
            'no json at all' => ['<?php return [];'],
            'a json list, where entries are keyed by module name' => ['[{"name": "blog"}]'],
            'a json value that is no set of entries' => ['42'],
            'a json null' => ['null'],
        ];
    }

    public function testAnEntryThisStoreDidNotWriteIsDroppedAndTheRestIsKept(): void
    {
        $this->writeRecord('{"blog": "not an entry", "theme-one": {"name": "theme-one", "type": "theme", "message": "bang"}}');

        $entries = $this->store()->all();

        // Callers are handed the shape they read, so anything that is not an
        // entry is dropped here rather than reaching them as one.
        self::assertSame(['theme-one'], array_keys($entries));
        self::assertSame('bang', $entries['theme-one']['message']);
    }

    public function testAnEntryMissingItsFieldsStillReadsInTheShapeCallersExpect(): void
    {
        // The notice reads every field off every entry. A partial one has to
        // arrive complete instead of making each reader guard each field.
        $this->writeRecord('{"blog": {}}');

        $entry = $this->store()->all()['blog'];

        self::assertSame(self::FIELDS, array_keys($entry));
        self::assertSame('blog', $entry['name']);
        self::assertSame('', $entry['message']);
        self::assertSame(0, $entry['line']);
    }

    public function testAnEntryCarryingOtherTypesThanItsOwnStillReadsInThatShape(): void
    {
        $this->writeRecord('{"blog": {"type": ["extension"], "class": 7, "message": null, "file": false, "line": "9", "time": 1.5}}');

        $entry = $this->store()->all()['blog'];

        self::assertSame(self::FIELDS, array_keys($entry));
        self::assertSame('', $entry['type']);
        self::assertSame('', $entry['class']);
        self::assertSame('', $entry['file']);
        self::assertSame(0, $entry['line']);
        self::assertSame(0, $entry['time']);
    }

    public function testAnEntryCannotNameAModuleOtherThanTheOneItIsFiledUnder(): void
    {
        // Callers match on the key: the load list is filtered by it and the
        // notice is rendered per key. An entry naming something else would
        // report the failure of one extension against another.
        $this->writeRecord('{"blog": {"name": "system/user", "type": "extension"}}');

        $entries = $this->store()->all();

        self::assertSame(['blog'], array_keys($entries));
        self::assertSame('blog', $entries['blog']['name']);
    }

    public function testAMessageThatIsNoValidUtf8IsRecordedRatherThanLost(): void
    {
        // A database driver or a filesystem path can put bytes in a message that
        // were never UTF-8. Refusing to encode them would lose the failure over
        // the one part of it that is decoration.
        self::assertTrue($this->store()->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException("broken \xB1\x31 message")));

        $entries = $this->store()->all();

        self::assertSame(['blog'], array_keys($entries));
        self::assertStringContainsString('broken', $entries['blog']['message']);
        self::assertStringContainsString('message', $entries['blog']['message']);
    }

    public function testASetOfEntriesThatCannotBeEncodedLeavesTheRecordOnDiskAlone(): void
    {
        // Substituted bytes keep a record that was never valid UTF-8, but a set
        // of entries with no JSON at all cannot be kept. Encoding reports that
        // by handing back no string rather than by raising, and a store writing
        // that out would replace a readable record with nothing.
        $this->writeRecord($this->recordOf('blog'));

        $writer = new AtomicWriteRecorder();
        $store = new ExtensionFailureStore($this->path, $writer);

        // Entries that carry themselves have no JSON. No caller can hand the
        // store such a set - it builds every entry itself out of a throwable -
        // so the write is driven directly to reach the encoder failing on it.
        $entries = ['theme-one' => ['name' => 'theme-one', 'type' => ExtensionFailureStore::TYPE_THEME]];
        $entries['theme-one']['self'] = &$entries;

        $written = (new \ReflectionMethod(ExtensionFailureStore::class, 'write'))->invoke($store, $entries);

        self::assertFalse($written);
        self::assertSame([], $writer->written, 'A set of entries with no JSON is reported as lost instead of written as one');
        self::assertSame(['blog'], array_keys($store->all()), 'The failure already on record stays readable');
    }

    public function testADirectoryThatCannotBeWrittenCostsTheRecordAndNothingElse(): void
    {
        mkdir($this->path, 0755, true);
        chmod($this->path, 0555);

        $this->requireUnwritable($this->path);

        $store = $this->store();

        self::assertFalse($store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom')));
        self::assertSame([], $this->entries($this->path));
        self::assertSame([], $store->all());
    }

    public function testAPathThatCannotBecomeADirectoryCostsTheRecordAndNothingElse(): void
    {
        // The directory cannot always be created: the path can be occupied by a
        // file, or sit on a volume that is not mounted. A boot dying over that
        // would turn one broken extension into a site that answers nothing.
        file_put_contents($this->path, '');

        self::assertFalse($this->store()->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom')));
    }

    public function testAClearThatCannotBeWrittenKeepsTheFailureItCouldNotRemove(): void
    {
        // Reporting an entry as cleared while it is still on disk would leave an
        // extension held off the next boot with nothing naming it any more.
        $this->writeRecord($this->recordOf('blog'));
        chmod($this->path, 0555);

        $this->requireUnwritable($this->path);

        $store = $this->store();

        self::assertFalse($store->clear('blog'));
        self::assertTrue($store->has('blog'));
    }

    public function testAWriteThatFailsCostsTheRecordAndLetsNothingEscape(): void
    {
        $this->nothingEscapesThrough(new WriterThatFails(new \RuntimeException('Failed to write file')));
    }

    public function testAWriteThatRaisesAnErrorCostsTheRecordAndLetsNothingEscape(): void
    {
        // A broken write does not only raise exceptions. An Error escaping the
        // store would be an Error on the recovery path of a failure that is
        // already being handled, where nothing is left to catch it.
        $this->nothingEscapesThrough(new WriterThatFails(new \Error('Something is deeply wrong')));
    }

    public function testADirectoryThatRaisesCostsTheRecordAndLetsNothingEscape(): void
    {
        $this->nothingEscapesThrough(new DirectoryThatFails());
    }

    /**
     * Runs every writing call through a filesystem that cannot write, which the
     * store has to survive: it reports what it could not do, and the failures
     * already on record stay readable.
     */
    private function nothingEscapesThrough(Filesystem $writer): void
    {
        $this->writeRecord($this->recordOf('blog'));

        $store = new ExtensionFailureStore($this->path, $writer);

        self::assertFalse($store->record('theme-one', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('boom')));
        self::assertFalse($store->clear('blog'));
        self::assertFalse($store->restore($store->all()['blog']));
        self::assertTrue($store->has('blog'));
        self::assertSame(['blog'], array_keys($store->all()));
    }

    /**
     * The store as the application builds it: the directory the record lives in
     * and the filesystem service that performs the write.
     */
    private function store(): ExtensionFailureStore
    {
        return new ExtensionFailureStore($this->path, new Filesystem());
    }

    /**
     * The same store, with its record addressed through a mount whose files
     * open and cannot be locked. The directory behind the mount is the one the
     * plain store reads, so what a change made without the lock did to the
     * record is readable off it.
     */
    private function storeOnAMountWithoutLocks(): ExtensionFailureStore
    {
        $files = new Filesystem();
        $files->registerAdapter(self::MOUNT, new MountWithoutLocks($this->workspace, '', StreamWithoutLocks::class));

        StreamWrapper::setFilesystem($files);

        return new ExtensionFailureStore(self::MOUNT.'://'.basename($this->path), $files);
    }

    /**
     * Puts a record on disk that this store did not write, which is how a
     * damaged or foreign file reaches it.
     */
    private function writeRecord(string $content): void
    {
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }

        file_put_contents($this->path.'/'.self::FILE, $content);
    }

    /**
     * A record holding one module's failure, as the store writes it.
     */
    private function recordOf(string $name): string
    {
        return (string) json_encode([
            $name => [
                'name' => $name,
                'type' => ExtensionFailureStore::TYPE_EXTENSION,
                'class' => \RuntimeException::class,
                'message' => 'boom',
                'file' => '/app/packages/pagekit/blog/index.php',
                'line' => 7,
                'time' => 1700000000,
                'count' => 1,
            ],
        ], JSON_FORCE_OBJECT);
    }

    /**
     * Skips where a file can still be created in a directory whose permission
     * bits refuse one: root ignores them, and a platform answering a read-only
     * directory with a flag rather than a refusal (Windows) lets the write
     * happen as well. A write cannot be made to fail there, and asserting that
     * it did would test the skip instead of the store.
     */
    private function requireUnwritable(string $dir): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root writes into a read-only directory regardless of its permission bits');
        }

        $probe = $dir.'/probe-writable';

        if (@file_put_contents($probe, '') !== false) {
            unlink($probe);

            self::markTestSkipped('This host writes into a read-only directory, where a failed write cannot be provoked');
        }
    }

    /**
     * Lists what a directory holds, so a file staged for a write and left behind
     * shows up as an unexpected entry.
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
 * Notes down the file it is asked to replace, and then replaces it.
 */
final class AtomicWriteRecorder extends Filesystem
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
 * Asks from inside the write whether anybody else could be changing the record
 * at the same moment, and then performs the write.
 */
final class LockProbe extends Filesystem
{
    /**
     * Whether the lock was there to be taken during each write, in the order
     * the writes happened.
     *
     * @var array<int, bool>
     */
    public array $free = [];

    public function __construct(private readonly string $lock)
    {
    }

    public function dumpAtomic(string $file, string $content, ?int $mode = null): void
    {
        $this->free[] = self::canLock($this->lock);

        parent::dumpAtomic($file, $content, $mode);
    }

    /**
     * Whether a writer could start its own read-change-replace here, asked
     * without waiting for one that is under way: a test that waited would be
     * this process waiting for itself.
     */
    public static function canLock(string $file): bool
    {
        $handle = fopen($file, 'c');

        if ($handle === false) {
            return false;
        }

        $taken = flock($handle, LOCK_EX | LOCK_NB);

        if ($taken) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return $taken;
    }
}

/**
 * A directory addressed as a mount rather than as a plain path, so that opening
 * a file in it goes through a stream instead of straight to the disk.
 *
 * What is behind the mount is an ordinary directory, and the record is replaced
 * in it exactly as it is replaced in any other: what this stands for is a
 * filesystem that has no locks, not one that has no writes.
 */
final class MountWithoutLocks extends StreamAdapter
{
    /**
     * @param  array<string, mixed> $info
     * @return array<string, mixed>
     */
    public function getPathInfo(array $info): array
    {
        $info = parent::getPathInfo($info);

        // The record is replaced by a rename onto the directory the mount leads
        // to, which is where a real one performs it as well - a write is not
        // what a mount without locks has no answer for.
        $info['protocol'] = 'file';
        $info['pathname'] = $info['localpath'];

        return $info;
    }
}

/**
 * Files on that mount: they open, they are read and they are written, and a
 * request to lock one is refused - the answer a share or a bind mount from a
 * host with no locks to hand out gives.
 */
final class StreamWithoutLocks extends StreamWrapper
{
    public function stream_lock(int $operation): bool
    {
        return false;
    }
}

/**
 * A filesystem whose write never happens, as a full disk, a read-only mount or a
 * target no move can reach makes it.
 */
final class WriterThatFails extends Filesystem
{
    public function __construct(private readonly \Throwable $error)
    {
    }

    public function dumpAtomic(string $file, string $content, ?int $mode = null): void
    {
        throw $this->error;
    }
}

/**
 * A filesystem that raises over the directory rather than reporting that it
 * could not be made, as one whose path resolution fails does.
 */
final class DirectoryThatFails extends Filesystem
{
    public function makeDir(string $dir, int $mode = 0777, bool $recursive = true): bool
    {
        throw new \RuntimeException("Failed to make a directory ($dir).");
    }
}
