<?php

declare(strict_types=1);

namespace Pagekit\Filesystem\Tests;

use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\Locator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LocatorTest extends TestCase
{
    protected ?Filesystem $file = null;
    protected ?Locator $locator = null;

    public function setUp(): void
    {
        $this->file = new Filesystem();
        $this->locator = new Locator(__DIR__);
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
}
