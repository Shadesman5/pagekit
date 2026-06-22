<?php

declare(strict_types=1);

namespace Pagekit\Database\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\SimpleArrayType as BaseSimpleArrayType;

class SimpleArrayType extends BaseSimpleArrayType
{
    /**
     * {@inheritdoc}
     *
     * Returns a list of native PHP scalars. The JSON-decode branch is what
     * makes this override meaningful — it preserves int/float/bool/null types
     * stored in `simple_array` columns (e.g. `roles` as `array<int, int>`).
     * Coercing every element through `strval()` would defeat the purpose and
     * break consumer property types (Role::$permissions stays `string`,
     * AccessModelTrait::$roles stays `int`, Widget::$nodes stays `int|string`).
     *
     * @return list<mixed>
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): array
    {
        if (is_string($value) && is_array($array = @json_decode("[{$value}]")) && $array !== []) {
            return array_values($array);
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return parent::convertToPHPValue($value, $platform);
    }
}
