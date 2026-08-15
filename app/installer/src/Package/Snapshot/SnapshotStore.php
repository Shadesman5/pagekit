<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package\Snapshot;

use Pagekit\Filesystem\Filesystem;

/**
 * The snapshots a removal leaves behind, one directory per snapshot.
 *
 * Removing a package has to be undoable, so what the removal takes away is put
 * aside first: the package's own files, a dump of the database it was installed
 * in, and a description of both. This class owns where that goes and what it is
 * called. What goes into the dump, and who decides that a snapshot is taken, is
 * somebody else's business.
 *
 * Snapshots live under a directory of their own rather than in the temp or cache
 * directory, because clearing the cache empties those and a snapshot has to
 * outlive one. It is not under storage/ either, which the webroot links to: a
 * dump carries every password hash on the site and may not be one request away
 * from anybody. What this class creates it creates owner-only for the same
 * reason - which does mean that a snapshot taken on the console is readable by
 * the web server only where both run as the same user, the arrangement a
 * container has and a shared host may not.
 *
 * The id is generated here and validated wherever one comes back in, because it
 * comes back from an HTTP request whenever an administrator restores or purges a
 * snapshot. It is a plain name - no separator, no dot segment, nothing but the
 * characters below - and it is only ever resolved directly under this directory,
 * so there is no id that names a path outside the store.
 *
 * A directory is inventoried whether or not its metadata can be read: an id and
 * a modification time are enough to show it, to expire it and to purge it, and a
 * snapshot that dropped out of the inventory would be disk nothing reclaims. The
 * directory name is what says which snapshot it is; the metadata only describes
 * it. Whether a snapshot can still be restored is decided when it is restored,
 * against the dump and the driver it was taken from.
 *
 * Being in the inventory is therefore not the same as being whole. Writing a
 * snapshot takes as long as a database and a package tree take to copy, and
 * whatever interrupts that - a full disk, a killed process, a removal that got
 * halfway through taking one back off the disk - leaves a directory holding some
 * of it. So a snapshot counts as a way back only while it is marked as one: the
 * mark goes on once the last byte is written, and a removal either takes it off
 * before it takes anything else or removes nothing at all. Everything else in
 * the directory is there long before the snapshot is finished.
 *
 * @phpstan-type SnapshotDatabase array{driver: string, platform: string, prefix: string}
 * @phpstan-type SnapshotDetails array{package: string, module: string, title: string, type: string, version: string, composer: bool, reason: string, format: int, database: SnapshotDatabase}
 * @phpstan-type Snapshot array{id: string, created: int, expires: int|null, size: int, complete: bool, package: string, module: string, title: string, type: string, version: string, composer: bool, reason: string, format: int, database: SnapshotDatabase}
 */
final class SnapshotStore
{
    /**
     * How long a snapshot is kept before a purge may reclaim it, where the
     * installation configures no window of its own.
     */
    public const DEFAULT_RETENTION_DAYS = 30;

    /**
     * What a snapshot says about itself. JSON rather than PHP because it is
     * data: nothing requires it, so a corrupted or tampered file cannot turn
     * into code on the next read.
     */
    public const METADATA_FILE = 'metadata.json';

    /**
     * The database as it stood before the removal.
     */
    public const DUMP_FILE = 'db.dump';

    /**
     * The archived package tree, one vendor/name directory below it - the same
     * shape it has in packages/, so a restore is a copy back.
     */
    public const FILES_DIR = 'files';

    /**
     * Composer's record of what it had installed, captured for a package
     * Composer manages. It sits beside the archived tree rather than in it,
     * because it describes the whole installation and not the one package: a
     * restore puts the package's own files back, and what to do with this is a
     * decision somebody makes about Composer's bookkeeping.
     */
    public const INSTALLED_FILE = 'installed.json';

    /**
     * The mark that says everything a restore needs is in the directory.
     *
     * Nothing else in a snapshot can say that. The metadata is written before
     * any of what it describes, the dump lands before the package files are
     * archived, and each of those is finished on its own well before the
     * snapshot is - so this file is written last and taken off first, and it
     * exists only while what is around it is whole. The time in it is for
     * whoever reads the directory by hand; that there is a file at all is what
     * the store goes by.
     */
    public const COMPLETE_FILE = 'complete';

    private const SECONDS_PER_DAY = 86400;

    /**
     * Owner-only, on the directory as well as on the metadata: the dump beside
     * it holds the whole database, and a snapshot is retained for weeks.
     */
    private const DIRECTORY_MODE = 0700;

    private const FILE_MODE = 0600;

    /**
     * What an id may consist of. No separator and no leading dot, so no id can
     * name anything but a directory directly under this store - and the pattern
     * ends in \z rather than $, which would accept a trailing newline.
     */
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*\z/';

    private const ID_MAX_LENGTH = 128;

    /**
     * How much of the module name goes into an id. The id is a label an
     * administrator reads in a list, not a key: enough of the name to recognise
     * it, and a path that stays short on every filesystem.
     */
    private const SLUG_MAX_LENGTH = 32;

    /**
     * How many ids are tried before the store gives up on finding a free one.
     * Two snapshots of the same module in the same second collide only if their
     * random halves do as well, so a retry is for the improbable rather than
     * the expected.
     */
    private const ID_ATTEMPTS = 3;

    /**
     * @param string $path          Directory the snapshots live in, created when the first one is taken
     * @param int    $retentionDays How long a snapshot is kept; zero or less keeps every snapshot until someone purges it
     */
    public function __construct(
        private readonly string $path,
        private readonly Filesystem $files,
        private readonly int $retentionDays = self::DEFAULT_RETENTION_DAYS,
    ) {
    }

    /**
     * The window an installation has configured, as a number of days.
     *
     * Module configuration is whatever the database holds under that key, and a
     * settings form, a hand-edited row or an older release may have left it as
     * text, as empty or as something that is no number in any reading. What is
     * one is taken as it stands - zero and below included, which is retention
     * turned off on purpose - and everything else is the default: a value nobody
     * can read says nothing about how long this installation wants to keep a way
     * back, so it gets the window every installation ships with.
     */
    public static function retentionDays(mixed $configured): int
    {
        return is_numeric($configured) ? (int) $configured : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Opens a snapshot: a directory of its own, with the description of what is
     * about to be put into it already in place.
     *
     * The metadata is written first and atomically, so that the directory is
     * never a nameless heap of bytes, and it is written before the dump because
     * everything it says is known before the dump starts. The payload beside it
     * decides nothing about this file: a dump lands under its own name only once
     * it is complete, and a snapshot whose dump never arrived is one a restore
     * refuses rather than one this store hides.
     *
     * @param  SnapshotDetails   $details what the snapshot is of, and of which database
     * @return string            the id the snapshot is addressed by from here on
     * @throws \RuntimeException where the directory or the metadata could not be
     *                          written, which leaves nothing of the snapshot behind
     */
    public function create(array $details): string
    {
        $id = $this->reserve($this->slug($details['module']));
        $directory = $this->pathFor($id);

        try {
            // A package title comes out of a composer.json, which nothing
            // guarantees is UTF-8. Substituting what cannot be encoded keeps the
            // snapshot; refusing would lose it over its decoration.
            $json = json_encode(
                ['id' => $id, 'created' => time()] + $details,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if (!is_string($json)) {
                throw new \RuntimeException('The snapshot metadata could not be encoded.');
            }

            $this->files->dumpAtomic($directory.'/'.self::METADATA_FILE, $json, self::FILE_MODE);
        } catch (\Throwable $e) {
            // The caller is about to be told that no snapshot was taken, so the
            // directory this call made is taken back with it. Where even that
            // fails, what stays is an empty directory - it is in the inventory,
            // it has no dump to restore from, and retention reclaims it.
            $this->files->delete($directory);

            throw new \RuntimeException(sprintf('Failed to write the snapshot metadata in "%s".', $directory), 0, $e);
        }

        return $id;
    }

    /**
     * Closes a snapshot: everything a restore needs is in it, and from here on
     * it is a way back.
     *
     * The one thing about a snapshot that cannot be read off the directory it
     * is in. A dump and an archive that are both there say nothing about
     * whether the second one is all of the package - which is exactly what an
     * interrupted write leaves - so the whole of a snapshot is a fact only
     * whoever wrote the last part of it has, and this is where that fact is put
     * on the disk.
     *
     * @param  string                    $id as this store handed it out
     * @throws \InvalidArgumentException where the id is not one, or names no snapshot in this store
     * @throws \RuntimeException         where the mark could not be written. What is
     *                                  left is then a directory nothing restores
     *                                  from, which is what an unmarked snapshot is
     */
    public function complete(string $id): void
    {
        $directory = $this->directory($id);

        try {
            $this->files->dumpAtomic($directory.'/'.self::COMPLETE_FILE, gmdate('c')."\n", self::FILE_MODE);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Failed to mark the snapshot in "%s" as complete.', $directory), 0, $e);
        }
    }

    /**
     * What a snapshot says about itself, plus what only the store can say: the
     * id it is addressed by, when it was taken, when it may be purged, how much
     * disk it is holding and whether it is whole.
     *
     * @param  string         $id as this store handed it out
     * @return Snapshot|null  null where no snapshot goes by that id, an id that
     *                        is not one included
     */
    public function get(string $id): ?array
    {
        if (!$this->isValidId($id)) {
            return null;
        }

        $directory = $this->pathFor($id);

        return is_dir($directory) ? $this->read($id, $directory) : null;
    }

    /**
     * Every snapshot in the store, newest first.
     *
     * @return array<string, Snapshot> keyed by id
     */
    public function list(): array
    {
        $snapshots = [];

        foreach ($this->ids() as $id) {
            $snapshots[$id] = $this->read($id, $this->pathFor($id));
        }

        uasort($snapshots, self::newestFirst(...));

        return $snapshots;
    }

    /**
     * The snapshots whose retention window has run out.
     *
     * A snapshot expires the moment the window is up rather than a day later,
     * and a window of zero or less expires nothing at all: an installation that
     * turns retention off keeps its snapshots until an administrator says
     * otherwise.
     *
     * Read without the disk accounting list() does, because this runs whenever a
     * snapshot is taken.
     *
     * @return array<int, string> ids, in whatever order the filesystem lists them
     */
    public function expired(): array
    {
        if ($this->retentionDays <= 0) {
            return [];
        }

        $deadline = time() - $this->retentionDays * self::SECONDS_PER_DAY;
        $expired = [];

        foreach ($this->ids() as $id) {
            $directory = $this->pathFor($id);

            if ($this->createdAt($this->metadata($directory), $directory) <= $deadline) {
                $expired[] = $id;
            }
        }

        return $expired;
    }

    /**
     * Removes a snapshot and everything in it.
     *
     * The mark goes before what it stands for does, and a mark that will not go
     * stops the removal where it is. Taking a directory tree apart is a walk
     * that can fail anywhere in it, and what is left then is a snapshot missing
     * whichever files the walk had already reached - so either the way back is
     * given up first and the tree removed after, or nothing is touched at all.
     * There is no order in which a half-removed directory is still marked as
     * something a package can be restored from.
     *
     * @param  string $id as this store handed it out
     * @return bool   whether the snapshot is gone from the store. False where
     *                there was none to remove, so that a purge cannot report
     *                success for something it never had; where the mark would
     *                not come off, which leaves the snapshot exactly as it was;
     *                and where the removal got part of the way, which leaves a
     *                directory that is no longer a snapshot anybody can restore
     *                from
     */
    public function delete(string $id): bool
    {
        if (!$this->isValidId($id)) {
            return false;
        }

        $directory = $this->pathFor($id);

        if (!is_dir($directory)) {
            return false;
        }

        if (!$this->unmark($directory)) {
            return false;
        }

        return $this->files->delete($directory);
    }

    /**
     * Where a snapshot keeps its files, for whoever writes into it or reads it
     * back.
     *
     * @param  string                    $id as this store handed it out
     * @throws \InvalidArgumentException where the id is not one, or names no snapshot in this store
     */
    public function directory(string $id): string
    {
        $directory = $this->pathFor($id);

        if (!is_dir($directory)) {
            throw new \InvalidArgumentException('No snapshot goes by this id.');
        }

        return $directory;
    }

    /**
     * The database dump of a snapshot, whether or not one has been written yet.
     *
     * @throws \InvalidArgumentException where the id is not one, or names no snapshot in this store
     */
    public function dumpFile(string $id): string
    {
        return $this->directory($id).'/'.self::DUMP_FILE;
    }

    /**
     * The archived package tree of a snapshot, whether or not one has been
     * written yet.
     *
     * @throws \InvalidArgumentException where the id is not one, or names no snapshot in this store
     */
    public function filesDirectory(string $id): string
    {
        return $this->directory($id).'/'.self::FILES_DIR;
    }

    /**
     * The captured Composer bookkeeping of a snapshot, whether or not the
     * package it was taken of was one Composer installed.
     *
     * @throws \InvalidArgumentException where the id is not one, or names no snapshot in this store
     */
    public function installedFile(string $id): string
    {
        return $this->directory($id).'/'.self::INSTALLED_FILE;
    }

    /**
     * Whether a string is an id at all.
     *
     * Everything that takes an id from outside asks this first: an id is the one
     * part of a snapshot's address a request gets to choose, and the store
     * resolves it directly under its own directory. A traversal, an absolute
     * path, a separator, a null byte or a newline is therefore not a snapshot
     * that cannot be found but a name that is refused before it is a path.
     */
    public function isValidId(string $id): bool
    {
        return strlen($id) <= self::ID_MAX_LENGTH && preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * Takes a directory for a snapshot that is about to be written, under an id
     * nothing in the store is using.
     *
     * Creating the directory is what claims the id, because creating one is a
     * single indivisible step that fails when the directory is already there:
     * of two removals that arrive at the same id, exactly one gets it and the
     * other tries the next. Asking beforehand whether the directory exists
     * decides nothing - the answer is from before the other one created it, and
     * both would go on to write their metadata, their dump and their files into
     * the same snapshot. Which is also why the filesystem service does not make
     * this directory: it reports one that is already there as made, the right
     * answer wherever a directory only has to exist and the wrong one where
     * making it is the claim.
     *
     * Recursive, so that the store's own directory comes into being with the
     * first snapshot taken in it, and owner-only for the same reason everything
     * else here is - the dump about to land in it holds the whole database.
     *
     * @throws \RuntimeException where the directory could not be created, a
     *                          store whose own directory cannot be written included
     */
    private function reserve(string $slug): string
    {
        for ($attempt = 1; $attempt <= self::ID_ATTEMPTS; $attempt++) {
            $id = $this->id($slug);
            $directory = $this->pathFor($id);

            if (@mkdir($directory, self::DIRECTORY_MODE, true)) {
                return $id;
            }

            // Nothing at the path afterwards either, so this is not an id that
            // is taken but a store that cannot be written: a read-only mount, a
            // full disk, a path occupied by a file. The removal this snapshot
            // was for is called off.
            if (!file_exists($directory)) {
                throw new \RuntimeException(sprintf('Failed to create the snapshot directory "%s".', $directory));
            }
        }

        throw new \RuntimeException('Failed to find a snapshot id that is not taken.');
    }

    /**
     * An id: when the snapshot was taken, what it is of, and a random half that
     * keeps two snapshots of the same module in the same second apart.
     *
     * The time is UTC, so that ids sort the way they were taken wherever the
     * installation thinks it is.
     */
    private function id(string $slug): string
    {
        return sprintf('%s-%s-%s', gmdate('Ymd-His'), $slug, bin2hex(random_bytes(4)));
    }

    /**
     * The part of an id that names the module.
     *
     * Reduced to what an id may carry rather than refused: the name comes out of
     * a package manifest, and a snapshot may not fail to be taken over a
     * character in it. A name that is left with nothing at all is labelled for
     * what it is instead.
     */
    private function slug(string $module): string
    {
        // Trimmed after the cut as well: a name shortened in the middle of what
        // was a separator would otherwise end in one.
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $module), '-');
        $slug = trim(substr($slug, 0, self::SLUG_MAX_LENGTH), '-');

        return $slug !== '' ? $slug : 'package';
    }

    /**
     * Takes the mark off a snapshot that is about to be removed.
     *
     * @return bool whether the directory is unmarked afterwards. A mark there
     *              was none of counts: what the caller needs to know before it
     *              starts removing files is that nothing is left claiming they
     *              are all still there
     */
    private function unmark(string $directory): bool
    {
        $mark = $directory.'/'.self::COMPLETE_FILE;

        if (@unlink($mark)) {
            return true;
        }

        // There was nothing to take off, or somebody else took it off first.
        // Asked of the disk rather than of the stat cache, which still holds
        // whatever the last read of this snapshot put in it.
        clearstatcache(true, $mark);

        return !file_exists($mark);
    }

    /**
     * Where a snapshot with this id belongs, whether or not it is there.
     *
     * @throws \InvalidArgumentException where the id is not one. The rejected
     *                                  value stays out of the message: it can
     *                                  come from a request, and an exception is
     *                                  no place to carry that back
     */
    private function pathFor(string $id): string
    {
        if (!$this->isValidId($id)) {
            throw new \InvalidArgumentException('Not a snapshot id.');
        }

        return rtrim($this->path, '/\\').'/'.$id;
    }

    /**
     * The snapshot directories in the store.
     *
     * @return array<int, string> ids, in whatever order the filesystem lists them
     */
    private function ids(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }

        $ids = [];

        foreach ($this->files->listDir($this->path) as $name) {
            if ($this->isValidId($name) && is_dir(rtrim($this->path, '/\\').'/'.$name)) {
                $ids[] = $name;
            }
        }

        return $ids;
    }

    /**
     * One snapshot, as far as it can be read.
     *
     * @return Snapshot
     */
    private function read(string $id, string $directory): array
    {
        $data = $this->metadata($directory);
        $section = $data['database'] ?? null;
        $database = is_array($section) ? $section : [];
        $created = $this->createdAt($data, $directory);

        return [
            // The directory name, not what the file claims: the name is where
            // the snapshot actually is.
            'id' => $id,
            'created' => $created,
            'expires' => $this->retentionDays > 0 ? $created + $this->retentionDays * self::SECONDS_PER_DAY : null,
            'size' => $this->size($directory),
            // Whether there is a way back in here, which is the one thing the
            // metadata cannot say: it was written before the rest of the
            // snapshot was, and the mark comes off again before a removal
            // touches any of it.
            'complete' => is_file($directory.'/'.self::COMPLETE_FILE),
            'package' => $this->text($data['package'] ?? null),
            'module' => $this->text($data['module'] ?? null),
            'title' => $this->text($data['title'] ?? null),
            'type' => $this->text($data['type'] ?? null),
            'version' => $this->text($data['version'] ?? null),
            'composer' => ($data['composer'] ?? null) === true,
            'reason' => $this->text($data['reason'] ?? null),
            'format' => $this->number($data['format'] ?? null),
            'database' => [
                'driver' => $this->text($database['driver'] ?? null),
                'platform' => $this->text($database['platform'] ?? null),
                'prefix' => $this->text($database['prefix'] ?? null),
            ],
        ];
    }

    /**
     * @param Snapshot $a
     * @param Snapshot $b
     */
    private static function newestFirst(array $a, array $b): int
    {
        return $b['created'] <=> $a['created'];
    }

    /**
     * When a snapshot was taken.
     *
     * The metadata says so, and where it does not, the directory's own
     * modification time stands in: retention has to reach a snapshot whose
     * description never made it to disk, or an interrupted removal would leave
     * bytes nothing ever reclaims.
     *
     * @param array<array-key, mixed> $data
     */
    private function createdAt(array $data, string $directory): int
    {
        $created = $this->number($data['created'] ?? null);

        if ($created > 0) {
            return $created;
        }

        $time = @filemtime($directory);

        return $time !== false ? $time : 0;
    }

    /**
     * What a snapshot's metadata file holds, or nothing where there is none to
     * read.
     *
     * @return array<array-key, mixed>
     */
    private function metadata(string $directory): array
    {
        try {
            $file = $directory.'/'.self::METADATA_FILE;

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

    /**
     * How much disk a snapshot is holding.
     *
     * What is counted is what is in the directory itself: a link is not followed
     * and not counted, because the bytes it points at are somebody else's and
     * purging the snapshot would not reclaim them. A file that cannot be stat-ed
     * counts as nothing rather than failing the listing it is shown in - the
     * number is there to let an operator watch the store grow, and a broken file
     * in it is what the operator would then go and look at.
     */
    private function size(string $directory): int
    {
        $size = 0;

        foreach ($this->files->listDir($directory) as $name) {
            $file = $directory.'/'.$name;

            if (is_link($file)) {
                continue;
            }

            if (is_dir($file)) {
                $size += $this->size($file);

                continue;
            }

            $bytes = @filesize($file);
            $size += $bytes !== false ? $bytes : 0;
        }

        return $size;
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
