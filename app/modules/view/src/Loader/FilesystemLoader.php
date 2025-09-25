<?php

namespace Pagekit\View\Loader;

use Pagekit\Filesystem\Locator;

/**
 * Filesystem Template Loader - Independent from Symfony
 */
class FilesystemLoader
{
    protected ?Locator $locator;

    /**
     * Constructor.
     *
     * @param Locator|null $locator
     */
    public function __construct(?Locator $locator = null)
    {
        $this->locator = $locator;
    }

    /**
     * Loads a template.
     */
    public function load($template)
    {
        // Handle TemplateReference objects for backward compatibility
        if (is_object($template) && method_exists($template, '__toString')) {
            $template = (string) $template;
        }
        
        if (!$this->locator) {
            // Return a simple file storage
            return new class($template) {
                private $path;
                
                public function __construct($path) {
                    $this->path = $path;
                }
                
                public function __toString() {
                    return $this->path;
                }
            };
        }
        
        // Try to locate the template file
        $file = null;
        
        if (!strpos($template, ':')) {
            // Try views: prefix first
            $file = $this->locator->get("views:{$template}");
        }
        
        if (!$file) {
            // Try direct path
            $file = $this->locator->get($template);
        }
        
        if (!$file) {
            return false;
        }
        
        // Return a simple file storage object
        return new class($file) {
            private $path;
            
            public function __construct($path) {
                $this->path = $path;
            }
            
            public function __toString() {
                return $this->path;
            }
        };
    }

    /**
     * Returns true if the template is still fresh.
     */
    public function isFresh($template, $time): bool
    {
        $storage = $this->load($template);
        
        if ($storage === false) {
            return false;
        }
        
        $path = (string) $storage;
        
        if (!is_readable($path)) {
            return false;
        }
        
        return filemtime($path) < $time;
    }
}