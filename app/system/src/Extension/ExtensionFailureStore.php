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
 * An entry also counts how many times its module failed in a row, which is what
 * separates a module that broke once from one that breaks on every request.
 *
 * Every method reports success as a bool and lets nothing escape. This runs on
 * the recovery path of a failure that is already in progress, where a throwing
 * store would replace the original fault with its own and take down the boot it
 * exists to keep alive. A record that cannot be written is a lost record, which
 * costs one degraded extension; a throw from here would cost the site.
 *
 * @phpstan-type ExtensionFailure array{name: string, type: string, class: string, message: string, file: string, line: int, time: int, count: int}
 */
final class ExtensionFailureStore
{
    public const TYPE_EXTENSION = 'extension';

    public const TYPE_THEME = 'theme';

    /**
     * How many failures in a row a module is given before it is left
     * unexecuted rather than tried again.
     *
     * An extension is already out of the load list after its first failure, so
     * this decides the theme, which is the one module executed whether or not
     * it is on the record. Trying it forever is what a theme failing on an
     * expensive query costs a site on every single request; giving up on it
     * after the first attempt would be worse, because being tried again is the
     * only way a theme ever comes back.
     */
    public const PAUSE_THRESHOLD = 3;

    private const FILE = 'extension-failures.json';

    /**
     * The file writers hold while they read, change and replace the record.
     * Beside the record rather than the record itself: the record is replaced
     * by a rename, so a lock on it would be a lock on a file that is no longer
     * there the moment the write succeeds.
     */
    private const LOCK = 'extension-failures.lock';

    /**
     * @param string $path Directory the record lives in, created when first written
     */
    public function __construct(
        private readonly string $path,
        private readonly Filesystem $files,
    ) {
    }

    /**
     * Records that a module failed, replacing what an earlier record said about
     * it and counting one failure more.
     *
     * What the entry describes is the most recent failure, because that is the
     * one an administrator can still act on. What the count carries is the
     * other half of the story: a module on its first failure may work again on
     * the next request, and one that has failed on every request since is not
     * going to.
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

        return $this->locked(function () use ($name, $type, $e): bool {

            $entries = $this->all();

            $entries[$name] = [
                'name' => $name,
                'type' => $type,
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'time' => time(),
                'count' => ($entries[$name]['count'] ?? 0) + 1,
            ];

            return $this->write($entries);
        });
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
                'count' => $this->tally($entry['count'] ?? null),
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
        return $this->locked(function () use ($name): bool {

            $entries = $this->all();

            if (!isset($entries[$name])) {
                return true;
            }

            unset($entries[$name]);

            return $this->write($entries);
        });
    }

    /**
     * Puts an entry back on the record, replacing any current one for its module.
     *
     * Clearing a record is part of an operation that can still fail after it,
     * and an operation that did not happen may not take the record with it: what
     * the entry says is the failure that actually occurred, at the time it
     * occurred, so it goes back as it was rather than being rewritten from
     * whatever the caller ran into afterwards.
     *
     * @param  ExtensionFailure $entry as it was read from this store
     * @return bool             whether the entry is on disk
     */
    public function restore(array $entry): bool
    {
        $name = $entry['name'];

        if ($name === '') {
            return false;
        }

        return $this->locked(function () use ($name, $entry): bool {

            $entries = $this->all();
            $entries[$name] = $entry;

            return $this->write($entries);
        });
    }

    /**
     * Reads, changes and replaces the record with no other writer in between.
     *
     * Every write here is that sequence, and the atomic replace at the end of
     * it only keeps a reader from seeing a torn file. Two workers running the
     * sequence at once both read the same entries, and the one that writes
     * second writes over what the first recorded: a failure nobody is told
     * about, or two failures that arrive as one count and leave a module short
     * of the threshold it should have reached. The lock covers the whole
     * sequence, which is the part an atomic write cannot cover.
     *
     * A change that cannot take the lock is made without one, exactly as every
     * write was made until now. This is the recovery path of a fault that is
     * already in progress: waiting behind a lock is worth it, failing over one
     * is not.
     *
     * @param \Closure(): bool $change
     */
    private function locked(\Closure $change): bool
    {
        $lock = $this->lock();

        try {
            return $change();
        } finally {
            if ($lock !== null) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    /**
     * Takes the lock the record is written under, waiting for whoever holds it.
     *
     * Only where the directory is already there: it is created by the write
     * itself, and a call that turns out to have nothing to write - a clear for
     * a module that was never on the record - leaves an installation that never
     * had a failure exactly as it found it. Nothing is being raced there
     * either, because a record that does not exist has no writer to lose an
     * entry to.
     *
     * @return resource|null null where no lock could be taken, which is the
     *                       unserialized path rather than a failure
     */
    private function lock(): mixed
    {
        if (!is_dir($this->path)) {
            return null;
        }

        $lock = @fopen($this->lockFile(), 'c');

        if ($lock === false) {
            return null;
        }

        if (!@flock($lock, LOCK_EX)) {
            @fclose($lock);

            return null;
        }

        return $lock;
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

    private function lockFile(): string
    {
        return rtrim($this->path, '/\\').'/'.self::LOCK;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function number(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    /**
     * How many failures an entry stands for, which is at least the one that put
     * it on the record: an entry written before the count existed, or carrying
     * anything that is not a count, describes a module that failed.
     */
    private function tally(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 1;
    }
}
