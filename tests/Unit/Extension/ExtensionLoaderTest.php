<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Extension;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Pagekit\Application;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Log\Logger;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\System\Extension\ExtensionFailureStore;
use Pagekit\System\Extension\ExtensionLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Loading an extension means running its own code, and an extension that throws
 * while doing so used to throw out of the boot itself: no frontend, no admin
 * panel, on every request, over one package an administrator may not even
 * remember installing - and no panel left to remove it with.
 *
 * What is asserted here is the price of that failure being the package instead of
 * the site. The extension that broke is logged with its trace, taken out of the
 * enabled list and written to a record that outlives the request, every other
 * extension and the theme still load, and the boot runs to the end. The three
 * consequences are independent, because what broke may be the database the first
 * of them writes to: the record is what keeps the extension off the next boot
 * when nothing else could be written down.
 *
 * The theme is the deliberate exception. Which theme a site uses is an
 * administrator's setting, so a failing one is logged and recorded but never
 * switched off, and it is tried again on the request after it - which is also
 * how it comes off the record once it works again.
 *
 * Tried again, not tried forever. A theme that fails the way it was written to
 * work - on a query, a remote call, a file it parses - charges every request for
 * the attempt, from whoever happens to be asking, for as long as nobody notices.
 * After enough failures in a row it is left unexecuted, which costs the site the
 * same blank layout a failed load costs it and nothing else. What gets it tried
 * again is an administrator enabling it, which takes it off the record.
 */
final class ExtensionLoaderTest extends TestCase
{
    /**
     * The name the record has on disk, which a test putting an entry on it that
     * the store did not write has to write itself.
     */
    private const FILE = 'extension-failures.json';

    /**
     * Where the counting fixture leaves how often it was executed. It writes it
     * into the container the boot hands to a module's own code.
     */
    private const EXECUTIONS = 'fixture-main-counting.executions';

    private Application $app;

    private ModuleManager $modules;

    private Logger $logger;

    private TestHandler $log;

    /**
     * The names the barrier asked to be taken out of the enabled list, in the
     * order it asked.
     *
     * @var array<int, string>
     */
    private array $disabled = [];

    private string $workspace;

    /**
     * Where the record lives. Not created in setUp(): an installation that never
     * had a broken extension has no such directory.
     */
    private string $path;

    protected function setUp(): void
    {
        $this->registerModules('healthy', 'second', 'main-throwing', 'main-erroring');

        $this->log = new TestHandler();
        $this->logger = new Logger('log');
        $this->logger->pushHandler($this->log);

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_extension_loader_'.getmypid().'_'.uniqid();
        $this->path = $this->workspace.'/system';

        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testABrokenExtensionCostsItselfAndLeavesTheRestOfTheSiteStanding(): void
    {
        $store = $this->store();

        // The broken extension is loaded first, so a barrier that ended the loop
        // rather than the extension would show as the ones behind it never
        // arriving.
        $this->loader($store)->load(['fixture-main-throwing', 'fixture-healthy'], 'fixture-second');

        self::assertSame(['fixture-healthy', 'fixture-second'], $this->loaded());
        self::assertInstanceOf(Module::class, $this->modules->get('fixture-healthy'));

        // The failure is named, because an administrator has to know which
        // package to act on, and it carries the throwable so its trace reaches
        // the log - the one place the fault is kept in full.
        $records = $this->log->getRecords();

        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertStringContainsString('fixture-main-throwing', $records[0]->message);
        self::assertStringContainsString('during load', $records[0]->message);
        self::assertStringContainsString('The module could not be loaded', $records[0]->message);
        self::assertInstanceOf(\RuntimeException::class, $records[0]->context['exception'] ?? null);

        // And it is out of service twice over: out of the enabled list for the
        // next boot, and on a record that says so even if that write was lost.
        self::assertSame(['fixture-main-throwing'], $this->disabled);

        $entry = $store->all()['fixture-main-throwing'] ?? null;

        self::assertNotNull($entry);
        self::assertSame(ExtensionFailureStore::TYPE_EXTENSION, $entry['type']);
        self::assertSame(\RuntimeException::class, $entry['class']);
        self::assertSame('The module could not be loaded', $entry['message']);
    }

    public function testAFailureThatIsAnErrorRatherThanAnExceptionIsCaughtTheSameWay(): void
    {
        $store = $this->store();

        // An extension whose dependency was uninstalled underneath it fails on a
        // class that cannot be loaded, which PHP raises as an Error. Isolating
        // exceptions alone - as this window did - leaves that one fatal, and it is
        // the everyday case rather than the exotic one.
        $this->loader($store)->load(['fixture-main-erroring', 'fixture-healthy'], null);

        self::assertSame(['fixture-healthy'], $this->loaded());

        $records = $this->log->getRecords();

        self::assertCount(1, $records);
        self::assertInstanceOf(\Error::class, $records[0]->context['exception'] ?? null);
        self::assertSame(['fixture-main-erroring'], $this->disabled);
        self::assertTrue($store->has('fixture-main-erroring'));
    }

    public function testAnExtensionOnRecordIsNotExecutedAgainOnTheNextBoot(): void
    {
        $store = $this->store();
        $store->record('fixture-main-throwing', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('the fault the boot before ran into'));

        // The enabled list still names it: the record is read exactly because the
        // request that failed may never have got as far as writing that list.
        $this->loader($store)->load(['fixture-main-throwing', 'fixture-healthy'], null);

        self::assertSame(['fixture-healthy'], $this->loaded());

        // Nothing is reported and nothing is recorded a second time. The failure
        // was written down once; repeating it on every request afterwards would
        // only bury it.
        self::assertSame([], $this->log->getRecords());
        self::assertSame([], $this->disabled);
        self::assertSame('the fault the boot before ran into', $store->all()['fixture-main-throwing']['message']);
    }

    public function testAnExtensionWhoseFileNeverRanIsReportedByPathAndThenByName(): void
    {
        $store = $this->store();
        $this->modules->register($this->fixture('throwing'));

        $this->loader($store)->load(['fixture-throwing'], null);

        $messages = $this->messages();

        self::assertCount(2, $messages);

        // The file is reported first, by path, because the name it would have
        // declared is what could not be read. The extension the site still
        // enables out of that file reads as an undefined module a moment later,
        // and in that order the log says why.
        self::assertStringContainsString($this->fixture('throwing'), $messages[0]);
        self::assertStringContainsString('during registration', $messages[0]);
        self::assertStringContainsString('Undefined module: fixture-throwing', $messages[1]);

        // A package that could not be executed leaves only one name behind - the
        // one the site configuration knows it by - and that is the name it is
        // taken out of service under.
        self::assertSame(['fixture-throwing'], $this->disabled);
        self::assertTrue($store->has('fixture-throwing'));
    }

    public function testTheThemeIsLoggedAndRecordedButNeverSwitchedOff(): void
    {
        $store = $this->store();

        $this->loader($store)->load([], 'fixture-main-throwing');

        self::assertCount(1, $this->log->getRecords());

        // Which theme a site uses is an administrator's setting, and a site whose
        // theme cannot be loaded already falls back to a blank layout while the
        // admin panel keeps a theme of its own. Overruling the setting would take
        // a decision that is not this barrier's to take.
        self::assertSame([], $this->disabled);
        self::assertSame(ExtensionFailureStore::TYPE_THEME, $store->all()['fixture-main-throwing']['type']);
    }

    public function testAThemeThatFailedBeforeIsTriedAgainRatherThanSkipped(): void
    {
        $store = $this->store();
        $store->record('fixture-healthy', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('the fault of the request before'));

        $this->loader($store)->load([], 'fixture-healthy');

        // The record keeps an extension from being executed because an extension
        // was switched off; a theme was not, so a theme that works again has to
        // come back on its own. Anything else would leave a site on a blank
        // layout with nothing to do about it.
        self::assertSame(['fixture-healthy'], $this->loaded());
        self::assertSame([], $this->log->getRecords());

        // Coming back means coming off the record. Nothing else on this path
        // ever finds out that a recorded theme runs again - an extension on the
        // record is not executed at all - so a record left behind here would go
        // on naming a working theme as broken in the admin panel until some
        // unrelated package operation happened to clear it.
        self::assertSame([], $store->all());
    }

    public function testAThemeThatCannotBeTakenOffTheRecordIsReportedAndKeepsRunning(): void
    {
        $this->store()->record('fixture-healthy', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('the fault of the request before'));

        $store = new ExtensionFailureStore($this->path, new FilesystemThatCannotWrite());

        $this->loader($store)->load([], 'fixture-healthy');

        // What is wrong is the notice, not the site: the theme is running and
        // the boot it is part of finishes. Ending the request over a warning
        // that stays up too long would cost more than the warning does.
        self::assertSame(['fixture-healthy'], $this->loaded());
        self::assertTrue($this->store()->has('fixture-healthy'));

        $messages = $this->messages();

        // Said out loud all the same, because an administrator looking at a
        // notice for a theme that works has no other way to find out why.
        self::assertCount(1, $messages);
        self::assertStringContainsString('fixture-healthy', $messages[0]);
        self::assertStringContainsString('could not be taken off the failure record', $messages[0]);
    }

    public function testAThemeThatFailsOnEveryRequestIsTriedAFewTimesAndThenLeftAlone(): void
    {
        $store = $this->store();
        $this->modules->register($this->fixture('main-counting'));

        // A module that fails is never registered as loaded, so the manager
        // executes it again on every call exactly as the next request's manager
        // would. What carries across those requests is the record on disk,
        // which is the only thing that can decide when to stop.
        for ($request = 1; $request <= ExtensionFailureStore::PAUSE_THRESHOLD + 3; $request++) {
            $this->loader($store)->load([], 'fixture-main-counting');
        }

        // A theme fails the way it was written to work - on a query, a remote
        // call, a file it parses - and that is what stops being paid for. The
        // requests after the last attempt still get their answer: the same
        // blank layout a failed load leaves behind, without the failure.
        self::assertSame(ExtensionFailureStore::PAUSE_THRESHOLD, $this->executions());
        self::assertCount(ExtensionFailureStore::PAUSE_THRESHOLD, $this->log->getRecords());
        self::assertSame(ExtensionFailureStore::PAUSE_THRESHOLD, $store->all()['fixture-main-counting']['count']);
    }

    public function testAThemeThatIsPausedIsNotExecutedEvenThoughItWouldLoadNow(): void
    {
        $store = $this->store();
        $this->recordFailures($store, 'fixture-healthy', ExtensionFailureStore::TYPE_THEME, ExtensionFailureStore::PAUSE_THRESHOLD);

        $this->loader($store)->load([], 'fixture-healthy');

        // Whether a theme works again is established by loading it, and this
        // barrier is the only place that ever does. A paused one is therefore
        // not asked - finding out is exactly what costs the request - and an
        // intact theme left unexecuted is what that looks like from here.
        self::assertSame([], $this->loaded());
        self::assertSame([], $this->log->getRecords());

        // Nothing was tried, so there is nothing new to record either: a
        // request that did no work does not walk the count further up.
        self::assertSame(ExtensionFailureStore::PAUSE_THRESHOLD, $store->all()['fixture-healthy']['count']);
    }

    public function testAThemeThatLoadsOneFailureShortOfThePauseComesOffTheRecord(): void
    {
        $store = $this->store();
        $this->recordFailures($store, 'fixture-healthy', ExtensionFailureStore::TYPE_THEME, ExtensionFailureStore::PAUSE_THRESHOLD - 1);

        $this->loader($store)->load([], 'fixture-healthy');

        // Up to the threshold the theme is tried as it always was, and a theme
        // that loads is a theme that is no longer broken. Its record goes, and
        // with it the count - so a failure after this one is a first failure
        // rather than the one that pauses the theme.
        self::assertSame(['fixture-healthy'], $this->loaded());
        self::assertSame([], $store->all());
    }

    public function testAThemeTakenOffTheRecordIsTriedAgainRatherThanStayingPaused(): void
    {
        $store = $this->store();
        $this->recordFailures($store, 'fixture-healthy', ExtensionFailureStore::TYPE_THEME, ExtensionFailureStore::PAUSE_THRESHOLD);

        $this->loader($store)->load([], 'fixture-healthy');

        self::assertSame([], $this->loaded());

        // Enabling the theme again is the way back, and clearing the record is
        // what that does. Without it an administrator who fixed the cause would
        // have no way to find out that they had - the site would stay on the
        // blank layout with the theme still selected.
        self::assertTrue($store->clear('fixture-healthy'));

        $this->loader($store)->load([], 'fixture-healthy');

        self::assertSame(['fixture-healthy'], $this->loaded());
        self::assertSame([], $this->log->getRecords());
    }

    public function testAThemeRecordedBeforeTheCountExistedIsTriedRatherThanPaused(): void
    {
        // An installation upgrading into the count has entries on record that
        // never carried one. Reading them as enough failures to pause would
        // pause a site's theme over the upgrade itself.
        $this->recordWithoutACount('fixture-main-throwing', ExtensionFailureStore::TYPE_THEME);

        $store = $this->store();

        $this->loader($store)->load([], 'fixture-main-throwing');

        self::assertCount(1, $this->log->getRecords());

        // One failure is what such an entry stands for - it was written by a
        // module that failed - so this request is the second, and the theme is
        // that much closer to being left alone rather than back at the start.
        self::assertSame(2, $store->all()['fixture-main-throwing']['count']);
    }

    public function testAnExtensionIsNotGivenTheAttemptsAThemeGets(): void
    {
        $store = $this->store();

        $store->record('fixture-main-throwing', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('the fault of the request before'));
        $store->record('fixture-healthy', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('the fault of the request before'));

        $this->loader($store)->load(['fixture-main-throwing'], 'fixture-healthy');

        // One failure is the end of it for an extension: it was taken out of
        // the enabled list, and an extension on the record is not executed at
        // all. The threshold belongs to the theme alone, which is the one
        // module executed while it is on the record.
        self::assertSame(['fixture-healthy'], $this->loaded());
        self::assertSame([], $this->log->getRecords());
        self::assertSame(['fixture-main-throwing'], array_keys($store->all()));
    }

    public function testAFailureIsRecordedEvenWhenTheExtensionCouldNotBeDisabled(): void
    {
        $store = $this->store();

        // The extension may have failed on the database, in which case the
        // enabled list is exactly what cannot be written. The record is written
        // anyway: it is the one thing left that keeps the extension off the next
        // boot.
        $this->loader($store, $this->disablerThatFails())->load(['fixture-main-throwing', 'fixture-healthy'], null);

        self::assertSame(['fixture-healthy'], $this->loaded());
        self::assertTrue($store->has('fixture-main-throwing'));

        $messages = $this->messages();

        // Trouble on the recovery path is reported on its own and never replaces
        // the failure the recovery was for.
        self::assertCount(2, $messages);
        self::assertStringContainsString('during load', $messages[0]);
        self::assertStringContainsString('could not be disabled', $messages[1]);
    }

    public function testAnExtensionWhoseFailureNeverReachedTheDatabaseStaysOffTheNextBoot(): void
    {
        $enabled = ['fixture-main-throwing', 'fixture-healthy'];

        $this->loader($this->store(), $this->disablerThatFails())->load($enabled, null);

        // Nothing was taken out of the enabled list, so the next boot is handed
        // the same one. A fresh manager stands in for that request: what it is
        // asked to execute is what reached the disk, not what the request before
        // it held.
        $this->registerModules('healthy', 'main-throwing');
        $this->log->clear();

        $this->loader($this->store())->load($enabled, null);

        self::assertSame(['fixture-healthy'], $this->loaded());
        self::assertSame([], $this->log->getRecords());
        self::assertTrue($this->store()->has('fixture-main-throwing'));
    }

    public function testARecordThatCouldNotBeWrittenIsReportedWithoutSilencingTheFailure(): void
    {
        $store = new ExtensionFailureStore($this->path, new FilesystemThatCannotWrite());

        $this->loader($store)->load(['fixture-main-throwing', 'fixture-healthy'], null);

        $messages = $this->messages();

        self::assertCount(2, $messages);
        self::assertStringContainsString('during load', $messages[0]);
        self::assertInstanceOf(\RuntimeException::class, $this->log->getRecords()[0]->context['exception'] ?? null);

        // A record that was lost is worth saying out loud: the extension will be
        // executed again on the next boot, so the same failure is about to
        // happen again and this line is the only warning of it.
        self::assertStringContainsString('could not be recorded', $messages[1]);

        // What could still be done was done.
        self::assertSame(['fixture-main-throwing'], $this->disabled);
        self::assertSame(['fixture-healthy'], $this->loaded());
    }

    public function testALogThatCannotBeWrittenCostsTheReportAndNotTheBoot(): void
    {
        $store = $this->store();

        // The log is the last place a failure can be reported to, which makes it
        // the last thing allowed to raise one: a full disk under tmp/logs would
        // otherwise take down the boot over the attempt to write about a broken
        // extension.
        $this->loader($store, null, new LoggerThatCannotWrite())->load(['fixture-main-throwing', 'fixture-healthy'], null);

        self::assertSame(['fixture-healthy'], $this->loaded());
        self::assertSame(['fixture-main-throwing'], $this->disabled);
        self::assertTrue($store->has('fixture-main-throwing'));
    }

    public function testASiteWithNowhereToKeepARecordStillGetsTheBarrier(): void
    {
        // The console and the installer boot without the directory the record
        // lives in. What they lose is what the next boot would have known, not
        // the barrier itself.
        $this->loader(null)->load(['fixture-main-throwing', 'fixture-healthy'], null);

        self::assertSame(['fixture-healthy'], $this->loaded());
        self::assertSame(['fixture-main-throwing'], $this->disabled);
        self::assertCount(1, $this->log->getRecords());
        self::assertDirectoryDoesNotExist($this->path);
    }

    public function testAnInstallationWithNothingBrokenLoadsEverythingAndReportsNothing(): void
    {
        $this->loader($this->store())->load(['fixture-healthy'], 'fixture-second');

        // Every request goes through this. An installation with nothing broken
        // must not grow a log line, a disabled extension or a record out of it.
        self::assertSame(['fixture-healthy', 'fixture-second'], $this->loaded());
        self::assertSame([], $this->log->getRecords());
        self::assertSame([], $this->disabled);
        self::assertDirectoryDoesNotExist($this->path);
    }

    public function testASiteWithoutAThemeLoadsNoneInsteadOfFailingOverIt(): void
    {
        // A site can be configured without a theme, and the boot behind this puts
        // a blank layout in its place. Looking up a theme that was never named
        // would report a failure over a site that has none.
        $this->loader($this->store())->load(['fixture-healthy'], null);

        self::assertSame(['fixture-healthy'], $this->loaded());
        self::assertSame([], $this->log->getRecords());
        self::assertDirectoryDoesNotExist($this->path);
    }

    /**
     * The barrier as the boot builds it, over the module manager these tests
     * registered their packages in.
     *
     * @param ExtensionFailureStore|null       $failures where a failure is kept for the next boot, or
     *                                                   null in a container that names no place for one
     * @param (\Closure(string): void)|null    $disable  how the enabled list is written, defaulting to
     *                                                   one that notes down what it was asked to disable
     */
    private function loader(?ExtensionFailureStore $failures, ?\Closure $disable = null, ?LoggerInterface $logger = null): ExtensionLoader
    {
        return new ExtensionLoader(
            $this->modules,
            $logger ?? $this->logger,
            $failures,
            $disable ?? function (string $name): void {
                $this->disabled[] = $name;
            },
        );
    }

    /**
     * An enabled list that cannot be written, as one whose database connection is
     * the reason the extension failed in the first place.
     *
     * @return \Closure(string): void
     */
    private function disablerThatFails(): \Closure
    {
        return function (string $name): void {
            throw new \RuntimeException('An exception occurred while executing a query');
        };
    }

    /**
     * The record as the application builds it: the directory it lives in and the
     * filesystem service that performs the write.
     */
    private function store(): ExtensionFailureStore
    {
        return new ExtensionFailureStore($this->path, new Filesystem());
    }

    /**
     * Puts a module on the record as many times as it failed, the way one
     * request after another does.
     *
     * @param ExtensionFailureStore::TYPE_* $type
     */
    private function recordFailures(ExtensionFailureStore $store, string $name, string $type, int $failures): void
    {
        for ($failure = 1; $failure <= $failures; $failure++) {
            self::assertTrue(
                $store->record($name, $type, new \RuntimeException('the fault of the request before')),
                'The record is what the next boot reads, so it has to be on disk before the load',
            );
        }
    }

    /**
     * Puts an entry on the record that carries no count, as every entry written
     * before there was one does.
     *
     * @param ExtensionFailureStore::TYPE_* $type
     */
    private function recordWithoutACount(string $name, string $type): void
    {
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }

        file_put_contents($this->path.'/'.self::FILE, (string) json_encode([
            $name => [
                'name' => $name,
                'type' => $type,
                'class' => \RuntimeException::class,
                'message' => 'the fault of the request before',
                'file' => '/app/packages/pagekit/blog/index.php',
                'line' => 7,
                'time' => 1700000000,
            ],
        ], JSON_FORCE_OBJECT));
    }

    /**
     * How often the counting fixture ran its own code, which is what every
     * request pays for as long as a broken theme keeps being tried.
     */
    private function executions(): int
    {
        return $this->app->has(self::EXECUTIONS) ? (int) $this->app->get(self::EXECUTIONS) : 0;
    }

    /**
     * The module manager of one request, over a container of its own: what a
     * boot loaded and what a module left behind stay in both, so a request
     * standing in for the next one needs new ones.
     */
    private function registerModules(string ...$fixtures): void
    {
        $this->app = new Application();
        $this->modules = new ModuleManager($this->app);
        $this->modules->register($this->fixtures(...$fixtures));
    }

    /**
     * The modules that finished loading, which is the only place the barrier's
     * decision to carry on is observable.
     *
     * @return array<int, string>
     */
    private function loaded(): array
    {
        $names = array_keys($this->modules->all());
        sort($names);

        return $names;
    }

    /**
     * @return array<int, string>
     */
    private function messages(): array
    {
        return array_map(fn (LogRecord $record) => $record->message, $this->log->getRecords());
    }

    /**
     * @return array<int, string>
     */
    private function fixtures(string ...$names): array
    {
        return array_map($this->fixture(...), $names);
    }

    private function fixture(string $name): string
    {
        return strtr(dirname(__DIR__, 2), '\\', '/').'/fixtures/modules/'.$name.'/index.php';
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
 * A filesystem whose write never happens, as a full disk or a read-only mount
 * makes it.
 */
final class FilesystemThatCannotWrite extends Filesystem
{
    public function dumpAtomic(string $file, string $content, ?int $mode = null): void
    {
        throw new \RuntimeException('Failed to write file');
    }
}

/**
 * A log that cannot be written to, as one whose directory is not writable or
 * whose disk is full is.
 */
final class LoggerThatCannotWrite extends AbstractLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('The stream or file "tmp/logs/debug.log" could not be opened');
    }
}
