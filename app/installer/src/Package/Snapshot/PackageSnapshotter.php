<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package\Snapshot;

use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Package\PackageInterface;
use Psr\Log\LoggerInterface;

/**
 * Takes the snapshot a package removal can be undone from.
 *
 * This is the one service the rest of the application talks to about snapshots.
 * The store, the dump and the archive are the parts it puts together; whoever is
 * about to remove a package needs none of them by name. What it promises is
 * all-or-nothing: a snapshot that could not be completed is taken off the disk
 * again and reported as no snapshot at all, because the caller is about to
 * destroy the very thing it describes.
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
        private readonly Filesystem $files,
        private readonly LoggerInterface $log,
        private readonly string $packages,
    ) {
    }

    /**
     * Puts everything a removal is about to take away aside.
     *
     * @param  string            $reason why the snapshot is being taken, {@see REASON_UNINSTALL}
     * @return string            the id the snapshot is addressed by from here on
     * @throws \RuntimeException where any part of the snapshot could not be written.
     *                          Nothing of it is left behind, and the caller may not
     *                          remove what it asked to have put aside
     */
    public function create(PackageInterface $package, string $reason): string
    {
        // Both of these run before the snapshot directory exists: an
        // installation on a database no dump can be taken of costs nothing but
        // the answer, and what Composer has on record is read while its record
        // is still the live one.
        $composer = $this->composerInstalled($package->getName());
        $details = $this->details($package, $reason, $composer);

        $id = $this->store->create($details);

        try {
            $this->dumper->dump($this->store->dumpFile($id));
            $this->archive($package, $id);

            if ($composer) {
                $this->bookkeeping($id);
            }
        } catch (\Throwable $e) {
            // Half a snapshot is worse than none: it would be listed as
            // something a package can be restored from, and it is not. What is
            // left where even this fails is a directory with no dump in it,
            // which a restore refuses and retention reclaims.
            $this->store->delete($id);

            throw new \RuntimeException(
                sprintf('Failed to take a snapshot of package "%s".', $package->getName()),
                0,
                $e,
            );
        }

        $this->audit($id, $package, $reason);

        return $id;
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
        return rtrim($this->packages, '/\\').'/'.self::BOOKKEEPING;
    }

    /**
     * Records that a snapshot was taken, of what, and what asked for it.
     *
     * The trail of a destructive operation, for the administrator who comes
     * looking weeks later. A log that cannot take the line does not cost the
     * snapshot: the store's own inventory is what says the snapshot exists, and
     * refusing here would refuse the removal it was taken for.
     */
    private function audit(string $id, PackageInterface $package, string $reason): void
    {
        try {
            $this->log->notice(
                sprintf('Snapshot "%s" of package "%s" taken for %s.', $id, $package->getName(), $reason),
                ['snapshot' => $id, 'package' => $package->get('module'), 'reason' => $reason],
            );
        } catch (\Throwable) {
            // Nothing is left that could take the line, and the snapshot it
            // would have described is on disk regardless.
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
