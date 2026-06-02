<?php

declare(strict_types=1);

namespace Pagekit\Filesystem;

class Path
{
    /**
     * Parses and canonicalizes a path into root, path, dirname, pathname, protocol.
     *
     * @return string|array<string, string>
     */
    public static function parse(string $path, ?string $option = null): string|array
    {
        $root = '';
        $path = strtr($path, '\\', '/');

        if (preg_match('@^(?:/|[a-z]:/?|[a-z]+://)@i', $path, $parts)) {
            $root = $parts[0];
            $path = substr($path, strlen($root));
        }

        $parts = [];

        foreach (array_filter(explode('/', $path), 'strlen') as $part) {
            if ('..' == $part) {

                if (count($parts)) {
                    array_pop($parts);

                    continue;
                } elseif (!$root) {
                    continue;
                }

            } elseif ('.' != $part) {
                $parts[] = $part;
            }
        }

        $path = implode('/', $parts);
        $info = compact('root', 'path');

        $slash = strrpos($path, '/');
        $info['dirname'] = $root.($slash !== false ? substr($path, 0, $slash) : '');
        $info['pathname'] = $root.$path;
        $info['protocol'] = strpos($root, '://') ? substr($root, 0, -3) : 'file';

        if ($option === null) {
            return $info;
        }

        return array_key_exists($option, $info) ? $info[$option] : '';
    }

    /**
     * Returns whether a path is absolute.
     */
    public static function isAbsolute(string $path): bool
    {
        return self::parse($path, 'root') !== '';
    }

    /**
     * Returns whether a path is relative.
     */
    public static function isRelative(string $path): bool
    {
        return self::parse($path, 'root') === '';
    }
}
