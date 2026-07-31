<?php

declare(strict_types=1);

namespace Pagekit\Intl\Tests;

use Pagekit\Application;
use Pagekit\Intl\IntlModule;
use Pagekit\Intl\IntlServiceLocator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

/**
 * The installer offers its language dropdown before anything is configured, and
 * the CLI runs from wherever the operator happens to stand. The list of shipped
 * languages must therefore be scanned below the application path the container
 * knows, never below the process working directory — otherwise the dropdown is
 * empty (or, worse, filled from an unrelated folder) as soon as the two differ.
 */
final class IntlModuleLanguagesTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_intl_languages_' . getmypid() . '_' . uniqid();
        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
        // bootModule() → IntlModule::main() overwrites the suite-default locator;
        // restore it so later __() calls do not fail order-dependently.
        $translator = new Translator('en_US');
        $intl = new IntlModule([
            'name' => 'system/intl',
            'path' => dirname(__DIR__, 2),
            'config' => ['locale' => 'en_US'],
        ]);
        IntlServiceLocator::register(new IntlServiceLocator($translator, $intl));
    }

    public function testAvailableLanguagesAreScannedBelowTheApplicationPath(): void
    {
        $module = $this->bootModule('zz_ZZ', ['de_DE', 'fr', 'en_gb', 'documentation']);

        self::assertSame(
            ['de_DE' => 'Deutsch - Deutschland', 'fr' => 'Français'],
            $module->getAvailableLanguages(),
            'only folders named like a locale count, and a known territory is appended to the language name',
        );
    }

    public function testAvailableLanguagesStayEmptyWhenTheApplicationPathShipsNone(): void
    {
        $module = $this->bootModule('zz_ZY', ['documentation']);

        self::assertSame(
            [],
            $module->getAvailableLanguages(),
            'an application path without locale folders must report none instead of leaking another installation',
        );
    }

    /**
     * Builds an application rooted in a private workspace that ships the given
     * language folders, and binds a module to it.
     *
     * IntlModule memoises parsed language data per relative file path for the
     * whole process, so each test needs its own locale id — otherwise the second
     * one would read the first one's fixture out of a workspace that is gone.
     *
     * @param array<int, string> $languageFolders
     */
    private function bootModule(string $locale, array $languageFolders): IntlModule
    {
        $root = $this->workspace . '/' . $locale;
        $languages = $root . '/app/system/languages';

        foreach (array_unique([...$languageFolders, $locale]) as $folder) {
            mkdir($languages . '/' . $folder, 0755, true);
        }

        file_put_contents($languages . '/' . $locale . '/languages.json', (string) json_encode(['de' => 'Deutsch', 'fr' => 'Français']));
        file_put_contents($languages . '/' . $locale . '/territories.json', (string) json_encode(['DE' => 'Deutschland']));

        $app = new Application();
        $app->set('path', $root);
        $app->set('locator', new class ($root) {
            public function __construct(private readonly string $root)
            {
            }

            public function get(string $file): string|false
            {
                $path = $this->root . '/' . $file;

                return is_file($path) ? $path : false;
            }
        });

        $module = new IntlModule([
            'name' => 'system/intl',
            'path' => $root . '/app/system/modules/intl',
            'config' => ['locale' => $locale],
        ]);
        $module->main($app);

        // main() must register the locator with a lazy translator factory so
        // language scanning (and anything else that only needs IntlModule) can
        // run before the module graph is complete enough to build the translator.
        self::assertSame($module, IntlServiceLocator::getIntl());

        return $module;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
