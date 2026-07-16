<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM;

use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\MetadataManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Metadata::getSerializationMap()} — the lazily-computed,
 * serialization-safe mapping data that {@see \Pagekit\Database\ORM\EntityManager::load()}
 * injects into entities for {@see \Pagekit\Database\ORM\ModelTrait::toArray()}.
 *
 * It must return plain arrays only (relation names + field types), never a live
 * Metadata/Connection reference, so a cached or `serialize()`-d entity that
 * stores the map stays safe.
 */
class MetadataTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'table' => 'test_serializable',
            'fields' => [
                'id' => ['name' => 'id', 'type' => 'integer'],
                'title' => ['name' => 'title', 'type' => 'string'],
                'created' => ['name' => 'created', 'type' => 'datetime'],
            ],
            'relations' => [
                'author' => ['name' => 'author', 'targetEntity' => 'Author'],
                'comments' => ['name' => 'comments', 'targetEntity' => 'Comment'],
            ],
        ];
    }

    public function testSerializationMapExposesRelationNamesAndFieldTypes(): void
    {
        $metadata = new Metadata($this->createMock(MetadataManager::class), \stdClass::class, $this->config());

        $map = $metadata->getSerializationMap();

        $this->assertSame(['author', 'comments'], $map['relations']);
        $this->assertSame(
            ['id' => 'integer', 'title' => 'string', 'created' => 'datetime'],
            $map['fieldTypes']
        );
    }

    public function testSerializationMapIsComputedLazilyThenCached(): void
    {
        $metadata = new Metadata($this->createMock(MetadataManager::class), \stdClass::class, $this->config());

        $property = new \ReflectionProperty(Metadata::class, 'serializationMap');

        $beforeFirstCall = $property->getValue($metadata);
        $this->assertNull($beforeFirstCall, 'the map must not be computed in the constructor');

        $first = $metadata->getSerializationMap();

        $afterFirstCall = $property->getValue($metadata);
        $this->assertSame($first, $afterFirstCall, 'the computed map must be cached on the instance');

        // A second call must return the cached value instead of recomputing it:
        // overwrite the cached property with a sentinel and assert it is returned
        // verbatim (comparing two getSerializationMap() calls would pass on equal
        // arrays even without caching).
        $sentinel = ['relations' => ['sentinel'], 'fieldTypes' => ['flag' => 'boolean']];
        $property->setValue($metadata, $sentinel);

        $this->assertSame($sentinel, $metadata->getSerializationMap(), 'a second call must return the cached map, not recompute it');
    }
}
