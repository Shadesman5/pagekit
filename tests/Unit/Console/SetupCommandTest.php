<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Console;

use Pagekit\Application;
use Pagekit\Console\Commands\SetupCommand;
use Pagekit\Module\Module;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Installing from the command line, which is how a container and every scripted
 * deployment does it - nobody reads a form there, and whatever the flags say is
 * what the site is created with.
 *
 * The prefix is the flag that has to survive that unattended: it is what tells
 * this installation's tables from everything else in the database, and the
 * command neither invents one where the line asks for none nor quietly corrects
 * one it cannot install into. Both decisions are the installer's, reached
 * through the same check the wizard posts to, and what the command adds is
 * carrying the answer to the exit code and the terminal.
 */
final class SetupCommandTest extends TestCase
{
    /**
     * The application root. It holds no config.php, which is what makes every
     * run here a fresh installation.
     */
    private string $root;

    protected function setUp(): void
    {
        $this->root = strtr(sys_get_temp_dir(), '\\', '/').'/pk_setup_cmd_'.getmypid().'_'.uniqid();

        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (array_diff((array) scandir($this->root), ['.', '..']) as $entry) {
            unlink($this->root.'/'.(string) $entry);
        }

        rmdir($this->root);
    }

    /**
     * "--db-prefix=" names the empty prefix, and an installation created with it
     * has tables a restore cannot tell from the rest of the database. The flag
     * is handed on rather than corrected, so what comes back is the shape a
     * prefix has to have - and a non-zero exit code, which is the only part a
     * deployment script reads.
     */
    public function testAPrefixWrittenAsNothingIsRefusedWithTheShapeAPrefixHasToHave(): void
    {
        $database = self::databaseModule();
        $db = new SetupDatabase();

        $tester = $this->install(['--db-prefix' => ''], $database, $db);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A table prefix is required', $tester->getDisplay());
        self::assertStringContainsString('"pk_"', $tester->getDisplay());
        self::assertSame(
            0,
            $db->connects,
            'The refusal lands before the database is opened, so there is nothing to clean up',
        );
    }

    /**
     * A prefix the command cannot install into is answered with the correction
     * rather than replaced by the default: an operator who typed "pagekit" gets
     * a site whose tables are called what they asked for, or none at all.
     */
    public function testAnIllShapedPrefixIsAnsweredWithTheCorrectionRatherThanTheDefault(): void
    {
        $db = new SetupDatabase();

        $tester = $this->install(['--db-prefix' => 'pagekit'], self::databaseModule(), $db);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('"pagekit_"', $tester->getDisplay());
        self::assertSame(
            0,
            $db->connects,
            'The command surfaces the installer\'s own answer, and reaches nothing on the way to it',
        );
    }

    /**
     * The flag written with nothing behind it names no prefix at all, which is
     * a different thing from naming the empty one. The connection then carries
     * no prefix key and the database module's own applies; reading the missing
     * value as the empty string would turn a flag someone left unfinished into
     * the one prefix an installation cannot be created with.
     */
    public function testTheFlagWrittenWithoutAValueLeavesTheModuleDefaultInPlace(): void
    {
        $database = self::databaseModule();
        $db = new SetupDatabase();

        $this->install(['--db-prefix' => null], $database, $db);

        self::assertSame('pk_', $database->config('connections.sqlite.prefix'));
        self::assertSame(
            1,
            $db->connects,
            'Nothing refused the run, so it got as far as opening the connection it was given',
        );
    }

    public function testThePrefixNamedOnTheCommandLineIsTheOneInstalledWith(): void
    {
        $database = self::databaseModule();

        $this->install(['--db-prefix' => 'site_'], $database, new SetupDatabase());

        self::assertSame('site_', $database->config('connections.sqlite.prefix'));
    }

    /**
     * Runs the command the way the console runs it, against a container holding
     * what the setup reaches for.
     *
     * @param array<string, string|null> $input as the flags arrive from the command line
     */
    private function install(array $input, Module $database, SetupDatabase $db): CommandTester
    {
        $app = new Application(['path' => $this->root, 'db' => $db]);

        $app->set('module', new SetupModules($database));

        $tester = new CommandTester(new SetupCommand($app));
        $tester->execute($input + ['--password' => 'not-a-real-password']);

        return $tester;
    }

    /**
     * The database module as an installation really starts from it, read from
     * the module itself: what the command submits is merged into this, so the
     * prefix asserted afterwards is the one a connection would be opened with.
     */
    private static function databaseModule(): Module
    {
        $path = dirname(__DIR__, 3).'/app/modules/database';

        /** @var array{config: array<string, mixed>} $definition */
        $definition = require $path.'/index.php';

        return new Module(['name' => 'database', 'path' => $path, 'config' => $definition['config']]);
    }
}

/**
 * The modules the setup asks the container for: the session, which it switches
 * to array storage so a console run leaves none behind, and the database module
 * whose configuration the submitted connection is merged into.
 */
final class SetupModules
{
    /** @var array<string, object> */
    private array $modules;

    public function __construct(Module $database)
    {
        $this->modules = [
            'session' => new Module(['name' => 'session', 'path' => '/pagekit/session', 'config' => ['storage' => 'file']]),
            'database' => $database,
        ];
    }

    public function get(string $name): mixed
    {
        return $this->modules[$name] ?? null;
    }

    /**
     * @param string|array<int, string> $modules
     */
    public function load(string|array $modules): self
    {
        return $this;
    }
}

/**
 * Stands in for the database the setup would install into, and stops the run
 * where the connection would be opened: what a prefix decides is settled by
 * then, and a schema created here would prove nothing about it.
 */
final class SetupDatabase
{
    public int $connects = 0;

    public function connect(): bool
    {
        $this->connects++;

        throw new \RuntimeException('no database stands behind this connection');
    }
}
