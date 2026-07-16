<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM;

use Pagekit\Event\Event;

/**
 * Event emitted by {@see EntityManager::trigger()} for every entity lifecycle
 * hook (init/saving/saved/creating/created/updating/updated/deleting/deleted).
 *
 * It carries the emitting {@see EntityManager} so lifecycle handlers can reach
 * queries and persistence through DI instead of the global model statics.
 * Extending {@see Event} keeps external `model.*` subscribers (which type-hint
 * {@see \Pagekit\Event\EventInterface}) working unchanged.
 */
class EntityEvent extends Event
{
    public function __construct(string $name, private readonly EntityManager $em)
    {
        parent::__construct($name);
    }

    /**
     * Gets the EntityManager that emitted this event.
     */
    public function getEntityManager(): EntityManager
    {
        return $this->em;
    }
}
