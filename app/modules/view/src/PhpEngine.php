<?php

declare(strict_types=1);

namespace Pagekit\View;

/**
 * PHP Template Engine - Independent from Symfony Templating Component
 *
 * This is a minimal implementation to support existing PHP templates
 * while we migrate to Twig for new templates.
 */
class PhpEngine
{
    /** @var array<string, object> */
    protected array $helpers = [];

    /** @var array<string, mixed> */
    protected array $globals = [];

    protected ?string $current = null;

    /** @var array<string, mixed> */
    protected array $parents = [];

    /** @var array<int, mixed> */
    protected array $stack = [];

    protected string $charset = 'UTF-8';

    /** @var array<string, mixed> */
    protected array $cache = [];

    protected mixed $loader;

    protected mixed $parser;

    /**
     * Constructor.
     *
     * @param array<int, object> $helpers
     */
    public function __construct(mixed $parser = null, mixed $loader = null, array $helpers = [])
    {
        // We don't really need these anymore but keep for compatibility
        $this->parser = $parser;
        $this->loader = $loader;

        foreach ($helpers as $helper) {
            $this->addHelper($helper);
        }
    }

    /**
     * Renders a template.
     *
     * @param array<string, mixed> $parameters
     */
    public function render(string|object $name, array $parameters = []): string
    {
        $loaded = $this->load($name);

        if ($loaded === false) {
            throw new \RuntimeException(sprintf('Unable to load template "%s"', $name));
        }

        $result = $this->evaluate($loaded, $parameters);

        return $result;
    }

    /**
     * Returns true if the template exists.
     */
    public function exists(string|object $name): bool
    {
        try {
            // For backward compatibility with Storage objects
            if (is_object($name)) {
                return true;
            }

            // Use the loader if available
            if ($this->loader) {
                $storage = $this->loader->load($name);

                return $storage !== false;
            }

            // Without a loader, check if it's a file
            if (file_exists($name)) {
                return true;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Returns true if this engine supports the given template.
     */
    public function supports(mixed $name): bool
    {
        if (is_string($name)) {
            // Support .php files
            return str_ends_with($name, '.php');
        }

        return true; // Support all for backward compatibility
    }

    /**
     * Loads a template.
     */
    protected function load(string|object $name): object|false
    {
        // For backward compatibility with Storage objects
        if (is_object($name)) {
            return $name;
        }

        // Use the loader if available
        if ($this->loader) {
            $storage = $this->loader->load($name);
            if ($storage !== false) {
                return $storage;
            }

            // Return false if loader couldn't find it
            return false;
        }

        // Without a loader, check if it's a direct file path
        if (file_exists($name)) {
            return new class ($name) {
                public function __construct(private string $template)
                {
                }

                public function getTemplate(): string
                {
                    return $this->template;
                }

                public function __toString(): string
                {
                    return $this->template;
                }
            };
        }

        return false;
    }

    /**
     * Evaluates a template.
     *
     * @param array<string, mixed> $parameters
     */
    protected function evaluate(string|object $template, array $parameters = []): string|false
    {
        // Convert template to string for use as key
        $templateKey = is_object($template) ? spl_object_hash($template) : (string) $template;
        $this->current = $templateKey;
        $this->parents[$templateKey] = null;

        // Add globals to parameters
        $parameters = array_replace($this->globals, $parameters);

        // Add helpers
        foreach ($this->helpers as $name => $helper) {
            $parameters[$name] = $helper;
        }

        // Start output buffering
        ob_start();

        // Extract variables
        extract($parameters, EXTR_SKIP);

        try {
            // Handle different storage types - ONLY file-based templates (security hardening)
            if (is_object($template)) {
                $templatePath = (string) $template;

                // Check if it's a file path
                if (file_exists($templatePath)) {
                    require $templatePath;
                } else {
                    throw new \RuntimeException(sprintf('Template file not found: %s', $templatePath));
                }
            } elseif (is_string($template)) {
                if (file_exists($template)) {
                    require $template;
                } else {
                    throw new \RuntimeException(sprintf('Template file not found: %s', $template));
                }
            }

            return ob_get_clean();

        } catch (\Exception $e) {
            ob_end_clean();

            throw $e;
        }
    }

    /**
     * Adds a global parameter.
     */
    public function addGlobal(string $name, mixed $value): void
    {
        $this->globals[$name] = $value;
    }

    /**
     * Sets a helper.
     */
    public function addHelper(object $helper): void
    {
        $this->helpers[$helper->getName()] = $helper;
    }

    /**
     * Gets a helper.
     */
    public function get(string $name): object
    {
        if (!isset($this->helpers[$name])) {
            throw new \InvalidArgumentException(sprintf('The helper "%s" is not defined.', $name));
        }

        return $this->helpers[$name];
    }

    /**
     * Returns true if the helper is defined.
     */
    public function has(string $name): bool
    {
        return isset($this->helpers[$name]);
    }

    /**
     * Escapes a string by using the current charset.
     */
    public function escape(mixed $value, string $context = 'html'): string
    {
        // Handle null values (PHP 8.1+ compatibility)
        if ($value === null) {
            return '';
        }

        // Convert to string if needed
        $value = (string) $value;

        if ($context === 'html') {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, $this->charset);
        }

        return $value;
    }

    /**
     * Sets the charset to use.
     */
    public function setCharset(string $charset): void
    {
        $this->charset = $charset;
    }

    /**
     * Gets the current charset.
     */
    public function getCharset(): string
    {
        return $this->charset;
    }

    /**
     * Returns the assigned globals.
     *
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        return $this->globals;
    }
}
