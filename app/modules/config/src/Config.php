<?php

declare(strict_types=1);

namespace Pagekit\Config;

use Pagekit\Util\Arr;

/**
 * @implements \ArrayAccess<string, mixed>
 */
class Config implements \ArrayAccess, \Countable, \JsonSerializable
{
    /** @var array<int|string, mixed> */
    protected array $values = [];

    protected bool $dirty = false;

    /**
     * @param array<int|string, mixed>|mixed $values
     */
    public function __construct(mixed $values = [])
    {
        $this->values = (array) $values;
    }

    public function has(?string $key): bool
    {
        return Arr::has($this->values, $key);
    }

    /**
     * @return mixed Genuinely unknown type — config values may be any scalar, array, or null depending on what was stored.
     */
    public function get(?string $key, mixed $default = null): mixed
    {
        return Arr::get($this->values, $key, $default);
    }

    public function set(?string $key, mixed $value): self
    {
        Arr::set($this->values, $key, $value);

        $this->dirty = true;

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

        $this->dirty = true;

        return $this;
    }

    public function push(string $key, mixed $value): self
    {
        $values = $this->get($key);

        $values[] = $value;

        return $this->set($key, $values);
    }

    public function pull(string $key, mixed $value): self
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
        $this->dirty = true;

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

    /**
     * Checks if the values are modified.
     */
    public function dirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Restores a Config instance from var_export() output.
     *
     * @param array<string, mixed> $state Exported state array
     */
    public static function __set_state(array $state): self
    {
        return new self($state['values'] ?? []);
    }

    /**
     * Dumps the values as php.
     */
    public function dump(): string
    {
        return '<?php return '.var_export(self::toPlainArray($this->values), true).';';
    }

    /**
     * Recursively converts Config objects to plain arrays for var_export().
     *
     * @param array<int|string, mixed> $values
     * @return array<int|string, mixed>
     */
    private static function toPlainArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if ($value instanceof self) {
                $values[$key] = self::toPlainArray($value->values);
            } elseif (is_array($value)) {
                $values[$key] = self::toPlainArray($value);
            }
        }

        return $values;
    }

    public function count(): int
    {
        return count($this->values);
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
