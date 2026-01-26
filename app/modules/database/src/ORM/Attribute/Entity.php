<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Entity implements Attribute
{
    public function __construct(
        public string $tableClass = '',
        public string $eventPrefix = ''
    ) {
    }
}
