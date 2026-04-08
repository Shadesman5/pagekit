<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Installer;

use Pagekit\Installer\Package\PackageManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \Pagekit\Installer\Package\PackageManager
 */
class PackageManagerNamespaceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pk_pm_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createPackageManager(): PackageManager
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        return new PackageManager($container, new \Symfony\Component\Console\Output\NullOutput());
    }

    private function invokePrivate(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invoke($obj, ...$args);
    }

    private function invokeProtected(object $obj, string $method, array $args = []): mixed
    {
        return $this->invokePrivate($obj, $method, $args);
    }

    // ── unescapePhpString ──

    public function testUnescapeSimpleNamespace(): void
    {
        $pm = $this->createPackageManager();
        $result = $this->invokePrivate($pm, 'unescapePhpString', ['Pagekit\\\\Blog\\\\']);
        $this->assertSame('Pagekit\\Blog\\', $result);
    }

    public function testUnescapeEscapedQuote(): void
    {
        $pm = $this->createPackageManager();
        $result = $this->invokePrivate($pm, 'unescapePhpString', ["it\\'s"]);
        $this->assertSame("it's", $result);
    }

    public function testUnescapeBackslashThenQuote(): void
    {
        $pm = $this->createPackageManager();
        // Raw source: \\' represents literal \ followed by literal '
        $result = $this->invokePrivate($pm, 'unescapePhpString', ["\\\\'"]); // 3 chars: \ \ '
        $this->assertSame("\\'", $result); // 2 chars: \ '
    }

    public function testUnescapeNoEscapes(): void
    {
        $pm = $this->createPackageManager();
        $result = $this->invokePrivate($pm, 'unescapePhpString', ['plain text']);
        $this->assertSame('plain text', $result);
    }

    public function testUnescapeEmptyString(): void
    {
        $pm = $this->createPackageManager();
        $result = $this->invokePrivate($pm, 'unescapePhpString', ['']);
        $this->assertSame('', $result);
    }

    // ── extractBracketBody ──

    public function testExtractBracketBodySimple(): void
    {
        $pm = $this->createPackageManager();
        $content = "['a' => 'b']";
        $result = $this->invokePrivate($pm, 'extractBracketBody', [$content, 0]);
        $this->assertSame("'a' => 'b'", $result);
    }

    public function testExtractBracketBodyWithBracketInString(): void
    {
        $pm = $this->createPackageManager();
        $content = "['key]val' => 'src[0]']";
        $result = $this->invokePrivate($pm, 'extractBracketBody', [$content, 0]);
        $this->assertSame("'key]val' => 'src[0]'", $result);
    }

    public function testExtractBracketBodyNested(): void
    {
        $pm = $this->createPackageManager();
        $content = "['outer' => ['inner']]";
        $result = $this->invokePrivate($pm, 'extractBracketBody', [$content, 0]);
        $this->assertSame("'outer' => ['inner']", $result);
    }

    public function testExtractBracketBodyWithEscapedBackslash(): void
    {
        $pm = $this->createPackageManager();
        $content = "['Pagekit\\\\Blog\\\\' => 'src']";
        $result = $this->invokePrivate($pm, 'extractBracketBody', [$content, 0]);
        $this->assertSame("'Pagekit\\\\Blog\\\\' => 'src'", $result);
    }

    public function testExtractBracketBodyUnclosed(): void
    {
        $pm = $this->createPackageManager();
        $result = $this->invokePrivate($pm, 'extractBracketBody', ['[unclosed', 0]);
        $this->assertNull($result);
    }

    public function testExtractBracketBodyInvalidPosition(): void
    {
        $pm = $this->createPackageManager();
        $result = $this->invokePrivate($pm, 'extractBracketBody', ['abc', 0]);
        $this->assertNull($result);
    }

    public function testExtractBracketBodyOutOfBounds(): void
    {
        $pm = $this->createPackageManager();
        $result = $this->invokePrivate($pm, 'extractBracketBody', ['[x]', 99]);
        $this->assertNull($result);
    }

    // ── parseAutoloadFromIndexFile ──

    public function testParseAutoloadStandardFormat(): void
    {
        $pm = $this->createPackageManager();
        $file = $this->tmpDir . '/index.php';
        file_put_contents($file, "<?php\nreturn [\n    'autoload' => [\n        'Pagekit\\\\Blog\\\\' => 'src',\n    ],\n];");

        $result = $this->invokePrivate($pm, 'parseAutoloadFromIndexFile', [$file]);
        $this->assertSame(['Pagekit\\Blog\\' => 'src'], $result);
    }

    public function testParseAutoloadDoubleQuotes(): void
    {
        $pm = $this->createPackageManager();
        $file = $this->tmpDir . '/index_dq.php';
        file_put_contents($file, "<?php\nreturn [\n    \"autoload\" => [\n        \"Vendor\\\\Ext\\\\\" => \"src\",\n    ],\n];");

        $result = $this->invokePrivate($pm, 'parseAutoloadFromIndexFile', [$file]);
        $this->assertSame(['Vendor\\Ext\\' => 'src'], $result);
    }

    public function testParseAutoloadNoAutoloadKey(): void
    {
        $pm = $this->createPackageManager();
        $file = $this->tmpDir . '/no_autoload.php';
        file_put_contents($file, "<?php\nreturn [\n    'name' => 'test',\n];");

        $result = $this->invokePrivate($pm, 'parseAutoloadFromIndexFile', [$file]);
        $this->assertNull($result);
    }

    public function testParseAutoloadWithClosures(): void
    {
        $pm = $this->createPackageManager();
        $file = $this->tmpDir . '/closures.php';
        $content = <<<'PHP'
<?php
return [
    'autoload' => [
        'My\\Ext\\' => 'src',
    ],
    'events' => [
        'boot' => function ($event) use ($app) {
            $app->get('something');
        },
    ],
];
PHP;
        file_put_contents($file, $content);

        $result = $this->invokePrivate($pm, 'parseAutoloadFromIndexFile', [$file]);
        $this->assertSame(['My\\Ext\\' => 'src'], $result);
    }

    public function testParseAutoloadNonexistentFile(): void
    {
        $pm = $this->createPackageManager();
        $result = @$this->invokePrivate($pm, 'parseAutoloadFromIndexFile', ['/nonexistent/path.php']);
        $this->assertNull($result);
    }

    // ── resolveExtensionMigrationNamespace ──

    public function testResolveFromIndexPhp(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $pm = new PackageManager($container, new \Symfony\Component\Console\Output\NullOutput());

        $pkgDir = $this->tmpDir . '/my-ext';
        mkdir($pkgDir, 0777, true);
        file_put_contents($pkgDir . '/index.php', "<?php\nreturn [\n    'autoload' => [\n        'Vendor\\\\MyExt\\\\' => 'src',\n    ],\n];");

        $package = $this->createPackageStub('my-ext', $pkgDir, null);
        $result = $this->invokeProtected($pm, 'resolveExtensionMigrationNamespace', [$package]);
        $this->assertSame('Vendor\\MyExt\\Migrations', $result);
    }

    public function testResolveFromComposerJson(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $pm = new PackageManager($container, new \Symfony\Component\Console\Output\NullOutput());

        $pkgDir = $this->tmpDir . '/composer-ext';
        mkdir($pkgDir, 0777, true);
        file_put_contents($pkgDir . '/composer.json', json_encode([
            'name' => 'vendor/composer-ext',
            'autoload' => ['psr-4' => ['Vendor\\ComposerExt\\' => 'src/']],
        ]));

        $package = $this->createPackageStub('composer-ext', $pkgDir, null);
        $result = $this->invokeProtected($pm, 'resolveExtensionMigrationNamespace', [$package]);
        $this->assertSame('Vendor\\ComposerExt\\Migrations', $result);
    }

    public function testResolveStudlyCapsFallback(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $pm = new PackageManager($container, new \Symfony\Component\Console\Output\NullOutput());

        $pkgDir = $this->tmpDir . '/no-autoload';
        mkdir($pkgDir, 0777, true);

        $package = $this->createPackageStub('no-autoload', $pkgDir, null, 'vendor/my-cool_ext');
        $result = $this->invokeProtected($pm, 'resolveExtensionMigrationNamespace', [$package]);
        $this->assertSame('Vendor\\MyCoolExt\\Migrations', $result);
    }

    public function testResolveIndexPhpTakesPrecedenceOverComposer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $pm = new PackageManager($container, new \Symfony\Component\Console\Output\NullOutput());

        $pkgDir = $this->tmpDir . '/both-ext';
        mkdir($pkgDir, 0777, true);
        file_put_contents($pkgDir . '/index.php', "<?php\nreturn [\n    'autoload' => [\n        'IndexNs\\\\' => 'src',\n    ],\n];");
        file_put_contents($pkgDir . '/composer.json', json_encode([
            'name' => 'vendor/both-ext',
            'autoload' => ['psr-4' => ['ComposerNs\\' => 'src/']],
        ]));

        $package = $this->createPackageStub('both-ext', $pkgDir, null);
        $result = $this->invokeProtected($pm, 'resolveExtensionMigrationNamespace', [$package]);
        $this->assertSame('IndexNs\\Migrations', $result);
    }

    /**
     * @return object A mock package object with get()/getName() methods
     */
    private function createPackageStub(string $module, ?string $path, ?string $type, ?string $name = null): object
    {
        $data = [
            'module' => $module,
            'path' => $path,
            'name' => $name,
        ];

        $stub = new class ($data) {
            /** @param array<string, mixed> $data */
            public function __construct(private readonly array $data) {}

            public function get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function getName(): ?string
            {
                return $this->data['name'] ?? null;
            }

            public function getType(): ?string
            {
                return $this->data['type'] ?? null;
            }
        };

        return $stub;
    }
}
