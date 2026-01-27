<?php

declare(strict_types=1);

namespace Pagekit\Routing\Attribute;

/**
 * Route attribute for defining routes on controllers and methods.
 *
 * Replaces the @Route annotation from Symfony Routing.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Route
{
    private ?string $path = null;
    private ?string $name = null;
    private array $requirements = [];
    private array $options = [];
    private array $defaults = [];
    private ?string $host = null;
    private array $methods = [];
    private array $schemes = [];
    private ?string $condition = null;

    public function __construct(
        string|array|null $path = null,
        ?string $name = null,
        array $requirements = [],
        array $options = [],
        array $defaults = [],
        ?string $host = null,
        array|string $methods = [],
        array|string $schemes = [],
        ?string $condition = null
    ) {
        if (is_array($path)) {
            // Handle associative array syntax for named arguments
            foreach ($path as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }
        } else {
            $this->path = $path;
        }

        if ($name !== null) {
            $this->name = $name;
        }
        if (!empty($requirements)) {
            $this->requirements = $requirements;
        }
        if (!empty($options)) {
            $this->options = $options;
        }
        if (!empty($defaults)) {
            $this->defaults = $defaults;
        }
        if ($host !== null) {
            $this->host = $host;
        }
        if (!empty($methods)) {
            $this->methods = is_array($methods) ? $methods : [$methods];
        }
        if (!empty($schemes)) {
            $this->schemes = is_array($schemes) ? $schemes : [$schemes];
        }
        if ($condition !== null) {
            $this->condition = $condition;
        }
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(?string $path): void
    {
        $this->path = $path;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getRequirements(): array
    {
        return $this->requirements;
    }

    public function setRequirements(array $requirements): void
    {
        $this->requirements = $requirements;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    public function getDefaults(): array
    {
        return $this->defaults;
    }

    public function setDefaults(array $defaults): void
    {
        $this->defaults = $defaults;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function setHost(?string $host): void
    {
        $this->host = $host;
    }

    public function getMethods(): array
    {
        return $this->methods;
    }

    public function setMethods(array $methods): void
    {
        $this->methods = $methods;
    }

    public function getSchemes(): array
    {
        return $this->schemes;
    }

    public function setSchemes(array $schemes): void
    {
        $this->schemes = $schemes;
    }

    public function getCondition(): ?string
    {
        return $this->condition;
    }

    public function setCondition(?string $condition): void
    {
        $this->condition = $condition;
    }
}
