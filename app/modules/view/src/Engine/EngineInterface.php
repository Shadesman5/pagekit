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
     *
     * @param string|array{name: string, engine?: string} $name
     * @param array<string, mixed>                        $parameters
     */
    public function render($name, array $parameters = []): string;

    /**
     * Returns true if the template exists.
     *
     * @param string|array{name: string, engine?: string} $name
     */
    public function exists($name): bool;

    /**
     * Returns true if this engine supports the given template.
     *
     * @param string|array{name: string, engine?: string} $name
     */
    public function supports($name): bool;
}
