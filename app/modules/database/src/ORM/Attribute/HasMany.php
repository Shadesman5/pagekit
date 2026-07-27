<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class HasMany implements Attribute
{
    public function __construct(
        public string $targetEntity,
        public string $keyFrom = '',
        public string $keyTo = ''
    ) {
    }
}
