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

A fresh install can no longer be created with an empty or ill-shaped table prefix. The mysql module default is `pk_`, matching sqlite. Existing installations — empty prefix included — keep booting; only a new install is measured. A dump skips leftover `_r_`/`_b_` tables (empty prefix included) so a half-written restore copy cannot be snapshotted back over live data. A MySQL restore now refuses before it creates anything (empty prefix, a copy name past 64 characters, leftover or colliding `_r_`/`_b_` tables, inbound foreign keys from outside the dump); a dump that names a reserved table is refused on both platforms. The in-place MySQL apply still runs after those refusals; the shadow cut-over is later checklist steps.

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

---

## 🧠 Key Decisions (Rationale)

- **One refusal method, three answers.** `TablePrefix::refusal()` is the whole surface. Empty → use `pk_`; missing delimiter → the corrected `"name_"`; otherwise the refused value plus the shape. The leading-letter rule is the reserved-namespace fence — no denylist beside it.
- **Missing key ≠ empty string.** Installer skips connections with no `prefix` key. SetupCommand writes the key only when the option is not null. EnvConfigLoader treats blank as unset. The three agree: "not given" is the module default; "given as empty" is a refusal at install / a no-op overlay at boot.
- **Boot is not the validator.** An odd `PAGEKIT_DB_PREFIX` still overlays so an existing site whose tables are called that keeps booting.
- **Mysql default flip.** sqlite already shipped `pk_`. The disagreement was the bug. Installed sites carry `prefix` in `config.php`; a hand-written file that omitted it and relied on implicit `''` is the one edge the flip moves.
- **One naming class.** Markers, name builders, and the reserved-name reading live in `RestoreTableNames`. The restorer consumes `live()` rather than restating the markers; a second spelling would be the bug.
- **Skip, do not refuse.** An empty-prefix dump with leftovers still writes. Refusing would block uninstall on the installations that most need the skip (`str_starts_with($name, '')` is every table).
- **Front of the name, exact case.** `isReserved` is `str_starts_with` on the lowercase markers. A marker in the middle (`a_b_users`) or a different case (`_R_`) is someone else's table. Folding is the restorer's: `live()` stays byte-exact; `comparable()` applies the server's rule.
- **One walk for collision and leftover.** A reserved remainder that starts with this prefix is a leftover — refused even if this dump does not need the name. A reserved remainder that does not is refused only when this restore needs that exact name, otherwise left alone. Two scans were rejected: every name the restore needs is owned by construction; the non-owned branch is the fail-closed backstop.
- **Cheapest first.** Length (no SQL), then the table listing, then `information_schema`. Empty prefix, name length, and reserved-in-dump therefore throw on a connection that only *reports* MySQL.
- **64, not 63.** `MYSQL_NAME_LIMIT` is literal 64. DBAL's MySQL max is the un-overridden 63 and would refuse names MySQL takes. One `mb_strlen` of the shadow name answers for both copies (markers are equal length).
- **SHOW, not `@@`.** Folding is `SHOW GLOBAL VARIABLES LIKE 'lower_case_table_names'` — the variable has no session value, and `@@…` would be rewritten by `Connection::replacePrefix`. 1 and 2 fold; anything else, a missing row included, compares as written.
- **Remainder, not a second spelling.** `RestoreTableNames::live()` is the counterpart of `shadow()`/`backup()`; `isReserved()` delegates. Folding stays the restorer's.
- **`inspect` lists names; `restore` still answers tables/rows.** The refusals measure against the dumped names; the caller's summary is unchanged.
- **Reserved-in-dump before the prefix check.** A dump naming `_r_`/`_b_` is refused on both platforms and on an empty prefix, where `str_starts_with($name, '')` would otherwise pass the name through.

---

## 💥 Breaking Changes (Extensions)

A fresh MySQL install that does not name a prefix is created with `pk_`, not an empty one. `--db-prefix=` is refused instead of installing empty. Blank `PAGEKIT_DB_PREFIX` no longer overlays `''`. Existing `config.php` values, empty included, are unchanged. A MySQL restore of an empty-prefix installation is refused. A dump that names a `_r_`/`_b_` table is refused on both platforms. A restore whose copy names would exceed 64 characters, that finds owned leftover copies, or that would take inbound foreign keys from tables outside the dump with it, is refused with the installation left as it was.

---

## ⚠️ Risks & Rollout Notes

A hand-written `config.php` that omitted `prefix` on mysql now reads `pk_` and will not find unprefixed tables. Sites the installer wrote are unaffected (`persistableDatabaseConfig()` stores the resolved prefix). Empty-prefix MySQL restore is refused (the message names reinstalling with a prefix; `pk_` is the default). Owned leftover `_r_`/`_b_` copies refuse the restore until they are dropped; dropping them automatically is later leftover cleanup. Inbound foreign keys from tables outside the dump have to be dropped before a restore. A dump omits leftover `_r_`/`_b_` tables rather than carrying them; uninstall still has a dump. The in-place MySQL apply still runs after a passing preflight.

---

## 🔐 Security & Data Impact

Shape rule: leading letter keeps the `_`-led namespace out of new installs; no `.` / `-` / quotes in unquoted identifiers. Fresh-install refusal happens before a connection is opened. No schema migration; no existing table is renamed. A dump no longer carries leftover restore copies, so an uninstall cannot replay a half-written `_r_`/`_b_` table over the live one. The reserved-name reading is prefix-only and case-exact, so `_migrations` and `a_b_*` tables stay in the dump. MySQL restore refusals run after the dump is read and before the first CREATE (empty prefix before the dump is opened). Ownership is the remainder after the marker plus this installation's prefix, compared the way the server folds names. A reserved table that is neither owned nor needed is left alone. Inbound FKs are read from `information_schema` in the current schema only.

---

## 🛡️ No-Mercy Compliance

One validator, both entries. No shim for the old empty mysql default. Existing empty-prefix installs are not revalidated (not a compatibility layer — they already have tables of that name); MySQL restore refuses them instead of inventing a second apply. One naming class; `live()` is the remainder, not a second marker spelling in the restorer. Empty-prefix dump still runs. Preflight is in the restorer, not a wrapper around in-place apply. Identifier cap is literal 64, not a DBAL adapter. Leftover presence-refusal is the later cleanup's predecessor, not a dual path.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: Step 1 — EnvConfigLoader overlay assertion changed from `pk_` to `site_` after the mysql default flip — asserting the default no longer proved the environment arrived. Step 2 — none. Step 3 — first Verifier pass failed on the `preflight` docblock restating call order and naming a future swap path; rewritten to say what the refusals do, not the order or the cut-over that is not wired yet.

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
