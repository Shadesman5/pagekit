<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\System;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Pagekit\Application;
use Pagekit\Auth\Event\LoginEvent;
use Pagekit\Auth\UserInterface;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Log\Logger;
use Pagekit\Session\MessageBag;
use Pagekit\System\SystemModule;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use PHPUnit\Framework\TestCase;

/**
 * What logging in does when the installation is behind the code it runs on.
 *
 * An administrator whose code is newer than their database is asked, at login,
 * whether anything is still owed: the system module's own updates and the
 * pending migrations. Both answers come from the installation itself - a file
 * the package ships and a service that talks to the database - and a
 * half-finished upgrade is precisely the state that breaks either of them.
 *
 * Which makes this listener the wrong place to fail. It runs on the way into
 * the panel the repair is made from, so a throw here answers the login with a
 * stack trace and leaves the administrator outside the one screen that could
 * fix it. The check is therefore allowed to fail: it is reported and the login
 * carries on.
 *
 * Recording the new version anyway would be the other way to get rid of the
 * error, and a worse one - it declares the update done and never offers it
 * again. So the recorded version is left exactly where it was, and the question
 * is asked again on the next login.
 *
 * The listener lives in the system module's index.php, which is a module
 * definition rather than an autoloaded class: it is pulled in below and bound
 * to the module, the way the event dispatcher binds a module's listeners.
 */
final class UpdateCheckOnLoginTest extends TestCase
{
    /**
     * The version recorded in the database, behind the code below.
     */
    private const RECORDED = '1.0.0';

    /**
     * The version of the code the site is running.
     */
    private const RUNNING = '2.0.0';

    private ConfigManagerWithSystemSettings $config;

    private TestHandler $log;

    /**
     * The flash storage of the session, from which what this request queued for
     * the next one can be read back.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $session = [];

    /**
     * Where the module looks for the lifecycle file it does not ship.
     */
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_update_check_'.getmypid().'_'.uniqid();

        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workspace)) {
            rmdir($this->workspace);
        }
    }

    public function testAnInstallationWithNothingOutstandingRecordsTheVersionItNowRuns(): void
    {
        $this->login($this->migrationsThatReport(['success' => true, 'has_pending' => false]));

        // Nothing is owed, so the installation is up to date by definition and
        // says so. Without this the same login would keep asking.
        self::assertSame(self::RUNNING, $this->recordedVersion());
        self::assertSame([], $this->log->getRecords());
        self::assertSame([], $this->session['new'] ?? []);
    }

    public function testAnUpdateThatIsStillOwedSendsTheAdministratorToTheMigrationScreen(): void
    {
        $event = $this->login($this->migrationsThatReport(['success' => true, 'has_pending' => true]));

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());

        // The version stays where it is until the migration screen has been
        // through. Recording it here would mark an update as done that has not
        // been run.
        self::assertSame(self::RECORDED, $this->recordedVersion());
    }

    public function testAnUpdateCheckThatFailsCostsTheCheckAndNotTheLogin(): void
    {
        $event = $this->login($this->migrationsThatFail());

        // The login goes through. Nothing is redirected, because there is no
        // answer to redirect on, and the panel is reached.
        self::assertNull($event->getResponse());

        // And the version is left alone: what is not known to be done is not
        // written down as done, so the next login asks again.
        self::assertSame(self::RECORDED, $this->recordedVersion());

        $records = $this->log->getRecords();

        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertStringContainsString('update check on login failed', $records[0]->message);
        self::assertInstanceOf(\RuntimeException::class, $records[0]->context['exception'] ?? null);

        // The administrator is told that the question could not be answered,
        // and no more than that: the fault itself can carry a query, a
        // connection string or a path, and it is already in the log.
        $notices = $this->session['new']['error'] ?? [];

        self::assertCount(1, $notices);
        self::assertStringContainsString('could not determine whether this installation needs an update', $notices[0]);
        self::assertStringNotContainsString('pk_migrations', $notices[0]);
    }

    public function testALogThatCannotBeWrittenCostsTheReportAndNotTheLogin(): void
    {
        $event = $this->login($this->migrationsThatFail(), logger: new LoggerThatCannotWrite());

        // Reporting is the last thing attempted and the last thing allowed to
        // raise anything of its own. A full disk under tmp/logs costs the line
        // about the failed check - it may not also cost the login.
        self::assertNull($event->getResponse());
        self::assertSame(self::RECORDED, $this->recordedVersion());
    }

    public function testASessionThatCannotBeWrittenCostsTheNoticeAndNotTheLogin(): void
    {
        $event = $this->login($this->migrationsThatFail(), message: new MessageBagThatCannotWrite());

        self::assertNull($event->getResponse());
        self::assertSame(self::RECORDED, $this->recordedVersion());

        // The log is written before the flash, so the failure is on record even
        // though the administrator was not told about it in the panel.
        self::assertCount(1, $this->log->getRecords());
    }

    /**
     * Logs an administrator in and hands back the event the listener saw.
     *
     * @param object $migration what answers whether the database is behind the code
     */
    private function login(object $migration, ?LoggerInterface $logger = null, ?MessageBag $message = null): LoginEvent
    {
        $this->log = new TestHandler();

        $log = new Logger('log');
        $log->pushHandler($this->log);

        $this->config = new ConfigManagerWithSystemSettings(self::RECORDED);
        $this->session = [];

        $message ??= new MessageBag();
        $message->initialize($this->session);

        $app = new Application();
        $app->set('version', self::RUNNING);
        $app->set('config', $this->config);
        $app->set('migration', $migration);
        $app->set('log', $logger ?? $log);
        $app->set('message', $message);
        $app->set('url', new UrlThatResolvesRoutes());
        $app->set('response', new ResponseThatRedirects());

        // The version the installation records is the module's own config, and
        // the lifecycle file is looked for beside the module. The system module
        // ships none, so what is still owed comes down to the migrations.
        $system = new SystemModule([
            'name' => 'system',
            'path' => $this->workspace,
            'config' => ['version' => self::RECORDED],
        ]);

        /** @var array{events: array{'auth.login': array{0: \Closure, 1: int}}} $module */
        $module = require dirname(__DIR__, 3).'/app/system/index.php';

        $listener = $module['events']['auth.login'][0]->bindTo($system, $system);

        self::assertNotNull($listener, 'The dispatcher binds a module listener to the module, which is what $this is here');

        $event = new LoginEvent('auth.login', new AdministratorWhoUpdates());

        $listener($event);

        return $event;
    }

    /**
     * What the installation currently records as its version - the one thing a
     * failed check may not change.
     */
    private function recordedVersion(): string
    {
        return (string) $this->config->system->get('version');
    }

    /**
     * A migration service that answers, as one whose database is reachable.
     *
     * @param array<string, mixed> $status
     */
    private function migrationsThatReport(array $status): object
    {
        return new class ($status) {
            /**
             * @param array<string, mixed> $status
             */
            public function __construct(private readonly array $status)
            {
            }

            /**
             * @return array<string, mixed>
             */
            public function status(): array
            {
                return $this->status;
            }
        };
    }

    /**
     * A migration service that cannot answer, as one whose schema is the half
     * of the upgrade that did not finish.
     */
    private function migrationsThatFail(): object
    {
        return new class () {
            /**
             * @return array<string, mixed>
             */
            public function status(): array
            {
                throw new \RuntimeException('An exception occurred while executing a query on "pk_migrations"');
            }
        };
    }
}

/**
 * The site configuration with the database taken out of it, keeping the system
 * settings the login writes the version into.
 */
final class ConfigManagerWithSystemSettings extends ConfigManager
{
    public Config $system;

    public function __construct(string $version)
    {
        $this->system = new Config(['version' => $version]);
    }

    public function get(string $name): ?Config
    {
        return $this->system;
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

/**
 * A session the notice cannot be queued in, as one whose storage is gone by the
 * time the login is answered.
 */
final class MessageBagThatCannotWrite extends MessageBag
{
    public function error(string $message): void
    {
        throw new \RuntimeException('Failed to start the session');
    }
}

/**
 * The route the administrator is sent back to once the migration screen is
 * through.
 */
final class UrlThatResolvesRoutes
{
    public function getRoute(string $name): string
    {
        return '/admin';
    }
}

/**
 * The response factory, reduced to the one thing this listener asks it for.
 */
final class ResponseThatRedirects
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function redirect(string $url, array $parameters = []): RedirectResponse
    {
        return new RedirectResponse('/admin/system/migration');
    }
}

/**
 * An account that is allowed to update the installation, which is the only one
 * the check runs for.
 */
final class AdministratorWhoUpdates implements UserInterface
{
    public function getId(): string
    {
        return '1';
    }

    public function getUsername(): string
    {
        return 'admin';
    }

    public function getPassword(): string
    {
        return '';
    }

    public function hasAccess(?string $expression): bool
    {
        return $expression === 'system: software updates';
    }
}
