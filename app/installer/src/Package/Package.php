<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package;

use Pagekit\Util\Arr;

class Package implements PackageInterface
{
    /** @var array<int|string, mixed> */
    protected array $data;

    /**
     * @param array<int|string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * {@inheritdoc}
     *
     * @return mixed Genuinely unknown type — see PackageInterface::get().
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
        $name = $this->get('name');

        return is_string($name) ? $name : '';
    }

    public function getType(): string
    {
        $type = $this->get('type');

        return is_string($type) ? $type : '';
    }

    /**
     * @return array<int|string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
