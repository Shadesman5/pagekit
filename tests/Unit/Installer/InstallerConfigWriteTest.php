<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Installer;

use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Installer;
use PHPUnit\Framework\TestCase;

/**
 * config.php is the whole of what an installation keeps of the connection it was
 * set up with, and it is written once, at the very end, after the schema and the
 * administrator are already in the database. A half-written file there leaves a
 * site that cannot boot at all, so the configuration is moved into place in a
 * single step - and where it cannot be written the installation says so, because
 * an install reported as finished without its configuration is the same dead site
 * with nobody looking for the cause.
 */
final class InstallerConfigWriteTest extends TestCase
{
    /**
     * What the person installing chose, on its way into config.php.
     */
    private const DATABASE = [
        'database' => [
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['path' => '/var/www/data/pagekit.db']],
        ],
    ];

    private const ADMINISTRATOR = [
        'username' => 'admin',
        'password' => 'not-a-real-password',
        'email' => 'admin@example.com',
    ];

    private string $workspace;

    /**
     * The application root, which is where config.php is written.
     */
    private string $root;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_installer_config_'.getmypid().'_'.uniqid();
        $this->root = $this->workspace.'/site';

        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        // A test that provoked an unwritable root has to hand it back before the
        // workspace can be removed.
        if (is_dir($this->root)) {
            chmod($this->root, 0755);
        }

        $this->removeTree($this->workspace);
    }

    public function testTheInstallationWritesTheConnectionItWasSetUpWith(): void
    {
        $result = $this->install();

        self::assertSame('success', $result['status']);

        $written = require $this->root.'/config.php';

        self::assertIsArray($written);
        self::assertSame(self::DATABASE['database'], $written['database']);
        self::assertFalse($written['application']['debug']);
        // The secret is the last thing set before the write, so a file carrying it
        // is a file that arrived whole.
        self::assertSame(64, strlen($written['system']['secret']));
    }

    public function testNothingIsLeftBesideTheConfigurationThatWasWritten(): void
    {
        $this->install();

        self::assertSame(['config.php'], $this->entries($this->root));
    }

    /**
     * The application root an archive was unpacked into is regularly one the web
     * server may read and not write. Everything up to here has already happened -
     * schema, administrator, extensions - so the one thing the installation must
     * not do is call that a success.
     */
    public function testAConfigurationThatCannotBeWrittenFailsTheInstallation(): void
    {
        chmod($this->root, 0555);

        if (is_writable($this->root)) {
            self::markTestSkipped('The test user writes into a read-only directory on this host');
        }

        $result = $this->install();

        self::assertSame('write-failed', $result['status']);
        self::assertSame("Can't write config.", $result['message']);
        self::assertSame([], $this->entries($this->root), 'A failed write leaves nothing behind either');
    }

    /**
     * @return array<string, string>
     */
    private function install(): array
    {
        return (new ConfigWriteInstaller($this->application()))
            ->install(self::DATABASE, [], self::ADMINISTRATOR);
    }

    /**
     * The services the installation reaches for between the schema and the
     * configuration write. Only the write itself runs for real.
     */
    private function application(): Application
    {
        $app = new Application([
            'path' => $this->root,
            'path.packages' => $this->workspace.'/packages',
            'file' => new Filesystem(),
            'db' => new ConfigWriteDatabase(),
            'auth.password' => new ConfigWritePasswords(),
            'config' => new ConfigWriteOptions(),
            'version' => '1.0.0',
        ]);

        $app->set('module', new ConfigWriteModules());

        return $app;
    }

    /**
     * Lists what a directory holds, so a temp file left behind by the write shows
     * up as an unexpected entry.
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
 * Installs against a database that is already migrated and an application root
 * that is already laid out, which is the state the configuration is written in.
 */
final class ConfigWriteInstaller extends Installer
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public function check(array $config): array
    {
        return ['status' => 'no-tables', 'message' => ''];
    }

    protected function runMigrations(): void
    {
    }

    /**
     * @param array<string, mixed> $user
     */
    protected function runContentScript(string $file, array $user): void
    {
    }

    protected function linkStorage(): void
    {
    }
}

/**
 * Takes the administrator the installation inserts before it writes the file.
 */
final class ConfigWriteDatabase
{
    /**
     * @param array<string, mixed> $values
     */
    public function insert(string $table, array $values): void
    {
    }
}

final class ConfigWritePasswords
{
    public function hash(string $password): string
    {
        return 'hashed:'.$password;
    }
}

/**
 * Stands in for the database-backed options, which are stored before the file
 * configuration is written and are no part of it.
 */
final class ConfigWriteOptions
{
    public function __invoke(string $name): Config
    {
        return new Config();
    }

    public function set(string $name, Config $config): void
    {
    }
}

/**
 * The installation clears the cache once the configuration is in place.
 */
final class ConfigWriteModules
{
    public function get(string $name): object
    {
        return new class () {
            public function clearCache(): void
            {
            }
        };
    }
}
