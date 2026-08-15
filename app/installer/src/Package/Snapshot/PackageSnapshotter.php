<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package\Snapshot;

use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Package\PackageInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * The snapshots a package removal can be undone from, and the three things that
 * can be done with one.
 *
 * This is the one service the rest of the application talks to about snapshots.
 * The store, the dump and the archive are the parts it puts together; whoever is
 * about to remove a package needs none of them by name. What taking one promises
 * is all-or-nothing: a snapshot that could not be completed is taken off the
 * disk again and reported as no snapshot at all, because the caller is about to
 * destroy the very thing it describes. Where even that cannot be done - the disk
 * that refused the write refusing the deletion as well - what is left is a
 * directory that was never marked as whole, so nothing offers it as a way back
 * and the retention window reclaims it.
 *
 * A snapshot holds three things. What was removed - the package's name, module,
 * version and type. The database it was removed from - every table the
 * installation owns, not only the ones the package created, because a package's
 * rows are spread across the site's content, configuration and permissions. And
 * the package's own files, in the shape they have under packages/. Where
 * Composer installed the package, Composer's record of what it had installed is
 * captured beside them.
 *
 * The dump being of the whole installation is what makes a restore a
 * point-in-time recovery rather than a package-shaped undo: putting one back
 * returns every row to the moment before the removal, and anything written
 * since is not in it. Whoever offers that to an administrator has to say so.
 *
 * Between them the three operations are what makes a removal reversible for as
 * long as it is: taking a snapshot is what a removal does before it destroys
 * anything, restoring one is what undoes that removal, and purging one is the
 * single point at which any of it becomes irreversible - whether an
 * administrator asks for that or the installation has simply kept the snapshot
 * for as long as it keeps one. All three leave a line in the log, because all
 * three are things an administrator will later need to account for.
 *
 * @phpstan-import-type Snapshot from SnapshotStore
 * @phpstan-import-type SnapshotDetails from SnapshotStore
 */
final class PackageSnapshotter
{
    /**
     * What a snapshot was taken for: a package on its way out.
     */
    public const REASON_UNINSTALL = 'uninstall';

    /**
     * Composer's record of what it installed, relative to the directory it
     * installs into.
     */
    private const BOOKKEEPING = 'composer/installed.json';

    /**
     * @param string $packages where runtime-installed packages live, which is
     *                         also where Composer keeps its record of them
     */
    public function __construct(
        private readonly SnapshotStore $store,
        private readonly DatabaseDumper $dumper,
        private readonly DatabaseRestorer $restorer,
        private readonly Filesystem $files,
        private readonly LoggerInterface $log,
        private readonly string $packages,
    ) {
    }

    /**
     * Puts everything a removal is about to take away aside.
     *
     * Taking one is also when the ones nobody needs any more go: the retention
     * window is enforced here rather than by anything running on a timer
     * ({@see purgeExpired()}), so the store is pruned by the same operation that
     * makes it grow.
     *
     * @param  string            $reason why the snapshot is being taken, {@see REASON_UNINSTALL}
     * @return string            the id the snapshot is addressed by from here on
     * @throws \RuntimeException where any part of the snapshot could not be written.
     *                          What was written is discarded, what cannot be
     *                          discarded is nothing anybody can restore from
     *                          ({@see discard()}), and the caller may not remove what
     *                          it asked to have put aside
     */
    public function create(PackageInterface $package, string $reason): string
    {
        // Both of these run before the snapshot directory exists: an
        // installation on a database no dump can be taken of costs nothing but
        // the answer, and what Composer has on record is read while its record
        // is still the live one.
        $composer = $this->composerInstalled($package->getName());
        $details = $this->details($package, $reason, $composer);

        // There is a snapshot to write, so whatever the retention window has
        // run out on goes before it does: snapshots nobody has reclaimed are
        // the disk a new one needs, and reclaiming them afterwards would be
        // reclaiming it too late.
        $this->purgeExpired();

        $id = $this->store->create($details);

        try {
            $this->dumper->dump($this->store->dumpFile($id));
            $this->archive($package, $id);

            if ($composer) {
                $this->bookkeeping($id);
            }

            // Last, because this is the step that turns a directory of files
            // into something a package can be brought back out of. Everything
            // above it is there in some form from the moment it starts being
            // written.
            $this->store->complete($id);
        } catch (\Throwable $e) {
            $this->discard($id, $package);

            throw new \RuntimeException(
                sprintf('Failed to take a snapshot of package "%s".', $package->getName()),
                0,
                $e,
            );
        }

        $this->audit(
            sprintf('Snapshot "%s" of package "%s" taken for %s.', $id, $package->getName(), $reason),
            ['snapshot' => $id, 'package' => $package->get('module'), 'reason' => $reason],
        );

        return $id;
    }

    /**
     * Puts the installation back the way the snapshot found it.
     *
     * Destructive, and destructive in a way the removal it undoes was not: the
     * dump is of the whole database, so every row written since the snapshot was
     * taken - a page, a comment, a user who signed up - is replaced by what was
     * there before. Whoever offers this to an administrator has to say that in
     * as many words.
     *
     * The files go back first and the database after them, because that is the
     * order in which the installation is coherent at every point in between: a
     * package tree nothing enables is a package the panel lists as not
     * installed, while a database naming an enabled extension whose files are
     * not there is a boot that fails. So a restore that puts the files back and
     * then cannot apply the dump reports the failure and leaves the snapshot
     * exactly where it is - running it again is what finishes the job.
     *
     * Composer's record of what it had installed is not written back. The
     * captured copy describes the installation as it was and the live one
     * describes it as it is, so overwriting the second with the first would take
     * every package installed since off Composer's books. It stays in the
     * snapshot for whoever reconciles the two.
     *
     * A restored snapshot is still a snapshot: nothing here destroys it, so the
     * same one can be replayed again until it is purged.
     *
     * What is refused outright is a snapshot the store does not mark as whole.
     * One is in the inventory like any other and may well hold a dump and part
     * of a package tree, which is precisely the danger: the mark is off both
     * where a write was interrupted and where a removal was, so neither the
     * dump nor the tree says how much of itself is still there. Applying that
     * would write a whole database back over the installation to reinstate
     * files that may be half gone.
     *
     * What the running process is holding is not restored with the database. The
     * configuration it read at boot, the modules it loaded and any cache it
     * built are all from before, so whoever calls this finishes by starting the
     * installation over rather than by carrying on with it.
     *
     * @throws \InvalidArgumentException where no snapshot goes by this id
     * @throws \RuntimeException         where the snapshot is not one anything can be
     *                                  restored from, or the restore could not be applied
     */
    public function restore(string $id): void
    {
        $snapshot = $this->snapshot($id);

        if (!$snapshot['complete']) {
            throw new \RuntimeException(sprintf(
                'Snapshot "%s" is not marked as whole, so what is in it is not the installation it describes.',
                $id,
            ));
        }

        $dump = $this->store->dumpFile($id);

        if (!is_file($dump)) {
            throw new \RuntimeException(sprintf('Snapshot "%s" holds no database dump, so there is nothing in it to put back.', $id));
        }

        $trees = $this->archived($id);

        if ($trees === []) {
            throw new \RuntimeException(sprintf('Snapshot "%s" holds no package files, so there is nothing in it to put back.', $id));
        }

        foreach ($trees as $tree) {
            $this->reinstate($id, $tree);
        }

        $summary = $this->restorer->restore($dump);

        $this->audit(
            sprintf(
                'Snapshot "%s" of package "%s" restored: its files are back under packages/, and the database is as it was when the snapshot was taken (%d tables, %d rows).',
                $id,
                $snapshot['package'],
                $summary['tables'],
                $summary['rows'],
            ),
            [
                'snapshot' => $id,
                'package' => $snapshot['module'],
                'tables' => $summary['tables'],
                'rows' => $summary['rows'],
            ],
        );
    }

    /**
     * Destroys a snapshot and everything in it.
     *
     * The one operation here that cannot be undone. Up to this point a removed
     * package was only put aside; afterwards its files, the dump of the database
     * it was removed from and the description of both are gone, and nobody can
     * bring that package back. Which is why what was destroyed and what asked
     * for it go on the record.
     *
     * @throws \InvalidArgumentException where no snapshot goes by this id
     * @throws \RuntimeException         where the snapshot could not be removed. The
     *                                  store either left it untouched or got part of
     *                                  the way through it, and only the first of
     *                                  those is still a way back
     */
    public function purge(string $id): void
    {
        $snapshot = $this->snapshot($id);

        if (!$this->store->delete($id)) {
            throw new \RuntimeException(sprintf(
                'Snapshot "%s" could not be removed. Either none of it could be, or the removal stopped somewhere in the tree - and then what is left is no longer something a package can be restored from.',
                $id,
            ));
        }

        $this->audit(
            sprintf('Snapshot "%s" of package "%s" purged on request: what it held is not recoverable.', $id, $snapshot['package']),
            ['snapshot' => $id, 'package' => $snapshot['module'], 'trigger' => 'request'],
        );
    }

    /**
     * Destroys the snapshots that have been kept for as long as the
     * installation keeps them.
     *
     * This is the whole of what stops a store of retained packages from growing
     * until it fills the disk, and it is deliberately not a schedule: it runs
     * when a snapshot is taken and when an administrator asks for it, so an
     * installation that does neither keeps everything. For an operation nobody
     * can undo, keeping too much is the direction to err in.
     *
     * As final as {@see purge()}, and on the record the same way, one line per
     * snapshot. A snapshot that will not go is reported and left rather than
     * raised: this runs while a new snapshot is being taken, and disk that could
     * not be reclaimed may not cost the way back that is being written.
     *
     * @return list<string> the ids that are gone, in the order the store listed them
     */
    public function purgeExpired(): array
    {
        $purged = [];

        foreach ($this->store->expired() as $id) {
            $snapshot = $this->store->get($id);

            // Gone between being listed and being read: another purge, or a
            // hand on the store. Either way there is nothing left to destroy
            // and nothing this call did.
            if ($snapshot === null) {
                continue;
            }

            if (!$this->store->delete($id)) {
                $this->reportUnreclaimed($id);

                continue;
            }

            $purged[] = $id;

            $this->audit(
                sprintf(
                    'Snapshot "%s" of package "%s" purged after its retention window: what it held is not recoverable.',
                    $id,
                    $snapshot['package'],
                ),
                ['snapshot' => $id, 'package' => $snapshot['module'], 'trigger' => 'retention'],
            );
        }

        return $purged;
    }

    /**
     * Every snapshot there is, newest first.
     *
     * What each one says about itself, and what only the store can say: how much
     * disk it is holding, when it may be reclaimed, and whether it is a way back
     * at all. The first two are what an operator watching the store grow has to
     * go on, since nothing here caps it; the last is what keeps what an
     * interrupted write or an interrupted removal left behind from being
     * offered as a package that can be brought back.
     *
     * No part of the dump, ever. It is the site's whole database - every
     * password hash on it included - and whoever is looking at a list of
     * snapshots is choosing one, not reading one.
     *
     * @return array<string, Snapshot> keyed by id
     */
    public function list(): array
    {
        return $this->store->list();
    }

    /**
     * Takes a snapshot that could not be finished back off the disk.
     *
     * Half a snapshot is worse than none while it is there: it holds a dump of
     * the whole database beside whatever part of the package the write got to,
     * and applying that pair would replace the installation to reinstate files
     * that are not all there. So it goes, and the caller is told it has no
     * snapshot.
     *
     * A directory that will not go is not that danger - it was never marked as
     * whole, so no restore reads it and no operator is offered it - but it is
     * disk nobody asked to spend, held until its retention window runs out.
     * Which is the whole of what this line is for: the removal it was taken for
     * is being called off in the same breath, and failing that a second time
     * over bytes would tell an administrator nothing they can act on.
     */
    private function discard(string $id, PackageInterface $package): void
    {
        if ($this->store->delete($id)) {
            return;
        }

        $this->audit(
            sprintf(
                'The snapshot "%s" of package "%s" could not be taken, and what had been written of it could not be removed either. Nothing can be restored from it; the disk it holds is reclaimed when its retention window runs out.',
                $id,
                $package->getName(),
            ),
            ['snapshot' => $id, 'package' => $package->get('module'), 'trigger' => 'create'],
            LogLevel::WARNING,
        );
    }

    /**
     * The snapshot an operation was asked to act on.
     *
     * @return Snapshot
     * @throws \InvalidArgumentException where none goes by this id, an id that is
     *                                  not one included. The value stays out of the
     *                                  message: it comes from a request
     */
    private function snapshot(string $id): array
    {
        $snapshot = $this->store->get($id);

        if ($snapshot === null) {
            throw new \InvalidArgumentException('No snapshot goes by this id.');
        }

        return $snapshot;
    }

    /**
     * The package trees a snapshot holds, as the vendor/name directories they go
     * back into.
     *
     * Read off the archive rather than out of the metadata, for the same reason
     * the archive was written that way: what can be restored is what was
     * actually put aside, and a name a package gave itself has no business
     * deciding where files land.
     *
     * @return list<string>
     */
    private function archived(string $id): array
    {
        $root = $this->store->filesDirectory($id);

        if (!is_dir($root)) {
            return [];
        }

        $trees = [];

        foreach ($this->files->listDir($root) as $vendor) {
            if (!is_dir($root.'/'.$vendor)) {
                continue;
            }

            foreach ($this->files->listDir($root.'/'.$vendor) as $name) {
                if (is_dir($root.'/'.$vendor.'/'.$name)) {
                    $trees[] = $vendor.'/'.$name;
                }
            }
        }

        return $trees;
    }

    /**
     * Puts one archived tree back where packages/ expects it.
     *
     * Whatever is at the target now is removed rather than copied over: a
     * package reinstalled since the snapshot has files this one never had, and
     * merging the two would leave a tree that is neither version. Where the
     * removal succeeds and the copy does not, the archive is untouched in the
     * snapshot, so the restore can be run again.
     *
     * @param  string            $tree the vendor/name directory, as the archive holds it
     * @throws \RuntimeException where the tree could not be put back
     */
    private function reinstate(string $id, string $tree): void
    {
        $target = $this->livePath($tree);

        if (is_dir($target) && !$this->files->delete($target)) {
            throw new \RuntimeException(sprintf(
                'The files of "%s" that are on disk now could not be removed, so the ones in snapshot "%s" were left where they are.',
                $tree,
                $id,
            ));
        }

        if (!$this->files->copyDir($this->store->filesDirectory($id).'/'.$tree, $target)) {
            throw new \RuntimeException(sprintf('Failed to put the files of "%s" back from snapshot "%s".', $tree, $id));
        }
    }

    /**
     * What the snapshot says about itself.
     *
     * @param  bool              $composer whether Composer is what installed the package
     * @return SnapshotDetails
     * @throws \RuntimeException where the installation is on a database no dump can be taken of
     */
    private function details(PackageInterface $package, string $reason, bool $composer): array
    {
        return [
            'package' => $package->getName(),
            'module' => $this->text($package->get('module')),
            'title' => $this->text($package->get('title')),
            'type' => $package->getType(),
            'version' => $this->text($package->get('version')),
            'composer' => $composer,
            'reason' => $reason,
            'format' => DumpFormat::VERSION,
            'database' => $this->dumper->describe(),
        ];
    }

    /**
     * Copies the package's own files into the snapshot.
     *
     * They keep the shape they have under packages/ - a vendor directory with
     * the package directory below it - and that shape is taken from where the
     * files actually are rather than from the name in the manifest: the name is
     * a package's own text, and text out of a package has no business naming a
     * path inside the store.
     *
     * @throws \RuntimeException where the files are not there or could not be copied
     */
    private function archive(PackageInterface $package, string $id): void
    {
        $path = $package->get('path');

        if (!is_string($path) || $path === '' || !is_dir($path)) {
            throw new \RuntimeException(sprintf(
                'The files of package "%s" are not at the path it names, so there is nothing to archive.',
                $package->getName(),
            ));
        }

        $target = sprintf(
            '%s/%s/%s',
            $this->store->filesDirectory($id),
            basename(dirname($path)),
            basename($path),
        );

        if (!$this->files->copyDir($path, $target)) {
            throw new \RuntimeException(sprintf('Failed to archive the files of package "%s".', $package->getName()));
        }
    }

    /**
     * Captures Composer's record of what it had installed.
     *
     * Copied rather than written out of what was read for the metadata, so that
     * what lands in the snapshot is the file itself and not this class's
     * understanding of it. Only ever asked for a package the record names, so a
     * record that has since gone is a snapshot that would be missing half of
     * what Composer needs to be put back - and that fails the snapshot.
     *
     * @throws \RuntimeException where the record could not be copied
     */
    private function bookkeeping(string $id): void
    {
        if (!$this->files->copy($this->bookkeepingFile(), $this->store->installedFile($id))) {
            throw new \RuntimeException('Failed to capture Composer\'s record of the installed packages.');
        }
    }

    /**
     * Whether Composer is what put the package under packages/.
     *
     * Read out of Composer's own record rather than asked of Composer itself:
     * the question is whether there is bookkeeping to capture, and that record
     * is the bookkeeping. A record that is missing or cannot be read answers no
     * - the same answer the removal path gets when it asks whether Composer has
     * to be told, so the two cannot disagree about one package.
     */
    private function composerInstalled(string $name): bool
    {
        $file = $this->bookkeepingFile();

        if (!is_file($file)) {
            return false;
        }

        $content = @file_get_contents($file);

        if ($content === false) {
            return false;
        }

        try {
            $installed = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!is_array($installed)) {
            return false;
        }

        foreach ($installed as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    private function bookkeepingFile(): string
    {
        return $this->livePath(self::BOOKKEEPING);
    }

    /**
     * Where something lives in the running installation, as opposed to in a
     * snapshot of it.
     */
    private function livePath(string $relative): string
    {
        return rtrim($this->packages, '/\\').'/'.$relative;
    }

    /**
     * Reports a snapshot the retention window could not reclaim.
     *
     * Expired and still on the disk. Either nothing of it could be removed, and
     * then it is the way back it was, or the removal stopped somewhere in the
     * tree - and then the store has already taken the mark off, so nothing
     * offers what is left as one. Neither is a reason to fail: this runs while
     * a new snapshot is being written, and disk that could not be handed back
     * may not cost the way back being made in its place. What it is a reason
     * for is a line an operator reads, because reclaiming those bytes is theirs
     * to do from here.
     */
    private function reportUnreclaimed(string $id): void
    {
        $this->audit(
            sprintf('Snapshot "%s" is past its retention window but could not be removed, so the disk it holds is not reclaimed.', $id),
            ['snapshot' => $id, 'trigger' => 'retention'],
            LogLevel::WARNING,
        );
    }

    /**
     * Records what happened to a snapshot, and to what.
     *
     * The trail of a destructive operation, for the administrator who comes
     * looking weeks later. A log that cannot take the line does not cost the
     * operation: the store's own inventory is what says which snapshots exist,
     * and refusing here would refuse a removal or a restore over a log entry.
     *
     * @param array<string, mixed> $context
     */
    private function audit(string $message, array $context, string $level = LogLevel::NOTICE): void
    {
        try {
            $this->log->log($level, $message, $context);
        } catch (\Throwable) {
            // Nothing is left that could take the line, and what it would have
            // described has happened regardless.
        }
    }

    /**
     * A manifest value as the metadata carries it.
     *
     * What a package says about itself is a package's own business, and a key
     * it left out or filled with something other than text is not worth failing
     * a snapshot over: the metadata describes the snapshot, the directory name
     * is what addresses it.
     */
    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
