<?php

declare(strict_types=1);

namespace Pagekit\View\Loader;

use Pagekit\Filesystem\Locator;

/**
 * Filesystem Template Loader - Independent from Symfony
 */
class FilesystemLoader
{
    protected ?Locator $locator;

    public function __construct(?Locator $locator = null)
    {
        $this->locator = $locator;
    }

    /**
     * Loads a template.
     */
    public function load(string|object $template): object|false
    {
        // Handle TemplateReference objects for backward compatibility
        if (is_object($template) && method_exists($template, '__toString')) {
            $template = (string) $template;
        }

        if (!$this->locator) {
            // Return a simple file storage
            return new class ($template) {
                public function __construct(private string $path)
                {
                }

                public function __toString(): string
                {
                    return $this->path;
                }
            };
        }

        // Try to locate the template file
        $file = null;

        // First try direct path (handles namespaced paths like system/theme:views/login.php)
        $file = $this->locator->get($template);

        if (!$file && strpos($template, ':') === false) {
            // If not found and no namespace, try with views: prefix
            $file = $this->locator->get("views:{$template}");
        }

        if (!$file) {
            return false;
        }

        // Return a simple file storage object
        return new class ($file) {
            public function __construct(private string $path)
            {
            }

            public function __toString(): string
            {
                return $this->path;
            }
        };
    }

    /**
     * Returns true if the template is still fresh.
     */
    public function isFresh(string|object $template, int $time): bool
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
