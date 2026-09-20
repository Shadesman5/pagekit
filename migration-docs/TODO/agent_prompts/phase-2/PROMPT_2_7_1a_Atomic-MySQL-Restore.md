# Step 2.7.1a: Atomic MySQL Restore (Shadow Cut-over)

<!-- conductor-mode: full -->

**ROADMAP:** 2.7.1a. GitHub Issue: #281. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.7.1a.

---

## CONTEXT

- **Land after:** Step 2.7.1 (#267) — snapshot store, dump format, dumper and restorer exist. If 2.7.1 has not merged, STOP and sequence correctly.
- **Land before:** Step 2.7.1b (#287), which relocates the restorer into a package module of its own. Rewrite the file here, move it there — never the other way round.
- **Provides:** the promise SQLite already keeps — a MySQL restore that fails leaves the live tables as they were — plus the install-time table-prefix invariant that shadow naming depends on.
- **Risk:** Medium–High. DDL rewriting, schema-unique constraint names, leftover shadow/backup names, 2× peak disk, inbound foreign keys from tables outside the dump, concurrent restores without a server lock. A cut-over that is not a single `RENAME TABLE` remaps foreign keys onto the backup tables.
- **Goal:** MySQL restore loads the dump into shadow tables and swaps them in with one `RENAME TABLE`. Any failure before that statement drops the shadows and leaves the installation untouched. Every new installation has a non-empty table prefix, so the shadow namespace sits outside "our tables".
- **Why:** MySQL commits on every schema statement. The restorer drops and recreates each dumped table in place, so a failure after the first `DROP` leaves the installation half replaced. Recovery today is "run restore again from the dump still on disk" — which assumes the dump is still readable and that the operator knows to do it. Shared hosts have no volume snapshot and no guaranteed `mysqldump`; this restorer is the recovery they actually have.

### Current state (verified 2026-09-10 — confirm in Discovery, then build; do not rediscover blindly)

**The apply path splits on platform, and only SQLite is safe.**

- `DatabaseRestorer::replace()` (`app/installer/src/Package/Snapshot/DatabaseRestorer.php:80-106`): SQLite gets `beginTransaction()` around `apply()`; every other platform calls `apply()` directly.
- `DatabaseRestorer::recreate()` (`:424-439`): `DROP TABLE IF EXISTS <table>` followed by the dump's DDL. This is the in-place replace that has to disappear for MySQL — deleted, not kept as a fallback.
- `DatabaseRestorer::inspect()` (`:144`) already reads the whole dump before a statement runs, so every refusal this step adds has a place to live while the installation is still whole.
- `foreignKeys()` / `setForeignKeys()` (`:461-490`): `SET FOREIGN_KEY_CHECKS` on MySQL, `PRAGMA foreign_keys` on SQLite. The MySQL half is what wraps rename and backup drop.
- `platform()` (`:492`) resolves through `DumpFormat::platform()`; `DumpFormat::SQLITE` is the constant the branch tests against.

**An empty prefix is reachable at every entry except the web installer.**

- `app/modules/database/index.php:176` — the `mysql` connection default is `'prefix' => ''`. Line `:186` — `sqlite` is `'prefix' => 'pk_'`. The two platforms disagree with each other.
- `app/console/src/Commands/SetupCommand.php:36` — `--db-prefix` is `VALUE_OPTIONAL` with default `pk_`, so `--db-prefix=` passes an empty string straight through to `:80`.
- `EnvConfigLoader` (`app/modules/application/src/Module/Loader/EnvConfigLoader.php:32`) maps `PAGEKIT_DB_PREFIX` → `prefix` with no empty-value exception. `PAGEKIT_DB_DRIVER` at `:79` is the pattern blank has to follow: set-but-empty reads as unset.
- `Installer` (`app/installer/src/Installer.php:305`) carries `prefix` among its connection keys; the shape is validated in Vue, not on the server. `:70` already uses the prefix to refuse installing over an existing installation.

**The dumper reads an empty prefix as "every table".**

- `DatabaseDumper::schema()` (`app/installer/src/Package/Snapshot/DatabaseDumper.php:256`) selects tables with `str_starts_with($name, $prefix)`. With `''` that is the whole database — a neighbouring application's tables included, and any leftover shadow or backup table entering the dump as if it were live data.

**Tests.**

- `tests/Unit/Snapshot/DatabaseRestorerTest.php` and `DatabaseDumperTest.php` exist from 2.7.1. The MySQL leg of "a failed apply leaves the database as it was" is skipped there — that skip is the assertion this step turns on.
- `phpunit-mysql` runs today as an advisory job; the full-suite flip stays Closeout.

---

## PRINCIPLES (hold across every checklist step)

- **One MySQL path.** Delete the in-place drop-and-recreate. No fallback, no flag, no "if the shadow path fails, try the old one".
- **Refuse before the first `CREATE`.** Empty prefix, a rewritten name over 64 characters, a collision with an existing table, an inbound foreign key from a table outside the dump, leftovers that will not drop — all of these are refusals taken while the installation is still whole.
- **Cut-over is one statement.** Live → backup and shadow → live in a single `RENAME TABLE`. Splitting it lets InnoDB remap foreign keys onto the backup names, after which the backup drop fails or a neighbour hangs.
- **Cleanup runs in `finally`.** Disk-full and timeouts may not leave undeclared `_r_` / `_b_` tables behind.
- **SQLite is untouched.** Its transactional apply already keeps the promise, empty prefix included. Do not "unify" the two platforms into one new abstraction.
- **Existing installations keep booting.** The prefix invariant binds new installations only. A site installed with an empty prefix must not fail boot — it is refused at *restore*, and told why.
- **Identifiers are rewritten as tokens, never as substrings.** Quoted identifier rewriting only; a `str_replace` over DDL text is a data-loss bug.
- **Rule 3** — tree stays green after every checklist step; required CI job names stay stable.

---

## 0. SAFETY CHECKS

Before starting:

1. Branch up-to-date with `develop`.
2. Confirm **Step 2.7.1 has landed** (snapshot store, dump format, dumper, restorer, snapshots panel).
3. Baseline green:

```bash
php -v
composer install --no-interaction --prefer-dist --no-progress
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
pnpm install --frozen-lockfile && pnpm build
```

**IF ANY FAILS → STOP AND FIX** (or report the pre-existing failure) before touching the restorer.

---

## 1. DISCOVERY

```bash
rg -n 'replace|apply|recreate|inspect|setForeignKeys|platform' app/installer/src/Package/Snapshot/DatabaseRestorer.php
rg -n 'schema|str_starts_with|prefix' app/installer/src/Package/Snapshot/DatabaseDumper.php
rg -n "'prefix'" app/modules/database/index.php app/installer/src/Installer.php app/console/src/Commands/SetupCommand.php
rg -n 'PAGEKIT_DB_PREFIX|PAGEKIT_DB_DRIVER|PAGEKIT_DB_PORT' app/modules/application/src/Module/Loader/EnvConfigLoader.php
rg -n 'getPrefix|replacePrefix|placeholder' app/modules/database/src/Connection.php
rg -n 'markTestSkipped|mysql' tests/Unit/Snapshot/DatabaseRestorerTest.php tests/Unit/Snapshot/DatabaseDumperTest.php
```

Resolve before writing code:

- **Where the prefix invariant is enforced.** One validator consumed by installer, CLI and env overlay, or three checks that can drift? Prefer one. Name where it lives so the shape rule (`^[A-Za-z]([A-Za-z0-9_]*)_$`, no `.`, no `-`, not a reserved shadow/backup prefix) has a single home.
- **Refusal wording for empty-prefix MySQL restore.** The operator sees it in the panel; the reason (no namespace to swap within) has to be actionable, not a platform lecture.
- **Constraint renaming strategy.** Foreign key, `CHECK` and `UNIQUE` names are schema-unique in MySQL 8 and also capped at 64 characters. Regenerate short unique tokens; do not prefix the dump's names (overflow plus collision with live). Index names are per-table — rewrite only on collision.
- **How shadow DDL is derived.** From the dump's DDL with table identifiers and `REFERENCES` rewritten. Not `CREATE TABLE … LIKE` against live tables — the live schema may have migrated since the snapshot was taken.
- **Serialization.** Vue `busy` is not a lock. Decide the `GET_LOCK` name, scope and timeout, and confirm it covers leftover cleanup so one restore cannot drop another's in-flight shadows.
- **`lower_case_table_names`.** Collision checks have to follow the server's folding, not PHP's.
- **What the dumper must now skip.** Reserved `_r_` / `_b_` names never enter a dump, including on an empty-prefix installation that is still allowed to dump so uninstall is not stranded.

---

## 2. WORK

Architect: decompose into ordered, individually-green checklist steps. Suggested order:

1. **Prefix invariant at install.** MySQL module default becomes `pk_`; server-side validation in `Installer`; `--db-prefix=` refused; blank `PAGEKIT_DB_PREFIX` reads as unset. Existing installs keep booting. No restorer change yet.
2. **Dumper guards.** Reserved `_r_` / `_b_` names are never dumped. Empty-prefix dump still runs.
3. **Refusals in `inspect()`.** Empty prefix on MySQL restore; rewritten table or constraint name over 64 characters; collision with an existing table; inbound foreign keys from tables the dump does not name; leftover reserved tables that will not drop. All before the first `CREATE`.
4. **Shadow fill.** Load the dump into `_r_`-prefixed tables with identifiers and `REFERENCES` rewritten as quoted tokens and constraint names regenerated. Live tables untouched. Failure drops the shadows in `finally`.
5. **Cut-over.** One `RENAME TABLE` covering every dumped table (live → `_b_`, shadow → live; tables with no live counterpart rename shadow → live only), wrapped in `FOREIGN_KEY_CHECKS = 0`. Then drop the backups. Delete the in-place MySQL apply in the same step — the two paths may not coexist in a merged tree.
6. **Serialization and leftover cleanup.** `GET_LOCK` for the session; at the start of every MySQL restore, drop the reserved leftovers this restorer owns, after the lock is held.
7. **CI leg.** A **required** job running `tests/Unit/Snapshot/` against MySQL 8.4 (`continue-on-error: false`). Do not flip the advisory full-suite `phpunit-mysql` job.
8. **Mandatory final `(XL)` step** — Review (Bugbot + Security) + E2E.

Sizing hints: (4) and (5) carry the behaviour risk and belong together in review even if they are separate commits. (1) touches four entry points and is the one an operator notices.

### Notes per group

- **(1)** The MySQL default and the SQLite default disagreeing is the bug, not an edge case. Fix both to say the same thing.
- **(3)** These refusals are the feature. A restore that starts and cannot finish is worse than one that never starts.
- **(5)** Peak disk is roughly 2× the dumped tables until the backups are dropped, and the rename takes metadata locks — a brief stall, not zero downtime. Both belong in operator-facing copy or README, not in a code comment.

---

## 3. OUT OF SCOPE

- **Where the restorer lives and the module boundary around it** → Step 2.7.1b.
- **Dump format version bump, checksums, views/triggers/routines** → not this step; the dumper's limits stay as they are.
- **Updater orchestration and update-time rollback** → Step 2.9.
- **Container or host-level snapshots** → not a product concern.
- **A fallback in-place apply, hashed live table names, `AUTO_INCREMENT` values in dump DDL** → explicitly rejected, not deferred.
- **A second CI leg for MariaDB** → same family; CI stays MySQL 8.4.
- **Flipping the full `phpunit-mysql` suite to required** → Step 2.11.
- **A repo-wide comment-prose sweep of existing Snapshot / Extension Safety docblocks** → not owned here.

---

## 4. TESTING

- **Per checklist step (Conductor Tester):** PHPUnit + PHPStan PASS/FAIL.
- **`test-writer` applies** on the restorer, dumper and prefix validation.
- **Minimum coverage:** a failed MySQL apply leaves live tables unchanged (the skipped assertion becomes real); empty-prefix MySQL restore is refused; the dump omits `_r_` / `_b_` leftovers; inbound-FK refusal; 64-character table and constraint cases; two concurrent restores serialize; `--db-prefix=` and blank `PAGEKIT_DB_PREFIX` are refused / read as unset; an existing empty-prefix installation still boots.
- **Env tests** `putenv()` with tearDown cleanup, as the existing `EnvConfigLoaderTest` does.
- **E2E (final `(XL)`):** 3 `@ci` specs; the snapshots panel still restores and purges.

---

## SUCCESS CRITERIA

- A MySQL restore that fails at any point before cut-over leaves every live table exactly as it was, and leaves no `_r_` / `_b_` tables behind.
- Cut-over is a single `RENAME TABLE`; the in-place drop-and-recreate MySQL apply no longer exists in the tree.
- Every install entry produces a non-empty, well-shaped table prefix; existing empty-prefix installations still boot and are refused only at MySQL restore, with a reason.
- The dumper never emits reserved shadow or backup names.
- Two restores cannot run at once, and leftover cleanup cannot touch another restore's tables.
- A required CI job runs the snapshot tests against MySQL 8.4.
- SQLite behaviour is unchanged, empty prefix included.
- No `Step 2.7.1a` references in code or tests; PHPUnit + PHPStan green with no new baseline entries.

---

## NOTES FOR THE ARCHITECT

- **The refusals are the product.** Most of this step is deciding what makes a restore impossible and saying so before anything is touched.
- **Do not unify the platforms.** SQLite's transaction and MySQL's cut-over are two answers to the same promise; an abstraction over both would hide which one is running.
- **Rewriting DDL is where this step can silently corrupt data.** Quoted-token rewriting only, and test the case where a column value contains a table name.
- **`RENAME TABLE` atomicity is the whole design.** If the plan splits it, the plan is wrong.
- **This file moves in the next step.** Do not spread the restorer across new locations, and do not pre-empt 2.7.1b's boundary — leave it where it is.
- One ticket / one PR; Conventional Commits; version bump once at Finalize — never inside checklist steps.
