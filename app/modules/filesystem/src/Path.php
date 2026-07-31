<?php

declare(strict_types=1);

namespace Pagekit\Filesystem;

class Path
{
    /**
     * Parses and canonicalizes a path into root, path, dirname, pathname, protocol.
     *
     * @return array{root: string, path: string, dirname: string, pathname: string, protocol: string}
     */
    public static function parse(string $path): array
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

        $slash = strrpos($path, '/');

        return [
            'root' => $root,
            'path' => $path,
            'dirname' => $root.($slash !== false ? substr($path, 0, $slash) : ''),
            'pathname' => $root.$path,
            'protocol' => strpos($root, '://') ? substr($root, 0, -3) : 'file',
        ];
    }

    /**
     * Normalizes a directory to forward slashes and a single trailing slash, so
     * that a prefix comparison cannot match halfway through a path segment.
     */
    public static function directory(string $path): string
    {
        return rtrim(strtr($path, '\\', '/'), '/').'/';
    }

    /**
     * Returns whether a path is absolute.
     */
    public static function isAbsolute(string $path): bool
    {
        return self::parse($path)['root'] !== '';
    }

    /**
     * Returns whether a path is relative.
     */
    public static function isRelative(string $path): bool
    {
        return self::parse($path)['root'] === '';
    }
}
