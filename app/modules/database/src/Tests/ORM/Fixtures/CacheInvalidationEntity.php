<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM\Fixtures;

use Pagekit\Database\ORM\Attribute\Column;
use Pagekit\Database\ORM\Attribute\Entity;
use Pagekit\Database\ORM\Attribute\Id;

/**
 * Minimal mapped entity driving the in-memory-SQLite cache-hit-then-miss round
 * trip in {@see \Pagekit\Database\Tests\ORM\EntityManagerCacheInvalidationTest}.
 *
 * It lives under Tests/ (excluded from the phpunit.xml.dist <source> set), so it
 * never counts toward coverage; it exists only to give the AttributeLoader a real
 * table + identifier + column to map.
 */
#[Entity(tableClass: 'cache_invalidation_test')]
class CacheInvalidationEntity
{
    #[Id]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Column(type: 'string')]
    public ?string $title = null;
}
