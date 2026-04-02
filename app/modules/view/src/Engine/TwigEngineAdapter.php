<?php

namespace Pagekit\View\Engine;

use Twig\Environment;

/**
 * Adapter to make Twig compatible with our EngineInterface
 */
class TwigEngineAdapter implements EngineInterface
{
    protected Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function render($name, array $parameters = []): string
    {
        // Handle twig templates
        if ($this->supports($name)) {
            return $this->twig->render($this->normalizeName($name), $parameters);
        }

        throw new \RuntimeException(sprintf('Template "%s" is not supported by Twig engine', $name));
    }

    public function exists($name): bool
    {
        try {
            $this->twig->getLoader()->getSourceContext($this->normalizeName($name));

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function supports($name): bool
    {
        // Support .twig files
        if (is_string($name) && str_ends_with($name, '.twig')) {
            return true;
        }

        // Check if template exists in Twig loader
        return $this->exists($name);
    }

    protected function normalizeName($name): string
    {
        // Remove views: prefix if present
        if (str_starts_with($name, 'views:')) {
            $name = substr($name, 6);
        }

        return $name;
    }
}
