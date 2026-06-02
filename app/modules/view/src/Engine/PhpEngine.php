<?php

declare(strict_types=1);

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

    /** @var array<string, object> */
    protected array $helpers = [];

    /** @var array<string, mixed> */
    protected array $globals = [];

    protected ?string $current = null;

    /** @var array<string, mixed> */
    protected array $parents = [];

    /** @var array<int, string> */
    protected array $stack = [];

    /** @var array<int, string> */
    protected array $charset = ['UTF-8'];

    /** @var array<string, mixed> */
    protected array $cache = [];

    /**
     * @param array<int, object> $helpers
     */
    public function __construct(LoaderInterface $loader, array $helpers = [])
    {
        $this->loader = $loader;

        foreach ($helpers as $helper) {
            $this->addHelper($helper);
        }
    }

    /**
     * Renders a template
     *
     * @param string|array{name: string, engine?: string} $name
     * @param array<string, mixed>                        $parameters
     */
    public function render($name, array $parameters = []): string
    {
        $storage = $this->load($name);

        if ($storage === false) {
            throw new \InvalidArgumentException(sprintf('The template "%s" does not exist.', is_array($name) ? $name['name'] : (string) $name));
        }

        return $this->evaluate($storage, $parameters);
    }

    /**
     * Returns true if the template exists
     *
     * @param string|array{name: string, engine?: string} $name
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
     *
     * @param string|array{name: string, engine?: string} $name
     */
    public function supports($name): bool
    {
        $template = $this->parseTemplateName($name);

        return 'php' === $template['engine'];
    }

    /**
     * Loads a template
     *
     * @param string|array{name: string, engine?: string} $name
     */
    protected function load($name): mixed
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
     *
     * @param array<string, mixed>|object $storage
     * @param array<string, mixed>        $parameters
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
    public function extend(string $template): void
    {
        $this->parents[$this->current] = $template;
    }

    /**
     * Starts a new block
     */
    public function block(string $name, mixed $default = null): void
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
    public function output(string $name, ?string $default = null): string
    {
        return $this->globals['blocks'][$name] ?? $default ?? '';
    }

    /**
     * Sets a global parameter
     */
    public function addGlobal(string $name, mixed $value): void
    {
        $this->globals[$name] = $value;
    }

    /**
     * Adds a helper
     */
    public function addHelper(object $helper): void
    {
        $this->helpers[$helper->getName()] = $helper;
    }

    /**
     * Gets a helper
     */
    public function get(string $name): object
    {
        if (!isset($this->helpers[$name])) {
            throw new \InvalidArgumentException(sprintf('The helper "%s" is not defined.', $name));
        }

        return $this->helpers[$name];
    }

    /**
     * Escapes a value for output
     */
    public function escape(mixed $value, string $strategy = 'html'): string
    {
        if ($strategy === 'html') {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, $this->charset[0]);
        }

        return (string) $value;
    }

    /**
     * Parses a template name
     *
     * @param string|array{name: string, engine?: string} $name
     *
     * @return array{name: string, engine: string}
     */
    protected function parseTemplateName($name): array
    {
        if (is_array($name)) {
            return [
                'name' => $name['name'],
                'engine' => $name['engine'] ?? 'php',
            ];
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
