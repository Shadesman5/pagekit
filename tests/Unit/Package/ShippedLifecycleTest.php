<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Pagekit\Application;
use Pagekit\Installer\Package\Lifecycle\LifecycleRunner;
use PHPUnit\Framework\TestCase;

/**
 * The lifecycle files Pagekit itself ships.
 *
 * Two packages in the tree declare one. The system's seeds the preferences a
 * fresh installation starts with, the blog's runs the extension's own schema
 * migrations, and both are read at a moment nobody is watching: the last step
 * of an installation, the activation of an extension. A file that does not
 * deliver a lifecycle is reported at the first hook asked of it rather than
 * skipped, so a broken one costs an installation that cannot finish - the right
 * failure to have, and a poor one to discover there.
 *
 * They are run through the runner a package operation reads them with, against
 * a container that records what they asked of it.
 */
final class ShippedLifecycleTest extends TestCase
{
    private const SYSTEM = 'app/system/scripts.php';

    private const BLOG = 'packages/pagekit/blog/scripts.php';

    public function testTheFilesTheTreeShipsAreReadableAsLifecycles(): void
    {
        foreach ([self::SYSTEM, self::BLOG] as $file) {
            // Asking what a lifecycle schedules is enough to read the file, so a
            // file that cannot deliver one fails here - and neither of these
            // carries a data change a fresh installation would have to run.
            self::assertFalse(
                $this->runner($file, new Application())->hasUpdates(),
                sprintf('%s has to deliver a lifecycle that can be asked what it schedules', $file),
            );
        }
    }

    // ------------------------------------------------------------------
    // The system's own lifecycle
    // ------------------------------------------------------------------

    public function testInstallingSeedsThePreferencesAFreshInstallationStartsWith(): void
    {
        $config = new RecordedConfig();

        $app = new Application();
        $app->set('config', $config);

        $this->runner(self::SYSTEM, $app)->install();

        // The tables come from the migrations; what is left for this file is
        // configuration. An installation that finishes without it has no
        // dashboard and no menu to hang its pages on, and an administrator has
        // to assemble both by hand before the site shows anything.
        self::assertSame(['system/dashboard', 'system/site'], array_keys($config->written));
        self::assertNotSame([], $config->written['system/dashboard']);
        self::assertSame(
            ['main' => ['id' => 'main', 'label' => 'Main']],
            $config->written['system/site']['menus'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // The blog's lifecycle
    // ------------------------------------------------------------------

    public function testTheBlogsSchemaIsBroughtUpToDateOnInstallAndOnEveryActivation(): void
    {
        $migration = new RecordedMigrations();

        $app = new Application();
        $app->set('migration', $migration);

        $runner = $this->runner(self::BLOG, $app);
        $runner->install();
        $runner->enable();

        // Both hooks migrate, because migrating is idempotent: a fresh
        // installation has every migration to run and an activation has
        // whatever an update added since the last one. An enable that skipped it
        // would run an updated extension against the schema of the version
        // before it.
        self::assertCount(2, $migration->migrated);
        self::assertSame($migration->migrated[0], $migration->migrated[1]);
    }

    public function testTheBlogDeclaresTheMigrationsItActuallyShips(): void
    {
        $migration = new RecordedMigrations();

        $app = new Application();
        $app->set('migration', $migration);

        $this->runner(self::BLOG, $app)->enable();

        self::assertCount(1, $migration->migrated);

        [$namespace, $path] = $migration->migrated[0];

        // A namespace and a directory are all the migration service is given,
        // and nothing checks them against each other: a rename that leaves this
        // file behind is an extension that migrates nothing and says it worked.
        self::assertSame('Pagekit\Blog\Migrations', $namespace);
        self::assertDirectoryExists($path);

        $shipped = $this->migrationsIn($path);

        self::assertNotSame([], $shipped, 'The declared directory has to be the one holding the migration classes');
        self::assertStringContainsString(
            'namespace ' . $namespace . ';',
            (string) file_get_contents($shipped[0]),
            'The declared namespace has to be the one the migration classes are declared in',
        );
    }

    public function testAFailedBlogMigrationIsReportedWithWhatWentWrong(): void
    {
        $app = new Application();
        $app->set('migration', new RecordedMigrations(['success' => false, 'error' => 'blog_post already exists']));

        // The failure has to travel: it is what turns an activation into an
        // error an administrator can act on, instead of an extension reported as
        // enabled whose tables were never created.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blog_post already exists');

        $this->runner(self::BLOG, $app)->enable();
    }

    public function testRemovingTheBlogKeepsItsPostsAndOnlyForgetsWhatWasCached(): void
    {
        $cache = new RecordedCache();
        $migration = new RecordedMigrations();

        $app = new Application();
        $app->set('cache', $cache);
        $app->set('migration', $migration);

        $this->runner(self::BLOG, $app)->uninstall();

        // Dropping the tables is a decision for whoever removes the extension,
        // not for the extension: an administrator who reinstalls after a mistake
        // gets their posts back. What has to go is the cache, which still holds
        // the routes and views of an extension that is leaving.
        self::assertSame([], $migration->rolledBack);
        self::assertSame(1, $cache->cleared);
    }

    public function testRemovingTheBlogWhereNothingIsCachedGoesThroughAllTheSame(): void
    {
        // A package is removed from the console and from the installer too, and
        // neither of those containers has a cache to clear.
        $app = new Application();
        $app->set('migration', new RecordedMigrations());

        $this->runner(self::BLOG, $app)->uninstall();

        self::assertFalse($app->has('cache'));
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * A runner over a lifecycle file in the tree, as a package operation builds
     * one: no recorded version, so nothing is filtered out as already passed.
     */
    private function runner(string $relative, Application $app): LifecycleRunner
    {
        $file = strtr(dirname(__DIR__, 3), '\\', '/') . '/' . $relative;

        self::assertFileExists($file);

        return new LifecycleRunner($file, null, $app);
    }

    /**
     * The migration classes under a directory, which the service collects the
     * same way: whatever year folders they were organised into.
     *
     * @return array<int, string>
     */
    private function migrationsIn(string $path): array
    {
        $files = [];

        /** @var \SplFileInfo $entry */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile() && str_starts_with($entry->getFilename(), 'Version')) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}

/**
 * What was written to the configuration, in place of the manager that would put
 * it in the database.
 */
final class RecordedConfig
{
    /** @var array<string, array<string, mixed>> */
    public array $written = [];

    /**
     * @param array<string, mixed> $config
     */
    public function set(string $name, array $config): void
    {
        $this->written[$name] = $config;
    }
}

/**
 * The migration service, recording what it was asked to run rather than running
 * it, and answering what the extension is told about the outcome.
 */
final class RecordedMigrations
{
    /** @var array<int, array{0: string, 1: string}> */
    public array $migrated = [];

    /** @var array<int, array{0: string, 1: string, 2: string|null}> */
    public array $rolledBack = [];

    /**
     * @param array<string, mixed> $result
     */
    public function __construct(private readonly array $result = ['success' => true, 'executed' => 1])
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function migrateExtension(string $namespace, string $path, ?string $version = null): array
    {
        $this->migrated[] = [$namespace, $path];

        return $this->result;
    }

    /**
     * @return array<string, mixed>
     */
    public function rollbackExtension(string $namespace, string $path, ?string $version = null): array
    {
        $this->rolledBack[] = [$namespace, $path, $version];

        return ['success' => true];
    }
}

/**
 * The cache an extension on its way out asks to be forgotten.
 */
final class RecordedCache
{
    public int $cleared = 0;

    public function clear(): bool
    {
        ++$this->cleared;

        return true;
    }
}
