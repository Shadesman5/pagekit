# Step 2.7.1 — Snapshot & Three-Stage Uninstall

<!-- Branch doc for Roadmap Step 2.7.1.
     Path: migration-docs/branches/phase-2/step-2-7-1-snapshot-three-stage-uninstall.md -->

**Branch:** `feature/snapshot-three-stage-uninstall`
**ROADMAP Step:** 2.7.1 (Snapshot & Three-Stage Uninstall)
**GitHub Issue:** [#267](https://github.com/Shadesman5/pagekit/issues/267)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-08-14 13:56
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### Snapshot store + `path.snapshots` (Checklist Step 1)

No uninstall consumer yet — removal still deletes as today. The store is the directory layout, the id, and the metadata write; dumps and archives land in later steps.

| File | Change |
|---|---|
| `app/installer/src/Package/Snapshot/SnapshotStore.php` (new) | New final `Pagekit\Installer\Package\Snapshot\SnapshotStore`, constructed from a directory path, the `Filesystem` service (Step 2.6 `dumpAtomic()`), and an optional retention window (default 30 days; zero or less keeps every snapshot until an explicit purge). API: `create(details): string` (reserves a 0700 directory, writes `metadata.json` atomically at 0600, returns the id), `get(id): ?array`, `list(): array` (newest first, keyed by id), `expired(): array` (ids only — no size walk), `delete(id): bool`, `directory(id)` / `dumpFile(id)` / `filesDirectory(id)` (paths for later dump/archive writers; throw `\InvalidArgumentException` when the id is not one or names nothing in the store), `isValidId(id): bool`. Layout constants: `metadata.json`, `db.dump`, `files/`. Id shape `<Ymd-His>-<slug>-<8 hex>` in UTC; allowlist `^[A-Za-z0-9][A-Za-z0-9._-]*\z` plus a 128-char cap. A create that cannot write metadata deletes the reserved directory before throwing. |
| `public/index.php` | New `path.snapshots` key in `$config` (`$path.'/tmp/snapshots'`), same posture as `path.system`: private, never DocRoot, never swept by cache/temp clear. Console and installer already boot through this file. |
| `docker/entrypoint.sh` | `tmp/snapshots` added to the `mkdir -p` list the container recreates on every start. |
| `tmp/snapshots/.htaccess` (new) | `Require all denied` — belt for an install whose document root was pointed at the application tree rather than `public/`. |
| `tmp/snapshots/.gitignore` (new) | Ignores everything written into the directory except its own `.htaccess`/`.gitignore`. |

### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `tests/Unit/Snapshot/SnapshotStoreTest.php` (new) | Real-filesystem coverage (per-test temp dir) of `SnapshotStore`: first `create()` makes the store directory; ids carry UTC time + slugged module + a random half so two snapshots of the same module do not collide; a module name that is not an id is reduced to one rather than costing the snapshot; metadata is JSON (never executable PHP) written through `dumpAtomic()` at 0600 with the directory at 0700; a title that is not valid UTF-8 is substituted; a metadata write that throws, a store path that cannot be written, and a path already occupied by a file each leave no snapshot behind; an empty store reads as empty without creating itself; `.htaccess`/`.gitignore` and non-directories are not inventoried; a snapshot whose metadata is missing, unreadable, or foreign-shaped stays on the inventory (id + mtime) so retention can still reclaim it; typed fields are sanitized rather than passed through; expiry is counted from created-at (default 30 days; `<= 0` expires nothing; expired ids are found even without readable metadata); `delete()` is false when there was nothing to remove or the removal only got part of the way; traversal / separator / absolute / null-byte / newline ids are refused before they are a path, and the refused value is not echoed in the exception; dump and archive paths are named only for an id that exists; size is the tree on disk, not what a symlink points at. Root-guarded permission tests skip under `posix_geteuid() === 0`. |
| `tests/Unit/Snapshot/SnapshotPathWiringTest.php` (new) | Reads `path.snapshots` out of `public/index.php` without booting the app: the key is absolute and under the install root; it is not inside `path.temp`, `path.cache`, `path.public`, or `path.storage`; the shipped directory exists with `Require all denied` and an ignore that keeps the two guards. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### DB dump + restore (Checklist Step 2)

No uninstall consumer yet — the dumper and restorer are the primitive a later snapshotter will call. One JSON-lines format, one writer, one reader; SQLite round-trip is what every PHPUnit run exercises, MySQL the same tests against `DbUtil` connection globals (advisory `phpunit-mysql` CI leg).

| File | Change |
|---|---|
| `app/installer/src/Package/Snapshot/DumpFormat.php` (new) | Versioned JSON-lines layout (`header` / `table` / `row` / `end`, `VERSION = 1`). Family-level platform (`sqlite` vs `mysql`, MariaDB counted as MySQL); anything else throws before a dump exists. Rows are positional against the preceding table's columns. Non-UTF-8 column values travel as `{b64: …}` rather than replacement characters; floats decode through `var_export` so they rebind with the digits they came out with. `json_encode` does not substitute — a value JSON cannot carry fails the dump. |
| `app/installer/src/Package/Snapshot/DatabaseDumper.php` (new) | Prefix-scoped introspection via the existing DBAL connection (empty prefix = whole database); platform DDL with indexes and FKs; rows streamed, never assembled. Tables sorted by name so two dumps of an unchanged database compare. Written to `<file>.part` at 0600 and renamed onto the restore name only after the `end` record is flushed; a mid-dump failure unlinks the staging file and leaves nothing at the restore name. Unknown platform or unread schema throws before any file is opened. |
| `app/installer/src/Package/Snapshot/DatabaseRestorer.php` (new) | Reads the dump end-to-end (format, platform, prefix, completeness, prefix-scoped table names) before a single `DROP`. Then drops and recreates each dumped table and re-inserts rows with FK checks off; tables the dump does not name are left alone. SQLite wraps apply in a transaction (failed restore rolls back); MySQL does not (DDL auto-commits — retry is the recovery). An empty dump is refused. `strings()` rebuilds a real `list<string>` rather than passing a mixed array through (PHPStan level 8). |

### Tests (Checklist Step 2)

| File | Change |
|---|---|
| `tests/Unit/Snapshot/DumpFormatTest.php` (new) | Family naming (SQLite / MySQL 8.0 / 8.4 / MariaDB vs PostgreSQL / Oracle / SQL Server / DB2 refused); describe records driver + platform + prefix (empty prefix is empty, not guessed); a line is one JSON object plus newline, paths unescaped; encode/decode round-trip for scalars, tagged bytes, a stream handle read rather than recorded, floats rebound with every digit; a value JSON cannot carry, a binary that will not decode, and a shape the format does not define each throw rather than substitute. |
| `tests/Unit/Snapshot/DatabaseDumperTest.php` (new) | Real-database coverage via `SnapshotDatabase`: prefix-scoped tables only (no prefix = every table); header names driver/platform/prefix and format 1; each table carries platform DDL + column list, then one row per row with one value per column; bytes stay bytes, text stays text; table order is the dump's (a connection that lists tables backwards still writes the same dump); two dumps of an unchanged database are the same file; the restore name does not exist until rename, a database that stops answering mid-table leaves the workspace empty, a blocked rename cleans the staging file; 0600 on the finished dump (root-guarded skip); unknown platform refuses before any file is made. |
| `tests/Unit/Snapshot/DatabaseRestorerTest.php` (new) | Round-trip of int/string/text/bool/datetime/float/blob/null columns, bytes that would otherwise become replacement characters, and a float's digits; post-dump writes to dumped tables are gone, tables the dump does not name (including ones created after) stay; a dropped table is put back; a child table is restored before its parent without the FK refusing it; FK enforcement is restored to whatever it was. Driver mismatch, a table outside the prefix, a missing file, a truncated/wrong-version/empty/foreign-shaped dump are each refused while the live tables are still there. A failed apply is recovered by running restore again; on SQLite a failed apply also leaves the database as it was (skipped on MySQL). |
| `tests/Unit/Snapshot/SnapshotDatabase.php` (new) | Shared trait over `DbUtil`: in-memory SQLite unless the connection globals name a real server, then the same tests empty and reopen that database. Per-test connections, prefix default `pk_`. |
| `tests/Unit/Snapshot/ConnectionOnAnUnsupportedDatabase.php` (new) | Connection whose platform is PostgreSQL — the installation that cannot be dumped or restored. |
| `tests/Unit/Snapshot/ConnectionThatStopsAnswering.php` (new) | Connection that throws on the query for a named column, after the dump has already written a header and a whole table. |
| `tests/Unit/Snapshot/ConnectionThatListsTablesBackwards.php` (new) | Connection that reverses the catalogue `ORDER BY`, so dump order is proven independent of the driver's list order. |

Gates: Verifier (production) PASS; Tester FAIL once (PHPStan `list<string>` vs `array` at `DatabaseRestorer::apply`) → refactorer retry → PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Snapshot-before-uninstall + console fix (Checklist Step 3)

Uninstall still hard-deletes after a successful snapshot — restore, purge, and the soft-uninstall stage are later steps. What this step adds is the gate: a snapshot is taken first, a failure aborts with the package untouched, and `php pagekit uninstall` actually constructs a manager.

| File | Change |
|---|---|
| `app/installer/src/Package/Snapshot/PackageSnapshotter.php` (new) | New final `Pagekit\Installer\Package\Snapshot\PackageSnapshotter` — the one service a removal talks to. Constructor-injected store, dumper, `Filesystem`, logger, and `path.packages`. `create(package, reason): string` writes metadata, streams the dump, archives the package tree via `copyDir()`, and (when Composer installed the package) copies `packages/composer/installed.json` beside the archive. Any part that throws deletes the reserved directory before rethrowing a `\RuntimeException` that names the package, not the path. Archive layout is `files/<basename(dirname(path))>/<basename(path)>/` — the on-disk tree, never the manifest name. `REASON_UNINSTALL` is the only reason this step writes. No `restore()`/`purge()`/`list()` yet. A log that cannot take the audit line does not cost the snapshot. |
| `app/installer/src/Package/Snapshot/SnapshotStore.php` | New `INSTALLED_FILE` (`installed.json`) and `installedFile(id)` path accessor, same id-guard as the dump/archive paths. The file sits at the snapshot root, not under `files/`: it describes the whole Composer installation, not the one package. |
| `app/installer/index.php` | `snapshotter` registered only when both `path.snapshots` and `db` are present. Absence of the id is the whole answer a caller gets for "this environment never had a store". |
| `app/installer/src/Package/PackageManager.php` | `uninstall()` calls `snapshot()` as its first action per package, before `disable()`. No `snapshotter` id → one warning log line and the removal proceeds. A present service that is not a `PackageSnapshotter`, fails to resolve, or whose `create()` throws → `\RuntimeException` streamed as "nothing was removed" (title, no disk path) with the cause chained for the log. Success writes `Snapshot %id% taken.` to the command output. |
| `app/console/src/Commands/UninstallCommand.php` | `new PackageManager($output)` (TypeError: `OutputInterface` where `ContainerInterface` is required) becomes `new PackageManager($this->container, $output)`. Console uninstall now shares the same snapshot-first pipeline as the panel. |
| `phpstan-baseline.neon` | The `UninstallCommand` `argument.type` ignore for that TypeError is deleted — the call site matches the constructor. No new baseline entries. |

### Tests (Checklist Step 3)

| File | Change |
|---|---|
| `tests/Unit/Snapshot/PackageSnapshotterTest.php` (new) | Real store + real database: a snapshot holds metadata (package/module/title/type/version/reason/format/driver/platform/prefix) plus a complete dump (`header` … `end`, installation tables not package-only) plus the on-disk tree; a manifest name that is a traversal, an absolute path, or empty still archives under the real `vendor/name` and writes nothing beside the snapshot; Composer bookkeeping is captured iff `Composer::isInstalled()` would say so (named / other / empty / truncated / non-JSON / missing record); a failed copy of `installed.json`, a missing package path, an unsupported database, and a dump that stops mid-table each leave the store empty and write no audit line; a taken snapshot is logged with id + package + reason; a logger that throws does not cost the snapshot. |
| `tests/Unit/Snapshot/SnapshotServiceWiringTest.php` (new) | Boots the installer module definition against a real container: `path.snapshots` + `db` register a `PackageSnapshotter` whose `create()` lands metadata, dump, archive, and captured bookkeeping under that path; missing `path.snapshots`, missing `db`, or both leave the id unregistered. |
| `tests/Unit/Package/PackageSnapshotGateTest.php` (new) | End-to-end `PackageManager::uninstall()` over a real snapshotter: the snapshot is in place before `package.disable` / `package.uninstall` fire; each name in one call gets its own snapshot; an unwritable store, a non-snapshotter under the id, and a service that cannot be built each refuse with the package folder, version key, and extensions list untouched and no events fired; the streamed message names the title and "nothing was removed", not the workspace path, while the log carries the throwable; no `snapshotter` id removes the package, writes the one warning, and leaves the store empty; a log that throws on that warning still removes the package. |
| `tests/Unit/Console/UninstallCommandTest.php` (new) | `CommandTester` against `UninstallCommand` built on the application container (the TypeError regression): a named package is removed; with a snapshotter the same dump + archive land and the id is printed; every name on the command line is removed with one snapshot each; an unknown name is reported rather than skipped, and the installed package is left alone. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Soft uninstall + restore + purge primitives (Checklist Step 4)

Uninstall is no longer a hard delete after a successful snapshot. The snapshot is the retained copy; the live tree is taken out of `packages/` so the factory stops globbing it. Restore and purge exist on `PackageSnapshotter` as primitives — no panel route, no console command, no retention window yet.

| File | Change |
|---|---|
| `app/installer/src/Package/Snapshot/PackageSnapshotter.php` | Constructor takes `DatabaseRestorer` as well as the dumper. `restore(id)`: refuse a missing dump or an empty archive before touching the live tree; put each archived `vendor/name` back under `packages/` (delete whatever is at the target first, never merge); then apply the dump; audit tables+rows. `installed.json` is not written back. A restored snapshot stays until purge. `purge(id)`: store `delete()` or throw (partial destroy possible); audit `trigger=request`. Unknown / traversal ids throw `\InvalidArgumentException` whose message does not echo the value. `audit()` is now a message+context helper shared by create/restore/purge; a log that cannot take the line still does not cost the operation. |
| `app/installer/src/Package/PackageManager.php` | Inline Composer-or-`file->delete` removal replaced by `removeFiles()`. Empty path refused before any delete. Composer packages: `composer->uninstall()` only after the snapshot already holds the archive, then the live tree is judged by `!is_dir()`. Other packages: `file->delete()` return value is the outcome (`=== true`), not a follow-up `is_dir` — a tree that the service says it removed is counted as gone even if the folder is still there. Failure streams the title (or package name) and that the snapshot holds the package; the path stays in the error log. Empty vendor dir is still `@rmdir`'d. Hook barrier + node trash + version-key removal unchanged. |
| `app/installer/index.php` | `snapshotter` factory now constructs `DatabaseRestorer` on the same `db` as the dumper. Absence of the id is still the whole no-store answer. |

### Tests (Checklist Step 4)

| File | Change |
|---|---|
| `tests/Unit/Snapshot/SnapshotRestoreTest.php` (new) | Real store + real database: files and the pre-removal config (version key + enabled list) come back together; rows written since the snapshot are gone; the tree on disk is the archived tree, not the manifest name; `installed.json` is not written back; a restored snapshot can be restored again; a truncated dump leaves the files back, the live config as-removed, and the snapshot in place (retry finishes it); a copy that cannot land, or a live tree that will not yield, refuses before the dump is applied and leaves the snapshot; missing dump / empty archive refuse before anything is dropped; traversal / absolute / null-byte / unknown ids are refused without echoing the value; restore is audited with id + package + table/row counts; a logger that throws does not cost the restore. |
| `tests/Unit/Snapshot/SnapshotPurgeTest.php` (new) | A purge deletes the snapshot directory (dump included); other snapshots stay; restore of a purged id is `\InvalidArgumentException`; traversal / absolute / null-byte / unknown ids are refused without echoing the value and without an audit line; a store `delete()` that returns false is reported rather than counted as gone (no audit); purge is audited with id + package + `trigger=request`; a logger that throws does not cost the purge. |
| `tests/Unit/Package/PackageTreeRemovalTest.php` (new) | End-to-end `uninstall()` over a real snapshotter: Composer is told only after the archive is already in the snapshot; the vendor directory goes with the last package in it and stays when another remains; `file->delete()` returning false, or a Composer uninstall that leaves the directory, streams the title and "could not be taken off the disk" (no workspace path) while the log carries the path, with the version key already gone and the snapshot in place; a package with no title is named by its package name; an empty path is refused before any delete; a throwing `disable`/`uninstall` hook still archives and removes the tree (barrier regression). |
| `tests/Unit/Snapshot/SnapshotAudit.php` (new) | Shared in-memory logger moved out of `PackageSnapshotterTest` so create/restore/purge tests read one trail. |
| `tests/Unit/Snapshot/AuditThatCannotBeWritten.php` (new) | Shared logger that throws, same move. |
| `tests/Unit/Snapshot/SnapshotServiceWiringTest.php` | The installer-built `snapshotter` restores as well as creates: a table planted before `create()`, files deleted and the version key cleared, `restore()` puts both back. |
| `tests/Unit/Snapshot/PackageSnapshotterTest.php` | Constructor passes the restorer; `SnapshotAudit` / `AuditThatCannotBeWritten` no longer live in this file. |
| `tests/Unit/Package/PackageSnapshotGateTest.php` | Constructor passes the restorer (existing snapshot-before-uninstall coverage unchanged). |
| `tests/Unit/Console/UninstallCommandTest.php` | Same constructor wiring. |

Gates: Verifier (production) PASS; Tester FAIL once (`removeFiles` threw when the live tree remained after `delete`; existing unit fixtures such as `RecordedFiles` do not physically remove the folder) → refactorer retry → Verifier PASS, Tester PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Retention window + prune-on-create (Checklist Step 5)

No scheduler, no panel action yet. The window is module config; it is enforced when the next snapshot is taken (`create()` prunes first) and when something calls `purgeExpired()` itself. An installation that does neither keeps every snapshot. Disk-full on a present store still aborts the removal.

| File | Change |
|---|---|
| `app/installer/index.php` | Module config `snapshots.retention_days` ships `SnapshotStore::DEFAULT_RETENTION_DAYS` (30). The `snapshotter` factory reads `$config['snapshots']['retention_days']` through `SnapshotStore::retentionDays()` and constructs the store with that window. `$this->config` is captured once as `$config`. |
| `app/installer/src/Package/Snapshot/SnapshotStore.php` | New static `retentionDays(mixed): int` — a numeric value is taken as it stands (zero and below included, which turns retention off); anything else is the shipped default. |
| `app/installer/src/Package/Snapshot/PackageSnapshotter.php` | `create()` calls `purgeExpired()` before reserving the new directory. New `purgeExpired()`: destroy each expired id (skip if already gone between list and get; warn and leave if `delete()` is false; audit `trigger=retention` per success) and return the ids that went. New `list()`: the store inventory (size + expires, never dump contents). `audit()` takes an optional PSR log level so an unreclaimed snapshot is a warning rather than a notice. |

### Tests (Checklist Step 5)

| File | Change |
|---|---|
| `tests/Unit/Snapshot/SnapshotRetentionTest.php` (new) | Real store + real database: taking a snapshot reclaims only what the window has run out on, before the new id is written; exactly-at-window is expired, a few minutes short is kept; `retentionDays <= 0` reclaims nothing (`expires` is null) and writes no retention line; `purgeExpired()` alone reclaims without a create; an empty install is left without a store directory; each reclaimed id is audited (`trigger=retention`, package, "not recoverable"); a `delete()` that returns false is a warning, the snapshot stays restorable, and the new snapshot still lands in full; a snapshot another purge already destroyed is not accounted for twice; `list()` is newest-first with size + expires and never contains dump contents. |
| `tests/Unit/Snapshot/ASnapshotThatWillNotGo.php` (new) | Filesystem whose `delete()` is always false — shared by explicit purge and retention, because the disk that will not go is the same either way. |
| `tests/Unit/Snapshot/SnapshotPurgeTest.php` | `ASnapshotThatWillNotGo` moved out of this file. |
| `tests/Unit/Snapshot/SnapshotStoreTest.php` | `retentionDays()` takes a number (int, numeric string, truncated float, 0, negative) as-is and falls back to the default for null / empty / non-numeric text / bool / array. |
| `tests/Unit/Snapshot/SnapshotServiceWiringTest.php` | The installer-built snapshotter honours a configured window (7 days → `expires = created + 7d`); missing / empty / non-numeric config keeps the shipped default; the module's shipped value is that same default. |
| `tests/Unit/Package/PackageSnapshotGateTest.php` | End-to-end uninstall against a store directory chmod'd 0555: the removal is refused (`nothing was removed`), the package folder / version key / extensions list are untouched, and the store is left empty (no directory offered as restorable). Skips under root or a host that still writes into a read-only directory. TearDown restores 0755 so the workspace can be removed. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

---

## 🧠 Key Decisions (Rationale)

- **Id validation is `\z`, not `$`, plus a length cap.** A trailing newline would otherwise match `$`; `get()`/`delete()` treat a non-id as absence (`null`/`false`) rather than throwing, while path accessors throw `\InvalidArgumentException` whose message does not echo the rejected value (it can come from a request).
- **A snapshot with unreadable metadata stays on the inventory.** The directory name is the id; metadata only describes it. Dropping a damaged directory from `list()`/`expired()` would leave disk nothing reclaims. Whether it can still be restored is a later restore-time question against the dump.
- **A module name that cannot be an id is slugged, not refused.** The name comes from a package manifest; refusing the snapshot over a character in it would block the destructive op that needs the snapshot first. An empty slug becomes `package`.
- **Owner-only modes (directory 0700, metadata 0600).** A later dump holds password hashes. A snapshot taken on the console is readable by the web server only where both run as the same user.
- **JSON-lines, not one document, and the `end` record is what makes a dump a dump.** The writer never holds the database in memory; the reader never holds more than the row it is on. A file that stops before `end` is incomplete — restore refuses it while the live tables are still there; the dumper never leaves a file at the name a restore reads until that line is flushed and the staging file renamed.
- **Family, not version, and the same family only.** DDL and FK handling agree at sqlite vs mysql (MariaDB included). A dump is a recovery artefact for this installation, not a cross-driver migration tool; mismatch is refused by name of both sides.
- **Dump order is the dump's.** Introspection list order is the driver's; tables are sorted by name so two dumps of one unchanged database read the same.
- **SQLite restore is transactional; MySQL restore is not.** SQLite keeps schema changes inside the transaction a restore opens, so a failed apply rolls back. MySQL commits on every schema statement — a failed apply leaves the database partly replaced. The snapshot stays on disk either way; running restore again is the recovery that works on both. Not worked around.
- **The archive path is the on-disk tree, not the manifest name.** `files/<basename(dirname(path))>/<basename(path)>/` — a package that names itself `../../escaped` or `/etc/passwd` still archives where it actually lives. Text out of a package does not name a path inside the store.
- **`installed.json` sits beside `files/`, not in it.** It is Composer's record of the whole installation. Copying the file itself (not a re-encoding) keeps the snapshot honest; restore leaves the live record alone (see below).
- **Presence of `snapshotter` is the escape hatch, nothing finer.** The service is defined only when `path.snapshots` and `db` are both there. A container without the id never had a store (installer-before-database) and may remove unsnapshotted, with one log line. A present id that is the wrong type, will not resolve, or whose `create()` throws always aborts. No second "skip snapshot" flag.
- **The streamed abort names the package, not the path.** The exception an admin (or a browser stream) sees is "nothing was removed"; the throwable and the disk path stay in the error log. Same boundary as the 2.7 hook notices.
- **Copy then delete, not rename.** `create()` already archives via `copyDir()`; `removeFiles()` only takes the live tree away. Composer removes its own tree after that archive exists. A failed delete still leaves a restorable snapshot — a rename into the store would not.
- **Non-Composer removal trusts `file->delete() === true`, not a follow-up `is_dir`.** The file service both performs the delete and answers whether it could (and a path it maps through an adapter is not necessarily a local `is_dir`). Composer reports nothing, so a Composer package is judged by `!is_dir($path)`. Existing unit fixtures (`RecordedFiles` and kin) record `delete()` without physically removing the folder; an `is_dir` check after a successful `delete()` therefore threw on an otherwise-green uninstall. The retry dropped that check. A `delete()` that returns true while the folder remains is counted as gone — the factory would still glob it.
- **Files first, then the dump, on restore.** A package tree nothing enables is a package the panel lists as not installed; a database naming an enabled extension whose files are missing is a boot that fails. A restore that cannot apply the dump leaves the files back and the snapshot in place — retry is the recovery. A restore that cannot even put the files back does not apply the dump.
- **Reinstatement deletes the live target, never merges.** A package reinstalled since the snapshot has files the archive never had.
- **`installed.json` is captured, not restored.** Overwriting the live Composer record would take every package installed since off Composer's books. The copy stays in the snapshot for whoever reconciles the two.
- **A restored snapshot is still a snapshot.** Nothing in `restore()` destroys it; the same id can be replayed until `purge()`.
- **Purge is the only irreversible step.** Uninstall leaves DB tables in place (a package that wants its rows gone says so in its own hook). Purge deletes the snapshot directory; a `delete()` that returns false is reported rather than counted as gone, and can leave part of the directory destroyed.
- **Prune-on-create, not a scheduler.** `create()` reclaims expired snapshots before it opens a new directory. `purgeExpired()` is also callable on its own (the admin action in the next step). An installation that never takes another snapshot and never asks keeps everything — for an irreversible delete, that is the direction to err in.
- **Exactly-at-window is expired.** The store treats `created <= now - days` as reclaimable, so the expiry `list()` shows is the moment it can actually go, not the following day. Zero or less expires nothing (`expires` is `null`).
- **A prune that cannot delete does not cost the new snapshot.** Explicit `purge(id)` still throws when `delete()` is false. Retention is different: it runs in the middle of writing a way back, so a stubborn expired directory is a warning (`trigger=retention`) and is left restorable. A snapshot already gone between `expired()` and `get()` is skipped, not double-accounted.
- **Non-numeric retention is the default, not zero.** Module config is whatever the DB holds. `is_numeric` (including `"0"` and negatives) is the administrator's decision; text / empty / bool / array would otherwise read as 0 and turn retention off by accident.

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

---

## ⚠️ Risks & Rollout Notes

- **0700 on a shared host.** Console and PHP-FPM as different users will make a console-taken snapshot unreadable to the panel. Containers share one user; a shared host may not.
- **`docker/entrypoint.sh` `mkdir` is CI-exercised.** The VM has no Docker daemon; the `Docker Image` workflow is what actually recreates `tmp/snapshots` on start.
- **MySQL restore that fails mid-apply leaves a partial replacement.** Documented, not papered over. Recovery is to run restore again from the dump still on disk. SQLite does roll back; do not assume the MySQL path does.
- **MySQL dump/restore is the advisory `phpunit-mysql` leg.** Default PHPUnit is SQLite in memory via `SnapshotDatabase`. The same tests run against MySQL 8.4 when `DbUtil` globals name it; they skip-cleanly otherwise. A full uninstall → restore → purge on a real MySQL site is still maintainer work — the primitives exist; the panel does not call them yet.
- **`php pagekit uninstall` now runs.** It used to TypeError before looking up a package. It now snapshots first (when the service exists) and then takes the live tree out of `packages/`. That create also prunes expired snapshots. Restore, purge, `list()`, and `purgeExpired()` exist on `PackageSnapshotter`; nothing in the panel or the CLI calls restore/purge/`list()`/`purgeExpired()` yet.
- **Uninstall is the soft stage.** The live tree is gone; the snapshot is the retained copy; DB tables stay. A crash between snapshot and delete can leave both; a crash after delete leaves a restorable snapshot that no in-product caller invokes yet. Purge is the only destroy of a snapshot — on request, or when the next `create()` (or an explicit `purgeExpired()`) finds the window has run out. Neither path has an admin action yet.
- **`removeFiles` does not re-stat a non-Composer tree after `delete()`.** Existing unit fixtures do not physically remove the folder. A `file->delete()` that returns true while the directory remains reports the uninstall as finished; the factory would still list the package. Composer-installed packages are still judged by what is on disk.
- **No cron, no hard cap.** Retention is enforced only on the next `create()` or an explicit `purgeExpired()` call. Nothing in the panel calls that yet. An install that stops uninstalling sits on every snapshot it took until someone does. Size + expires on `list()` are the growth signal; there is no silent delete-oldest cap.
- **An unreclaimed expired snapshot stays a snapshot.** A `delete()` that returns false during prune leaves dump and archive in place. Disk is not handed back; restore still works. The warning is the operator's cue.
- **The 0555 disk-full abort skips as root.** Same as the store's permission tests: `posix_geteuid() === 0` (and hosts that ignore directory mode) cannot provoke a failed write. The gate is still a present store that will not accept a snapshot — uninstall refuses, package intact.

---

## 🔐 Security & Data Impact

Uninstall now writes a dump, then takes the live tree away. A finished snapshot is owner-only (directory 0700, dump/metadata 0600) and holds every row the installation owns — password hashes and session data among them — plus the package tree and, for Composer packages, `installed.json`. The store is still not HTTP-reachable (`path.snapshots` outside DocRoot; `Require all denied` belt). Failure of any part of `create()` deletes the reserved directory; the removal does not start, so there is no half-removed package offered as restorable. A store that is present but will not accept a write (disk full / unwritable root) is the same abort — no empty directory left behind as something to restore. Restore puts those files back and then replaces the whole database — everything written since the snapshot is gone — and does not write `installed.json` back. Purge (on request or after the retention window) destroys the dump. Neither restore nor purge is reachable over HTTP yet (no controller); `list()` is metadata + size + expires only, never dump contents. Ids that name no snapshot, including traversal payloads, are refused without echoing the value. A tree that would not leave the live installation streams the package title and that the snapshot holds it; the path stays in the error log. The archive path (and the restore target) is taken from where the files are, not from the package name. Retention lines name snapshot id + package + `trigger=retention`; they do not carry dump contents.

---

## 🛡️ No-Mercy Compliance

One snapshotter, one `uninstall()` path, one `removeFiles()`. Call sites constructing `PackageSnapshotter` all pass the restorer — no optional collaborator, no "restore if the dumper is present". The old inline Composer-or-`file->delete` removal is deleted, not wrapped. The no-id escape hatch is still absence of the service. Dump/restore remains one format and one pair of classes. `create()` is all-or-nothing; `restore()` / `purge()` / `list()` / `purgeExpired()` are real methods, not stubs. One window, one parser (`SnapshotStore::retentionDays()`), one prune path — a failed prune does not wrap or skip `create()`. No second node-trash mechanism — `PackageLifecycleWiringTest` is unmodified. No HTTP surface for list/restore/purge yet (the next checklist step).

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: Checklist Step 2 production tester FAIL once (PHPStan `list<string>` vs `array` at `DatabaseRestorer::apply`) → refactorer retry → PASS. Checklist Step 4 production tester FAIL once (`removeFiles` threw when the live tree remained after `delete`; existing unit fixtures do not physically remove the folder) → refactorer retry (non-Composer path trusts `file->delete() === true` rather than `is_dir`) → PASS. Checklist Steps 1, 3, and 5 gates all PASS. Step 3 deleted the `UninstallCommand` PHPStan baseline ignore rather than adding one.

---

## 📋 Phase 1 Audit Closure

_TBD / None_

---

## 👤 Maintainer action

<!-- Human-only follow-ups the maintainer must do (ruleset flips, real Docker/Apache
     verification, secrets, etc.). Not ROADMAP deferrals — those go under Deferred. -->

_TBD / None_

---

## 📚 Deferred / Out-of-Scope

<!-- Future ROADMAP/PHASE work, explicit non-goals, bridges. Do NOT put maintainer
   Manual Work here — that belongs under Maintainer action above. -->

_TBD / None_

---

## 📌 Follow-on (ROADMAP)

_TBD / None_

---

## 🧊 Parked (unplanned)

_TBD / None_

---

## 🧹 Cleanup

_TBD / None_

---

## 🛡️ Audit

_TBD / None_

---

## 🎁 Bonus

_TBD / None_

---

## 🔍 Research

_TBD / None_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_7_1_Snapshot-Three-Stage-Uninstall_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_1_Snapshot-Three-Stage-Uninstall.md`
- Predecessor: Step 2.7 — Extension Safety System
- Successor: Step 2.7.2 — Module Dependency Integrity

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
