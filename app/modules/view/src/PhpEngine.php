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
    /** @var array<string, \Pagekit\View\Helper\HelperInterface> */
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

    /**
     * Constructor.
     *
     * @param array<int, \Pagekit\View\Helper\HelperInterface> $helpers
     */
    public function __construct(mixed $loader = null, array $helpers = [])
    {
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
    public function render(string|\Stringable $name, array $parameters = []): string
    {
        $loaded = $this->load($name);

        if ($loaded === false) {
            $label = is_object($name) ? (string) $name : $name;

            throw new \RuntimeException(sprintf('Unable to load template "%s"', $label));
        }

        $result = $this->evaluate($loaded, $parameters);
        if ($result === false) {
            throw new \RuntimeException('Failed to evaluate template.');
        }

        return $result;
    }

    /**
     * Returns true if the template exists.
     */
    public function exists(string|\Stringable $name): bool
    {
        try {
            if (is_object($name)) {
                return true;
            }

            if ($this->loader) {
                $storage = $this->loader->load($name);

                return $storage !== false;
            }

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
    protected function load(string|\Stringable $name): \Stringable|false
    {
        if (is_object($name)) {
            return $name;
        }

        if ($this->loader) {
            $storage = $this->loader->load($name);
            if ($storage !== false) {
                return $storage;
            }

            return false;
        }

        if (file_exists($name)) {
            return new class ($name) implements \Stringable {
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
    protected function evaluate(string|\Stringable $template, array $parameters = []): string|false
    {
        $templateKey = is_object($template) ? spl_object_hash($template) : $template;
        $this->current = $templateKey;
        $this->parents[$templateKey] = null;

        $parameters = array_replace($this->globals, $parameters);

        foreach ($this->helpers as $name => $helper) {
            $parameters[$name] = $helper;
        }

        ob_start();

        extract($parameters, EXTR_SKIP);

        try {
            $templatePath = is_object($template) ? (string) $template : $template;

            if (file_exists($templatePath)) {
                require $templatePath;
            } else {
                throw new \RuntimeException(sprintf('Template file not found: %s', $templatePath));
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
    public function addHelper(\Pagekit\View\Helper\HelperInterface $helper): void
    {
        $this->helpers[$helper->getName()] = $helper;
    }

    /**
     * Gets a helper.
     */
    public function get(string $name): \Pagekit\View\Helper\HelperInterface
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
        // PHP 8.1+ deprecates passing null to non-nullable string params (e.g. htmlspecialchars());
        // guard it. Min PHP is 8.2 — this is forward-compatibility, not old-PHP support.
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
