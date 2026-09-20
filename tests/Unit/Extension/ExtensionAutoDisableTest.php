<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Extension;

use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\Locator;
use Pagekit\Log\Logger;
use Pagekit\Module\Module;
use Pagekit\System\Extension\ExtensionFailureStore;
use Pagekit\System\SystemModule;
use PHPUnit\Framework\TestCase;

/**
 * What the boot does with an extension that could not be loaded, wired the way
 * the application wires it.
 *
 * An extension is taken out of the enabled list and that list is written back
 * before the request ends - not left to the event that normally persists
 * configuration, because a request that just broke is not one to trust with
 * reaching its own end. The failure is also written to a file, in the directory
 * the boot names for it, and that file is what the next request reads: it is
 * still there when the database write was the thing that failed, and it is the
 * only reason a site whose database is unreachable does not run the same broken
 * extension on every request until someone finds the log.
 *
 * The theme keeps its own posture throughout. It is recorded like anything else
 * and the site falls back to a blank layout, but the enabled extensions are none
 * of a theme failure's business. Once it has failed on enough requests in a row
 * the boot stops executing it, and what the site serves is that same fallback -
 * reached without running the theme into its failure first. The setting stays as
 * the administrator left it either way.
 */
final class ExtensionAutoDisableTest extends TestCase
{
    private TestHandler $log;

    private string $workspace;

    /**
     * The directory the failure record lives in, which the container names as a
     * path and the boot only creates once there is a failure to write.
     */
    private string $path;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_extension_disable_'.getmypid().'_'.uniqid();
        $this->path = $this->workspace.'/system';

        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testAFailingExtensionIsTakenOutOfTheEnabledListBeforeTheRequestEnds(): void
    {
        $config = new ConfigManagerThatRecords(['fixture-main-throwing', 'fixture-healthy']);

        $app = $this->boot(
            extensions: ['fixture-main-throwing', 'fixture-healthy'],
            config: $config,
        );

        // The list was written during the boot. Nothing here fires the terminate
        // event that normally persists configuration, so an enabled list left to
        // that event would still name the broken extension.
        self::assertCount(1, $config->persisted);
        self::assertNotContains('fixture-main-throwing', $config->persisted[0]);
        self::assertContains('fixture-healthy', $config->persisted[0]);

        // The site it left behind is a working one.
        self::assertInstanceOf(Module::class, $app->get('module')->get('fixture-healthy'));
        self::assertCount(1, $this->log->getRecords());
    }

    public function testTheRecordOfAFailureLandsWhereTheNextRequestReadsIt(): void
    {
        $this->boot(
            extensions: ['fixture-main-throwing'],
            config: new ConfigManagerThatRecords(['fixture-main-throwing']),
            systemPath: $this->path,
        );

        // Read back through a store of its own, the way the next request and the
        // extension manager read it: what matters is that the boot put the record
        // in the directory the container names, not that some file was written.
        $store = new ExtensionFailureStore($this->path, new Filesystem());

        self::assertTrue($store->has('fixture-main-throwing'));
        self::assertSame(ExtensionFailureStore::TYPE_EXTENSION, $store->all()['fixture-main-throwing']['type']);
    }

    public function testAnExtensionThatBrokeARequestIsNotExecutedOnTheNextOne(): void
    {
        // The database is what failed, so the enabled list could not be written
        // and the next request is handed the same one.
        $enabled = ['fixture-main-throwing', 'fixture-healthy'];

        $this->boot(
            extensions: $enabled,
            config: new ConfigManagerThatCannotWrite(),
            systemPath: $this->path,
        );

        self::assertCount(2, $this->messages());

        $app = $this->boot(
            extensions: $enabled,
            config: new ConfigManagerThatCannotWrite(),
            systemPath: $this->path,
        );

        // The record carried the failure across the request boundary on its own,
        // which is the whole point of it being a file: the site comes up without
        // the broken extension and without repeating its failure in the log.
        self::assertNull($app->get('module')->get('fixture-main-throwing'));
        self::assertInstanceOf(Module::class, $app->get('module')->get('fixture-healthy'));
        self::assertSame([], $this->log->getRecords());
    }

    public function testAFailingThemeIsRecordedWithoutTouchingTheEnabledExtensions(): void
    {
        $config = new ConfigManagerThatRecords(['fixture-healthy']);

        $app = $this->boot(
            extensions: ['fixture-healthy'],
            theme: 'fixture-main-throwing',
            config: $config,
            systemPath: $this->path,
        );

        // A theme is an administrator's choice and stays selected. Pulling it out
        // of the enabled extensions would not even be the same setting.
        self::assertSame([], $config->persisted);
        self::assertSame(
            ExtensionFailureStore::TYPE_THEME,
            (new ExtensionFailureStore($this->path, new Filesystem()))->all()['fixture-main-throwing']['type']
        );

        // What a site with an unusable theme falls back to: a layout that renders
        // nothing but the page, with the admin panel's own theme untouched.
        $theme = $app->get('theme');

        self::assertInstanceOf(Module::class, $theme);
        self::assertSame('theme-default', $theme->name);
        self::assertSame('views:system/blank.php', $theme->get('layout'));
    }

    public function testAThemeThatFailedTooOftenIsNotExecutedByTheBootAtAll(): void
    {
        $store = new ExtensionFailureStore($this->path, new Filesystem());

        for ($failure = 1; $failure <= ExtensionFailureStore::PAUSE_THRESHOLD; $failure++) {
            self::assertTrue($store->record('fixture-main-throwing', ExtensionFailureStore::TYPE_THEME, new \RuntimeException('The module could not be loaded')));
        }

        $config = new ConfigManagerThatRecords(['fixture-healthy']);

        $app = $this->boot(
            extensions: ['fixture-healthy'],
            theme: 'fixture-main-throwing',
            config: $config,
            systemPath: $this->path,
        );

        // What the site serves is what it serves after any failed theme: the
        // blank layout, with the admin panel's own theme untouched and the rest
        // of the installation up. What it no longer does is run the theme into
        // its failure first, on this request and on every request after it.
        $theme = $app->get('theme');

        self::assertInstanceOf(Module::class, $theme);
        self::assertSame('theme-default', $theme->name);
        self::assertSame('views:system/blank.php', $theme->get('layout'));

        self::assertSame([], $this->log->getRecords());
        self::assertSame(ExtensionFailureStore::PAUSE_THRESHOLD, $store->all()['fixture-main-throwing']['count']);
        self::assertInstanceOf(Module::class, $app->get('module')->get('fixture-healthy'));

        // The theme is still the one the administrator selected. Writing a
        // different one into the configuration would be this boot overruling a
        // deliberate choice on a visitor-facing request, and it is also what
        // would leave nothing to enable again.
        self::assertSame([], $config->persisted);
    }

    public function testASiteThatCannotWriteItsConfigurationStillFinishesBooting(): void
    {
        $app = $this->boot(
            extensions: ['fixture-main-throwing', 'fixture-healthy'],
            config: new ConfigManagerThatCannotWrite(),
            systemPath: $this->path,
        );

        $messages = $this->messages();

        // The database being unreachable is a plausible reason for the extension
        // to have failed at all. It costs the enabled list, is reported on its
        // own, and does not cost the record or the rest of the boot.
        self::assertCount(2, $messages);
        self::assertStringContainsString('during load', $messages[0]);
        self::assertStringContainsString('could not be disabled', $messages[1]);

        self::assertTrue((new ExtensionFailureStore($this->path, new Filesystem()))->has('fixture-main-throwing'));
        self::assertInstanceOf(Module::class, $app->get('module')->get('fixture-healthy'));
        self::assertInstanceOf(Module::class, $app->get('theme'));
    }

    public function testAContainerWithoutAConfigurationServiceSurvivesAFailureToo(): void
    {
        // The installer boots the system module against a container that has no
        // configuration service yet, so there is no enabled list to take the
        // extension out of - and nothing to fail over either.
        $app = $this->boot(extensions: ['fixture-main-throwing', 'fixture-healthy'], systemPath: $this->path);

        self::assertCount(1, $this->log->getRecords());
        self::assertTrue((new ExtensionFailureStore($this->path, new Filesystem()))->has('fixture-main-throwing'));
        self::assertInstanceOf(Module::class, $app->get('module')->get('fixture-healthy'));
    }

    public function testAContainerThatNamesNoDirectoryForTheRecordKeepsNoneAndBootsAnyway(): void
    {
        $config = new ConfigManagerThatRecords(['fixture-main-throwing', 'fixture-healthy']);

        $app = $this->boot(
            extensions: ['fixture-main-throwing', 'fixture-healthy'],
            config: $config,
        );

        // A caller asking the container for the record has one question to ask,
        // and it answers the same as whether the record can be resolved at all.
        self::assertFalse($app->has('extension.failures'));

        // The barrier is still a barrier: the failure is reported, the extension
        // disabled, the boot finished. Only the next boot is poorer for it.
        self::assertCount(1, $this->log->getRecords());
        self::assertNotContains('fixture-main-throwing', $config->persisted[0]);
        self::assertInstanceOf(Module::class, $app->get('module')->get('fixture-healthy'));
        self::assertDirectoryDoesNotExist($this->path);
    }

    /**
     * Boots the system module the way the application does: the packages on disk
     * are discovered first, then the module runs against the container they were
     * registered in.
     *
     * @param array<int, string> $extensions the module names the site configuration enables
     * @param string|null        $systemPath the directory the failure record lives in, or null in a
     *                                       container that names none
     */
    private function boot(
        array $extensions,
        string $theme = 'fixture-second',
        ?ConfigManager $config = null,
        ?string $systemPath = null,
    ): Application {
        $this->log = new TestHandler();

        $logger = new Logger('log');
        $logger->pushHandler($this->log);

        $app = new Application();
        $app->set('log', $logger);
        $app->set('locator', new Locator($this->root()));
        $app->set('file', fn () => new Filesystem());
        // The boot decorates the asset factory, which needs a definition to
        // decorate. Nothing resolves it here, so the decoration never runs.
        $app->set('assets', fn () => new \stdClass());

        if ($config !== null) {
            $app->set('config', $config);
        }

        if ($systemPath !== null) {
            $app->set('path.system', $systemPath);
        }

        $app->get('module')->register($this->fixtures('healthy', 'second', 'main-throwing'));

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
        return array_map(fn (string $name) => $this->root().'/tests/fixtures/modules/'.$name.'/index.php', $names);
    }

    private function root(): string
    {
        return strtr(dirname(__DIR__, 3), '\\', '/');
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
 * The site configuration with the database taken out of it: it hands out the
 * system settings and notes down the enabled list every time one is written.
 */
final class ConfigManagerThatRecords extends ConfigManager
{
    /**
     * The enabled lists that reached the store, in the order they were written.
     *
     * @var array<int, array<int|string, mixed>>
     */
    public array $persisted = [];

    private Config $system;

    /**
     * @param array<int, string> $extensions the module names the site configuration enables
     */
    public function __construct(array $extensions)
    {
        $this->system = new Config(['extensions' => $extensions]);
    }

    public function get(string $name): ?Config
    {
        return $this->system;
    }

    public function set(string $name, Config|array $config): void
    {
        $values = $config instanceof Config ? $config->get('extensions') : ($config['extensions'] ?? null);

        $this->persisted[] = (array) $values;
    }
}

/**
 * A site configuration that cannot be written, which is the state of one whose
 * database is exactly what the failing extension broke on.
 */
final class ConfigManagerThatCannotWrite extends ConfigManager
{
    public function __construct()
    {
    }

    public function get(string $name): ?Config
    {
        return new Config(['extensions' => []]);
    }

    public function set(string $name, Config|array $config): void
    {
        throw new \RuntimeException('An exception occurred while executing a query');
    }
}
