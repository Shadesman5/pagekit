<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Pagekit\Application;
use Pagekit\Application\Response as PagekitResponse;
use Pagekit\Application\UrlProvider;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Controller\PackageController;
use Pagekit\Installer\Package\PackageFactory;
use Pagekit\Installer\Package\PackageManager;
use Pagekit\Installer\Package\Snapshot\PackageSnapshotter;
use Pagekit\Log\Logger;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the pages a package is removed from say a removal will leave behind.
 *
 * Removing a package is offered as something that can be undone: a snapshot is
 * taken of the files and the database first, and the package is restorable from
 * it until it is purged. That is true of an installation with somewhere to keep
 * snapshots and a database to dump into one, and of no other. The installer runs
 * before there is either, and in an installation assembled without them a removal
 * is exactly as final as it always was.
 *
 * So the promise is not the page's to make, and it is made before anything
 * happens - on a confirm that has to be right about an operation nobody has asked
 * for yet. It is answered per page by the one question the removal itself goes
 * by, whether this installation has a snapshotter at all, and handed to the
 * confirm from there.
 *
 * Both ends of that are asserted here: what the page is told, out of an
 * installation booted the way one that keeps snapshots and one that keeps none
 * are, and that the page reads it back under the name it is told it by - with a
 * page that was told nothing promising nothing.
 */
final class RemovalPromiseTest extends TestCase
{
    use SnapshotDatabase;

    /**
     * What the page is told under, and what it reads back. One name for both
     * ends, so a rename on only one of them is a failure here rather than a
     * confirm that quietly stops promising anything.
     */
    private const PROMISE = 'keepsSnapshots';

    /**
     * The packages the panel lists, one of each kind that can be removed.
     */
    private const PACKAGES = [
        'test-ext' => 'pagekit-extension',
        'test-theme' => 'pagekit-theme',
    ];

    private string $workspace;

    private string $packages;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_removal_promise_'.getmypid().'_'.uniqid();
        $this->packages = $this->workspace.'/packages';

        foreach (self::PACKAGES as $module => $type) {
            $tree = $this->packages.'/pagekit/'.$module;

            mkdir($tree, 0755, true);

            file_put_contents($tree.'/composer.json', (string) json_encode([
                'name' => 'pagekit/'.$module,
                'type' => $type,
                'title' => 'Test Package',
                'version' => '1.4.2',
            ]));
        }
    }

    protected function tearDown(): void
    {
        $this->closeDatabases();
        $this->removeTree($this->workspace);
    }

    #[DataProvider('providePagesAPackageIsRemovedFrom')]
    public function testAPageOfAnInstallationThatKeepsSnapshotsSaysARemovalCanBeUndone(string $type): void
    {
        $data = $this->page($this->installation(keepsSnapshots: true), $type);

        // Said before the removal, because that is when it is acted on: the
        // confirm names the snapshot, and the page it ends on offers the
        // snapshots the package can be restored from.
        self::assertTrue($data[self::PROMISE]);
    }

    #[DataProvider('providePagesAPackageIsRemovedFrom')]
    public function testAPageOfAnInstallationThatKeepsNoneSaysTheRemovalIsFinal(string $type): void
    {
        $data = $this->page($this->installation(keepsSnapshots: false), $type);

        // The removal still goes ahead there - refusing it would leave such an
        // installation unable to remove a package at all - so the difference is
        // the whole of what an administrator has to be told beforehand.
        self::assertFalse($data[self::PROMISE]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function providePagesAPackageIsRemovedFrom(): array
    {
        return [
            'the extensions page' => ['pagekit-extension'],
            'the themes page' => ['pagekit-theme'],
        ];
    }

    public function testThePageReadsThePromiseBackUnderTheNameItIsToldItBy(): void
    {
        $answered = sprintf('/\b%s:\s*false\b/', self::PROMISE);

        // The page and the confirm it opens both start from a removal that
        // cannot be undone. What the server says is merged over that, so a page
        // served by something that answers nothing - an older bundle, a view
        // that was not given the data - promises nothing rather than promising
        // a snapshot nobody took.
        self::assertMatchesRegularExpression(
            $answered,
            $this->source('app/components/package-manager.js'),
            'The page starts from a removal it cannot undo',
        );
        self::assertMatchesRegularExpression(
            $answered,
            $this->source('app/lib/uninstall.vue'),
            'So does the confirm it opens',
        );

        // Handed over rather than looked up: the confirm is opened by the page
        // that was told which installation it is on, and the two of them are
        // separate components.
        self::assertStringContainsString(
            'this.'.self::PROMISE,
            $this->source('app/lib/package.js'),
            'The page hands the answer to the removal it opens',
        );
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * What one of the pages a package is removed from was handed to render
     * itself from.
     *
     * @param string $type the kind of package the page lists
     *
     * @return array<string, mixed>
     */
    private function page(Application $app, string $type): array
    {
        $controller = $this->controller($app);

        $data = $type === 'pagekit-theme'
            ? $controller->themesAction()['$data']
            : $controller->extensionsAction()['$data'];

        self::assertIsArray($data);

        return $data;
    }

    /**
     * The controller as the panel builds it, over the services the booted
     * installation holds.
     *
     * The snapshotter is one of them rather than something handed in here: the
     * constructor is filled from the container by parameter name, so what the
     * page can promise is whatever that installation defined under that name -
     * which is the same thing the removal itself asks for.
     */
    private function controller(Application $app): PackageController
    {
        $manager = $app->get('manager');
        $packages = $app->get('package');
        $url = $app->get('url');
        $snapshotter = null;

        if ($app->has('snapshotter')) {
            $snapshotter = $app->get('snapshotter');

            self::assertInstanceOf(PackageSnapshotter::class, $snapshotter);
        }

        self::assertInstanceOf(PackageManager::class, $manager);
        self::assertInstanceOf(PackageFactory::class, $packages);
        self::assertInstanceOf(UrlProvider::class, $url);

        return new PackageController(
            $manager,
            $packages,
            $this->createMock(ModuleManager::class),
            $url,
            new Request(),
            new PagekitResponse($url),
            $this->workspace,
            false,
            new Logger('test'),
            snapshotter: $snapshotter,
        );
    }

    /**
     * An installation with the packages on disk, booted the way one that can
     * keep a snapshot is - or one that has nowhere to keep one and no database
     * to dump into it.
     *
     * Booted rather than assembled: whether the service exists is decided by the
     * module definition the installation ships, and that decision is what the
     * page ends up promising.
     */
    private function installation(bool $keepsSnapshots): Application
    {
        $app = new Application();

        $app->set('path', $this->workspace);
        $app->set('path.packages', $this->packages);
        $app->set('file', new Filesystem());
        $app->set('log', new Logger('test'));
        $app->set('url', $this->createMock(UrlProvider::class));

        if ($keepsSnapshots) {
            $app->set('path.snapshots', $this->workspace.'/tmp/snapshots');
            $app->set('db', $this->openDatabase());
        }

        $main = self::definition()['main'];

        self::assertInstanceOf(\Closure::class, $main);

        // Not enabled: what registers the packages and the snapshotter runs in
        // every environment the module is loaded in, and the installer's own
        // routes and assets are another concern entirely.
        (new Module([
            'name' => 'installer',
            'path' => self::installerPath(),
            'config' => ['enabled' => false],
            'main' => $main,
        ]))->main($app);

        return $app;
    }

    /**
     * The module as the installation ships it, read out of the definition every
     * boot loads.
     *
     * @return array<string, mixed>
     */
    private static function definition(): array
    {
        return require self::installerPath().'/index.php';
    }

    /**
     * A file of the page, as the panel ships it.
     */
    private function source(string $path): string
    {
        return (string) file_get_contents(self::installerPath().'/'.$path);
    }

    private static function installerPath(): string
    {
        return strtr(dirname(__DIR__, 3), '\\', '/').'/app/installer';
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
