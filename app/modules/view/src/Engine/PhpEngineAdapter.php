<?php

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
        return $this->engine->render($name, $parameters);
    }
    
    public function exists($name): bool
    {
        return $this->engine->exists($name);
    }
    
    public function supports($name): bool
    {
        return $this->engine->supports($name);
    }
}