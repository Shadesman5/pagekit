<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package;

use Pagekit\Util\Arr;

class Package implements PackageInterface
{
    /** @var array<string, mixed> */
    protected array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value): void
    {
        Arr::set($this->data, $key, $value);
    }

    public function getName(): string
    {
        return $this->get('name');
    }

    public function getType(): string
    {
        return $this->get('type');
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
