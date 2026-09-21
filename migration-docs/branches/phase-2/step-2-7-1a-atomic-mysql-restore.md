# Step 2.7.1a — Atomic MySQL Restore (Shadow Cut-over)

<!-- Branch doc for Roadmap Step 2.7.1a.
     Path: migration-docs/branches/phase-2/step-2-7-1a-atomic-mysql-restore.md -->

**Branch:** `feature/atomic-mysql-restore`
**ROADMAP Step:** 2.7.1a (Atomic MySQL Restore (Shadow Cut-over))
**GitHub Issue:** [#281](https://github.com/Shadesman5/pagekit/issues/281)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-09-20 21:43
**Completed:** _TBD_

---

## 🎯 Overview

A fresh install can no longer be created with an empty or ill-shaped table prefix. The mysql module default is `pk_`, matching sqlite. Existing installations — empty prefix included — keep booting; only a new install is measured. A dump skips leftover `_r_`/`_b_` tables (empty prefix included) so a half-written restore copy cannot be snapshotted back over live data. A MySQL restore refuses before it creates anything (empty prefix, a copy name past 64 characters, a reserved name this installation does not own that this dump needs, inbound foreign keys from tables outside the dump that are not leftovers); a dump that names a reserved table is refused on both platforms. One restore of an installation runs at a time — a second is refused, not queued. Owned leftover `_r_`/`_b_` tables are dropped after those refusals, under the lock; a leftover that will not drop is the refusal. What passes is filled into `_r_` copies and swapped in with one `RENAME TABLE`; a failure before the rename costs the copies and nothing live. SQLite still applies in place inside a transaction. The Snapshot suite is a required MySQL 8.4 CI job (`phpunit-mysql-snapshot`); the full-suite MySQL run stays advisory.

---

## ✅ What Changed

### Prefix invariant at install (Checklist Step 1)

No restorer change. The prefix is what later restore uses to tell this installation's tables from the rest of the database; this step is the install-time fence that makes that possible.

| File | Change |
|---|---|
| `app/installer/src/TablePrefix.php` (new) | Final `Pagekit\Installer\TablePrefix`. One shape `^[A-Za-z][A-Za-z0-9_]*_$`. `refusal(string): ?string` — null if installable; otherwise an operator-facing reason. Empty names `pk_`; a letter-led name missing the trailing `_` is answered with `"name_"`; anything else names the refused value and the shape. The leading letter is the reserved `_`-namespace fence — no denylist beside it. |
| `app/installer/src/Installer.php` | `check()` runs the validator only when `!$this->config` (fresh install). Every submitted connection that names a `prefix` is measured; a missing key is not a refusal (module default applies). Status `invalid-prefix` returns before any connect. `install()` throws `BadRequestHttpException` on that status, same surface as the other install refusals. |
| `app/modules/database/index.php` | mysql connection default `'prefix' => 'pk_'` (was `''`). |
| `app/console/src/Commands/SetupCommand.php` | `--db-prefix` help names the shape. A valueless flag (`null`) omits the `prefix` key; `--db-prefix=` (empty string) is handed to the installer uncorrected. |
| `app/modules/application/src/Module/Loader/EnvConfigLoader.php` | `PAGEKIT_DB_PREFIX` taken out of the generic MySQL param map. Blank reads as unset (module `pk_` stays); a non-empty value of any shape is still overlaid — boot is not the validator. Class docblock updated. |
| `AGENTS.md` | Caveat sentence: blank `PAGEKIT_DB_PREFIX` reads as unset, with `PAGEKIT_DB_DRIVER` and `PAGEKIT_DB_PORT`. |

#### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `tests/Unit/Installer/TablePrefixTest.php` (new) | Shape table (valid / invalid including leading `_`, missing trailing `_`, `.`/`-`, empty, quotes, non-ASCII); delimiter-missing correction; empty names `pk_`; reserved `_r_`/`_b_` spellings refused; every prefix the database module ships is installable. |
| `tests/Unit/Installer/InstallerTablePrefixTest.php` (new) | Fresh-install refusal before connect; every named connection measured; missing prefix key not refused; existing `config.php` never measured (empty prefix still proceeds); `install()` surfaces `invalid-prefix` and writes no `config.php`. |
| `tests/Unit/Console/SetupCommandTest.php` (new) | `--db-prefix=` fails the command with the shape message; ill-shaped is corrected, not defaulted; valueless flag leaves module `pk_`; a named prefix is the one installed with. |
| `app/modules/application/src/Tests/EnvConfigLoaderTest.php` | Overlay uses `site_` (not `pk_`, which is now the mysql default); blank prefix overlays nothing; an ill-shaped overlay still reaches the connection. |
| `app/modules/database/src/Tests/ConnectionTest.php` | Explicit empty prefix still constructs, substitutes `@table` to the bare name, and queries. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done → Verifier (test files) FAIL once (EnvConfigLoader overlay asserted `pk_`, now equal to the mysql default) → retry with `site_` → Verifier PASS; Tester PHPUnit+PHPStan PASS.

### Reserved names + dumper guard (Checklist Step 2)

No restorer change. The names a later restore invents now have one home, and a dump will not carry leftover copies — including on an empty-prefix installation, where every name otherwise reads as owned.

| File | Change |
|---|---|
| `app/installer/src/Package/Snapshot/RestoreTableNames.php` (new) | Final `Pagekit\Installer\Package\Snapshot\RestoreTableNames`. Markers `SHADOW = '_r_'` and `BACKUP = '_b_'` (equal length). `shadow()` / `backup()` prepend the live name. `isReserved()` is a byte-for-byte start match on either marker — not a search, not the whole `_`-led namespace, not case-folded. |
| `app/installer/src/Package/Snapshot/DatabaseDumper.php` | `schema()` skips a reserved name before the prefix filter. Class docblock names the skip. Empty-prefix dump still runs. |

#### Tests (Checklist Step 2)

| File | Change |
|---|---|
| `tests/Unit/Snapshot/RestoreTableNamesTest.php` (new) | Live name recoverable from both invented names; markers same length and distinct; invented names (empty-prefix, doubled, marker alone) reserved; ordinary / mid-name / lookalike / different-case names not reserved; every installable prefix's tables stay unreserved; the markers themselves fail `TablePrefix`. |
| `tests/Unit/Snapshot/DatabaseDumperTest.php` | Leftover `_r_`/`_b_` copies (and their half-written rows) stay out of empty-prefix and prefixed dumps; empty-prefix dump still succeeds; `_migrations` still dumped; `a_b_` prefix keeps its own tables. |

Gates: Verifier (production) PASS; Tester PASS; test-writer done; Verifier (test files) PASS; Tester PASS. No deviations.

### Preflight refusals before the first CREATE (Checklist Step 3)

MySQL restore now fails closed while the installation is still whole. The in-place apply is unchanged; shadow fill and cut-over are later steps.

| File | Change |
|---|---|
| `app/installer/src/Package/Snapshot/DatabaseRestorer.php` | Empty-prefix refusal before the dump is opened. After `inspect()`, `preflight()` cheapest-first: copy name over 64 characters (`mb_strlen` on the shadow; `MYSQL_NAME_LIMIT` is literal 64), then one walk of `listTableNames` for owned leftovers and non-owned collisions, then inbound FKs from `information_schema` in the current schema. Folding from `SHOW GLOBAL VARIABLES LIKE 'lower_case_table_names'` (1 and 2 fold). Reserved name in the dump refused in `name()` before the prefix check. `inspect()` also returns dumped names; `restore()` still answers `tables`/`rows`. |
| `app/installer/src/Package/Snapshot/RestoreTableNames.php` | `live()` is the remainder after the marker (`null` if none). `isReserved()` delegates, so ownership is not a second spelling of the markers in the restorer. |

#### Tests (Checklist Step 3)

| File | Change |
|---|---|
| `tests/Unit/Snapshot/DatabaseRestorerTest.php` | Empty-prefix MySQL restore refused before the dump is opened (test double); SQLite empty-prefix still restores. Copy name over 64 refused, 64 allowed, non-ASCII counted as MySQL counts. Owned leftovers refused whether or not this dump needs the name (sorted); a reserved name that is neither owned nor needed is left alone; folding 1/2 recognises the leftover, a different-case marker is another table when the server does not fold. Inbound FK from outside the dump refused naming child, parent, and constraint. Reserved name in the dump refused on both platforms, empty prefix included. Leftover copies are no obstacle on SQLite. Every refusal leaves live tables and creates no reserved names. |
| `tests/Unit/Snapshot/RestoreTableNamesTest.php` | `live()` is the remainder after one marker (a copy of a copy is not this installation's); `isReserved()` is that remainder not being null. |
| `tests/Unit/Snapshot/ConnectionThatAnswersForAMysqlServer.php` (new) | Connection that reports MySQL and answers folding plus inbound FKs; records what was asked so a refusal that needed no MySQL-only SQL is distinguished from one that did. Tables are still listed from the real database. |

Gates: Verifier (production) FAIL once (`preflight` docblock restated call order and named a future swap path) → retry → PASS; Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Shadow DDL rewriter + fill machinery (Checklist Step 4)

Copies can be filled; `restore()` still applies MySQL in place. Cut-over is the next step.

| File | Change |
|---|---|
| `app/installer/src/Package/Snapshot/ShadowSchema.php` (new) | Final `Pagekit\Installer\Package\Snapshot\ShadowSchema`. Tokenises dump DDL (words, backticks, quoted values, comments) and splices only three things: the table the statement works on becomes the `_r_` copy; a `REFERENCES` target the dump names becomes that table's copy (schema-qualified or outside the dump stay); a `CONSTRAINT … FOREIGN`/`CHECK` name is regenerated (`_r_` + 24 hex of sha256 over a per-instance run token, the table, and the dumped name). Every other byte is copied through. A statement that is not `CREATE [TEMPORARY] TABLE [IF NOT EXISTS] <this table> (`, `ALTER TABLE <this table> ADD …`, or `CREATE [UNIQUE\|FULLTEXT\|SPATIAL] INDEX … ON <this table> (` — or that stacks a second statement, is schema-qualified, or has an unterminated quote or comment — is refused. Rewritten names are always backtick-quoted. |
| `app/installer/src/Package/Snapshot/DatabaseRestorer.php` | `fill(file, dumped): list<string>` creates the `_r_` copies in dump order (rewritten DDL, then a prepared `INSERT` with quoted identifiers and bound values), lists each copy for cleanup before its first statement, and on any failure `drop()`s every copy it began. `drop()` tries every name then refuses naming the ones still there; `fill()` swallows that so the failure that caused cleanup is what the caller sees. `fill` is public (`restore()` does not call it; PHPStan 8 would flag an unused private). `apply()` / `recreate()` / `replace()` unchanged. |

#### Tests (Checklist Step 4)

| File | Change |
|---|---|
| `tests/Unit/Snapshot/ShadowSchemaTest.php` (new) | Byte-for-byte: every statement of a table's schema works on the copy and is otherwise as dumped (quoted and unquoted, `TEMPORARY` / `IF NOT EXISTS` / `UNIQUE`\|`FULLTEXT`\|`SPATIAL` INDEX, backticks escaped); a value / `DEFAULT` that spells a table name is left; comments and quoted keywords are read past; dump-internal `REFERENCES` retargeted, outside-dump and schema-qualified left; FOREIGN/CHECK names regenerated (same instance deterministic, two instances distinct, unique per (table, constraint), ≤ 64, `_r_`-led); UNIQUE / PRIMARY / index names stay; `LIKE` / `RENAME` / `DROP` / `INSERT` / stacked / other-schema / other-table / unterminated quote or comment / nameless `REFERENCES` refused, naming the table and an excerpt. |
| `tests/Unit/Snapshot/DatabaseRestorerTest.php` | Fill: copies hold dumped rows while live tables (post-snapshot writes included) stay; schema comes from the dump, not the live table; dump-internal FKs point at copies. Failure (unusable DDL, half-created copy, row that will not insert) leaves no `_r_` table and live tables untouched; a drop that also fails still reports the fill failure and tries every copy. Fill fixtures omit named secondary indexes (SQLite keeps those per schema). |
| `tests/Unit/Snapshot/ConnectionThatWillNotDropATable.php` (new) | Connection that throws on `DROP TABLE` (all, or one named table) so cleanup-on-failure can be asserted without a server that actually refuses the drop. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS. No deviations.

### Cut-over + delete the in-place MySQL apply (Checklist Step 5)

MySQL restore is now copies then one rename. The in-place drop-and-recreate is gone; `apply()` / `recreate()` stay the SQLite apply.

| File | Change |
|---|---|
| `app/installer/src/Package/Snapshot/DatabaseRestorer.php` | `replace()` MySQL branch is `fill()` then `swap()`. `cutOver()` builds one `RENAME TABLE` from the copies `fill()` reported (`RestoreTableNames::live()`), quoting every name; a live counterpart is set aside first (`live TO _b_live` before `_r_live TO live`), decided through `comparable()`, and a dumped table the installation no longer has contributes only the copy half. A failed rename drops the copies and rethrows; a failed backup drop after a successful rename is a log line (`reportUndropped`) and the restore stands. Optional `?LoggerInterface` defaults to `NullLogger`. Class docblock no longer describes a partly replaced MySQL install. `drop()` now says "names of its own" (copies and tables set aside). `preflight()` stays in `restore()` so a refusal is not wrapped as "Failed to restore the database from …". |
| `app/installer/index.php` | Snapshotter constructs `DatabaseRestorer` with `$app->get('log')`. |
| `README.md` | Operator note next to the snapshots passage: roughly twice the dumped tables' disk until backups are dropped; the rename takes metadata locks (a brief stall, not zero downtime); FK/CHECK names on restored tables are regenerated tokens. |

#### Tests (Checklist Step 5)

| File | Change |
|---|---|
| `tests/Unit/Snapshot/DatabaseRestorerTest.php` | One `RENAME TABLE` reaches the server (live aside first; dumped-only tables contribute only the copy half; folding decides whether a different-case live table is set aside). Failed swap leaves live tables byte-identical and no leftovers — or the copy that would not drop, with the swap still the failure reported. Success path: backups gone, dumped-only table back, post-snapshot table outside the dump untouched. Failed backup drop after a successful rename is a warning on a successful restore; a log that cannot take the line does not undo it. Refusals stay unwrapped; fill and cut-over failures are wrapped. |
| `tests/Unit/Snapshot/ConnectionThatWillNotSwapTables.php` (new) | Connection that records every `RENAME TABLE` and refuses it, so the statement and the leftover state are assertable without a server that actually fails the rename. Fill and cleanup still run against the real database. |
| `tests/Unit/Snapshot/ConnectionThatAnswersForAMysqlServer.php` | No longer `final`. `platformUnderneath()` lists and runs SQL on the real platform while the stand-in still answers folding and inbound FKs — the swap double has to fill copies for real. |
| `tests/Unit/Snapshot/SnapshotDatabase.php` | `isSqlite()` rationale rewritten: it names which engine the run is against (dump platform, FK-check spelling, in-place vs copies), not a half-applied restore as a platform property. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done → Verifier (test files) FAIL once (stale `isSqlite()` rationale in `SnapshotDatabase.php` still described a half-applied restore as a platform property, which the rewritten restorer tests contradict) → retry → PASS; Tester PHPUnit+PHPStan PASS.

### Serialization + leftover cleanup (Checklist Step 6)

One MySQL restore of an installation at a time, and leftover copies a previous restore left behind come off before this one fills any. Presence-refusal of owned leftovers is gone.

| File | Change |
|---|---|
| `app/installer/src/Package/Snapshot/DatabaseRestorer.php` | `acquire()` inside `preflight()`, after the name-length refusal and before the table listing: `GET_LOCK` with bound name and timeout 0; `1` and `'1'` are the lock, anything else is a concurrent-restore refusal. `lockName()` is `pagekit.restore.` plus 32 hex of sha256 over `getDatabase()` and the prefix. `release()` in `restore()`'s `finally` (a failed `RELEASE_LOCK` is swallowed). `refuseNamesAlreadyInUse()` returns owned leftovers sorted instead of refusing them; a non-owned collision still throws before anything is dropped. `refuseInboundReferences()` skips a child that is one of those leftovers. `clear()` runs from `restore()` after `preflight()` returns — own `foreign_key_checks` window, then `drop()`; a drop that fails is an unwrapped refusal naming the leftovers. |

#### Tests (Checklist Step 6)

| File | Change |
|---|---|
| `tests/Unit/Snapshot/DatabaseRestorerTest.php` | Second restore refused, not queued (timeout 0). `GET_LOCK` as `1` or `'1'` is the lock; `null` / `false` / `'0'` are not. Length and reserved-in-dump still leave the server unasked; a copy that fits stops on the lock, whose message names no table. Lock held while the database is read, given up on refuse / failed swap / success. Same schema+prefix → same name; a different prefix or schema → a different one; the name is a bound value, never in the statement. Owned leftovers (needed or not, folded or not) cleared before fill; a leftover FK into the dump is no refusal; a neighbour's still is, and that refusal leaves the leftover standing. Inter-leftover FKs still drop; the connection's FK setting is what it was afterwards. A leftover that will not drop is an unwrapped refusal naming every leftover. A reserved-marker table whose remainder is not this prefix, and that no copy needs, is still there after a restore that succeeded. Presence-refusal cases rewritten rather than deferred. |
| `tests/Unit/Snapshot/ConnectionThatAnswersForAMysqlServer.php` | Answers `GET_LOCK` / `RELEASE_LOCK` through `fetchOne` and records both. `$locked` stands for a second session holding it; `$handsNumbersBackAsText` is the emulated-prepare `'1'`/`'0'` arm. |
| `tests/Unit/Snapshot/ConnectionThatWillNotSayWhoHoldsTheLock.php` (new) | `GET_LOCK` returns whatever the test plants (`null` / `false` / `'0'`), so a server that will not say is not read as a lock. |
| `tests/Unit/Snapshot/ConnectionThatIsRestoredTwiceAtOnce.php` (new) | Asks a second restorer on another session as the first `CREATE TABLE` of a copy runs — the only stretch a second restore must not share. |
| `tests/Unit/Snapshot/ConnectionOnASchemaOfItsOwn.php` (new) | `getDatabase()` answers a schema of the test's naming, so two lock names can differ without a second real database. |
| `tests/Unit/Snapshot/ConnectionThatWillNotTakeTheLockBack.php` (new) | `RELEASE_LOCK` throws after being recorded, so a give-up the server will not take is not the sentence the operator sees. |
| `tests/Unit/Snapshot/SnapshotDatabase.php` | `openAnotherSession()` — a second connection to the same named server; skips when the run has only in-memory SQLite (no second session, no lock). |

Gates: Verifier (production) FAIL once (`GET_LOCK` unhandled on MySQL doubles; leftover inbound FK could block cleanup; presence-refusal tests leftover) → refactorer retry → PASS; Tester PHPUnit+PHPStan PASS. test-writer done → Verifier (test files) FAIL (folding assertion could not fail; `GET_LOCK` string `'1'` arm unpinned) → FAIL (stale presence-refusal wording; non-owned collision UNTESTABLE) → refactorer retry (unreachable collision kept as fail-closed backstop; leftover invariant restated; production docblocks updated) → Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer added schema/lock doubles → Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

### Required Snapshot-on-MySQL CI job (Checklist Step 7)

No restorer change. The default PHPUnit suite skips every case that needs a real MySQL server; without this job those cases are green without having run.

| File | Change |
|---|---|
| `.github/workflows/php-tests.yml` | New required job `phpunit-mysql-snapshot` (`mysql:8.4`, `pdo_mysql`, no `continue-on-error`) running `./app/vendor/bin/phpunit -c phpunit-mysql.xml.dist tests/Unit/Snapshot`, written out above the advisory `phpunit-mysql` job. Advisory job and its Step 2.11 tag untouched except the comment, which now names the Snapshot suite as a globals consumer and points at the job above. |
| `phpunit-mysql.xml.dist` | Header now names both consumers of the connection globals, and that CI runs this config twice: Snapshot-narrowed as the required job, whole as the advisory one. |

No tests. test-writer skipped (workflow YAML + config comments only).

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer skipped.

### Review (Bugbot + Security) + E2E — unmatched `--step` no longer selects every session (Checklist Step 8)

No restorer change. Bugbot on the branch diff found the CSV overlay widening an unmatched `--step` to every file in `sessions/`; that fallback is gone. Security found nothing to correct. E2E then ran on the tree as it stood.

| File | Change |
|---|---|
| `.github/conductor/import-usage-csv.mjs` | The fallback that listed every file in `sessions/` is deleted. After `main()` already refuses a run with neither filter, an unmatched `--step` (no index entry, or empty `sessionIds`) writes no session file and exits non-zero. `--session` stays its own arm at the call site — one id, never the selection that matched nothing. |
| `.github/conductor/metrics.mjs` | `sessionIdsForStep` lives here: the index shape belongs to the metrics library, and `import-usage-csv.mjs` calls `main()` at load so the lookup could not stay on the CLI. Empty may not widen to every session. |

No tests. test-writer skipped (XL review; the fix-loop is conductor metrics).

Gates: Bugbot FAIL once (unmatched `--step` selected every session) → first attempt withdrawn → second landed → clean; Security clean; E2E PASS. No test-writer.

---

## 🧠 Key Decisions (Rationale)

- **One refusal method, three answers.** `TablePrefix::refusal()` is the whole surface. Empty → use `pk_`; missing delimiter → the corrected `"name_"`; otherwise the refused value plus the shape. The leading-letter rule is the reserved-namespace fence — no denylist beside it.
- **Missing key ≠ empty string.** Installer skips connections with no `prefix` key. SetupCommand writes the key only when the option is not null. EnvConfigLoader treats blank as unset. The three agree: "not given" is the module default; "given as empty" is a refusal at install / a no-op overlay at boot.
- **Boot is not the validator.** An odd `PAGEKIT_DB_PREFIX` still overlays so an existing site whose tables are called that keeps booting.
- **Mysql default flip.** sqlite already shipped `pk_`. The disagreement was the bug. Installed sites carry `prefix` in `config.php`; a hand-written file that omitted it and relied on implicit `''` is the one edge the flip moves.
- **One naming class.** Markers, name builders, and the reserved-name reading live in `RestoreTableNames`. The restorer consumes `live()` rather than restating the markers; a second spelling would be the bug.
- **Skip, do not refuse.** An empty-prefix dump with leftovers still writes. Refusing would block uninstall on the installations that most need the skip (`str_starts_with($name, '')` is every table).
- **Front of the name, exact case.** `isReserved` is `str_starts_with` on the lowercase markers. A marker in the middle (`a_b_users`) or a different case (`_R_`) is someone else's table. Folding is the restorer's: `live()` stays byte-exact; `comparable()` applies the server's rule.
- **One walk for collision and leftover.** A reserved remainder that starts with this prefix is a leftover — collected (sorted) for `clear()`, whether or not this dump needs the name. A reserved remainder that does not is refused only when this restore needs that exact name, otherwise left alone. Two scans were rejected: every name the restore needs is owned by construction; the non-owned branch is the fail-closed backstop.
- **Cheapest first.** Length (no SQL, no lock), then the lock, then the table listing, then `information_schema`. Empty prefix, name length, and reserved-in-dump therefore throw on a connection that only *reports* MySQL, and they leave the lock unasked.
- **64, not 63.** `MYSQL_NAME_LIMIT` is literal 64. DBAL's MySQL max is the un-overridden 63 and would refuse names MySQL takes. One `mb_strlen` of the shadow name answers for both copies (markers are equal length).
- **SHOW, not `@@`.** Folding is `SHOW GLOBAL VARIABLES LIKE 'lower_case_table_names'` — the variable has no session value, and `@@…` would be rewritten by `Connection::replacePrefix`. 1 and 2 fold; anything else, a missing row included, compares as written.
- **Remainder, not a second spelling.** `RestoreTableNames::live()` is the counterpart of `shadow()`/`backup()`; `isReserved()` delegates. Folding stays the restorer's.
- **`inspect` lists names; `restore` still answers tables/rows.** The refusals measure against the dumped names; the caller's summary is unchanged.
- **Reserved-in-dump before the prefix check.** A dump naming `_r_`/`_b_` is refused on both platforms and on an empty prefix, where `str_starts_with($name, '')` would otherwise pass the name through.
- **Whitelist, not rewrite-what-you-recognise.** A statement must be `CREATE [TEMPORARY] TABLE [IF NOT EXISTS] <this table> (`, `ALTER TABLE <this table> ADD …`, or `CREATE [UNIQUE|FULLTEXT|SPATIAL] INDEX … ON <this table> (`. Passing unrecognised SQL through would let `executeStatement()` run a second statement behind a `;` as readily as the first. `CREATE TABLE … LIKE`, `ALTER TABLE … RENAME`, a schema-qualified name, and a stacked statement all refuse before anything runs.
- **Run token in constraint names.** Regenerating from table + dumped name alone collides on the ordinary case of restoring one snapshot twice: the first restore left its regenerated name on the live table, and MySQL keeps FK/CHECK names per schema with no way to rename them. `_r_` + 24 hex of sha256 over a per-instance random run token, the table, and the dumped name (27 characters). One instance is deterministic; two instances never share a name.
- **FOREIGN/CHECK only.** `CONSTRAINT … UNIQUE`, `PRIMARY KEY`, and every index name stay as dumped — those are per-table in MySQL. SQLite keeps index names per schema, so a SQLite-leg fill of a dump carrying a named secondary index collides with the live index; fill fixtures omit them. DBAL's MySQL platform writes indexes inline in `CREATE TABLE` and emits no `CREATE INDEX`.
- **Comments as well as quotes.** The tokenizer reads past `--`, `#`, and `/* */`; an unterminated quote or comment refuses rather than being read to the end. Handling quotes only leaves a desynced reader that can miss a `REFERENCES` and leave a copy's foreign key pointing at a live table. Every byte outside a rewritten token comes through unchanged, `DEFAULT 'pk_users'` included.
- **`fill` is public and handed the dumped names.** `replace()` now calls it; it stays public so the fill tests can still exercise the half that writes nothing live. The dump is a generator, so a foreign key can name a table that comes later in the file — the caller passes the names `inspect()` already collected. A copy is listed for cleanup before its DDL runs, so one that got halfway is dropped too. A failure leaves no `_r_` table; a success leaves the copies standing for whoever swaps them in.
- **`drop` tries every table, then reports.** One name that will not go does not leave the rest standing. `fill()` swallows that refusal so the failure that caused cleanup is the one the caller acts on. The wording is "names of its own" rather than "copies" — the same method drops both the copies and the tables set aside, and it keeps "could not be dropped" for the fill-cleanup assertion. The row-binding loop is repeated from `apply()` rather than shared — `apply()`/`recreate()` stay the SQLite apply and are not unified with the shadow path.
- **Optional logger, not a required one.** A restore whose backup drop fails still returns its summary; the failure is a warning and never an exception. The constructor takes `?LoggerInterface` defaulting to `NullLogger` (`Helper/Composer.php` already does this); `app/installer/index.php` passes `$app->get('log')`. A required parameter would have rewritten every construction site, including the Snapshot suite. Swallowing the failure was rejected.
- **The statement is built from the copies, not the dump list.** `cutOver()` reads the live name off each copy with `RestoreTableNames::live()`, so the one `RENAME TABLE` can only rename what `fill()` reported creating, and a name that is not a copy refuses. `live TO _b_live` stands before `_r_live TO live` for the same table. A dumped table the installation no longer has contributes only the second half and produces no `_b_` name. Whether a live counterpart exists is decided through `comparable()`, so a folding server matches one in another case and a case-sensitive one does not. There is no empty-copy-list guard — `end()` already refuses a dump holding no tables.
- **Failed rename vs failed backup drop.** A failed cut-over drops the copies and rethrows the server's own failure. A failed backup drop after a successful rename is logged and the restore stands. After a failed MySQL restore no `_r_`/`_b_` table exists and every live table is byte-identical, unless dropping a copy is what also failed — that leftover is the next restore's leftover-cleanup problem, not this restore's.
- **`preflight` stays in `restore()`, not `replace()`.** `restore()` wraps everything `replace()` throws in "Failed to restore the database from …". A refusal moved inside would reach the panel with its own message replaced. Refusals stay unwrapped; fill and cut-over failures are always wrapped.
- **Lock inside `preflight`, not around `restore()`.** Taken after the name-length refusal and before the table listing, so empty prefix / too-long copy / reserved-in-dump still refuse without a MySQL-only statement, while every later observation is made under the lock. Wrapping the whole call would have `GET_LOCK` run before a refusal that needs nothing of the server. Timeout 0: a second restore is refused, not queued — whoever asked is waiting on a page.
- **Bound lock, hashed name.** Name and timeout are parameters, not statement text, so `replacePrefix()` has no `@`-led fragment to rewrite. Anything other than `1` / `'1'` from `GET_LOCK` is no lock (a silent server included). The name is `pagekit.restore.` plus 32 hex of sha256 over schema and prefix (`getDatabase()`, not the `dbname` parameter — same schema `information_schema` is already measured against). Two restorers on the same schema and prefix lock on the same name; a different prefix or schema does not.
- **`clear` after every other refusal.** It is the only write a restore's preparation makes, so a restore turned away for any other reason leaves even the leftovers where they were. Its drop failure is an unwrapped refusal (same invariant as the others) and names the tables it could not remove. Leftovers that point at each other cannot be dropped in name order with references enforced, so `clear()` opens its own `foreign_key_checks` window and puts the connection back on what it was — not the apply window `restore()` opens later, which would run `SHOW SESSION VARIABLES` before refusals that must not ask the server.
- **Leftover inbound FK is not a refusal.** The leftover comes off in `clear()` before the first copy exists, so nothing holds it at the swap. Refusing would leave the one restore that would have removed it unable to run. A neighbour's inbound FK still refuses, and that refusal does not drop the leftover.
- **Unreachable collision kept.** A name a restore needs is a dumped table behind a marker, and `name()` holds dumped tables to the installation's prefix, so the ownership branch takes every collision that can occur. Deleting the throw would let `fill()`'s cleanup drop a foreign table if that ever stopped being true; narrowing ownership to reach the throw would leave a copy of a table the dump no longer names with nothing to clear it. The testable stand-in: a reserved-marker table whose remainder is not this prefix and whose name no copy needs is still standing after a restore that succeeded.
- **A second job, not a matrix leg.** `phpunit-mysql-snapshot` is written out beside `phpunit-mysql`. A matrix would give both legs one name (with the leg in parentheses) and one `continue-on-error`, which is the whole difference between required and advisory. Placed above the advisory job so the blocking run reads first; nothing inside `phpunit-mysql` is touched, its 2.11 tag included.
- **Path argument, not a third config.** The suite is narrowed with `-c phpunit-mysql.xml.dist tests/Unit/Snapshot`. The config's job is the connection globals; a new `<testsuite>` or a third xml would be a second spelling of the same suite, and the local check would no longer be the command the gate runs.
- **Shared Composer cache, unedited collector.** The cache key is the one every other PHP 8.5 job in the workflow uses — a job-unique key would buy a second copy of the same `app/vendor` per run. `quality-snapshot.mjs` matches jobs by exact name, so the new job does not answer for `phpunit-mysql`; the dashboard still records the full-suite MySQL run as `required: false` and reads the Snapshot job off the workflow run's own conclusion.
- **Empty step selection stays empty.** The `sessions/` listing is deleted, not gated. `main()` already refuses a run with neither filter; the only remaining widen was a `--step` the index cannot answer, and that overwrite lands on unrelated steps (`--push` then publishes them). A `--step` with no index entry or empty `sessionIds` writes nothing and exits non-zero.
- **Lookup in the library, not the CLI.** `sessionIdsForStep` moved into `metrics.mjs` because the CSV importer calls `main()` at load — nothing after that line is importable. A main-guard on the CLI was rejected. `--session` stays a call-site arm rather than a filter parameter: it names one id and cannot be the empty match the error reports.

---

## 💥 Breaking Changes (Extensions)

A fresh MySQL install that does not name a prefix is created with `pk_`, not an empty one. `--db-prefix=` is refused instead of installing empty. Blank `PAGEKIT_DB_PREFIX` no longer overlays `''`. Existing `config.php` values, empty included, are unchanged. A MySQL restore of an empty-prefix installation is refused. A dump that names a `_r_`/`_b_` table is refused on both platforms. A restore whose copy names would exceed 64 characters, that finds a reserved name this installation does not own that this dump needs, or that would take inbound foreign keys from tables outside the dump (leftovers excepted) with it, is refused with the installation left as it was. A second MySQL restore of the same installation while one is running is refused rather than queued. Owned leftover `_r_`/`_b_` copies are dropped by the next restore instead of refusing it; a leftover that will not drop is the refusal. MySQL restore is copies then one rename, not an in-place drop-and-recreate; a failure before the rename leaves live tables as they were. Foreign-key and CHECK names on MySQL-restored tables are regenerated tokens.

---

## ⚠️ Risks & Rollout Notes

A hand-written `config.php` that omitted `prefix` on mysql now reads `pk_` and will not find unprefixed tables. Sites the installer wrote are unaffected (`persistableDatabaseConfig()` stores the resolved prefix). Empty-prefix MySQL restore is refused (the message names reinstalling with a prefix; `pk_` is the default). Owned leftover `_r_`/`_b_` copies are dropped by the next restore; one that will not drop refuses until it is removed by hand. A reserved `_r_`/`_b_` table whose remainder is not this installation's is left alone unless this dump needs that exact name. Inbound foreign keys from tables outside the dump (not leftovers) have to be dropped before a restore. A dump omits leftover `_r_`/`_b_` tables rather than carrying them; uninstall still has a dump. A MySQL restore holds the dumped tables twice over until the tables they replaced are dropped; the rename takes a metadata lock on every table in it (a brief stall, not zero downtime). A dump whose DDL is not one of the three recognised statement shapes is now refused on `restore()`, not only on a direct `fill()`. A SQLite-leg fill of a dump that carries a named secondary index collides with the live index of that name (MySQL restore does not meet this; DBAL emits those indexes inline). A backup drop that fails after a successful rename leaves `_b_` tables on disk and logs a warning — the site is already restored; the next restore drops those names. A second restore of the same installation while one is running is refused (timeout 0); asking again after the first finishes is the recovery. The Snapshot-on-MySQL job is blocking in the workflow (`continue-on-error` absent); marking it a GitHub required status check is a ruleset change — the YAML cannot do that.

---

## 🔐 Security & Data Impact

Shape rule: leading letter keeps the `_`-led namespace out of new installs; no `.` / `-` / quotes in unquoted identifiers. Fresh-install refusal happens before a connection is opened. No schema migration. A dump no longer carries leftover restore copies, so an uninstall cannot replay a half-written `_r_`/`_b_` table over the live one. The reserved-name reading is prefix-only and case-exact, so `_migrations` and `a_b_*` tables stay in the dump. MySQL restore refusals run after the dump is read and before the first CREATE (empty prefix before the dump is opened). Ownership is the remainder after the marker plus this installation's prefix, compared the way the server folds names. A reserved table that is neither owned nor needed is left alone. Inbound FKs are read from `information_schema` in the current schema only; a leftover child's inbound FK is not a refusal. Shadow DDL is a whitelist: the only table names fill SQL can name are `_r_` plus a table the dump declared; string literals are never rewritten; stacked statements, `CREATE TABLE … LIKE`, and schema-qualified names refuse. Rewritten identifiers are backtick-quoted (no `@`-placeholder for `Connection::replacePrefix` to rewrite). The one `RENAME TABLE` is built from the copies `fill()` reported, not from the dump's name list, and every name in it is quoted. Rows go in through bound values. A copy is listed for drop before its first statement; a failed fill or a failed rename tries every copy before reporting, and still surfaces the failure that caused cleanup. A failed backup drop after the rename is a log line, never an exception that would report a restored site as unrestored. The restore lock is a bound `GET_LOCK` over a hashed schema+prefix name; only `1`/`'1'` is a lock; `release()` runs in `finally` and a failed `RELEASE_LOCK` is not what the operator is told. `clear()` drops only owned leftovers, after every other refusal, and puts `foreign_key_checks` back as it found them.

---

## 🛡️ No-Mercy Compliance

One validator, both entries. No shim for the old empty mysql default. Existing empty-prefix installs are not revalidated (not a compatibility layer — they already have tables of that name); MySQL restore refuses them instead of inventing a second apply. One naming class; `live()` is the remainder, not a second marker spelling in the restorer. Empty-prefix dump still runs. Preflight is in the restorer, not a wrapper around in-place apply. Identifier cap is literal 64, not a DBAL adapter. Presence-refusal of owned leftovers was deleted, not kept beside `clear()`. The non-owned collision throw stays as a fail-closed backstop although no dump that passes `name()` can reach it — not narrowed to make it testable, not deleted. One MySQL path: fill + one rename; the in-place MySQL apply was deleted in the same commit that wired it. `apply()`/`recreate()` stay the SQLite apply and are not unified with the shadow path. `fill()` stays public so the fill tests can still reach it without swapping; nothing else in the tree calls it. Unrecognised dump DDL is refused, not wrapped and passed through. An optional logger with a `NullLogger` default is not a second reporting path — the restore either happened or it did not; the line is only for tables nobody reads. `1` and `'1'` from `GET_LOCK` are the same lock, not two code paths; `clear()`'s `foreign_key_checks` window is not a second apply. The required Snapshot job is a second workflow job, not a wrapper or matrix over the advisory full-suite run; that job and its 2.11 tag stay, and the collector was not taught to treat the new name as the old one. The unmatched-`--step` fallback that listed every session is deleted, not made conditional; empty stays empty.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: Step 1 — EnvConfigLoader overlay assertion changed from `pk_` to `site_` after the mysql default flip — asserting the default no longer proved the environment arrived. Step 2 — none. Step 3 — first Verifier pass failed on the `preflight` docblock restating call order and naming a future swap path; rewritten to say what the refusals do, not the order or the cut-over that is not wired yet. Step 4 — none. Step 5 — first test-files Verifier pass failed on the stale `isSqlite()` rationale in `SnapshotDatabase.php`, which still described a half-applied restore as a platform property; rewritten to name which engine the run is against. Step 6 — production Verifier FAIL once (`GET_LOCK` unhandled on MySQL doubles; leftover inbound FK could block cleanup; presence-refusal tests leftover) then PASS; test-files Verifier FAIL (folding assertion could not fail; `GET_LOCK` string `'1'` arm unpinned) then FAIL (stale presence-refusal wording; non-owned collision UNTESTABLE) — unreachable collision kept as fail-closed backstop, leftover invariant restated, production docblocks updated; test-writer added schema/lock doubles; then PASS. Step 7 — none. Step 8 — Bugbot FAIL once (unmatched `--step` selected every session); first fix withdrawn, second landed (fallback deleted); then Bugbot clean. Security clean. E2E PASS. No test-writer.

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

<!-- Filled by the post-close review after Finalize: what the finished work left unowned,
     one bullet per finding with the ROADMAP step whose area it belongs to. Doc-writer leaves None. -->

_TBD / None_

---

## 🧹 Cleanup

<!-- Removed in passing (deleted files, dropped baseline/ignore entries, dead code). Doc-writer from
     the handover; the post-close review adds what the diff shows and the handover missed. -->

_TBD / None_

---

## 🛡️ Audit

<!-- No-Mercy leftovers of the shipped diff that have no owner (forward-debt tags, added baseline
     entries, ANOMALIES patterns), each with the ROADMAP step that resolves it. Post-close review. -->

_TBD / None_

---

## 🎁 Bonus

<!-- Work delivered beyond the ticket. Doc-writer from the handover; the post-close review adds
     what the diff shows and the handover missed. -->

_TBD / None_

---

## 🔍 Research

<!-- The verified facts behind each DECISION the post-close review raised — symbols, call chain,
     what each exit deletes or adds — so the maintainer can decide without re-reading the tree. -->

_TBD / None_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_7_1a_Atomic-MySQL-Restore_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_1a_Atomic-MySQL-Restore.md`
- Predecessor: Step 2.7.1 — Snapshot & Three-Stage Uninstall
- Successor: Step 2.7.1b — Package Module Boundary

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
