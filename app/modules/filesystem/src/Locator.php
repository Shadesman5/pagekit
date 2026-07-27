<?php

declare(strict_types=1);

namespace Pagekit\Filesystem;

class Locator
{
    protected string $path;

    /** @var array<int, array{0: string, 1: string}> */
    protected array $paths = [];

    public function __construct(string $path)
    {
        $path = strtr($path, '\\', '/');

        if (substr($path, -1) != '/') {
            $path .= '/';
        }

        $this->path = $path;
    }

    /**
     * Adds file paths to locator.
     *
     * @param string|array<int, string> $paths
     */
    public function add(string $prefix, $paths): self
    {
        $paths = array_map(function ($path) use ($prefix) {

            $path = strtr($path, '\\', '/');

            if (substr($path, -1) != '/') {
                $path .= '/';
            }

            return [$prefix, $path];
        }, (array) $paths);

        $this->paths = array_merge($paths, $this->paths);

        return $this;
    }

    /**
     * Gets a file path from locator.
     */
    public function get(string $file): string|false
    {
        $file = ltrim(strtr($file, '\\', '/'), '/');
        $paths = array_merge($this->paths, [['', $this->path]]);

        foreach ($paths as $parts) {

            list($prefix, $path) = $parts;

            if ($prefix !== '' && strpos($file, $prefix) !== 0) {
                continue;
            }

            if (($part = substr($file, strlen($prefix))) !== false) {
                $path .= ltrim($part, '/');
            }

            if (file_exists($path)) {
                return $path;
            }
        }

        return false;
    }
}
