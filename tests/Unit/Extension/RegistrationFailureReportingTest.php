<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Extension;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Pagekit\Application;
use Pagekit\Filesystem\Locator;
use Pagekit\Log\Logger;
use Pagekit\Module\Module;
use Pagekit\System\SystemModule;
use PHPUnit\Framework\TestCase;

/**
 * A package that could not be executed is found before the boot has a logger, so
 * its failure is held by the module manager until the system module runs. This is
 * where that handover happens, and it has to carry the throwable itself: the
 * report names the file, and the trace it puts in the log is all anyone has to
 * debug a package they did not write.
 *
 * The order matters as much as the content. An extension whose file failed was
 * never registered, so the site configuration still asks for a module that does
 * not exist - reporting the file first and the missing module second leaves the
 * two halves of one fault in the log in the order they happened, instead of a
 * bare "undefined module" with nothing saying why.
 */
final class RegistrationFailureReportingTest extends TestCase
{
    public function testEveryPackageThatCouldNotBeRegisteredIsReportedWithTheFailureItself(): void
    {
        $log = new TestHandler();

        $app = $this->boot(
            $log,
            packages: ['throwing', 'missing-class', 'healthy'],
            extensions: [],
            theme: 'fixture-healthy',
        );

        $records = $log->getRecords();

        self::assertCount(2, $records);

        // Each failure is reported on its own and names the file, because the
        // module name it would have declared is precisely what could not be read.
        self::assertSame(Level::Error, $records[0]->level);
        self::assertStringContainsString($this->fixture('throwing'), $records[0]->message);
        self::assertStringContainsString('The module file could not be executed', $records[0]->message);
        self::assertStringContainsString($this->fixture('missing-class'), $records[1]->message);

        // The throwable travels with the report. Its trace lands in the log, which
        // is the one place the fault is kept in full.
        self::assertInstanceOf(\RuntimeException::class, $records[0]->context['exception'] ?? null);
        self::assertInstanceOf(\Error::class, $records[1]->context['exception'] ?? null);

        // And the boot ran through to a usable site with two unusable packages
        // lying on disk.
        $theme = $app->get('theme');

        self::assertInstanceOf(Module::class, $theme);
        self::assertSame('fixture-healthy', $theme->name);
    }

    public function testTheExtensionBehindAFailedPackageIsReportedAfterTheFileThatCausedIt(): void
    {
        $log = new TestHandler();

        $this->boot(
            $log,
            packages: ['throwing', 'healthy'],
            extensions: ['fixture-throwing'],
            theme: 'fixture-healthy',
        );

        $messages = array_map(fn (LogRecord $record) => $record->message, $log->getRecords());

        self::assertCount(2, $messages);

        // The file is reported before the first extension is loaded, so the module
        // that is missing afterwards can be read as the consequence it is. The
        // other way round the log would explain nothing.
        self::assertStringContainsString($this->fixture('throwing'), $messages[0]);
        self::assertStringContainsString('Undefined module: fixture-throwing', $messages[1]);
    }

    public function testAnInstallationWhereEveryPackageRanLogsNothingOnBoot(): void
    {
        $log = new TestHandler();

        $this->boot(
            $log,
            packages: ['healthy'],
            extensions: [],
            theme: 'fixture-healthy',
        );

        // Every request walks past the collected failures. An installation with
        // nothing broken must not grow a log line out of that.
        self::assertSame([], $log->getRecords());
    }

    /**
     * Boots the system module the way the application does: the packages are
     * discovered first, then the module runs against the container they were
     * registered in.
     *
     * @param array<int, string> $packages   fixture directories standing in for the packages on disk
     * @param array<int, string> $extensions the module names the site configuration enables
     */
    private function boot(TestHandler $log, array $packages, array $extensions, string $theme): Application
    {
        $logger = new Logger('log');
        $logger->pushHandler($log);

        $app = new Application();
        $app->set('log', $logger);
        $app->set('locator', new Locator($this->root()));
        // The boot decorates the asset factory, which needs a definition to
        // decorate. Nothing resolves it here, so the decoration never runs.
        $app->set('assets', fn () => new \stdClass());

        $app->get('module')->register(array_map($this->fixture(...), $packages));

        $system = new SystemModule([
            'name' => 'system',
            'path' => '',
            'config' => [
                'site' => ['theme' => $theme],
                'extensions' => $extensions,
            ],
        ]);

        $system->main($app);

        return $app;
    }

    private function fixture(string $name): string
    {
        return $this->root().'/tests/fixtures/modules/'.$name.'/index.php';
    }

    private function root(): string
    {
        return strtr(dirname(__DIR__, 3), '\\', '/');
    }
}
