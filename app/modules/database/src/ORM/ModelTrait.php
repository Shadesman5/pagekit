<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM;

trait ModelTrait
{
    use PropertyTrait;

    /**
     * Pre-computed, serialization-safe mapping data injected by
     * {@see EntityManager::load()} for {@see toArray()}. Null only on instances
     * that were not hydrated through the EntityManager (e.g. a raw `new`), which
     * {@see toArray()} rejects with a {@see \LogicException}.
     *
     * @var array{relations: list<string>, fieldTypes: array<string, string>}|null
     */
    private ?array $_serializationMap = null;

    /**
     * Injects the pre-computed serialization map (see
     * {@see SerializableModelInterface}).
     *
     * @param array{relations: list<string>, fieldTypes: array<string, string>} $map
     */
    public function setSerializationMap(array $map): void
    {
        $this->_serializationMap = $map;
    }

    /**
     * Gets model data as array.
     *
     * Reads the serialization map injected by {@see EntityManager::load()}. Every
     * entity is hydrated through the EntityManager, so a missing map means the
     * instance was built with a raw `new` and must not be serialized.
     *
     * @param  array<string, mixed> $data
     * @param  array<int, string>   $ignore
     * @return array<string, mixed>
     * @throws \LogicException when the serialization map was never injected
     */
    public function toArray(array $data = [], array $ignore = []): array
    {
        if ($this->_serializationMap === null) {
            throw new \LogicException(sprintf(
                '%s has no serialization map; entities must be hydrated through EntityManager::load() before serialization.',
                static::class
            ));
        }

        $relations = $this->_serializationMap['relations'];
        $fieldTypes = $this->_serializationMap['fieldTypes'];

        $relationKeys = array_flip($relations);

        foreach (static::getProperties($this) as $name => $value) {

            if (isset($data[$name]) || isset($relationKeys[$name])) {
                continue;
            }

            // Never leak internal scaffolding: `_`-prefixed properties (e.g. the
            // injected serialization map) and Closure values (e.g. the role loader).
            if (str_starts_with($name, '_') || $value instanceof \Closure) {
                continue;
            }

            switch ($fieldTypes[$name] ?? null) {
                case 'json':
                    $value = $value ?: new \stdClass();

                    break;
                case 'datetime':
                    $value = $value ? $value->format(\DateTime::ATOM) : null;

                    break;
            }

            $data[$name] = $value;
        }

        return array_diff_key($data, array_flip($ignore));
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
