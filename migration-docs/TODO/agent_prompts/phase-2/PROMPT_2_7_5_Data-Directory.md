# Step 2.7.5: Data Directory (`data/`)

<!-- conductor-mode: full -->

**ROADMAP:** 2.7.5. GitHub Issue: #299. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.7.5.

---

## Task

One boundary by lifetime and exposure. `tmp/` is disposable. `data/` is the only copy and private. `storage/` is the only copy and public. The writable locations the boot names each fall into exactly one of the three.

`path.data` defaults to `<root>/data`, beside the other `path.*` keys. It is never inside `public/` and never linked from it. `path.snapshots` is `data/snapshots`. `path.system` is `data/state`. The directory is mode 0700. `.htaccess` denies every request. `.gitignore` keeps only the guards.

The SQLite default is `data/pagekit.db`. The web installer and `php pagekit setup` write it there. `PAGEKIT_DB_PATH` still overrides. `config.php` stays in the root.

`tmp/` holds only what a clear may take: cache, temp, upload staging, and logs. A lost log costs diagnosis, not data.

Code, `public/`, `packages/`, and the Composer vendor directory are not runtime state. They do not move into `data/`.

The directory moves. The dump format, the restore, and snapshot retention stay as they are. No code reads the old locations. An installation that still has content under `tmp/snapshots` or `tmp/system` is moved once by hand. The upgrade note says so.

In the prod image, `PAGEKIT_DATA_DIR` is `path.data`. The named volume mounts `data/`. The entrypoint does not link `tmp/snapshots`. It checks that `data/` is writable by the serving user. Compose, `prod.env.example`, the README, and `AGENTS.md` follow.

## Findings

**What the boot names.** `public/index.php` sets `path.temp`, `path.cache`, and `path.logs` under `tmp/`, `path.system` to `tmp/system`, `path.snapshots` to `tmp/snapshots`, `path.artifact` to `tmp/packages`, and `path.storage` to `storage`. `config.file` is `config.php` in the root.

**SQLite.** The default connection path is the relative string `pagekit.db`. A relative path is joined to the application root. A path under `public/` is refused. `PAGEKIT_DB_PATH` replaces that value. `.gitignore` ignores `/pagekit.db` at the root.

**Prod image.** `docker/entrypoint.sh` recreates `tmp/cache`, `tmp/logs`, `tmp/packages`, `tmp/sessions`, `tmp/system`, and `tmp/temp` on every start, and links `tmp/snapshots` at `$PAGEKIT_DATA_DIR/snapshots`. It refuses to start when that name is not a link it can write through. `config.php` is a link into the same volume. The image smoke checks the link target, and that the SQLite file is `/var/www/data/pagekit.db` when `PAGEKIT_DB_PATH` says so.

**Store.** `SnapshotStore::DIRECTORY_MODE` is 0700. `tmp/snapshots` ships an `.htaccess` with `Require all denied` and a `.gitignore` that ignores everything except those two guards. `SnapshotPathWiringTest` checks that `path.snapshots` is outside temp, cache, public, and storage. It does not look at `path.system`.

## Out of scope

- Moving `config.php`.
- A log directory of its own, or log rotation. Logs stay under `tmp/`.
- A backup script. The layout is what a backup copies.

## Done when

- A fresh install's SQLite file is `data/pagekit.db`. `PAGEKIT_DB_PATH` still wins.
- Snapshots land in `data/snapshots`, the failure record in `data/state`. Both are mode 0700, denied by `.htaccess`, and gitignored except the guards. Neither is reachable from `public/` or `storage/`.
- Clearing `tmp/` removes cache, temp, staging, and logs, and does not remove `data/` or `storage/`.
- Nothing reads `tmp/snapshots` or `tmp/system`.
- The prod entrypoint checks that `data/` is writable and does not link `tmp/snapshots`. The image smoke asserts the volume mount.
- A test reads the boot paths and fails when a writable location sits outside the three directories, or when `data/` sits inside `public/` or `storage/`.
