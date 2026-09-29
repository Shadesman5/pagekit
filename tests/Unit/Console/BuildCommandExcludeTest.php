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

    public function testADatabaseFileUnderDataIsLeftOutOfTheRelease(): void
    {
        $patterns = $this->excludes();

        self::assertContains(
            '^data\\/(?!(?:\\.htaccess|\\.gitignore|snapshots\\/\\.htaccess|snapshots\\/\\.gitignore|state\\/\\.htaccess|state\\/\\.gitignore)$)',
            $patterns,
        );
        self::assertContains('(^|\\/)db\\.dump$', $patterns);

        $filter = '/'.implode('|', $patterns).'/i';

        self::assertSame(1, preg_match($filter, 'data/pagekit.db'));
        self::assertSame(1, preg_match($filter, 'data/other.db'));
        self::assertSame(1, preg_match($filter, 'data/pagekit.db-wal'));
        self::assertSame(1, preg_match($filter, 'data/pagekit.db-shm'));
        self::assertSame(1, preg_match($filter, 'data/config.php'));
        self::assertSame(1, preg_match($filter, 'data/snapshots/db.dump'));
        self::assertSame(1, preg_match($filter, 'data/snapshots/20260101-blog-deadbeef/db.dump'));
        self::assertSame(1, preg_match($filter, 'data/snapshots/20260101-blog-deadbeef/metadata.json'));
        self::assertSame(1, preg_match($filter, 'data/state/failures.php'));
        self::assertSame(1, preg_match($filter, 'db.dump'));
        self::assertSame(1, preg_match($filter, 'packages/pagekit/blog/db.dump'));
        self::assertSame(0, preg_match($filter, 'nested/data/pagekit.db'));
        self::assertSame(0, preg_match($filter, 'data/.htaccess'));
        self::assertSame(0, preg_match($filter, 'data/.gitignore'));
        self::assertSame(0, preg_match($filter, 'data/snapshots/.htaccess'));
        self::assertSame(0, preg_match($filter, 'data/snapshots/.gitignore'));
        self::assertSame(0, preg_match($filter, 'data/state/.htaccess'));
        self::assertSame(0, preg_match($filter, 'data/state/.gitignore'));

        $guards = (new \ReflectionClassConstant(BuildCommand::class, 'DATA_GUARDS'))->getValue();

        self::assertSame([
            'data/.htaccess',
            'data/.gitignore',
            'data/snapshots/.htaccess',
            'data/snapshots/.gitignore',
            'data/state/.htaccess',
            'data/state/.gitignore',
        ], $guards);

        foreach ($guards as $guard) {
            self::assertSame(0, preg_match($filter, $guard));
        }

        $source = file_get_contents(dirname(__DIR__, 3).'/app/console/src/Commands/BuildCommand.php');

        self::assertIsString($source);
        self::assertStringContainsString('foreach (self::DATA_GUARDS as $guard)', $source);
        self::assertStringContainsString('addFile("{$path}/{$guard}", $guard)', $source);
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
