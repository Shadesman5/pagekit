<?php

declare(strict_types=1);

namespace Pagekit\View\Asset;

/**
 * @extends \ArrayAccess<string, mixed>
 */
interface AssetInterface extends \ArrayAccess
{
    /**
     * Gets the name.
     */
    public function getName(): string;

    /**
     * Gets the source.
     */
    public function getSource(): ?string;

    /**
     * Gets the path.
     */
    public function getPath(): string;

    /**
     * Gets the dependencies.
     *
     * @return array<int, string>
     */
    public function getDependencies(): array;

    /**
     * Gets the content.
     */
    public function getContent(): string;

    /**
     * Sets the content.
     */
    public function setContent(string $content): void;

    /**
     * Gets all options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array;

    /**
     * Gets a option.
     *
     * @return mixed Genuinely unknown type — asset options are user-defined key-value pairs; any scalar, array, or null is valid.
     */
    public function getOption(string $name): mixed;

    /**
     * Sets a option.
     */
    public function setOption(string $name, mixed $value): void;

    /**
     * Gets the unique hash.
     */
    public function hash(string $salt = ''): string;

    /**
     * Applies filters and returns the asset as a string.
     *
     * @param array<int, callable|object> $filters
     */
    public function dump(array $filters = []): string;
}
