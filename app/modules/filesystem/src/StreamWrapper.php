<?php

declare(strict_types=1);

namespace Pagekit\Filesystem;

class StreamWrapper
{
    /**
     * @var resource
     */
    protected $handle;

    protected static ?\Pagekit\Filesystem\Filesystem $file = null;

    /**
     * @param Filesystem $file
     */
    public static function setFilesystem(Filesystem $file): void
    {
        static::$file = $file;
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
        $resolved = self::$file->getPath($path, true);
        if ($resolved === false) {
            return false;
        }

        $this->handle = opendir($resolved);

        return (bool) $this->handle;
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
        $resolved = self::$file->getPath($path, true);
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
        $resolvedFrom = self::$file->getPath($pathFrom, true);
        $resolvedTo = self::$file->getPath($pathTo, true);
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
        $resolved = self::$file->getPath($path, true);
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
        $resolved = self::$file->getPath($path, true);
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
        $path = self::$file->getPath($path, true);

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
        fclose($this->handle);
    }

    /**
     * Tests for end-of-file on a file pointer.
     */
    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    /**
     * Flushes the output.
     */
    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }

    /**
     * Advisory file locking.
     */
    public function stream_lock(int $operation): bool
    {
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
        $resolved = self::$file->getPath($path, true);
        if ($resolved === false) {
            return false;
        }

        $this->handle = fopen($resolved, $mode);

        return (bool) $this->handle;
    }

    /**
     * Read from stream.
     */
    public function stream_read(int $count): string|false
    {
        return fread($this->handle, $count);
    }

    /**
     * Seeks to specific location in a stream.
     */
    public function stream_seek(int $offset, int $whence): bool
    {
        return !fseek($this->handle, $offset, $whence);
    }

    /**
     * Retrieve information about a file resource.
     *
     * @return array<int|string, mixed>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    /**
     * Retrieve the current position of a stream.
     */
    public function stream_tell(): int|false
    {
        return ftell($this->handle);
    }

    /**
     * Write to stream.
     */
    public function stream_write(string $data): int|false
    {
        return fwrite($this->handle, $data);
    }
}
