<?php

declare(strict_types=1);

namespace Pagekit\View\Engine;

/**
 * Minimal Engine Interface to replace Symfony's
 */
interface EngineInterface
{
    /**
     * Renders a template.
     */
    public function render($name, array $parameters = []): string;

    /**
     * Returns true if the template exists.
     */
    public function exists($name): bool;

    /**
     * Returns true if this engine supports the given template.
     */
    public function supports($name): bool;
}
