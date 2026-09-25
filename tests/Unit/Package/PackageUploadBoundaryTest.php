<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Monolog\Handler\TestHandler;
use Pagekit\Application;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Log\Logger;
use Pagekit\Module\ModuleManager;
use Pagekit\Package\Archive\ArchiveRefusedException;
use Pagekit\Package\Archive\PackageArchive;
use Pagekit\Package\Controller\PackageController;
use Pagekit\Package\InstallProbes;
use Pagekit\Package\PackageFactory;
use Pagekit\Package\PackageManager;
use Pagekit\Routing\Attribute\Access;
use Pagekit\Routing\Attribute\Request as RequestAttribute;
use Pagekit\Routing\Response as PagekitResponse;
use Pagekit\Routing\UrlProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Upload and install accept only a checked archive, and a refusal leaves nothing new on disk.
 */
final class PackageUploadBoundaryTest extends TestCase
{
    private string $workspace = '';

    private string $packages = '';

    private string $staging = '';

    private TestHandler $records;

    private Logger $log;

    private CacheDouble $cache;

    private PackageManagerUnderTest $manager;

    protected function setUp(): void
    {
        require_once __DIR__.'/bootstrap.php';
        InstallProbes::reset();

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_upload_'.bin2hex(random_bytes(4));
        $this->packages = $this->workspace.'/packages';
        $this->staging = $this->workspace.'/staging';
        mkdir($this->packages, 0777, true);
        mkdir($this->staging, 0777, true);

        $this->records = new TestHandler();
        $this->log = new Logger('test', [$this->records]);
        $this->cache = new CacheDouble();
        $this->manager = $this->newManager();
    }

    protected function tearDown(): void
    {
        InstallProbes::reset();

        if ($this->workspace !== '') {
            $this->removeTree($this->workspace);
        }
    }

    public function testUploadAndInstallKeepThePackagePermissionAndCsrf(): void
    {
        $access = (new \ReflectionClass(PackageController::class))->getAttributes(Access::class);
        self::assertCount(1, $access);
        $permission = $access[0]->newInstance();
        self::assertSame('system: manage packages', $permission->getExpression());
        self::assertTrue($permission->getAdmin());

        foreach (['uploadAction', 'installAction'] as $method) {
            $requests = (new \ReflectionMethod(PackageController::class, $method))->getAttributes(RequestAttribute::class);
            self::assertCount(1, $requests);
            self::assertTrue($requests[0]->newInstance()->getCsrf());
        }

        $upload = (new \ReflectionMethod(PackageController::class, 'uploadAction'))->getAttributes(RequestAttribute::class)[0]->newInstance();
        self::assertSame(['type' => 'string'], $upload->getData());

        $install = (new \ReflectionMethod(PackageController::class, 'installAction'))->getAttributes(RequestAttribute::class)[0]->newInstance();
        self::assertSame(['package' => 'array'], $install->getData());
    }

    public function testUploadWithoutAFileIsRefusedAndStagesNothing(): void
    {
        $exception = $this->refusedUpload(null);

        self::assertSame(400, $exception->getStatusCode());
        self::assertSame('No file uploaded.', $exception->getMessage());
        self::assertSame([], $this->entries($this->staging));
        self::assertSame([], $this->entries($this->packages));
    }

    public function testAnInvalidUploadIsRefusedAndStagesNothing(): void
    {
        $zip = $this->extensionZip();
        $incoming = $this->uploaded($zip, \UPLOAD_ERR_INI_SIZE);

        $exception = $this->refusedUpload($incoming);

        self::assertSame(400, $exception->getStatusCode());
        self::assertSame('No file uploaded.', $exception->getMessage());
        self::assertFileExists($incoming->getPathname());
        self::assertSame([], $this->entries($this->staging));
        self::assertSame([], $this->entries($this->packages));
    }

    public function testAThemeUploadedAsAnExtensionIsRefusedAndStagesNothing(): void
    {
        $zip = $this->workspace.'/theme.zip';
        PackageZip::write($zip, [
            'name' => 'pagekit/demo',
            'type' => 'pagekit-theme',
            'version' => '1.0.1',
            'title' => 'Fancy Title',
        ]);
        $incoming = $this->uploaded($zip);

        $exception = $this->refusedUpload($incoming);

        self::assertSame(400, $exception->getStatusCode());
        self::assertSame('No Pagekit extension', $exception->getMessage());
        self::assertNull($exception->getPrevious());
        self::assertFileExists($incoming->getPathname());
        self::assertSame([], $this->entries($this->staging));
    }

    public function testAnArchiveTheCheckRefusesIsABadRequestAndStagesNothing(): void
    {
        $zip = $this->workspace.'/no-autoload.zip';
        PackageZip::write($zip, [
            'title' => 'Fancy Title',
        ], [], "<?php\n\nreturn [\n    'name' => 'demo',\n];\n");
        $incoming = $this->uploaded($zip);

        $exception = $this->refusedUpload($incoming);

        self::assertSame(400, $exception->getStatusCode());
        self::assertStringContainsString("'autoload'", $exception->getMessage());
        self::assertInstanceOf(ArchiveRefusedException::class, $exception->getPrevious());
        self::assertFileExists($incoming->getPathname());
        self::assertSame([], $this->entries($this->staging));
        self::assertSame([], $this->entries($this->packages));
    }

    public function testAManifestTheFactoryCannotLoadIsRefusedBeforeTheFileIsStaged(): void
    {
        $incoming = $this->uploaded($this->extensionZip([
            'extra' => [
                'icon' => '../secret.svg',
                'image' => 'image.png',
            ],
        ]));

        $exception = $this->refusedUpload($incoming, 'extension', new FactoryThatCannotLoad());

        self::assertSame(400, $exception->getStatusCode());
        self::assertSame('"composer.json" file not valid.', $exception->getMessage());
        self::assertFileExists($incoming->getPathname());
        self::assertSame([], $this->entries($this->staging));
    }

    public function testAValidExtensionIsStagedOnceWithoutAChecksumOrItsImages(): void
    {
        $incoming = $this->uploaded($this->extensionZip([
            'title' => 'Fancy Title',
            'extra' => [
                'icon' => '../secret.svg',
                'image' => 'image.png',
                'homepage' => 'https://example.test/demo',
            ],
        ]));
        $controller = $this->controller($this->requestWith($incoming));

        $result = $controller->uploadAction('extension');

        self::assertArrayHasKey('package', $result);
        $package = $result['package'];
        self::assertSame('pagekit/demo', $package->get('name'));
        self::assertSame('Fancy Title', $package->get('title'));
        self::assertSame('1.2.3', $package->get('version'));
        self::assertSame('pagekit-extension', $package->get('type'));
        self::assertSame('https://example.test/demo', $package->get('extra.homepage'));
        self::assertNull($package->get('extra.icon'));
        self::assertNull($package->get('extra.image'));
        self::assertArrayNotHasKey('shasum', $package->jsonSerialize());
        self::assertStringNotContainsString('../secret.svg', json_encode($package, JSON_THROW_ON_ERROR));
        self::assertFileDoesNotExist($incoming->getPathname());
        self::assertSame(['pagekit-demo-1.2.3.zip'], $this->entries($this->staging));

        $staged = PackageArchive::open($this->staging.'/pagekit-demo-1.2.3.zip');
        self::assertSame('pagekit/demo', $staged->name());
        self::assertSame('1.2.3', $staged->version());
        self::assertSame([], $this->entries($this->packages));
    }

    /**
     * @param array<string, mixed> $package
     * @param list<string>         $absent
     */
    #[DataProvider('rejectedInstallRequests')]
    public function testARequestThatFailsThePatternsWritesNothing(array $package, array $absent): void
    {
        $stream = $this->stream(fn () => $this->controller(new Request())->installAction($package));

        self::assertSame("No valid package name and version given.\nstatus=error", $stream);

        foreach ($absent as $needle) {
            self::assertStringNotContainsString($needle, $stream);
        }

        self::assertSame(0, $this->manager->installs);
        self::assertSame([], $this->entries($this->staging));
        self::assertSame([], $this->entries($this->packages));
        self::assertSame([], $this->records->getRecords());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function rejectedInstallRequests(): array
    {
        return [
            'a traversing name' => [['name' => '../x', 'version' => '1.0.0', 'title' => 'Fancy Title'], ['../x', 'Fancy Title']],
            'build metadata in the version' => [['name' => 'pagekit/demo', 'version' => '1.0+build', 'title' => 'Fancy Title'], ['1.0+build', 'Fancy Title']],
            'a missing name' => [['version' => '1.0.0', 'title' => 'Fancy Title'], ['Fancy Title']],
            'a missing version' => [['name' => 'pagekit/demo', 'title' => 'Fancy Title'], ['Fancy Title']],
            'a name that is not a string' => [['name' => 1, 'version' => '1.0.0', 'title' => 'Fancy Title'], ['Fancy Title']],
        ];
    }

    public function testAMissingStagedArchiveNamesThePackageAndDoesNotInstall(): void
    {
        $stream = $this->stream(fn () => $this->controller(new Request())->installAction([
            'name' => 'pagekit/demo',
            'version' => '1.2.3',
        ]));

        self::assertSame("The uploaded archive of pagekit/demo 1.2.3 is gone. Upload it again.\nstatus=error", $stream);
        self::assertSame(0, $this->manager->installs);
        self::assertSame([], $this->records->getRecords());
        self::assertSame([], $this->entries($this->packages));
    }

    public function testAStagedArchiveTheCheckRefusesIsDeletedAndNotInstalled(): void
    {
        file_put_contents($this->staging.'/pagekit-demo-1.2.3.zip', 'not a zip');

        $stream = $this->stream(fn () => $this->controller(new Request())->installAction([
            'name' => 'pagekit/demo',
            'version' => '1.2.3',
        ]));

        self::assertSame('status=error', $this->lastLine($stream));
        self::assertStringContainsString('not a readable ZIP archive', $stream);
        self::assertSame(0, $this->manager->installs);
        self::assertFileDoesNotExist($this->staging.'/pagekit-demo-1.2.3.zip');
        self::assertSame([], $this->entries($this->staging));
        self::assertSame([], $this->entries($this->packages));
        self::assertSame(0, $this->cache->clears);
    }

    public function testAStagedArchiveForADifferentPackageIsDeletedAndNotInstalled(): void
    {
        $this->stage($this->extensionZip(['version' => '1.2.3']), 'pagekit/demo', '9.9.9');

        $stream = $this->stream(fn () => $this->controller(new Request())->installAction([
            'name' => 'pagekit/demo',
            'version' => '9.9.9',
        ]));

        self::assertSame("The uploaded archive is not pagekit/demo 9.9.9. Upload it again.\nstatus=error", $stream);
        self::assertSame(0, $this->manager->installs);
        self::assertSame([], $this->entries($this->staging));
        self::assertSame([], $this->entries($this->packages));
    }

    public function testTwoPackageNamesThatShareAStagedFileDoNotInstallTheOtherArchive(): void
    {
        $zip = $this->workspace.'/collision.zip';
        PackageZip::write($zip, [
            'name' => 'a/b-c',
            'version' => '1.0.0',
            'title' => 'Collision',
        ]);
        $this->stage($zip, 'a-b/c', '1.0.0');

        $stream = $this->stream(fn () => $this->controller(new Request())->installAction([
            'name' => 'a-b/c',
            'version' => '1.0.0',
        ]));

        self::assertSame("The uploaded archive is not a-b/c 1.0.0. Upload it again.\nstatus=error", $stream);
        self::assertSame(0, $this->manager->installs);
        self::assertSame([], $this->entries($this->staging));
        self::assertSame([], $this->entries($this->packages));
    }

    public function testInstallOfAnUploadedArchiveClearsTheCacheAndRemovesTheStagedFile(): void
    {
        $controller = $this->controller($this->requestWith($this->uploaded($this->extensionZip())));
        $package = $controller->uploadAction('extension')['package'];
        self::assertSame(['pagekit-demo-1.2.3.zip'], $this->entries($this->staging));

        $stream = $this->stream(fn () => $controller->installAction([
            'name' => $package->getName(),
            'version' => $package->get('version'),
            'path' => '/tmp/not-the-package',
            'type' => 'pagekit-theme',
        ]));

        self::assertSame("\nstatus=success", $stream);
        self::assertSame(1, $this->manager->installs);
        self::assertInstanceOf(PackageArchive::class, $this->manager->archive);
        self::assertSame('pagekit/demo', $this->manager->archive->name());
        self::assertSame('1.2.3', $this->manager->archive->version());
        self::assertSame('pagekit-extension', $this->manager->archive->type());
        self::assertSame(1, $this->cache->clears);
        self::assertSame([], $this->entries($this->staging));
        self::assertSame([], $this->entries($this->packages));
        self::assertSame([], $this->records->getRecords());
    }

    public function testAnInstallFailureIsStreamedAsItStandsAndTheStagedFileIsRemoved(): void
    {
        $this->stage($this->extensionZip(), 'pagekit/demo', '1.2.3');
        $this->manager->installFailure = new \RuntimeException('disk full on /secret');

        $stream = $this->stream(fn () => $this->controller(new Request())->installAction([
            'name' => 'pagekit/demo',
            'version' => '1.2.3',
        ]));

        self::assertSame("disk full on /secret\nstatus=error", $stream);
        self::assertStringNotContainsString('could not be completed', $stream);
        self::assertSame(1, $this->manager->installs);
        self::assertSame(0, $this->cache->clears);
        self::assertSame([], $this->entries($this->staging));
        self::assertSame([], $this->records->getRecords());
    }

    public function testAnInstallErrorIsLoggedAndThePageIsToldOnlyThatItFailed(): void
    {
        $this->stage($this->extensionZip(), 'pagekit/demo', '1.2.3');
        $this->manager->installFailure = new \Error('Undefined index in /var/secret.php');

        $stream = $this->stream(fn () => $this->controller(new Request())->installAction([
            'name' => 'pagekit/demo',
            'version' => '1.2.3',
        ]));

        self::assertSame("The installation could not be completed. See error log for details.\nstatus=error", $stream);
        self::assertStringNotContainsString('/var/secret.php', $stream);
        self::assertSame(1, $this->manager->installs);
        self::assertSame(0, $this->cache->clears);
        self::assertSame([], $this->entries($this->staging));
        self::assertCount(1, $this->records->getRecords());
        $record = $this->records->getRecords()[0];
        self::assertStringContainsString('Failed to install package "pagekit/demo"', $record->message);
        self::assertStringContainsString('Undefined index in /var/secret.php', $record->message);
        self::assertInstanceOf(\Error::class, $record->context['exception'] ?? null);
    }

    public function testACacheClearFailureAfterInstallIsLoggedAndTheInstallStillSucceeded(): void
    {
        $this->stage($this->extensionZip(), 'pagekit/demo', '1.2.3');
        $this->cache->failure = new \RuntimeException('cache disk');

        $stream = $this->stream(fn () => $this->controller(new Request())->installAction([
            'name' => 'pagekit/demo',
            'version' => '1.2.3',
        ]));

        self::assertSame("\nstatus=success", $stream);
        self::assertStringNotContainsString('cache disk', $stream);
        self::assertSame(1, $this->cache->clears);
        self::assertSame([], $this->entries($this->staging));
        self::assertCount(1, $this->records->getRecords());
        self::assertStringContainsString(
            'Failed to clear the cache after installing or removing a package',
            $this->records->getRecords()[0]->message,
        );
        self::assertStringContainsString('cache disk', $this->records->getRecords()[0]->message);
    }

    public function testAStagedDirectoryIsNotRemovedRecursivelyAndTheFailureIsOnlyLogged(): void
    {
        $staged = $this->staging.'/pagekit-demo-1.2.3.zip';
        mkdir($staged);
        file_put_contents($staged.'/kept.txt', 'stay');

        $stream = $this->stream(fn () => $this->controller(new Request())->installAction([
            'name' => 'pagekit/demo',
            'version' => '1.2.3',
        ]));

        self::assertSame("The uploaded archive of pagekit/demo 1.2.3 is gone. Upload it again.\nstatus=error", $stream);
        self::assertStringNotContainsString('Failed to delete', $stream);
        self::assertSame(0, $this->manager->installs);
        self::assertSame('stay', file_get_contents($staged.'/kept.txt'));
        self::assertCount(1, $this->records->getRecords());
        self::assertStringContainsString('Failed to delete the staged archive', $this->records->getRecords()[0]->message);
        self::assertStringContainsString($staged, $this->records->getRecords()[0]->message);
    }

    public function testAStagedArchiveThatCannotBeRemovedStillEndsWhenTheLogCannotTakeTheLine(): void
    {
        $staged = $this->staging.'/pagekit-demo-1.2.3.zip';
        mkdir($staged);
        file_put_contents($staged.'/kept.txt', 'stay');

        $stream = $this->stream(fn () => $this->controller(new Request(), null, new LogThatRefusesTheLine())->installAction([
            'name' => 'pagekit/demo',
            'version' => '1.2.3',
        ]));

        self::assertSame("The uploaded archive of pagekit/demo 1.2.3 is gone. Upload it again.\nstatus=error", $stream);
        self::assertStringNotContainsString('log down', $stream);
        self::assertSame(0, $this->manager->installs);
        self::assertSame('stay', file_get_contents($staged.'/kept.txt'));
        self::assertSame([], $this->records->getRecords());
    }

    public function testAStagedLinkIsRemovedWithoutDeletingTheArchiveItNames(): void
    {
        $real = $this->workspace.'/real.zip';
        PackageZip::write($real, [
            'name' => 'pagekit/demo',
            'version' => '1.2.3',
            'title' => 'Demo',
        ]);
        $staged = $this->staging.'/pagekit-demo-1.2.3.zip';
        self::assertTrue(symlink($real, $staged));

        $stream = $this->stream(fn () => $this->controller(new Request())->installAction([
            'name' => 'pagekit/demo',
            'version' => '1.2.3',
        ]));

        self::assertSame("\nstatus=success", $stream);
        self::assertSame(1, $this->manager->installs);
        self::assertFileDoesNotExist($staged);
        self::assertFileExists($real);
        self::assertSame([], $this->records->getRecords());
    }

    public function testARemovalErrorKeepsTheRemovalWording(): void
    {
        $this->manager->uninstallFailure = new \Error('Undefined index in /var/secret.php');

        $stream = $this->stream(fn () => $this->controller(new Request())->uninstallAction('pagekit/demo'));

        self::assertSame("The removal could not be completed. See error log for details.\nstatus=error", $stream);
        self::assertStringNotContainsString('/var/secret.php', $stream);
        self::assertStringNotContainsString('installation could not be completed', $stream);
        self::assertCount(1, $this->records->getRecords());
        $record = $this->records->getRecords()[0];
        self::assertStringContainsString('Failed to remove package "pagekit/demo"', $record->message);
        self::assertStringContainsString('Undefined index in /var/secret.php', $record->message);
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function extensionZip(array $composer = []): string
    {
        $path = $this->workspace.'/extension-'.bin2hex(random_bytes(3)).'.zip';
        PackageZip::write($path, array_merge([
            'name' => 'pagekit/demo',
            'type' => 'pagekit-extension',
            'version' => '1.2.3',
            'title' => 'Demo',
        ], $composer));

        return $path;
    }

    private function uploaded(string $zip, ?int $error = null): UploadedFile
    {
        $incoming = $this->workspace.'/incoming-'.bin2hex(random_bytes(3)).'.zip';
        copy($zip, $incoming);

        return new UploadedFile($incoming, 'package.zip', 'application/zip', $error, true);
    }

    private function stage(string $zip, string $name, string $version): string
    {
        $staged = $this->staging.'/'.strtr($name, '/', '-').'-'.$version.'.zip';
        copy($zip, $staged);

        return $staged;
    }

    private function requestWith(UploadedFile $file): Request
    {
        $request = new Request();
        $request->files->set('file', $file);

        return $request;
    }

    private function refusedUpload(?UploadedFile $file, string $type = 'extension', ?PackageFactory $factory = null): BadRequestHttpException
    {
        try {
            $this->controller($file === null ? new Request() : $this->requestWith($file), $factory)->uploadAction($type);
        } catch (BadRequestHttpException $exception) {
            return $exception;
        }

        self::fail('The upload was expected to be refused.');
    }

    private function controller(Request $request, ?PackageFactory $factory = null, ?Logger $log = null): PackageController
    {
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('get')->willReturnCallback(fn (string $name): mixed => $name === 'system/cache' ? $this->cache : null);
        $url = $this->createMock(UrlProvider::class);

        return new PackageController(
            $this->manager,
            $factory ?? new PackageFactory(),
            $modules,
            $url,
            $request,
            new PagekitResponse($url),
            $this->staging,
            false,
            $log ?? $this->log,
        );
    }

    /**
     * @param callable(): StreamedResponse $call
     */
    private function stream(callable $call): string
    {
        $response = $call();
        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();

        try {
            $response->sendContent();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function lastLine(string $stream): string
    {
        $lines = explode("\n", $stream);

        return (string) end($lines);
    }

    private function newManager(): PackageManagerUnderTest
    {
        $app = new Application();
        $app->set('path.temp', $this->workspace.'/tmp/temp');
        $app->set('path.cache', $this->workspace.'/tmp/cache');
        $app->set('path.vendor', $this->workspace.'/app/vendor');
        $app->set('path.packages', $this->packages);
        $app->set('system.api', 'https://example.test');
        $app->set('file', new Filesystem());

        return new PackageManagerUnderTest($app);
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
 * Records the archive install was handed, or fails the way the case needs.
 */
final class PackageManagerUnderTest extends PackageManager
{
    public int $installs = 0;

    public ?PackageArchive $archive = null;

    public ?\Throwable $installFailure = null;

    public ?\Throwable $uninstallFailure = null;

    public function install(PackageArchive $archive): void
    {
        $this->installs++;
        $this->archive = $archive;

        if ($this->installFailure !== null) {
            throw $this->installFailure;
        }
    }

    public function uninstall(string|array $uninstall): void
    {
        if ($this->uninstallFailure !== null) {
            throw $this->uninstallFailure;
        }

        parent::uninstall($uninstall);
    }
}

/**
 * A manifest that cannot be turned into a package.
 */
final class FactoryThatCannotLoad extends PackageFactory
{
    public function load(string|array $data): ?\Pagekit\Package\Package
    {
        return null;
    }
}

/**
 * Counts cache rebuilds, and can refuse one.
 */
final class CacheDouble
{
    public int $clears = 0;

    public ?\Throwable $failure = null;

    public function clearCache(): void
    {
        $this->clears++;

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}

/**
 * The error log refuses the line about a staged archive that stayed behind.
 */
final class LogThatRefusesTheLine extends Logger
{
    public function __construct()
    {
        parent::__construct('test');
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('log down');
    }
}
