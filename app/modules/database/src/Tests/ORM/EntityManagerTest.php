<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM;

use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Database\ORM\SerializableModelInterface;
use Pagekit\Event\EventDispatcherInterface;
use PHPUnit\Framework\TestCase;

class EntityManagerTest extends TestCase
{
    private EntityManager $manager;
    private Connection $connection;
    private MetadataManager $metadataManager;

    protected function setUp(): void
    {
        // Create mock connection
        $this->connection = $this->createMock(Connection::class);

        // Create mock metadata manager
        $this->metadataManager = $this->createMock(MetadataManager::class);

        // Create mock event dispatcher
        $events = $this->createMock(EventDispatcherInterface::class);

        // Create entity manager
        $this->manager = new EntityManager(
            $this->connection,
            $this->metadataManager,
            $events
        );
    }

    public function testGetConnection(): void
    {
        $this->assertSame($this->connection, $this->manager->getConnection());
    }

    public function testGetMetadataManager(): void
    {
        $this->assertSame($this->metadataManager, $this->manager->getMetadataManager());
    }

    public function testExistsReturnsFalseForNewEntity(): void
    {
        $entity = new \stdClass();

        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getIdentifier')->willReturn('id');
        $metadata->method('getValue')->willReturn(null);

        $this->metadataManager->method('get')->willReturn($metadata);

        $this->assertFalse($this->manager->exists($entity));
    }

    public function testGetMetadata(): void
    {
        $metadata = $this->createMock(Metadata::class);
        $this->metadataManager
            ->expects($this->once())
            ->method('get')
            ->with(\stdClass::class)
            ->willReturn($metadata);

        $result = $this->manager->getMetadata(\stdClass::class);
        $this->assertSame($metadata, $result);
    }

    public function testLoadCreatesEntityWithData(): void
    {
        $metadata = $this->createMock(Metadata::class);
        $entity = new \stdClass();

        $metadata->method('getClass')->willReturn(\stdClass::class);

        $metadata->expects($this->once())
            ->method('newInstance')
            ->willReturn($entity);

        $metadata->expects($this->once())
            ->method('setValues')
            ->with($entity, ['id' => 1, 'name' => 'Test'], true, true);

        $result = $this->manager->load($metadata, ['id' => 1, 'name' => 'Test'], true, true);

        $this->assertSame($entity, $result);
    }

    /**
     * Central hydration type-guard: {@see EntityManager::load()} asserts the
     * freshly created instance against {@see Metadata::getClass()}. This one
     * choke point replaces the ~10 per-call-site `instanceof` LogicExceptions
     * (e.g. the old {@see \Pagekit\User\Auth\UserProvider} non-`User` case) that
     * `Repository<T>`/`QueryBuilder<T>` typing makes statically dead.
     */
    public function testLoadThrowsWhenInstanceClassMismatchesMetadataClass(): void
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getClass')->willReturn(\ArrayObject::class);
        $metadata->method('newInstance')->willReturn(new \stdClass());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('EntityManager::load() expected an instance of ArrayObject, got stdClass.');

        $this->manager->load($metadata, ['id' => 1]);
    }

    /**
     * `load()` injects the pre-computed, serialization-safe map into every
     * hydrated instance that implements {@see SerializableModelInterface}, so
     * `toArray()` never needs a live Metadata lookup.
     */
    public function testLoadInjectsSerializationMapIntoSerializableEntity(): void
    {
        $entity = new class implements SerializableModelInterface {
            /** @var array{relations: list<string>, fieldTypes: array<string, string>}|null */
            public ?array $injectedMap = null;

            /**
             * @param array{relations: list<string>, fieldTypes: array<string, string>} $map
             */
            public function setSerializationMap(array $map): void
            {
                $this->injectedMap = $map;
            }
        };

        $map = ['relations' => ['author'], 'fieldTypes' => ['id' => 'integer']];

        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getClass')->willReturn($entity::class);
        $metadata->method('newInstance')->willReturn($entity);
        $metadata->method('getSerializationMap')->willReturn($map);

        $result = $this->manager->load($metadata, []);

        $this->assertSame($entity, $result);
        $this->assertSame($map, $entity->injectedMap);
    }

    /**
     * A plain entity that does not implement {@see SerializableModelInterface}
     * must never trigger the (potentially expensive) serialization-map build.
     */
    public function testLoadDoesNotFetchSerializationMapForPlainEntity(): void
    {
        $entity = new \stdClass();

        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getClass')->willReturn(\stdClass::class);
        $metadata->method('newInstance')->willReturn($entity);
        $metadata->expects($this->never())->method('getSerializationMap');

        $this->assertSame($entity, $this->manager->load($metadata, []));
    }
}
