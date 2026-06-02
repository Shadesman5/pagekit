<?php

declare(strict_types=1);

namespace Pagekit\View\Helper;

use Pagekit\View\View;

/**
 * DataHelper - CSP-compliant configuration delivery
 *
 * Uses JSON data container instead of inline scripts for strict CSP compliance.
 * JavaScript reads configuration from data-attribute (no eval, no inline execution).
 */
class DataHelper implements HelperInterface
{
    /** @var array<string, mixed> */
    protected array $data = [];

    /**
     * Encode <, >, ', &, and " for RFC4627-compliant JSON, which may also be embedded into HTML.
     * 15 === JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
     */
    protected int $encodingOptions = 15;

    /**
     * {@inheritdoc}
     */
    public function register(View $view): void
    {
        // Priority 10 = runs BEFORE ScriptHelper (priority 5)
        // JSON data element MUST appear before script tags in HTML
        // so config-loader.js can read it
        //
        // Modules should add data via 'view.data' event, NOT 'view.scripts'
        $view->on('head', function ($event) use ($view) {
            $view->trigger('data', [$this]);
            $event->addResult($this->render());
        }, 10);
    }

    /**
     * Add shortcut.
     *
     * @see add()
     */
    public function __invoke(string $name, mixed $value): void
    {
        $this->add($name, $value);
    }

    /**
     * Gets the data values or a value by name.
     *
     * @return mixed
     */
    public function get(?string $name = null): mixed
    {
        if ($name === null) {
            return $this->data;
        }

        return isset($this->data[$name]) ? $this->data[$name] : null;
    }

    /**
     * Adds a data value to an existing key name.
     */
    public function add(string $name, mixed $value): void
    {
        if (isset($this->data[$name]) && is_array($this->data[$name])) {
            $value = array_replace_recursive($this->data[$name], $value);
        }

        $this->data[$name] = $value;
    }

    /**
     * Renders the data as CSP-compliant JSON container.
     *
     * Output: <script id="pagekit-data" type="application/json">{"data":...}</script>
     *
     * Why type="application/json"?
     * - Browser does NOT execute scripts with type="application/json"
     * - Perfect for strict CSP (no 'unsafe-inline' needed)
     * - JavaScript reads data via JSON.parse(element.textContent)
     * - Industry best practice for passing server data to client
     */
    public function render(): string
    {
        if (empty($this->data)) {
            return '';
        }

        // Build config object with all data
        $config = [
            'data' => $this->data,
        ];

        // Encode as JSON (safe for embedding in HTML)
        $json = json_encode($config, $this->encodingOptions | JSON_UNESCAPED_SLASHES);

        // Output as JSON script tag (CSP-safe, not executed by browser)
        return sprintf("        <script id=\"pagekit-data\" type=\"application/json\">%s</script>\n", $json);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'data';
    }
}
