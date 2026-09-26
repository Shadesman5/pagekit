<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Monolog\Handler\TestHandler;
use Pagekit\Application;
use Pagekit\Intl\IntlModule;
use Pagekit\Intl\IntlServiceLocator;
use Pagekit\Log\Logger;
use Pagekit\Module\ModuleManager;
use Pagekit\Package\Controller\PackageController;
use Pagekit\Package\Package;
use Pagekit\Package\PackageFactory;
use Pagekit\Package\PackageManager;
use Pagekit\Routing\Response;
use Pagekit\Routing\UrlProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * The enable action names an unsatisfied requirement even when debug output is off.
 */
final class PackageControllerEnableRequirementTest extends TestCase
{
    private string $workspace;

    private string|false $displayErrors;

    protected function setUp(): void
    {
        $this->displayErrors = ini_get('display_errors');
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_enable_action_' . getmypid() . '_' . uniqid();

        if (!mkdir($this->workspace, 0755, true) && !is_dir($this->workspace)) {
            self::fail('The fixture workspace could not be created.');
        }

        $translator = new Translator('en_US');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'Module "%depender%" requires "%required%", which is not registered.' => 'unregistered:%depender%:%required%',
            'Module "%depender%" requires "%required%", which is registered but disabled.' => 'disabled:%depender%:%required%',
            'Unable to enable "%name%". See error log for details.' => 'generic:%name%',
        ], 'en_US');

        IntlServiceLocator::register(new IntlServiceLocator(
            $translator,
            new IntlModule([
                'name' => 'system/intl',
                'path' => dirname(__DIR__, 3) . '/app/system/modules/intl',
                'config' => ['locale' => 'en_US'],
            ]),
        ));
    }

    protected function tearDown(): void
    {
        if ($this->displayErrors !== false) {
            ini_set('display_errors', $this->displayErrors);
        }

        pagekit_phpunit_install_intl_locator();
        $this->removeTree($this->workspace);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function unsatisfiedRequirements(): iterable
    {
        yield 'unregistered, debug off' => [false, false];
        yield 'unregistered, debug on' => [true, false];
        yield 'disabled, debug off' => [false, true];
        yield 'disabled, debug on' => [true, true];
    }

    #[DataProvider('unsatisfiedRequirements')]
    public function testEnableNamesTheUnsatisfiedRequirement(bool $debug, bool $registered): void
    {
        $modules = new ModuleManager(new Application());
        $required = $registered ? 'comments' : 'missing';
        $files = [$this->declareModule('blog', [$required])];

        if ($registered) {
            $files[] = $this->declareModule('comments');
        }

        $modules->register($files);
        $modules->setActivityPolicy(['blog'], 'system');

        $result = $this->controller($modules, $this->package('pagekit/blog', 'blog'), $debug)->enableAction('pagekit/blog');

        $kind = $registered ? 'disabled' : 'unregistered';

        self::assertSame(['error' => $kind . ':blog:' . $required], $result);
        self::assertNull($modules->get('blog'));

        if ($registered) {
            self::assertNull($modules->get('comments'));
        }
    }

    public function testACircularRequirementStaysGenericWhenDebugIsOff(): void
    {
        self::assertSame(
            ['error' => 'generic:pagekit/alpha'],
            $this->enableCycle(false),
        );
    }

    public function testACircularRequirementIsReportedWhenDebugIsOn(): void
    {
        self::assertSame(
            ['error' => 'Circular requirement "beta > alpha" detected.'],
            $this->enableCycle(true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function enableCycle(bool $debug): array
    {
        $modules = new ModuleManager(new Application());
        $modules->register([
            $this->declareModule('alpha', ['beta']),
            $this->declareModule('beta', ['alpha']),
        ]);
        $modules->setActivityPolicy(['alpha', 'beta'], 'system');

        return $this->controller($modules, $this->package('pagekit/alpha', 'alpha'), $debug)->enableAction('pagekit/alpha');
    }

    private function controller(ModuleManager $modules, Package $package, bool $debug): PackageController
    {
        $packages = new PackageFactory();
        $packages[$package->getName()] = $package;

        $log = new Logger('package');
        $log->pushHandler(new TestHandler());

        return new PackageController(
            $this->createMock(PackageManager::class),
            $packages,
            $modules,
            $this->createMock(UrlProvider::class),
            Request::create('/'),
            $this->createMock(Response::class),
            $this->workspace,
            $debug,
            $log,
        );
    }

    private function package(string $name, string $module): Package
    {
        return new Package([
            'name' => $name,
            'type' => 'pagekit-extension',
            'title' => 'Example',
            'module' => $module,
        ]);
    }

    /**
     * @param list<string> $require
     */
    private function declareModule(string $name, array $require = []): string
    {
        $directory = $this->workspace . '/' . $name;

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            self::fail('The fixture module directory could not be created.');
        }

        $file = $directory . '/index.php';
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'name' => " . var_export($name, true) . ",\n    'require' => " . var_export(array_values($require), true) . ",\n];\n";

        if (file_put_contents($file, $contents) === false) {
            self::fail('The fixture module could not be written.');
        }

        return $file;
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
            $this->removeTree($path . '/' . $entry);
        }

        rmdir($path);
    }
}
