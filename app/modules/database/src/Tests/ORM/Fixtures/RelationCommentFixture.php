<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM\Fixtures;

use Pagekit\Database\ORM\Attribute\Column;
use Pagekit\Database\ORM\Attribute\Entity;
use Pagekit\Database\ORM\Attribute\Id;

/**
 * HasMany target for the eager-load regression coverage in
 * {@see \Pagekit\Database\Tests\ORM\QueryBuilderEagerLoadTest}.
 *
 * Plain mapped entity (no ModelTrait, no static `query()`), joined back to
 * {@see RelationPostFixture} via the `post_id` foreign key.
 *
 * It lives under Tests/ (excluded from the phpunit.xml.dist <source> set), so it
 * never counts toward coverage.
 */
#[Entity(tableClass: 'rel_comment')]
class RelationCommentFixture
{
    #[Id]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Column(type: 'integer')]
    public ?int $post_id = null;

    #[Column(type: 'string')]
    public ?string $body = null;
}
