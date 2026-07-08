<?php

declare(strict_types=1);

namespace Pagekit\Database\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * Array-safe JSON type: extends Doctrine's JsonType to guarantee array returns.
 *
 * Ensures null/empty values are converted to [] instead of null, which is required
 * by DataModelTrait (Arr::get expects arrays). Non-array values are wrapped in [].
 */
class JsonArrayType extends JsonType
{
    /**
     * {@inheritdoc}
     *
     * @return mixed Genuinely unknown type — overrides Doctrine DBAL's JsonType which uses mixed return; always returns array in this implementation.
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
        return 'json';
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
