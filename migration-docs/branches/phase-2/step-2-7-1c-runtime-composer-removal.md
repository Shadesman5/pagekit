# Step 2.7.1c — Runtime Composer Removal

<!-- Branch doc for Roadmap Step 2.7.1c.
     Path: migration-docs/branches/phase-2/step-2-7-1c-runtime-composer-removal.md -->

**Branch:** `feature/runtime-composer-removal`
**ROADMAP Step:** 2.7.1c (Runtime Composer Removal)
**GitHub Issue:** [#297](https://github.com/Shadesman5/pagekit/issues/297)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-09-23 19:01
**Completed:** _TBD_

---

## 🎯 Overview

A package zip can be refused, read, and extracted without Composer. Nothing installs through that seam yet. `ext-zip` is a runtime requirement, and the shipped One theme declares an empty autoload map so the seam accepts it.

---

## ✅ What Changed

### Archive seam (Checklist Step 1)

`PackageArchive::open()` is the whole validation. It throws `ArchiveRefusedException` and writes nothing. No caller yet.

| File | Change |
|---|---|
| `app/package/src/Archive/PackageArchive.php` (new) | Final `Pagekit\Package\Archive\PackageArchive`. `open()` validates the zip; accessors expose name, module, version, type, title, autoload, the composer object, and the path. `extractTo()` streams the entries into a directory. |
| `app/package/src/Archive/ArchiveRefusedException.php` (new) | Final `RuntimeException`. The refusal an administrator reads. |
| `packages/pagekit/theme-one/index.php` | `'autoload' => []` after `'name'`. |
| `composer.json` | `"ext-zip": "*"` moved from `require-dev` to `require`. |
| `composer.lock` | Lock refreshed for that platform move. |

#### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageArchiveTest.php` (new) | One temp workspace per case; fixtures built with `\ZipArchive`. A refusal names its reason and leaves only the fixture. Both shipped trees open. `extractTo()` writes the entries at umask modes, refuses an archive that changed after `open()`, and does not replace an existing path. |

Gates: production verifier PASS; production tester PASS; test-file verifier PASS; tester PASS. No deviations.

---

## 🧠 Key Decisions (Rationale)

- **Entry names collide after normalisation.** Two entries that match once a trailing `/` is dropped and the name is ASCII-lowercased are refused ("more than once"), and so is a file that is another entry's folder ("both as a file and as a folder") — the manifest read takes the first match, extraction would write every entry, including on a case-insensitive filesystem. Empty segments (`a//b`) refuse instead of collapsing. A `\` refuses before the drive check, so `C:\win.php` is the backslash refusal and `C:/win.php` is the drive one. A symlink is `attributes >> 16`, independent of host endian. `index.php` twice, `Index.php` beside `index.php`, or `a` beside `a/b` refuses and writes nothing.
- **An empty archive is its own refusal.** A missing, 0-byte, or non-ZIP file is "not a readable ZIP archive"; an archive with no entries is "The archive is empty." `\ZipArchive` will not write a file with no entries, so that fixture is the 22-byte end-of-central-directory record.
- **Each manifest file is capped at 1 MiB** (`MANIFEST_MAX_BYTES`). `composer.json` and `index.php` are read into memory whole, which the 512 MiB uncompressed total does not bound. Above the cap the refusal is "is too large".
- **Extraction streams entry by entry.** `ZipArchive::extractTo()` was rejected: it overwrites existing paths and cannot hold a file to the listing `open()` accepted. `extractTo()` re-opens the zip (no handle is kept) and throws if the entry count or any index's name, size, or CRC changed. Files open with `fopen('xb')`, so an existing path fails, and a write never passes the declared size. Size and CRC are checked in PHP ("is damaged") because the zip stream reports an end-of-entry CRC error as EOF. Modes are `0666` / `0777` under the umask. A failure leaves what was already written — cleanup belongs to `replaceTree()` — so a test asserts the exception, not an empty directory.
- **The manifest is the file's only `return` outside function and class bodies.** A closure, method, or anonymous-class return does not count; a second return outside those bodies refuses, because a condition or a `goto` would pick which one PHP runs. That return must be top-level, including under `namespace X;` (the node may hold the following code, or have null `stmts` with that code as siblings). A return that exists only inside `if`, `declare`, or `try` refuses. Keys resolve last-wins, and a spread or a computed key (not a string or integer literal) clears the literal keys before it. Every `autoload` path is folder-checked, including one a later key overrides. Each such refusal is an `ArchiveRefusedException`, never an `Error` or `TypeError`.
- **Script paths and autoload paths normalise differently.** Both drop empty and `.` segments. `''`, a leading `/`, a drive prefix, or any `..` refuses. Autoload `.` is the package root. Autoload values turn `\` into `/` first, as `AutoLoader` does; script paths do not. Scripts count only when the value is non-empty; a non-string value refuses.
- **Archive text quoted in a refusal is made printable.** Control characters and invalid UTF-8 become `?`.

Safety gates before any edit: the branch contained `origin/develop`, `composer install` was a no-op, and `pnpm install --frozen-lockfile && pnpm build` exited 0. The suite was left to the tester.

---

## 💥 Breaking Changes (Extensions)

None. The install path is unchanged; the seam has no caller.

---

## ⚠️ Risks & Rollout Notes

- `composer install` now requires the zip extension, including on a host that previously needed it only for dev.
- A failed `extractTo()` leaves the files it already wrote. The caller that deletes that tree does not exist yet.

---

## 🔐 Security & Data Impact

`open()` is the trust boundary for an untrusted zip and writes nothing. Refused before a byte is written: a path that leaves the package, a `\`, a NUL, an absolute or drive path, a symlink entry, an uncompressed total above 512 MiB, a name that collides after case-folding, and a file that is also a folder. The PHP manifest is parsed, not executed, and must be one static top-level return. `extractTo()` will not overwrite a path, will not follow a link planted at the target, and does not trust the zip stream for size or CRC. Nothing calls the seam yet, so this boundary is not on the install path.

---

## 🛡️ No-Mercy Compliance

The seam is a finished class with no caller. No stub body, no fallback, no `class_alias`, no second install path. The theme's `'autoload' => []` is the declaration the seam requires.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: None. Production verifier PASS; production tester PASS; test-file verifier PASS; tester PASS.

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

- Ticket: `migration-docs/tickets/active/PROMPT_2_7_1c_Runtime-Composer-Removal_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_1c_Runtime-Composer-Removal.md`
- Predecessor: Step 2.7.1b — Package Module Boundary
- Successor: Step 2.7.2 — Module Dependency Integrity

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
