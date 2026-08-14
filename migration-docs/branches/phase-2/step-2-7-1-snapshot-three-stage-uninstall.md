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

---

## 🧠 Key Decisions (Rationale)

- **Id validation is `\z`, not `$`, plus a length cap.** A trailing newline would otherwise match `$`; `get()`/`delete()` treat a non-id as absence (`null`/`false`) rather than throwing, while path accessors throw `\InvalidArgumentException` whose message does not echo the rejected value (it can come from a request).
- **A snapshot with unreadable metadata stays on the inventory.** The directory name is the id; metadata only describes it. Dropping a damaged directory from `list()`/`expired()` would leave disk nothing reclaims. Whether it can still be restored is a later restore-time question against the dump.
- **A module name that cannot be an id is slugged, not refused.** The name comes from a package manifest; refusing the snapshot over a character in it would block the destructive op that needs the snapshot first. An empty slug becomes `package`.
- **Owner-only modes (directory 0700, metadata 0600).** A later dump holds password hashes. A snapshot taken on the console is readable by the web server only where both run as the same user.

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

---

## ⚠️ Risks & Rollout Notes

- **0700 on a shared host.** Console and PHP-FPM as different users will make a console-taken snapshot unreadable to the panel. Containers share one user; a shared host may not.
- **`docker/entrypoint.sh` `mkdir` is CI-exercised.** The VM has no Docker daemon; the `Docker Image` workflow is what actually recreates `tmp/snapshots` on start.

---

## 🔐 Security & Data Impact

No dump is written yet. The store already treats the directory as secret: outside the webroot and outside `storage/`, `Require all denied` for a mis-pointed document root, gitignored so a dump cannot land in the repository, owner-only modes on create. Id input is allowlisted before it is ever joined to a path.

---

## 🛡️ No-Mercy Compliance

Primitive only — no dual store, no uninstall wrapper, no consumer. Call sites that need a snapshotter are later steps; this one does not keep a second path for "no snapshots yet".

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: None (Checklist Step 1 gates all PASS)

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
