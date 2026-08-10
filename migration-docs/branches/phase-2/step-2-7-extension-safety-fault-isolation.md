# Step 2.7 — Extension Safety System

<!-- Branch doc for Roadmap Step 2.7.
     Path: migration-docs/branches/phase-2/step-2-7-extension-safety-fault-isolation.md -->

**Branch:** `feature/extension-safety-fault-isolation`
**ROADMAP Step:** 2.7 (Extension Safety System — Extension Safety & Fault Isolation)
**GitHub Issue:** [#160](https://github.com/Shadesman5/pagekit/issues/160)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-08-10 20:22
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### Durable failure record: `ExtensionFailureStore` + `path.system` (Checklist Step 1)

| File | Change |
|---|---|
| `app/system/src/Extension/ExtensionFailureStore.php` (new) | New final `Pagekit\System\Extension\ExtensionFailureStore`, constructed from a directory path and the `Filesystem` service (the Step 2.6 `dumpAtomic()` primitive). API: `record(name, type, \Throwable): bool`, `all(): array`, `has(name): bool`, `clear(name): bool`. Every method catches `\Throwable` internally and reports success as a `bool` — nothing escapes to a caller that is itself already handling a fault. Backed by one JSON file (`extension-failures.json`, one entry per module keyed by name — `name`, `type` (`extension`\|`theme`), `class`, `message`, `file`, `line`, `time`; no stack trace) written through `dumpAtomic()` so a concurrent boot never reads a half-written record. A missing, empty, truncated, non-object or foreign-shaped file — and any entry inside it with missing or mistyped fields — reads back as empty/defaulted rather than failing the read. Its directory is created on first write, not eagerly. Not yet called from anywhere; this step lands the primitive only. |
| `public/index.php` | New `path.system` key in `$config` (`$path.'/tmp/system'`), alongside the existing `path.temp`/`path.cache`/`path.logs` entries — the directory the store above uses. No other change: the existing conditional last-resort exception handler (gated on `tmp/logs/debug.log` already existing) is untouched here. |
| `docker/entrypoint.sh` | `tmp/system` added to the `mkdir -p` list of directories the container recreates on every start, alongside `tmp/cache`, `tmp/logs`, `tmp/packages`, `tmp/sessions`, `tmp/temp`. |
| `tmp/system/.gitignore` (new) | Ignores everything written into the directory except its own `.gitignore`/`.htaccess`. |
| `tmp/system/.htaccess` (new) | `Require all denied`. |

### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `tests/Unit/Extension/ExtensionFailureStoreTest.php` (new) | Real-filesystem coverage (per-test temp dir, no vfsStream) of `ExtensionFailureStore`: record/read/has/clear round-trip via a second store instance standing in for the next request; the directory is created by the first write, not upfront; the on-disk file is JSON, never executable PHP, and carries no stack trace; a second failure for the same module replaces the first; multiple modules coexist and clear independently; a missing/empty/whitespace-only/truncated/non-object/foreign-keyed record (data-provider cases) reads as no failures rather than throwing; an entry with missing or wrongly-typed fields still reads back in the full expected shape; a non-UTF-8 exception message is substituted rather than dropping the record; the atomic-write collaborator is asserted to receive the same target file on every write, never a second path; an unwritable directory, a path already occupied by a file, and a filesystem collaborator that throws (`\RuntimeException` and `\Error` alike) each fail only the call in progress and leave any prior record intact. Root-guarded permission tests skip under `posix_geteuid() === 0`, matching the Step 2.6 suite's convention. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

---

## 🧠 Key Decisions (Rationale)

- **`path.system` is a directory of its own, not `path.cache`/`path.temp`/`storage` (Checklist Step 1).** The extension-failure record has to survive exactly the operations those existing paths do not: `CacheModule::doClearCache()` sweeps `path.cache` and `path.temp`, and `storage/` is reachable over HTTP through the `public/storage` mount. A record that is the only thing telling the next boot which extension to leave off needs a home a routine cache clear does not empty and a browser cannot request.
- **`ExtensionFailureStore` never throws (Checklist Step 1).** Every public method catches `\Throwable` internally and reports success as a `bool`. It is only ever called while another fault is already being handled — a throw here would replace the failure an administrator needs to see with one of its own, on the request that is trying to keep booting.

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

---

## ⚠️ Risks & Rollout Notes

_TBD / None_

---

## 🔐 Security & Data Impact

- **The failure record is denied over HTTP and lives outside the webroot (Checklist Step 1).** `tmp/system/.htaccess` adds `Require all denied` on top of the directory already sitting outside `public/`; the record holds a throwable's class, message and `file:line` — no stack trace — for whichever extensions/themes fail, and neither the directory nor its content is reachable by a request.

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

- Ticket: `migration-docs/tickets/active/PROMPT_2_7_Extension-Safety-Fault-Isolation_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_Extension-Safety-Fault-Isolation.md`
- Predecessor: Step 2.6 — Filesystem Write Resilience
- Successor: Step 2.7.1 — Snapshot & Three-Stage Uninstall

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
