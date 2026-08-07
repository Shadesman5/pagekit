<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Console;

use Pagekit\Application;
use Pagekit\Console\Commands\SelfupdateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `pagekit self-update` replaced the core with an archive it downloaded from the
 * pagekit.com update endpoint, and that endpoint is gone: there is no release to
 * ask for and no checksum to verify one against. Running the command anyway would
 * either overwrite an installation with whatever some URL happens to serve, or
 * leave an operator believing the core was updated when nothing happened.
 *
 * So the command refuses, says why, and exits non-zero - the exit code is what a
 * cron job or a deployment script reads. It reaches nothing on its way there:
 * every command is built on the application container, and this one asks it for no
 * service at all, least of all the discontinued endpoint or a temp directory to
 * stage a download in.
 */
final class SelfupdateCommandTest extends TestCase
{
    private const REFUSAL = "The 'self-update' command is disabled: the pagekit.com update endpoint was discontinued.";

    /**
     * Where a download would put the archive it fetched.
     */
    private string $archive;

    protected function setUp(): void
    {
        $this->archive = strtr(sys_get_temp_dir(), '\\', '/').'/pk_selfupdate_'.getmypid().'_'.uniqid().'.zip';
    }

    protected function tearDown(): void
    {
        if (is_file($this->archive)) {
            unlink($this->archive);
        }
    }

    public function testTheCommandRefusesWithAReasonAndANonZeroExitCode(): void
    {
        $app = new RecordingApplication();

        $tester = new CommandTester(new SelfupdateCommand($app));
        $tester->execute([]);

        self::assertSame(
            SymfonyCommand::FAILURE,
            $tester->getStatusCode(),
            'A command that updated nothing must not report success to whatever called it',
        );
        self::assertStringContainsString(
            self::REFUSAL,
            $tester->getDisplay(),
            'The refusal has to name its reason, or the only way to learn it is to read the source',
        );
        self::assertSame(
            [],
            $app->resolved,
            'A disabled command has no business resolving services, the update endpoint above all',
        );
    }

    /**
     * The URL option is what the command took to fetch a release from somewhere
     * other than pagekit.com. It is still declared, and it must not be a way past
     * the refusal.
     */
    public function testAnExplicitUrlDoesNotGetPastTheRefusal(): void
    {
        $app = new RecordingApplication();

        $tester = new CommandTester(new SelfupdateCommand($app));
        $tester->execute(['--url' => 'https://example.test/pagekit-latest.zip']);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(self::REFUSAL, $tester->getDisplay());
        self::assertSame([], $app->resolved);
    }

    /**
     * The download is kept for the day the endpoint has a successor. Handed no URL
     * it has to fail loudly: writing whatever an empty URL opens into the archive
     * path would hand the updater a file to unpack.
     */
    public function testTheDownloadRefusesAMissingUrl(): void
    {
        $command = new SelfupdateCommand(new RecordingApplication());
        $failed = null;

        try {
            $command->download('', $this->archive);
        } catch (\RuntimeException $error) {
            $failed = $error;
        }

        self::assertInstanceOf(\RuntimeException::class, $failed, 'A download without a URL must be refused, not attempted');
        self::assertStringContainsString('url is missing', $failed->getMessage());
        self::assertFileDoesNotExist($this->archive, 'A refused download must leave no file behind for the updater to find');
    }
}

/**
 * Notes down which services are asked of it.
 */
final class RecordingApplication extends Application
{
    /** @var array<int, string> */
    public array $resolved = [];

    public function get(string $id): mixed
    {
        $this->resolved[] = $id;

        return parent::get($id);
    }
}
