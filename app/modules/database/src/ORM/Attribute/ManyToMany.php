<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ManyToMany implements Attribute
{
    public function __construct(
        public string $targetEntity,
        public string $keyFrom = '',
        public string $keyTo = '',
        public string $keyThroughFrom = '',
        public string $keyThroughTo = '',
        public string $tableThrough = ''
    ) {
    }
}
