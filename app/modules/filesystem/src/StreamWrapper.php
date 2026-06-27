<?php

declare(strict_types=1);

namespace Pagekit\Filesystem;

class StreamWrapper
{
    /**
     * @var resource|null
     */
    protected mixed $handle = null;

    protected static ?\Pagekit\Filesystem\Filesystem $file = null;

    /**
     * @param Filesystem $file
     */
    public static function setFilesystem(Filesystem $file): void
    {
        static::$file = $file;
    }

    /**
     * Returns the filesystem, throwing if it has not been set.
     */
    private static function getFilesystem(): Filesystem
    {
        if (self::$file === null) {
            throw new \RuntimeException('StreamWrapper: filesystem not initialized. Call setFilesystem() first.');
        }

        return self::$file;
    }

    /**
     * Close directory handle.
     */
    public function dir_closedir(): bool
    {
        closedir($this->handle);

        return true;
    }

    /**
     * Open directory handle.
     */
    public function dir_opendir(string $path, int $options): bool
    {
        $resolved = self::getFilesystem()->getPath($path, true);
        if ($resolved === false) {
            return false;
        }

        $handle = opendir($resolved);
        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    /**
     * Read entry from directory handle.
     */
    public function dir_readdir(): string|false
    {
        return readdir($this->handle);
    }

    /**
     * Rewind directory handle.
     */
    public function dir_rewinddir(): bool
    {
        rewinddir($this->handle);

        return true;
    }

    /**
     * Create a directory.
     */
    public function mkdir(string $path, int $mode, int $options): bool
    {
        $resolved = self::getFilesystem()->getPath($path, true);
        if ($resolved === false) {
            return false;
        }

        return mkdir($resolved, $mode, (bool) ($options & STREAM_MKDIR_RECURSIVE));
    }

    /**
     * Renames a file or directory.
     */
    public function rename(string $pathFrom, string $pathTo): bool
    {
        $resolvedFrom = self::getFilesystem()->getPath($pathFrom, true);
        $resolvedTo = self::getFilesystem()->getPath($pathTo, true);
        if ($resolvedFrom === false || $resolvedTo === false) {
            return false;
        }

        return rename($resolvedFrom, $resolvedTo);
    }

    /**
     * Removes a directory.
     */
    public function rmdir(string $path, int $options): bool
    {
        $resolved = self::getFilesystem()->getPath($path, true);
        if ($resolved === false) {
            return false;
        }

        return rmdir($resolved);
    }

    /**
     * Delete a file.
     */
    public function unlink(string $path): bool
    {
        $resolved = self::getFilesystem()->getPath($path, true);
        if ($resolved === false) {
            return false;
        }

        return unlink($resolved);
    }

    /**
     * Retrieve information about a file.
     *
     * @return array<int|string, mixed>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $path = self::getFilesystem()->getPath($path, true);

        if ($path === false) {
            return false;
        }

        if ($flags & STREAM_URL_STAT_QUIET || !file_exists($path)) {
            return @stat($path);
        }

        return stat($path);
    }

    /**
     * Retrieve the underlaying resource.
     */
    public function stream_cast(int $castAs): bool
    {
        return false;
    }

    /**
     * Close an resource.
     */
    public function stream_close(): void
    {
        if ($this->handle === null) {
            return;
        }
        fclose($this->handle);
    }

    /**
     * Tests for end-of-file on a file pointer.
     */
    public function stream_eof(): bool
    {
        if ($this->handle === null) {
            return true;
        }
        return feof($this->handle);
    }

    /**
     * Flushes the output.
     */
    public function stream_flush(): bool
    {
        if ($this->handle === null) {
            return false;
        }
        return fflush($this->handle);
    }

    /**
     * Advisory file locking.
     */
    public function stream_lock(int $operation): bool
    {
        if ($this->handle === null) {
            return false;
        }
        if (in_array($operation, [LOCK_SH, LOCK_EX, LOCK_UN, LOCK_NB])) {
            return flock($this->handle, $operation);
        }

        return false;
    }

    /**
     * Opens file or URL.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $resolved = self::getFilesystem()->getPath($path, true);
        if ($resolved === false) {
            return false;
        }

        $handle = fopen($resolved, $mode);
        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    /**
     * Read from stream.
     */
    public function stream_read(int $count): string|false
    {
        if ($this->handle === null) {
            return false;
        }
        if ($count < 1) {
            return '';
        }

        return fread($this->handle, $count);
    }

    /**
     * Seeks to specific location in a stream.
     */
    public function stream_seek(int $offset, int $whence): bool
    {
        if ($this->handle === null) {
            return false;
        }
        return !fseek($this->handle, $offset, $whence);
    }

    /**
     * Retrieve information about a file resource.
     *
     * @return array<int|string, mixed>|false
     */
    public function stream_stat(): array|false
    {
        if ($this->handle === null) {
            return false;
        }
        return fstat($this->handle);
    }

    /**
     * Retrieve the current position of a stream.
     */
    public function stream_tell(): int|false
    {
        if ($this->handle === null) {
            return false;
        }
        return ftell($this->handle);
    }

    /**
     * Write to stream.
     */
    public function stream_write(string $data): int|false
    {
        if ($this->handle === null) {
            return false;
        }
        return fwrite($this->handle, $data);
    }
}
