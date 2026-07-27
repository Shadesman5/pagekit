<?php

declare(strict_types=1);

namespace Pagekit\Intl;

use Pagekit\Application as App;
use Pagekit\Intl\Loader\ArrayLoader;
use Pagekit\Intl\Loader\MoFileLoader;
use Pagekit\Intl\Loader\PhpFileLoader;
use Pagekit\Intl\Loader\PoFileLoader;
use Pagekit\Module\Module;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

class IntlModule extends Module
{
    protected ?App $app = null;

    /**
     * @return mixed Genuinely unknown type — overrides Module::main(); the return value is not consumed by the framework (inherited contract from ModuleInterface).
     */
    public function main(App $app): mixed
    {
        $this->app = $app;
        // Load translation functions
        require_once __DIR__ . '/../functions.php';
        require_once __DIR__ . '/../functions-pagekit-namespace.php';

        $app->set('translator', function () {

            $translator = new Translator($this->getLocale());
            $translator->addLoader('php', new PhpFileLoader());
            $translator->addLoader('mo', new MoFileLoader());
            $translator->addLoader('po', new PoFileLoader());
            $translator->addLoader('array', new ArrayLoader());

            $this->loadLocale($this->getLocale(), $translator);

            return $translator;
        });

        return null;
    }

    /**
     * Gets the current locale id.
     */
    public function getLocale(): string
    {
        return $this->config('locale');
    }

    /**
     * Sets the current locale id.
     *
     * @param string $locale
     */
    public function setLocale($locale): void
    {
        $this->config['locale'] = $locale;
    }

    /**
     * Gets the current locale tag.
     */
    public function getLocaleTag(): string
    {
        return str_replace('_', '-', $this->getLocale());
    }

    /**
     * Gets the system's available languages.
     *
     * @return array<string, string>
     */
    public function getAvailableLanguages(?string $locale = null): array
    {
        $languages = $this->getLanguages($locale);
        $territories = $this->getTerritories();

        $available = [];
        foreach (Finder::create()->directories()->depth(0)->in('app/system/languages')->name('/^[a-z]{2,3}(_[A-Z]{2})?$/') as $dir) {

            $id = $dir->getFilename();
            @list($lang, $country) = explode('_', $id);

            if (isset($languages[$lang])) {
                $available[$id] = $languages[$lang];

                if (isset($country, $territories[$country])) {
                    $available[$id] .= ' - '.$territories[$country];
                }

            }
        }

        asort($available);

        return $available;
    }

    /**
     * Gets the languages list.
     *
     * @param  string|null $locale
     * @return array<string, string>|null
     */
    public function getLanguages($locale = null): ?array
    {
        return $this->getData('languages', $locale);
    }

    /**
     * Gets the territories list.
     *
     * @param  string|null $locale
     * @return array<string, string>|null
     */
    public function getTerritories($locale = null): ?array
    {
        return $this->getData('territories', $locale);
    }

    /**
     * Gets the continents list.
     *
     * @param  string|null $locale
     * @return array<string, string>
     */
    public function getContinents($locale = null): array
    {
        return $this->getTerritoryContainment(1, $locale);
    }

    /**
     * Gets the subcontinents list.
     *
     * @param  string|null $locale
     * @return array<string, string>
     */
    public function getSubContinents($locale = null): array
    {
        return $this->getTerritoryContainment(2, $locale);
    }

    /**
     * Gets the countries list.
     *
     * @param  string|null $locale
     * @return array<string, string>
     */
    public function getCountries($locale = null): array
    {
        return $this->getTerritoryContainment(3, $locale);
    }

    /**
     * Gets the locales formats data.
     *
     * @param  string|null $locale
     * @return array<string, mixed>|null
     */
    public function getFormats($locale = null): ?array
    {
        return $this->getData('formats', $locale);
    }

    /**
     * Formats a number according to the given style using PHP's intl extension.
     *
     * @param string $style   'decimal'|'currency'|'percent'|'spellout'|'ordinal'|'scientific'
     * @param string $pattern Optional NumberFormatter pattern (e.g. '#,##0.##')
     */
    public function formatNumber(int|float $number, string $style = 'decimal', string $pattern = '', ?string $locale = null): string
    {
        $locale ??= $this->getLocale();

        $styleConstant = match (strtolower($style)) {
            'currency' => \NumberFormatter::CURRENCY,
            'percent' => \NumberFormatter::PERCENT,
            'spellout' => \NumberFormatter::SPELLOUT,
            'ordinal' => \NumberFormatter::ORDINAL,
            'duration' => \NumberFormatter::DURATION,
            'scientific' => \NumberFormatter::SCIENTIFIC,
            default => \NumberFormatter::DECIMAL,
        };

        $formatter = new \NumberFormatter($locale, $styleConstant);

        if ($pattern !== '') {
            $formatter->setPattern($pattern);
        }

        $result = $formatter->format($number);

        return $result !== false ? $result : (string) $number;
    }

    /**
     * Loads language files.
     *
     * @param string              $locale
     * @param TranslatorInterface $translator
     */
    public function loadLocale($locale, ?TranslatorInterface $translator = null): void
    {
        $translator = $translator ?: $this->getApp()->get('translator');

        foreach ($this->getApp()->get('module') as $module) {

            $domains = [];
            $path = $module->get('path').($module->get('languages') ?: '/languages');
            $files = glob("{$path}/{$locale}/*.php") ?: [];

            foreach ($files as $file) {

                $format = pathinfo($file, PATHINFO_EXTENSION);
                $domain = basename($file, '.'.$format);

                if (in_array($domain, $domains)) {
                    continue;
                }

                $domains[] = $domain;

                $translator->addResource($format, $file, $locale, $domain);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    protected function getTerritoryContainment(int $level = 1, ?string $locale = null): array
    {
        static $tree;

        if (null === $tree) {
            $tree = [];
            $data = $this->getGeneric('territoryContainment');

            $build = function ($code, &$tree) use (&$build, $data) {

                $tree[$code] = [];

                if (isset($data[$code])) {
                    foreach ($data[$code] as $node) {
                        $build($node, $tree[$code]);
                    }
                }

            };

            $build('001', $tree);
        }

        $getLevel = function ($node, $depth = 1) use (&$getLevel, $level) {
            if ($level === $depth) {
                return $node;
            }

            $result = [];
            foreach ($node as $child) {
                $result += $getLevel($child, $depth + 1);
            }

            return $result;
        };

        return array_intersect_key($this->getTerritories($locale) ?? [], $getLevel($tree['001']));
    }

    /**
     * @param  string      $name
     * @param  string|null $locale
     * @return array<string, mixed>|null
     */
    protected function getData($name, $locale = null): ?array
    {
        $locale = $locale ?: $this->getLocale();

        if (!($data = $this->parse("app/system/languages/{$locale}/{$name}.json"))) {
            $data = $this->parse("app/system/languages/en_GB/{$name}.json");
        }

        return $data;
    }

    /**
     * @param  string $name
     * @return array<string, mixed>|null
     */
    protected function getGeneric($name): ?array
    {
        return $this->parse("system/intl:data/{$name}.json");
    }

    /**
     * @param  string $file
     * @return array<string, mixed>|null
     */
    protected function parse($file): ?array
    {
        static $data = [];

        if (!isset($data[$file])) {
            $resolved = $this->getApp()->get('locator')->get($file);
            if ($resolved && ($contents = file_get_contents($resolved)) !== false) {
                $data[$file] = json_decode($contents, true);
            } else {
                $data[$file] = null;
            }
        }

        return $data[$file];
    }

    /**
     * Returns the application instance, asserting main() has been called.
     */
    private function getApp(): App
    {
        if ($this->app === null) {
            throw new \LogicException('IntlModule::main() has not been called yet.');
        }

        return $this->app;
    }
}
