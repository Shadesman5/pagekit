<?php

declare(strict_types=1);

namespace Pagekit\View\Asset;

abstract class Asset implements AssetInterface
{
    protected string $name;

    protected ?string $source = null;

    protected ?string $content = null;

    /** @var array<int, string> */
    protected array $dependencies;

    /** @var array<string, mixed> */
    protected array $options;

    /**
     * Constructor.
     *
     * @param array<int, string>   $dependencies
     * @param array<string, mixed> $options
     */
    public function __construct(string $name, string $source, array $dependencies = [], array $options = [])
    {
        $this->name = $name;
        $this->source = $source;
        $this->dependencies = $dependencies;
        $this->options = $options;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getSource(): ?string
    {
        return $this->source;
    }

    /**
     * {@inheritdoc}
     */
    public function getPath(): string
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * {@inheritdoc}
     */
    public function getContent(): string
    {
        return $this->content ?? '';
    }

    /**
     * {@inheritdoc}
     */
    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * {@inheritdoc}
     *
     * @return mixed Genuinely unknown type — asset options are user-defined key-value pairs; any scalar, array, or null is valid.
     */
    public function getOption(string $name): mixed
    {
        return isset($this->options[$name]) ? $this->options[$name] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function setOption(string $name, mixed $value): void
    {
        $this->options[$name] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function dump(array $filters = []): string
    {
        $asset = clone $this;

        foreach ($filters as $filter) {
            if (is_object($filter) && method_exists($filter, 'filterContent')) {
                $filter->filterContent($asset);
            }
        }

        return $asset->getContent();
    }

    /**
     * Sets an option.
     */
    public function offsetSet(mixed $name, mixed $value): void
    {
        $this->options[$name] = $value;
    }

    /**
     * Gets a option value.
     *
     * @return mixed Genuinely unknown type — implements \ArrayAccess; value type depends on what option was stored at offset.
     */
    public function offsetGet(mixed $name): mixed
    {
        return isset($this->options[$name]) ? $this->options[$name] : null;
    }

    /**
     * Returns true if the option exists.
     */
    public function offsetExists(mixed $name): bool
    {
        return isset($this->options[$name]);
    }

    /**
     * Removes an option.
     */
    public function offsetUnset(mixed $name): void
    {
        unset($this->options[$name]);
    }
}
