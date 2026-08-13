<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\System;

use Pagekit\Application;
use Pagekit\Application\Response;
use Pagekit\Application\UrlProvider;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Routing\Router;
use Pagekit\Session\MessageBag;
use Pagekit\System\Controller\MigrationController;
use Pagekit\System\SystemModule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The screen an administrator updates the installation from.
 *
 * An update has two stages: the Doctrine migrations, which own the schema, and
 * the version-keyed updates the system's own lifecycle file declares, which own
 * the data changes a new version needs. Where those updates come from is the
 * lifecycle file read from beside the module - a path that need not exist, and
 * that in a normal installation declares no update at all. Neither absence is a
 * broken installation: it is one whose migrations are the only thing still owed,
 * and the screen has to keep working out of exactly that.
 *
 * Which is what the two questions asked here come down to. Whether anything is
 * owed decides whether the screen is put up at all - an administrator sent to a
 * screen offering an update that would do nothing is invited to run one. And the
 * version the installation records decides whether it is ever offered again, so
 * it is written once a run is through and not before: recorded after a stage
 * failed, it declares an update done that never ran, and no later request offers
 * it again.
 */
final class MigrationControllerTest extends TestCase
{
    /**
     * The version the installation records, behind the code below.
     */
    private const RECORDED = '1.0.0';

    /**
     * The version of the code the site is running.
     */
    private const RUNNING = '2.0.0';

    /**
     * Stands in for the system module's directory, which is where the lifecycle
     * file is read from and where an update that ran leaves its mark.
     */
    private string $workspace;

    /**
     * The system settings, from which the recorded version can be read back.
     */
    private Config $settings;

    private MessageBag $message;

    /**
     * The flash storage of the session, holding what this request queued for the
     * next one.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $session = [];

    private Application $app;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_migration_controller_' . getmypid() . '_' . uniqid();

        mkdir($this->workspace, 0755, true);

        $this->settings = new Config(['version' => self::RECORDED]);
        $this->session = [];
        $this->app = new Application();

        $this->message = new MessageBag();
        $this->message->initialize($this->session);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    // ------------------------------------------------------------------
    // Whether the screen is put up at all
    // ------------------------------------------------------------------

    public function testAnInstallationWithNothingOwedIsSentPastTheUpdateScreen(): void
    {
        // What a normal installation ships: a lifecycle with hooks for install
        // and none of the data changes an update would run.
        $this->writeLifecycleWithoutUpdates();

        $controller = $this->controller($this->migrationsThatReport(pending: false));

        $sentBack = $controller->indexAction('/admin/system');

        self::assertInstanceOf(RedirectResponse::class, $sentBack);
        self::assertSame('/admin/system', $sentBack->getTargetUrl());

        // And to the system screen for an administrator who arrived without one:
        // the update screen is not a place to be left standing.
        $sentOn = $controller->indexAction();

        self::assertInstanceOf(RedirectResponse::class, $sentOn);
        self::assertSame('@system', $sentOn->getTargetUrl());
    }

    public function testAnUpdateTheSystemDeclaresPutsTheUpdateScreenUp(): void
    {
        $this->writeLifecycleWithUpdate(self::RUNNING, "file_put_contents(__DIR__ . '/update-ran.marker', 'ok');");

        $screen = $this->controller($this->migrationsThatReport(pending: false))->indexAction('/admin/system');

        self::assertIsArray($screen);
        self::assertSame('system/theme:views/migration.php', $screen['$view']['name'] ?? null);

        // Where the administrator came from is carried through the screen, so
        // that running the update returns them there rather than to the panel's
        // front page.
        self::assertSame('/admin/system', $screen['redirect'] ?? null);

        // Putting the screen up is all this does. The update itself runs when it
        // is asked for, not when it is offered.
        self::assertFileDoesNotExist($this->workspace . '/update-ran.marker');
    }

    public function testAPendingDatabaseMigrationPutsTheScreenUpWithoutAnyDeclaredUpdate(): void
    {
        // No lifecycle file at all, which is the other shape a system with
        // nothing to declare has. The schema is still behind the code, and a
        // lifecycle with nothing to say may not answer for the migrations.
        $screen = $this->controller($this->migrationsThatReport(pending: true))->indexAction();

        self::assertIsArray($screen);
        self::assertSame('system/theme:views/migration.php', $screen['$view']['name'] ?? null);
    }

    // ------------------------------------------------------------------
    // Running the update
    // ------------------------------------------------------------------

    public function testTheDeclaredUpdatesRunAndTheVersionTheyBringTheInstallationToIsRecorded(): void
    {
        $this->writeLifecycleWithUpdate(self::RUNNING, "file_put_contents(__DIR__ . '/update-ran.marker', 'ok');");

        $answer = $this->runUpdate($this->migrationsThatRan(0));

        self::assertFileExists($this->workspace . '/update-ran.marker');
        self::assertTrue($answer['status']);
        self::assertStringContainsString('updated successfully', (string) $answer['message']);

        // The run is through, so the installation is on the version the code is
        // on and the screen is not offered again.
        self::assertSame(self::RUNNING, $this->settings->get('version'));
    }

    public function testAnUpdateThatIsAlreadyBehindTheInstallationIsNotRunAgain(): void
    {
        // An update keyed at or below the recorded version has already run.
        // Running it again is how a data change that is not idempotent doubles
        // whatever it did the first time.
        $this->writeLifecycleWithUpdate(self::RECORDED, "file_put_contents(__DIR__ . '/stale-update-ran.marker', 'ok');");

        $answer = $this->runUpdate($this->migrationsThatRan(0));

        self::assertFileDoesNotExist($this->workspace . '/stale-update-ran.marker');
        self::assertFalse($answer['status']);
        self::assertStringContainsString('up to date', (string) $answer['message']);
        self::assertSame(self::RUNNING, $this->settings->get('version'));
    }

    public function testAnUpdateThatFailsIsReportedAndLeavesTheRecordedVersionWhereItWas(): void
    {
        $this->writeLifecycleWithUpdate(self::RUNNING, "throw new \\RuntimeException('the update could not finish');");

        $response = $this->controller($this->migrationsThatRan(0))->migrateAction();

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(
            'Script update failed: the update could not finish',
            $this->answerOf($response)['message'] ?? null,
        );

        // Recording the version here would declare an update done that threw
        // half way, and no later request would offer it again.
        self::assertSame(self::RECORDED, $this->settings->get('version'));
    }

    public function testAFailedDatabaseMigrationStopsBeforeTheDeclaredUpdatesRun(): void
    {
        $this->writeLifecycleWithUpdate(self::RUNNING, "file_put_contents(__DIR__ . '/update-ran.marker', 'ok');");

        $response = $this->controller($this->migrationsThatFail('the database could not be reached'))->migrateAction();

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString(
            'the database could not be reached',
            (string) ($this->answerOf($response)['message'] ?? ''),
        );

        // The data changes are written against the schema the migrations create,
        // so a schema that did not get there is a reason not to run them.
        self::assertFileDoesNotExist($this->workspace . '/update-ran.marker');
        self::assertSame(self::RECORDED, $this->settings->get('version'));
    }

    public function testAnInstallationWithNoLifecycleOfItsOwnReportsWhatTheMigrationsDid(): void
    {
        // No lifecycle file to read: whether anything happened comes down to the
        // migrations alone, and the absence may not report a run as empty.
        $answer = $this->runUpdate($this->migrationsThatRan(2));

        self::assertTrue($answer['status']);
        self::assertStringContainsString('updated successfully', (string) $answer['message']);
        self::assertSame(self::RUNNING, $this->settings->get('version'));
    }

    public function testAnInstallationWithNothingLeftToDoSaysSoAndStillRecordsTheVersion(): void
    {
        $answer = $this->runUpdate($this->migrationsThatRan(0));

        // Nothing ran, which is not a failure: the code is newer than what the
        // installation records, and once that is written down the update is not
        // offered again.
        self::assertFalse($answer['status']);
        self::assertStringContainsString('up to date', (string) $answer['message']);
        self::assertSame(self::RUNNING, $this->settings->get('version'));
    }

    public function testAnUpdateStartedFromAScreenReturnsToItWithTheOutcome(): void
    {
        $this->writeLifecycleWithUpdate(self::RUNNING, "file_put_contents(__DIR__ . '/update-ran.marker', 'ok');");

        $response = $this->controller($this->migrationsThatRan(0))->migrateAction('/admin/system');

        // Asked for from a screen rather than by the panel's own request, the
        // answer is the screen the administrator came from plus the outcome to
        // show there.
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/system', $response->getTargetUrl());
        self::assertFileExists($this->workspace . '/update-ran.marker');

        $notices = $this->session['new'][MessageBag::SUCCESS] ?? [];

        self::assertCount(1, $notices);
        self::assertStringContainsString('updated successfully', $notices[0]);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * The controller as the panel builds it, over a system module whose
     * directory is the workspace the lifecycle file is written into.
     *
     * @param object|null $migration what runs the schema migrations, where the
     *                               container has such a service at all
     */
    private function controller(?object $migration = null): MigrationController
    {
        if ($migration !== null) {
            $this->app->set('migration', $migration);
        }

        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($this->settings);

        $router = $this->createMock(Router::class);
        $router->method('redirect')->willReturnCallback(
            static fn (string $url): RedirectResponse => new RedirectResponse($url),
        );

        return new MigrationController(
            new SystemModule([
                'name' => 'system',
                'path' => $this->workspace,
                'config' => ['version' => self::RECORDED],
            ]),
            $config,
            self::RUNNING,
            $this->message,
            new Response($this->createMock(UrlProvider::class)),
            $router,
            $this->app,
        );
    }

    /**
     * Runs the update the way the panel asks for it - without a screen to return
     * to - and hands back the answer it is given.
     *
     * @return array<string, mixed>
     */
    private function runUpdate(object $migration): array
    {
        $response = $this->controller($migration)->migrateAction();

        self::assertInstanceOf(JsonResponse::class, $response);

        return $this->answerOf($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function answerOf(JsonResponse $response): array
    {
        return (array) json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A migration service that answers whether the schema is behind the code, as
     * one whose database is reachable.
     */
    private function migrationsThatReport(bool $pending): object
    {
        return new class ($pending) {
            public function __construct(private readonly bool $pending)
            {
            }

            /**
             * @return array<string, mixed>
             */
            public function status(): array
            {
                return ['success' => true, 'has_pending' => $this->pending, 'available' => [], 'executed' => []];
            }
        };
    }

    /**
     * A migration service whose migrations went through, having found the given
     * number of them to run.
     */
    private function migrationsThatRan(int $executed): object
    {
        return new class ($executed) {
            public function __construct(private readonly int $executed)
            {
            }

            /**
             * @return array<string, mixed>
             */
            public function migrate(?string $version = null, bool $dryRun = false): array
            {
                return ['success' => true, 'executed' => $this->executed, 'time' => 0.0, 'sql' => []];
            }
        };
    }

    /**
     * A migration service that could not run the schema migrations, as one whose
     * database is the half of the upgrade that is not there yet.
     */
    private function migrationsThatFail(string $error): object
    {
        return new class ($error) {
            public function __construct(private readonly string $error)
            {
            }

            /**
             * @return array<string, mixed>
             */
            public function migrate(?string $version = null, bool $dryRun = false): array
            {
                return ['success' => false, 'error' => $this->error];
            }
        };
    }

    /**
     * The lifecycle a normal installation ships: hooks for the moments it cares
     * about, and no data change an update would have to run.
     */
    private function writeLifecycleWithoutUpdates(): void
    {
        $this->writeLifecycle(<<<'PHP'
            return new class () extends PackageLifecycle {};
            PHP);
    }

    /**
     * A lifecycle declaring one data change under the given version, which is
     * what the system's scripts.php is read as.
     */
    private function writeLifecycleWithUpdate(string $version, string $body): void
    {
        $lifecycle = str_replace(
            ['{VERSION}', '{BODY}'],
            [$version, $body],
            <<<'PHP'
                return new class () extends PackageLifecycle {
                    public function updates(): array
                    {
                        return [
                            '{VERSION}' => function (ContainerInterface $app): void {
                                {BODY}
                            },
                        ];
                    }
                };
                PHP,
        );

        $this->writeLifecycle($lifecycle);
    }

    private function writeLifecycle(string $lifecycle): void
    {
        $file = str_replace(
            '{LIFECYCLE}',
            $lifecycle,
            <<<'PHP'
                <?php

                declare(strict_types=1);

                use Pagekit\Installer\Package\Lifecycle\PackageLifecycle;
                use Psr\Container\ContainerInterface;

                {LIFECYCLE}
                PHP,
        );

        file_put_contents($this->workspace . '/scripts.php', $file);
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
            $this->removeTree($path . '/' . $entry);
        }

        rmdir($path);
    }
}
