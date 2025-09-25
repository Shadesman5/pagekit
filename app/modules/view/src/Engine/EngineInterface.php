<?php

namespace Pagekit\View\Engine;

/**
 * Template Engine Interface
 */
interface EngineInterface
{
    /**
     * Renders a template.
     *
     * @param string|array $name       The template name
     * @param array        $parameters An array of parameters to pass to the template
     *
     * @return string The evaluated template as a string
     *
     * @throws \RuntimeException if the template cannot be rendered
     */
    public function render($name, array $parameters = []): string;

    /**
     * Returns true if the template exists.
     *
     * @param string|array $name The template name
     *
     * @return bool true if the template exists, false otherwise
     */
    public function exists($name): bool;

    /**
     * Returns true if this engine supports the given template.
     *
     * @param string|array $name The template name
     *
     * @return bool true if this engine supports the given template, false otherwise
     */
    public function supports($name): bool;
}