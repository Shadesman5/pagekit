<?php

declare(strict_types=1);

namespace Pagekit\View\Helper;

use Pagekit\View\View;

/**
 * @implements \IteratorAggregate<string, string>
 */
class MapHelper implements HelperInterface, \IteratorAggregate
{
    /** @var array<string, string> */
    protected array $map = [];

    /**
     * {@inheritdoc}
     */
    public function register(View $view): void
    {
        $view->on('render', function ($event) {
            if ($this->has($name = $event->getTemplate())) {
                $event->setTemplate($this->get($name));
            }
        }, 10);
    }

    /**
     * Add shortcut.
     *
     * @see add()
     *
     * @param string|array<string, string> $name
     */
    public function __invoke($name, ?string $path = null): void
    {
        $this->add($name, $path);
    }

    /**
     * Gets a template.
     */
    public function get(string $name): ?string
    {
        return isset($this->map[$name]) ? $this->map[$name] : null;
    }

    /**
     * Adds a template.
     *
     * @param string|array<string, string> $name
     */
    public function add($name, ?string $path = null): void
    {
        if (is_string($name) && $path) {
            $this->map[$name] = $path;
        } elseif (is_array($name)) {
            foreach ($name as $key => $path) {
                $this->map[$key] = $path;
            }
        }
    }

    /**
     * Checks if the template exists.
     */
    public function has(string $name): bool
    {
        return isset($this->map[$name]);
    }

    /**
     * Implements the IteratorAggregate.
     *
     * @return \ArrayIterator<string, string>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->map);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'map';
    }
}
