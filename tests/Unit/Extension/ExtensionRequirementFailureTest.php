<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Extension;

use Pagekit\Application;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Module\UnsatisfiedRequirementException;
use Pagekit\Package\Extension\ExtensionFailureStore;
use Pagekit\System\Extension\ExtensionLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A requirement an enabled extension cannot meet costs that extension; a theme is only recorded.
 */
final class ExtensionRequirementFailureTest extends TestCase
{
    private string $workspace;

    private ModuleManager $modules;

    /** @var list<string> */
    private array $disabled = [];

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_extension_requirement_' . getmypid() . '_' . uniqid();
        $this->disabled = [];

        if (!mkdir($this->workspace, 0755, true) && !is_dir($this->workspace)) {
            self::fail('The fixture workspace could not be created.');
        }

        $this->modules = new ModuleManager(new Application());
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testAnEnabledExtensionWhoseRequirementIsMissingIsDisabledAndRecorded(): void
    {
        $this->modules->register([
            $this->declareModule('blog', ['missing']),
            $this->declareModule('pages'),
        ]);
        $this->modules->setActivityPolicy(['blog', 'pages'], 'system');

        $store = $this->store();
        $this->loader($store)->load(['blog', 'pages'], null);

        self::assertSame(['blog'], $this->disabled);
        self::assertNull($this->modules->get('blog'));
        self::assertInstanceOf(Module::class, $this->modules->get('pages'));

        $entry = $store->all()['blog'] ?? null;

        self::assertNotNull($entry);
        self::assertSame(ExtensionFailureStore::TYPE_EXTENSION, $entry['type']);
        self::assertSame(UnsatisfiedRequirementException::class, $entry['class']);
        self::assertSame(
            'Module "blog" requires "missing", which is not registered.',
            $entry['message'],
        );
    }

    public function testAThemeWhoseRequirementFailsIsRecordedAndNotSwitchedOff(): void
    {
        $this->modules->register([
            $this->declareModule('site-theme', ['missing']),
            $this->declareModule('pages'),
        ]);
        $this->modules->setActivityPolicy(['site-theme', 'pages'], 'system');

        $store = $this->store();
        $this->loader($store)->load(['pages'], 'site-theme');

        self::assertSame([], $this->disabled);
        self::assertNull($this->modules->get('site-theme'));
        self::assertInstanceOf(Module::class, $this->modules->get('pages'));

        $entry = $store->all()['site-theme'] ?? null;

        self::assertNotNull($entry);
        self::assertSame(ExtensionFailureStore::TYPE_THEME, $entry['type']);
        self::assertSame(UnsatisfiedRequirementException::class, $entry['class']);
        self::assertSame(
            'Module "site-theme" requires "missing", which is not registered.',
            $entry['message'],
        );
    }

    private function loader(ExtensionFailureStore $store): ExtensionLoader
    {
        return new ExtensionLoader(
            $this->modules,
            new NullLogger(),
            $store,
            function (string $name): void {
                $this->disabled[] = $name;
            },
        );
    }

    private function store(): ExtensionFailureStore
    {
        return new ExtensionFailureStore($this->workspace . '/system', new Filesystem());
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
