<?php

declare(strict_types=1);

namespace Pagekit\Installer\Controller;

use Pagekit\Installer\Package\Snapshot\PackageSnapshotter;
use Pagekit\Installer\Package\Snapshot\SnapshotStore;
use Pagekit\Log\Logger;
use Pagekit\Module\ModuleInterface;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;
use Pagekit\User\Attribute\Access;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The snapshots removals left behind, and what an administrator can do with one.
 *
 * A snapshot is the whole of the way back from a removal, and it is also the
 * site's entire database as it stood - every password hash on it included. Both
 * halves of that shape this class. Nothing here is reachable without a session
 * that may manage packages, and nothing here hands any of a snapshot's contents
 * back: the listing is what the snapshots say about themselves and how much disk
 * they hold, never a line of the dump.
 *
 * The id is the one part of a snapshot's address a request gets to choose, so it
 * is never used to build a path. Every action looks it up among the snapshots
 * the store actually lists, and an id that is not one of them is refused before
 * anything reads the disk on its behalf.
 *
 * @phpstan-import-type Snapshot from SnapshotStore
 */
#[Access('system: manage packages', admin: true)]
class SnapshotController
{
    /**
     * @param PackageSnapshotter|null $snapshotter what a removed package can be restored
     *                                             from, and null in an installation that
     *                                             keeps no snapshots at all
     */
    public function __construct(
        private readonly ModuleManager $module,
        private readonly Logger $log,
        private readonly ?PackageSnapshotter $snapshotter = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function indexAction(): array
    {
        return [
            '$view' => [
                'title' => __('Snapshots'),
                'name' => 'installer:views/snapshots.php',
            ],
            '$data' => [
                // A list rather than the map the store keys by id: the order is
                // newest first and worth keeping, which an object would not.
                'snapshots' => array_values($this->snapshotter?->list() ?? []),
                'retention' => $this->retentionDays(),
            ],
        ];
    }

    /**
     * Puts one snapshot back over the installation.
     *
     * Destructive in a way the removal it undoes was not, which is why the
     * confirm it comes from says so: the dump is of the whole database, so every
     * row written since the snapshot was taken is replaced by what was there
     * then. This end of it only checks that the request may do that and reports
     * what happened.
     *
     * @return array<string, mixed>
     */
    #[Route(methods: ['POST'])]
    #[Request(['id' => 'string'], csrf: true)]
    public function restoreAction(string $id = ''): array
    {
        $snapshot = $this->snapshot($id);

        try {
            $this->snapshotter()->restore($id);
        } catch (\Throwable $e) {
            return $this->failed(sprintf('Snapshot "%s" could not be restored', $id), $e, __(
                '"%name%" could not be restored. See the error log for details.',
                ['%name%' => $this->label($snapshot)]
            ));
        }

        // The same clear enabling and disabling do, and for more reason than
        // either: what the panel and the site load is cached, and this replaced
        // the configuration both of them were built from.
        $this->module->get('system/cache')->clearCache();

        return ['message' => 'success'];
    }

    /**
     * Destroys one snapshot.
     *
     * The only thing here that cannot be undone: afterwards the package it held
     * is not restorable by anybody. What was destroyed is on the record, written
     * by the snapshotter itself.
     *
     * @return array<string, mixed>
     */
    #[Route(methods: ['POST'])]
    #[Request(['id' => 'string'], csrf: true)]
    public function purgeAction(string $id = ''): array
    {
        $snapshot = $this->snapshot($id);

        try {
            $this->snapshotter()->purge($id);
        } catch (\Throwable $e) {
            return $this->failed(sprintf('Snapshot "%s" could not be purged', $id), $e, __(
                'The snapshot of "%name%" could not be purged. See the error log for details.',
                ['%name%' => $this->label($snapshot)]
            ));
        }

        // Nothing the installation loads was in that directory, so there is
        // nothing cached to clear - unlike a restore, which rewrites the
        // configuration the panel was built from.
        return ['message' => 'success'];
    }

    /**
     * Reclaims the snapshots that have been kept for as long as this
     * installation keeps them.
     *
     * The same window a new snapshot enforces on its way in, on demand: nothing
     * runs on a timer, so an installation that removes no further packages keeps
     * what it has until somebody asks here.
     *
     * @return array<string, mixed>
     */
    #[Route('/purge-expired', name: 'purge-expired', methods: ['POST'])]
    #[Request(csrf: true)]
    public function purgeExpiredAction(): array
    {
        // A snapshot that would not go is reported by the snapshotter and left
        // where it is, so this is the list of what is actually gone.
        return ['message' => 'success', 'purged' => $this->snapshotter()->purgeExpired()];
    }

    /**
     * The snapshot an action was asked to act on.
     *
     * Looked up among the ids the store lists rather than resolved from the one
     * that arrived, so text out of a request never reaches the filesystem as a
     * path. An id nothing goes by is a bad request whether it is malformed, a
     * traversal or simply a snapshot somebody else purged first; the value stays
     * out of the answer either way.
     *
     * @return Snapshot
     * @throws BadRequestHttpException where no snapshot in this installation goes by the id
     */
    private function snapshot(string $id): array
    {
        $snapshots = $this->snapshotter?->list() ?? [];

        if (!isset($snapshots[$id])) {
            throw new BadRequestHttpException(__('No snapshot goes by this id.'));
        }

        return $snapshots[$id];
    }

    /**
     * The snapshots of this installation, for an action that is about to change
     * them.
     *
     * @throws BadRequestHttpException where the installation keeps none, which is
     *                                 an installation with nowhere to keep them or
     *                                 no database to dump into one
     */
    private function snapshotter(): PackageSnapshotter
    {
        if ($this->snapshotter === null) {
            throw new BadRequestHttpException(__('This installation keeps no snapshots.'));
        }

        return $this->snapshotter;
    }

    /**
     * Reports an operation that did not do what it said, without saying more
     * than the panel has any business showing.
     *
     * What refused is usually a path on the disk or a table in the database, and
     * that belongs in the log where an administrator reads it deliberately. The
     * answer says which snapshot, what it was for and where the rest of it is.
     *
     * @param  string              $context what was being attempted, for the log
     * @param  string              $message what the administrator is told
     * @return array<string, mixed>
     */
    private function failed(string $context, \Throwable $e, string $message): array
    {
        try {
            $this->log->error(sprintf('%s: %s', $context, $e->getMessage()), ['exception' => $e]);
        } catch (\Throwable) {
            // The administrator still has to be told that it did not happen,
            // which a log that cannot take the line does not get to prevent.
        }

        return ['error' => true, 'message' => $message];
    }

    /**
     * How long this installation keeps a snapshot, for a page that has to say
     * when the ones it lists may be reclaimed.
     */
    private function retentionDays(): int
    {
        $installer = $this->module->get('installer');

        return $installer instanceof ModuleInterface
            ? SnapshotStore::retentionDays($installer->config('snapshots.retention_days'))
            : SnapshotStore::DEFAULT_RETENTION_DAYS;
    }

    /**
     * What a snapshot is called where an administrator is being told about it.
     *
     * The title the package gave itself, which is what the panel lists it as,
     * and its package name where it gave none.
     *
     * @param Snapshot $snapshot
     */
    private function label(array $snapshot): string
    {
        return $snapshot['title'] !== '' ? $snapshot['title'] : $snapshot['package'];
    }
}
