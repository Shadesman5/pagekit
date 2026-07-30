<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Installer;

use Pagekit\Application;
use Pagekit\Module\Module;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The installer picks its UI language from the very first request, long before
 * anything is configured. That input is not trustworthy: `?locale[]=de_DE` hands
 * the listener an array, and a client that sends no Accept-Language header hands
 * it null. Neither is usable as an array key, and neither may take the first
 * page of a fresh installation down.
 *
 * The listener is registered inside the installer module's `main`, so the module
 * is booted here the way the framework boots it and the request event is then
 * dispatched through the real dispatcher.
 */
final class InstallerLocaleTest extends TestCase
{
    public function testAShippedLocaleFromTheQueryIsApplied(): void
    {
        $intl = $this->bootInstaller(Request::create('/installer?locale=de_DE'));

        self::assertSame(['de_DE'], $intl->applied);
    }

    public function testALocaleTheInstallationDoesNotShipIsIgnored(): void
    {
        $intl = $this->bootInstaller(Request::create('/installer?locale=xx_XX'));

        self::assertSame([], $intl->applied);
    }

    public function testAnArrayLocaleIsIgnored(): void
    {
        $intl = $this->bootInstaller(Request::create('/installer?locale[]=de_DE'));

        self::assertSame([], $intl->applied, 'a non-string locale must never reach the language lookup');
    }

    public function testTheBrowserPreferenceIsUsedWhenTheQueryCarriesNoLocale(): void
    {
        $request = Request::create('/installer', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'de-DE']);

        self::assertSame(['de_DE'], $this->bootInstaller($request)->applied);
    }

    public function testAMissingBrowserPreferenceIsIgnored(): void
    {
        $intl = $this->bootInstaller(Request::create('/installer'));

        self::assertSame([], $intl->applied, 'a client that states no preference leaves the default language in place');
    }

    /**
     * Boots the installer module around the given request and hands back the
     * intl module its request listener talked to.
     */
    private function bootInstaller(Request $request): object
    {
        $intl = new class () {
            /** @var array<int, string> */
            public array $applied = [];

            /** @return array<string, string> */
            public function getAvailableLanguages(): array
            {
                return ['de_DE' => 'German', 'en_GB' => 'English'];
            }

            public function setLocale(string $locale): void
            {
                $this->applied[] = $locale;
            }
        };

        $app = new Application();
        $app->set('request', $request);
        // Left unresolved: main() only decorates it with the asset version.
        $app->set('assets', fn () => new \stdClass());
        $app->set('routes', new class () {
            /** @param array<string, string> $route */
            public function add(array $route): void
            {
            }
        });
        $app->set('module', new class ($intl) {
            public function __construct(private readonly object $intl)
            {
            }

            public function get(string $name): object
            {
                if ($name !== 'system/intl') {
                    throw new \LogicException(sprintf('The request listener must not resolve module "%s".', $name));
                }

                return $this->intl;
            }
        });

        $definition = require dirname(__DIR__, 3) . '/app/installer/index.php';

        (new Module([
            'name' => 'installer',
            'path' => dirname(__DIR__, 3) . '/app/installer',
            'config' => ['enabled' => true],
            'main' => $definition['main'],
        ]))->main($app);

        $app->get('events')->trigger('request', [$request]);

        return $intl;
    }
}
