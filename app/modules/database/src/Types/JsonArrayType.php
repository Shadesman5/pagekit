<?php

namespace Pagekit\Database\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * JSON array type for DBAL 3.x compatibility.
 * Replaces the deprecated JsonArrayType from DBAL 2.x.
 *
 * This type ensures backward compatibility with the old json_array type
 * while using the modern JSON type infrastructure from DBAL 3.x.
 */
class JsonArrayType extends JsonType
{
    /**
     * {@inheritdoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        $value = parent::convertToPHPValue($value, $platform);

        // Ensure we always return an array
        return is_array($value) ? $value : [];
    }

    /**
     * {@inheritdoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        // Ensure we have an array before converting to JSON
        if (!is_array($value) && $value !== null) {
            $value = [$value];
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'json_array';
    }

    /**
     * {@inheritdoc}
     */
    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        // Use the parent JSON type's SQL declaration
        return parent::getSQLDeclaration($column, $platform);
    }
}
