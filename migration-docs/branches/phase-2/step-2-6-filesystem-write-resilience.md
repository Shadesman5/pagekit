# Step 2.6 — Filesystem Write Resilience

<!-- Branch doc for Roadmap Step 2.6.
     Path: migration-docs/branches/phase-2/step-2-6-filesystem-write-resilience.md -->

**Branch:** `feature/filesystem-write-resilience`
**ROADMAP Step:** 2.6 (Filesystem Write Resilience — Atomic Writes & Error-Handling Hygiene)
**GitHub Issue:** [#257](https://github.com/Shadesman5/pagekit/issues/257)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-08-07 20:54
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### Atomic-write primitive: `Filesystem::dumpAtomic()` (Checklist Step 1)

| File | Change |
|---|---|
| `app/modules/filesystem/src/Filesystem.php` | New `dumpAtomic(string $file, string $content, ?int $mode = null): void` on the existing `file` service — no new class, no new service id. Resolves the target via `getPathInfo()` and requires protocol `file` with a non-empty `pathname`, else throws `\InvalidArgumentException` (refuses a stream wrapper or an adapter-backed path rather than degrading it to a non-atomic write). When the target is itself a symlink, the write target is resolved to its `realpath()` first (see Key Decisions). Writes content to a `tempnam()` temp file in the target's own directory, `chmod`s it to the target's existing permission bits when the target already exists or `($mode ?? 0666) & ~umask()` when it doesn't, then `rename()`s over the target; a blocked rename (a reader holding the target open — Windows only) falls back to a direct `file_put_contents($target, …, LOCK_EX)`; the temp file is removed on every failure path; total failure throws `\RuntimeException`. Either path ends in the new private `invalidateOpcache()` (`opcache_invalidate($target, true)` when the extension is loaded). No caller wired to it yet (Checklist Steps 2–4). |

### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `app/modules/filesystem/src/Tests/DumpAtomicTest.php` (new) | Real-filesystem coverage (`FileUtil` trait, no vfsStream) of `dumpAtomic()`: fresh write reads back correctly via `require`; replacing an existing file leaves no temp-file residue; a reader holding the target open never observes a half-written replacement (skipped on a platform that allows the rename regardless); a write through a symlink lands on the file the link points at and leaves the link itself in place; created-file permissions across a `null` mode, a mode the umask narrows, and a mode stricter than the umask; replacing a `0600`-hardened file keeps `0600`; a missing target directory, a target occupied by a directory, and an unwritable target directory each throw `\RuntimeException` with no temp-file residue (root-aware skip on the permission-based cases); a remote URL, a stream wrapper and an empty path each throw `\InvalidArgumentException`; a path routed through a registered adapter is refused rather than silently written as a local file. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

---

## 🧠 Key Decisions (Rationale)

- **`dumpAtomic()` resolves a symlinked target before the temp+rename dance (Checklist Step 1).** A `rename()` replaces whatever directory entry it is pointed at, so renaming straight over a symlinked `config.php` would replace the link itself with a plain file and orphan whatever it pointed at — a Docker deployment's `$PAGEKIT_DATA_DIR` volume (Step 2.5) is the concrete case this guards against. `dumpAtomic()` resolves the target to `realpath()` first whenever it is a symlink, so the temp file, the permission carry-over and the final `rename()` all act on the file the link points at, and the link itself survives untouched.

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

---

## ⚠️ Risks & Rollout Notes

- **The rename-blocked fallback write is not atomic (Checklist Step 1).** Where a reader holding the target open blocks the `rename()` — reachable only on Windows — `dumpAtomic()` falls back to a direct `file_put_contents($target, …, LOCK_EX)`, on which a concurrent reader without its own lock can observe a partially written file. The primitive's only wired-up deployment target so far is the Docker Linux production image (Step 2.5), where this path cannot be reached.

---

## 🔐 Security & Data Impact

- **A symlinked `config.php` keeps pointing at its target after a write (Checklist Step 1).** Resolving the link before the temp+rename dance (see Key Decisions) means the first write through `dumpAtomic()` cannot silently sever the link a Docker deployment relies on to keep `config.php` on `$PAGEKIT_DATA_DIR` (Step 2.5).
- **An existing target's permission bits survive a rewrite (Checklist Step 1).** `dumpAtomic()` carries `fileperms($target) & 0777` onto the replacement when the target already exists, so an operator-hardened `config.php` (e.g. `0600`) is not silently widened back to a fresh-file default by the next write through it.

---

## 🛡️ No-Mercy Compliance

_TBD_

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: _TBD / None_

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

- Ticket: `migration-docs/tickets/active/PROMPT_2_6_Filesystem-Write-Resilience_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_6_Filesystem-Write-Resilience.md`
- Predecessor: Step 2.5 — Docker Production Image & Deploy
- Successor: Step 2.7 — Extension Safety System

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
