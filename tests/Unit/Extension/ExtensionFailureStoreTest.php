<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Extension;

use Pagekit\Filesystem\Filesystem;
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
 */
final class ExtensionFailureStoreTest extends TestCase
{
    /**
     * The name the record has on disk. Tests that put a damaged or foreign
     * record in place have to write the file the store reads back.
     */
    private const FILE = 'extension-failures.json';

    /**
     * The shape callers read an entry in. The notice names the module and points
     * at the log, so the origin of the failure is kept here and its trace is not.
     */
    private const FIELDS = ['name', 'type', 'class', 'message', 'file', 'line', 'time'];

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
        self::assertSame([self::FILE], $this->entries($this->path), 'A file staged for the record is moved into place, never left beside it');
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
     * Runs both writing calls through a filesystem that cannot write, which the
     * store has to survive: it reports what it could not do, and the failures
     * already on record stay readable.
     */
    private function nothingEscapesThrough(Filesystem $writer): void
    {
        $this->writeRecord($this->recordOf('blog'));

        $store = new ExtensionFailureStore($this->path, $writer);

        self::assertFalse($store->record('theme-one', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('boom')));
        self::assertFalse($store->clear('blog'));
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
