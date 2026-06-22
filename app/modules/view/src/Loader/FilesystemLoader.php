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
    public function load(string $template): \Stringable|false
    {
        if (!$this->locator) {
            return new class ($template) implements \Stringable {
                public function __construct(private string $path)
                {
                }

                public function __toString(): string
                {
                    return $this->path;
                }
            };
        }

        $file = $this->locator->get($template);

        if (!$file && strpos($template, ':') === false) {
            $file = $this->locator->get("views:{$template}");
        }

        if (!$file) {
            return false;
        }

        return new class ($file) implements \Stringable {
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
    public function isFresh(string $template, int $time): bool
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
