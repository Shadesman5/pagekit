<?php

declare(strict_types=1);

namespace Pagekit\Content\Event;

use Pagekit\Event\Event;

class ContentEvent extends Event
{
    protected string $content;

    /** @var array<string, mixed> */
    protected array $plugins = [];

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(string $name, string $content, array $parameters = [])
    {
        parent::__construct($name, $parameters);

        $this->content = $content;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    public function getPlugin(string $name): mixed
    {
        return $this->plugins[$name] ?? null;
    }

    public function addPlugin(string $name, mixed $callback): void
    {
        $this->plugins[$name] = $callback;
    }
}
