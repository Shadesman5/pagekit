<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Theme;

use Pagekit\Application\UrlProvider;
use Pagekit\Util\ArrObject;
use Pagekit\View\Engine\PhpEngineAdapter;
use Pagekit\View\Helper\Helper;
use Pagekit\View\Helper\HelperInterface;
use Pagekit\View\Helper\UrlHelper;
use Pagekit\View\PhpEngine;
use Pagekit\View\View;
use PHPUnit\Framework\TestCase;

/**
 * Covers the two theme templates that render the site logo: the one in the
 * header and the one in the offcanvas panel.
 *
 * A logo is configured as a stored path ("storage/logo.png"), and a browser
 * cannot ask for that - it needs the URL the file is served under, which on a
 * site installed in a subdirectory is not the path itself. Resolving it is the
 * view's url helper, and the templates are the ones that call it: the img
 * builder they hand the result to renders the source it is given. So these
 * tests render the templates and read the source back out of the markup, a call
 * site that passes the stored path straight through leaves a broken logo on
 * every page it is rendered on.
 */
final class ThemeOneLogoTemplateTest extends TestCase
{
    /** The subdirectory the site under test is installed in. */
    private const BASE_PATH = '/pagekit';

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    /**
     * A raster logo, with the contrast variant the theme renders on top of a
     * dark header next to it: both are files of the site, and both are asked
     * for by URL.
     */
    public function testTheHeaderLogoAndItsInverseAreServedFromResolvedUrls(): void
    {
        $html = $this->renderHeaderLogo(
            ['logo' => 'storage/logo.png', 'logo_contrast' => 'storage/logo-inverse.png', 'title' => 'Acme'],
            ['image' => 'storage/logo.png']
        );

        $this->assertSame(
            [self::BASE_PATH . '/storage/logo.png', self::BASE_PATH . '/storage/logo-inverse.png'],
            $this->imageSources($html)
        );
    }

    /**
     * An svg logo takes the other branch of the template - it is rendered for
     * UIkit to fetch and inline - and UIkit fetches exactly the source in the
     * markup, so it needs the resolved URL just as much.
     */
    public function testTheHeaderSvgLogoAndItsInverseAreServedFromResolvedUrls(): void
    {
        $html = $this->renderHeaderLogo(
            ['logo' => 'storage/logo.svg', 'logo_contrast' => 'storage/logo-inverse.svg', 'title' => 'Acme'],
            ['image' => 'storage/logo.svg']
        );

        $this->assertSame(
            [self::BASE_PATH . '/storage/logo.svg', self::BASE_PATH . '/storage/logo-inverse.svg'],
            $this->imageSources($html)
        );
    }

    /**
     * The offcanvas panel has a logo setting of its own, rendered by its own
     * template - the same two branches, the same resolution.
     */
    public function testTheOffcanvasLogoIsServedFromAResolvedUrl(): void
    {
        $html = $this->renderOffcanvas(
            ['logo_offcanvas' => 'storage/logo.png', 'title' => 'Acme'],
            ['image' => 'storage/logo.png']
        );

        $this->assertSame([self::BASE_PATH . '/storage/logo.png'], $this->imageSources($html));
    }

    public function testTheOffcanvasSvgLogoIsServedFromAResolvedUrl(): void
    {
        $html = $this->renderOffcanvas(
            ['logo_offcanvas' => 'storage/logo.svg', 'title' => 'Acme'],
            ['image' => 'storage/logo.svg']
        );

        $this->assertSame([self::BASE_PATH . '/storage/logo.svg'], $this->imageSources($html));
    }

    /**
     * Renders the header logo template.
     *
     * Its raster branch reads the image from the theme configuration and its
     * svg branch from the logo parameter; the tests point both at the same
     * file, so what they pin is the resolution and not which of the two
     * settings a branch reads.
     *
     * @param array<string, mixed> $params theme parameters, as the site settings leave them
     * @param array<string, mixed> $config the theme configuration in scope
     */
    private function renderHeaderLogo(array $params, array $config): string
    {
        return $this->render('header-logo.php', $params, $config);
    }

    /**
     * Renders the offcanvas template, which carries its logo the same way the
     * header one does.
     *
     * @param array<string, mixed> $params theme parameters, as the site settings leave them
     * @param array<string, mixed> $config the theme configuration in scope
     */
    private function renderOffcanvas(array $params, array $config): string
    {
        return $this->render('offcanvas.php', $params, $config);
    }

    /**
     * Renders one of the theme's templates through the php engine, which is
     * what puts the parameters and the view in its scope. Production looks the
     * file up in the theme's views directory through the template locator; the
     * test names it instead.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $config
     */
    private function render(string $template, array $params, array $config): string
    {
        $views = dirname(__DIR__, 3) . '/packages/pagekit/theme-one/views/';

        return (string) $this->view(new ArrObject($params))->render($views . $template, ['config' => $config]);
    }

    /**
     * A view holding what these templates reach for: the globals the view
     * module registers (the parameters and the view itself) and the url helper
     * they resolve their logo with. The menu and the widget positions of the
     * offcanvas panel are empty, leaving the logo as the only thing rendered.
     */
    private function view(ArrObject $params): View
    {
        $view = new View(null, new PhpEngineAdapter(new PhpEngine()));

        $view->addGlobal('params', $params);
        $view->addGlobal('view', $view);
        $view->addHelper(new UrlHelper($this->urlProvider()));
        $view->addHelper($this->emptyRegionHelper('menu'));
        $view->addHelper($this->emptyRegionHelper('position'));

        return $view;
    }

    /**
     * The url provider of a site installed in a subdirectory: it answers a
     * stored path with the path the browser has to ask for. The prefix it adds
     * is what tells a resolved URL apart from the raw setting.
     */
    private function urlProvider(): UrlProvider
    {
        $url = $this->createMock(UrlProvider::class);
        $url->method('get')->willReturnCallback(
            static fn (?string $path = '', array $parameters = [], int|string $referenceType = 0): string
                => self::BASE_PATH . '/' . ltrim((string) $path, '/')
        );

        return $url;
    }

    /**
     * A helper for a page region that holds nothing, so the templates skip it.
     */
    private function emptyRegionHelper(string $name): HelperInterface
    {
        return new class ($name) extends Helper {
            public function __construct(private readonly string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function exists(string $region): bool
            {
                return false;
            }
        };
    }

    /**
     * The sources of the images in the rendered markup, in the order they are
     * rendered in.
     *
     * @return list<string>
     */
    private function imageSources(string $html): array
    {
        preg_match_all('/<img[^>]*\ssrc="([^"]*)"/', $html, $matches);

        return $matches[1];
    }
}
