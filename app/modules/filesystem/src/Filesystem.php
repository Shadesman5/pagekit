<?php

declare(strict_types=1);

namespace Pagekit\Filesystem;

use Pagekit\Filesystem\Adapter\AdapterInterface;
use Pagekit\Routing\Generator\UrlGenerator;

class Filesystem
{
    /**
     * @var array<string, AdapterInterface>
     */
    protected array $adapters = [];

    /**
     * Gets file path URL.
     *
     * @param int|bool $referenceType One of {@see UrlGenerator}'s reference type constants
     *                                or `true` for {@see UrlGenerator::ABSOLUTE_URL} (legacy).
     */
    public function getUrl(string $file, int|bool $referenceType = UrlGenerator::ABSOLUTE_PATH): string|false
    {
        if (!$url = $this->getPathOption($file, 'url')) {
            return false;
        }

        if ($referenceType === UrlGenerator::ABSOLUTE_PATH) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (strlen($path) > 1) {
                $pos = strpos($url, $path);
                $url = $pos !== false ? substr($url, $pos) : $url;
            } else {
                $url = '/';
            }
        } elseif ($referenceType === UrlGenerator::NETWORK_PATH) {
            $pos = strpos($url, '//');
            $url = $pos !== false ? substr($url, $pos) : $url;
        }

        return $url;
    }

    /**
     * Gets canonicalized file path or localpath.
     */
    public function getPath(string $file, bool $local = false): string|false
    {
        return $this->getPathOption($file, $local ? 'localpath' : 'pathname') ?: false;
    }

    /**
     * Gets file path info.
     *
     * @return array<string, mixed>
     */
    public function getPathInfo(string $file): array
    {
        $info = Path::parse($file);

        if ($info['protocol'] != 'file') {
            $info['url'] = $info['pathname'];
        }

        if ($adapter = $this->getAdapter($info['protocol'])) {
            return $adapter->getPathInfo($info);
        }

        return $info;
    }

    /**
     * Gets a single file path info option.
     */
    public function getPathOption(string $file, string $option): string
    {
        $info = $this->getPathInfo($file);
        $value = $info[$option] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Checks whether a file or directory exists.
     *
     * @param mixed $files string, array of strings, or anything else that should be treated as not existing
     */
    public function exists(mixed $files): bool
    {
        $files = (array) $files;

        foreach ($files as $file) {

            if (!is_string($file) || $file === '') {
                return false;
            }

            $file = $this->getPathOption($file, 'pathname');

            if ($file === '' || !file_exists($file)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Copies a file.
     */
    public function copy(string $source, string $target): bool
    {
        $source = $this->getPathOption($source, 'pathname');
        $target = $this->getPathInfo($target);

        $dirname = is_string($target['dirname'] ?? null) ? $target['dirname'] : '';
        $pathname = is_string($target['pathname'] ?? null) ? $target['pathname'] : '';

        if (!is_file($source) || !$this->makeDir($dirname)) {
            return false;
        }

        return @copy($source, $pathname);
    }

    /**
     * Deletes a file.
     *
     * @param string|array<int, string> $files
     */
    public function delete($files): bool
    {
        $files = (array) $files;

        foreach ($files as $file) {

            $file = $this->getPathOption($file, 'pathname');

            if (is_dir($file)) {

                if (substr($file, -1) != '/') {
                    $file .= '/';
                }

                foreach ($this->listDir($file) as $name) {
                    if (!$this->delete($file.$name)) {
                        return false;
                    }
                }

                if (!@rmdir($file)) {
                    return false;
                }

            } elseif (!@unlink($file)) {
                return false;
            }
        }

        return true;
    }

    /**
     * List files and directories inside the specified path.
     *
     * @return array<int, string>
     */
    public function listDir(string $dir): array
    {
        $dir = $this->getPathOption($dir, 'pathname');

        return array_diff(scandir($dir) ?: [], ['..', '.']);
    }

    /**
     * Makes a directory.
     */
    public function makeDir(string $dir, int $mode = 0777, bool $recursive = true): bool
    {
        $dir = $this->getPathOption($dir, 'pathname');

        return is_dir($dir) ? true : @mkdir($dir, $mode, $recursive);
    }

    /**
     * Copies a directory.
     */
    public function copyDir(string $source, string $target): bool
    {
        $source = $this->getPathOption($source, 'pathname');
        $target = $this->getPathOption($target, 'pathname');

        if (!is_dir($source) || !$this->makeDir($target)) {
            return false;
        }

        if (substr($source, -1) != '/') {
            $source .= '/';
        }

        if (substr($target, -1) != '/') {
            $target .= '/';
        }

        foreach ($this->listDir($source) as $file) {
            if (is_dir($source.$file)) {

                if (!$this->copyDir($source.$file, $target.$file)) {
                    return false;
                }

            } elseif (!$this->copy($source.$file, $target.$file)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gets a adapter.
     */
    public function getAdapter(string $protocol): ?AdapterInterface
    {
        return isset($this->adapters[$protocol]) ? $this->adapters[$protocol] : null;
    }

    /**
     * Registers a adapter.
     */
    public function registerAdapter(string $protocol, AdapterInterface $adapter): void
    {
        $this->adapters[$protocol] = $adapter;

        if ($wrapper = $adapter->getStreamWrapper()) {
            stream_wrapper_register($protocol, $wrapper);
        }
    }
}
