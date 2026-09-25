<?php

declare(strict_types=1);

namespace Pagekit\Util;

/**
 * Replaces PSR-6 reserved characters in a cache key.
 */
final class CacheKeyUtil
{
    private const RESERVED = [':', '\\', '/', '@', '{', '}', '(', ')'];
    private const REPLACEMENT = '_';

    /**
     * Replace PSR-6 reserved characters with underscores.
     */
    public static function sanitize(string $key): string
    {
        return str_replace(self::RESERVED, self::REPLACEMENT, $key);
    }
}
