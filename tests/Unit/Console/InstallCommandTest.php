<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Console;

use Monolog\Handler\NullHandler;
use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Console\Commands\InstallCommand;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Log\Logger;
use Pagekit\Package\InstallProbes;
use Pagekit\Package\PackageFactory;
use Pagekit\Tests\Unit\Package\PackageZip;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The install command installs one archive and reports a refusal without writing a package.
 */
final class InstallCommandTest extends TestCase
{
    private string $workspace = '';

    private string $packages = '';

    private Config $system;

    protected function setUp(): void
    {
        require_once dirname(__DIR__).'/Package/bootstrap.php';
        InstallProbes::reset();

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_install_cmd_'.bin2hex(random_bytes(4));
        $this->packages = $this->workspace.'/packages';
        mkdir($this->packages, 0777, true);

        $this->system = new Config();
        $this->system->set('extensions', []);
    }

    protected function tearDown(): void
    {
        InstallProbes::reset();

        if ($this->workspace !== '') {
            $this->removeTree($this->workspace);
        }
    }

    public function testAnArchiveIsInstalledAndThePackageIsNotEnabled(): void
    {
        $archive = $this->workspace.'/pagekit-demo.zip';
        PackageZip::write($archive, [
            'title' => 'Fancy Title',
            'version' => '1.2.3',
        ], [
            'fresh.txt' => 'new',
        ]);

        $tester = new CommandTester(new InstallCommand($this->container()));
        $tester->execute(['archive' => $archive]);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        self::assertSame("Installed pagekit/demo 1.2.3.\n", $tester->getDisplay(true));
        self::assertStringNotContainsString('Fancy Title', $tester->getDisplay(true));
        self::assertFileExists($archive);
        self::assertSame('new', file_get_contents($this->packages.'/pagekit/demo/fresh.txt'));
        self::assertSame('1.2.3', $this->system->get('packages.demo'));
        self::assertSame([], $this->system->get('extensions'));
        self::assertSame(['demo'], $this->entries($this->packages.'/pagekit'));
    }

    public function testARefusedArchiveFailsAndWritesNothing(): void
    {
        $archive = $this->workspace.'/notes.txt';
        file_put_contents($archive, 'hello');

        $tester = new CommandTester(new InstallCommand($this->container()));
        $tester->execute(['archive' => $archive]);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertSame("The file is not a readable ZIP archive.\n", $tester->getDisplay(true));
        self::assertSame('hello', file_get_contents($archive));
        self::assertSame([], $this->entries($this->packages));
        self::assertNull($this->system->get('packages.demo'));
    }

    public function testAPackageWhoseFolderIsALinkFailsAndTheLinkStays(): void
    {
        $archive = $this->workspace.'/pagekit-demo.zip';
        PackageZip::write($archive, [
            'title' => 'Fancy Title',
        ]);

        $elsewhere = $this->workspace.'/elsewhere';
        mkdir($elsewhere, 0777, true);
        mkdir($this->packages.'/pagekit', 0777, true);
        file_put_contents($elsewhere.'/marker.txt', 'old');
        self::assertTrue(symlink($elsewhere, $this->packages.'/pagekit/demo'));

        $tester = new CommandTester(new InstallCommand($this->container()));
        $tester->execute(['archive' => $archive]);
        $display = $tester->getDisplay(true);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('symbolic link', $display);
        self::assertStringContainsString('pagekit/demo', $display);
        self::assertStringNotContainsString('Fancy Title', $display);
        self::assertStringNotContainsString('Installed', $display);
        self::assertTrue(is_link($this->packages.'/pagekit/demo'));
        self::assertSame('old', file_get_contents($elsewhere.'/marker.txt'));
        self::assertFileDoesNotExist($elsewhere.'/fresh.txt');
        self::assertSame([], $this->system->get('extensions'));
    }

    public function testANonStringArchiveArgumentIsRefusedBeforeAnythingIsRead(): void
    {
        try {
            (new CommandTester(new InstallCommandWithANonStringArchive($this->container())))->execute([
                'archive' => 'unused.zip',
            ]);
            self::fail('A non-string archive argument must be refused.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('archive', $exception->getMessage());
        }

        self::assertSame([], $this->entries($this->packages));
        self::assertNull($this->system->get('packages.demo'));
    }

    private function container(): Application
    {
        $app = new Application();

        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($this->system);

        $factory = new PackageFactory();
        $factory->addPath($this->packages.'/*/*/composer.json');

        $app->set('config', $config);
        $app->set('package', $factory);
        $app->set('file', new Filesystem());
        $app->set('log', new Logger('test', [new NullHandler()]));
        $app->set('module', new class () {
            public function get(string $name): null
            {
                return null;
            }
        });
        $app->set('path.temp', $this->workspace.'/tmp/temp');
        $app->set('path.cache', $this->workspace.'/tmp/cache');
        $app->set('path.vendor', $this->workspace.'/app/vendor');
        $app->set('path.packages', $this->packages);
        $app->set('system.api', 'https://example.test');

        return $app;
    }

    /**
     * @return list<string>
     */
    private function entries(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

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

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path.'/'.$entry);
        }

        rmdir($path);
    }
}

/**
 * Forces the archive argument off the string the console would have passed.
 */
final class InstallCommandWithANonStringArchive extends InstallCommand
{
    public function argument(?string $key = null): string|array|null
    {
        return ['not-a-path'];
    }
}
