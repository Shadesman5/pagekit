<?php

declare(strict_types=1);

namespace Pagekit\System\Extension;

use Pagekit\Filesystem\Filesystem;

/**
 * The record of extensions and themes that failed, kept across requests.
 *
 * A failing extension is only handled once the request that hit the failure is
 * over: the next boot has to know not to execute it again, and an administrator
 * has to be told which one it was. Neither survives in the request that failed,
 * so the record is a file - it outlives the request, the session and a cache
 * clear, and it is readable when the database is exactly what broke.
 *
 * It is JSON rather than a PHP file because it is data. Nothing requires it, so
 * a half-written or tampered record cannot turn into code on the next boot.
 *
 * Every method reports success as a bool and lets nothing escape. This runs on
 * the recovery path of a failure that is already in progress, where a throwing
 * store would replace the original fault with its own and take down the boot it
 * exists to keep alive. A record that cannot be written is a lost record, which
 * costs one degraded extension; a throw from here would cost the site.
 *
 * @phpstan-type ExtensionFailure array{name: string, type: string, class: string, message: string, file: string, line: int, time: int}
 */
final class ExtensionFailureStore
{
    public const TYPE_EXTENSION = 'extension';

    public const TYPE_THEME = 'theme';

    private const FILE = 'extension-failures.json';

    /**
     * @param string $path Directory the record lives in, created when first written
     */
    public function __construct(
        private readonly string $path,
        private readonly Filesystem $files,
    ) {
    }

    /**
     * Records that a module failed, replacing any earlier record for it: the
     * most recent failure is the one an administrator can still act on.
     *
     * The throwable's message and origin are kept, its trace is not - the trace
     * belongs in the log, where it is written with the same failure.
     *
     * @param  self::TYPE_* $type
     * @return bool         whether the failure is now on disk
     */
    public function record(string $name, string $type, \Throwable $e): bool
    {
        if ($name === '') {
            return false;
        }

        $entries = $this->all();

        $entries[$name] = [
            'name' => $name,
            'type' => $type,
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'time' => time(),
        ];

        return $this->write($entries);
    }

    /**
     * Every recorded failure, keyed by module name.
     *
     * A record that cannot be read or parsed reads as no records at all. The
     * alternative - failing here - would make an unreadable file break every
     * boot, and this is called on the boot path.
     *
     * @return array<string, ExtensionFailure>
     */
    public function all(): array
    {
        $entries = [];

        foreach ($this->read() as $name => $entry) {

            // The key is the name. An entry that carries a different one, or is
            // not an entry at all, comes from a file that was written by
            // something other than this store and is dropped rather than passed
            // on to callers that expect the shape.
            if (!is_string($name) || $name === '' || !is_array($entry)) {
                continue;
            }

            $entries[$name] = [
                'name' => $name,
                'type' => $this->text($entry['type'] ?? null),
                'class' => $this->text($entry['class'] ?? null),
                'message' => $this->text($entry['message'] ?? null),
                'file' => $this->text($entry['file'] ?? null),
                'line' => $this->number($entry['line'] ?? null),
                'time' => $this->number($entry['time'] ?? null),
            ];
        }

        return $entries;
    }

    /**
     * Whether a module is on record as failed.
     */
    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /**
     * Drops a module's record, leaving the others in place.
     *
     * @return bool whether the module is off the record, which a module that
     *              was never on it already is
     */
    public function clear(string $name): bool
    {
        $entries = $this->all();

        if (!isset($entries[$name])) {
            return true;
        }

        unset($entries[$name]);

        return $this->write($entries);
    }

    /**
     * Replaces the record with the given entries.
     *
     * The write is atomic, so a boot reading the file while it is replaced sees
     * either the old set of failures or the new one, never a truncated file it
     * would have to discard.
     *
     * @param array<string, ExtensionFailure> $entries
     */
    private function write(array $entries): bool
    {
        try {
            if (!$this->files->makeDir($this->path)) {
                return false;
            }

            // An exception message can carry bytes from a source that was never
            // UTF-8, a file path or a database error among them. Substituting
            // them keeps the record - refusing to encode would lose the failure
            // over the one part of it that is decoration.
            $json = json_encode(
                $entries,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if (!is_string($json)) {
                return false;
            }

            $this->files->dumpAtomic($this->file(), $json);

            return true;

        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The record as it is on disk, or nothing when there is none to read.
     *
     * @return array<array-key, mixed>
     */
    private function read(): array
    {
        try {
            $file = $this->file();

            if (!is_file($file)) {
                return [];
            }

            $content = @file_get_contents($file);

            if ($content === false || trim($content) === '') {
                return [];
            }

            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : [];

        } catch (\Throwable) {
            return [];
        }
    }

    private function file(): string
    {
        return rtrim($this->path, '/\\').'/'.self::FILE;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function number(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
