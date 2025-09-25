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
        error_log("[FILESYSTEMLOADER DEBUG] load() called with: " . (is_object($template) ? get_class($template) : $template));
        
        // Handle TemplateReference objects for backward compatibility
        if (is_object($template) && method_exists($template, '__toString')) {
            $template = (string) $template;
            error_log("[FILESYSTEMLOADER DEBUG] Converted object to string: $template");
        }
        
        if (!$this->locator) {
            error_log("[FILESYSTEMLOADER DEBUG] No locator, returning simple storage");
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
        
        error_log("[FILESYSTEMLOADER DEBUG] Trying to locate: $template");
        // First try direct path (handles namespaced paths like system/theme:views/login.php)
        $file = $this->locator->get($template);
        
        if ($file) {
            error_log("[FILESYSTEMLOADER DEBUG] Found via direct path: $file");
        } else {
            // If not found and no namespace, try with views: prefix
            if (strpos($template, ':') === false) {
                error_log("[FILESYSTEMLOADER DEBUG] Trying with views: prefix");
                $file = $this->locator->get("views:{$template}");
                if ($file) {
                    error_log("[FILESYSTEMLOADER DEBUG] Found via views: prefix: $file");
                }
            }
        }
        
        if (!$file) {
            error_log("[FILESYSTEMLOADER DEBUG] Template not found, returning false");
            return false;
        }
        
        error_log("[FILESYSTEMLOADER DEBUG] Returning storage for file: $file");
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