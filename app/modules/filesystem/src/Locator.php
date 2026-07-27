<?php

declare(strict_types=1);

namespace Pagekit\Filesystem;

class Locator
{
    protected string $path;

    protected string $public;

    /** @var array<int, array{0: string, 1: string}> */
    protected array $paths = [];

    /**
     * @param string $path   application root, holding the module and package sources
     * @param string $public webroot the servable part of those sources is published into,
     *                       empty when nothing is published
     */
    public function __construct(string $path, string $public = '')
    {
        $this->path = Path::directory($path);
        $this->public = $public === '' ? '' : Path::directory($public);
    }

    /**
     * Adds file paths to locator.
     *
     * A path below the application root is registered together with its
     * published copy, which wins: what a browser may request is served from
     * the webroot, everything else - views, translations, data - is read from
     * the source beside it.
     *
     * @param string|array<int, string> $paths
     */
    public function add(string $prefix, string|array $paths): self
    {
        $added = [];

        foreach ((array) $paths as $path) {

            $path = Path::directory($path);

            if ($published = $this->published($path)) {
                $added[] = [$prefix, $published];
            }

            $added[] = [$prefix, $path];
        }

        $this->paths = array_merge($added, $this->paths);

        return $this;
    }

    /**
     * Gets a file path from locator.
     */
    public function get(string $file): string|false
    {
        $file = ltrim(strtr($file, '\\', '/'), '/');
        $roots = $this->public !== '' ? [['', $this->public], ['', $this->path]] : [['', $this->path]];

        foreach (array_merge($this->paths, $roots) as $parts) {

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

    /**
     * Gets the webroot copy of a directory, or an empty string when it has none.
     */
    private function published(string $path): string
    {
        if ($this->public === '' || strpos($path, $this->public) === 0 || strpos($path, $this->path) !== 0) {
            return '';
        }

        return $this->public.substr($path, strlen($this->path));
    }
}
