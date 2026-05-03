<?php

declare(strict_types=1);

namespace Pagekit\View\Loader;

/**
 * Template Loader Interface
 */
interface LoaderInterface
{
    /**
     * Loads a template.
     *
     * @param string $name The template name
     *
     * @return array|false An array with template data or false if not found
     *                     Array should contain:
     *                     - 'name' => template name
     *                     - 'path' => file path (for file templates)
     *                     - 'content' => template content (for string templates)
     */
    public function load(string $name): array|false;

    /**
     * Returns true if the template is still fresh.
     *
     * @param string $name The template name
     * @param int    $time The last modification time of the cached template
     *
     * @return bool true if the template is still fresh, false otherwise
     */
    public function isFresh(string $name, int $time): bool;
}
