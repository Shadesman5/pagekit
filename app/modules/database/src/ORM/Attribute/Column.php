<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Column implements Attribute
{
    public function __construct(
        public string $name = '',
        public string|int|null $type = 'string'
    ) {
    }
}
