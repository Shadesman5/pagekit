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

A fresh install can no longer be created with an empty or ill-shaped table prefix. The mysql module default is `pk_`, matching sqlite. Existing installations — empty prefix included — keep booting; only a new install is measured. A dump skips leftover `_r_`/`_b_` tables (empty prefix included) so a half-written restore copy cannot be snapshotted back over live data. The shadow-cut-over restorer is later checklist steps.

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

---

## 🧠 Key Decisions (Rationale)

- **One refusal method, three answers.** `TablePrefix::refusal()` is the whole surface. Empty → use `pk_`; missing delimiter → the corrected `"name_"`; otherwise the refused value plus the shape. The leading-letter rule is the reserved-namespace fence — no denylist beside it.
- **Missing key ≠ empty string.** Installer skips connections with no `prefix` key. SetupCommand writes the key only when the option is not null. EnvConfigLoader treats blank as unset. The three agree: "not given" is the module default; "given as empty" is a refusal at install / a no-op overlay at boot.
- **Boot is not the validator.** An odd `PAGEKIT_DB_PREFIX` still overlays so an existing site whose tables are called that keeps booting.
- **Mysql default flip.** sqlite already shipped `pk_`. The disagreement was the bug. Installed sites carry `prefix` in `config.php`; a hand-written file that omitted it and relied on implicit `''` is the one edge the flip moves.
- **One naming class.** Markers, name builders, and the reserved-name reading live in `RestoreTableNames` next to the dumper. The restorer is not a consumer yet; a second spelling later would be the bug.
- **Skip, do not refuse.** An empty-prefix dump with leftovers still writes. Refusing would block uninstall on the installations that most need the skip (`str_starts_with($name, '')` is every table).
- **Front of the name, exact case.** `isReserved` is `str_starts_with` on the lowercase markers. A marker in the middle (`a_b_users`) or a different case (`_R_`) is someone else's table. Server `lower_case_table_names` folding is later restorer ownership/collision work — the dumper only has to recognise names it itself would have written.

---

## 💥 Breaking Changes (Extensions)

A fresh MySQL install that does not name a prefix is created with `pk_`, not an empty one. `--db-prefix=` is refused instead of installing empty. Blank `PAGEKIT_DB_PREFIX` no longer overlays `''`. Existing `config.php` values, empty included, are unchanged.

---

## ⚠️ Risks & Rollout Notes

A hand-written `config.php` that omitted `prefix` on mysql now reads `pk_` and will not find unprefixed tables. Sites the installer wrote are unaffected (`persistableDatabaseConfig()` stores the resolved prefix). Empty-prefix MySQL restore refusal is later checklist steps. A dump now omits leftover `_r_`/`_b_` tables rather than carrying them; uninstall still has a dump.

---

## 🔐 Security & Data Impact

Shape rule: leading letter keeps the `_`-led namespace out of new installs; no `.` / `-` / quotes in unquoted identifiers. Fresh-install refusal happens before a connection is opened. No schema migration; no existing table is renamed. A dump no longer carries leftover restore copies, so an uninstall cannot replay a half-written `_r_`/`_b_` table over the live one. The reserved-name reading is prefix-only and case-exact, so `_migrations` and `a_b_*` tables stay in the dump.

---

## 🛡️ No-Mercy Compliance

One validator, both entries. No shim for the old empty mysql default. Existing empty-prefix installs are not revalidated (not a compatibility layer — they already have tables of that name). One naming class; the dumper calls it directly. Empty-prefix dump still runs (those installs already have tables of that name). No restorer change this step.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: Step 1 — EnvConfigLoader overlay assertion changed from `pk_` to `site_` after the mysql default flip — asserting the default no longer proved the environment arrived. Step 2 — none.

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
