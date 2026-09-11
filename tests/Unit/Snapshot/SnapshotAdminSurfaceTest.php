<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Pagekit\Application;
use Pagekit\Application\Response as PagekitResponse;
use Pagekit\Application\UrlProvider;
use Pagekit\Auth\Auth;
use Pagekit\Event\Event;
use Pagekit\Installer\Controller\SnapshotController;
use Pagekit\Routing\Event\ConfigureRouteListener;
use Pagekit\Routing\Loader\RoutesLoader;
use Pagekit\Routing\Route;
use Pagekit\Routing\Routes;
use Pagekit\Session\Csrf\Event\CsrfListener;
use Pagekit\Session\Csrf\Exception\CsrfException;
use Pagekit\Session\Csrf\Provider\CsrfProviderInterface;
use Pagekit\User\Event\AccessListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The end of the snapshots that faces the web, as the installation assembles it.
 *
 * Everything a request can reach here is either the site's whole database as it
 * stood or the destruction of one. Listing the snapshots says which packages this
 * site had and when, restoring one replaces every row written since, and purging
 * one is the single point at which a removed package stops being recoverable. So
 * what guards them is not a detail of the controller but the surface itself: the
 * permission each route is behind, the method it answers, and the token an
 * operation needs.
 *
 * None of that is written at the route. It is read off attributes by two
 * listeners while the routes are being loaded, which is why the routes asserted
 * on here are loaded the way a boot loads them - out of the module definition the
 * installation ships, through those same listeners - rather than declared to
 * suit. An attribute dropped from an action is then a failure here instead of an
 * administration page anybody can post to.
 *
 * The page itself is part of that surface. It is a Vue application that reaches
 * these routes by URL and does nothing at all without its bundle, so what the
 * panel links to, what the page posts to and what the build produces are read
 * against each other: a renamed route or an undeclared bundle is a button that
 * answers 404 and a page that renders empty, neither of which the classes below
 * would notice on their own.
 *
 * The removal that fills the store is read the same way. It is the one place an
 * administrator is told that a snapshot is taken at all, and the place they are
 * sent to it from afterwards.
 */
final class SnapshotAdminSurfaceTest extends TestCase
{
    /**
     * The snapshots as the panel lists them.
     */
    private const LISTING = '@system/snapshot';

    /**
     * The way back from a removal, and the two ways a snapshot is destroyed.
     */
    private const RESTORE = '@system/snapshot/restore';

    private const PURGE = '@system/snapshot/purge';

    private const PURGE_EXPIRED = '@system/snapshot/purge-expired';

    /**
     * The token of the session that opened the page. Anything else arriving on a
     * request is a request that page did not make.
     */
    private const TOKEN = 'e4d1f0ab7c25396d';

    // ------------------------------------------------------------------
    // Who gets in
    // ------------------------------------------------------------------

    public function testEveryWayIntoTheSnapshotsIsBehindTheAdminAreaAndThePermissionToManagePackages(): void
    {
        $routes = $this->snapshotRoutes();
        $names = array_keys($routes);

        sort($names);

        // The whole surface, so an action added without the guards below is a
        // failure here rather than a route nobody thought to look at.
        self::assertSame([self::LISTING, self::PURGE, self::PURGE_EXPIRED, self::RESTORE], $names);

        foreach ($routes as $name => $route) {
            // Both, and in that order: the permission is what a session is
            // checked against, and the admin area is what one without a session
            // at all is sent to log in to.
            self::assertSame(
                ['system: manage packages', 'system: access admin area'],
                $route->getDefault('_access'),
                $name,
            );

            // Under the panel rather than beside it, which is what the admin
            // area amounts to as a path - and what the page's own URLs assume.
            self::assertStringStartsWith('/admin/system/snapshot', $route->getPath(), $name);
        }
    }

    // ------------------------------------------------------------------
    // What it takes to change something
    // ------------------------------------------------------------------

    public function testAnOperationThatChangesSomethingIsNotReachableByFollowingALink(): void
    {
        $routes = $this->snapshotRoutes();

        // A restore replaces the database and a purge destroys a snapshot, so
        // neither may be one URL somebody can be handed, embedded in a page or
        // walked into by anything that follows links.
        foreach ([self::RESTORE, self::PURGE, self::PURGE_EXPIRED] as $name) {
            self::assertSame(['POST'], $routes[$name]->getMethods(), $name);
        }

        // The listing is the other way round: it is what the menu entry links
        // to, so nothing may restrict it to a method a link cannot use.
        self::assertSame([], $routes[self::LISTING]->getMethods());
    }

    #[DataProvider('provideOperationsThatChangeSomething')]
    public function testAnOperationThatChangesSomethingNeedsTheTokenOfTheSessionThatAskedForIt(string $name): void
    {
        $route = $this->snapshotRoutes()[$name];
        $listener = new CsrfListener(new ATokenOnlyThisSessionKnows(self::TOKEN));

        // What a form on somebody else's site posts with: nothing, or something
        // that is not this session's. A page that is logged in is enough for
        // such a form to reach the panel, and a purge is not undoable.
        $this->refused($listener, $route, null);
        $this->refused($listener, $route, 'the token of another session');

        // And through with it, so the two refusals above are the token being
        // read rather than the operation being unreachable either way.
        $this->accepted($listener, $route, self::TOKEN);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideOperationsThatChangeSomething(): array
    {
        return [
            'putting a snapshot back' => [self::RESTORE],
            'destroying one' => [self::PURGE],
            'destroying the ones that have expired' => [self::PURGE_EXPIRED],
        ];
    }

    public function testTheListingIsNotBehindATokenALinkCouldNotCarry(): void
    {
        // The page is reached by following an entry in the panel menu, which
        // carries no token. Requiring one there would leave an administration
        // page nobody can open - and there is nothing to protect: the listing
        // changes nothing.
        $this->accepted(
            new CsrfListener(new ATokenOnlyThisSessionKnows(self::TOKEN)),
            $this->snapshotRoutes()[self::LISTING],
            null,
            'GET',
        );
    }

    // ------------------------------------------------------------------
    // The page these routes exist for
    // ------------------------------------------------------------------

    public function testEveryUrlThePageAsksForIsOneOfTheseRoutes(): void
    {
        $paths = array_map(
            static fn (Route $route): string => ltrim($route->getPath(), '/'),
            array_values($this->snapshotRoutes()),
        );

        $urls = self::urlsThePagePostsTo();

        self::assertNotSame([], $urls, 'The page reaches this API by URL');

        foreach ($urls as $url) {
            // The URLs are written into the page, so a route that moved is a
            // button answering 404 - which nothing else here would catch,
            // because both halves are correct on their own.
            self::assertContains($url, $paths, sprintf('The page posts to "%s"', $url));
        }
    }

    public function testTheRemovalPointsAtThePageItLeavesThePackageOn(): void
    {
        // A removal now ends by offering the page the package can be restored
        // from, which is the only place an administrator is told where it went.
        $pattern = <<<'REGEX'
            /\$url\.route\(\s*'([^']+)'\s*\)/
            REGEX;

        preg_match_all($pattern, (string) file_get_contents(self::installerPath().'/app/lib/uninstall.vue'), $matches);

        self::assertContains(
            ltrim($this->snapshotRoutes()[self::LISTING]->getPath(), '/'),
            $matches[1],
            'The removal links to the listing',
        );
    }

    #[DataProvider('providePagesAPackageIsRemovedFrom')]
    public function testTheRemovalIsConfirmedWhereItSaysWhatItDoesRatherThanBeforeThat(string $view): void
    {
        $pattern = <<<'REGEX'
            /<a\b[^>]*@click="uninstall\([^"]*"[^>]*>/
            REGEX;

        $found = preg_match_all(
            $pattern,
            (string) file_get_contents(self::installerPath().'/views/'.$view),
            $triggers,
        );

        self::assertSame(1, $found, 'The page offers one way to remove a package');

        // The staged removal is where an administrator is told that a snapshot
        // is taken, that the package is only put aside and that purging it is
        // what makes any of it final. A confirm of its own in front of that
        // would ask for the removal before saying what it does.
        self::assertStringNotContainsString('v-confirm', $triggers[0][0]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function providePagesAPackageIsRemovedFrom(): array
    {
        return [
            'the extensions page' => ['extensions.php'],
            'the themes page' => ['themes.php'],
        ];
    }

    public function testTheListingOffersARestoreOnlyForASnapshotThatIsAWayBack(): void
    {
        // A snapshot the store does not mark as whole is what an interrupted
        // write or an interrupted removal left behind: a dump of the whole
        // database beside however much of a package tree the interruption got
        // to. The API refuses to replay that, and the page may not offer it
        // either - a row whose only action would be refused is one an
        // administrator is invited to lose data over.
        $pattern = <<<'REGEX'
            /<li\b([^>]*)>\s*<a\b[^>]*confirmRestore\(/
            REGEX;

        $found = preg_match_all(
            $pattern,
            (string) file_get_contents(self::installerPath().'/views/snapshots.php'),
            $actions,
        );

        self::assertSame(1, $found, 'The page offers one way to put a snapshot back');
        self::assertStringContainsString('snapshot.complete', $actions[1][0]);
    }

    public function testTheMenuLeadsToTheListingUnderThePermissionThatOpensIt(): void
    {
        $definition = self::definition();
        $menu = $definition['menu']['system: snapshots'] ?? null;
        $permissions = $definition['permissions'] ?? null;

        self::assertIsArray($menu, 'The panel offers the snapshots of its own accord');
        self::assertIsArray($permissions);

        // Beside the extensions and themes it is the other half of: a snapshot
        // is what a removal on those pages leaves behind.
        self::assertSame('system: system', $menu['parent'] ?? null);

        $listing = $this->snapshotRoutes()[(string) ($menu['url'] ?? '')] ?? null;

        self::assertInstanceOf(Route::class, $listing, 'The entry links to a route that is there');

        // Rendered for the sessions the page itself lets in. An entry shown to
        // more than that is a link into a denial; one shown to fewer hides a
        // page they may open.
        self::assertContains($menu['access'] ?? null, (array) $listing->getDefault('_access'));
        self::assertArrayHasKey($menu['access'], $permissions, 'The permission it names is one this module declares');
    }

    public function testThePageTheListingRendersComesWithTheBundleItRegisters(): void
    {
        $installer = self::installerPath();

        // The prefix the view is named by resolves to the module itself, which
        // is what makes both files below the ones this page is rendered from.
        self::assertSame('', self::definition()['resources']['installer:'] ?? null);

        $view = $installer.'/views/snapshots.php';

        self::assertFileExists($view);

        // The bundle is build output and absent from a fresh checkout, so what
        // is asserted is that the build knows about it: a script the manifest
        // does not name is a file nothing ever writes, which leaves this page an
        // empty element and no way to purge anything.
        $entry = self::declaredEntry('app/installer', self::bundleThePageRegisters($view));

        self::assertFileExists($installer.'/'.$entry, 'The bundle is built from a source that is there');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Runs a request through the token check, and asserts that it is refused.
     */
    private function refused(CsrfListener $listener, Route $route, ?string $token): void
    {
        $thrown = null;

        try {
            $listener->onRequest(new Event('request'), $this->arriving($route, $token));
        } catch (CsrfException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(
            CsrfException::class,
            $thrown,
            sprintf('"%s" refuses a request carrying %s', $route->getName(), $token ?? 'no token'),
        );
    }

    /**
     * Runs a request through the token check, and asserts that it goes through.
     */
    private function accepted(CsrfListener $listener, Route $route, ?string $token, string $method = 'POST'): void
    {
        $thrown = null;

        try {
            $listener->onRequest(new Event('request'), $this->arriving($route, $token, $method));
        } catch (CsrfException $e) {
            $thrown = $e;
        }

        self::assertNull(
            $thrown,
            sprintf('"%s" accepts a request carrying %s', $route->getName(), $token ?? 'no token'),
        );
    }

    /**
     * A request on a matched route, as the listeners see one.
     *
     * The route's defaults are in the attributes because that is where the
     * kernel puts them once a route has matched: what an action requires travels
     * with the request rather than being looked up again.
     */
    private function arriving(Route $route, ?string $token, string $method = 'POST'): Request
    {
        return new Request(
            [],
            $token === null ? [] : ['_csrf' => $token],
            $route->getDefaults(),
            [],
            [],
            ['REQUEST_METHOD' => $method],
        );
    }

    /**
     * The snapshot routes of a booted installation, keyed by name.
     *
     * Loaded out of the module definition through the loader and the two
     * listeners a boot has subscribed, so the paths, the methods, the permission
     * and the token requirement are the ones a request would actually meet.
     *
     * @return array<string, Route>
     */
    private function snapshotRoutes(): array
    {
        $app = new Application();
        $events = $app->get('events');

        $events->subscribe(new AccessListener(
            $this->createMock(Auth::class),
            $this->createMock(UrlProvider::class),
            $this->createMock(PagekitResponse::class),
            new RequestStack(),
        ));
        $events->subscribe(new ConfigureRouteListener());

        $declared = self::definition()['routes'] ?? [];

        self::assertIsArray($declared);

        $routes = new Routes();

        foreach ($declared as $path => $route) {
            $routes->add(array_merge(['path' => $path], (array) $route));
        }

        $loaded = [];

        foreach ((new RoutesLoader($events))->load($routes) as $name => $route) {
            if ($route instanceof Route && $route->getControllerClass()?->getName() === SnapshotController::class) {
                $loaded[$name] = $route;
            }
        }

        return $loaded;
    }

    /**
     * The bundle the page registers for itself, as the entry the build names it
     * by.
     */
    private static function bundleThePageRegisters(string $view): string
    {
        $pattern = <<<'REGEX'
            /\$view->script\(\s*'[^']+'\s*,\s*'installer:app\/bundle\/([A-Za-z0-9._-]+)\.js'/
            REGEX;

        self::assertSame(1, preg_match($pattern, (string) file_get_contents($view), $match), 'The page registers one bundle');

        return $match[1];
    }

    /**
     * What the build produces an entry from, relative to the group's directory.
     *
     * @param string $group the module directory the bundle belongs to
     * @param string $name  the entry as the page registers it
     */
    private static function declaredEntry(string $group, string $name): string
    {
        $manifest = (string) file_get_contents(self::rootPath().'/scripts/bundle-entries.mjs');

        $block = sprintf(
            <<<'REGEX'
                /dir:\s*'%s',\s*(?:global:[^,]*,\s*)?entries:\s*\{(?<entries>[^}]*)\}/
                REGEX,
            preg_quote($group, '/'),
        );

        self::assertSame(1, preg_match($block, $manifest, $declared), sprintf('The build ships a "%s" group', $group));

        $entry = sprintf(
            <<<'REGEX'
                /\b%s:\s*'(?<input>[^']+)'/
                REGEX,
            preg_quote($name, '/'),
        );

        self::assertSame(1, preg_match($entry, $declared['entries'], $input), sprintf('The build declares the "%s" bundle', $name));

        return $input['input'];
    }

    /**
     * The URLs the page reaches this API by.
     *
     * @return array<int, string>
     */
    private static function urlsThePagePostsTo(): array
    {
        $pattern = <<<'REGEX'
            /\$http\.post\(\s*'([^']+)'/
            REGEX;

        preg_match_all($pattern, (string) file_get_contents(self::installerPath().'/app/views/snapshots.js'), $matches);

        return array_values(array_unique($matches[1]));
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

    private static function installerPath(): string
    {
        return self::rootPath().'/app/installer';
    }

    private static function rootPath(): string
    {
        return strtr(dirname(__DIR__, 3), '\\', '/');
    }
}

/**
 * The token check as a session provides it: one token is this session's, and
 * everything else - including nothing at all - is not.
 *
 * Stands in for the session-backed provider because a unit test has no session
 * to start, and what is under test is which requests are checked against a
 * token rather than how the token itself is derived.
 */
final class ATokenOnlyThisSessionKnows implements CsrfProviderInterface
{
    private ?string $received = null;

    public function __construct(private readonly string $token)
    {
    }

    public function generate(): string
    {
        return $this->token;
    }

    public function validate(?string $token = null): bool
    {
        return ($token ?? $this->received) === $this->token;
    }

    public function setToken(?string $token): void
    {
        $this->received = $token;
    }
}
