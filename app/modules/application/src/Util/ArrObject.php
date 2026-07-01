<?php

declare(strict_types=1);

namespace Pagekit\Util;

// Removed the self-referencing use statement
// use Pagekit\Util\ArrObject;

/**
 * @implements \ArrayAccess<string, mixed>
 */
class ArrObject implements \ArrayAccess, \Countable, \JsonSerializable
{
    /** @var array<int|string, mixed> */
    protected array $values = [];

    /**
     * @param array<int|string, mixed>|mixed $values
     * @param array<int|string, mixed>|mixed $defaults
     */
    public function __construct(mixed $values = [], mixed $defaults = [])
    {
        $this->values = Arr::merge((array) $defaults, (array) $values);
    }

    public function has(?string $key): bool
    {
        return Arr::has($this->values, $key);
    }

    /**
     * @return mixed Genuinely unknown type — the underlying values array may contain any type; the caller is responsible for type-narrowing the result.
     */
    public function get(?string $key, mixed $default = null): mixed
    {
        return Arr::get($this->values, $key, $default);
    }

    public function set(?string $key, mixed $value): self
    {
        Arr::set($this->values, $key, $value);

        return $this;
    }

    /**
     * Removes one or more values.
     *
     * @param array<int, string>|string $keys
     */
    public function remove(array|string $keys): self
    {
        Arr::remove($this->values, $keys);

        return $this;
    }

    public function push(string $key, mixed $value): ArrObject
    {
        $values = $this->get($key);
        $values[] = $value;

        return $this->set($key, $values);
    }

    public function pull(string $key, mixed $value): ArrObject
    {
        $values = $this->get($key);

        Arr::pull($values, $value);

        return $this->set($key, $values);
    }

    /**
     * @param array<int|string, mixed> $values
     */
    public function merge(array $values, bool $replace = false): self
    {
        $this->values = Arr::merge($this->values, $values, $replace);

        return $this;
    }

    /**
     * Extracts config values.
     *
     * @param array<int, string> $keys
     * @return array<int|string, mixed>
     */
    public function extract(array $keys, bool $include = true): array
    {
        return Arr::extract($this->values, $keys, $include);
    }

    public function count(): int
    {
        return count($this->values);
    }

    /**
     * @return list<int|string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    /**
     * @return list<mixed>
     */
    public function values(): array
    {
        return array_values($this->values);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->values;
    }

    public function offsetExists(mixed $key): bool
    {
        return $this->has($key);
    }

    /** @return mixed Genuinely unknown type — implements \ArrayAccess; value type depends on what was stored at offset. */
    public function offsetGet(mixed $key): mixed
    {
        return $this->get($key);
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    public function offsetUnset(mixed $key): void
    {
        $this->remove($key);
    }
}
