<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Installer;

use Pagekit\Application;
use Pagekit\Installer\Installer;
use PHPUnit\Framework\TestCase;

/**
 * A connection the installation cannot open is checked before anything is
 * installed, and the check is the only thing that ever sees the driver's reason
 * for it - the file SQLite could not open, the host that refused, the
 * credentials it refused them with. Answering with "No database connection."
 * alone leaves the person installing, or the log of a container that ended its
 * start on it, with a sentence and nothing to act on.
 */
final class InstallerConnectionDiagnosticsTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_installer_connection_'.getmypid().'_'.uniqid();

        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        rmdir($this->workspace);
    }

    public function testTheDriversReasonForARefusedConnectionReachesTheCaller(): void
    {
        $result = $this->installer('SQLSTATE[HY000] [14] unable to open database file')->install();

        self::assertSame('no-connection', $result['status']);
        self::assertStringContainsString('unable to open database file', $result['message']);
    }

    /**
     * An exception without a message of its own is still a failed installation,
     * and still says so.
     */
    public function testACheckWithNothingToSayStillNamesTheFailure(): void
    {
        $result = $this->installer('')->install();

        self::assertSame('no-connection', $result['status']);
        self::assertSame('No database connection.', $result['message']);
    }

    /**
     * An installation over an existing set of tables keeps answering with the
     * prefix advice the check writes for it, rather than the connection message.
     */
    public function testAnExistingInstallationIsReportedAsItsOwnCase(): void
    {
        $installer = new StubbedCheckInstaller(
            new Application(['path' => $this->workspace]),
            ['status' => 'tables-exist', 'message' => 'Existing Pagekit installation detected.'],
        );

        $result = $installer->install();

        self::assertSame('tables-exist', $result['status']);
        self::assertSame('Existing Pagekit installation detected.', $result['message']);
    }

    private function installer(string $cause): StubbedCheckInstaller
    {
        return new StubbedCheckInstaller(
            new Application(['path' => $this->workspace]),
            ['status' => 'no-connection', 'message' => $cause],
        );
    }
}

/**
 * Installs against a check that has already made up its mind, which is where an
 * unusable connection is decided.
 */
final class StubbedCheckInstaller extends Installer
{
    /**
     * @param array<string, string> $result
     */
    public function __construct(Application $app, private readonly array $result)
    {
        parent::__construct($app);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public function check(array $config): array
    {
        return $this->result;
    }
}
