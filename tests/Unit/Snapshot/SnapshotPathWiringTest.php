<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Pagekit\Filesystem\Path;
use PHPUnit\Framework\TestCase;

/**
 * Where the snapshots of removed packages are kept is decided once, in the boot
 * configuration every environment reads - the web front controller, the console
 * and the installer all come through that one file.
 *
 * Two of its neighbours would each be a defect rather than a preference. Under
 * the temp or cache directory a snapshot lives until the next cache clear, and
 * the one thing it has to do is outlive one: it is the only copy of a package
 * that is no longer on disk. Under the webroot - storage/, which the public
 * directory links to, included - it is one request away from anybody, and what
 * it holds is a database dump with every password hash on the site in it.
 *
 * The boot file cannot be required to be asked where it points: the last thing
 * it does is boot the application. What it registers is read out of it instead.
 */
final class SnapshotPathWiringTest extends TestCase
{
    /**
     * The keys this reads relationships between. Asserted to be present before
     * anything is concluded from them, so a value that moved out of reach of
     * the reader below cannot pass as a path that is where it belongs.
     */
    private const KEYS = ['path', 'path.public', 'path.storage', 'path.temp', 'path.cache', 'path.snapshots'];

    public function testTheBootGivesTheSnapshotStoreADirectoryOfItsOwn(): void
    {
        $paths = self::bootPaths();

        foreach (self::KEYS as $key) {
            self::assertArrayHasKey($key, $paths, sprintf('The boot configuration registers "%s"', $key));
        }

        // The store resolves an id directly under this directory and never
        // against a working directory, so an installation that is entered
        // through a relative path still has one place for its snapshots.
        self::assertTrue(Path::isAbsolute($paths['path.snapshots']));
        self::assertTrue(self::isInside($paths['path.snapshots'], $paths['path']));
    }

    public function testNoCacheClearCanReachASnapshot(): void
    {
        $paths = self::bootPaths();

        // Clearing the cache empties both of these, and an administrator clears
        // it to get a site working again - which is exactly the situation a
        // restorable snapshot exists for.
        self::assertFalse(self::isInside($paths['path.snapshots'], $paths['path.temp']));
        self::assertFalse(self::isInside($paths['path.snapshots'], $paths['path.cache']));
    }

    public function testNoRequestCanReachASnapshot(): void
    {
        $paths = self::bootPaths();

        // The dump a snapshot holds is the whole database. Below either of
        // these it is served as a file to whoever asks for it by name.
        self::assertFalse(self::isInside($paths['path.snapshots'], $paths['path.public']));
        self::assertFalse(self::isInside($paths['path.snapshots'], $paths['path.storage']));
    }

    public function testTheDirectoryTheBootNamesRefusesRequestsAndStaysOutOfTheRepository(): void
    {
        $snapshots = self::bootPaths()['path.snapshots'];

        self::assertDirectoryExists($snapshots, 'The directory the boot names is the one the installation ships');
        self::assertFileExists($snapshots.'/.htaccess');
        self::assertFileExists($snapshots.'/.gitignore');

        // The directory lies outside the webroot, so nothing in it is reachable
        // to begin with. The denial is for the installation whose document root
        // was pointed at the application instead.
        self::assertStringContainsString('Require all denied', (string) file_get_contents($snapshots.'/.htaccess'));

        // A dump committed to a repository is the same disclosure by another
        // route, so what lands here is ignored - and the two files that arrange
        // both are kept, which takes a negation for each.
        self::assertSame(
            ['*', '!.htaccess', '!.gitignore'],
            self::rules($snapshots.'/.gitignore'),
            'The snapshots are ignored and the guards are kept'
        );
    }

    /**
     * The paths the boot configuration registers.
     *
     * @return array<string, string>
     */
    private static function bootPaths(): array
    {
        $file = self::bootFile();

        // The file works these two out from where it lies: the installation it
        // is in, and the webroot it is the front controller of. Everything else
        // it registers is written relative to the first.
        $paths = ['path' => dirname($file, 2), 'path.public' => dirname($file)];

        $pattern = <<<'REGEX'
            /'(path\.[a-z]+)'\s*=>\s*\$path\s*\.\s*'([^']*)'/
            REGEX;

        preg_match_all($pattern, (string) file_get_contents($file), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $paths[$match[1]] = $paths['path'].$match[2];
        }

        return $paths;
    }

    private static function bootFile(): string
    {
        return strtr(dirname(__DIR__, 3), '\\', '/').'/public/index.php';
    }

    /**
     * Whether a path is the given directory or lies below it. Compared as
     * directories, so a name that merely starts with another one - tmp/system
     * beside tmp/systemd - does not read as being inside it.
     */
    private static function isInside(string $path, string $directory): bool
    {
        return str_starts_with(Path::directory($path), Path::directory($directory));
    }

    /**
     * What an ignore file actually says, without its explanation.
     *
     * @return array<int, string>
     */
    private static function rules(string $file): array
    {
        $lines = array_map('trim', explode("\n", (string) file_get_contents($file)));

        return array_values(array_filter(
            $lines,
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')
        ));
    }
}
