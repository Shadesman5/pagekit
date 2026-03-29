<?php

namespace Pagekit\View\Engine;

use Pagekit\View\Loader\LoaderInterface;

/**
 * Custom PHP Template Engine - Independent from Symfony Templating
 *
 * This replaces Symfony's deprecated PhpEngine component
 * Compatible with existing PHP templates
 */
class PhpEngine implements EngineInterface
{
    protected LoaderInterface $loader;
    protected array $helpers = [];
    protected array $globals = [];
    protected ?string $current = null;
    protected array $parents = [];
    protected array $stack = [];
    protected array $charset = ['UTF-8'];
    protected array $cache = [];

    public function __construct(LoaderInterface $loader, array $helpers = [])
    {
        $this->loader = $loader;

        foreach ($helpers as $helper) {
            $this->addHelper($helper);
        }
    }

    /**
     * Renders a template
     */
    public function render($name, array $parameters = []): string
    {
        $storage = $this->load($name);

        if ($storage === false) {
            throw new \InvalidArgumentException(sprintf('The template "%s" does not exist.', $name));
        }

        return $this->evaluate($storage, $parameters);
    }

    /**
     * Returns true if the template exists
     */
    public function exists($name): bool
    {
        try {
            $this->load($name);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Returns true if this engine supports the given template
     */
    public function supports($name): bool
    {
        $template = $this->parseTemplateName($name);

        return 'php' === $template['engine'];
    }

    /**
     * Loads a template
     */
    protected function load($name)
    {
        $template = $this->parseTemplateName($name);

        $key = $template['name'];

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $storage = $this->loader->load($template['name']);

        if ($storage !== false) {
            $this->cache[$key] = $storage;
        }

        return $storage;
    }

    /**
     * Evaluates a template
     */
    protected function evaluate($storage, array $parameters = []): string
    {
        $this->current = $storage['name'] ?? null;
        $this->parents[$this->current] = null;

        // Make parameters available to template
        if (isset($parameters['this'])) {
            throw new \InvalidArgumentException('Invalid parameter (this)');
        }

        $parameters = array_merge($this->globals, $parameters);

        // Add helpers as variables
        foreach ($this->helpers as $name => $helper) {
            $parameters[$name] = $helper;
        }

        // Start output buffering
        ob_start();

        // Extract parameters to local scope
        extract($parameters, EXTR_SKIP);

        try {
            // Include template file - ONLY file-based templates (security hardening)
            if (isset($storage['path']) && file_exists($storage['path'])) {
                require $storage['path'];
            } else {
                throw new \RuntimeException('Invalid storage: path not found or content not supported');
            }

            $content = ob_get_clean();

            // Handle template inheritance
            if ($this->parents[$this->current]) {
                $content = $this->render($this->parents[$this->current], $parameters);
            }

            return $content;

        } catch (\Exception $e) {
            ob_end_clean();

            throw $e;
        }
    }

    /**
     * Extends another template
     */
    public function extend($template): void
    {
        $this->parents[$this->current] = $template;
    }

    /**
     * Starts a new block
     */
    public function block($name, $default = null): void
    {
        $this->stack[] = $name;
        ob_start();
    }

    /**
     * Ends the current block
     */
    public function endblock(): void
    {
        if (!$this->stack) {
            throw new \LogicException('No block started');
        }

        $name = array_pop($this->stack);
        $content = ob_get_clean();

        if (!isset($this->globals['blocks'])) {
            $this->globals['blocks'] = [];
        }

        if (!isset($this->globals['blocks'][$name])) {
            $this->globals['blocks'][$name] = $content;
        }
    }

    /**
     * Outputs a block
     */
    public function output($name, $default = null): string
    {
        return $this->globals['blocks'][$name] ?? $default ?? '';
    }

    /**
     * Sets a global parameter
     */
    public function addGlobal($name, $value): void
    {
        $this->globals[$name] = $value;
    }

    /**
     * Adds a helper
     */
    public function addHelper($helper): void
    {
        $this->helpers[$helper->getName()] = $helper;
    }

    /**
     * Gets a helper
     */
    public function get($name)
    {
        if (!isset($this->helpers[$name])) {
            throw new \InvalidArgumentException(sprintf('The helper "%s" is not defined.', $name));
        }

        return $this->helpers[$name];
    }

    /**
     * Escapes a value for output
     */
    public function escape($value, $strategy = 'html'): string
    {
        if ($strategy === 'html') {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, $this->charset[0]);
        }

        return $value;
    }

    /**
     * Parses a template name
     */
    protected function parseTemplateName($name): array
    {
        if (is_array($name)) {
            return $name;
        }

        // Default to PHP engine
        $engine = 'php';

        // Check for engine hint (e.g., "template.html.php")
        if (preg_match('/\.([^.]+)\.php$/', $name, $matches)) {
            // Keep as php engine
        }

        return [
            'name' => $name,
            'engine' => $engine,
        ];
    }
}
