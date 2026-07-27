<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM\Fixtures;

use Pagekit\Database\ORM\Attribute\BelongsTo;
use Pagekit\Database\ORM\Attribute\Column;
use Pagekit\Database\ORM\Attribute\Entity;
use Pagekit\Database\ORM\Attribute\Id;

/**
 * Entity whose only relation maps to a targetEntity class that does not exist,
 * so eager-loading it must trip the "not a mapped entity class" guard in
 * {@see \Pagekit\Database\ORM\QueryBuilder::getRelations()} instead of reaching
 * the repository lookup.
 *
 * A plain mapped entity (no ModelTrait); it lives under Tests/ (excluded from
 * the phpunit.xml.dist <source> set), so it never counts toward coverage.
 */
#[Entity(tableClass: 'rel_broken_target')]
class RelationBrokenTargetFixture
{
    #[Id]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Column(type: 'integer')]
    public ?int $author_id = null;

    #[BelongsTo(targetEntity: 'Pagekit\\Database\\Tests\\ORM\\Fixtures\\NoSuchEntity', keyFrom: 'author_id')]
    public ?object $author = null;
}
