<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM\Fixtures;

use Pagekit\Database\ORM\ModelTrait;
use Pagekit\Database\ORM\SerializableModelInterface;

/**
 * Minimal entity that uses {@see ModelTrait} and implements
 * {@see SerializableModelInterface}, driving the `toArray()` map/skip coverage in
 * {@see \Pagekit\Database\Tests\ORM\ModelTraitTest} without a database.
 *
 * It carries one property per behavior the serialization boundary must honor: a
 * plain field, a `json` field, a `datetime` field, a relation, a `_`-prefixed
 * internal, and a {@see \Closure} (the shape of the injected role loader).
 *
 * It lives under Tests/ (excluded from the phpunit.xml.dist <source> set), so it
 * never counts toward coverage.
 */
class SerializableFixtureEntity implements \JsonSerializable, SerializableModelInterface
{
    use ModelTrait;

    public ?int $id = null;

    public ?string $title = null;

    public mixed $meta = null;

    public ?\DateTime $created = null;

    public mixed $author = null;

    public mixed $_secret = null;

    public ?\Closure $loader = null;
}
