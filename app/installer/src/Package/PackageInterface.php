<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package;

interface PackageInterface extends \JsonSerializable
{
    /**
     * Gets a package value.
     *
     * @return mixed Genuinely unknown type — package manifest values may be any scalar, array, or null depending on the composer.json/package.php key.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Sets a package value.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Gets the name.
     */
    public function getName(): string;

    /**
     * Gets the type.
     */
    public function getType(): string;
}
