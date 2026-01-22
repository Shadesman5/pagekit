<?php

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
        // Priority 1 = runs AFTER ScriptHelper (priority 5)
        // This allows modules to add data in 'view.scripts' event
        // before the JSON is rendered
        $view->on('head', function ($event) use ($view) {
            $view->trigger('data', [$this]);
            $event->addResult($this->render());
        }, 1);
    }

    /**
     * Add shortcut.
     *
     * @see add()
     */
    public function __invoke($name, $value)
    {
        $this->add($name, $value);
    }

    /**
     * Gets the data values or a value by name.
     *
     * @param  null|string $name
     * @return array
     */
    public function get($name = null)
    {
        if ($name === null) {
            return $this->data;
        }

        return isset($this->data[$name]) ? $this->data[$name] : null;
    }

    /**
     * Adds a data value to an existing key name.
     *
     * @param  string $name
     * @param  mixed  $value
     */
    public function add($name, $value): void
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
            'data' => $this->data
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
