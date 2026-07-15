<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM;

use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityEvent;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Event\Event;
use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Event\EventInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see EntityEvent} and its emission from
 * {@see EntityManager::trigger()}.
 *
 * The event carries the emitting EntityManager so lifecycle handlers can reach
 * queries/persistence through DI instead of the model statics. It still extends
 * {@see Event}, so external `model.*` subscribers that type-hint
 * {@see EventInterface} keep working unchanged.
 */
class EntityEventTest extends TestCase
{
    public function testCarriesTheEmittingEntityManager(): void
    {
        $manager = $this->createMock(EntityManager::class);

        $event = new EntityEvent('user.saving', $manager);

        $this->assertSame($manager, $event->getEntityManager());
        $this->assertSame('user.saving', $event->getName());
    }

    public function testIsAnEventSoExternalSubscribersKeepWorking(): void
    {
        $event = new EntityEvent('node.saved', $this->createMock(EntityManager::class));

        // Assert the contract via the runtime class graph: a direct instanceof
        // would be statically redundant (EntityEvent extends Event), but external
        // `model.*` subscribers rely on exactly this parent/interface chain.
        $this->assertContains(Event::class, class_parents($event) ?: []);
        $this->assertContains(EventInterface::class, class_implements($event) ?: []);
    }

    /**
     * {@see EntityManager::trigger()} must dispatch an {@see EntityEvent} whose
     * name is the metadata event prefix joined to the lifecycle name, carrying
     * the emitting manager, and forward the lifecycle arguments untouched.
     */
    public function testTriggerDispatchesEntityEventCarryingTheManager(): void
    {
        $events = $this->createMock(EventDispatcherInterface::class);

        $manager = new EntityManager(
            $this->createMock(Connection::class),
            $this->createMock(MetadataManager::class),
            $events
        );

        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getEventPrefix')->willReturn('user');

        $entity = new \stdClass();
        $arguments = [$entity, ['name' => 'x']];

        $captured = null;
        $events->expects($this->once())
            ->method('trigger')
            ->with(
                $this->callback(function (mixed $event) use (&$captured): bool {
                    $captured = $event;

                    return $event instanceof EntityEvent;
                }),
                $arguments
            )
            ->willReturnArgument(0);

        $manager->trigger('saving', $metadata, $arguments);

        $this->assertInstanceOf(EntityEvent::class, $captured);
        $this->assertSame('user.saving', $captured->getName());
        $this->assertSame($manager, $captured->getEntityManager());
    }
}
