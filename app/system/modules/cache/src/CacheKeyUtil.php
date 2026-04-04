<?php

declare(strict_types=1);

namespace Pagekit\Cache;

/**
 * PSR-6 cache key sanitizer.
 *
 * PSR-6 (RFC 6) reserves the characters {}()/\@: in cache keys.
 * This utility provides a single, canonical replacement used by
 * every component that builds dynamic cache keys.
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
