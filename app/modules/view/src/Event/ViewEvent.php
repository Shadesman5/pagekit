<?php

declare(strict_types=1);

namespace Pagekit\View\Event;

use Pagekit\Event\Event;

class ViewEvent extends Event
{
    protected ?string $template = null;

    protected ?string $result = null;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $parameters
     */
    public function __construct(string $name, ?string $template, array $parameters = [])
    {
        parent::__construct($name, $parameters);

        $this->template = $template;
    }

    public function getTemplate(): ?string
    {
        return $this->template;
    }

    public function setTemplate(?string $template): void
    {
        $this->template = $template;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function setResult(?string $result): void
    {
        $this->result = $result;
    }

    public function addResult(?string $result): void
    {
        $this->result .= $result;
    }

    public function __toString(): string
    {
        return $this->result ?? '';
    }
}
