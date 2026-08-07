<?php

declare(strict_types=1);

namespace Pagekit\Tests;

use Pagekit\Module\Loader\ConfigLoader;
use Pagekit\Module\Loader\EnvConfigLoader;
use Pagekit\Module\Loader\LoaderInterface;
use Pagekit\Module\Module;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Configuration an installation is handed through the process environment.
 *
 * This is what makes an immutable image configurable without a writable
 * config.php: the loader runs last in the chain, so a variable that is set
 * outranks the module defaults and config.php alike. An installation that sets
 * nothing has to be indistinguishable from one running without the loader at
 * all, which is the case for every classic, non-container deployment.
 *
 * The result is read back through Module::config(), the way the modules read
 * their own configuration, so what is asserted is the key each consumer really
 * looks up rather than the intermediate array.
 */
final class EnvConfigLoaderTest extends TestCase
{
    /**
     * Every variable the loader knows. Cleared before each test so an inherited
     * environment cannot decide the outcome, and put back afterwards.
     */
    private const VARIABLES = [
        'PAGEKIT_DEBUG',
        'PAGEKIT_SECRET',
        'PAGEKIT_DB_DRIVER',
        'PAGEKIT_DB_HOST',
        'PAGEKIT_DB_PORT',
        'PAGEKIT_DB_NAME',
        'PAGEKIT_DB_USER',
        'PAGEKIT_DB_PASSWORD',
        'PAGEKIT_DB_PREFIX',
        'PAGEKIT_DB_PATH',
    ];

    /** @var array<string, string> */
    private array $inherited = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $value = getenv($name);

            if ($value !== false) {
                $this->inherited[$name] = $value;
            }

            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
        }

        foreach ($this->inherited as $name => $value) {
            putenv($name.'='.$value);
        }

        $this->inherited = [];
    }

    // -----------------------------------------------------------------------
    // An empty environment changes nothing.
    // -----------------------------------------------------------------------

    public function testAnInstallationThatSetsNoVariablesIsHandedOnUntouched(): void
    {
        $loader = new EnvConfigLoader();

        foreach (['application', 'system', 'database'] as $name) {
            $definition = ['name' => $name, 'config' => ['keep' => 'this']];

            self::assertSame(
                $definition,
                $loader->load($definition),
                sprintf('"%s" must survive an empty environment unchanged', $name),
            );
        }
    }

    /**
     * The loader speaks for a fixed set of modules. Everything else passes
     * through it, however much of the environment is set.
     */
    public function testAModuleTheEnvironmentSaysNothingAboutIsHandedOnUntouched(): void
    {
        putenv('PAGEKIT_SECRET=the-secret-from-the-environment');
        putenv('PAGEKIT_DB_HOST=db.internal');

        $definition = ['name' => 'system/cache', 'config' => ['caches' => ['cache' => ['storage' => 'auto']]]];

        self::assertSame($definition, (new EnvConfigLoader())->load($definition));
    }

    // -----------------------------------------------------------------------
    // application: debug.
    // -----------------------------------------------------------------------

    public function testDebugStaysOffUntilTheEnvironmentAsksForIt(): void
    {
        self::assertFalse($this->configure('application', self::applicationDefaults())->config('debug'));

        putenv('PAGEKIT_DEBUG=1');

        self::assertTrue($this->configure('application', self::applicationDefaults())->config('debug'));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function debugSpellings(): array
    {
        return [
            'one' => ['1', true],
            'true' => ['true', true],
            'on' => ['on', true],
            'yes' => ['yes', true],
            'capitalised' => ['True', true],
            'zero' => ['0', false],
            'false' => ['false', false],
            'off' => ['off', false],
            'no' => ['no', false],
            'blank' => ['', false],
            'gibberish' => ['perhaps', false],
        ];
    }

    /**
     * The environment only ever holds strings, and a plain cast would read the
     * string "false" as switched on. Debug has to arrive as the boolean the
     * spelling means, because it decides whether visitors are shown internals.
     */
    #[DataProvider('debugSpellings')]
    public function testDebugArrivesAsTheBooleanTheSpellingMeans(string $value, bool $expected): void
    {
        putenv('PAGEKIT_DEBUG='.$value);

        // Seeded with the opposite, so every spelling has to prove it landed.
        $application = $this->configure('application', ['debug' => !$expected]);

        self::assertSame($expected, $application->config('debug'));
    }

    // -----------------------------------------------------------------------
    // system: secret.
    // -----------------------------------------------------------------------

    public function testTheSigningSecretCanBePinnedFromOutsideTheInstallation(): void
    {
        putenv('PAGEKIT_SECRET=the-secret-from-the-environment');

        $system = $this->configure('system', ['secret' => 'the-secret-from-config-php']);

        self::assertSame('the-secret-from-the-environment', $system->config('secret'));
    }

    /**
     * A variable that is present but empty is a decision, not an omission: it
     * blanks the value instead of falling back to config.php. Reading it as
     * unset would make "no value" impossible to express.
     */
    public function testAVariableSetToNothingOverridesWithNothing(): void
    {
        putenv('PAGEKIT_SECRET=');

        $system = $this->configure('system', ['secret' => 'the-secret-from-config-php']);

        self::assertSame('', $system->config('secret'));
    }

    // -----------------------------------------------------------------------
    // database: connections.
    // -----------------------------------------------------------------------

    public function testTheMysqlConnectionIsTakenFromTheEnvironment(): void
    {
        putenv('PAGEKIT_DB_DRIVER=mysql');
        putenv('PAGEKIT_DB_HOST=db.internal');
        putenv('PAGEKIT_DB_PORT=3307');
        putenv('PAGEKIT_DB_NAME=pagekit_live');
        putenv('PAGEKIT_DB_USER=pagekit');
        putenv('PAGEKIT_DB_PASSWORD=not-a-real-password');
        putenv('PAGEKIT_DB_PREFIX=pk_');

        $database = $this->configure('database', self::databaseDefaults());

        self::assertSame('mysql', $database->config('default'));
        self::assertSame('db.internal', $database->config('connections.mysql.host'));
        self::assertSame('pagekit_live', $database->config('connections.mysql.dbname'));
        self::assertSame('pagekit', $database->config('connections.mysql.user'));
        self::assertSame('not-a-real-password', $database->config('connections.mysql.password'));
        self::assertSame('pk_', $database->config('connections.mysql.prefix'));

        // The one parameter that is not handed on as the string it arrived as.
        self::assertSame(3307, $database->config('connections.mysql.port'));

        // The environment names parameters, it does not replace the connection:
        // what only the module knows has to survive the merge, or Doctrine is
        // handed a connection without a driver.
        self::assertSame('pdo_mysql', $database->config('connections.mysql.driver'));
        self::assertSame('utf8', $database->config('connections.mysql.charset'));
        self::assertSame('utf8_unicode_ci', $database->config('connections.mysql.collate'));
        self::assertSame('InnoDB', $database->config('connections.mysql.engine'));
    }

    /**
     * An image is rebuilt, the database is not, so the SQLite file has to be
     * placed outside the image without losing the driver options the module
     * registers with it.
     */
    public function testTheSqliteFileCanBeMovedOntoAVolume(): void
    {
        putenv('PAGEKIT_DB_DRIVER=sqlite');
        putenv('PAGEKIT_DB_PATH=/var/www/data/pagekit.db');

        $database = $this->configure('database', self::databaseDefaults());

        self::assertSame('sqlite', $database->config('default'));
        self::assertSame('/var/www/data/pagekit.db', $database->config('connections.sqlite.path'));
        self::assertSame('pdo_sqlite', $database->config('connections.sqlite.driver'));
        self::assertSame('pk_', $database->config('connections.sqlite.prefix'));
    }

    /**
     * A partly configured environment must not invent the rest: only what is
     * named changes, so adding a single variable to a config.php installation
     * stays predictable.
     */
    public function testASingleVariableLeavesTheRestOfTheDatabaseAlone(): void
    {
        putenv('PAGEKIT_DB_PORT=3307');

        $database = $this->configure('database', self::databaseDefaults());

        self::assertSame(3307, $database->config('connections.mysql.port'));
        self::assertSame('sqlite', $database->config('default'));
        self::assertSame('localhost', $database->config('connections.mysql.host'));
        self::assertSame('pagekit.db', $database->config('connections.sqlite.path'));
    }

    /**
     * There is no port 0 to connect to, so a variable that arrives blank - an
     * env file carrying the line without a value, a compose file passing an
     * unset one through - has to read as unset here. Cast over the connection
     * it would point every such container at a port nothing listens on.
     */
    public function testABlankPortIsNoPortAtAll(): void
    {
        putenv('PAGEKIT_DB_PORT=');
        putenv('PAGEKIT_DB_HOST=db.internal');

        $database = $this->configure('database', self::databaseDefaults());
        $mysql = $database->config('connections.mysql');

        self::assertIsArray($mysql);
        self::assertArrayNotHasKey('port', $mysql, 'the connection keeps the port the environment did not name');

        self::assertSame(
            'db.internal',
            $database->config('connections.mysql.host'),
            'the rest of the environment still arrives',
        );
    }

    /**
     * A driver the module has no connection for would otherwise surface much
     * later, as a missing array key while the connection is being built.
     */
    public function testADriverWithoutAConnectionIsRefusedWhileTheLoaderIsBuilt(): void
    {
        putenv('PAGEKIT_DB_DRIVER=postgres');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PAGEKIT_DB_DRIVER must be one of "mysql", "sqlite", got "postgres".');

        new EnvConfigLoader();
    }

    // -----------------------------------------------------------------------
    // Precedence in the chain the boot files build.
    // -----------------------------------------------------------------------

    /**
     * Module defaults, then config.php, then the environment. Later loaders
     * win, and each of them speaks only for the keys it names.
     */
    public function testTheEnvironmentOutranksConfigPhp(): void
    {
        putenv('PAGEKIT_SECRET=the-secret-from-the-environment');

        $system = $this->through(
            self::definition('system', ['secret' => 'the-module-default', 'locale' => 'en_US']),
            new ConfigLoader(['system' => ['secret' => 'the-secret-from-config-php', 'locale' => 'de_DE']]),
            new EnvConfigLoader(),
        );

        self::assertSame('the-secret-from-the-environment', $system->config('secret'));
        self::assertSame('de_DE', $system->config('locale'));
    }

    /**
     * The mirror image, and the case every classic installation is in: with the
     * variable unset, config.php still decides. A loader sitting in the chain
     * has to be invisible.
     */
    public function testConfigPhpStillDecidesWhatTheEnvironmentDoesNotName(): void
    {
        $system = $this->through(
            self::definition('system', ['secret' => 'the-module-default', 'locale' => 'en_US']),
            new ConfigLoader(['system' => ['secret' => 'the-secret-from-config-php', 'locale' => 'de_DE']]),
            new EnvConfigLoader(),
        );

        self::assertSame('the-secret-from-config-php', $system->config('secret'));
        self::assertSame('de_DE', $system->config('locale'));
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     */
    private function configure(string $name, array $config): Module
    {
        return $this->through(self::definition($name, $config), new EnvConfigLoader());
    }

    /**
     * The module a chain of loaders leaves behind.
     *
     * @param array<string, mixed> $definition
     */
    private function through(array $definition, LoaderInterface ...$loaders): Module
    {
        foreach ($loaders as $loader) {
            $loaded = $loader->load($definition);

            self::assertIsArray($loaded, 'a loader hands the module definition on');

            /** @var array<string, mixed> $definition */
            $definition = $loaded;
        }

        return new Module($definition);
    }

    /**
     * @param  array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function definition(string $name, array $config): array
    {
        return ['name' => $name, 'path' => '/pagekit/'.$name, 'config' => $config];
    }

    /**
     * @return array<string, mixed>
     */
    private static function applicationDefaults(): array
    {
        return self::defaultsOf(dirname(__DIR__, 2).'/index.php');
    }

    /**
     * @return array<string, mixed>
     */
    private static function databaseDefaults(): array
    {
        return self::defaultsOf(dirname(__DIR__, 3).'/database/index.php');
    }

    /**
     * The configuration a module ships, read from the module itself so the
     * merge is asserted against what an installation really starts from.
     *
     * @return array<string, mixed>
     */
    private static function defaultsOf(string $file): array
    {
        /** @var array{config: array<string, mixed>} $module */
        $module = require $file;

        return $module['config'];
    }
}
