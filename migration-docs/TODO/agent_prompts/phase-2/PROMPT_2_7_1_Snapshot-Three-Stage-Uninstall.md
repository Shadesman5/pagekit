# Step 2.7.1: Snapshot & Three-Stage Uninstall

<!-- conductor-mode: full -->

**ROADMAP:** 2.7.1. GitHub Issue: #267. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.7.1.

---

## CONTEXT

- **Land after:** Step 2.6 (#257) for atomic writes, and Step 2.7 (#160) for fault isolation / lifecycle seams. If either is missing, STOP and sequence correctly — snapshots must reuse `dumpAtomic()`, and automatic removals must not invent a second disable/notify path.
- **Provides:** the snapshot + staged-removal primitive that Automated Updates (2.9) reuse for rollback, and that Sub-Extension automatic cleanup (5.0) must call whenever a removal was not explicitly requested by an operator.
- **Risk:** Medium. DB dump portability (SQLite/MySQL), storage growth under `tmp/snapshots/`, and a destructive package path that today deletes code from disk with only a browser `confirm()`.
- **Goal:** Before any destructive package operation, a restorable snapshot exists; uninstall becomes disable → uninstall (soft) → purge after retention, with admin UX to list / restore / purge; and when a package's `disable` / `uninstall` lifecycle hook throws, the stage still completes but the admin sees that the hook failed (not log-only success).
- **Why:** Today `PackageManager::uninstall()` disables, runs the lifecycle uninstall hook, fires events, removes the package version key, then **deletes the package folder from disk** (or via Composer) with no DB dump and no retention window. Site nodes are soft-trashed by `ExtensionNodeLifecycle`, but schema/data and the code tree are not uniformly recoverable. Step 2.7 keeps a broken site alive and deliberately swallows broken exit hooks into the log so an administrator can always leave a misbehaving package — but the panel still reports plain success, so operators never learn the hook failed. This step keeps removal restorable and makes that swallowed-hook case visible without giving the package a veto.

### Current state (verified 2026-08-10 — confirm in Discovery, then build; do not rediscover blindly)

**Package lifecycle (destructive path).** Confirm line numbers in Discovery — they drift.

- `PackageManager::uninstall()` — order: `disable($package)` → lifecycle `uninstall()` (barrier: log + continue on throw) → `package.uninstall` event → `config('system')->remove('packages.<module>')` → if Composer-installed: `composer->uninstall()`, else `file->delete($path)` + `@rmdir(dirname($path))`. No try/catch around the overall flow; a mid-flight throwable outside the hook barrier leaves partial state.
- `PackageManager::disable()` — lifecycle `disable()` (same log+continue barrier) → `package.disable` → pull from `extensions`. No dependency check (that is 2.7.2). **Admin feedback gap:** a thrown exit hook is log-only; the panel reports success with no distinction from a clean hook.
- `PackageManager::enable()` — already has `try/catch` + schema/config rollback and failure-record clear/restore (Step 2.7); do not reopen that contract here.
- Lifecycle entry: `LifecycleRunner` loads `extra.scripts` → `scripts.php` returning `PackageLifecycleInterface` (rename to `extra.lifecycle` / `lifecycle.php` is Step **2.8**, not this ticket).

**The only existing "snapshot" is node placement, not packages.**

- `ExtensionNodeLifecycle` (`app/system/modules/site/src/ExtensionNodeLifecycle.php`) stores menu/parent/neighbor placement in node `data._extension_restore` on disable/uninstall and restores on enable. `PackageNodeTypes` remembers node type IDs under `_extension_nodes.<module>`. Covered by `app/system/modules/site/src/Tests/PackageLifecycleWiringTest.php`.
- **No** `tmp/snapshots/` tree, **no** DB dump/restore helper, **no** `config.php` backup-before-uninstall path.

**What uninstall actually destroys vs. soft-removes today.**

| Asset | Today on uninstall |
| --- | --- |
| Package files on disk | **Hard-deleted** (`PackageManager.php:132-133` or Composer) |
| `packages.<module>` version key | Removed |
| Extension membership / theme | Cleared via prior `disable()` |
| Site nodes for the package's types | Soft-trashed (`menu='trash'`, status 0) with restore metadata |
| Extension DB tables (e.g. blog) | **Left in place** — `BlogLifecycle::uninstall()` clears cache only; table rollback is an explicit developer choice |

**Admin UX.**

- Extensions list: `app/installer/views/extensions.php` — uninstall control only when disabled; `v-confirm="'Uninstall extension?'"` (single confirm, no stages). Failed auto-disable shows a warning icon via `pkg.failure` (Step 2.7) — unrelated to a swallowed exit-hook failure on a deliberate disable/uninstall.
- Controller: `PackageController::uninstallAction()` streams status; `disableAction()` / `enableAction()` clear cache after. Uninstall path does **not** schedule the same cache clear as enable/disable. Disable/uninstall responses do not currently carry a "lifecycle hook failed" signal.
- Vue: `app/installer/app/lib/uninstall.vue` + `package.js` `uninstall()` — progress modal calling `GET admin/system/package/uninstall`. Themes page is analogous.

**Database targets for a dump.**

- Default connection from `app/modules/database/index.php` — `database.default` (`sqlite` default), `database.connections.mysql` / `.sqlite`. Service id `db`.
- Env overrides via `EnvConfigLoader` (`PAGEKIT_DB_DRIVER`, `PAGEKIT_DB_PATH`, …). Dump/restore must work for both drivers used in CI and Docker.

**Paths.**

- Runtime: `path.temp` → `tmp/temp`, `path.cache` → `tmp/cache`, `path.artifact` → `tmp/packages`, `path.storage` → `storage`. Snapshot store must be a **dedicated** directory (PHASE: `tmp/snapshots/`), never DocRoot, never `storage/`, and never swept by `CacheModule::doClearCache(['temp' => true])` (depth-0 wipe of `tmp/temp`).
- Metadata / small PHP or JSON state written for the next boot or admin list → reuse the 2.6 `dumpAtomic()` primitive. Large dump blobs may use a separate write path if `dumpAtomic()`'s unconditional `opcache_invalidate` is wrong for non-PHP artefacts (same caveat as Step 2.9 notes in PHASE).

**Downstream consumers (do not implement here, design for them).**

- Step **2.9** Automated Updates — rollback after a failed update reuses the snapshot primitive.
- Step **5.0** Sub-Extensions — automatic dependency cleanup may deactivate; any **deletion** it triggers must go through disable → uninstall → purge **with a snapshot first**.

---

## PRINCIPLES (hold across every checklist step)

- **Snapshot before destroy.** No code/path/schema purge runs without a restorable snapshot already on disk (or an explicit, logged decision that this operation is non-destructive).
- **One removal pipeline.** Operator uninstall and future automatic cleanup share the same three stages. Do not add a second "quick delete" for agents or 5.0.
- **Soft before hard.** Uninstall removes activation and hides/soft-removes owned data; purge is the only stage that permanently destroys retained artefacts after the retention window.
- **Private snapshot store.** Under `tmp/snapshots/` (or equivalent dedicated path key), never HTTP-reachable, never cleared by routine cache/temp clear.
- **Reuse 2.6 for boot-critical metadata.** Snapshot index / manifests that PHP may `require` go through `dumpAtomic()`. Do not invent a second temp+rename.
- **Driver honesty.** SQLite and MySQL dumps must both restore on the same driver family the site uses; document limits rather than claiming universal portability.
- **Node lifecycle stays.** `ExtensionNodeLifecycle` already soft-trashes nodes — integrate with stages, do not replace it with a second node-trash mechanism.
- **Rule 3** — tree stays green after every checklist step; required CI job names stay stable.

---

## 0. SAFETY CHECKS

Before starting:

1. Branch up-to-date with `develop`.
2. Confirm **Steps 2.6 and 2.7 have landed** (or this branch includes their primitives).
3. Baseline green:

```bash
php -v
composer install --no-interaction --prefer-dist --no-progress
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
pnpm install --frozen-lockfile && pnpm build
```

**IF ANY FAILS → STOP AND FIX** (or report the pre-existing failure) before touching uninstall.

---

## 1. DISCOVERY

```bash
rg -n 'function uninstall|function disable|package\.uninstall|package\.disable' app/installer/ app/system/ --glob '!app/vendor/**'
rg -n 'ExtensionNodeLifecycle|_extension_restore|PackageNodeTypes' app/ --glob '!app/vendor/**'
rg -n 'tmp/snapshots|path\.temp|doClearCache|dumpAtomic' app/ public/index.php --glob '!app/vendor/**'
rg -n 'uninstall\.vue|v-confirm.*Uninstall|uninstallAction' app/installer/ --glob '!app/vendor/**'
rg -n 'PAGEKIT_DB_|database\.default|pdo_sqlite|pdo_mysql' app/ --glob '!app/vendor/**'
```

Resolve before writing code:

- **Snapshot contents** — minimum viable set: metadata (package id, version, timestamp, reason), DB dump for the connection in use, relevant config slice, optional package files. State what is in vs. out for v1.
- **Dump mechanism per driver** — SQLite file copy vs. SQL dump; MySQL dump tool vs. pure-PHP. What restore needs. Fail loudly when the driver cannot be snapshotted safely.
- **Stage semantics** — exact meaning of disable / uninstall (soft) / purge; what remains on disk and in DB after each; how re-install / restore interacts with `ExtensionNodeLifecycle`.
- **Retention default** — e.g. 30 days; where it is configured; who runs purge (admin action only vs. scheduled — if scheduled, keep it minimal and explicit).
- **Trigger points** — which `PackageManager` / controller entry points must snapshot first (uninstall today; major package ops; leave 2.9 update orchestration as a consumer, not implemented here).
- **Admin API + UI** — list / restore / purge surfaces; replace the single `v-confirm` uninstall with a staged flow without inventing a second package manager.
- **Swallowed exit-hook feedback** — how disable / soft-uninstall / purge report a lifecycle hook that threw: stage still succeeds (no package veto), but the admin sees a warning (flash, stream message, and/or package list state) naming that the hook failed and pointing at the log — without rendering the throwable's own message (same boundary as Step 2.7's failure notices).
- **Storage growth** — caps, cleanup on purge, and what happens if the snapshot write fails (destructive op must not proceed).
- **Atomic metadata** — snapshot index format and write path via 2.6.

---

## 2. WORK

Architect: decompose into ordered, individually-green checklist steps. Suggested order — each group is at least one step:

1. **Snapshot store + path key** — dedicated directory, not swept by cache/temp clear; index written atomically; no behaviour change to uninstall yet.
2. **DB dump + restore for SQLite and MySQL** — round-trip tested; failure blocks destructive ops.
3. **Wire snapshot-before-uninstall** — `PackageManager` / controller take the snapshot first; on snapshot failure, abort.
4. **Three-stage uninstall** — disable → soft uninstall → purge after retention; align node lifecycle and package-file deletion with stages (hard disk delete moves to purge, not soft uninstall — decide explicitly if PHASE's "soft-removed" means code stays until purge). Keep the Step 2.7 exit-hook barrier (log + continue — never block the stage).
5. **Admin UX** — list / restore / purge; replace one-shot confirm uninstall; surface swallowed `disable` / `uninstall` lifecycle-hook failures to the admin (stage still OK) without leaking throwable messages into the page.
6. **Retention + purge path** — enforceable window; purge is irreversible and audited in the log.
7. **Mandatory final `(XL)` step** — Review (Bugbot + Security) + E2E.

Sizing hints: (2) and (4) carry the design weight; (5) is the UX fix-loop risk; (1) and (6) are smaller once the model exists.

### Notes per group

- **(4) Soft vs hard:** today uninstall already deletes the package folder. Three-stage uninstall is a **behaviour change** — soft uninstall must not be today's hard delete under a new name. State the new contract in the ticket and cover it with tests.
- **(2) Drivers:** prefer mechanisms already available in the environment (PHP + existing DBAL connection). Do not add a mandatory external `mysqldump` binary unless Discovery proves there is no acceptable pure-PHP path — and if you require a binary, document the operator dependency.
- **(5) UX:** keep Vue 2.7 patterns (no `<script setup>` / Composition API unless already used in this module). Hook-failure feedback must not reopen the Step 2.7 contract that exit hooks never veto disable/uninstall — only make the logged failure visible.
- **Do not rename** `extra.scripts` / `scripts.php` here — that is Step **2.8** (`extra.lifecycle` / `lifecycle.php`).

---

## 3. OUT OF SCOPE

- **Fault barrier, auto-disable, standing admin notice from durable failure records, lifecycle interface / runner, routing dumpers, static bridges** → Step 2.7 (reuse; do not reopen). Exit-hook **visibility** in the package UI is in scope here; the non-blocking barrier itself is not.
- **Module dependency fail-closed / pre-flight "what would break"** → Step 2.7.2 (this step may call into that pre-flight once it exists, but must not implement the graph).
- **Static module registration** → Step 2.7.3.
- **`extra.lifecycle` / `lifecycle.php` rename** → Step 2.8.
- **Background update orchestration and update-time rollback UX** → Step 2.9 (consumes the snapshot primitive).
- **Marketplace signing / catalogue** → Step 5.6.
- **Automatic orphan cleanup / install-reason bookkeeping** → Step 5.0 (must use this pipeline; do not build 5.0 here).
- **Process-level PHP sandboxing** — not Core; optional Phase 5 candidate after marketplace trust.

---

## 4. TESTING

- **Per checklist step (Conductor Tester):** PHPUnit + PHPStan PASS/FAIL.
- **`test-writer` applies to every step** that changes production PHP. No blanket `test-writer: skip`.
- **Core coverage from scratch:** snapshot write/restore round-trip (SQLite mandatory; MySQL if the test harness can provide it), snapshot failure blocks uninstall, soft uninstall leaves code/data recoverable, purge after retention removes retained artefacts, restore brings back a known fixture package state, and a throwing disable/uninstall lifecycle hook still completes the stage while the admin-facing signal marks the hook failure. Keep `PackageLifecycleWiringTest` green — node soft-trash/restore must still work with the new stages.
- **Frontend:** any Vue/JS touched → `pnpm lint` and `pnpm exec prettier --check .` (blocking in CI).
- **E2E (final `(XL)` step):** the 3 `@ci` specs. Manual or documented maintainer action for a full uninstall → restore cycle if agents cannot run MySQL dump reliably — record under `## Maintainer action` in the branch doc, never claim it automated.

---

## SUCCESS CRITERIA

- Destructive package uninstall cannot run without a successful snapshot first (or an explicit non-destructive classification).
- Snapshots live under a dedicated private path (`tmp/snapshots/` or equivalent), not under `storage/` or `tmp/temp`, and survive routine cache/temp clears.
- Three stages exist: disable, soft uninstall, purge-after-retention; hard deletion of retained package artefacts happens at purge, not at soft uninstall.
- Admin can list snapshots, restore one, and purge; the old one-shot "Uninstall extension?" path is gone.
- A thrown `disable` / `uninstall` lifecycle hook still lets the stage complete, but the admin UI (or streamed status) reports that the hook failed and points at the log — without repeating the throwable message.
- SQLite snapshot round-trip works in automated tests; MySQL path is implemented and either tested or explicitly listed under maintainer verification.
- `ExtensionNodeLifecycle` behaviour remains coherent across stages (no second competing trash mechanism).
- No `Step 2.7.1` references in code or tests; PHPUnit + PHPStan green with no new baseline entries.

---

## NOTES FOR THE ARCHITECT

- **This step changes uninstall semantics.** Do not wrap today's hard delete in a "snapshot then delete anyway" one-liner and call it three-stage. Soft uninstall has to be restorable; purge is the point of no return.
- **Design for 2.9 and 5.0 without building them.** The snapshot service API should be callable from update rollback and from automatic cleanup later — keep the surface small and documented in the ticket.
- **DumpAtomic is for metadata, not necessarily multi-hundred-MB SQL files.** Separate concerns; do not force every blob through opcache invalidation.
- **Storage growth is a product risk.** Retention without purge UX (or an enforceable default) will fill disks on active sites.
- **Security review will care about restore.** Restoring a snapshot must not become an unauthenticated or CSRF-friendly path to rewrite the database.
- One ticket / one PR; Conventional Commits; version bump once at Finalize — never inside checklist steps.
