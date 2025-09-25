<?php

namespace Pagekit\View;

/**
 * PHP Template Engine - Independent from Symfony Templating Component
 * 
 * This is a minimal implementation to support existing PHP templates
 * while we migrate to Twig for new templates.
 */
class PhpEngine
{
    protected $helpers = [];
    protected $globals = [];
    protected $current;
    protected $parents = [];
    protected $stack = [];
    protected $charset = 'UTF-8';
    protected $cache = [];
    protected $loader;
    protected $parser;
    
    /**
     * Constructor.
     */
    public function __construct($parser = null, $loader = null, array $helpers = [])
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
     */
    public function render($name, array $parameters = []): string
    {
        return $this->evaluate($this->load($name), $parameters);
    }
    
    /**
     * Returns true if the template exists.
     */
    public function exists($name): bool
    {
        try {
            $storage = $this->load($name);
            return $storage !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Returns true if this engine supports the given template.
     */
    public function supports($name): bool
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
    protected function load($name)
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
        }
        
        // Fallback: Simple file storage implementation
        return new class($name) {
            private $template;
            
            public function __construct($template) {
                $this->template = $template;
            }
            
            public function getTemplate() {
                return $this->template;
            }
            
            public function __toString() {
                return $this->template;
            }
        };
    }
    
    /**
     * Evaluates a template.
     */
    protected function evaluate($template, array $parameters = []): string|false
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
            // Handle different storage types
            if (is_object($template)) {
                $templatePath = (string) $template;
                
                // Check if it's a file path
                if (file_exists($templatePath)) {
                    require $templatePath;
                } else {
                    // Treat as string template
                    eval('?>' . $templatePath);
                }
            } elseif (is_string($template)) {
                if (file_exists($template)) {
                    require $template;
                } else {
                    eval('?>' . $template);
                }
            }
            
            return ob_get_clean();
            
        } catch (\Exception $e) {
            ob_end_clean();
            throw $e;
        }
    }
    
    /**
     * Sets a helper.
     */
    public function addHelper($helper): void
    {
        $this->helpers[$helper->getName()] = $helper;
    }
    
    /**
     * Gets a helper.
     */
    public function get($name)
    {
        if (!isset($this->helpers[$name])) {
            throw new \InvalidArgumentException(sprintf('The helper "%s" is not defined.', $name));
        }
        
        return $this->helpers[$name];
    }
    
    /**
     * Returns true if the helper is defined.
     */
    public function has($name): bool
    {
        return isset($this->helpers[$name]);
    }
    
    /**
     * Escapes a string by using the current charset.
     */
    public function escape($value, $context = 'html'): string
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
    public function setCharset($charset): void
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
     * Adds a global variable.
     */
    public function addGlobal($name, $value): void
    {
        $this->globals[$name] = $value;
    }
    
    /**
     * Returns the assigned globals.
     */
    public function getGlobals(): array
    {
        return $this->globals;
    }
}