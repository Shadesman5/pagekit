<?php

declare(strict_types=1);

namespace Pagekit\Filesystem\Tests;

use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\Locator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LocatorTest extends TestCase
{
    use FileUtil;

    private Filesystem $file;
    private Locator $locator;

    /** Locator that knows the webroot the build publishes into. */
    private Locator $overlay;

    private string $workspace;
    private string $source;
    private string $published;

    public function setUp(): void
    {
        $this->file = new Filesystem();
        $this->locator = new Locator(__DIR__);

        $this->workspace = strtr($this->getTempDir('locator_'), '\\', '/');
        $this->source = $this->workspace.'/app/system/modules/editor';
        $this->published = $this->workspace.'/public/app/system/modules/editor';

        $this->writeFile($this->workspace.'/public/app/assets/uikit/uikit.js');
        $this->writeFile($this->published.'/app/bundle/editor.js');
        $this->writeFile($this->published.'/assets/icon.svg');
        $this->writeFile($this->source.'/assets/icon.svg');
        $this->writeFile($this->source.'/views/index.php');
        $this->writeFile($this->workspace.'/app/system/config.php');

        $this->overlay = new Locator($this->workspace, $this->workspace.'/public');
        $this->overlay->add('system/editor:', $this->source);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->workspace);
    }

    #[DataProvider('dataGetPaths')]
    public function testGet(string $path, string|false $result, bool $exists): void
    {
        $this->assertSame($exists, $this->file->exists($result));
        $this->assertSame($result, $this->locator->get($path));
    }

    /**
     * @return array<int, array{0: string, 1: string|false, 2: bool}>
     */
    public static function dataGetPaths(): array
    {
        $fixtures = strtr(__DIR__, '\\', '/').'/Fixtures';

        return [
            ['Fixtures', $fixtures, true],
            ['/Fixtures', $fixtures, true],
            ['Fixtures/file1.txt', $fixtures.'/file1.txt', true],
            ['/Fixtures/file1.txt', $fixtures.'/file1.txt', true],
            ['Fixtures/file3.txt', false, false],
            ['/Fixtures/file3.txt', false, false],
        ];
    }

    public function testPathOverride(): void
    {
        $file = basename(__FILE__);

        $this->assertFalse($this->locator->get('Dir/'.$file));

        $this->locator->add('Dir', __DIR__);

        $this->assertSame(strtr(__FILE__, '\\', '/'), $this->locator->get('Dir/'.$file));
    }

    public function testUnprefixedFileResolvesInTheWebroot(): void
    {
        $this->assertSame(
            $this->workspace.'/public/app/assets/uikit/uikit.js',
            $this->overlay->get('app/assets/uikit/uikit.js'),
        );
    }

    public function testUnprefixedFileFallsBackToTheApplicationRoot(): void
    {
        // Files PHP reads itself are never published and must stay resolvable.
        $this->assertSame($this->workspace.'/app/system/config.php', $this->overlay->get('app/system/config.php'));
    }

    public function testRegisteredResourceResolvesToThePublishedCopy(): void
    {
        $this->assertSame(
            $this->published.'/app/bundle/editor.js',
            $this->overlay->get('system/editor:app/bundle/editor.js'),
        );
    }

    public function testPublishedCopyWinsOverTheModuleSource(): void
    {
        $this->assertFileExists($this->source.'/assets/icon.svg');
        $this->assertSame($this->published.'/assets/icon.svg', $this->overlay->get('system/editor:assets/icon.svg'));
    }

    public function testUnpublishedResourceResolvesToTheModuleSource(): void
    {
        // Views are rendered by PHP, so they have no copy in the webroot.
        $this->assertSame($this->source.'/views/index.php', $this->overlay->get('system/editor:views/index.php'));
    }

    public function testWithoutAWebrootOnlyTheSourcesResolve(): void
    {
        $locator = new Locator($this->workspace);
        $locator->add('system/editor:', $this->source);

        $this->assertFalse($locator->get('app/assets/uikit/uikit.js'));
        $this->assertSame($this->source.'/assets/icon.svg', $locator->get('system/editor:assets/icon.svg'));
    }
}
