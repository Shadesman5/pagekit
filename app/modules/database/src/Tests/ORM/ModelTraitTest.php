<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM;

use Pagekit\Database\Tests\ORM\Fixtures\SerializableFixtureEntity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see \Pagekit\Database\ORM\ModelTrait::toArray()} consuming the
 * serialization map injected by {@see \Pagekit\Database\ORM\EntityManager::load()}.
 *
 * Covers reading relation names + field types from the injected map (no live
 * Metadata lookup), the json/datetime field conversions, and the two leak-guards
 * that keep internal scaffolding out of the serialized output — `_`-prefixed
 * properties (the injected map itself) and {@see \Closure} values (the
 * per-instance role loader).
 */
class ModelTraitTest extends TestCase
{
    public function testToArrayReadsInjectedMapAndConvertsFieldTypes(): void
    {
        $entity = new SerializableFixtureEntity();
        $entity->id = 5;
        $entity->title = 'Hello';
        $entity->meta = [];
        $entity->created = new \DateTime('2021-06-15T08:30:00+00:00');
        $entity->author = new \stdClass();
        $entity->_secret = 'do-not-leak';
        $entity->loader = static fn (): string => 'x';

        $entity->setSerializationMap([
            'relations' => ['author'],
            'fieldTypes' => ['id' => 'integer', 'title' => 'string', 'meta' => 'json', 'created' => 'datetime'],
        ]);

        $data = $entity->toArray();

        $this->assertSame(5, $data['id']);
        $this->assertSame('Hello', $data['title']);
        $this->assertInstanceOf(\stdClass::class, $data['meta'], 'an empty json field is normalized to an object');
        $this->assertSame('2021-06-15T08:30:00+00:00', $data['created']);
        $this->assertArrayNotHasKey('author', $data, 'relation properties are excluded from toArray()');
    }

    public function testToArrayKeepsPopulatedJsonAndNullDatetime(): void
    {
        $entity = new SerializableFixtureEntity();
        $entity->meta = ['k' => 'v'];
        $entity->created = null;
        $entity->loader = static fn (): string => 'x';

        $entity->setSerializationMap([
            'relations' => [],
            'fieldTypes' => ['meta' => 'json', 'created' => 'datetime'],
        ]);

        $data = $entity->toArray();

        $this->assertSame(['k' => 'v'], $data['meta']);
        $this->assertArrayHasKey('created', $data);
        $this->assertNull($data['created']);
    }

    public function testToArraySkipsUnderscorePrefixedAndClosureProperties(): void
    {
        $entity = new SerializableFixtureEntity();
        $entity->id = 1;
        $entity->title = 'visible';
        $entity->_secret = 'do-not-leak';
        $entity->loader = static fn (): string => 'never-serialized';

        $entity->setSerializationMap([
            'relations' => [],
            'fieldTypes' => ['id' => 'integer', 'title' => 'string'],
        ]);

        $data = $entity->toArray();

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('title', $data);
        $this->assertArrayNotHasKey('_secret', $data, '`_`-prefixed properties must never leak');
        $this->assertArrayNotHasKey('loader', $data, 'Closure values must never leak');
        $this->assertArrayNotHasKey('_serializationMap', $data, 'the injected map must never leak into its own output');
    }

    public function testToArrayHonoursIgnoreList(): void
    {
        $entity = new SerializableFixtureEntity();
        $entity->id = 1;
        $entity->title = 'secret-title';
        $entity->loader = static fn (): string => 'x';

        $entity->setSerializationMap([
            'relations' => [],
            'fieldTypes' => ['id' => 'integer', 'title' => 'string'],
        ]);

        $data = $entity->toArray([], ['title']);

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayNotHasKey('title', $data, 'ignored keys are removed from the output');
    }

    public function testJsonSerializeMatchesToArrayAndHidesInternals(): void
    {
        $entity = new SerializableFixtureEntity();
        $entity->id = 9;
        $entity->title = 'Node';
        $entity->loader = static fn (): string => 'x';

        $entity->setSerializationMap([
            'relations' => [],
            'fieldTypes' => ['id' => 'integer', 'title' => 'string'],
        ]);

        $this->assertSame($entity->toArray(), $entity->jsonSerialize());
        $this->assertArrayNotHasKey('_serializationMap', $entity->jsonSerialize());
        $this->assertArrayNotHasKey('loader', $entity->jsonSerialize());
    }
}
