<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Monolog\Handler\TestHandler;
use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Log\Logger;
use Pagekit\Module\ModuleManager;
use Pagekit\Module\UnsatisfiedRequirementException;
use Pagekit\Package\Archive\ArchiveRefusedException;
use Pagekit\Package\Archive\PackageArchive;
use Pagekit\Package\Controller\PackageController;
use Pagekit\Package\InstallProbes;
use Pagekit\Package\PackageFactory;
use Pagekit\Package\PackageManager;
use Pagekit\Routing\Response as PagekitResponse;
use Pagekit\Routing\UrlProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * An archive requirement is refused before a package file is written.
 */
final class PackageArchiveRequirementTest extends TestCase
{
    private string $workspace = '';

    private string $packages = '';

    private string $staging = '';

    private Config $system;

    private BufferedOutput $output;

    private CountingFilesystem $files;

    private Application $app;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        InstallProbes::reset();

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_archive_req_' . bin2hex(random_bytes(4));
        $this->packages = $this->workspace . '/packages';
        $this->staging = $this->workspace . '/staging';
        mkdir($this->packages, 0777, true);
        mkdir($this->staging, 0777, true);
        file_put_contents($this->packages . '/canary.txt', 'stay');

        $this->system = new Config([
            'extensions' => ['kept'],
        ]);
        $this->output = new BufferedOutput();
        $this->files = new CountingFilesystem();

        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($this->system);

        $factory = new PackageFactory();
        $factory->addPath($this->packages . '/*/*/composer.json');

        $this->app = new Application();
        $this->app->set('config', $config);
        $this->app->set('package', $factory);
        $this->app->set('file', $this->files);
        $this->app->set('path.temp', $this->workspace . '/tmp/temp');
        $this->app->set('path.cache', $this->workspace . '/tmp/cache');
        $this->app->set('path.vendor', $this->workspace . '/app/vendor');
        $this->app->set('path.packages', $this->packages);
        $this->app->set('system.api', 'https://example.test');
    }

    protected function tearDown(): void
    {
        InstallProbes::reset();

        if ($this->workspace !== '') {
            $this->removeTree($this->workspace);
        }
    }

    public function testInstallRefusesAnUnregisteredRequirementBeforeAnythingIsWritten(): void
    {
        $before = $this->entries($this->packages);

        $exception = $this->refusal($this->archive("['missing']"));

        self::assertInstanceOf(ArchiveRefusedException::class, $exception);
        self::assertSame(
            'Module "demo" requires "missing", which is not registered.',
            $exception->getMessage(),
        );
        $this->assertNothingWritten($before);
    }

    public function testInstallNamesTheFirstUnregisteredRequirementBeforeTheActivationWalk(): void
    {
        $this->plant();
        $this->register('demo');
        $this->register('comments');
        $this->modules()->setActivityPolicy(['demo'], 'system');
        $this->modules()->load('demo');
        $before = $this->entries($this->packages);
        $require = <<<'PHP'
            [
                'comments',
                'missing',
                'also-missing',
            ]
            PHP;

        $exception = $this->refusal($this->archive($require));

        self::assertInstanceOf(ArchiveRefusedException::class, $exception);
        self::assertSame(
            'Module "demo" requires "missing", which is not registered.',
            $exception->getMessage(),
        );
        $this->assertNothingWritten($before);
        $this->assertInstalledTreeUntouched();
    }

    public function testInstallQuotesAnUnregisteredRequirementWithoutControlCharacters(): void
    {
        $before = $this->entries($this->packages);
        $required = "missing\x01name";

        $exception = $this->refusal($this->archive('[' . var_export($required, true) . ']'));

        self::assertInstanceOf(ArchiveRefusedException::class, $exception);
        self::assertSame(
            'Module "demo" requires "missing?name", which is not registered.',
            $exception->getMessage(),
        );
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $exception->getMessage());
        $this->assertNothingWritten($before);
    }

    public function testAFreshInstallAllowsARegisteredButDisabledRequirement(): void
    {
        $this->register('comments');
        $this->register('captcha');
        $this->modules()->setActivityPolicy([], 'system');
        $require = <<<'PHP'
            [
                'comments',
                'captcha',
            ]
            PHP;

        $this->manager()->install($this->archive($require));

        self::assertGreaterThan(0, $this->files->directories);
        self::assertSame('new', (string) file_get_contents($this->packages . '/pagekit/demo/fresh.txt'));
        self::assertSame(['canary.txt', 'pagekit'], $this->entries($this->packages));
        self::assertSame(['demo'], $this->entries($this->packages . '/pagekit'));
        self::assertSame('stay', (string) file_get_contents($this->packages . '/canary.txt'));
        self::assertSame(['kept'], $this->system->get('extensions'));
        self::assertSame('1.2.3', $this->system->get('packages.demo'));
        self::assertNull($this->modules()->get('comments'));
        self::assertNull($this->modules()->get('captcha'));
        self::assertNull($this->modules()->get('demo'));
        self::assertSame('', $this->output->fetch());
    }

    public function testAnUpdateRefusesADisabledRequirementBeforeReplaceTree(): void
    {
        $this->plant();
        $this->register('demo');
        $this->register('comments');
        $this->modules()->setActivityPolicy(['demo'], 'system');
        $this->modules()->load('demo');
        $before = $this->entries($this->packages);

        $exception = $this->refusal($this->archive("['comments']"));

        self::assertInstanceOf(UnsatisfiedRequirementException::class, $exception);
        self::assertTrue($exception->registered);
        self::assertSame('demo', $exception->depender);
        self::assertSame('comments', $exception->requirement);
        self::assertSame(
            'Module "demo" requires "comments", which is registered but disabled.',
            $exception->getMessage(),
        );
        $this->assertNothingWritten($before);
        $this->assertInstalledTreeUntouched();
        $this->modules()->assertRequirements('demo');
        self::assertSame([], $this->modules()->requiredBy('comments'));
        self::assertNull($this->modules()->get('comments'));
        self::assertNotNull($this->modules()->get('demo'));
    }

    public function testAnUpdateWalksTheArchiveModuleRatherThanTheInstalledOne(): void
    {
        $this->plant(['module' => 'legacy-demo']);
        $this->register('legacy-demo');
        $this->register('comments');
        $this->modules()->setActivityPolicy(['legacy-demo'], 'system');
        $this->modules()->load('legacy-demo');
        $before = $this->entries($this->packages);

        $exception = $this->refusal($this->archive("['comments']"));

        self::assertInstanceOf(UnsatisfiedRequirementException::class, $exception);
        self::assertSame('demo', $exception->depender);
        self::assertSame('comments', $exception->requirement);
        self::assertSame(
            'Module "demo" requires "comments", which is registered but disabled.',
            $exception->getMessage(),
        );
        $this->assertNothingWritten($before);
        $this->assertInstalledTreeUntouched();
        self::assertFalse($this->modules()->isRegistered('demo'));
        self::assertTrue($this->modules()->isRegistered('legacy-demo'));
        self::assertSame([], $this->modules()->requiredBy('comments'));
        self::assertNotNull($this->modules()->get('legacy-demo'));
    }

    public function testAnUpdateRefusesACycleBeforeReplaceTree(): void
    {
        $this->plant();
        $this->register('demo');
        $this->register('beta', ['demo']);
        $this->modules()->setActivityPolicy(['demo', 'beta'], 'system');
        $this->modules()->load('demo');
        $before = $this->entries($this->packages);

        $exception = $this->refusal($this->archive("['beta']"));

        self::assertSame(\RuntimeException::class, $exception::class);
        self::assertSame('Circular requirement "beta > demo" detected.', $exception->getMessage());
        $this->assertNothingWritten($before);
        $this->assertInstalledTreeUntouched();
        self::assertSame(['beta'], $this->modules()->requiredBy('demo'));
        self::assertSame([], $this->modules()->requiredBy('beta'));
        $this->modules()->assertRequirements('demo');
    }

    public function testAnUpdateAllowsARequirementThatIsActive(): void
    {
        $this->plant();
        $this->system->set('extensions', ['kept', 'demo']);
        $this->system->set('packages.demo', '1.0.0');
        $this->register('demo');
        $this->register('comments');
        $this->modules()->setActivityPolicy(['demo', 'comments'], 'system');
        $this->modules()->load('demo');

        $this->manager()->install($this->archive("['comments']"));

        self::assertGreaterThan(0, $this->files->directories);
        self::assertSame('new', (string) file_get_contents($this->packages . '/pagekit/demo/fresh.txt'));
        self::assertFileDoesNotExist($this->packages . '/pagekit/demo/marker.txt');
        self::assertSame(['demo'], $this->entries($this->packages . '/pagekit'));
        self::assertSame(['kept', 'demo'], $this->system->get('extensions'));
        self::assertSame('1.2.3', $this->system->get('packages.demo'));
        self::assertNull($this->modules()->get('comments'));
        self::assertSame('', $this->output->fetch());
    }

    public function testAServiceThatIsNotAModuleManagerRefusesARequirementBeforeAnythingIsWritten(): void
    {
        $this->app->set('module', new \stdClass());
        $before = $this->entries($this->packages);

        $exception = $this->refusal($this->archive("['missing']"));

        self::assertInstanceOf(ArchiveRefusedException::class, $exception);
        self::assertSame(
            'Module "demo" requires "missing", which is not registered.',
            $exception->getMessage(),
        );
        $this->assertNothingWritten($before);
    }

    public function testAnEmptyRequireDoesNotReadTheModuleServiceAndStillInstalls(): void
    {
        $read = false;
        $this->app->set('module', static function () use (&$read): never {
            $read = true;

            throw new \RuntimeException('module service was read');
        });

        $archive = $this->archive('[]');
        self::assertSame([], $archive->require());

        try {
            $this->manager()->install($archive);
        } catch (\Throwable $e) {
            self::assertFalse($read, 'An empty require must not read the module service.');

            throw $e;
        }

        self::assertFalse($read);
        self::assertGreaterThan(0, $this->files->directories);
        self::assertSame('new', (string) file_get_contents($this->packages . '/pagekit/demo/fresh.txt'));
        self::assertSame(['kept'], $this->system->get('extensions'));
        self::assertSame('1.2.3', $this->system->get('packages.demo'));
        self::assertSame('', $this->output->fetch());
    }

    public function testUploadOfAnUnregisteredRequirementIsABadRequestBeforeTheFileIsStaged(): void
    {
        $incoming = $this->uploaded($this->zip("['missing']"));

        $exception = $this->refusedUpload($incoming);

        self::assertSame(400, $exception->getStatusCode());
        self::assertSame(
            'Module "demo" requires "missing", which is not registered.',
            $exception->getMessage(),
        );
        self::assertInstanceOf(ArchiveRefusedException::class, $exception->getPrevious());
        self::assertFileExists($incoming->getPathname());
        self::assertSame([], $this->entries($this->staging));
        self::assertSame(['canary.txt'], $this->entries($this->packages));
        self::assertSame(0, $this->files->directories);
    }

    public function testUploadOfADisabledRequirementIsStaged(): void
    {
        $this->register('comments');
        $this->modules()->setActivityPolicy([], 'system');
        $incoming = $this->uploaded($this->zip("['comments']"));

        $result = $this->controller($this->requestWith($incoming))->uploadAction('extension');

        self::assertSame('pagekit/demo', $result['package']->get('name'));
        self::assertFileDoesNotExist($incoming->getPathname());
        self::assertSame(['pagekit-demo-1.2.3.zip'], $this->entries($this->staging));
        self::assertSame(['canary.txt'], $this->entries($this->packages));
        self::assertSame(0, $this->files->directories);
        self::assertSame(['comments'], PackageArchive::open($this->staging . '/pagekit-demo-1.2.3.zip')->require());
    }

    private function manager(): PackageManager
    {
        return new PackageManager($this->app, $this->output);
    }

    private function modules(): ModuleManager
    {
        $modules = $this->app->get('module');
        self::assertInstanceOf(ModuleManager::class, $modules);

        return $modules;
    }

    /**
     * @param list<string> $require
     */
    private function register(string $name, array $require = []): void
    {
        $directory = $this->workspace . '/modules/' . $name;

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail('The fixture module directory could not be created.');
        }

        $file = $directory . '/index.php';
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'name' => " . var_export($name, true) . ",\n    'require' => " . var_export(array_values($require), true) . ",\n];\n";

        if (file_put_contents($file, $contents) === false) {
            self::fail('The fixture module could not be written.');
        }

        $this->modules()->register([$file]);
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function plant(array $composer = []): void
    {
        $tree = $this->packages . '/pagekit/demo';
        mkdir($tree, 0777, true);
        file_put_contents($tree . '/composer.json', json_encode(array_merge([
            'name' => 'pagekit/demo',
            'type' => 'pagekit-extension',
            'version' => '1.0.0',
            'title' => 'Demo',
        ], $composer), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        file_put_contents($tree . '/marker.txt', 'old');
    }

    private function archive(string $require): PackageArchive
    {
        return PackageArchive::open($this->zip($require));
    }

    private function zip(string $require): string
    {
        $path = $this->workspace . '/incoming-' . bin2hex(random_bytes(3)) . '.zip';
        PackageZip::write($path, [
            'name' => 'pagekit/demo',
            'type' => 'pagekit-extension',
            'version' => '1.2.3',
            'title' => 'Demo',
        ], [
            'fresh.txt' => 'new',
        ], $this->index($require));

        return $path;
    }

    private function index(string $require): string
    {
        return "<?php\n\nreturn [\n    'name' => 'demo',\n    'autoload' => [],\n    'require' => " . $require . ",\n];\n";
    }

    private function refusal(PackageArchive $archive): \Throwable
    {
        try {
            $this->manager()->install($archive);
        } catch (\Throwable $exception) {
            return $exception;
        }

        self::fail('The install was expected to be refused.');
    }

    /**
     * @param list<string> $before
     */
    private function assertNothingWritten(array $before): void
    {
        self::assertSame(0, $this->files->directories);
        self::assertSame($before, $this->entries($this->packages));
        self::assertSame('stay', (string) file_get_contents($this->packages . '/canary.txt'));
        self::assertSame('', $this->output->fetch());
    }

    private function assertInstalledTreeUntouched(): void
    {
        self::assertSame(['demo'], $this->entries($this->packages . '/pagekit'));
        self::assertSame('old', (string) file_get_contents($this->packages . '/pagekit/demo/marker.txt'));
        self::assertFileDoesNotExist($this->packages . '/pagekit/demo/fresh.txt');
    }

    private function uploaded(string $zip): UploadedFile
    {
        $incoming = $this->workspace . '/upload-' . bin2hex(random_bytes(3)) . '.zip';
        copy($zip, $incoming);

        return new UploadedFile($incoming, 'package.zip', 'application/zip', null, true);
    }

    private function requestWith(UploadedFile $file): Request
    {
        $request = new Request();
        $request->files->set('file', $file);

        return $request;
    }

    private function refusedUpload(UploadedFile $file): BadRequestHttpException
    {
        try {
            $this->controller($this->requestWith($file))->uploadAction('extension');
        } catch (BadRequestHttpException $exception) {
            return $exception;
        }

        self::fail('The upload was expected to be refused.');
    }

    private function controller(Request $request): PackageController
    {
        $url = $this->createMock(UrlProvider::class);

        return new PackageController(
            $this->manager(),
            new PackageFactory(),
            $this->modules(),
            $url,
            $request,
            new PagekitResponse($url),
            $this->staging,
            false,
            new Logger('test', [new TestHandler()]),
        );
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
            $this->removeTree($path . '/' . $entry);
        }

        rmdir($path);
    }
}

/**
 * Counts the directories an install creates, which is how a package tree is unpacked.
 */
final class CountingFilesystem extends Filesystem
{
    public int $directories = 0;

    public function makeDir(string $dir, int $mode = 0777, bool $recursive = true): bool
    {
        $this->directories++;

        return parent::makeDir($dir, $mode, $recursive);
    }
}
