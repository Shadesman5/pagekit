<?php

declare(strict_types=1);

namespace Pagekit\View\Asset;

class AssetFactory
{
    /** @var array<string, class-string<AssetInterface>> */
    protected array $types = [
        'file' => 'Pagekit\View\Asset\FileAsset',
        'string' => 'Pagekit\View\Asset\StringAsset',
        'url' => 'Pagekit\View\Asset\UrlAsset',
    ];

    protected string $version;

    /**
     * Set a version number for cache breaking.
     */
    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    /**
     * Returns version number for cache breaking.
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Create an asset instance.
     *
     * @param string|array<int, string>   $dependencies
     * @param string|array<string, mixed> $options
     *
     * @throws \InvalidArgumentException
     */
    public function create(string $name, mixed $source, $dependencies = [], $options = []): AssetInterface
    {
        if (is_string($dependencies)) {
            $dependencies = [$dependencies];
        }

        if (is_string($options)) {
            $options = ['type' => $options];
        }

        if (!isset($options['type'])) {
            $options['type'] = 'file';
        }

        if ($options['type'] === 'file' && !isset($options['version'])) {
            $options['version'] = $this->version;
        }

        if (isset($this->types[$options['type']])) {

            $class = $this->types[$options['type']];

            return new $class($name, $source, $dependencies, $options);
        }

        throw new \InvalidArgumentException('Unable to determine asset type.');
    }

    /**
     * Registers an asset type.
     *
     * @param class-string<AssetInterface> $class
     */
    public function register(string $name, string $class): self
    {
        $this->types[$name] = $class;

        return $this;
    }
}
