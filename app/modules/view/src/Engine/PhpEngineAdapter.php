<?php

declare(strict_types=1);

namespace Pagekit\View\Engine;

use Pagekit\View\PhpEngine;

/**
 * Adapter to make PhpEngine compatible with EngineInterface
 */
class PhpEngineAdapter implements EngineInterface
{
    protected PhpEngine $engine;

    public function __construct(PhpEngine $engine)
    {
        $this->engine = $engine;
    }

    public function render($name, array $parameters = []): string
    {
        if (is_array($name)) {
            $name = $name['name'];
        }

        return $this->engine->render($name, $parameters);
    }

    public function exists($name): bool
    {
        if (is_array($name)) {
            $name = $name['name'];
        }

        return $this->engine->exists($name);
    }

    public function supports($name): bool
    {
        return $this->engine->supports($name);
    }

    /**
     * Get the underlying PhpEngine instance
     */
    public function getEngine(): PhpEngine
    {
        return $this->engine;
    }
}
