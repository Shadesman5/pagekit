<?php

declare(strict_types=1);

namespace Pagekit\Routing\Tests;

use Pagekit\Routing\Attribute\Access;
use Pagekit\System\Controller\AdminController;
use Pagekit\User\Controller\UserController;
use PHPUnit\Framework\TestCase;

/**
 * Controller access attributes resolve to {@see Access}, and the user-module class is gone.
 */
final class AccessAttributeOwnershipTest extends TestCase
{
    public function testControllerAttributesResolveToTheRoutingAccessAttribute(): void
    {
        $classAttribute = $this->oneAccess((new \ReflectionClass(UserController::class))->getAttributes(Access::class));
        $this->assertTrue($classAttribute->getAdmin());
        $this->assertNull($classAttribute->getExpression());

        $methodAttribute = $this->oneAccess((new \ReflectionMethod(UserController::class, 'indexAction'))->getAttributes(Access::class));
        $this->assertSame('user: manage users', $methodAttribute->getExpression());
        $this->assertNull($methodAttribute->getAdmin());

        $admin = $this->oneAccess((new \ReflectionMethod(AdminController::class, 'indexAction'))->getAttributes(Access::class));
        $this->assertTrue($admin->getAdmin());
        $this->assertNull($admin->getExpression());

        $file = (new \ReflectionClass(Access::class))->getFileName();

        $this->assertIsString($file);
        $this->assertSame(
            $this->root().'/app/modules/routing/src/Attribute/Access.php',
            strtr($file, '\\', '/'),
        );
    }

    public function testTheUserModuleAccessAttributeIsGone(): void
    {
        $this->assertFalse(class_exists($this->removedAccess()));
        $this->assertFileDoesNotExist($this->root().'/app/system/modules/user/src/Attribute/Access.php');
        $this->assertSame([], $this->references([$this->removedAccess()]));

        foreach ([
            'packages/pagekit/blog/src/Controller/BlogController.php',
            'packages/pagekit/blog/src/Controller/CommentApiController.php',
            'packages/pagekit/blog/src/Controller/PostApiController.php',
        ] as $relative) {
            $source = file_get_contents($this->root().'/'.$relative);

            $this->assertIsString($source, $relative);
            $this->assertStringContainsString('Pagekit\\Routing\\Attribute\\Access', $source, $relative);
        }
    }

    /**
     * @param list<\ReflectionAttribute<Access>> $attributes
     */
    private function oneAccess(array $attributes): Access
    {
        $this->assertCount(1, $attributes);
        $attribute = $attributes[0];
        $this->assertSame(Access::class, $attribute->getName());

        return $attribute->newInstance();
    }

    private function removedAccess(): string
    {
        return 'Pagekit\\User\\Attribute\\'.'Access';
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
