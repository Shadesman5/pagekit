<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Composer\Package\Package;
use Pagekit\Installer\Helper\Composer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * An install is requested with whatever version constraint the extension is
 * listed under, and a constraint like ^1.0 or ~2.3 names a range instead of one
 * version. Only one thing hangs off that difference: a package pinned to an exact
 * version is dropped from the local repository first, so that a copy already
 * cached under that version is fetched again rather than reused. There is nothing
 * to drop for a range, so the package is left out of that list.
 *
 * Being left out of the refresh must not mean being left out of the install - a
 * range is ordinary input, and skipping those packages would break the installs
 * that use them. The decision is written to the log instead, so an installation
 * that ends up with a cached copy can be explained afterwards.
 */
final class PackageInstallConstraintTest extends TestCase
{
    /**
     * The marketplace the helper is pointed at. Credentials in that URL are what a
     * report about an install must never carry along.
     */
    private const MARKETPLACE = 'https://packages:s3cret-token@example.test';

    private string $workspace;

    /**
     * The vendor directory the package registry lives in (path.packages).
     */
    private string $vendor;

    private string $registry;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_install_constraint_'.getmypid().'_'.uniqid();
        $this->vendor = $this->workspace.'/packages';
        $this->registry = $this->vendor.'/packages.php';

        mkdir($this->vendor, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testAConstraintThatIsNotAnExactVersionIsReportedAndInstalledAnyway(): void
    {
        $log = new RecordingLogger();
        $helper = $this->helper($log);

        $helper->install(['pagekit/blog' => '^1.0']);

        self::assertCount(1, $log->records, 'A package that cannot be forced to refresh has to say so once');

        $record = $log->records[0];

        self::assertSame(
            LogLevel::INFO,
            $record['level'],
            'A range constraint is ordinary input, so the note about it is not a fault report',
        );
        self::assertStringContainsString(
            'pagekit/blog',
            $record['message'],
            'The report is worthless unless it names the package it is about',
        );
        self::assertStringContainsString(
            '^1.0',
            $record['message'],
            'The constraint is what made the difference, so it belongs in the report',
        );

        self::assertSame(
            ['pagekit/blog'],
            $helper->updated,
            'The package stays part of the install; only its forced refresh is skipped',
        );
        self::assertSame([], $this->refreshedNames($helper));
        self::assertSame(
            ['pagekit/blog' => '^1.0'],
            $this->installedPackages(),
            'A package that was installed has to appear in the list the next operation reads back',
        );
    }

    public function testAnExactVersionIsForcedToRefreshWithoutAWordInTheLog(): void
    {
        $log = new RecordingLogger();
        $helper = $this->helper($log);

        $helper->install(['pagekit/hello' => '1.2.3']);

        self::assertSame([], $log->records, 'The case the refresh was built for is not worth a line in the log');
        self::assertSame(
            ['pagekit/hello'],
            $this->refreshedNames($helper),
            'An exact version is dropped from the local repository so the install fetches it again',
        );
        self::assertSame(['pagekit/hello' => '1.2.3'], $this->installedPackages());
    }

    public function testEveryPackageOfAMixedInstallIsAccountedFor(): void
    {
        $log = new RecordingLogger();
        $helper = $this->helper($log);

        $helper->install([
            'pagekit/blog' => '^1.0',
            'pagekit/hello' => '1.2.3',
            'pagekit/pages' => '~2.3',
        ]);

        self::assertCount(2, $log->records, 'Reporting one package must not stop the others from being looked at');
        self::assertStringContainsString('pagekit/blog', $log->records[0]['message']);
        self::assertStringContainsString('pagekit/pages', $log->records[1]['message']);

        self::assertSame(
            ['pagekit/hello'],
            $this->refreshedNames($helper),
            'Only the package pinned to one version has a cached copy worth discarding',
        );
        self::assertSame(
            ['pagekit/blog', 'pagekit/hello', 'pagekit/pages'],
            $helper->updated,
            'All three were requested, so all three are part of the install',
        );
        self::assertSame(
            [
                'pagekit/blog' => '^1.0',
                'pagekit/hello' => '1.2.3',
                'pagekit/pages' => '~2.3',
            ],
            $this->installedPackages(),
        );
    }

    /**
     * Where the packages come from is not part of what happened, and the URL they
     * come from is exactly the place an installation keeps a marketplace token.
     */
    public function testTheReportCarriesTheRequestAndNothingElse(): void
    {
        $log = new RecordingLogger();

        $this->helper($log)->install(['pagekit/blog' => '^1.0']);

        self::assertCount(1, $log->records);

        $record = $log->records[0];

        self::assertStringNotContainsString(
            's3cret-token',
            $record['message'],
            'The report is about the request, never about where packages are fetched from',
        );
        self::assertSame([], $record['context'], 'Nothing beyond the package and its constraint is worth recording');
    }

    /**
     * The helper as the package manager builds it: the paths it works in, the
     * marketplace it resolves packages against, and the logger the application
     * holds.
     */
    private function helper(RecordingLogger $log): RecordingComposer
    {
        return new RecordingComposer([
            'path.packages' => $this->vendor,
            'path.artifact' => $this->workspace.'/artifact',
            'system.api' => self::MARKETPLACE,
        ], null, null, $log);
    }

    /**
     * @return array<int, string>
     */
    private function refreshedNames(RecordingComposer $helper): array
    {
        return array_map(static fn (Package $package): string => $package->getName(), $helper->refreshed);
    }

    /**
     * The list of packages as the next operation reads it back off disk, rather
     * than as the helper that wrote it remembers it.
     *
     * @return array<string, string>
     */
    private function installedPackages(): array
    {
        return require $this->registry;
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
            $this->removeTree($path.'/'.$entry);
        }

        rmdir($path);
    }
}

/**
 * An install without the Composer run behind it. That run is what resolves and
 * downloads packages off the marketplace; what it is handed is the set of
 * packages to update and the set to fetch again from scratch.
 */
final class RecordingComposer extends Composer
{
    /** @var array<int, string>|bool */
    public array|bool $updated = false;

    /** @var array<int, Package> */
    public array $refreshed = [];

    /**
     * @param array<int, string>|bool $updates
     * @param array<int, Package>     $refresh
     */
    protected function composerUpdate(array|bool $updates = false, array $refresh = [], bool $packagist = false, bool $preferSource = false): void
    {
        $this->updated = $updates;
        $this->refreshed = $refresh;
    }
}

/**
 * Keeps what it is told, so a test can read it back.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
