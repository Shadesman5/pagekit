<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Console;

use Pagekit\Application;
use Pagekit\Console\Commands\BuildCommand;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A release omits dependency junk under the root vendor directory.
 */
final class BuildCommandExcludeTest extends TestCase
{
    public function testJunkUnderTheRootVendorDirectoryIsLeftOutOfTheRelease(): void
    {
        $patterns = $this->excludes();

        self::assertContains('^vendor\\/lusitanian\\/oauth\\/examples', $patterns);
        self::assertContains('^vendor\\/maximebf\\/debugbar\\/src\\/DebugBar\\/Resources', $patterns);
        self::assertContains('^vendor\\/nickic\\/php-parser\\/(grammar|test_old)', $patterns);
        self::assertContains('^vendor\\/(phpdocumentor|phpspec|sebastian|symfony\\/yaml)', $patterns);
        self::assertContains(
            '^vendor\\/[^\\/]+\\/[^\\/]+\\/(build|docs?|tests?|changelog|phpunit|upgrade?)',
            $patterns,
        );

        foreach ($patterns as $pattern) {
            self::assertStringNotContainsString('app\\/vendor', $pattern);
            self::assertStringNotContainsString('app/vendor', $pattern);
        }

        $vendorPatterns = array_values(array_filter(
            $patterns,
            static fn (string $pattern): bool => str_contains($pattern, 'vendor'),
        ));

        self::assertNotEmpty($vendorPatterns);

        foreach ($vendorPatterns as $pattern) {
            self::assertStringStartsWith('^vendor\\/', $pattern);
        }

        // execute() builds this expression beside the node build, which this test does not run.
        $source = file_get_contents(dirname(__DIR__, 3).'/app/console/src/Commands/BuildCommand.php');

        self::assertIsString($source);
        self::assertStringContainsString("\$filter = '/' . implode('|', \$this->excludes) . '/i';", $source);

        $filter = '/'.implode('|', $patterns).'/i';

        self::assertSame(1, preg_match($filter, 'vendor/acme/widget/tests/Foo.php'));
        self::assertSame(0, preg_match($filter, 'vendor/acme/widget/src/Foo.php'));
        self::assertSame(0, preg_match($filter, 'app/vendor/acme/widget/tests/Foo.php'));
    }

    /**
     * @return list<string>
     */
    private function excludes(): array
    {
        $property = new ReflectionProperty(BuildCommand::class, 'excludes');
        $patterns = $property->getValue(new BuildCommand(new Application()));

        self::assertIsArray($patterns);

        $values = [];

        foreach ($patterns as $pattern) {
            self::assertIsString($pattern);
            $values[] = $pattern;
        }

        return $values;
    }
}
