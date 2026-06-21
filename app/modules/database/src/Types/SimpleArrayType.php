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
     * @return list<string>
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): array
    {
        if (is_string($value) && is_array($array = @json_decode("[{$value}]")) && $array !== []) {
            return array_values(array_map('strval', $array));
        }

        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        return parent::convertToPHPValue($value, $platform);
    }
}
