<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM;

use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\MetadataManager;
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

    public function testGetInstance(): void
    {
        $instance = EntityManager::getInstance();
        $this->assertInstanceOf(EntityManager::class, $instance);
        $this->assertSame($this->manager, $instance);
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

        $metadata->expects($this->once())
            ->method('newInstance')
            ->willReturn($entity);

        $metadata->expects($this->once())
            ->method('setValues')
            ->with($entity, ['id' => 1, 'name' => 'Test'], true, true);

        $result = $this->manager->load($metadata, ['id' => 1, 'name' => 'Test'], true, true);

        $this->assertSame($entity, $result);
    }
}
