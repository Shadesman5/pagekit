<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM\Fixtures;

use Pagekit\Database\ORM\Attribute\Column;
use Pagekit\Database\ORM\Attribute\Entity;
use Pagekit\Database\ORM\Attribute\Id;

/**
 * BelongsTo target for the eager-load regression coverage in
 * {@see \Pagekit\Database\Tests\ORM\QueryBuilderEagerLoadTest}.
 *
 * A plain mapped entity (no ModelTrait): relation resolution runs through
 * {@see \Pagekit\Database\ORM\Metadata}, so the fixture only needs a table, an
 * identifier and a column. It deliberately exposes no static `query()` method —
 * that is exactly the shape the eager-load path must handle.
 *
 * It lives under Tests/ (excluded from the phpunit.xml.dist <source> set), so it
 * never counts toward coverage.
 */
#[Entity(tableClass: 'rel_author')]
class RelationAuthorFixture
{
    #[Id]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Column(type: 'string')]
    public ?string $name = null;
}
