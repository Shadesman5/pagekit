<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\System;

use Monolog\Handler\TestHandler;
use Pagekit\Application;
use Pagekit\Filesystem\Locator;
use Pagekit\Log\Logger;
use Pagekit\Module\Module;
use Pagekit\System\SystemModule;
use PHPUnit\Framework\TestCase;

/**
 * What an installation renders with when its configuration names no theme.
 *
 * A site that has only been half set up - unpacked, or interrupted between
 * writing its settings - has not chosen one yet. Modules are looked up by name,
 * so that absence is not something the boot can hand to the registry, and a
 * boot that breaks on it takes away the very panel a theme is chosen from. It
 * falls back to the same blank layout an unusable theme falls back to.
 *
 * And it says nothing while doing so: no theme chosen is a state to render, not
 * a fault to report. A log line here would appear on every request of a site
 * that has nothing wrong with it beyond being new.
 */
final class SystemModuleThemeFallbackTest extends TestCase
{
    public function testASiteThatNamesNoThemeStillGetsOneToRenderWith(): void
    {
        $app = $this->boot(new TestHandler());

        $theme = $app->get('theme');

        self::assertInstanceOf(Module::class, $theme);
        self::assertSame('theme-default', $theme->name);
        self::assertSame('theme', $theme->get('type'));
        self::assertSame('views:system/blank.php', $theme->get('layout'));
    }

    public function testNamingNoThemeIsNotReportedAndCostsTheSiteNothingElse(): void
    {
        $log = new TestHandler();

        $app = $this->boot($log);

        self::assertSame([], $log->getRecords());

        // The rest of the boot ran: the enabled extensions are loaded, so the
        // missing theme costs the layout and nothing beyond it.
        self::assertInstanceOf(Module::class, $app->get('module')->get('fixture-healthy'));
    }

    /**
     * Boots the system module against a site configuration with no theme in it,
     * the way the application boots it: the packages on disk are discovered
     * first, then the module runs against the container they were registered in.
     */
    private function boot(TestHandler $log): Application
    {
        $logger = new Logger('log');
        $logger->pushHandler($log);

        $app = new Application();
        $app->set('log', $logger);
        $app->set('locator', new Locator($this->root()));
        // The boot decorates the asset factory, which needs a definition to
        // decorate. Nothing resolves it here, so the decoration never runs.
        $app->set('assets', fn () => new \stdClass());

        $app->get('module')->register([$this->root().'/tests/fixtures/modules/healthy/index.php']);

        $system = new SystemModule([
            'name' => 'system',
            'path' => '',
            'config' => [
                'extensions' => ['fixture-healthy'],
            ],
        ]);

        $system->main($app);

        return $app;
    }

    private function root(): string
    {
        return strtr(dirname(__DIR__, 3), '\\', '/');
    }
}
