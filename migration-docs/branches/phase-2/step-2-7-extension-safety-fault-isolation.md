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

### Registration-window barrier + last-resort exception handler (Checklist Step 2)

| File | Change |
|---|---|
| `app/modules/application/src/Module/ModuleManager.php` | `register()`'s `include $file` — discovery's only way to read what a module declares, run against every on-disk `index.php` whether the module is enabled or not — is now wrapped in its own `try/catch (\Throwable)`. A throwing file registers nothing; its throwable is collected into a new `protected array $registrationFailures`, keyed by file path rather than module name (a file that failed to `include` never got far enough to declare one). Every other file in the same sweep keeps registering normally, including through the recursive `register($includes)` call a module's own `include` glob triggers one level below the top sweep. New `getRegistrationFailures(): array<string, \Throwable>` accessor returns the collected map as-is. The `try` carries `// TODO: Must be refactored in Step 2.7.3 (Static Module Registration)`; the method's doc block records that `ParseError` is caught like any other throwable, while a genuinely fatal compile error (duplicate class/function declaration), `exit`/`die`, or resource exhaustion inside the same `include` is not a throwable and still ends the request. The pre-existing `!is_array($module) \|\| !isset($module['name'])` skip for a file that runs cleanly but declares no module is untouched — that path was never a failure. |
| `app/system/src/SystemModule.php` | `main()` gains one loop, placed after the module loader is registered and before the extensions/theme load loop: every entry from `$app->get('module')->getRegistrationFailures()` is written to the `log` service at `error` level (`Extension failure [<file>] during registration: <message>`, with `['exception' => $error]` context for the stack trace). Placing it before the load loop means a file's registration failure is logged first, so an enabled extension that file belonged to hitting `Undefined module: <name>` moments later in the same request reads as the second half of one fault. The load loop itself is untouched — it still catches only `\RuntimeException` (Step 3). |
| `public/index.php` | The last-resort `set_exception_handler` — previously registered only `if (file_exists($path.'/tmp/logs/debug.log'))`, so a fresh install had no handler at all until something else had already created that file — is now registered unconditionally. It creates `tmp/logs` on first use (`@mkdir(…, 0755, true)`, re-checked against a concurrent creation) instead of requiring the directory to pre-exist, and both the directory creation and the `error_log()` write are `@`-suppressed with a silent early return on failure — the handler exists to record a throwable that already ended the request, so it must never raise one of its own. The logged message format (`[UNCAUGHT EXCEPTION]` header, type/message/file:line/trace) is unchanged. |

### Tests (Checklist Step 2)

| File | Change |
|---|---|
| `tests/Unit/Module/ModuleRegistrationTest.php` (new) | 9 tests against a bare `ModuleManager`: a throwing package is isolated while healthy neighbours on either side of it in the same sweep still register and load; a missing-dependency `Error` (not just an `Exception`) is isolated the same way; a `ParseError` from an unparsable file — generated at runtime into a temp directory, never committed — is isolated too; a file that failed to register surfaces as `Undefined module: <name>` when the boot later tries to load it under the name the site configuration still lists; a file that runs cleanly but declares no module (or returns nothing) is not counted as a failure; a package that registers correctly and only throws once loaded stays registered (its failure belongs to the load window, not this one); a package-of-packages one level below the sweep — the pattern the system module itself uses to find its own modules — isolates a broken child the same way; a clean install reports no failures; failures from separate `register()` calls accumulate together under one accessor. |
| `tests/Unit/Extension/RegistrationFailureReportingTest.php` (new) | 3 tests booting a real `SystemModule::main()` against a Monolog `TestHandler`: every registration failure is logged once, naming its file and carrying the original throwable in the record's `exception` context; a failed file's log line lands before the `Undefined module` line for an extension the site configuration still enables from that same file, preserving cause-then-consequence order; a clean install logs nothing on boot. |
| `tests/fixtures/modules/{healthy,second,throwing,missing-class,main-throwing,unnamed,no-return}/index.php` (new) | Syntactically valid module descriptors, one per registration scenario above (two intact modules; one throwing at top level; one hitting a missing class; one that only throws from its `main` closure once loaded; one declaring no `name`; one returning nothing). Safe to commit as plain fixtures: neither PHPStan's analysed paths (`app/modules`, `app/system`, `app/installer`, `app/console`, `packages`) nor PHPUnit's `<source>` include set (`app/modules`, `app/system`, `app/console`) reach `tests/`. |
| `tests/fixtures/modules/host/index.php` + `tests/fixtures/modules/host/modules/{healthy,broken}/index.php` (new) | A package declaring its own `include` glob one level below it, with one intact and one throwing child, covering the recursive `register()` call the same barrier reaches through. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

---

## 🧠 Key Decisions (Rationale)

- **`path.system` is a directory of its own, not `path.cache`/`path.temp`/`storage` (Checklist Step 1).** The extension-failure record has to survive exactly the operations those existing paths do not: `CacheModule::doClearCache()` sweeps `path.cache` and `path.temp`, and `storage/` is reachable over HTTP through the `public/storage` mount. A record that is the only thing telling the next boot which extension to leave off needs a home a routine cache clear does not empty and a browser cannot request.
- **`ExtensionFailureStore` never throws (Checklist Step 1).** Every public method catches `\Throwable` internally and reports success as a `bool`. It is only ever called while another fault is already being handled — a throw here would replace the failure an administrator needs to see with one of its own, on the request that is trying to keep booting.
- **Registration failures are keyed by file path, not module name (Checklist Step 2).** A file that throws during `include` never executes far enough to declare the array discovery would otherwise read a name from — the path is the only handle available, and it is also what the boot's later `Undefined module` error names the same fault by a second time. Flushing the collected failures to the log before the extension/theme load loop keeps the two ends of one story in the order they happened, instead of a bare "undefined module" with nothing saying why.
- **The last-resort handler's own recovery path is not allowed to throw (Checklist Step 2).** It exists only to record a throwable that has already ended the request; `mkdir()` and `error_log()` are both `@`-suppressed and the handler returns silently rather than escalating, so an unwritable `tmp/` cannot turn one crash into a second, unhandled one from inside the handler meant to report the first.

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

---

## ⚠️ Risks & Rollout Notes

- **The registration barrier stops where PHP stops throwing (Checklist Step 2).** `ModuleManager::register()` isolates every exception, `Error`, and `ParseError` a module file's `include` can raise, but a genuinely fatal compile error (a duplicate class/function declaration), `exit`/`die`, or resource exhaustion inside that same `include` is not a throwable and still ends the request — the barrier's own doc block records the residue, and closing it is Step 2.7.3's static-registration redesign (forward-debt tag on the `try` itself).
- **The last-resort handler is now always on, not opt-in via a pre-existing `debug.log` (Checklist Step 2).** It previously registered only when `tmp/logs/debug.log` already existed, so a throwable early in a fresh install's boot had no handler and no record at all. It is now unconditional and creates `tmp/logs` on demand, so every environment logs every uncaught throwable to that file from here on — there is no remaining way to opt out short of denying `tmp/` write access, which the handler's own suppressed `mkdir`/`error_log` calls degrade out of silently rather than escalate.

---

## 🔐 Security & Data Impact

- **The failure record is denied over HTTP and lives outside the webroot (Checklist Step 1).** `tmp/system/.htaccess` adds `Require all denied` on top of the directory already sitting outside `public/`; the record holds a throwable's class, message and `file:line` — no stack trace — for whichever extensions/themes fail, and neither the directory nor its content is reachable by a request.

---

## 🛡️ No-Mercy Compliance

- **Rule 4 (Delete over wrap) — Checklist Step 2:** `public/index.php`'s `if (file_exists(...))`-gated handler registration is deleted outright in favor of unconditional registration — no flag or fallback keeps the old conditional path alive alongside it.
- **Rule 5 (Mandatory Flagging) — Checklist Step 2:** the registration-window `try/catch` in `ModuleManager::register()` carries `// TODO: Must be refactored in Step 2.7.3 (Static Module Registration)` — forward debt for the residual risk noted above, not a marker on anything already finished.

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
