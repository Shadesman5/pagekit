<?php

namespace Pagekit\View\Engine;

/**
 * Delegating Engine - delegates to multiple engines (PHP and Twig)
 */
class DelegatingEngine implements EngineInterface
{
    protected array $engines = [];

    /**
     * Adds an engine.
     */
    public function addEngine(EngineInterface $engine): void
    {
        $this->engines[] = $engine;
    }

    /**
     * {@inheritdoc}
     */
    public function render($name, array $parameters = []): string
    {
        foreach ($this->engines as $engine) {
            if ($engine->supports($name)) {
                return $engine->render($name, $parameters);
            }
        }

        throw new \RuntimeException(sprintf('No engine found for template "%s"', $name));
    }

    /**
     * {@inheritdoc}
     */
    public function exists($name): bool
    {
        foreach ($this->engines as $engine) {
            if ($engine->exists($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function supports($name): bool
    {
        foreach ($this->engines as $engine) {
            if ($engine->supports($name)) {
                return true;
            }
        }

        return false;
    }
}
