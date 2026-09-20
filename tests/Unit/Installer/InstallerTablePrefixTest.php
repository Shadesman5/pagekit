<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Installer;

use Pagekit\Application;
use Pagekit\Installer\Installer;
use Pagekit\Installer\TablePrefix;
use PHPUnit\Framework\TestCase;

/**
 * Which prefix an installation may be created with is settled on the server,
 * not by the form that submitted it.
 *
 * Both ways in come through the same check - the wizard posts to it, the setup
 * command reaches it through install() - so a prefix refused one way cannot be
 * installed the other. The refusal lands before a connection is opened: nothing
 * was created, nothing was written, and the field can simply be retyped.
 *
 * An installation that already exists is never measured against the shape.
 * Whatever it was created with is what its tables are called, an empty prefix
 * included, and measuring it now could only refuse it the one prefix that still
 * reaches them.
 */
final class InstallerTablePrefixTest extends TestCase
{
    private const ADMINISTRATOR = [
        'username' => 'admin',
        'password' => 'not-a-real-password',
        'email' => 'admin@example.com',
    ];

    /**
     * The application root, which is where config.php would be written and
     * therefore what decides whether an installation already exists.
     */
    private string $root;

    protected function setUp(): void
    {
        $this->root = strtr(sys_get_temp_dir(), '\\', '/').'/pk_installer_prefix_'.getmypid().'_'.uniqid();

        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (array_diff((array) scandir($this->root), ['.', '..']) as $entry) {
            unlink($this->root.'/'.(string) $entry);
        }

        rmdir($this->root);
    }

    public function testAPrefixThatCannotBeInstalledIntoIsRefusedBeforeAConnectionIsOpened(): void
    {
        $db = new PrefixCheckDatabase();

        $result = $this->installer($db)->check(self::submitted(['mysql' => ['prefix' => '']]));

        self::assertSame('invalid-prefix', $result['status']);
        self::assertSame(
            TablePrefix::refusal(''),
            $result['message'],
            'The check answers with the shape rule itself, so browser and command line say the same thing',
        );
        self::assertSame(
            0,
            $db->connects,
            'A prefix is refused while the database is still untouched - nothing has to be undone',
        );
    }

    /**
     * config.php keeps every connection it was handed, not only the one the
     * installation starts on, so a prefix that was never measured is the one an
     * installation ends up switching to.
     */
    public function testEveryConnectionTheSubmissionNamesIsMeasuredNotOnlyTheDefault(): void
    {
        $db = new PrefixCheckDatabase();

        $result = $this->installer($db)->check(self::submitted([
            'sqlite' => ['prefix' => 'pk_'],
            'mysql' => ['prefix' => 'pagekit'],
        ]));

        self::assertSame('invalid-prefix', $result['status']);
        self::assertStringContainsString('"pagekit"', $result['message']);
        self::assertSame(0, $db->connects);
    }

    /**
     * A submission that names no prefix at all is not a refusal: the module
     * default applies to that connection, and what a connection ships is
     * installable by definition. Refusing it would make the wizard's own
     * optional field impossible to leave alone.
     */
    public function testAConnectionThatNamesNoPrefixIsNotRefused(): void
    {
        $db = new PrefixCheckDatabase();

        $result = $this->installer($db)->check(self::submitted(['sqlite' => ['path' => 'pagekit.db']]));

        self::assertSame('no-tables', $result['status']);
        self::assertSame(1, $db->connects, 'The check has to get as far as the connection it was asked about');
    }

    public function testAPrefixOfTheRightShapeIsInstalledInto(): void
    {
        $db = new PrefixCheckDatabase();

        $result = $this->installer($db)->check(self::submitted(['sqlite' => ['prefix' => 'site_']]));

        self::assertSame('no-tables', $result['status']);
        self::assertSame(1, $db->connects);
    }

    /**
     * The shape is a rule about choosing a prefix, and an installation that
     * already exists has nothing left to choose: its tables carry the name they
     * were created with. A site set up before the rule existed would otherwise
     * be refused the only prefix that still reaches its own tables.
     */
    public function testAnInstallationThatAlreadyExistsIsNeverMeasuredAgainstTheShape(): void
    {
        file_put_contents($this->root.'/config.php', '<?php return [];');

        $db = new PrefixCheckDatabase();

        $result = $this->installer($db)->check(self::submitted(['mysql' => ['prefix' => '']]));

        self::assertSame('no-tables', $result['status']);
        self::assertSame(1, $db->connects);
    }

    /**
     * The refusal travels the route every other failed installation takes, so
     * the wizard shows it in the panel and the setup command prints it - rather
     * than an installation that reports no reason and leaves the prefix field
     * looking accepted.
     */
    public function testTheRefusalReachesWhoeverAskedForTheInstallation(): void
    {
        $db = new PrefixCheckDatabase();

        $result = $this->installer($db)->install(
            self::submitted(['sqlite' => ['prefix' => '_r_']]),
            [],
            self::ADMINISTRATOR,
        );

        self::assertSame('invalid-prefix', $result['status']);
        self::assertSame(TablePrefix::refusal('_r_'), $result['message']);
        self::assertSame(0, $db->connects);
        self::assertFileDoesNotExist(
            $this->root.'/config.php',
            'A refused installation leaves no configuration behind to boot from',
        );
    }

    /**
     * What the wizard posts and what the setup command builds: the database
     * module's configuration, with a prefix per connection.
     *
     * @param  array<string, array<string, mixed>> $connections
     * @return array<string, mixed>
     */
    private static function submitted(array $connections): array
    {
        return [
            'locale' => 'en_GB',
            'database' => [
                'default' => array_key_first($connections),
                'connections' => $connections,
            ],
        ];
    }

    private function installer(PrefixCheckDatabase $db): Installer
    {
        $app = new Application(['path' => $this->root, 'db' => $db]);

        $app->set('module', new PrefixCheckModules());

        return new Installer($app);
    }
}

/**
 * Stands in for the connection the check opens, counting how often it was asked
 * for one: a prefix refused after the database has been reached is a prefix
 * refused too late to be free.
 */
final class PrefixCheckDatabase
{
    public int $connects = 0;

    public function connect(): bool
    {
        $this->connects++;

        return true;
    }

    public function getUtility(): object
    {
        return new class () {
            /**
             * No installation stands behind this connection, which is the state
             * a fresh one is checked in.
             */
            public function tableExists(string $table): bool
            {
                return false;
            }
        };
    }
}

/**
 * A registry holding no module, which is what the check makes of a submitted
 * section it finds no module for: it leaves the configuration where it is.
 */
final class PrefixCheckModules
{
    public function get(string $name): mixed
    {
        return null;
    }
}
