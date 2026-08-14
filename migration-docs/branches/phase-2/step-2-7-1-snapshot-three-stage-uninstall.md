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

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

---

## ⚠️ Risks & Rollout Notes

- **0700 on a shared host.** Console and PHP-FPM as different users will make a console-taken snapshot unreadable to the panel. Containers share one user; a shared host may not.
- **`docker/entrypoint.sh` `mkdir` is CI-exercised.** The VM has no Docker daemon; the `Docker Image` workflow is what actually recreates `tmp/snapshots` on start.
- **MySQL restore that fails mid-apply leaves a partial replacement.** Documented, not papered over. Recovery is to run restore again from the dump still on disk. SQLite does roll back; do not assume the MySQL path does.
- **MySQL dump/restore is the advisory `phpunit-mysql` leg.** Default PHPUnit is SQLite in memory via `SnapshotDatabase`. The same tests run against MySQL 8.4 when `DbUtil` globals name it; they skip-cleanly otherwise. A full uninstall → restore → purge on a real MySQL site is still maintainer work once the consumer exists.

---

## 🔐 Security & Data Impact

The dump format now exists and a finished dump is 0600 — it holds every row the installation owns, password hashes and session data among them. Still no uninstall consumer, so nothing writes one into the store yet. Restore judges the file before any `DROP`: a dump from another driver family, one that names a table outside this installation's prefix, or one that is incomplete/wrong-version is refused while the live tables are still there. A dump cannot name a neighbour's table into being dropped.

---

## 🛡️ No-Mercy Compliance

Primitive only — no dual store, no uninstall wrapper, no consumer. Call sites that need a snapshotter are later steps; this one does not keep a second path for "no snapshots yet". Dump/restore is one format and one pair of classes: no `mysqldump`/`sqlite3` fallback, no second SQL dialect beside the JSON-lines file, no adapter that pretends a failed MySQL restore rolled back.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: Checklist Step 2 production tester FAIL once (PHPStan `list<string>` vs `array` at `DatabaseRestorer::apply`) → refactorer retry → PASS. Checklist Step 1 gates all PASS.

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
