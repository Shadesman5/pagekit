<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM\Fixtures;

use Pagekit\Database\ORM\Attribute\BelongsTo;
use Pagekit\Database\ORM\Attribute\Column;
use Pagekit\Database\ORM\Attribute\Entity;
use Pagekit\Database\ORM\Attribute\HasMany;
use Pagekit\Database\ORM\Attribute\Id;

/**
 * Root entity for the eager-load regression coverage in
 * {@see \Pagekit\Database\Tests\ORM\QueryBuilderEagerLoadTest}: mirrors the blog
 * post shape (a BelongsTo `author` + a HasMany `comments`) that returned HTTP
 * 500 when {@see \Pagekit\Database\ORM\QueryBuilder::getRelations()} still built
 * relation queries via a removed static `query()`.
 *
 * Plain mapped entity (no ModelTrait); the eager-loaded `author` / `comments`
 * are assigned through {@see \Pagekit\Database\ORM\Metadata}.
 *
 * It lives under Tests/ (excluded from the phpunit.xml.dist <source> set), so it
 * never counts toward coverage.
 */
#[Entity(tableClass: 'rel_post')]
class RelationPostFixture
{
    #[Id]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Column(type: 'string')]
    public ?string $title = null;

    #[Column(type: 'integer')]
    public ?int $author_id = null;

    #[BelongsTo(targetEntity: RelationAuthorFixture::class, keyFrom: 'author_id')]
    public ?RelationAuthorFixture $author = null;

    /** @var array<int, RelationCommentFixture>|null */
    #[HasMany(targetEntity: RelationCommentFixture::class, keyFrom: 'id', keyTo: 'post_id')]
    public ?array $comments = null;
}
