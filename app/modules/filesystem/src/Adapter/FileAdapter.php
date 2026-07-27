<?php

declare(strict_types=1);

namespace Pagekit\Filesystem\Adapter;

use Pagekit\Filesystem\Path;

class FileAdapter implements AdapterInterface
{
    protected string $path;

    /**
     * Directories a webserver exposes, each with the URL prefix it answers to,
     * in the order they are matched.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    protected array $mounts;

    /**
     * @param string                $path   directory relative paths resolve against; the first mount
     * @param string                $url    URL that directory is served under
     * @param array<string, string> $mounts further served directories, each mapped to its URL prefix
     */
    public function __construct(string $path, string $url = '', array $mounts = [])
    {
        $this->path = Path::directory($path);
        $this->mounts = [[$this->path, $url]];

        foreach ($mounts as $mount => $mountUrl) {
            $this->mounts[] = [Path::directory($mount), $mountUrl];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getStreamWrapper(): ?string
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getPathInfo(array $info): array
    {
        $info['localpath'] = $info['root'] === '' ? $this->path.$info['path'] : $info['pathname'];

        if ($info['localpath'] && file_exists($info['localpath'])) {
            $url = $this->url($info['localpath']);

            if ($url !== null) {
                $info['url'] = $url;
            }
        }

        return $info;
    }

    /**
     * Gets the URL a file is reachable under, or null when no mount holds it -
     * a file outside every served directory has no URL by construction.
     */
    protected function url(string $localpath): ?string
    {
        $directory = Path::directory($localpath);

        foreach ($this->mounts as [$path, $url]) {

            if (strpos($directory, $path) !== 0) {
                continue;
            }

            $relative = substr($localpath, strlen($path) - 1);

            return $url.strtr(rawurlencode($relative), ['%2F' => '/']);
        }

        return null;
    }
}
