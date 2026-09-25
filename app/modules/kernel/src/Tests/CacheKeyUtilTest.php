<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Tests;

use Pagekit\Util\CacheKeyUtil;
use PHPUnit\Framework\TestCase;

/**
 * Cache keys are sanitized in kernel's util namespace. A class name has to
 * survive that, because metadata and login keys are built from one.
 */
final class CacheKeyUtilTest extends TestCase
{
    public function testSanitizeReplacesEveryReservedCharacter(): void
    {
        $this->assertSame('plain.key-1', CacheKeyUtil::sanitize('plain.key-1'));
        $this->assertSame('', CacheKeyUtil::sanitize(''));
        $this->assertSame('a_b_c_d_e_f_g_h_i', CacheKeyUtil::sanitize("a:b/c@d\\e{f}g(h)i"));
    }

    public function testSanitizeTurnsAClassNameIntoACacheKeyFragment(): void
    {
        $this->assertSame(
            'Metadata.12.Pagekit_User_Model_User',
            CacheKeyUtil::sanitize('Metadata.12.Pagekit\\User\\Model\\User'),
        );
    }

    public function testCacheKeyUtilResolvesFromKernelUtil(): void
    {
        $file = (new \ReflectionClass(CacheKeyUtil::class))->getFileName();

        $this->assertIsString($file);
        $this->assertSame(
            $this->root().'/app/modules/kernel/src/Util/CacheKeyUtil.php',
            strtr($file, '\\', '/'),
        );
        $this->assertFalse(class_exists($this->removedCacheKeyUtil()));
        $this->assertFileDoesNotExist($this->root().'/app/system/modules/cache/src/CacheKeyUtil.php');
        $this->assertSame([], $this->references([$this->removedCacheKeyUtil()]));
    }

    private function removedCacheKeyUtil(): string
    {
        return 'Pagekit\\Cache\\'.'CacheKeyUtil';
    }

    /**
     * @param list<string> $needles
     *
     * @return list<string>
     */
    private function references(array $needles): array
    {
        $hits = [];

        foreach ($this->productionRoots() as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = strtr($file->getPathname(), '\\', '/');

                if (preg_match('#/(Tests|vendor|node_modules)/#', $path) === 1) {
                    continue;
                }

                $contents = file_get_contents($path);

                if ($contents === false) {
                    continue;
                }

                foreach ($needles as $needle) {
                    if (str_contains($contents, $needle)) {
                        $hits[] = substr($path, strlen($this->root()) + 1).': '.$needle;
                    }
                }
            }
        }

        sort($hits);

        return $hits;
    }

    /**
     * @return list<string>
     */
    private function productionRoots(): array
    {
        $root = $this->root();

        return [
            $root.'/app/modules',
            $root.'/app/system',
            $root.'/app/installer',
            $root.'/app/package',
            $root.'/app/console',
            $root.'/packages/pagekit',
        ];
    }

    private function root(): string
    {
        return dirname(__DIR__, 5);
    }
}
