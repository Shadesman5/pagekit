<?php

declare(strict_types=1);

namespace Pagekit\Console\NodeVisitor;

use Symfony\Component\Templating\EngineInterface;

abstract class NodeVisitor
{
    public ?string $file = null;

    /** @var array<string, array<string, mixed>> */
    public array $results = [];

    public EngineInterface $engine;

    public function __construct(EngineInterface $engine)
    {
        $this->engine = $engine;
    }

    public function getEngine(): EngineInterface
    {
        return $this->engine;
    }

    /**
     * Starts traversing an array of files.
     *
     * @param  array<int, string> $files
     * @return array<string, array<string, mixed>>
     */
    abstract public function traverse(array $files): array;

    /**
     * @param  string $name
     */
    protected function loadTemplate($name): string
    {
        return $this->file = $name;
    }
}
