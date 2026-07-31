<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Installer;

use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Installer\Installer;
use PHPUnit\Framework\TestCase;

/**
 * A fresh installation is filled with its initial content by a PHP file that is
 * included rather than called. Those files open by taking the application apart
 * into variables of their own, $db and $config among them, and $config is also
 * the name the installation holds the configuration under while it is still on
 * its way into config.php. Included where that variable lives, the script
 * overwrites it and the file left behind names neither the database nor the
 * locale - which is the end of a container, whose only record of its connection
 * is that file.
 *
 * What the script may read is therefore only what a scope of its own hands it:
 * the application, and the administrator the demo content signs a comment as.
 */
final class InstallerContentScriptTest extends TestCase
{
    /**
     * The account the installation has just created when it runs the script.
     */
    private const ADMINISTRATOR = [
        'username' => 'admin',
        'password' => 'not-a-real-password',
        'email' => 'admin@example.com',
    ];

    /**
     * What the installation is holding by the time it runs the content script.
     * Sharing a scope with it made every one of these the script's to overwrite,
     * $config - the configuration bound for config.php - first among them.
     */
    private const INSTALLATION_VARIABLES = [
        'config',
        'option',
        'status',
        'message',
        'demo_content',
        'scripts',
        'packageManager',
        'package',
        'name',
        'values',
    ];

    /**
     * A content script that hands the scope it ran in back to the test.
     */
    private const PROBE = <<<'PHP'
        $app->get('probe')->record(get_defined_vars());
        PHP;

    /**
     * A content script that inserts a single row, so a second run would show.
     */
    private const SINGLE_PAGE = <<<'PHP'
        $app->get('db')->insert('@system_page', ['title' => 'Home']);
        PHP;

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_installer_content_'.getmypid().'_'.uniqid();

        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workspace.'/*') ?: [] as $entry) {
            unlink($entry);
        }

        rmdir($this->workspace);
    }

    /**
     * The script shipped with the installation, run over nothing but the scope
     * it is given: everything it reaches for, it reaches for through $app.
     */
    public function testTheShippedContentScriptFillsTheInstallationFromTheScopeAlone(): void
    {
        $db = new ContentScriptDatabase();
        $config = new ContentScriptConfiguration();
        $installer = new ContentScriptInstaller($this->application(['db' => $db, 'config' => $config]));

        $installer->runContentScript(dirname(__DIR__, 3).'/app/installer/install.php', self::ADMINISTRATOR);

        self::assertContains('@system_node', $db->tables(), 'the site is given its pages');
        self::assertContains('@blog_post', $db->tables(), 'and the blog it found is given a post');

        $site = $config->written['system/site'] ?? null;

        self::assertInstanceOf(Config::class, $site);
        self::assertSame(1, $site->get('frontpage'));
    }

    public function testTheScriptIsHandedTheApplicationAndTheAdministrator(): void
    {
        $probe = new ContentScriptProbe();
        $app = $this->application(['probe' => $probe]);

        (new ContentScriptInstaller($app))->runContentScript($this->script(self::PROBE), self::ADMINISTRATOR);

        self::assertSame($app, $probe->scope['app'] ?? null);
        self::assertSame(self::ADMINISTRATOR, $probe->scope['user'] ?? null, 'the demo content signs a comment as the owner');
    }

    /**
     * The regression the scope exists for: the configuration on its way into
     * config.php, and everything else the installation is part way through,
     * has to be beyond the reach of a script that declares those names itself.
     */
    public function testNothingTheInstallationIsHoldingIsInTheScriptsReach(): void
    {
        $probe = new ContentScriptProbe();

        (new ContentScriptInstaller($this->application(['probe' => $probe])))
            ->runContentScript($this->script(self::PROBE), self::ADMINISTRATOR);

        foreach (self::INSTALLATION_VARIABLES as $variable) {
            self::assertArrayNotHasKey(
                $variable,
                $probe->scope,
                sprintf('$%s must not be the content script\'s to overwrite', $variable),
            );
        }
    }

    public function testTheContentIsNotInsertedASecondTime(): void
    {
        $db = new ContentScriptDatabase();
        $installer = new ContentScriptInstaller($this->application(['db' => $db]));
        $script = $this->script(self::SINGLE_PAGE);

        $installer->runContentScript($script, self::ADMINISTRATOR);
        $installer->runContentScript($script, self::ADMINISTRATOR);

        self::assertSame(['@system_page'], $db->tables());
    }

    /**
     * An installation the content was stripped out of still installs.
     */
    public function testAnInstallationWithoutAContentScriptIsNotAFailure(): void
    {
        $db = new ContentScriptDatabase();

        (new ContentScriptInstaller($this->application(['db' => $db])))
            ->runContentScript($this->workspace.'/no-such-content.php', self::ADMINISTRATOR);

        self::assertSame([], $db->tables());
    }

    /**
     * The content script runs long before anything is installed, so a bare
     * application carrying the services the script asks for is all it takes.
     *
     * @param array<string, mixed> $services
     */
    private function application(array $services = []): Application
    {
        return new Application(['path' => $this->workspace] + $services);
    }

    /**
     * A content script of the given body, under a name of its own - a script is
     * included once per process, so a shared name would silence a second test.
     */
    private function script(string $body): string
    {
        $file = $this->workspace.'/content_'.uniqid().'.php';

        file_put_contents($file, "<?php\n\n".$body."\n");

        return $file;
    }
}

/**
 * Opens the content script step so it can be run on its own.
 */
final class ContentScriptInstaller extends Installer
{
    /**
     * @param array<string, mixed> $user
     */
    public function runContentScript(string $file, array $user): void
    {
        parent::runContentScript($file, $user);
    }
}

/**
 * Records what a content script inserts, in place of the database that only a
 * complete installation has.
 */
final class ContentScriptDatabase
{
    /** @var list<array{table: string, values: array<string, mixed>}> */
    public array $inserted = [];

    /**
     * @param array<string, mixed> $values
     */
    public function insert(string $table, array $values): void
    {
        $this->inserted[] = ['table' => $table, 'values' => $values];
    }

    public function getUtility(): object
    {
        // The shipped scripts fill the blog only where the extension installed
        // one, which is the case for a default installation.
        return new class () {
            public function tableExists(string $table): bool
            {
                return $table === '@blog_post';
            }
        };
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_column($this->inserted, 'table');
    }
}

/**
 * Stands in for the configuration manager a content script writes the site's
 * own settings through.
 */
final class ContentScriptConfiguration
{
    /** @var array<string, Config> */
    public array $written = [];

    public function __invoke(string $name): Config
    {
        return $this->written[$name] ?? new Config();
    }

    public function set(string $name, Config $config): void
    {
        $this->written[$name] = $config;
    }
}

/**
 * Reports the scope a content script was run in back to the test.
 */
final class ContentScriptProbe
{
    /** @var array<string, mixed> */
    public array $scope = [];

    /**
     * @param array<string, mixed> $scope
     */
    public function record(array $scope): void
    {
        $this->scope = $scope;
    }
}
