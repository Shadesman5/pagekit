<?php

namespace Pagekit\View\Loader;

use Pagekit\Filesystem\Locator;

/**
 * Filesystem Template Loader - Independent from Symfony
 */
class FilesystemLoader implements LoaderInterface
{
    protected Locator $locator;

    /**
     * Constructor.
     *
     * @param Locator $locator
     */
    public function __construct(Locator $locator)
    {
        $this->locator = $locator;
    }

    /**
     * {@inheritdoc}
     */
    public function load(string $name): array|false
    {
        $template = is_string($name) ? $name : ($name['name'] ?? '');
        
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
        
        if (!$file || !file_exists($file)) {
            return false;
        }
        
        return [
            'name' => $template,
            'path' => $file,
            'content' => null // We use path, not content
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function isFresh(string $name, int $time): bool
    {
        $storage = $this->load($name);
        
        if ($storage === false || !isset($storage['path'])) {
            return false;
        }
        
        if (!is_readable($storage['path'])) {
            return false;
        }
        
        return filemtime($storage['path']) < $time;
    }
}